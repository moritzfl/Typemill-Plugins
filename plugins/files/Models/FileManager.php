<?php

namespace Plugins\files\Models;

class FileManager
{
    private const TMP_DIR_NAME = '.tmp';
    private const MAX_ZIP_BYTES = 200 * 1024 * 1024;

    private string $rootPath;

    private FileUploadMetaStore $uploadMeta;

    public function __construct(string $projectRoot)
    {
        $this->rootPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'files';
        if (!is_dir($this->rootPath)) {
            mkdir($this->rootPath, 0755, true);
        }

        $this->uploadMeta = new FileUploadMetaStore($this->rootPath);
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    public function getTmpDir(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . self::TMP_DIR_NAME;
    }

    public function normalizeRelativePath(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if ($this->isReservedName($segment)) {
                return null;
            }
        }

        return $path;
    }

    public function sanitizeEntryName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || $this->isReservedName($name)) {
            return null;
        }

        if (preg_match('/[\/\\\\\x00-\x1f]/', $name)) {
            return null;
        }

        return $name;
    }

    public function isReservedName(string $name): bool
    {
        return $name === '.' || $name === '..' || $name === self::TMP_DIR_NAME || str_starts_with($name, '.');
    }

    public function joinPath(string $parent, string $name): string
    {
        $parent = $this->normalizeRelativePath($parent) ?? '';

        return $parent === '' ? $name : $parent . '/' . $name;
    }

    public function resolveExistingPath(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $absolute = $this->absoluteFromRelative($relativePath);
        $resolved = realpath($absolute);
        $rootReal = realpath($this->rootPath);
        if ($resolved === false || $rootReal === false || !$this->isInsideRoot($resolved, $rootReal)) {
            return null;
        }

        return $resolved;
    }

    public function resolveDirectoryForWrite(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        if ($relativePath === '') {
            return realpath($this->rootPath) ?: $this->rootPath;
        }

        $absolute = $this->absoluteFromRelative($relativePath);
        $resolved = realpath($absolute);
        $rootReal = realpath($this->rootPath);
        if ($resolved === false || !is_dir($resolved) || $rootReal === false || !$this->isInsideRoot($resolved, $rootReal)) {
            return null;
        }

        return $resolved;
    }

    public function browse(string $relativePath): ?array
    {
        $directory = $this->resolveDirectoryForWrite($relativePath);
        if ($directory === null) {
            return null;
        }

        $relativePath = $this->normalizeRelativePath($relativePath) ?? '';
        $entries = scandir($directory);
        if ($entries === false) {
            return null;
        }

        $folders = [];
        $files = [];

        foreach ($entries as $entry) {
            if ($this->isReservedName($entry)) {
                continue;
            }

            $entryPath = $directory . DIRECTORY_SEPARATOR . $entry;
            $relativeEntry = $this->joinPath($relativePath, $entry);

            if (is_dir($entryPath)) {
                $folders[] = [
                    'name' => $entry,
                    'path' => $relativeEntry,
                ];
                continue;
            }

            if (!is_file($entryPath)) {
                continue;
            }

            $files[] = [
                'name' => $entry,
                'path' => $relativeEntry,
                'bytes' => filesize($entryPath) ?: 0,
                'timestamp' => filemtime($entryPath) ?: 0,
                'uploaded_by' => $this->uploadMeta->getUploader($relativeEntry),
            ];
        }

        usort($folders, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'path' => $relativePath,
            'parent' => $this->parentPath($relativePath),
            'breadcrumbs' => $this->buildBreadcrumbs($relativePath),
            'folders' => $folders,
            'files' => $files,
        ];
    }

    public function createFolder(string $parentPath, string $folderName): ?string
    {
        $folderName = $this->sanitizeEntryName($folderName);
        if ($folderName === null) {
            return 'files.msg_folder_invalid';
        }

        $parentPath = $this->normalizeRelativePath($parentPath);
        if ($parentPath === null) {
            return 'files.msg_folder_invalid';
        }

        $parentDirectory = $this->resolveDirectoryForWrite($parentPath);
        if ($parentDirectory === null) {
            return 'files.msg_folder_parent_missing';
        }

        $target = $parentDirectory . DIRECTORY_SEPARATOR . $folderName;
        if (file_exists($target)) {
            return 'files.msg_folder_exists';
        }

        if (!mkdir($target, 0755)) {
            return 'files.msg_folder_create_error';
        }

        return null;
    }

    public function recordUpload(string $relativePath, string $username): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        $this->uploadMeta->recordUpload($relativePath, $username);
    }

    public function deletePath(string $relativePath): bool
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null || $relativePath === '' || $relativePath === self::TMP_DIR_NAME) {
            return false;
        }

        if (str_starts_with($relativePath, self::TMP_DIR_NAME . '/')) {
            return false;
        }

        $absolute = $this->resolveExistingPath($relativePath);
        if ($absolute === null) {
            return false;
        }

        if (is_file($absolute)) {
            if (!unlink($absolute)) {
                return false;
            }

            $this->uploadMeta->removeEntry($relativePath, false);

            return true;
        }

        if (!is_dir($absolute)) {
            return false;
        }

        if (!$this->deleteDirectoryRecursive($absolute)) {
            return false;
        }

        $this->uploadMeta->removeEntry($relativePath, true);

        return true;
    }

    public function transferEntry(string $sourcePath, string $destinationFolderPath, bool $copy): ?string
    {
        $sourcePath = $this->normalizeRelativePath($sourcePath);
        $destinationFolderPath = $this->normalizeRelativePath($destinationFolderPath);
        if ($destinationFolderPath === null) {
            return 'files.msg_folder_invalid';
        }

        if ($sourcePath === null || $sourcePath === '') {
            return 'files.msg_transfer_invalid';
        }

        if ($sourcePath === self::TMP_DIR_NAME || str_starts_with($sourcePath, self::TMP_DIR_NAME . '/')) {
            return 'files.msg_transfer_invalid';
        }

        $sourceAbsolute = $this->resolveExistingPath($sourcePath);
        if ($sourceAbsolute === null) {
            return 'files.msg_transfer_not_found';
        }

        $destinationDirectory = $this->resolveDirectoryForWrite($destinationFolderPath);
        if ($destinationDirectory === null) {
            return 'files.msg_folder_parent_missing';
        }

        $sourceParent = $this->parentPath($sourcePath) ?? '';
        if (!$copy && $sourceParent === $destinationFolderPath) {
            return 'files.msg_transfer_same_location';
        }

        if (is_dir($sourceAbsolute)) {
            $normalizedSource = rtrim($sourceAbsolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $normalizedDestination = rtrim($destinationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($normalizedSource === $normalizedDestination || str_starts_with($normalizedDestination, $normalizedSource)) {
                return 'files.msg_transfer_into_self';
            }
        }

        $basename = basename(str_replace('\\', '/', $sourcePath));
        $targetAbsolute = $destinationDirectory . DIRECTORY_SEPARATOR . $basename;
        if (file_exists($targetAbsolute)) {
            return 'files.msg_transfer_exists';
        }

        $destinationRelative = $this->joinPath($destinationFolderPath, $basename);
        $sourceIsDirectory = is_dir($sourceAbsolute);

        if ($copy) {
            if (is_file($sourceAbsolute)) {
                if (!copy($sourceAbsolute, $targetAbsolute)) {
                    return 'files.msg_transfer_error';
                }

                $this->uploadMeta->copyEntry($sourcePath, $destinationRelative, false);

                return null;
            }

            if (!is_dir($sourceAbsolute)) {
                return 'files.msg_transfer_not_found';
            }

            if (!$this->copyDirectoryRecursive($sourceAbsolute, $targetAbsolute)) {
                return 'files.msg_transfer_error';
            }

            $this->uploadMeta->copyEntry($sourcePath, $destinationRelative, true);

            return null;
        }

        if (!rename($sourceAbsolute, $targetAbsolute)) {
            return 'files.msg_transfer_error';
        }

        $this->uploadMeta->relocateEntry($sourcePath, $destinationRelative, $sourceIsDirectory);

        return null;
    }

    public function getFileDownload(string $relativePath): ?array
    {
        $absolute = $this->resolveExistingPath($relativePath);
        if ($absolute === null || !is_file($absolute)) {
            return null;
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            return null;
        }

        return [
            'filename' => basename($absolute),
            'content' => $content,
            'mime_type' => 'application/octet-stream',
        ];
    }

    public function createFolderZip(string $relativePath): ?array
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $directory = $this->resolveExistingPath($relativePath);
        if ($directory === null || !is_dir($directory)) {
            return null;
        }

        if ($this->folderSize($directory) > self::MAX_ZIP_BYTES) {
            return null;
        }

        $zip = ZipCreator::open();
        if ($zip === null) {
            return null;
        }

        $baseName = basename($directory);
        $this->addDirectoryToZip($zip, $directory, $baseName);

        $zipContent = $zip->finish();
        if ($zipContent === null) {
            return null;
        }

        return [
            'filename' => $this->sanitizeArchiveName($baseName) . '.zip',
            'content' => $zipContent,
            'mime_type' => 'application/zip',
        ];
    }

    private function parentPath(string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }

        $parts = explode('/', $relativePath);
        array_pop($parts);

        return implode('/', $parts);
    }

    private function buildBreadcrumbs(string $relativePath): array
    {
        $crumbs = [
            ['name' => 'files', 'path' => ''],
        ];

        if ($relativePath === '') {
            return $crumbs;
        }

        $parts = explode('/', $relativePath);
        $built = '';
        foreach ($parts as $part) {
            $built = $built === '' ? $part : $built . '/' . $part;
            $crumbs[] = ['name' => $part, 'path' => $built];
        }

        return $crumbs;
    }

    private function absoluteFromRelative(string $relativePath): string
    {
        if ($relativePath === '') {
            return $this->rootPath;
        }

        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function isInsideRoot(string $path, string $rootReal): bool
    {
        if ($path === $rootReal) {
            return true;
        }

        $prefix = $rootReal . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix);
    }

    private function deleteDirectoryRecursive(string $directory): bool
    {
        $items = scandir($directory);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                if (!$this->deleteDirectoryRecursive($path)) {
                    return false;
                }
                continue;
            }

            if (!unlink($path)) {
                return false;
            }
        }

        return rmdir($directory);
    }

    private function folderSize(string $directory): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile()) {
                $size += $fileInfo->getSize();
            }

            if ($size > self::MAX_ZIP_BYTES) {
                break;
            }
        }

        return $size;
    }

    private function addDirectoryToZip(ZipCreator $zip, string $directory, string $zipPrefix): void
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
            $relative = ltrim(str_replace($directory, '', $absolutePath), DIRECTORY_SEPARATOR);
            $zipPath = $zipPrefix . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $zip->addFile($zipPath, $absolutePath);
        }
    }

    private function copyDirectoryRecursive(string $source, string $destination): bool
    {
        if (!mkdir($destination, 0755) && !is_dir($destination)) {
            return false;
        }

        $items = scandir($source);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $item;
            $to = $destination . DIRECTORY_SEPARATOR . $item;
            if (is_dir($from)) {
                if (!$this->copyDirectoryRecursive($from, $to)) {
                    return false;
                }
                continue;
            }

            if (!is_file($from) || !copy($from, $to)) {
                return false;
            }
        }

        return true;
    }

    private function sanitizeArchiveName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'folder';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? 'folder';
        $value = trim($value, '-.');

        return $value !== '' ? $value : 'folder';
    }
}
