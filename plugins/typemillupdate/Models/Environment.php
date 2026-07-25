<?php

namespace Plugins\typemillupdate\Models;

/**
 * Locates the Typemill installation and answers whether this environment is
 * capable of updating its own core.
 *
 * A Typemill update only replaces the `system` directory, which holds the core
 * (`system/typemill`), the Composer dependencies (`system/vendor`) and
 * `system/autoload.php`. Everything else - content, media, settings, data,
 * cache, plugins, themes, index.php, .htaccess - is user state and is never
 * touched.
 */
class Environment
{
    /** Working directory for downloads, staging and backups, relative to root. */
    public const WORK_DIRNAME = '.tm-update';

    /** Refuse to run when less than this is free, in bytes. */
    public const MIN_FREE_BYTES = 209715200; // 200 MB

    private string $root;

    public function __construct(?string $root = null)
    {
        $candidate = $root !== null ? $root : self::detectRoot();
        $real = realpath($candidate);

        // Kept canonical, because the containment check that fences recursive
        // deletes compares against this prefix. A symlinked path component
        // would otherwise make every check miss - on macOS, for example, /var
        // is a symlink to /private/var.
        $this->root = rtrim($real !== false ? $real : $candidate, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve the installation root.
     *
     * This file lives at <root>/plugins/typemillupdate/Models, but plugins can be
     * symlinked, so the derived path is validated and getcwd() is used as a
     * fallback - that is what the core itself relies on when loading plugins.
     */
    public static function detectRoot(): string
    {
        $candidates = [dirname(__DIR__, 3)];

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd;
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim((string) $candidate, DIRECTORY_SEPARATOR);
            if ($candidate !== '' && is_dir($candidate . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'typemill')) {
                return $candidate;
            }
        }

        return rtrim((string) $candidates[0], DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function systemPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'system';
    }

    public function workPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . self::WORK_DIRNAME;
    }

    /**
     * Create the working directory and keep it off the web.
     *
     * The directory has to sit inside the project root: the swap renames the
     * staged core over `system`, and rename() cannot cross filesystems. On a
     * typical Docker setup `data/` is a bind mount and would fail with EXDEV.
     */
    public function ensureWorkPath(): bool
    {
        $work = $this->workPath();

        if (!is_dir($work) && !@mkdir($work, 0755, true) && !is_dir($work)) {
            return false;
        }

        $htaccess = $work . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Downloaded and superseded core files. Never serve these.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            );
        }

        return is_dir($work);
    }

    public function installedVersion(): ?string
    {
        $file = $this->systemPath() . '/typemill/settings/defaults.yaml';
        if (!is_readable($file)) {
            return null;
        }

        return self::parseVersionFromYaml((string) file_get_contents($file));
    }

    public static function parseVersionFromYaml(string $yaml): ?string
    {
        if (preg_match('/^\s*version:\s*[\'"]?([0-9]+\.[0-9]+\.[0-9]+)[\'"]?/m', $yaml, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Minimum PHP version demanded by a Composer vendor tree, read from the
     * generated platform_check.php.
     */
    public static function parsePhpFloor(string $platformCheck): ?int
    {
        if (preg_match('/PHP_VERSION_ID\s*>=\s*(\d+)/', $platformCheck, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Run every environment check. Returns a list of
     * ['id', 'ok', 'blocking', 'detail'] entries.
     *
     * Only the writability probe touches the filesystem, and it cleans up after
     * itself. Nothing here modifies the installation.
     */
    public function preflight(): array
    {
        $checks = [];

        $checks[] = $this->checkZipArchive();
        $checks[] = $this->checkSystemLayout();
        $checks[] = $this->checkLegacyVendor();
        $checks[] = $this->checkRootWritable();
        $checks[] = $this->checkSystemWritable();
        $checks[] = $this->checkDiskSpace();
        $checks[] = $this->checkOpcache();

        return $checks;
    }

    public static function isBlocked(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['ok'] && $check['blocking']) {
                return true;
            }
        }

        return false;
    }

    private function checkZipArchive(): array
    {
        $ok = class_exists('ZipArchive');

        return $this->check('ziparchive', $ok, true, $ok
            ? 'The PHP zip extension is available.'
            : 'The PHP zip extension is missing, so the release archive cannot be unpacked.');
    }

    private function checkSystemLayout(): array
    {
        $system = $this->systemPath();

        if (is_link($system)) {
            return $this->check('system_layout', false, true, 'The system directory is a symlink; replacing it is not supported.');
        }

        $ok = is_dir($system)
            && is_dir($system . DIRECTORY_SEPARATOR . 'typemill')
            && is_dir($system . DIRECTORY_SEPARATOR . 'vendor');

        return $this->check('system_layout', $ok, true, $ok
            ? 'Found system/typemill and system/vendor in ' . $this->root . '.'
            : 'Could not find system/typemill and system/vendor in ' . $this->root . '.');
    }

    /**
     * Installations older than 2.23 keep Composer packages in <root>/vendor.
     * Replacing only `system` would leave those stale, so refuse to run.
     */
    private function checkLegacyVendor(): array
    {
        $legacy = is_dir($this->root . DIRECTORY_SEPARATOR . 'vendor')
            && !is_dir($this->systemPath() . DIRECTORY_SEPARATOR . 'vendor');

        return $this->check('vendor_location', !$legacy, true, $legacy
            ? 'This installation keeps Composer packages in <root>/vendor, which predates Typemill 2.23. Update manually.'
            : 'Composer packages live in system/vendor, as expected.');
    }

    /**
     * Renaming `system` requires write permission on its parent directory, not
     * on `system` itself, so the probe runs against the project root and never
     * touches the live core.
     */
    private function checkRootWritable(): array
    {
        $probe = $this->root . DIRECTORY_SEPARATOR . '.tm-update-probe-' . bin2hex(random_bytes(6));

        if (!@mkdir($probe, 0755)) {
            return $this->check('root_writable', false, true,
                'PHP cannot create entries in ' . $this->root . '. This usually means the files belong to a different user than the web server.');
        }

        $moved = $probe . '-moved';
        if (!@rename($probe, $moved)) {
            @rmdir($probe);

            return $this->check('root_writable', false, true,
                'PHP cannot rename entries in ' . $this->root . ', which the update needs in order to swap the system directory.');
        }

        @rmdir($moved);

        return $this->check('root_writable', true, true, 'PHP can create and rename entries in the project root.');
    }

    /**
     * Whether the core can be replaced in place.
     *
     * The installer prefers renaming `system` aside, which is atomic. Some
     * filesystems refuse to rename a directory at all - notably Docker's
     * overlayfs, which returns EXDEV for directories that still live in the
     * image's lower layer. There the installer copies instead, which needs
     * `system` itself to be writable. This is reported rather than enforced,
     * because either route alone is enough.
     */
    private function checkSystemWritable(): array
    {
        $system = $this->systemPath();
        $writable = is_dir($system) && is_writable($system);

        return $this->check('system_writable', true, false, $writable
            ? 'The system directory is writable, so an in-place copy is possible if the filesystem refuses directory renames.'
            : 'The system directory is not writable. The update then depends on being able to rename it, which not every filesystem allows.');
    }

    private function checkDiskSpace(): array
    {
        $free = @disk_free_space($this->root);

        if ($free === false) {
            return $this->check('disk_space', true, false, 'Free disk space could not be determined.');
        }

        $ok = $free >= self::MIN_FREE_BYTES;

        return $this->check('disk_space', $ok, true, $ok
            ? self::formatBytes((int) $free) . ' free.'
            : 'Only ' . self::formatBytes((int) $free) . ' free; at least ' . self::formatBytes(self::MIN_FREE_BYTES) . ' is required.');
    }

    /**
     * Informational only. The installer resets OPcache itself; this surfaces
     * the case where timestamp validation is off, which is what makes a stale
     * cache permanent rather than a two second blip.
     */
    private function checkOpcache(): array
    {
        if (!function_exists('opcache_get_status')) {
            return $this->check('opcache', true, false, 'OPcache is not enabled.');
        }

        $resettable = function_exists('opcache_reset');
        $validate = ini_get('opcache.validate_timestamps');
        $validates = $validate === false || (string) $validate === '' || (bool) $validate;

        if ($resettable) {
            return $this->check('opcache', true, false, $validates
                ? 'OPcache is enabled and will be reset after the swap.'
                : 'OPcache runs with validate_timestamps off; it will be reset after the swap.');
        }

        return $this->check('opcache', $validates, false, $validates
            ? 'OPcache is enabled and revalidates by timestamp.'
            : 'OPcache has validate_timestamps off and opcache_reset() is unavailable. Restart PHP after updating.');
    }

    private function check(string $id, bool $ok, bool $blocking, string $detail): array
    {
        return [
            'id' => $id,
            'ok' => $ok,
            'blocking' => $blocking,
            'detail' => $detail,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
