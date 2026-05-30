<?php

namespace Plugins\versions\Models;

class TrashDownloadService
{
    public function __construct(private AssetVersionStore $assetVersions)
    {
    }

    public function createPackage(string $recordId, string $recordType, array $version): ?array
    {
        $downloadFiles = $this->assetVersions->collectSnapshotContents($version);
        if ($downloadFiles === []) {
            return null;
        }

        if ($recordType === 'asset') {
            $assetFile = $this->assetVersions->selectPrimaryDownloadFile($downloadFiles, $version);
            if ($assetFile) {
                $filename = basename($assetFile['path']);

                return [
                    'filename' => $filename,
                    'content' => $assetFile['content'],
                    'mime_type' => 'application/octet-stream',
                ];
            }
        }

        if (count($downloadFiles) === 1) {
            $singleFile = $downloadFiles[0];
            $filename = basename($singleFile['path']);

            return [
                'filename' => $filename,
                'content' => $singleFile['content'],
                'mime_type' => 'application/octet-stream',
            ];
        }

        $baseName = $this->doSanitizeArchiveName($version['title'] ?? $recordId);
        $zip = ZipCreator::open();
        if ($zip === null) {
            return null;
        }

        $addedFiles = 0;
        foreach ($downloadFiles as $file) {
            if ($zip->addFromString($file['path'], $file['content'])) {
                $addedFiles++;
            }
        }

        $zipContent = $zip->finish();
        if ($addedFiles === 0 || $zipContent === null) {
            return null;
        }

        return [
            'filename' => $baseName . '.zip',
            'content' => $zipContent,
            'mime_type' => 'application/zip',
        ];
    }

    public function createPreviewFile(array $version): ?array
    {
        return $this->assetVersions->getPreviewFile($version);
    }

    public function sanitizeArchiveName(string $value): string
    {
        return $this->doSanitizeArchiveName($value);
    }

    private function doSanitizeArchiveName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'trash-entry';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? 'trash-entry';
        $value = trim($value, '-.');

        return $value !== '' ? $value : 'trash-entry';
    }
}
