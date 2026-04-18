<?php

namespace Plugins\versions\Models;

use Typemill\Models\Content;
use Typemill\Models\StorageWrapper;
use Typemill\Models\User;

class VersionStore
{
    private const MAX_SNAPSHOT_FILES = 500;
    private const MAX_SNAPSHOT_BYTES = 50 * 1024 * 1024; // 50 MB

    private StorageWrapper $storage;
    private LineDiff $diff;
    private VersionRecordRepository $records;
    private AssetVersionStore $assetVersions;
    private PageSnapshotService $snapshots;
    private TrashDownloadService $downloads;

    public function __construct()
    {
        $this->storage = new StorageWrapper('\Typemill\Models\Storage');
        $this->diff = new LineDiff();
        $this->records = new VersionRecordRepository($this->storage, 'versions');
        $this->assetVersions = new AssetVersionStore($this->storage, $this->records, $this->diff);
        $this->snapshots = new PageSnapshotService($this->storage);
        $this->downloads = new TrashDownloadService($this->assetVersions);
    }

    public function getSettings(array $pluginSettings = []): array
    {
        return [
            'retention_days' => $this->sanitizeInteger($pluginSettings['retention_days'] ?? 30, 1, 3650, 30),
            'group_hours' => $this->sanitizeInteger($pluginSettings['group_hours'] ?? 24, 1, 120, 24),
            'max_versions' => $this->sanitizeInteger($pluginSettings['max_versions'] ?? 50, 1, 1000, 50),
        ];
    }

    public function storeVersion(
        object $item,
        array $metadata,
        string $markdown,
        string $username,
        array $pluginSettings = [],
        string $action = 'update',
        array $options = []
    ): array {
        $settings = $this->getSettings($pluginSettings);
        $pageId = $this->resolvePageId($item, $metadata);
        $record = $this->records->loadPageRecord($pageId);
        $timestamp = time();

        // When content is unchanged, store a lightweight event entry (no markdown copy)
        // so status changes like publish/unpublish still appear in the timeline.
        // Delete and restore_deleted are always full entries regardless of content.
        // Compare against the last *non-event-only* version — event-only entries have
        // markdown: null and must not be used as the baseline or every event after them
        // would appear to have changed content.
        $lastContentMarkdown = null;
        foreach (array_reverse($record['versions']) as $v) {
            if (!($v['event_only'] ?? false)) {
                $lastContentMarkdown = $v['markdown'] ?? null;
                break;
            }
        }
        $contentChanged = in_array($action, ['delete', 'restore_deleted'], true)
            || ($lastContentMarkdown === null && $markdown !== '')
            || $lastContentMarkdown !== $markdown;

        $isoNow = gmdate('c', $timestamp);
        $title  = $this->resolveTitle($item, $metadata);

        $entry = [
            'id' => $this->generateVersionId(),
            'action' => $action,
            'created_at' => $isoNow,
            'updated_at' => $isoNow,
            'username' => $username,
            'user_label' => $this->resolveUserLabel($username),
            'status' => $item->status ?? null,
            'item_type' => $item->elementType ?? 'file',
            'title' => $title,
            'url' => $item->urlRelWoF ?? '/',
            'path' => $item->path ?? '',
            'path_without_type' => $item->pathWithoutType ?? '',
            'markdown' => $contentChanged ? $markdown : null,
            'metadata' => $contentChanged ? $metadata : [],
            'snapshot_files' => $options['snapshot_files'] ?? [],
            'restorable' => $options['restorable'] ?? true,
            'deleted_snapshot' => $action === 'delete',
            'event_only' => !$contentChanged,
        ];

        $forceNew = $options['force_new'] ?? false;

        if (
            !$contentChanged ||
            (
                !$forceNew &&
                $action === 'update' &&
                !empty($record['versions']) &&
                $this->shouldMergeIntoLastVersion($record['versions'], $username, $settings['group_hours'])
            )
        ) {
            // Event-only entries and same-session updates both append (never merge events into content versions)
            if (!$contentChanged) {
                $record['versions'][] = $entry;
            } else {
                $lastIndex = array_key_last($record['versions']);
                $entry['id'] = $record['versions'][$lastIndex]['id'];
                $entry['created_at'] = $record['versions'][$lastIndex]['created_at'];
                $record['versions'][$lastIndex] = $entry;
            }
        } else {
            $record['versions'][] = $entry;
        }

        if (count($record['versions']) > $settings['max_versions']) {
            $record['versions'] = array_slice($record['versions'], -1 * $settings['max_versions']);
        }

        $record['page'] = [
            'pageid' => $pageId,
            'title' => $entry['title'],
            'url' => $entry['url'],
            'path' => $entry['path'],
            'path_without_type' => $entry['path_without_type'],
            'item_type' => $entry['item_type'],
            'status' => $entry['status'],
            'updated_at' => $isoNow,
        ];

        if ($action === 'delete') {
            $record['deleted'] = [
                'pageid' => $pageId,
                'version_id' => $entry['id'],
                'deleted_at' => $isoNow,
                'username' => $username,
                'user_label' => $entry['user_label'],
                'title' => $entry['title'],
                'url' => $entry['url'],
                'path' => $entry['path'],
                'item_type' => $entry['item_type'],
            ];
        } elseif ($action === 'restore_deleted') {
            $record['deleted'] = null;
        }

        $this->records->savePageRecord($pageId, $record);

        return $entry;
    }

    public function getPageSummariesByItem(object $item, array $metadata): array
    {
        $pageId = $this->resolvePageId($item, $metadata);
        $record = $this->records->loadPageRecord($pageId);
        $versions = [];

        $previousMarkdown = null;
        foreach ($record['versions'] as $entry) {
            $isEventOnly = $entry['event_only'] ?? false;

            if ($isEventOnly) {
                $diffStats = ['added' => 0, 'removed' => 0];
            } else {
                $diff = $this->diff->compare($previousMarkdown ?? '', $entry['markdown'] ?? '');
                $diffStats = $diff['stats'];
                $previousMarkdown = $entry['markdown'] ?? '';
            }

            $versions[] = [
                'id' => $entry['id'],
                'action' => $entry['action'],
                'created_at' => $entry['created_at'],
                'updated_at' => $entry['updated_at'] ?? $entry['created_at'],
                'username' => $entry['username'],
                'user_label' => $entry['user_label'] ?? $entry['username'],
                'status' => $entry['status'] ?? null,
                'title' => $entry['title'] ?? '',
                'url' => $entry['url'] ?? '/',
                'restorable' => $entry['restorable'] ?? true,
                'deleted_snapshot' => $entry['deleted_snapshot'] ?? false,
                'event_only' => $isEventOnly,
                'diff_stats' => $diffStats,
            ];
        }

        return [
            'pageid' => $pageId,
            'deleted' => $record['deleted'],
            'versions' => array_reverse($versions),
        ];
    }

    public function storeAssetDeletion(
        string $assetType,
        string $name,
        string $username,
        array $pluginSettings = []
    ): array {
        $settings = $this->getSettings($pluginSettings);
        return $this->assetVersions->storeDeletion(
            $assetType,
            $name,
            $username,
            $settings['max_versions'],
            $this->resolveUserLabel($username),
            $this->generateVersionId()
        );
    }

    public function getVersionDetailByPageId(string $pageId, string $versionId): ?array
    {
        $record = $this->records->loadPageRecord($pageId);
        $versions = $record['versions'];
        $selectedIndex = $this->findVersionIndex($versions, $versionId);

        if ($selectedIndex === null) {
            return null;
        }

        $selected = $versions[$selectedIndex];
        $selectedContentIndex = $selected['event_only'] ?? false
            ? $this->findPreviousContentVersionIndex($versions, $selectedIndex, true)
            : $selectedIndex;
        $previousContentIndex = ($selected['event_only'] ?? false)
            ? $selectedContentIndex
            : $this->findPreviousContentVersionIndex($versions, $selectedIndex, false);

        $selectedMarkdown = $selectedContentIndex !== null
            ? (string) ($versions[$selectedContentIndex]['markdown'] ?? '')
            : '';
        $previous = $previousContentIndex !== null ? $versions[$previousContentIndex] : null;
        $diff = $this->diff->compare($previous['markdown'] ?? '', $selectedMarkdown);

        return [
            'version' => [
                'id' => $selected['id'],
                'action' => $selected['action'],
                'created_at' => $selected['created_at'],
                'updated_at' => $selected['updated_at'] ?? $selected['created_at'],
                'username' => $selected['username'],
                'user_label' => $selected['user_label'] ?? $selected['username'],
                'status' => $selected['status'] ?? null,
                'title' => $selected['title'] ?? '',
                'url' => $selected['url'] ?? '/',
                'markdown' => $selectedMarkdown,
                'metadata' => $selected['metadata'] ?? [],
                'restorable' => $selected['restorable'] ?? true,
                'deleted_snapshot' => $selected['deleted_snapshot'] ?? false,
                'event_only' => $selected['event_only'] ?? false,
            ],
            'compare_to' => [
                'label' => $previous ? ($previous['created_at'] ?? 'previous version') : 'empty document',
                'created_at' => $previous['created_at'] ?? null,
                'user_label' => $previous['user_label'] ?? $previous['username'] ?? null,
                'version_id' => $previous['id'] ?? null,
            ],
            'diff' => $diff,
        ];
    }

    private function findVersionIndex(array $versions, string $versionId): ?int
    {
        foreach ($versions as $index => $entry) {
            if (($entry['id'] ?? null) === $versionId) {
                return $index;
            }
        }

        return null;
    }

    private function findPreviousContentVersionIndex(array $versions, int $startIndex, bool $includeStart): ?int
    {
        $index = $includeStart ? $startIndex : $startIndex - 1;

        for (; $index >= 0; $index--) {
            if (!($versions[$index]['event_only'] ?? false)) {
                return $index;
            }
        }

        return null;
    }

    public function restoreVersionToCurrentPage(object $item, array $currentMetadata, string $versionId): array
    {
        $pageId = $this->resolvePageId($item, $currentMetadata);
        $record = $this->records->loadPageRecord($pageId);
        $version = $this->findVersion($record, $versionId);

        if (!$version) {
            return ['success' => false, 'message' => 'Version not found.'];
        }

        $content = new Content();
        $markdownArray = $content->markdownTextToArray($version['markdown'] ?? '');

        if (($version['status'] ?? '') === 'published') {
            $result = $content->publishMarkdown($item, $markdownArray);
        } elseif (($version['status'] ?? '') === 'unpublished') {
            $result = $content->unpublishMarkdown($item, $markdownArray);
        } else {
            $result = $content->saveDraftMarkdown($item, $markdownArray);
        }

        if ($result !== true) {
            error_log('[versions] Restore failed for page ' . ($item->path ?? '?') . ': ' . (is_string($result) ? $result : 'unknown error'));
            return ['success' => false, 'message' => 'Version restore failed.'];
        }

        if (!empty($version['metadata'])) {
            $this->storage->updateYaml('contentFolder', '', $item->pathWithoutType . '.yaml', $version['metadata']);
        }

        return ['success' => true];
    }

    public function listDeletedEntries(array $pluginSettings = []): array
    {
        $settings = $this->getSettings($pluginSettings);
        $this->purgeExpiredTrash($settings);

        $entries = [];
        foreach ($this->records->loadAllPageRecords() as $record) {
            if (empty($record['deleted'])) {
                continue;
            }

            $entries[] = [
                'record_type' => 'page',
                'record_id' => $record['deleted']['pageid'],
                'pageid' => $record['deleted']['pageid'],
                'version_id' => $record['deleted']['version_id'],
                'title' => $record['deleted']['title'],
                'url' => $record['deleted']['url'],
                'path' => $record['deleted']['path'],
                'item_type' => $record['deleted']['item_type'],
                'deleted_at' => $record['deleted']['deleted_at'],
                'username' => $record['deleted']['username'],
                'user_label' => $record['deleted']['user_label'],
                'previewable' => true,
            ];
        }

        $entries = array_merge($entries, $this->assetVersions->listDeletedEntries());

        usort($entries, static function ($a, $b) {
            return strcmp($b['deleted_at'], $a['deleted_at']);
        });

        return $entries;
    }

    public function restoreDeletedEntry(string $recordId, string $versionId, bool $force = false, string $recordType = 'page'): array
    {
        $recordType = $this->sanitizeRecordType($recordType);
        $record = $recordType === 'asset'
            ? $this->records->loadAssetRecord($recordId)
            : $this->records->loadPageRecord($recordId);
        $version = $this->findVersion($record, $versionId);

        if (!$version || empty($version['snapshot_files'])) {
            return ['success' => false, 'message' => 'Deleted version not found.'];
        }

        $conflicts = $this->findSnapshotConflicts($version['snapshot_files']);
        if (!$force && !empty($conflicts)) {
            return [
                'success' => false,
                'message' => 'A page or folder already exists at the original location.',
                'conflicts' => $conflicts,
            ];
        }

        $operations = [];
        foreach ($version['snapshot_files'] as $file) {
            $filePath = str_replace('\\', '/', (string) ($file['path'] ?? ''));
            if (!$this->isValidSnapshotPath($filePath)) {
                return ['success' => false, 'message' => 'Invalid file path in snapshot.'];
            }

            $location = $file['location'] ?? 'contentFolder';
            $content = isset($file['content_base64']) ? base64_decode($file['content_base64'], true) : ($file['content'] ?? '');
            if ($content === false) {
                return ['success' => false, 'message' => 'A deleted snapshot could not be decoded.'];
            }

            $directory = dirname($filePath);
            $folder = ($directory !== '.' && $directory !== '') ? $directory : '';
            $filename = basename($filePath);

            $operations[] = [
                'location' => $location,
                'directory' => $directory,
                'folder' => $folder,
                'filename' => $filename,
                'content' => $content,
            ];
        }

        $writtenFiles = [];
        $createdDirectories = [];

        try {
            foreach ($operations as $operation) {
                $location = $operation['location'];
                $directory = $operation['directory'];

                if ($directory !== '.' && $directory !== '' && !$this->directoryExists($location, $directory)) {
                    if (!$this->storage->createFolder($location, $directory)) {
                        throw new \RuntimeException('Failed to create restore directory.');
                    }
                    $createdDirectories[] = [
                        'location' => $location,
                        'path' => $directory,
                    ];
                }

                if (!$this->storage->writeFile($location, $operation['folder'], $operation['filename'], $operation['content'])) {
                    throw new \RuntimeException('Failed to write restored file.');
                }

                $writtenFiles[] = [
                    'location' => $location,
                    'folder' => $operation['folder'],
                    'filename' => $operation['filename'],
                ];
            }
        } catch (\Throwable $e) {
            $this->rollbackRestoreFiles($writtenFiles, $createdDirectories);
            return ['success' => false, 'message' => 'Deleted version could not be restored completely.'];
        }

        $record['deleted'] = null;
        if ($recordType === 'asset') {
            $this->records->saveAssetRecord($recordId, $record);
        } else {
            $this->records->savePageRecord($recordId, $record);
        }

        return ['success' => true];
    }

    public function deleteTrashEntry(string $recordId, string $recordType = 'page'): bool
    {
        return $this->records->deleteTrashEntry($recordId, $this->sanitizeRecordType($recordType));
    }

    public function emptyTrash(array $pluginSettings = []): array
    {
        $deleted = 0;
        foreach ($this->listDeletedEntries($pluginSettings) as $entry) {
            if ($this->deleteTrashEntry($entry['record_id'], $entry['record_type'] ?? 'page')) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted];
    }

    public function purgeExpiredTrash(array $settings): int
    {
        $deleted = 0;
        $retentionDays = $settings['retention_days'];
        $threshold = strtotime('-' . $retentionDays . ' days');

        foreach ($this->records->loadAllRecords() as $record) {
            if (empty($record['deleted']['deleted_at'])) {
                continue;
            }

            $deletedTime = strtotime($record['deleted']['deleted_at']);
            if ($deletedTime === false || $deletedTime > $threshold) {
                continue;
            }

            $recordId = $record['deleted']['record_id'] ?? $record['deleted']['pageid'] ?? null;
            $recordType = $record['deleted']['record_type'] ?? 'page';
            if ($recordId && $this->deleteTrashEntry($recordId, $recordType)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function createSnapshotFiles(object $item): array
    {
        return $this->snapshots->createSnapshotFiles($item);
    }

    public function getTrashVersionDetail(string $recordId, string $versionId, string $recordType = 'page'): ?array
    {
        $recordType = $this->sanitizeRecordType($recordType);
        if ($recordType === 'asset') {
            return $this->assetVersions->getVersionDetail($recordId, $versionId);
        }

        return $this->getVersionDetailByPageId($recordId, $versionId);
    }

    public function createTrashDownloadPackage(string $recordId, string $versionId, string $recordType = 'page'): ?array
    {
        $recordType = $this->sanitizeRecordType($recordType);
        $record = $recordType === 'asset'
            ? $this->records->loadAssetRecord($recordId)
            : $this->records->loadPageRecord($recordId);
        $version = $this->findVersion($record, $versionId);

        return $version ? $this->downloads->createPackage($recordId, $recordType, $version) : null;
    }

    public function readCurrentMarkdown(object $item): string
    {
        $content = new Content();
        $markdownArray = $content->getDraftMarkdown($item);

        return $content->markdownArrayToText($markdownArray);
    }

    private function findVersion(array $record, string $versionId): ?array
    {
        foreach ($record['versions'] as $version) {
            if (($version['id'] ?? null) === $versionId) {
                return $version;
            }
        }

        return null;
    }

    private function resolvePageId(object $item, array $metadata): string
    {
        if (!empty($metadata['meta']['pageid'])) {
            return (string) $metadata['meta']['pageid'];
        }

        return sha1(($item->pathWithoutType ?? $item->path ?? 'unknown') . '|' . ($item->urlRelWoF ?? '/'));
    }

    private function resolveTitle(object $item, array $metadata): string
    {
        $title = $metadata['meta']['title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        $navtitle = $metadata['meta']['navtitle'] ?? null;
        if (is_string($navtitle) && trim($navtitle) !== '') {
            return trim($navtitle);
        }

        return $item->name ?? $item->slug ?? 'Untitled';
    }

    private function resolveUserLabel(string $username): string
    {
        $user = new User();
        if ($user->setUser($username)) {
            $fullName = $user->getFullName();
            if ($fullName) {
                return $fullName . ' (' . $username . ')';
            }
        }

        return $username;
    }

    private function rollbackRestoreFiles(array $writtenFiles, array $createdDirectories): void
    {
        foreach (array_reverse($writtenFiles) as $file) {
            $this->storage->deleteFile($file['location'], $file['folder'], $file['filename']);
        }

        foreach (array_reverse($createdDirectories) as $directory) {
            $this->removeDirectoryIfEmpty($directory['location'], $directory['path']);
        }
    }

    private function directoryExists(string $location, string $path): bool
    {
        $fullPath = $this->resolveStoragePath($location, $path);
        return $fullPath !== null && is_dir($fullPath);
    }

    private function removeDirectoryIfEmpty(string $location, string $path): void
    {
        $fullPath = $this->resolveStoragePath($location, $path);
        if ($fullPath === null || !is_dir($fullPath)) {
            return;
        }

        @rmdir($fullPath);
    }

    private function resolveStoragePath(string $location, string $path = ''): ?string
    {
        $basePath = $this->storage->getFolderPath($location);
        if (!is_string($basePath) || $basePath === '') {
            return null;
        }

        $trimmedPath = trim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $resolvedBasePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        return $trimmedPath === ''
            ? $resolvedBasePath
            : $resolvedBasePath . DIRECTORY_SEPARATOR . $trimmedPath;
    }

    private function snapshotPageFiles(string $pathWithoutType): array
    {
        return $this->snapshots->snapshotPageFiles($pathWithoutType);
    }

    private function snapshotFolderFiles(string $folderPath): array
    {
        return $this->snapshots->snapshotFolderFiles($folderPath);
    }

    private function findSnapshotConflicts(array $snapshotFiles): array
    {
        return $this->snapshots->findSnapshotConflicts($snapshotFiles);
    }

    private function sanitizeArchiveName(string $value): string
    {
        return $this->downloads->sanitizeArchiveName($value);
    }

    private function shouldMergeIntoLastVersion(array $versions, string $username, int $groupHours): bool
    {
        $last = end($versions);
        if (!$last) {
            return false;
        }

        if (($last['action'] ?? null) !== 'update') {
            return false;
        }

        if (($last['username'] ?? null) !== $username) {
            return false;
        }

        $lastTimestamp = (int) ($last['updated_at'] ? strtotime($last['updated_at']) : strtotime($last['created_at'] ?? 'now'));
        if (!$lastTimestamp) {
            return false;
        }

        return (time() - $lastTimestamp) < ($groupHours * 3600);
    }

    private function generateVersionId(): string
    {
        return 'version_' . bin2hex(random_bytes(12));
    }

    private function isValidSnapshotPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Reject null bytes
        if (strpos($path, "\0") !== false) {
            return false;
        }

        // Reject absolute paths
        if (str_starts_with($path, '/')) {
            return false;
        }

        // Reject path traversal segments
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function sanitizeRecordType(string $recordType): string
    {
        return $recordType === 'asset' ? 'asset' : 'page';
    }

    private function sanitizeInteger($value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $value = (int) $value;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }

}
