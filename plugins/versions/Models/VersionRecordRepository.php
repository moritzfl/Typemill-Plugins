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
        $folder = $recordType === 'asset' ? 'assets' : 'pages';

        return $this->storage->deleteFile('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder, $recordId . '.json');
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
        $raw = $this->storage->getFile('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder, $recordId . '.json');
        if (!$raw) {
            return $defaultRecord($recordId);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
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
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return (bool) $this->storage->writeFile('dataFolder', $this->pluginName . DIRECTORY_SEPARATOR . $folder, $recordId . '.json', $json);
    }
}
