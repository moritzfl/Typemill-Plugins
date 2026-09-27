<?php

namespace Plugins\gitupdate\Models;

use ZipArchive;

/**
 * Replaces one installed plugin or theme with the copy from a repository archive.
 *
 * Staging sits inside plugins/ or themes/, because that directory is often a
 * bind mount and rename() cannot cross filesystems. Only the named folder is
 * extracted. A symlink is never followed and never replaced.
 */
final class PackageInstaller
{
    public const MAX_ENTRIES = 8000;

    public const MAX_UNCOMPRESSED_BYTES = 134217728; // 128 MB

    private const WORK = '.gitupdate';

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public static function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        if (str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('#^[a-zA-Z]:#', $name) === 1) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * GitHub wraps the archive in one directory. Anything else is rejected
     * rather than guessed at.
     *
     * @param list<string> $names
     */
    public static function findPrefix(array $names): ?string
    {
        $tops = [];
        foreach ($names as $name) {
            $name = str_replace('\\', '/', $name);
            if (!self::isSafeEntryName($name)) {
                continue;
            }
            $top = explode('/', $name, 2)[0];
            if ($top === '') {
                continue;
            }
            $tops[$top] = true;
        }

        if (count($tops) !== 1) {
            return null;
        }

        return array_key_first($tops) . '/';
    }

    public static function belongsTo(string $name, string $kind, string $slug, string $prefix): bool
    {
        $name = str_replace('\\', '/', $name);
        $base = $prefix . Reference::folder($kind) . '/' . $slug . '/';

        return $name === rtrim($base, '/') || str_starts_with($name, $base);
    }

    public static function isSymlinkEntry(array $stat): bool
    {
        $attr = (int) ($stat['external_attributes'] ?? 0);

        return (($attr >> 16) & 0170000) === 0120000;
    }

    /**
     * @return array{ok: bool, error?: string, error_key?: string, path?: string}
     */
    public function stage(string $zipPath, string $kind, string $slug): array
    {
        if (Reference::kind($kind) === null || !Reference::isSlug($slug)) {
            return self::problem('That is not a valid name.', 'err_slug');
        }

        if (!class_exists(ZipArchive::class)) {
            return self::problem('The PHP zip extension is missing.', 'err_zip');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return self::problem('Could not open the archive.', 'err_archive');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $prefix = self::findPrefix($names);
        if ($prefix === null) {
            $zip->close();

            return self::problem('The archive is not a single repository snapshot.', 'err_archive_layout');
        }

        $selected = [];
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $names[$i];
            if (!self::isSafeEntryName($name) || !self::belongsTo($name, $kind, $slug, $prefix)) {
                continue;
            }

            $stat = $zip->statIndex($i);
            if (!is_array($stat) || self::isSymlinkEntry($stat)) {
                $zip->close();

                return self::problem('The archive contains a symlink, so it was not unpacked.', 'err_symlink');
            }

            $selected[] = $name;
            $bytes += (int) ($stat['size'] ?? 0);
        }

        if ($selected === []) {
            $zip->close();

            return self::problem('The archive has no files for ' . $slug . '.', 'err_missing');
        }

        if (count($selected) > self::MAX_ENTRIES || $bytes > self::MAX_UNCOMPRESSED_BYTES) {
            $zip->close();

            return self::problem('The archive is larger than expected.', 'err_too_large');
        }

        $staging = $this->workPath($kind) . DIRECTORY_SEPARATOR . 'staging-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($staging, 0755, true) && !is_dir($staging)) {
            $zip->close();

            return self::problem('Could not create the staging directory.', 'err_stage');
        }

        $extracted = $zip->extractTo($staging, $selected);
        $zip->close();

        if (!$extracted) {
            self::deleteTree($staging);

            return self::problem('Extracting the archive failed.', 'err_stage');
        }

        $staged = $staging . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $prefix)
            . Reference::folder($kind) . DIRECTORY_SEPARATOR . $slug;

        $realStaging = realpath($staging);
        $realStaged = realpath($staged);
        if ($realStaging === false || $realStaged === false || !self::isInside($realStaged, $realStaging)) {
            self::deleteTree($staging);

            return self::problem('The staged folder escaped the working directory.', 'err_stage');
        }

        if (self::containsLink($realStaged) || !Catalog::looksInstalled($realStaged, $kind, $slug)) {
            self::deleteTree($staging);

            return self::problem('The staged ' . $kind . ' is incomplete.', 'err_incomplete');
        }

        if ($kind === 'plugin' && !self::directoryParses($realStaged)) {
            self::deleteTree($staging);

            return self::problem('The new plugin PHP does not parse, so it was not installed.', 'err_php');
        }

        return ['ok' => true, 'error' => null, 'error_key' => null, 'path' => $realStaged, 'staging' => $realStaging];
    }

    /**
     * @return array{ok: bool, touched: bool, error?: string, error_key?: string, backup?: string}
     */
    public function swap(string $kind, string $slug, string $staged): array
    {
        $live = $this->livePath($kind, $slug);

        if (is_link($live)) {
            return ['touched' => false] + self::problem('That folder is a symlink; replacing it is not supported.', 'err_symlink');
        }

        if (!Catalog::looksInstalled($live, $kind, $slug)) {
            return ['touched' => false] + self::problem($slug . ' is not installed.', 'err_not_installed');
        }

        if (!Catalog::looksInstalled($staged, $kind, $slug)) {
            return ['touched' => false] + self::problem('The staged ' . $kind . ' is incomplete.', 'err_incomplete');
        }

        if ($kind === 'plugin' && !self::directoryParses($staged)) {
            return ['touched' => false] + self::problem('The new plugin PHP does not parse, so it was not installed.', 'err_php');
        }

        $backup = $this->workPath($kind) . DIRECTORY_SEPARATOR . 'backup-' . $slug . '--' . gmdate('YmdHis');
        if (file_exists($backup)) {
            return ['touched' => false] + self::problem('Could not create a backup.', 'err_backup');
        }

        self::resetOpcache();

        if (!@rename($live, $backup)) {
            return ['touched' => false] + self::problem(
                'This filesystem does not allow the folder to be renamed, so nothing was changed.',
                'err_rename'
            );
        }

        if (!@rename($staged, $live)) {
            $restored = @rename($backup, $live);

            return ['touched' => !$restored] + self::problem(
                $restored
                    ? 'Could not move the new folder into place. The previous version was restored.'
                    : 'Could not move the new folder into place, and restoring the previous version failed. It is at ' . $backup . '.',
                $restored ? 'err_move' : 'err_stranded'
            );
        }

        self::resetOpcache();
        $this->dropOlderBackups($kind, $slug, $backup);

        return ['ok' => true, 'touched' => true, 'error' => null, 'error_key' => null, 'backup' => $backup];
    }

    public function livePath(string $kind, string $slug): string
    {
        return $this->root . DIRECTORY_SEPARATOR . Reference::folder($kind) . DIRECTORY_SEPARATOR . $slug;
    }

    /** Explicit installation is separate from swap: updates never add folders. */
    public function install(string $kind, string $slug, string $staged): array
    {
        if (Reference::kind($kind) === null || !Reference::isSlug($slug)) {
            return self::problem('That is not a valid name.', 'err_slug');
        }
        $live = $this->livePath($kind, $slug);
        if (file_exists($live) || is_link($live)) {
            return self::problem('That folder already exists.', 'err_exists');
        }
        if (self::containsLink($staged) || !Catalog::looksInstalled($staged, $kind, $slug)
            || ($kind === 'plugin' && !self::directoryParses($staged))) {
            return self::problem('The staged package is incomplete.', 'err_incomplete');
        }
        if (!@rename($staged, $live)) {
            return self::problem('Could not move the package into place.', 'err_move');
        }
        self::resetOpcache();
        return ['ok' => true];
    }

    /** Keep one recoverable copy; settings and content belong to the site. */
    public function remove(string $kind, string $slug): array
    {
        if (Reference::kind($kind) === null || !Reference::isSlug($slug)) {
            return self::problem('That is not a valid name.', 'err_slug');
        }
        $live = $this->livePath($kind, $slug);
        if (self::containsLink($live) || !Catalog::looksInstalled($live, $kind, $slug)) {
            return self::problem('That package cannot be removed.', 'err_not_installed');
        }
        $backup = $this->workPath($kind) . '/removed-' . $slug . '-' . bin2hex(random_bytes(5));
        if (!@rename($live, $backup)) {
            return self::problem('Could not move the package out of the installation.', 'err_rename');
        }
        self::resetOpcache();
        return ['ok' => true, 'backup' => $backup];
    }

    public function workPath(string $kind): string
    {
        $work = $this->root . DIRECTORY_SEPARATOR . Reference::folder($kind) . DIRECTORY_SEPARATOR . self::WORK;
        if (!is_dir($work)) {
            @mkdir($work, 0755, true);
        }

        $htaccess = $work . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Staged updates. Never serve these.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            );
        }

        return $work;
    }

    public static function directoryParses(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        $saw = false;

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            if ($file->isLink() || !self::phpParses($file->getPathname())) {
                return false;
            }
            $saw = true;
        }

        return $saw;
    }

    public static function phpParses(string $path): bool
    {
        $code = @file_get_contents($path);
        if (!is_string($code) || $code === '') {
            return false;
        }

        try {
            token_get_all($code, TOKEN_PARSE);

            return true;
        } catch (\ParseError $e) {
            return false;
        }
    }

    public static function containsLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isLink()) {
                return true;
            }
        }

        return false;
    }

    public static function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_link($child) || is_file($child)) {
                @unlink($child);
                continue;
            }
            self::deleteTree($child);
        }

        @rmdir($path);
    }

    private function dropOlderBackups(string $kind, string $slug, string $keep): void
    {
        $work = $this->workPath($kind);
        $prefix = 'backup-' . $slug . '--';
        foreach (scandir($work) ?: [] as $entry) {
            if (!is_string($entry) || !str_starts_with($entry, $prefix)) {
                continue;
            }
            $path = $work . DIRECTORY_SEPARATOR . $entry;
            if ($path === $keep || is_link($path)) {
                continue;
            }
            self::deleteTree($path);
        }

        foreach (scandir($work) ?: [] as $entry) {
            if (!is_string($entry) || !str_starts_with($entry, 'staging-')) {
                continue;
            }
            $path = $work . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::deleteTree($path);
            }
        }
    }

    private static function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $path === rtrim($root, DIRECTORY_SEPARATOR) || str_starts_with($path, $root);
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * @return array{ok: false, error: string, error_key: string}
     */
    private static function problem(string $error, string $key): array
    {
        return ['ok' => false, 'error' => $error, 'error_key' => 'gitupdate.' . $key];
    }
}
