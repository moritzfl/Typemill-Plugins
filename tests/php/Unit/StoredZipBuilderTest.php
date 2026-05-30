<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\files\Models\StoredZipBuilder as FilesStoredZipBuilder;
use Plugins\versions\Models\StoredZipBuilder as VersionsStoredZipBuilder;

class StoredZipBuilderTest extends TestCase
{
    public function testVersionsBuilderProducesValidZipWithExpectedEntries(): void
    {
        $this->assertValidArchive(new VersionsStoredZipBuilder());
    }

    public function testFilesBuilderProducesValidZipWithExpectedEntries(): void
    {
        $this->assertValidArchive(new FilesStoredZipBuilder());
    }

    private function assertValidArchive(object $builder): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'tm_zip_src_');
        $this->assertNotFalse($tempFile);
        file_put_contents($tempFile, 'snapshot-bytes');

        $builder->addFromString('manifest.json', '{"ok":true}');
        $builder->addFromString('pages/example.json', '{"pageid":"example"}');
        $builder->addFile('snapshots/example.txt', $tempFile);

        $archive = $builder->build();
        unlink($tempFile);

        $this->assertStringStartsWith("PK\x03\x04", $archive);

        if (!class_exists(\ZipArchive::class)) {
            $this->assertGreaterThan(100, strlen($archive));

            return;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'tm_zip_out_') . '.zip';
        file_put_contents($zipPath, $archive);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(3, $zip->numFiles);
        $this->assertSame('{"ok":true}', $zip->getFromName('manifest.json'));
        $this->assertSame('{"pageid":"example"}', $zip->getFromName('pages/example.json'));
        $this->assertSame('snapshot-bytes', $zip->getFromName('snapshots/example.txt'));
        $zip->close();
        unlink($zipPath);
    }
}
