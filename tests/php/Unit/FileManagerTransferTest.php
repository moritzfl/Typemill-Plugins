<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\files\Models\FileManager;

class FileManagerTransferTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tm_files_transfer_' . uniqid('', true);
        mkdir($this->root . '/media/files/docs', 0775, true);
        file_put_contents($this->root . '/media/files/readme.txt', 'root file');
        file_put_contents($this->root . '/media/files/docs/note.txt', 'nested file');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDir($this->root);
        }
    }

    public function testMovesFileIntoFolder(): void
    {
        $manager = $this->makeManager();

        $this->assertNull($manager->transferEntry('readme.txt', 'docs', false));
        $this->assertFileExists($this->root . '/media/files/docs/readme.txt');
        $this->assertFileDoesNotExist($this->root . '/media/files/readme.txt');
    }

    public function testCopiesFileIntoFolder(): void
    {
        $manager = $this->makeManager();

        $this->assertNull($manager->transferEntry('readme.txt', 'docs', true));
        $this->assertFileExists($this->root . '/media/files/readme.txt');
        $this->assertFileExists($this->root . '/media/files/docs/readme.txt');
    }

    public function testRejectsMoveIntoSameFolder(): void
    {
        $manager = $this->makeManager();

        $this->assertSame('files.msg_transfer_same_location', $manager->transferEntry('docs/note.txt', 'docs', false));
    }

    public function testRejectsMoveFolderIntoItself(): void
    {
        $manager = $this->makeManager();
        mkdir($this->root . '/media/files/bundle', 0775, true);
        mkdir($this->root . '/media/files/bundle/sub', 0775, true);

        $this->assertSame('files.msg_transfer_into_self', $manager->transferEntry('bundle', 'bundle', false));
        $this->assertSame('files.msg_transfer_into_self', $manager->transferEntry('bundle', 'bundle/sub', false));
    }

    public function testRejectsExistingDestinationName(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->transferEntry('readme.txt', 'docs', true));

        $this->assertSame('files.msg_transfer_exists', $manager->transferEntry('readme.txt', 'docs', true));
    }

    private function makeManager(): FileManager
    {
        return new FileManager($this->root);
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
