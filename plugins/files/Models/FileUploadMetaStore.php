<?php

namespace Plugins\files\Models;

final class FileUploadMetaStore
{
    private string $metaFile;

    /** @var array<string, array{uploaded_by: string, uploaded_at: int}>|null */
    private ?array $cache = null;

    public function __construct(string $filesRootPath)
    {
        $metaDir = rtrim($filesRootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.meta';
        if (!is_dir($metaDir)) {
            mkdir($metaDir, 0755, true);
        }

        $this->metaFile = $metaDir . DIRECTORY_SEPARATOR . 'uploaders.json';
    }

    public function recordUpload(string $relativePath, string $username): void
    {
        $relativePath = $this->normalizePath($relativePath);
        if ($relativePath === '') {
            return;
        }

        $username = trim($username);
        if ($username === '') {
            $username = 'unknown';
        }

        $data = $this->load();
        $data[$relativePath] = [
            'uploaded_by' => $username,
            'uploaded_at' => time(),
        ];
        $this->save($data);
    }

    public function getUploader(string $relativePath): ?string
    {
        $relativePath = $this->normalizePath($relativePath);
        if ($relativePath === '') {
            return null;
        }

        $entry = $this->load()[$relativePath] ?? null;

        return is_array($entry) ? (string) ($entry['uploaded_by'] ?? null) : null;
    }

    public function relocateEntry(string $sourcePath, string $destinationPath, bool $isDirectory): void
    {
        $sourcePath = $this->normalizePath($sourcePath);
        $destinationPath = $this->normalizePath($destinationPath);
        if ($sourcePath === '' || $destinationPath === '') {
            return;
        }

        $data = $this->load();
        $changed = false;

        foreach (array_keys($data) as $path) {
            if (!$this->pathMatchesEntry($path, $sourcePath, $isDirectory)) {
                continue;
            }

            $newPath = $this->remapPath($path, $sourcePath, $destinationPath, $isDirectory);
            if ($newPath === null || $newPath === $path) {
                continue;
            }

            $data[$newPath] = $data[$path];
            unset($data[$path]);
            $changed = true;
        }

        if ($changed) {
            $this->save($data);
        }
    }

    public function copyEntry(string $sourcePath, string $destinationPath, bool $isDirectory): void
    {
        $sourcePath = $this->normalizePath($sourcePath);
        $destinationPath = $this->normalizePath($destinationPath);
        if ($sourcePath === '' || $destinationPath === '') {
            return;
        }

        $data = $this->load();
        $changed = false;

        foreach ($data as $path => $meta) {
            if (!$this->pathMatchesEntry($path, $sourcePath, $isDirectory)) {
                continue;
            }

            $newPath = $this->remapPath($path, $sourcePath, $destinationPath, $isDirectory);
            if ($newPath === null || isset($data[$newPath])) {
                continue;
            }

            $data[$newPath] = $meta;
            $changed = true;
        }

        if ($changed) {
            $this->save($data);
        }
    }

    public function removeEntry(string $relativePath, bool $isDirectory): void
    {
        $relativePath = $this->normalizePath($relativePath);
        if ($relativePath === '') {
            return;
        }

        $data = $this->load();
        $changed = false;

        foreach (array_keys($data) as $path) {
            if (!$this->pathMatchesEntry($path, $relativePath, $isDirectory)) {
                continue;
            }

            unset($data[$path]);
            $changed = true;
        }

        if ($changed) {
            $this->save($data);
        }
    }

    private function pathMatchesEntry(string $path, string $entryPath, bool $isDirectory): bool
    {
        if ($isDirectory) {
            return $path === $entryPath || str_starts_with($path, $entryPath . '/');
        }

        return $path === $entryPath;
    }

    private function remapPath(string $path, string $sourcePath, string $destinationPath, bool $isDirectory): ?string
    {
        if (!$isDirectory) {
            return $path === $sourcePath ? $destinationPath : null;
        }

        if ($path === $sourcePath) {
            return $destinationPath;
        }

        if (!str_starts_with($path, $sourcePath . '/')) {
            return null;
        }

        $suffix = substr($path, strlen($sourcePath) + 1);

        return $destinationPath . '/' . $suffix;
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /** @return array<string, array{uploaded_by: string, uploaded_at: int}> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!is_file($this->metaFile)) {
            $this->cache = [];

            return $this->cache;
        }

        $raw = file_get_contents($this->metaFile);
        if ($raw === false || trim($raw) === '') {
            $this->cache = [];

            return $this->cache;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[files] Failed to decode upload metadata: ' . $e->getMessage());
            $this->cache = [];

            return $this->cache;
        }

        if (!is_array($decoded)) {
            $this->cache = [];

            return $this->cache;
        }

        $normalized = [];
        foreach ($decoded as $path => $meta) {
            if (!is_string($path) || !is_array($meta)) {
                continue;
            }

            $key = $this->normalizePath($path);
            if ($key === '') {
                continue;
            }

            $username = trim((string) ($meta['uploaded_by'] ?? ''));
            if ($username === '') {
                continue;
            }

            $normalized[$key] = [
                'uploaded_by' => $username,
                'uploaded_at' => (int) ($meta['uploaded_at'] ?? 0),
            ];
        }

        $this->cache = $normalized;

        return $this->cache;
    }

    /** @param array<string, array{uploaded_by: string, uploaded_at: int}> $data */
    private function save(array $data): void
    {
        ksort($data);

        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[files] Failed to encode upload metadata: ' . $e->getMessage());

            return;
        }

        if (file_put_contents($this->metaFile, $json, LOCK_EX) === false) {
            error_log('[files] Failed to write upload metadata: ' . $this->metaFile);
        }

        $this->cache = $data;
    }
}
