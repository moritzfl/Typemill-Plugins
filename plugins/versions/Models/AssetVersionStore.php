<?php

namespace Plugins\versions\Models;

use Typemill\Models\StorageWrapper;

class AssetVersionStore
{
    private const MAX_SNAPSHOT_FILES = 500;
    private const MAX_SNAPSHOT_BYTES = 50 * 1024 * 1024;

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

        $recordId = $this->resolveRecordId($assetType, $name);
        $snapshotFiles = $this->createSnapshotFiles($assetType, $name, $recordId, $versionId);
        if (empty($snapshotFiles)) {
            return ['success' => false, 'message' => ucfirst($assetType) . ' not found.'];
        }

        $record = $this->records->loadAssetRecord($recordId);
        $label = $this->resolveLabel($assetType);

        $isoNow = gmdate('c');

        $entry = [
            'id' => $versionId,
            'action' => 'delete',
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
        ];
        $entry['previewable'] = $this->isVersionPreviewable($entry);

        $record['versions'][] = $entry;
        if (count($record['versions']) > $maxVersions) {
            $dropped = array_slice($record['versions'], 0, count($record['versions']) - $maxVersions);
            $record['versions'] = array_slice($record['versions'], -1 * $maxVersions);
            foreach ($dropped as $droppedVersion) {
                $this->cleanupVersionSnapshots($recordId, $droppedVersion['id']);
            }
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
            'deleted_at' => $isoNow,
            'username' => $username,
            'user_label' => $entry['user_label'],
            'title' => $entry['title'],
            'url' => $entry['url'],
            'path' => $entry['path'],
            'item_type' => $entry['item_type'],
            'asset_type' => $assetType,
            'previewable' => $entry['previewable'],
        ];

        $this->records->saveAssetRecord($recordId, $record);

        return [
            'success' => true,
            'record_id' => $recordId,
            'version_id' => $entry['id'],
        ];
    }

    public function storeMediaFilesDeletion(
        string $relativePath,
        string $username,
        int $maxVersions,
        string $userLabel,
        string $versionId
    ): array {
        $relativePath = $this->normalizeMediaFilesPath($relativePath);
        if ($relativePath === null) {
            return ['success' => false, 'message' => 'Invalid path.'];
        }

        $assetType = 'mediafiles';
        $recordId = $this->resolveRecordId($assetType, $relativePath);

        try {
            $snapshotFiles = $this->createMediaFilesSnapshots($relativePath, $recordId, $versionId);
        } catch (SnapshotTooLargeException $exception) {
            throw $exception;
        }

        if (empty($snapshotFiles)) {
            return ['success' => false, 'message' => 'File or folder not found.'];
        }

        $displayTitle = basename($relativePath) ?: $relativePath;

        $base = rtrim($this->storage->getFolderPath('fileFolder'), DIRECTORY_SEPARATOR);
        $absolute = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $elementType = is_dir($absolute) ? 'folder' : 'file';

        $record = $this->records->loadAssetRecord($recordId);
        $label = $this->resolveLabel($assetType);
        $isoNow = gmdate('c');

        $entry = [
            'id' => $versionId,
            'action' => 'delete',
            'created_at' => $isoNow,
            'updated_at' => $isoNow,
            'username' => $username,
            'user_label' => $userLabel,
            'status' => null,
            'item_type' => 'asset_' . $assetType,
            'asset_type' => $assetType,
            'title' => $displayTitle,
            'url' => $this->resolveUrl($assetType, $relativePath),
            'path' => $this->resolveUrl($assetType, $relativePath),
            'path_without_type' => '',
            'markdown' => '',
            'metadata' => [
                'asset_type' => $assetType,
                'name' => $relativePath,
                'label' => $label,
                'element_type' => $elementType,
            ],
            'snapshot_files' => $snapshotFiles,
            'restorable' => true,
            'deleted_snapshot' => true,
        ];
        $entry['previewable'] = $this->isVersionPreviewable($entry);

        $record['versions'][] = $entry;
        if (count($record['versions']) > $maxVersions) {
            $dropped = array_slice($record['versions'], 0, count($record['versions']) - $maxVersions);
            $record['versions'] = array_slice($record['versions'], -1 * $maxVersions);
            foreach ($dropped as $droppedVersion) {
                $this->cleanupVersionSnapshots($recordId, $droppedVersion['id']);
            }
        }

        $record['asset'] = [
            'record_id' => $recordId,
            'asset_type' => $assetType,
            'name' => $relativePath,
            'title' => $displayTitle,
            'url' => $entry['url'],
            'path' => $entry['path'],
            'updated_at' => $isoNow,
        ];

        $record['deleted'] = [
            'record_type' => 'asset',
            'record_id' => $recordId,
            'version_id' => $entry['id'],
            'deleted_at' => $isoNow,
            'username' => $username,
            'user_label' => $entry['user_label'],
            'title' => $entry['title'],
            'url' => $entry['url'],
            'path' => $entry['path'],
            'item_type' => $entry['item_type'],
            'asset_type' => $assetType,
            'previewable' => $entry['previewable'],
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

            $deletedVersion = $this->findVersion($record, (string) ($record['deleted']['version_id'] ?? ''));

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
                'previewable' => $deletedVersion
                    ? $this->isVersionPreviewable($deletedVersion)
                    : (bool) ($record['deleted']['previewable'] ?? false),
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

        $preview = $this->resolvePreviewMeta($version);
        $markdown = $preview['text'] ?? (
            ($version['metadata']['label'] ?? ucfirst($version['asset_type'] ?? 'asset'))
            . ' snapshot stored for restore. Captured files: '
            . count($version['snapshot_files'] ?? [])
        );

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
                'markdown' => $markdown,
                'metadata' => $version['metadata'] ?? [],
                'restorable' => $version['restorable'] ?? true,
                'deleted_snapshot' => $version['deleted_snapshot'] ?? true,
                'previewable' => $preview['previewable'],
                'preview_kind' => $preview['kind'] ?? null,
                'preview_mime' => $preview['mime_type'] ?? null,
                'preview_filename' => $preview['filename'] ?? null,
                'preview_files' => $preview['files'] ?? null,
                'preview_file_count' => $preview['file_count'] ?? null,
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
                $filePath = str_replace('\\', '/', (string) ($file['path'] ?? ''));
                if ($filePath === $assetName || basename($filePath) === basename($assetName)) {
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

    public function collectSnapshotContents(array $version): array
    {
        $downloadFiles = [];
        foreach ($version['snapshot_files'] ?? [] as $file) {
            $path = ltrim(str_replace('\\', '/', (string) ($file['path'] ?? '')), '/');
            if ($path === '') {
                continue;
            }

            $content = $this->readSnapshotFileContent($file);
            if ($content === null) {
                continue;
            }

            $downloadFiles[] = [
                'path' => $path,
                'content' => $content,
                'location' => $file['location'] ?? null,
            ];
        }

        return $downloadFiles;
    }

    public function isVersionPreviewable(array $version): bool
    {
        return $this->resolvePreviewMeta($version)['previewable'];
    }

    public function getPreviewFile(array $version): ?array
    {
        $preview = $this->resolvePreviewMeta($version);
        if (!$preview['previewable'] || $preview['kind'] === 'text') {
            return null;
        }

        $file = $preview['file'] ?? null;
        if (!$file || !isset($file['content'])) {
            return null;
        }

        return [
            'filename' => $preview['filename'],
            'content' => $file['content'],
            'mime_type' => $preview['mime_type'],
        ];
    }

    private function resolvePreviewMeta(array $version): array
    {
        $support = $this->getPreviewSupport();
        if ($support === null) {
            return ['previewable' => false];
        }

        $trashPreview = $this->getTrashPreviewResolver();
        if ($trashPreview !== null && $trashPreview->isFolderDeletion($version)) {
            return $trashPreview->resolveFolder(
                $trashPreview->buildSnapshotDescriptors($version['snapshot_files'] ?? [])
            );
        }

        $downloadFiles = $this->collectSnapshotContents($version);
        if ($downloadFiles === []) {
            return ['previewable' => false];
        }

        $primary = $this->selectPrimaryDownloadFile($downloadFiles, $version);
        $candidates = $primary ? [$primary] : [];
        foreach ($downloadFiles as $file) {
            if ($primary && ($file['path'] ?? '') === ($primary['path'] ?? '')) {
                continue;
            }
            $candidates[] = $file;
        }

        foreach ($candidates as $file) {
            $path = (string) ($file['path'] ?? '');
            $kind = $support->getPreviewKind($path);
            if ($kind === null) {
                continue;
            }

            $content = $file['content'] ?? '';
            $size = strlen($content);
            if ($size === 0 || $size > $support->maxPreviewBytes($kind)) {
                continue;
            }

            if ($kind === 'text' && !$support->isLikelyTextContent($content)) {
                continue;
            }

            $meta = [
                'previewable' => true,
                'kind' => $kind,
                'filename' => basename($path),
                'mime_type' => $support->guessMimeType($path),
                'file' => $file,
            ];

            if ($kind === 'text') {
                $meta['text'] = $content;
            }

            return $meta;
        }

        return ['previewable' => false];
    }

    private function getPreviewSupport(): ?\Plugins\preview\Models\PreviewSupport
    {
        if (!class_exists(\Plugins\preview\PreviewIntegration::class)) {
            return null;
        }

        if (!\Plugins\preview\PreviewIntegration::isAvailable()) {
            return null;
        }

        return \Plugins\preview\PreviewIntegration::support();
    }

    private function getTrashPreviewResolver(): ?\Plugins\preview\Models\TrashPreviewResolver
    {
        if (!class_exists(\Plugins\preview\PreviewIntegration::class)) {
            return null;
        }

        return \Plugins\preview\PreviewIntegration::trashPreviewResolver();
    }

    private function readSnapshotFileContent(array $file): ?string
    {
        if (isset($file['snapshot_path']) && is_string($file['snapshot_path']) && file_exists($file['snapshot_path'])) {
            $content = file_get_contents($file['snapshot_path']);

            return $content === false ? null : $content;
        }

        if (isset($file['content_base64'])) {
            $content = base64_decode((string) $file['content_base64'], true);

            return $content === false ? null : $content;
        }

        if (isset($file['content']) && is_string($file['content'])) {
            return $file['content'];
        }

        return null;
    }

    public function cleanupVersionSnapshots(string $recordId, string $versionId): void
    {
        $dir = $this->getSnapshotBasePath() . DIRECTORY_SEPARATOR . $recordId . DIRECTORY_SEPARATOR . $versionId;
        if (is_dir($dir)) {
            $this->recursiveDeleteDir($dir);
        }
    }

    private function createSnapshotFiles(string $assetType, string $name, string $recordId, string $versionId): array
    {
        if ($assetType === 'image') {
            return $this->snapshotImageFiles($name, $recordId, $versionId);
        }

        return $this->snapshotMediaFile($name, $recordId, $versionId);
    }

    private function createMediaFilesSnapshots(string $relativePath, string $recordId, string $versionId): array
    {
        $base = rtrim($this->storage->getFolderPath('fileFolder'), DIRECTORY_SEPARATOR);
        $absolute = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($absolute)) {
            return [];
        }

        if (is_file($absolute)) {
            return $this->snapshotMediaFilesEntry($absolute, $relativePath, $recordId, $versionId);
        }

        if (!is_dir($absolute)) {
            return [];
        }

        return $this->snapshotMediaFilesDirectory($absolute, $relativePath, $recordId, $versionId);
    }

    private function snapshotMediaFilesEntry(string $absolutePath, string $relativePath, string $recordId, string $versionId): array
    {
        $snapshotPath = $this->copyToSnapshot($absolutePath, $recordId, $versionId, $relativePath);
        if ($snapshotPath !== null) {
            return [[
                'location' => 'fileFolder',
                'path' => $relativePath,
                'snapshot_path' => $snapshotPath,
            ]];
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            return [];
        }

        return [[
            'location' => 'fileFolder',
            'path' => $relativePath,
            'content_base64' => base64_encode($content),
        ]];
    }

    private function snapshotMediaFilesDirectory(string $absoluteDir, string $relativeDir, string $recordId, string $versionId): array
    {
        $snapshots = [];
        $fileCount = 0;
        $totalBytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $subPath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($absoluteDir) + 1)), '/');
            $relativePath = $relativeDir === '' ? $subPath : $relativeDir . '/' . $subPath;
            if ($this->normalizeMediaFilesPath($relativePath) === null) {
                continue;
            }

            $fileCount++;
            if ($fileCount > self::MAX_SNAPSHOT_FILES) {
                throw new SnapshotTooLargeException(
                    'This folder exceeds the ' . self::MAX_SNAPSHOT_FILES . '-file limit for the recycle bin.'
                );
            }

            $fileSize = $fileInfo->getSize();
            if ($fileSize === false) {
                continue;
            }

            $totalBytes += $fileSize;
            if ($totalBytes > self::MAX_SNAPSHOT_BYTES) {
                throw new SnapshotTooLargeException(
                    'This folder exceeds the ' . (self::MAX_SNAPSHOT_BYTES / 1024 / 1024) . ' MB size limit for the recycle bin.'
                );
            }

            $entrySnapshots = $this->snapshotMediaFilesEntry($absolutePath, $relativePath, $recordId, $versionId);
            if (!empty($entrySnapshots)) {
                $snapshots = array_merge($snapshots, $entrySnapshots);
            }
        }

        return $snapshots;
    }

    private function normalizeMediaFilesPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if ($segment === '.tmp' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        return $path;
    }

    private function snapshotMediaFile(string $name, string $recordId, string $versionId): array
    {
        $srcPath = rtrim($this->storage->getFolderPath('fileFolder'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($srcPath)) {
            return [];
        }

        $snapshotPath = $this->copyToSnapshot($srcPath, $recordId, $versionId, basename($name));
        if ($snapshotPath !== null) {
            return [[
                'location' => 'fileFolder',
                'path' => $name,
                'snapshot_path' => $snapshotPath,
            ]];
        }

        // Fallback for very small files or copy failures
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

    private function snapshotImageFiles(string $name, string $recordId, string $versionId): array
    {
        $name = basename($name);
        $pathInfo = pathinfo($name);
        $baseName = $pathInfo['filename'] ?? '';
        $extension = $pathInfo['extension'] ?? '';

        if ($baseName === '') {
            return [];
        }

        // Strip glob meta-characters so user-supplied filenames cannot escape the pattern
        $baseName  = preg_replace('/[*?\[\]{}\\\\]/', '', $baseName) ?? '';
        $extension = preg_replace('/[*?\[\]{}\\\\]/', '', $extension) ?? '';

        if ($baseName === '') {
            return [];
        }

        $snapshots = [];
        $snapshots = array_merge($snapshots, $this->snapshotAssetLocationFiles('liveFolder', [$name], $recordId, $versionId));
        $snapshots = array_merge($snapshots, $this->snapshotAssetLocationFiles('thumbsFolder', [$name], $recordId, $versionId));
        $snapshots = array_merge($snapshots, $this->snapshotAssetLocationFiles('originalFolder', $this->globLocationFiles('originalFolder', $baseName . '.*'), $recordId, $versionId));

        if ($extension !== '') {
            $snapshots = array_merge(
                $snapshots,
                $this->snapshotAssetLocationFiles('customFolder', $this->globLocationFiles('customFolder', $baseName . '-*.' . $extension), $recordId, $versionId)
            );
        }

        return $snapshots;
    }

    private function snapshotAssetLocationFiles(string $location, array $filenames, string $recordId, string $versionId): array
    {
        $snapshots = [];
        foreach ($filenames as $filename) {
            $filename = basename($filename);
            if ($filename === '') {
                continue;
            }

            $srcPath = rtrim($this->storage->getFolderPath($location), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($srcPath)) {
                continue;
            }

            $snapshotPath = $this->copyToSnapshot($srcPath, $recordId, $versionId, $filename);
            if ($snapshotPath !== null) {
                $snapshots[] = [
                    'location' => $location,
                    'path' => $filename,
                    'snapshot_path' => $snapshotPath,
                ];
                continue;
            }

            // Fallback for small files or copy failures
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

    private function getSnapshotBasePath(): string
    {
        $base = $this->storage->getFolderPath('dataFolder');
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'versions' . DIRECTORY_SEPARATOR . 'snapshots';
    }

    private function copyToSnapshot(string $srcPath, string $recordId, string $versionId, string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        if ($relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return null;
        }

        $dir = $this->getSnapshotBasePath() . DIRECTORY_SEPARATOR . $recordId . DIRECTORY_SEPARATOR . $versionId;
        $destPath = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
            return null;
        }

        if (!copy($srcPath, $destPath)) {
            return null;
        }

        return $destPath;
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
        return match ($assetType) {
            'image' => 'Image',
            'mediafiles' => 'File',
            default => 'File',
        };
    }

    private function sanitizeType(string $assetType): string
    {
        return match ($assetType) {
            'image' => 'image',
            'mediafiles' => 'mediafiles',
            default => 'file',
        };
    }
}
