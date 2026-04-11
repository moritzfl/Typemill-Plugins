<?php

namespace Plugins\versions\Models;

use Typemill\Models\StorageWrapper;

class AssetVersionStore
{
    private StorageWrapper $storage;
    private VersionRecordRepository $records;
    private LineDiff $diff;

    public function __construct(StorageWrapper $storage, VersionRecordRepository $records, LineDiff $diff)
    {
        $this->storage = $storage;
        $this->records = $records;
        $this->diff = $diff;
    }

    public function storeDeletion(
        string $assetType,
        string $name,
        string $username,
        int $maxVersions,
        string $userLabel,
        string $versionId
    ): array {
        $assetType = $this->sanitizeType($assetType);
        $name = basename(trim($name));

        if ($name === '') {
            return ['success' => false, 'message' => 'Asset name is missing.'];
        }

        $snapshotFiles = $this->createSnapshotFiles($assetType, $name);
        if (empty($snapshotFiles)) {
            return ['success' => false, 'message' => ucfirst($assetType) . ' not found.'];
        }

        $recordId = $this->resolveRecordId($assetType, $name);
        $record = $this->records->loadAssetRecord($recordId);
        $timestamp = time();
        $label = $this->resolveLabel($assetType);

        $isoNow = gmdate('c', $timestamp);

        $entry = [
            'id' => $versionId,
            'action' => 'delete',
            'timestamp' => $timestamp,
            'created_at' => $isoNow,
            'updated_at' => $isoNow,
            'username' => $username,
            'user_label' => $userLabel,
            'status' => null,
            'item_type' => 'asset_' . $assetType,
            'asset_type' => $assetType,
            'title' => $name,
            'url' => $this->resolveUrl($assetType, $name),
            'path' => $this->resolveUrl($assetType, $name),
            'path_without_type' => '',
            'markdown' => '',
            'metadata' => [
                'asset_type' => $assetType,
                'name' => $name,
                'label' => $label,
            ],
            'snapshot_files' => $snapshotFiles,
            'restorable' => true,
            'deleted_snapshot' => true,
            'previewable' => false,
        ];

        $record['versions'][] = $entry;
        if (count($record['versions']) > $maxVersions) {
            $record['versions'] = array_slice($record['versions'], -1 * $maxVersions);
        }

        $record['asset'] = [
            'record_id' => $recordId,
            'asset_type' => $assetType,
            'name' => $name,
            'title' => $name,
            'url' => $entry['url'],
            'path' => $entry['path'],
            'updated_at' => $isoNow,
        ];

        $record['deleted'] = [
            'record_type' => 'asset',
            'record_id' => $recordId,
            'version_id' => $entry['id'],
            'timestamp' => $timestamp,
            'deleted_at' => $isoNow,
            'username' => $username,
            'user_label' => $entry['user_label'],
            'title' => $entry['title'],
            'url' => $entry['url'],
            'path' => $entry['path'],
            'item_type' => $entry['item_type'],
            'asset_type' => $assetType,
            'previewable' => false,
        ];

        $this->records->saveAssetRecord($recordId, $record);

        return [
            'success' => true,
            'record_id' => $recordId,
            'version_id' => $entry['id'],
        ];
    }

    public function listDeletedEntries(): array
    {
        $entries = [];

        foreach ($this->records->loadAllAssetRecords() as $record) {
            if (empty($record['deleted'])) {
                continue;
            }

            $entries[] = [
                'record_type' => 'asset',
                'record_id' => $record['deleted']['record_id'],
                'pageid' => $record['deleted']['record_id'],
                'version_id' => $record['deleted']['version_id'],
                'title' => $record['deleted']['title'],
                'url' => $record['deleted']['url'],
                'path' => $record['deleted']['path'],
                'item_type' => $record['deleted']['item_type'],
                'asset_type' => $record['deleted']['asset_type'] ?? null,
                'deleted_at' => $record['deleted']['deleted_at'],
                'username' => $record['deleted']['username'],
                'user_label' => $record['deleted']['user_label'],
                'previewable' => false,
            ];
        }

        return $entries;
    }

    public function getVersionDetail(string $recordId, string $versionId): ?array
    {
        $record = $this->records->loadAssetRecord($recordId);
        $version = $this->findVersion($record, $versionId);
        if (!$version) {
            return null;
        }

        $label = $version['metadata']['label'] ?? ucfirst($version['asset_type'] ?? 'asset');
        $snapshotCount = count($version['snapshot_files'] ?? []);

        return [
            'version' => [
                'id' => $version['id'],
                'action' => $version['action'],
                'created_at' => $version['created_at'],
                'updated_at' => $version['updated_at'] ?? $version['created_at'],
                'username' => $version['username'],
                'user_label' => $version['user_label'] ?? $version['username'],
                'status' => null,
                'title' => $version['title'] ?? '',
                'url' => $version['url'] ?? '/',
                'markdown' => $label . ' snapshot stored for restore. Captured files: ' . $snapshotCount,
                'metadata' => $version['metadata'] ?? [],
                'restorable' => $version['restorable'] ?? true,
                'deleted_snapshot' => $version['deleted_snapshot'] ?? true,
            ],
            'compare_to' => [
                'label' => 'deleted asset',
                'created_at' => null,
                'user_label' => null,
                'version_id' => null,
            ],
            'diff' => $this->diff->compare('', ''),
        ];
    }

    public function selectPrimaryDownloadFile(array $downloadFiles, array $version): ?array
    {
        if (empty($downloadFiles)) {
            return null;
        }

        $assetType = $version['asset_type'] ?? $version['metadata']['asset_type'] ?? 'file';
        $assetName = basename((string) ($version['metadata']['name'] ?? $version['title'] ?? ''));

        if ($assetType !== 'image') {
            foreach ($downloadFiles as $file) {
                if (basename($file['path']) === $assetName) {
                    return $file;
                }
            }

            return $downloadFiles[0];
        }

        foreach (['liveFolder', 'fileFolder', 'originalFolder', 'thumbsFolder', 'customFolder'] as $location) {
            foreach ($downloadFiles as $file) {
                if (($file['location'] ?? null) === $location) {
                    return $file;
                }
            }
        }

        foreach ($downloadFiles as $file) {
            if (basename($file['path']) === $assetName) {
                return $file;
            }
        }

        return $downloadFiles[0];
    }

    private function createSnapshotFiles(string $assetType, string $name): array
    {
        if ($assetType === 'image') {
            return $this->snapshotImageFiles($name);
        }

        return $this->snapshotMediaFile($name);
    }

    private function snapshotMediaFile(string $name): array
    {
        $content = $this->storage->getFile('fileFolder', '', $name);
        if ($content === false) {
            return [];
        }

        return [[
            'location' => 'fileFolder',
            'path' => $name,
            'content_base64' => base64_encode($content),
        ]];
    }

    private function snapshotImageFiles(string $name): array
    {
        $name = basename($name);
        $pathInfo = pathinfo($name);
        $baseName = $pathInfo['filename'] ?? '';
        $extension = $pathInfo['extension'] ?? '';

        if ($baseName === '') {
            return [];
        }

        $snapshots = [];
        $snapshots = array_merge($snapshots, $this->snapshotAssetLocationFiles('liveFolder', [$name]));
        $snapshots = array_merge($snapshots, $this->snapshotAssetLocationFiles('thumbsFolder', [$name]));
        $snapshots = array_merge($snapshots, $this->snapshotAssetLocationFiles('originalFolder', $this->globLocationFiles('originalFolder', $baseName . '.*')));

        if ($extension !== '') {
            $snapshots = array_merge(
                $snapshots,
                $this->snapshotAssetLocationFiles('customFolder', $this->globLocationFiles('customFolder', $baseName . '-*.' . $extension))
            );
        }

        return $snapshots;
    }

    private function snapshotAssetLocationFiles(string $location, array $filenames): array
    {
        $snapshots = [];
        foreach ($filenames as $filename) {
            $filename = basename($filename);
            if ($filename === '') {
                continue;
            }

            $content = $this->storage->getFile($location, '', $filename);
            if ($content === false) {
                continue;
            }

            $snapshots[] = [
                'location' => $location,
                'path' => $filename,
                'content_base64' => base64_encode($content),
            ];
        }

        return $snapshots;
    }

    private function globLocationFiles(string $location, string $pattern): array
    {
        $folderPath = $this->storage->getFolderPath($location);
        if (!$folderPath) {
            return [];
        }

        $matches = glob($folderPath . $pattern);
        if (!$matches) {
            return [];
        }

        return array_map('basename', $matches);
    }

    private function findVersion(array $record, string $versionId): ?array
    {
        foreach ($record['versions'] ?? [] as $version) {
            if (($version['id'] ?? null) === $versionId) {
                return $version;
            }
        }

        return null;
    }

    private function resolveRecordId(string $assetType, string $name): string
    {
        return sha1('asset|' . $assetType . '|' . $name);
    }

    private function resolveUrl(string $assetType, string $name): string
    {
        if ($assetType === 'image') {
            return 'media/live/' . $name;
        }

        return 'media/files/' . $name;
    }

    private function resolveLabel(string $assetType): string
    {
        return $assetType === 'image' ? 'Image' : 'File';
    }

    private function sanitizeType(string $assetType): string
    {
        return $assetType === 'image' ? 'image' : 'file';
    }
}
