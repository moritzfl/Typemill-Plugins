<?php

namespace Plugins\typemillupdate\Models;

use ZipArchive;

/**
 * Puts a validated directory plugin in place.
 *
 * Staging and the backup live under plugins/.tm-update/, on the same
 * filesystem as the live plugin. That matters when plugins/ is a bind mount
 * (the Docker test setup): renaming from the project-root working directory
 * would cross filesystems and fail with EXDEV. Renames inside plugins/ do not.
 *
 * The live folder is renamed aside, then the staged folder is renamed into
 * its place. A filesystem that refuses the first rename stops here, with the
 * installed plugin untouched.
 */
class PluginInstaller
{
    public const KEEP_BACKUPS = 1;

    private Environment $environment;
    private Installer $installer;
    private string $stamp;

    public function __construct(Environment $environment, Installer $installer)
    {
        $this->environment = $environment;
        $this->installer = $installer;
        $this->stamp = $installer->stamp();
    }

    public static function looksLikePlugin(string $path, string $slug): bool
    {
        return Environment::isPluginSlug($slug)
            && is_file($path . DIRECTORY_SEPARATOR . $slug . '.php')
            && is_file($path . DIRECTORY_SEPARATOR . $slug . '.yaml');
    }

    public function livePath(string $slug): string
    {
        return $this->environment->pluginsPath() . DIRECTORY_SEPARATOR . $slug;
    }

    public function stagingPath(): string
    {
        return $this->environment->pluginWorkPath() . DIRECTORY_SEPARATOR . 'staging-' . $this->stamp;
    }

    public function backupPath(string $slug): string
    {
        return $this->environment->pluginWorkPath() . DIRECTORY_SEPARATOR . 'backup-' . $slug . '--' . $this->stamp;
    }

    /**
     * Extract only the named plugin.
     *
     * @return array{ok: bool, error?: string, error_key?: string, path?: string}
     */
    public function stage(string $zipPath, string $slug, string $prefix = ''): array
    {
        if (!Environment::isPluginSlug($slug)) {
            return self::problem('That is not a valid plugin name.', 'err_plugin_slug');
        }

        $staging = $this->stagingPath();

        if (!@mkdir($staging, 0755, true) && !is_dir($staging)) {
            return self::problem('Could not create the staging directory.', 'err_plugin_stage_failed');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return self::problem('Could not open the archive.', 'err_plugin_stage_failed');
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (Release::isSafeEntryName($name) && Registry::isPluginEntry($name, $slug, $prefix)) {
                $entries[] = $name;
            }
        }

        if ($entries === []) {
            $zip->close();

            return self::problem('The archive contains no files for ' . $slug . '.', 'err_plugin_stage_failed');
        }

        $extracted = $zip->extractTo($staging, $entries);
        $zip->close();

        if (!$extracted) {
            return self::problem('Extracting the archive failed.', 'err_plugin_stage_failed');
        }

        $stagedPlugin = $staging . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $prefix)
            . $slug;

        if (!self::looksLikePlugin($stagedPlugin, $slug)) {
            return self::problem('The staged plugin is incomplete.', 'err_plugin_incomplete');
        }

        return ['ok' => true, 'error' => null, 'error_key' => null, 'path' => $stagedPlugin, 'entries' => count($entries)];
    }

    /**
     * Swap the staged plugin over the live one.
     *
     * @return array{ok: bool, touched: bool, error?: string, error_key?: string, backup?: string}
     */
    public function install(string $slug, string $stagedPlugin): array
    {
        if (!self::looksLikePlugin($stagedPlugin, $slug)) {
            return ['touched' => false] + self::problem('The plugin to install is incomplete.', 'err_plugin_incomplete');
        }

        $live = $this->livePath($slug);

        if (is_link($live)) {
            return ['touched' => false] + self::problem(
                'That plugin is a symlink; replacing it is not supported.',
                'err_plugin_symlink'
            );
        }

        if (!self::looksLikePlugin($live, $slug)) {
            return ['touched' => false] + self::problem(
                'The installed plugin ' . $slug . ' is incomplete, so it was not replaced.',
                'err_plugin_incomplete'
            );
        }

        if (!$this->environment->ensurePluginWorkPath()) {
            return ['touched' => false] + self::problem(
                'Could not create the working directory for plugin updates.',
                'err_plugin_workdir'
            );
        }

        $backup = $this->backupPath($slug);
        if (file_exists($backup)) {
            return ['touched' => false] + self::problem(
                'Could not create a backup of the installed plugin.',
                'err_plugin_backup'
            );
        }

        $this->installer->resetOpcache();

        error_clear_last();
        if (!@rename($live, $backup)) {
            $reason = error_get_last()['message'] ?? '';

            return ['touched' => false] + self::problem(
                'This filesystem does not allow the plugin directory to be renamed, so the update was stopped before anything was changed.'
                    . ($reason !== '' ? ' ' . $reason : ''),
                'err_plugin_rename_unsupported'
            );
        }

        if (!@rename($stagedPlugin, $live)) {
            $restored = @rename($backup, $live);

            return ['touched' => !$restored] + ($restored
                ? self::problem(
                    'Could not move the new plugin into place. The previous version was restored.',
                    'err_plugin_install_move_failed'
                )
                : self::problem(
                    'Could not move the new plugin into place, and restoring the previous version failed. The previous version is at ' . $backup . '.',
                    'err_plugin_install_stranded',
                    ['path' => $backup]
                ));
        }

        clearstatcache(true);
        $this->installer->resetOpcache();

        return ['ok' => true, 'touched' => true, 'error' => null, 'error_key' => null, 'backup' => $backup];
    }

    /**
     * Drop staging leftovers and older backups for this slug.
     */
    public function cleanup(string $slug): void
    {
        $work = $this->environment->pluginWorkPath();
        if (!is_dir($work)) {
            return;
        }

        foreach ((array) @scandir($work) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }

            $path = $work . DIRECTORY_SEPARATOR . $entry;

            if (str_starts_with($entry, 'staging-')) {
                $this->installer->deleteInsideRoot($path);
            }
        }

        $prefix = 'backup-' . $slug . '--';
        $backups = [];
        foreach ((array) @scandir($work) as $entry) {
            if (!is_string($entry) || !str_starts_with($entry, $prefix)) {
                continue;
            }

            $path = $work . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $backups[] = ['path' => $path, 'created' => (int) @filemtime($path)];
        }

        usort($backups, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);

        foreach (array_slice($backups, self::KEEP_BACKUPS) as $backup) {
            $this->installer->deleteInsideRoot($backup['path']);
        }
    }

    private static function problem(string $message, string $key, array $params = []): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'error_key' => 'typemillupdate.' . $key,
            'error_params' => $params,
        ];
    }
}
