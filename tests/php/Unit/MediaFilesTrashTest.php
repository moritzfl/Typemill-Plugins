<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\AssetVersionStore;
use Plugins\versions\Models\LineDiff;
use Plugins\versions\Models\VersionRecordRepository;
use Typemill\Models\StorageWrapper;

class MediaFilesTrashTest extends TestCase
{
    private string $fileRoot = '';
    private string $dataRoot = '';

    protected function tearDown(): void
    {
        if ($this->fileRoot !== '' && is_dir($this->fileRoot)) {
            $this->removeDir($this->fileRoot);
        }
        if ($this->dataRoot !== '' && is_dir($this->dataRoot)) {
            $this->removeDir($this->dataRoot);
        }
    }

    public function testStoreMediaFilesDeletionSnapshotsNestedFile(): void
    {
        $this->setUpRoots();
        $target = $this->fileRoot . '/docs/readme.txt';
        mkdir(dirname($target), 0775, true);
        file_put_contents($target, 'hello trash');

        $store = $this->makeStore();
        $result = $store->storeMediaFilesDeletion('docs/readme.txt', 'admin', 10, 'Admin', 'version_test123');

        $this->assertTrue($result['success']);
        $entries = $store->listDeletedEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('docs/readme.txt', $entries[0]['path']);
        $this->assertStringContainsString('media/files/docs/readme.txt', $entries[0]['url']);
        $this->assertTrue($entries[0]['previewable']);
        $detail = $store->getVersionDetail((string) $result['record_id'], (string) $result['version_id']);
        $this->assertNotNull($detail);
        $this->assertSame('text', $detail['version']['preview_kind']);
        $this->assertSame('readme.txt', $detail['version']['preview_filename']);
        $this->assertStringContainsString('hello trash', $detail['version']['markdown']);
    }

    public function testStoreMediaFilesDeletionSnapshotsFolder(): void
    {
        $this->setUpRoots();
        mkdir($this->fileRoot . '/bundle/sub', 0775, true);
        file_put_contents($this->fileRoot . '/bundle/a.txt', 'a');
        file_put_contents($this->fileRoot . '/bundle/sub/b.txt', 'b');

        $store = $this->makeStore();
        $result = $store->storeMediaFilesDeletion('bundle', 'admin', 10, 'Admin', 'version_folder1');

        $this->assertTrue($result['success']);

        $entries = $store->listDeletedEntries();
        $detail = $store->getVersionDetail((string) $result['record_id'], (string) $result['version_id']);
        $this->assertNotNull($detail);
        $record = $this->loadRecord((string) $result['record_id']);
        $version = $record['versions'][0];
        $this->assertCount(2, $version['snapshot_files']);
        $this->assertSame('folder', $version['metadata']['element_type']);
        $this->assertTrue($entries[0]['previewable'] ?? false);
        $this->assertSame('folder', $detail['version']['preview_kind']);
        $this->assertSame(2, $detail['version']['preview_file_count']);
        $this->assertSame('bundle/a.txt', $detail['version']['preview_files'][0]['path']);
        $this->assertSame('bundle/sub/b.txt', $detail['version']['preview_files'][1]['path']);
    }

    public function testRejectsInvalidPath(): void
    {
        $this->setUpRoots();
        $store = $this->makeStore();
        $result = $store->storeMediaFilesDeletion('../secret', 'admin', 10, 'Admin', 'version_bad1');

        $this->assertFalse($result['success']);
    }

    private function setUpRoots(): void
    {
        $base = sys_get_temp_dir() . '/tm_media_trash_' . uniqid('', true);
        $this->fileRoot = $base . '/files';
        $this->dataRoot = $base . '/data';
        mkdir($this->fileRoot, 0775, true);
        mkdir($this->dataRoot . '/versions/assets', 0775, true);
        mkdir($this->dataRoot . '/versions/snapshots', 0775, true);
    }

    private function makeStore(): AssetVersionStore
    {
        $storage = new TestFileFolderStorage($this->fileRoot, $this->dataRoot);
        $records = new VersionRecordRepository($storage, 'versions');

        return new AssetVersionStore($storage, $records, new LineDiff());
    }

    private function loadRecord(string $recordId): array
    {
        $path = $this->dataRoot . '/versions/assets/' . $recordId . '.json';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

class TestFileFolderStorage extends StorageWrapper
{
    public function __construct(private string $fileRoot, private string $dataRoot)
    {
        parent::__construct('\Typemill\Models\Storage');
    }

    public function createFolder(string $location, string $path): bool
    {
        $full = rtrim($this->getFolderPath($location), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
        if (is_dir($full)) {
            return true;
        }

        return mkdir($full, 0775, true);
    }

    public function getFolderPath(string $location, string $sub = ''): string
    {
        $base = match ($location) {
            'fileFolder' => $this->fileRoot,
            'dataFolder' => $this->dataRoot,
            default => rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
        };

        if ($sub !== '') {
            $base .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function writeFile(string $location, string $folder, string $filename, string $content): bool
    {
        $dir = rtrim($this->getFolderPath($location), DIRECTORY_SEPARATOR);
        if ($folder !== '') {
            $dir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $content) !== false;
    }

    public function getFile(string $location, string $folder, string $filename): string|false
    {
        $dir = rtrim($this->getFolderPath($location), DIRECTORY_SEPARATOR);
        if ($folder !== '') {
            $dir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return false;
        }

        $content = file_get_contents($path);

        return $content === false ? false : $content;
    }

    public function checkFile(string $location, string $folder, string $filename): bool
    {
        $dir = rtrim($this->getFolderPath($location), DIRECTORY_SEPARATOR);
        if ($folder !== '') {
            $dir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }

        return is_file($dir . DIRECTORY_SEPARATOR . $filename);
    }
}
