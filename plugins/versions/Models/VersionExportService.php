<?php

namespace Plugins\versions\Models;

use Typemill\Models\StorageWrapper;

class VersionExportService
{
    private const EXPORT_FORMAT_VERSION = 1;

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

    public function createFullExport(VersionRecordRepository $records, StorageWrapper $storage): ?array
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $pageRecords = $records->loadAllPageRecords();
        $assetRecords = $records->loadAllAssetRecords();

        $tempPath = tempnam(sys_get_temp_dir(), 'tm_versions_export_');
        if ($tempPath === false) {
            return null;
        }

        $zipPath = $tempPath . '.zip';
        if (!unlink($tempPath)) {
            error_log('[versions] Failed to remove temp placeholder file: ' . $tempPath);
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            $manifest = [
                'export_format' => self::EXPORT_FORMAT_VERSION,
                'exported_at' => gmdate('c'),
                'plugin' => 'versions',
                'pages' => count($pageRecords),
                'assets' => count($assetRecords),
            ];

            $zip->addFromString(
                'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            foreach ($pageRecords as $record) {
                $recordId = (string) ($record['pageid'] ?? '');
                if ($recordId === '') {
                    continue;
                }

                $zip->addFromString(
                    'pages/' . $this->sanitizeFilename($recordId) . '.json',
                    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }

            foreach ($assetRecords as $record) {
                $recordId = (string) ($record['record_id'] ?? '');
                if ($recordId === '') {
                    continue;
                }

                $zip->addFromString(
                    'assets/' . $this->sanitizeFilename($recordId) . '.json',
                    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }

            $this->addSnapshotDirectoryToZip($zip, $storage);

            $zip->close();

            if (!file_exists($zipPath)) {
                return null;
            }

            $zipContent = file_get_contents($zipPath);
            if ($zipContent === false) {
                return null;
            }

            return [
                'filename' => 'versions-export-' . gmdate('Y-m-d') . '.zip',
                'content' => $zipContent,
                'mime_type' => 'application/zip',
            ];
        } finally {
            if (file_exists($zipPath) && !unlink($zipPath)) {
                error_log('[versions] Failed to remove export zip temp file: ' . $zipPath);
            }
        }
    }

    private function addSnapshotDirectoryToZip(\ZipArchive $zip, StorageWrapper $storage): void
    {
        $snapshotsPath = rtrim($storage->getFolderPath('dataFolder'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'versions'
            . DIRECTORY_SEPARATOR . 'snapshots';

        if (!is_dir($snapshotsPath)) {
            return;
        }

        $this->addDirectoryToZip($zip, $snapshotsPath, 'snapshots');
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $directory, string $zipPrefix): void
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
            $zipPath = $zipPrefix . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

            $zip->addFile($absolutePath, $zipPath);
        }
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
