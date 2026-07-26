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

    /**
     * Bytes actually written to prove the space is available before an update.
     *
     * An update holds the downloaded archive, the unpacked staging copy and a
     * backup of the current core at the same time. That is around 35 MB for a
     * current release, so this leaves room to spare without writing the full
     * MIN_FREE_BYTES, which would be slow for no added certainty.
     */
    public const PROBE_BYTES = 67108864; // 64 MB

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
    public function preflight(bool $thorough = false): array
    {
        $checks = [];

        $checks[] = $this->checkZipArchive();
        $checks[] = $this->checkSystemLayout();
        $checks[] = $this->checkLegacyVendor();
        $checks[] = $this->checkRootWritable();
        $checks[] = $this->checkSystemWritable();
        $checks[] = $this->checkDiskSpace();

        // Only before an install: it writes real bytes, which is too costly to
        // repeat every time the page is opened.
        if ($thorough) {
            $checks[] = $this->checkUsableSpace();
        }

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

        return $ok
            ? $this->check('ziparchive', true, true,
                'The PHP zip extension is available.', 'ziparchive_ok')
            : $this->check('ziparchive', false, true,
                'The PHP zip extension is missing, so the release archive cannot be unpacked.', 'ziparchive_missing');
    }

    private function checkSystemLayout(): array
    {
        $system = $this->systemPath();

        if (is_link($system)) {
            return $this->check('system_layout', false, true,
                'The system directory is a symlink; replacing it is not supported.', 'system_layout_symlink');
        }

        $ok = is_dir($system)
            && is_dir($system . DIRECTORY_SEPARATOR . 'typemill')
            && is_dir($system . DIRECTORY_SEPARATOR . 'vendor');

        return $ok
            ? $this->check('system_layout', true, true,
                'Found system/typemill and system/vendor in ' . $this->root . '.',
                'system_layout_ok', ['root' => $this->root])
            : $this->check('system_layout', false, true,
                'Could not find system/typemill and system/vendor in ' . $this->root . '.',
                'system_layout_missing', ['root' => $this->root]);
    }

    /**
     * Installations older than 2.23 keep Composer packages in <root>/vendor.
     * Replacing only `system` would leave those stale, so refuse to run.
     */
    private function checkLegacyVendor(): array
    {
        $legacy = is_dir($this->root . DIRECTORY_SEPARATOR . 'vendor')
            && !is_dir($this->systemPath() . DIRECTORY_SEPARATOR . 'vendor');

        return $legacy
            ? $this->check('vendor_location', false, true,
                'This installation keeps Composer packages in <root>/vendor, which predates Typemill 2.23. Update manually.',
                'vendor_location_legacy')
            : $this->check('vendor_location', true, true,
                'Composer packages live in system/vendor, as expected.', 'vendor_location_ok');
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
                'PHP cannot create entries in ' . $this->root . '. This usually means the files belong to a different user than the web server.',
                'root_writable_nocreate', ['root' => $this->root]);
        }

        $moved = $probe . '-moved';
        if (!@rename($probe, $moved)) {
            @rmdir($probe);

            return $this->check('root_writable', false, true,
                'PHP cannot rename entries in ' . $this->root . ', which the update needs in order to swap the system directory.',
                'root_writable_norename', ['root' => $this->root]);
        }

        @rmdir($moved);

        return $this->check('root_writable', true, true,
            'PHP can create and rename entries in the project root.', 'root_writable_ok');
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

        return $writable
            ? $this->check('system_writable', true, false,
                'Write access to the system directory is available.',
                'system_writable_ok')
            : $this->check('system_writable', true, false,
                'The system directory is not writable. The update then depends on being able to rename it, which not every filesystem allows.',
                'system_writable_readonly');
    }

    private function checkDiskSpace(): array
    {
        $free = @disk_free_space($this->root);

        if ($free === false) {
            return $this->check('disk_space', true, false,
                'Free disk space could not be determined.', 'disk_space_unknown');
        }

        $ok = $free >= self::MIN_FREE_BYTES;

        // Deliberately says "on the volume": disk_free_space() reports the
        // filesystem, and on shared hosting the filesystem is far bigger than
        // the account may use. A 25 GB quota on a 1 TB disk reads as 1 TB free.
        return $ok
            ? $this->check('disk_space', true, true,
                self::formatBytes((int) $free) . ' free on the volume.',
                'disk_space_ok', ['size' => self::formatBytes((int) $free)])
            : $this->check('disk_space', false, true,
                'Only ' . self::formatBytes((int) $free) . ' free; at least ' . self::formatBytes(self::MIN_FREE_BYTES) . ' is required.',
                'disk_space_low', [
                    'size' => self::formatBytes((int) $free),
                    'required' => self::formatBytes(self::MIN_FREE_BYTES),
                ]);
    }

    /**
     * Prove the space is really available to this account.
     *
     * disk_free_space() asks the filesystem, and a shared host gives every
     * account a slice of a much larger disk: 24 GB of quota on a 1 TB volume
     * is reported as 1 TB free, so the cheap check above passes a site that
     * has no room left at all. PHP has no portable way to read a quota, and
     * neither `quota` nor the panel's numbers are reachable from here.
     *
     * Writing the bytes is the one answer that cannot be wrong, so before an
     * install they are claimed for real and released again.
     */
    private function checkUsableSpace(): array
    {
        $path = $this->root . DIRECTORY_SEPARATOR . '.tm-update-space-' . bin2hex(random_bytes(6));
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            return $this->check('usable_space', false, true,
                'A test file could not be created in ' . $this->root . '.',
                'usable_space_nofile', ['root' => $this->root]);
        }

        $chunk = str_repeat("\0", 1048576);
        $written = 0;
        $failed = false;

        while ($written < self::PROBE_BYTES) {
            $bytes = @fwrite($handle, $chunk);
            if ($bytes === false || $bytes < strlen($chunk)) {
                $failed = true;
                break;
            }
            $written += $bytes;
        }

        // A quota can surface on flush rather than on write, when the buffer
        // is handed to the filesystem.
        if (!$failed && !@fflush($handle)) {
            $failed = true;
        }

        @fclose($handle);
        @unlink($path);

        if ($failed) {
            return $this->check('usable_space', false, true,
                'Only ' . self::formatBytes($written) . ' of the ' . self::formatBytes(self::PROBE_BYTES)
                    . ' an update needs could be written. On shared hosting this is usually the account quota.',
                'usable_space_short', [
                    'written' => self::formatBytes($written),
                    'needed' => self::formatBytes(self::PROBE_BYTES),
                ]);
        }

        return $this->check('usable_space', true, true,
            self::formatBytes(self::PROBE_BYTES) . ' could be written.',
            'usable_space_ok', ['needed' => self::formatBytes(self::PROBE_BYTES)]);
    }

    /**
     * Informational only. The installer resets OPcache itself; this surfaces
     * the case where timestamp validation is off, which is what makes a stale
     * cache permanent rather than a two second blip.
     */
    private function checkOpcache(): array
    {
        if (!function_exists('opcache_get_status')) {
            return $this->check('opcache', true, false, 'OPcache is not enabled.', 'opcache_off');
        }

        $resettable = function_exists('opcache_reset');
        $validate = ini_get('opcache.validate_timestamps');
        $validates = $validate === false || (string) $validate === '' || (bool) $validate;

        if ($resettable) {
            return $validates
                ? $this->check('opcache', true, false,
                    'OPcache is enabled and will be reset after the swap.', 'opcache_reset')
                : $this->check('opcache', true, false,
                    'OPcache runs with validate_timestamps off; it will be reset after the swap.',
                    'opcache_reset_stale');
        }

        return $validates
            ? $this->check('opcache', true, false,
                'OPcache is enabled and revalidates by timestamp.', 'opcache_revalidates')
            : $this->check('opcache', false, false,
                'OPcache has validate_timestamps off and opcache_reset() is unavailable. Restart PHP after updating.',
                'opcache_manual_restart');
    }

    /**
     * `detail` stays the English sentence: it is what the log records and what
     * the panel falls back to when a language file has no entry yet.
     *
     * `label` is the translation key for that sentence and `params` carries the
     * values it interpolates, because the admin translator resolves a key to a
     * string and cannot fill placeholders itself.
     */
    private function check(string $id, bool $ok, bool $blocking, string $detail, string $label = '', array $params = []): array
    {
        return [
            'id' => $id,
            'ok' => $ok,
            'blocking' => $blocking,
            'detail' => $detail,
            'label' => $label === '' ? '' : 'typemillupdate.check.' . $label,
            'params' => $params,
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
