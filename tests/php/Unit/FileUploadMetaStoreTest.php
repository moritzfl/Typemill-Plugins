<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\files\Models\FileManager;
use Plugins\files\Models\FileUploadMetaStore;

class FileUploadMetaStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tm_files_meta_' . uniqid('', true);
        mkdir($this->root . '/media/files/docs', 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDir($this->root);
        }
    }

    public function testRecordsAndReadsUploader(): void
    {
        $store = $this->makeStore();

        $store->recordUpload('docs/readme.txt', 'admin');

        $this->assertSame('admin', $store->getUploader('docs/readme.txt'));
    }

    public function testRelocatesMetadataWhenFileIsMoved(): void
    {
        $store = $this->makeStore();
        $store->recordUpload('readme.txt', 'editor');

        $store->relocateEntry('readme.txt', 'docs/readme.txt', false);

        $this->assertNull($store->getUploader('readme.txt'));
        $this->assertSame('editor', $store->getUploader('docs/readme.txt'));
    }

    public function testRelocatesMetadataForFilesInsideMovedFolder(): void
    {
        $store = $this->makeStore();
        $store->recordUpload('bundle/a.txt', 'admin');
        $store->recordUpload('bundle/sub/b.txt', 'admin');

        $store->relocateEntry('bundle', 'archive/bundle', true);

        $this->assertNull($store->getUploader('bundle/a.txt'));
        $this->assertSame('admin', $store->getUploader('archive/bundle/a.txt'));
        $this->assertSame('admin', $store->getUploader('archive/bundle/sub/b.txt'));
    }

    public function testCopiesMetadataWithoutRemovingSource(): void
    {
        $store = $this->makeStore();
        $store->recordUpload('readme.txt', 'admin');

        $store->copyEntry('readme.txt', 'docs/readme.txt', false);

        $this->assertSame('admin', $store->getUploader('readme.txt'));
        $this->assertSame('admin', $store->getUploader('docs/readme.txt'));
    }

    public function testManagerMoveKeepsUploader(): void
    {
        $manager = new FileManager($this->root);
        file_put_contents($this->root . '/media/files/readme.txt', 'root file');

        $manager->recordUpload('readme.txt', 'admin');
        $this->assertNull($manager->transferEntry('readme.txt', 'docs', false));

        $listing = $manager->browse('docs');
        $this->assertNotNull($listing);
        $this->assertSame('admin', $listing['files'][0]['uploaded_by'] ?? null);
    }

    private function makeStore(): FileUploadMetaStore
    {
        return new FileUploadMetaStore($this->root . '/media/files');
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
