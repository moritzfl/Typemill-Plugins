<?php

namespace Typemill\Models;

/**
 * Minimal stub for Typemill\Models\StorageWrapper.
 *
 * Loaded only when running PHP tests locally (without the Docker container).
 * The stub satisfies the constructor and method signatures used by the plugin
 * model classes so that pure-logic methods can be instantiated and tested
 * without requiring a full Typemill installation.
 *
 * getFolderPath() returns sys_get_temp_dir() so that tests that exercise
 * real filesystem operations (e.g. SnapshotLimitTest) work correctly by
 * creating their test directories there instead of under /var/www/html/content.
 */
class StorageWrapper
{
    public function __construct(string $storageClass) {}

    public function createFolder(string $location, string $path): bool
    {
        return true;
    }

    public function getFolderPath(string $location, string $sub = ''): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function getFile(string $location, string $folder, string $filename): string|false
    {
        return false;
    }

    public function writeFile(string $location, string $folder, string $filename, string $content): bool
    {
        return true;
    }

    public function deleteFile(string $location, string $folder, string $filename): bool
    {
        return true;
    }

    public function checkFile(string $location, string $folder, string $filename): bool
    {
        return false;
    }

    public function updateYaml(string $location, string $folder, string $filename, array $data): bool
    {
        return true;
    }
}

class Settings
{
    public function loadSettings(): array
    {
        return [
            'plugins' => [
                'preview' => ['active' => true],
            ],
        ];
    }

    public function updateSettings(array $settings, string $type, string $name): bool
    {
        return true;
    }
}
