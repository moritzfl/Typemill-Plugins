<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\ExportOptions;
use Plugins\versions\Models\VersionExportService;
use Plugins\versions\Models\VersionRecordRepository;
use Typemill\Models\StorageWrapper;

class VersionExportServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tm_export_test_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        mkdir($this->root . '/content', 0777, true);
        mkdir($this->root . '/media/files', 0777, true);
        mkdir($this->root . '/media/live', 0777, true);
        mkdir($this->root . '/media/files/.tmp', 0777, true);
        mkdir($this->root . '/data/versions/pages', 0777, true);
        mkdir($this->root . '/data/versions/assets', 0777, true);

        file_put_contents($this->root . '/content/welcome.md', "# Welcome\n");
        file_put_contents($this->root . '/media/files/guide.pdf', '%PDF-sample');
        file_put_contents($this->root . '/media/live/published.jpg', 'jpeg-data');
        file_put_contents($this->root . '/media/files/.tmp/chunk.bin', 'temporary');
        file_put_contents(
            $this->root . '/data/versions/assets/deleted-asset.json',
            json_encode([
                'record_id' => 'deleted-asset',
                'asset' => [],
                'versions' => [],
                'deleted' => ['title' => 'Removed file'],
            ], JSON_THROW_ON_ERROR)
        );
        file_put_contents(
            $this->root . '/data/versions/pages/deleted-page.json',
            json_encode([
                'pageid' => 'deleted-page',
                'page' => [],
                'versions' => [],
                'deleted' => ['title' => 'Removed page'],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFullExportIncludesLiveContentMediaAndVersionMetadata(): void
    {
        $storage = new ExportStorageDouble(
            $this->root . '/content',
            $this->root . '/media/files',
            $this->root . '/data'
        );
        $records = new VersionRecordRepository($storage, 'versions');
        $service = new VersionExportService();

        $options = ExportOptions::defaults(['files', 'live']);
        $download = $service->createFullExport($records, $storage, $options);
        $this->assertNotNull($download);
        $this->assertSame('application/zip', $download['mime_type']);

        if (!class_exists(\ZipArchive::class)) {
            $this->assertStringStartsWith("PK\x03\x04", $download['content']);

            return;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'tm_export_') . '.zip';
        file_put_contents($zipPath, $download['content']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame(2, $manifest['export_format']);
        $this->assertSame(['content', 'media', 'versions', 'recycle_bin'], $manifest['includes']);
        $this->assertSame(['files', 'live'], $manifest['media_folders']);
        $this->assertTrue($manifest['include_recycle_bin']);
        $this->assertSame("# Welcome\n", $zip->getFromName('content/welcome.md'));
        $this->assertSame('%PDF-sample', $zip->getFromName('media/files/guide.pdf'));
        $this->assertSame('jpeg-data', $zip->getFromName('media/live/published.jpg'));
        $this->assertFalse($zip->locateName('media/files/.tmp/chunk.bin'));
        $this->assertNotFalse($zip->locateName('versions/assets/deleted-asset.json'));
        $this->assertNotFalse($zip->locateName('versions/pages/deleted-page.json'));

        $zip->close();
        unlink($zipPath);
    }

    public function testFullExportCanLimitMediaFoldersAndExcludeRecycleBin(): void
    {
        $storage = new ExportStorageDouble(
            $this->root . '/content',
            $this->root . '/media/files',
            $this->root . '/data'
        );
        $records = new VersionRecordRepository($storage, 'versions');
        $service = new VersionExportService();
        $options = new ExportOptions(['files'], false);

        $download = $service->createFullExport($records, $storage, $options);
        $this->assertNotNull($download);

        if (!class_exists(\ZipArchive::class)) {
            $this->assertStringStartsWith("PK\x03\x04", $download['content']);

            return;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'tm_export_') . '.zip';
        file_put_contents($zipPath, $download['content']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame(['content', 'media', 'versions'], $manifest['includes']);
        $this->assertSame(['files'], $manifest['media_folders']);
        $this->assertFalse($manifest['include_recycle_bin']);
        $this->assertSame('%PDF-sample', $zip->getFromName('media/files/guide.pdf'));
        $this->assertFalse($zip->locateName('media/live/published.jpg'));
        $this->assertFalse($zip->locateName('versions/assets/deleted-asset.json'));
        $this->assertFalse($zip->locateName('versions/pages/deleted-page.json'));

        $zip->close();
        unlink($zipPath);
    }

    public function testListMediaSubfoldersIgnoresHiddenAndTemporaryFolders(): void
    {
        mkdir($this->root . '/media/.hidden', 0777, true);
        mkdir($this->root . '/media/custom', 0777, true);

        $storage = new ExportStorageDouble(
            $this->root . '/content',
            $this->root . '/media/files',
            $this->root . '/data'
        );
        $service = new VersionExportService();

        $this->assertSame(['custom', 'files', 'live'], $service->listMediaSubfolders($storage));
    }

    public function testGetMediaSubfolderSizesIgnoresTemporaryPaths(): void
    {
        $storage = new ExportStorageDouble(
            $this->root . '/content',
            $this->root . '/media/files',
            $this->root . '/data'
        );
        $service = new VersionExportService();

        $sizes = $service->getMediaSubfolderSizes($storage);

        $this->assertSame(strlen('%PDF-sample'), $sizes['files']);
        $this->assertSame(strlen('jpeg-data'), $sizes['live']);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($directory);
    }
}

final class ExportStorageDouble extends StorageWrapper
{
    public function __construct(
        private readonly string $contentRoot,
        private readonly string $fileFolder,
        private readonly string $dataRoot,
    ) {
        parent::__construct('\Typemill\Models\Storage');
    }

    public function getFolderPath(string $location, string $sub = ''): string
    {
        $base = match ($location) {
            'contentFolder' => $this->contentRoot,
            'fileFolder' => $this->fileFolder,
            'dataFolder' => $this->dataRoot,
            default => rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
        };

        if ($sub === '') {
            return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($sub, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function getFile(string $location, string $folder, string $filename): string|false
    {
        $path = rtrim($this->getFolderPath($location, $folder), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return false;
        }

        $content = file_get_contents($path);

        return $content === false ? false : $content;
    }
}
