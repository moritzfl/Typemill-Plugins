<?php

namespace Plugins\versions\Models;

use Typemill\Models\StorageWrapper;

class VersionExportService
{
    private const EXPORT_FORMAT_VERSION = 2;

    private const MAX_EXPORT_BYTES = 200 * 1024 * 1024;

    private const MAX_EXPORT_FILES = 10_000;

    public function createPageExport(string $recordId, array $record, array $pageMeta = []): ?array
    {
        $payload = [
            'export_format' => self::EXPORT_FORMAT_VERSION,
            'exported_at' => gmdate('c'),
            'record_type' => 'page',
            'record_id' => $recordId,
            'page' => $record['page'] ?? [],
            'page_meta' => $pageMeta,
            'versions' => $record['versions'] ?? [],
            'deleted' => $record['deleted'] ?? null,
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[versions] Page export JSON encoding failed: ' . $e->getMessage());

            return null;
        }

        $title = (string) ($record['page']['title'] ?? $recordId);

        return [
            'filename' => $this->sanitizeFilename($title) . '-versions.json',
            'content' => $json,
            'mime_type' => 'application/json; charset=UTF-8',
        ];
    }

    /**
     * @return list<string>
     */
    public function listMediaSubfolders(StorageWrapper $storage): array
    {
        $mediaRoot = $this->resolveMediaRoot($storage);
        if ($mediaRoot === null || !is_dir($mediaRoot)) {
            return [];
        }

        $folders = [];
        foreach (scandir($mediaRoot) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($entry === '' || $entry[0] === '.') {
                continue;
            }

            $path = $mediaRoot . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path) || !$this->isValidMediaSubfolderName($entry)) {
                continue;
            }

            if ($this->shouldSkipMediaSubfolder($entry)) {
                continue;
            }

            $folders[] = $entry;
        }

        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);

        return $folders;
    }

    /**
     * @return array<string, int>
     */
    public function getMediaSubfolderSizes(StorageWrapper $storage): array
    {
        $mediaRoot = $this->resolveMediaRoot($storage);
        if ($mediaRoot === null || !is_dir($mediaRoot)) {
            return [];
        }

        $sizes = [];
        foreach ($this->listMediaSubfolders($storage) as $folder) {
            $subdir = $mediaRoot . DIRECTORY_SEPARATOR . $folder;
            if (is_dir($subdir)) {
                $sizes[$folder] = $this->directorySize($subdir);
            }
        }

        return $sizes;
    }

    public function createFullExport(
        VersionRecordRepository $records,
        StorageWrapper $storage,
        ExportOptions $options
    ): ?array {
        $pageRecords = $records->loadAllPageRecords();
        $assetRecords = $options->includeRecycleBin ? $records->loadAllAssetRecords() : [];

        $zip = ZipCreator::open();
        if ($zip === null) {
            return null;
        }

        $stats = [
            'files' => 0,
            'bytes' => 0,
            'content_files' => 0,
            'media_files' => 0,
        ];
        $pageRecordsExported = 0;
        $assetRecordsExported = 0;

        try {
            $contentRoot = rtrim($storage->getFolderPath('contentFolder'), DIRECTORY_SEPARATOR);
            if (is_dir($contentRoot)) {
                $before = $stats['files'];
                $this->addDirectoryToZip($zip, $contentRoot, 'content', $stats);
                $stats['content_files'] = $stats['files'] - $before;
            }

            $mediaRoot = $this->resolveMediaRoot($storage);
            if ($mediaRoot !== null && $options->mediaFolders !== []) {
                $before = $stats['files'];
                foreach ($options->mediaFolders as $folder) {
                    if (!$this->isValidMediaSubfolderName($folder) || $this->shouldSkipMediaSubfolder($folder)) {
                        continue;
                    }

                    $subdir = $mediaRoot . DIRECTORY_SEPARATOR . $folder;
                    if (is_dir($subdir)) {
                        $this->addDirectoryToZip($zip, $subdir, 'media/' . $folder, $stats);
                    }
                }
                $stats['media_files'] = $stats['files'] - $before;
            }

            foreach ($pageRecords as $record) {
                if (!$options->includeRecycleBin && !empty($record['deleted'])) {
                    continue;
                }

                $recordId = (string) ($record['pageid'] ?? '');
                if ($recordId === '') {
                    continue;
                }

                $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $zip->addFromString(
                    'versions/pages/' . $this->sanitizeFilename($recordId) . '.json',
                    $json
                );
                $this->trackFileExport($stats, strlen($json));
                $pageRecordsExported++;
            }

            foreach ($assetRecords as $record) {
                $recordId = (string) ($record['record_id'] ?? '');
                if ($recordId === '') {
                    continue;
                }

                $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $zip->addFromString(
                    'versions/assets/' . $this->sanitizeFilename($recordId) . '.json',
                    $json
                );
                $this->trackFileExport($stats, strlen($json));
                $assetRecordsExported++;
            }

            if ($options->includeRecycleBin) {
                $this->addSnapshotDirectoryToZip($zip, $storage, $stats);
            }
        } catch (ExportTooLargeException $exception) {
            error_log('[versions] Full export aborted: ' . $exception->getMessage());

            return null;
        }

        $includes = ['content'];
        if ($options->mediaFolders !== []) {
            $includes[] = 'media';
        }
        $includes[] = 'versions';
        if ($options->includeRecycleBin) {
            $includes[] = 'recycle_bin';
        }

        $manifest = [
            'export_format' => self::EXPORT_FORMAT_VERSION,
            'exported_at' => gmdate('c'),
            'plugin' => 'versions',
            'includes' => $includes,
            'media_folders' => $options->mediaFolders,
            'include_recycle_bin' => $options->includeRecycleBin,
            'content_files' => $stats['content_files'],
            'media_files' => $stats['media_files'],
            'version_pages' => $pageRecordsExported,
            'version_assets' => $assetRecordsExported,
            'total_files' => $stats['files'],
            'total_bytes' => $stats['bytes'],
        ];

        $zip->addFromString(
            'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $zipContent = $zip->finish();
        if ($zipContent === null) {
            return null;
        }

        return [
            'filename' => 'versions-export-' . gmdate('Y-m-d') . '.zip',
            'content' => $zipContent,
            'mime_type' => 'application/zip',
        ];
    }

    private function resolveMediaRoot(StorageWrapper $storage): ?string
    {
        $fileFolder = rtrim($storage->getFolderPath('fileFolder'), DIRECTORY_SEPARATOR);
        if ($fileFolder === '') {
            return null;
        }

        $mediaRoot = dirname($fileFolder);
        if ($mediaRoot === '' || $mediaRoot === '.' || $mediaRoot === DIRECTORY_SEPARATOR) {
            return null;
        }

        return $mediaRoot;
    }

    private function addSnapshotDirectoryToZip(ZipCreator $zip, StorageWrapper $storage, array &$stats): void
    {
        $snapshotsPath = rtrim($storage->getFolderPath('dataFolder'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'versions'
            . DIRECTORY_SEPARATOR . 'snapshots';

        if (!is_dir($snapshotsPath)) {
            return;
        }

        $this->addDirectoryToZip($zip, $snapshotsPath, 'versions/snapshots', $stats);
    }

    private function addDirectoryToZip(ZipCreator $zip, string $directory, string $zipPrefix, array &$stats): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $relativePath = ltrim(str_replace($directory, '', $absolutePath), DIRECTORY_SEPARATOR);
            $relativePath = str_replace('\\', '/', $relativePath);

            if ($this->shouldSkipExportPath($relativePath)) {
                continue;
            }

            $fileSize = $fileInfo->getSize();
            if ($fileSize === false) {
                continue;
            }

            $this->trackFileExport($stats, (int) $fileSize);

            $zipPath = $zipPrefix . '/' . $relativePath;
            $zip->addFile($zipPath, $absolutePath);
        }
    }

    private function directorySize(string $directory): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $relativePath = ltrim(str_replace($directory, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
            $relativePath = str_replace('\\', '/', $relativePath);
            if ($this->shouldSkipExportPath($relativePath)) {
                continue;
            }

            $fileSize = $fileInfo->getSize();
            if ($fileSize === false) {
                continue;
            }

            $size += (int) $fileSize;
        }

        return $size;
    }

    private function shouldSkipExportPath(string $relativePath): bool
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '') {
            return true;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.tmp') {
                return true;
            }
        }

        return false;
    }

    private function trackFileExport(array &$stats, int $bytes): void
    {
        $stats['files']++;
        $stats['bytes'] += $bytes;

        if ($stats['files'] > self::MAX_EXPORT_FILES) {
            throw new ExportTooLargeException(
                'Export exceeds the ' . self::MAX_EXPORT_FILES . '-file limit.'
            );
        }

        if ($stats['bytes'] > self::MAX_EXPORT_BYTES) {
            throw new ExportTooLargeException(
                'Export exceeds the ' . (self::MAX_EXPORT_BYTES / 1024 / 1024) . ' MB size limit.'
            );
        }
    }

    private function isValidMediaSubfolderName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
    }

    private function shouldSkipMediaSubfolder(string $name): bool
    {
        return $name === '.tmp' || $name === 'tmp';
    }

    private function sanitizeFilename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'export';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? 'export';
        $value = trim($value, '-.');

        return $value !== '' ? $value : 'export';
    }
}
