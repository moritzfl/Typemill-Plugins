<?php

namespace Plugins\versions\Models;

use Typemill\Models\StorageWrapper;

class PageSnapshotService
{
    private const MAX_SNAPSHOT_FILES = 500;
    private const MAX_SNAPSHOT_BYTES = 50 * 1024 * 1024; // 50 MB

    public function __construct(private StorageWrapper $storage)
    {
    }

    public function createSnapshotFiles(object $item): array
    {
        if (($item->elementType ?? 'file') === 'folder') {
            return $this->doSnapshotFolderFiles($item->path ?? '');
        }

        return $this->doSnapshotPageFiles($item->pathWithoutType ?? '');
    }

    public function snapshotPageFiles(string $pathWithoutType): array
    {
        return $this->doSnapshotPageFiles($pathWithoutType);
    }

    public function snapshotFolderFiles(string $folderPath): array
    {
        return $this->doSnapshotFolderFiles($folderPath);
    }

    public function findSnapshotConflicts(array $snapshotFiles): array
    {
        $conflicts = [];
        foreach ($snapshotFiles as $file) {
            $location = $file['location'] ?? 'contentFolder';
            if ($this->storage->checkFile($location, '', $file['path'])) {
                $conflicts[] = $file['path'];
            }
        }

        return $conflicts;
    }

    private function doSnapshotPageFiles(string $pathWithoutType): array
    {
        $files = [];
        foreach (['md', 'txt', 'yaml'] as $extension) {
            $relativePath = $pathWithoutType . '.' . $extension;
            $content = $this->storage->getFile('contentFolder', '', $relativePath);
            if ($content !== false) {
                $files[] = [
                    'location' => 'contentFolder',
                    'path' => $relativePath,
                    'content' => $content,
                ];
            }
        }

        return $files;
    }

    private function doSnapshotFolderFiles(string $folderPath): array
    {
        $basePath = rtrim($this->storage->getFolderPath('contentFolder'), DIRECTORY_SEPARATOR);
        $fullPath = $basePath . DIRECTORY_SEPARATOR . trim($folderPath, DIRECTORY_SEPARATOR);

        if (!is_dir($fullPath)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        $totalBytes = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (count($files) >= self::MAX_SNAPSHOT_FILES) {
                throw new SnapshotTooLargeException('This folder exceeds the ' . self::MAX_SNAPSHOT_FILES . '-file limit for the recycle bin.');
            }

            if ($totalBytes + $file->getSize() > self::MAX_SNAPSHOT_BYTES) {
                throw new SnapshotTooLargeException('This folder exceeds the ' . (self::MAX_SNAPSHOT_BYTES / 1024 / 1024) . ' MB size limit for the recycle bin.');
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            $totalBytes += strlen($content);
            $path = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $files[] = [
                'location' => 'contentFolder',
                'path' => str_replace('\\', '/', $path),
                'content' => $content,
            ];
        }

        return $files;
    }
}
