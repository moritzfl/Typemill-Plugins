<?php

namespace Plugins\typemillupdate\Models;

use ZipArchive;

/**
 * Puts a validated release archive in place.
 *
 * The sequence is: extract only `system/**` into a staging directory, move the
 * live `system` aside into a backup, then move the staged tree into its place.
 * Both moves happen inside the project root, so they stay on one filesystem and
 * each rename is atomic. The backup is the rollback - it is a rename, not a
 * copy, so it costs nothing and always exists before the live tree moves.
 *
 * Renaming is the only route. A filesystem that refuses to rename the core -
 * Docker's overlayfs returns EXDEV for directories still in the image's lower
 * layer - stops the update before anything is touched, rather than falling back
 * to copying over the live tree. Copying cannot be undone as one step, so a
 * failure part way through leaves a half-replaced core; refusing leaves a
 * working site and a manual update to do.
 *
 * Every operation that could leave the site without a working core validates
 * that the tree it is about to install actually looks like a core, and reports
 * through a `touched` flag whether the live installation was modified.
 */
class Installer
{
    /** Superseded cores kept for rollback. */
    public const KEEP_BACKUPS = 2;

    private Environment $environment;
    private string $stamp;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
        $this->stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    public function stamp(): string
    {
        return $this->stamp;
    }

    public function downloadTarget(): string
    {
        return $this->environment->workPath() . DIRECTORY_SEPARATOR . 'download-' . $this->stamp . '.zip';
    }

    public function stagingPath(): string
    {
        return $this->environment->workPath() . DIRECTORY_SEPARATOR . 'staging-' . $this->stamp;
    }

    public function backupPath(): string
    {
        return $this->environment->workPath() . DIRECTORY_SEPARATOR . 'backup-' . $this->stamp;
    }

    /**
     * A directory is only treated as a core if it carries the things the site
     * cannot boot without. Everything that installs or restores a tree checks
     * this first, so an empty or truncated directory can never be moved over
     * the live core.
     */
    public static function looksLikeCore(string $path): bool
    {
        return is_file($path . DIRECTORY_SEPARATOR . 'autoload.php')
            && is_dir($path . DIRECTORY_SEPARATOR . 'typemill')
            && is_file($path . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
    }

    /**
     * Serialise updates. Two concurrent runs would delete each other's staging
     * directories. The lock is released when the handle closes, which includes
     * the process dying, so it cannot go stale.
     */
    public function acquireLock(): bool
    {
        $handle = @fopen($this->environment->workPath() . DIRECTORY_SEPARATOR . 'update.lock', 'c');
        if ($handle === false) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    public function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Extract the core, and nothing else.
     *
     * The release archive is a full fresh-install image: it also carries demo
     * content, media, settings and themes. Extracting it wholesale would
     * overwrite the site. Only entries under `system/` are passed to
     * extractTo(), so everything else is ignored.
     */
    public function stage(string $zipPath, string $prefix = ''): array
    {
        $staging = $this->stagingPath();

        if (!@mkdir($staging, 0755, true) && !is_dir($staging)) {
            return ['ok' => false, 'error' => 'Could not create the staging directory.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Could not open the archive.'];
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (Release::isSafeEntryName($name) && Release::isSystemEntry($name, $prefix)) {
                $entries[] = $name;
            }
        }

        if ($entries === []) {
            $zip->close();

            return ['ok' => false, 'error' => 'The archive contains no system/ entries.'];
        }

        $extracted = $zip->extractTo($staging, $entries);
        $zip->close();

        if (!$extracted) {
            return ['ok' => false, 'error' => 'Extracting the archive failed.'];
        }

        // extractTo keeps full entry paths, so a wrapped archive lands one
        // directory deeper than a plain one.
        $stagedSystem = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $prefix) . 'system';
        if (!self::looksLikeCore($stagedSystem)) {
            return ['ok' => false, 'error' => 'The staged core is incomplete.'];
        }

        return ['ok' => true, 'error' => null, 'path' => $stagedSystem, 'entries' => count($entries)];
    }

    /**
     * Put the staged core in place, keeping the old one as a backup.
     *
     * Two renames, both inside the project root: the live core moves into the
     * backup, the staged core moves into its place. There is a sub-millisecond
     * window between them in which `system` does not exist, and a request
     * landing exactly there fails. That cannot be closed from PHP alone; it can
     * only be kept short.
     *
     * A filesystem that will not rename the core stops the update here, with
     * nothing changed.
     *
     * The returned `touched` flag says whether the live core was modified. When
     * it is true the caller must not rely on the framework any more, because a
     * class that has not been autoloaded yet may no longer be readable.
     */
    public function install(string $stagedSystem): array
    {
        if (!self::looksLikeCore($stagedSystem)) {
            return ['ok' => false, 'touched' => false, 'error' => 'The core to install is incomplete.'];
        }

        $system = $this->environment->systemPath();
        $backup = $this->backupPath();

        if (!@mkdir($backup, 0755, true) && !is_dir($backup)) {
            return ['ok' => false, 'touched' => false, 'error' => 'Could not create the backup directory.'];
        }

        $backupSystem = $backup . DIRECTORY_SEPARATOR . 'system';

        $this->resetOpcache();

        error_clear_last();
        if (!@rename($system, $backupSystem)) {
            $reason = error_get_last()['message'] ?? '';
            @rmdir($backup);

            return [
                'ok' => false,
                'touched' => false,
                'error' => self::renameRefusedMessage($reason),
                'error_key' => 'typemillupdate.err_rename_unsupported',
            ];
        }

        if (!@rename($stagedSystem, $system)) {
            // Put the original back; the site must not be left without a
            // system directory.
            $restored = @rename($backupSystem, $system);

            return [
                'ok' => false,
                'touched' => !$restored,
                'error' => $restored
                    ? 'Could not move the new core into place. The previous core was restored.'
                    : 'Could not move the new core into place, and restoring the previous core failed. The previous core is at ' . $backupSystem . '.',
            ];
        }

        clearstatcache(true);
        $this->resetOpcache();

        return ['ok' => true, 'touched' => true, 'error' => null, 'backup' => $backup];
    }

    /**
     * Put a stored core back in place.
     *
     * Mirrors install(): renames only, so a filesystem that will not rename the
     * core leaves the site exactly as it was.
     */
    public function rollback(string $backupDirectory): array
    {
        $backupSystem = $backupDirectory . DIRECTORY_SEPARATOR . 'system';

        if (!self::looksLikeCore($backupSystem)) {
            return ['ok' => false, 'touched' => false, 'error' => 'That backup does not contain a complete core.'];
        }

        $system = $this->environment->systemPath();

        // The core being replaced is parked under a backup name, so the version
        // rolled back from stays restorable instead of being orphaned. The name
        // is generated fresh: reusing this instance's stamp would collide with
        // the backup created by an install() on the same instance, which is
        // exactly the directory being restored from.
        $parked = $this->environment->workPath() . DIRECTORY_SEPARATOR
            . 'backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        if (!@mkdir($parked, 0755, true) && !is_dir($parked)) {
            return ['ok' => false, 'touched' => false, 'error' => 'Could not create a directory for the core being replaced.'];
        }

        $parkedSystem = $parked . DIRECTORY_SEPARATOR . 'system';

        $this->resetOpcache();

        error_clear_last();
        if (!@rename($system, $parkedSystem)) {
            $reason = error_get_last()['message'] ?? '';
            @rmdir($parked);

            return [
                'ok' => false,
                'touched' => false,
                'error' => self::renameRefusedMessage($reason),
                'error_key' => 'typemillupdate.err_rename_unsupported',
            ];
        }

        if (!@rename($backupSystem, $system)) {
            if (!@rename($parkedSystem, $system)) {
                return [
                    'ok' => false,
                    'touched' => true,
                    'error' => 'Could not restore the backup, and the current core could not be put back. It is at ' . $parkedSystem . '.',
                ];
            }

            @rmdir($parked);

            return ['ok' => false, 'touched' => false, 'error' => 'Could not restore the backup. The current core was put back.'];
        }

        // The restored backup directory is empty now; rmdir only succeeds if
        // that is really the case.
        @rmdir($backupDirectory);

        clearstatcache(true);
        $this->resetOpcache();

        return ['ok' => true, 'touched' => true, 'error' => null];
    }

    /**
     * Compiled Twig templates are generated from core templates, so they have
     * to go after a core swap. The directory is a cache and is rebuilt on
     * demand.
     */
    public function clearTwigCache(): bool
    {
        $twig = $this->environment->root() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'twig';

        if (!is_dir($twig)) {
            return false;
        }

        return $this->removeDirectory($twig);
    }

    /**
     * Fetch the site over HTTP to confirm the new core boots.
     *
     * Deliberately conservative: a 5xx is treated as a broken install, but a
     * connection failure is not, because that is just as likely to mean the
     * server cannot reach itself.
     */
    public function selfTest(string $baseUrl): array
    {
        if (!function_exists('curl_init') || $baseUrl === '') {
            return ['conclusive' => false, 'ok' => true, 'status' => 0, 'detail' => 'Self-test skipped.'];
        }

        $curl = curl_init($baseUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Typemill-Update/1.0 (self-test)',
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status === 0) {
            return ['conclusive' => false, 'ok' => true, 'status' => 0, 'detail' => 'The site could not be reached for the self-test: ' . $error];
        }

        if ($status >= 500) {
            return ['conclusive' => true, 'ok' => false, 'status' => $status, 'detail' => 'The site answered with HTTP ' . $status . ' after the update.'];
        }

        return ['conclusive' => true, 'ok' => true, 'status' => $status, 'detail' => 'The site answered with HTTP ' . $status . '.'];
    }

    /** @return array<int, array{name: string, path: string, created: int, version: ?string}> */
    public function listBackups(): array
    {
        $work = $this->environment->workPath();
        if (!is_dir($work)) {
            return [];
        }

        $backups = [];
        foreach ((array) @scandir($work) as $entry) {
            if (!is_string($entry) || !str_starts_with($entry, 'backup-')) {
                continue;
            }

            $path = $work . DIRECTORY_SEPARATOR . $entry;

            // Only complete cores are offered, because listing is what the
            // restore action is chosen from.
            if (!self::looksLikeCore($path . DIRECTORY_SEPARATOR . 'system')) {
                continue;
            }

            $defaults = $path . DIRECTORY_SEPARATOR . 'system/typemill/settings/defaults.yaml';
            $version = is_readable($defaults)
                ? Environment::parseVersionFromYaml((string) file_get_contents($defaults))
                : null;

            $backups[] = [
                'name' => $entry,
                'path' => $path,
                'created' => (int) @filemtime($path),
                'version' => $version,
            ];
        }

        usort($backups, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);

        return $backups;
    }

    /**
     * Drop staging leftovers and stale backups. Safe to run broadly because
     * runs are serialised by acquireLock().
     */
    public function cleanup(): void
    {
        $work = $this->environment->workPath();
        if (!is_dir($work)) {
            return;
        }

        foreach ((array) @scandir($work) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..'
                || $entry === '.htaccess' || $entry === 'update.lock') {
                continue;
            }

            $path = $work . DIRECTORY_SEPARATOR . $entry;

            if (str_starts_with($entry, 'staging-')) {
                $this->removeDirectory($path);
                continue;
            }

            if ((str_starts_with($entry, 'download-') || str_starts_with($entry, 'upload-')) && is_file($path)) {
                @unlink($path);
                continue;
            }

            // Half-finished chunk uploads.
            if ($entry === Upload::CHUNK_DIRNAME) {
                $this->removeDirectory($path);
                continue;
            }

            // A backup directory with no complete core inside is debris from an
            // attempt that failed before or during the swap.
            if (str_starts_with($entry, 'backup-') && is_dir($path)
                && !self::looksLikeCore($path . DIRECTORY_SEPARATOR . 'system')) {
                $this->removeDirectory($path);
            }
        }

        foreach (array_slice($this->listBackups(), self::KEEP_BACKUPS) as $backup) {
            $this->removeDirectory($backup['path']);
        }
    }

    public function removeBackup(string $name): array
    {
        $path = $this->resolveBackupPath($name);
        if ($path === null) {
            return ['ok' => false, 'error' => 'That backup does not exist.'];
        }

        if (!$this->removeDirectory($path)) {
            return ['ok' => false, 'error' => 'The stored version could not be deleted from disk.'];
        }

        return ['ok' => true, 'error' => null];
    }

    public function resolveBackupPath(string $name): ?string
    {
        if (!str_starts_with($name, 'backup-')
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || str_contains($name, '..')) {
            return null;
        }

        $path = $this->environment->workPath() . DIRECTORY_SEPARATOR . $name;

        return is_dir($path) ? $path : null;
    }

    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Recursive delete, fenced to the project root and refusing to follow
     * symlinks out of it. The root is canonical (see Environment), so the
     * prefix comparison cannot be defeated by a symlinked path component.
     */
    private function removeDirectory(string $path): bool
    {
        $root = $this->environment->root();
        $real = realpath($path);

        if ($real === false || $real === $root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (is_link($path)) {
            return @unlink($path);
        }

        $entries = @scandir($real);
        if ($entries === false) {
            return false;
        }

        $ok = true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $real . DIRECTORY_SEPARATOR . $entry;

            if (is_link($child)) {
                $ok = @unlink($child) && $ok;
                continue;
            }

            if (is_dir($child)) {
                $ok = $this->removeDirectory($child) && $ok;
                continue;
            }

            $ok = @unlink($child) && $ok;
        }

        return @rmdir($real) && $ok;
    }

    /**
     * The one failure an ordinary installation can run into that is not a
     * mistake by the admin, so it says what happened and what it means.
     */
    private static function renameRefusedMessage(string $reason): string
    {
        return 'This filesystem does not allow the system directory to be renamed, '
            . 'so the update was stopped before anything was changed. Update manually instead'
            . ($reason !== '' ? ': ' . self::lastErrorDetail($reason) : '.');
    }

    private static function lastErrorDetail(string $message): string
    {
        $position = strrpos($message, '): ');

        return $position === false ? $message : substr($message, $position + 3);
    }
}
