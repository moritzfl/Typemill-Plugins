<?php

namespace Plugins\versions\Models;

use Typemill\Models\StorageWrapper;

class VersionRecordRepository
{
    private string $pluginName;
    private StorageWrapper $storage;

    public function __construct(StorageWrapper $storage, string $pluginName = 'versions')
    {
        $this->storage = $storage;
        $this->pluginName = $pluginName;

        $this->storage->createFolder('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . 'pages');
        $this->storage->createFolder('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . 'assets');
    }

    public function loadAllRecords(): array
    {
        return array_merge($this->loadAllPageRecords(), $this->loadAllAssetRecords());
    }

    public function loadAllPageRecords(): array
    {
        return $this->loadRecordsFromFolder('pages', static function (string $id): array {
            return [
                'pageid' => $id,
                'page' => [],
                'versions' => [],
                'deleted' => null,
            ];
        });
    }

    public function loadAllAssetRecords(): array
    {
        return $this->loadRecordsFromFolder('assets', static function (string $id): array {
            return [
                'record_id' => $id,
                'asset' => [],
                'versions' => [],
                'deleted' => null,
            ];
        });
    }

    private function isValidRecordId(string $recordId): bool
    {
        // Real record IDs are hex: sha1() (40 chars) or bin2hex(random_bytes(8))
        // (16 chars). This guard keeps a user-supplied record_id from escaping
        // the storage folder via path traversal into recursiveDeleteDir/getFile.
        return $recordId !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $recordId) === 1;
    }

    public function loadPageRecord(string $pageId): array
    {
        return $this->loadRecord('pages', $pageId, static function (string $id): array {
            return [
                'pageid' => $id,
                'page' => [],
                'versions' => [],
                'deleted' => null,
            ];
        });
    }

    public function savePageRecord(string $pageId, array $record): bool
    {
        return $this->saveRecord('pages', $pageId, $record);
    }

    public function loadAssetRecord(string $recordId): array
    {
        return $this->loadRecord('assets', $recordId, static function (string $id): array {
            return [
                'record_id' => $id,
                'asset' => [],
                'versions' => [],
                'deleted' => null,
            ];
        });
    }

    public function saveAssetRecord(string $recordId, array $record): bool
    {
        return $this->saveRecord('assets', $recordId, $record);
    }

    public function deleteTrashEntry(string $recordId, string $recordType = 'page'): bool
    {
        if (!$this->isValidRecordId($recordId)) {
            return false;
        }

        $folder = $recordType === 'asset' ? 'assets' : 'pages';

        // Delete external snapshot files for this record
        $snapshotBase = rtrim($this->storage->getFolderPath('dataFolder'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $this->pluginName
            . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . $recordId;
        if (is_dir($snapshotBase)) {
            $this->recursiveDeleteDir($snapshotBase);
        }

        return $this->storage->deleteFile('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder, $recordId . '.json');
    }

    private function recursiveDeleteDir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->recursiveDeleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function loadRecordsFromFolder(string $folder, callable $defaultRecord): array
    {
        $path = $this->storage->getFolderPath('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder);
        if (!$path || !is_dir($path)) {
            return [];
        }

        $records = [];
        foreach (array_diff(scandir($path), ['.', '..']) as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }

            $recordId = substr($file, 0, -5);
            $records[] = $this->loadRecord($folder, $recordId, $defaultRecord);
        }

        return $records;
    }

    private function loadRecord(string $folder, string $recordId, callable $defaultRecord): array
    {
        if (!$this->isValidRecordId($recordId)) {
            return $defaultRecord($recordId);
        }

        $raw = $this->storage->getFile('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder, $recordId . '.json');
        if (!$raw) {
            return $defaultRecord($recordId);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('[versions] Malformed record file for ID ' . $recordId . ' in folder ' . $folder . '; returning default.');
            return $defaultRecord($recordId);
        }

        $fallback = $defaultRecord($recordId);
        foreach ($fallback as $key => $value) {
            if (!array_key_exists($key, $decoded)) {
                $decoded[$key] = $value;
            }
        }

        return $decoded;
    }

    private function saveRecord(string $folder, string $recordId, array $record): bool
    {
        if (!$this->isValidRecordId($recordId)) {
            return false;
        }

        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return (bool) $this->storage->writeFile('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder, $recordId . '.json', $json);
    }
}
