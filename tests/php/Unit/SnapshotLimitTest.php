<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\PageSnapshotService;
use Plugins\versions\Models\SnapshotTooLargeException;
use Typemill\Models\StorageWrapper;

/**
 * Tests for the file-count and size limits in PageSnapshotService::snapshotFolderFiles().
 *
 * When a folder is too large to snapshot, the method must throw
 * SnapshotTooLargeException so the caller (the delete endpoint) can return
 * a 409 and let the user decide whether to permanently delete instead.
 */
class SnapshotLimitTest extends TestCase
{
    private string $testDir = '';

    protected function tearDown(): void
    {
        if ($this->testDir === '' || !is_dir($this->testDir)) {
            return;
        }

        foreach (glob($this->testDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->testDir);
    }

    public function testThrowsWhenFileCountExceeds500(): void
    {
        $this->testDir = $this->makeTestDir();

        // MAX_SNAPSHOT_FILES is 500 — create 501 files to cross the threshold.
        for ($i = 0; $i <= 500; $i++) {
            file_put_contents($this->testDir . '/file_' . $i . '.md', 'x');
        }

        $this->expectException(SnapshotTooLargeException::class);
        $this->invokeSnapshot(basename($this->testDir));
    }

    public function testExceptionMessageMentionsLimit(): void
    {
        $this->testDir = $this->makeTestDir();

        for ($i = 0; $i <= 500; $i++) {
            file_put_contents($this->testDir . '/file_' . $i . '.md', 'x');
        }

        try {
            $this->invokeSnapshot(basename($this->testDir));
            $this->fail('SnapshotTooLargeException was not thrown.');
        } catch (SnapshotTooLargeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    public function testNoExceptionForSmallFolder(): void
    {
        $this->testDir = $this->makeTestDir();

        for ($i = 0; $i < 5; $i++) {
            file_put_contents($this->testDir . '/file_' . $i . '.md', 'small content');
        }

        $result = $this->invokeSnapshot(basename($this->testDir));

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
    }

    public function testReturnedFilesHaveExpectedShape(): void
    {
        $this->testDir = $this->makeTestDir();
        file_put_contents($this->testDir . '/page.md', '# Hello');

        $result = $this->invokeSnapshot(basename($this->testDir));

        $this->assertCount(1, $result);
        $file = $result[0];
        $this->assertArrayHasKey('location', $file);
        $this->assertArrayHasKey('path', $file);
        $this->assertArrayHasKey('content', $file);
        $this->assertSame('# Hello', $file['content']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTestDir(): string
    {
        // PHPUNIT_CONTENT_ROOT is defined by bootstrap.php and matches the base
        // path that StorageWrapper::getFolderPath('contentFolder') returns:
        //   - Docker:  /var/www/html/content
        //   - Local:   sys_get_temp_dir()
        $base = defined('PHPUNIT_CONTENT_ROOT')
            ? PHPUNIT_CONTENT_ROOT
            : sys_get_temp_dir();

        $dir = rtrim($base, DIRECTORY_SEPARATOR)
             . DIRECTORY_SEPARATOR . '_phpunit_' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function invokeSnapshot(string $folderName): array
    {
        $service = new PageSnapshotService(new StorageWrapper('\Typemill\Models\Storage'));

        return $service->snapshotFolderFiles($folderName);
    }
}
