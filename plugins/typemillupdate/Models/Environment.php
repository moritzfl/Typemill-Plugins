<?php

namespace Plugins\typemillupdate\Models;

/**
 * Locates the Typemill installation and answers whether this environment is
 * capable of updating its own core.
 *
 * A core update only replaces the `system` directory, which holds the core
 * (`system/typemill`), the Composer dependencies (`system/vendor`) and
 * `system/autoload.php`. Content, media, settings, data, cache, themes,
 * index.php and .htaccess are user state and are never touched. Plugins from
 * the Typemill directory can be replaced separately, one folder at a time.
 */
class Environment
{
    /** Working directory for downloads, staging and backups, relative to root. */
    public const WORK_DIRNAME = '.tm-update';

    /**
     * Floor for the space probe, in bytes.
     *
     * The requirement is measured from the core itself; this only guards the
     * case where that measurement comes back implausibly small, for instance
     * because the directory could not be read.
     */
    public const MIN_PROBE_BYTES = 16777216; // 16 MB

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

    public function pluginsPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'plugins';
    }

    public function workPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . self::WORK_DIRNAME;
    }

    /**
     * Staging and backups for directory plugins.
     *
     * This has to sit inside plugins/: the swap renames the live plugin folder,
     * and rename() cannot cross filesystems. On the Docker test setup plugins/
     * is a bind mount and the project-root working directory is not.
     */
    public function pluginWorkPath(): string
    {
        return $this->pluginsPath() . DIRECTORY_SEPARATOR . self::WORK_DIRNAME;
    }

    /**
     * Folder names Typemill will load as plugins, and that a zip from the
     * directory may legally replace. Dots, slashes and traversal are out.
     */
    public static function isPluginSlug(string $slug): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $slug) === 1;
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

    public function ensurePluginWorkPath(): bool
    {
        $work = $this->pluginWorkPath();

        if (!is_dir($work) && !@mkdir($work, 0755, true) && !is_dir($work)) {
            return false;
        }

        $htaccess = $work . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Downloaded and superseded plugin files. Never serve these.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            );
        }

        return is_dir($work);
    }

    /**
     * Plugins this installation has on disk.
     *
     * Only folders that look like a Typemill plugin - `{slug}/{slug}.php` and
     * `{slug}/{slug}.yaml` - are returned. Hidden folders are skipped, because
     * that is where plugin staging lives.
     *
     * @return array<string, array{slug: string, name: string, version: ?string}>
     */
    public function installedPlugins(): array
    {
        $dir = $this->pluginsPath();
        if (!is_dir($dir)) {
            return [];
        }

        $plugins = [];

        foreach ((array) @scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (!self::isPluginSlug($entry)) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path) || is_link($path) || !PluginInstaller::looksLikePlugin($path, $entry)) {
                continue;
            }

            $yaml = (string) file_get_contents($path . DIRECTORY_SEPARATOR . $entry . '.yaml');
            $name = $entry;
            if (preg_match('/^\s*name:\s*[\'"]?([^\'"\n]+)/m', $yaml, $matches) === 1) {
                $name = trim($matches[1]);
            }

            $plugins[$entry] = [
                'slug' => $entry,
                'name' => $name !== '' ? $name : $entry,
                'version' => self::parseVersionFromYaml($yaml),
            ];
        }

        return $plugins;
    }

    /**
     * Whether plugin folders can be created and renamed, which the swap needs.
     *
     * The probe runs against plugins/ itself and never touches an installed
     * plugin.
     */
    public function pluginsAreWritable(): bool
    {
        $plugins = $this->pluginsPath();
        if (!is_dir($plugins)) {
            return false;
        }

        $probe = $plugins . DIRECTORY_SEPARATOR . '.tm-update-probe-' . bin2hex(random_bytes(6));
        if (!@mkdir($probe, 0755)) {
            return false;
        }

        $moved = $probe . '-moved';
        if (!@rename($probe, $moved)) {
            @rmdir($probe);

            return false;
        }

        @rmdir($moved);

        return true;
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
        $checks[] = $this->checkUsableSpace();
        $checks[] = $this->checkOpcache();

        return $checks;
    }

    /**
     * Bytes an update has to be able to write, estimated from the core itself.
     *
     * An update writes the archive it downloads and the core it unpacks from
     * it. The backup of the current core is a rename, so it costs nothing.
     * Twice the current core covers both with room over, and is only an
     * estimate: once the archive has been read, requiredForCore() gives the
     * real figure and the probe is repeated.
     *
     * Sizes are rounded up to whole blocks, because a core is largely small
     * files: this one is 6.3 MB of content that occupies 9 MB on disk.
     */
    public function requiredBytes(): int
    {
        $system = $this->systemPath();

        if (!is_dir($system)) {
            return self::MIN_PROBE_BYTES;
        }

        $onDisk = 0;

        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($system, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($entries as $entry) {
                if ($entry->isFile()) {
                    $onDisk += (int) (ceil($entry->getSize() / 4096) * 4096);
                }
            }
        } catch (\Throwable $e) {
            return self::MIN_PROBE_BYTES;
        }

        return max(self::MIN_PROBE_BYTES, $onDisk * 2);
    }

    /**
     * Bytes needed to unpack a core of a known uncompressed size.
     *
     * Used once the archive has been inspected, because the estimate above is
     * measured from the version being replaced and a release may be
     * substantially larger than the one it supersedes. The archive itself is
     * already on disk by then, so only the unpacked core is counted, plus a
     * tenth for the slack that small files leave in their last block.
     */
    public static function requiredForCore(int $uncompressedBytes): int
    {
        return (int) ceil($uncompressedBytes * 1.1);
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
        $needed = $this->requiredBytes();
        $probe = $this->probeSpace($needed);

        // How far the probe got is not reported: that figure is free space by
        // another name, and free space is the number that cannot be trusted
        // here. The answer is whether an update fits, and what it would take.
        if ($probe['reason'] === 'nofile') {
            return $this->check('usable_space', false, true,
                'A test file could not be created in ' . $this->root . '.',
                'usable_space_nofile', ['root' => $this->root]);
        }

        if (!$probe['ok']) {
            return $this->check('usable_space', false, true,
                'Not enough space for an update; it needs ' . self::formatBytes($needed)
                    . '. On shared hosting this is usually the account quota.',
                'usable_space_short', ['needed' => self::formatBytes($needed)]);
        }

        return $this->check('usable_space', true, true,
            'There is enough space for an update.', 'usable_space_ok');
    }

    /**
     * Claim the bytes for real and release them again.
     *
     * @return array{ok: bool, reason: ?string} `reason` is 'nofile' when the
     *         probe could not be created at all and 'short' when it ran out.
     */
    public function probeSpace(int $needed): array
    {
        $path = $this->root . DIRECTORY_SEPARATOR . '.tm-update-space-' . bin2hex(random_bytes(6));
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            return ['ok' => false, 'reason' => 'nofile'];
        }

        $chunk = str_repeat("\0", 1048576);
        $written = 0;
        $failed = false;

        while ($written < $needed) {
            $length = (int) min(strlen($chunk), $needed - $written);
            $bytes = @fwrite($handle, $chunk, $length);
            if ($bytes === false || $bytes < $length) {
                $failed = true;
                break;
            }
            $written += $bytes;
        }

        // A quota can surface on flush rather than on write, when the buffer is
        // handed to the filesystem, and on some filesystems only once the data
        // is really allocated - which is what fsync waits for.
        if (!$failed && !@fflush($handle)) {
            $failed = true;
        }

        if (!$failed && function_exists('fsync') && !@fsync($handle)) {
            $failed = true;
        }

        @fclose($handle);
        @unlink($path);

        return ['ok' => !$failed, 'reason' => $failed ? 'short' : null];
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
