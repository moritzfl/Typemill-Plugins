<?php

namespace Plugins\coreupdate\Models;

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
     * A directory is only treated as a core if it carries the two things the
     * site cannot boot without. Everything that installs or restores a tree
     * checks this first, so an empty or truncated directory can never be moved
     * over the live core.
     */
    public static function looksLikeCore(string $path): bool
    {
        return is_dir($path . DIRECTORY_SEPARATOR . 'typemill')
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
     * Renaming is tried first because it is atomic and free. A failed rename
     * changes nothing, so falling back to copying afterwards is safe. The
     * fallback exists because some filesystems refuse to rename directories at
     * all: Docker's overlayfs returns EXDEV for any directory still living in
     * the image's lower layer, and network mounts such as CIFS behave similarly.
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

        $result = $this->installByRename($stagedSystem);

        if ($result['ok'] || empty($result['retryable'])) {
            return $result;
        }

        $copy = $this->installByCopy($stagedSystem);
        if (!$copy['ok'] && isset($result['error'])) {
            $copy['error'] .= ' (renaming was not possible either: ' . $result['error'] . ')';
        }

        return $copy;
    }

    /**
     * Atomic route.
     *
     * There is a sub-millisecond window between the two renames in which
     * `system` does not exist. A request landing exactly there fails. That
     * cannot be closed from PHP alone; it can only be kept short.
     */
    private function installByRename(string $stagedSystem): array
    {
        $system = $this->environment->systemPath();
        $backup = $this->backupPath();

        if (!@mkdir($backup, 0755, true) && !is_dir($backup)) {
            return ['ok' => false, 'touched' => false, 'retryable' => false, 'error' => 'Could not create the backup directory.'];
        }

        $backupSystem = $backup . DIRECTORY_SEPARATOR . 'system';

        $this->resetOpcache();

        error_clear_last();
        if (!@rename($system, $backupSystem)) {
            $reason = error_get_last()['message'] ?? '';
            @rmdir($backup);

            // Nothing has been touched, so the caller may still try copying.
            return [
                'ok' => false,
                'touched' => false,
                'retryable' => true,
                'error' => 'the system directory could not be renamed'
                    . ($reason !== '' ? ': ' . self::lastErrorDetail($reason) : ''),
            ];
        }

        if (!@rename($stagedSystem, $system)) {
            // Put the original back; the site must not be left without a
            // system directory.
            $restored = @rename($backupSystem, $system);

            return [
                'ok' => false,
                'touched' => !$restored,
                'retryable' => false,
                'error' => $restored
                    ? 'Could not move the new core into place. The previous core was restored.'
                    : 'Could not move the new core into place, and restoring the previous core failed. The previous core is at ' . $backupSystem . '.',
            ];
        }

        clearstatcache(true);
        $this->resetOpcache();

        return ['ok' => true, 'touched' => true, 'error' => null, 'backup' => $backup, 'method' => 'rename'];
    }

    /**
     * Copy route, for filesystems that will not rename directories.
     *
     * This is not atomic. To avoid ever leaving `system` empty, the new files
     * are written over the old ones and only then are leftovers from the
     * previous version removed. A verified full copy of the old core is taken
     * first, so a failure part way through can still be undone.
     */
    private function installByCopy(string $stagedSystem): array
    {
        $system = $this->environment->systemPath();
        $backup = $this->backupPath();
        $backupSystem = $backup . DIRECTORY_SEPARATOR . 'system';

        if (!@mkdir($backup, 0755, true) && !is_dir($backup)) {
            return ['ok' => false, 'touched' => false, 'error' => 'Could not create the backup directory.'];
        }

        // The backup is the only way back, so it is verified before the live
        // core is written to.
        if (!$this->copyTree($system, $backupSystem) || !self::looksLikeCore($backupSystem)) {
            $this->removeDirectory($backup);

            return ['ok' => false, 'touched' => false, 'error' => 'Could not copy the current core to a backup, so nothing was changed.'];
        }

        $this->resetOpcache();

        if (!$this->mergeTree($stagedSystem, $system)) {
            $restored = $this->mergeTree($backupSystem, $system);

            return [
                'ok' => false,
                'touched' => true,
                'error' => $restored
                    ? 'Could not copy the new core into place. The previous core was restored.'
                    : 'Could not copy the new core into place, and restoring the previous core failed. The previous core is at ' . $backupSystem . '.',
            ];
        }

        clearstatcache(true);
        $this->resetOpcache();

        return ['ok' => true, 'touched' => true, 'error' => null, 'backup' => $backup, 'method' => 'copy'];
    }

    /**
     * Put a stored core back in place.
     *
     * Mirrors install(): rename if the filesystem allows it, otherwise copy the
     * backup over the current core and remove whatever the newer version added.
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

        $this->resetOpcache();

        if (@mkdir($parked, 0755, true) || is_dir($parked)) {
            $parkedSystem = $parked . DIRECTORY_SEPARATOR . 'system';

            if (@rename($system, $parkedSystem)) {
                if (@rename($backupSystem, $system)) {
                    clearstatcache(true);
                    $this->resetOpcache();

                    return ['ok' => true, 'touched' => true, 'error' => null, 'method' => 'rename'];
                }

                // Could not complete the swap - undo the first move.
                if (!@rename($parkedSystem, $system)) {
                    return [
                        'ok' => false,
                        'touched' => true,
                        'error' => 'Could not restore the backup, and the current core could not be put back. It is at ' . $parkedSystem . '.',
                    ];
                }
            }

            // Only discard the parked directory when it does not hold the only
            // copy of a core.
            if (!is_dir($parkedSystem)) {
                $this->removeDirectory($parked);
            }
        }

        if (!$this->mergeTree($backupSystem, $system)) {
            return ['ok' => false, 'touched' => true, 'error' => 'Could not restore the backup. It is still at ' . $backupSystem . '.'];
        }

        clearstatcache(true);
        $this->resetOpcache();

        return ['ok' => true, 'touched' => true, 'error' => null, 'method' => 'copy'];
    }

    /**
     * Recursive copy.
     *
     * Fails rather than skipping when it meets a symlink: the core ships none,
     * so one that is present was put there deliberately and silently dropping
     * it would produce a backup that cannot restore the installation.
     */
    private function copyTree(string $source, string $destination): bool
    {
        if (is_link($source) || !is_dir($source)) {
            return false;
        }

        $entries = @scandir($source);
        if ($entries === false) {
            return false;
        }

        if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;

            if (is_link($from)) {
                return false;
            }

            if (is_dir($from)) {
                if (!$this->copyTree($from, $to)) {
                    return false;
                }
                continue;
            }

            if (!@copy($from, $to)) {
                return false;
            }

            $mode = @fileperms($from);
            if ($mode !== false) {
                @chmod($to, $mode & 0777);
            }
        }

        return true;
    }

    /**
     * Copy a core over another one, then drop whatever the source no longer
     * has. The source is checked first: pruning against an empty or partial
     * tree would delete the destination instead of updating it.
     */
    private function mergeTree(string $source, string $destination): bool
    {
        if (!self::looksLikeCore($source)) {
            return false;
        }

        if (!$this->copyTree($source, $destination)) {
            return false;
        }

        return $this->pruneExtras($source, $destination);
    }

    private function pruneExtras(string $source, string $destination): bool
    {
        $entries = @scandir($destination);
        if ($entries === false) {
            return false;
        }

        $ok = true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $counterpart = $source . DIRECTORY_SEPARATOR . $entry;
            $current = $destination . DIRECTORY_SEPARATOR . $entry;

            if (!file_exists($counterpart)) {
                if (is_dir($current) && !is_link($current)) {
                    $ok = $this->removeDirectory($current) && $ok;
                } else {
                    $ok = @unlink($current) && $ok;
                }
                continue;
            }

            if (is_dir($current) && is_dir($counterpart) && !is_link($current)) {
                $ok = $this->pruneExtras($counterpart, $current) && $ok;
            }
        }

        return $ok;
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
            CURLOPT_USERAGENT => 'Typemill-CoreUpdate/1.0 (self-test)',
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

    private static function lastErrorDetail(string $message): string
    {
        $position = strrpos($message, '): ');

        return $position === false ? $message : substr($message, $position + 3);
    }
}
