<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\typemillupdate\Models\Environment;
use Plugins\typemillupdate\Models\Upload;

/**
 * Chunked upload of a core archive.
 *
 * PHP's default upload_max_filesize is smaller than a release archive, so the
 * browser posts the file in base64 slices and they are reassembled here. The
 * upload id and the assembled archive name both come from the browser and are
 * used to build file paths, which is what these tests are mostly about.
 */
class TypemillUpdateUploadTest extends TestCase
{
    private string $root;
    private Upload $upload;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        $this->root = $base . '/typemillupdate-upload-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $this->upload = new Upload(new Environment($this->root));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testChunksAreReassembledInOrder(): void
    {
        $payload = random_bytes(5000);
        $chunks = str_split($payload, 1000);

        foreach ($chunks as $index => $chunk) {
            $result = $this->upload->storeChunk('abc123', $index, base64_encode($chunk));
            $this->assertTrue($result['ok'], $result['error'] ?? '');
        }

        $target = $this->root . '/assembled.bin';
        $result = $this->upload->assemble('abc123', count($chunks), $target);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(strlen($payload), $result['bytes']);
        $this->assertSame($payload, file_get_contents($target));
    }

    public function testChunksAreRemovedAfterAssembly(): void
    {
        $this->upload->storeChunk('abc123', 0, base64_encode('hello'));
        $this->upload->assemble('abc123', 1, $this->root . '/assembled.bin');

        $this->assertFileDoesNotExist($this->upload->chunkDirectory() . '/abc123.0');
    }

    public function testAssemblyFailsWhenAChunkIsMissing(): void
    {
        $this->upload->storeChunk('abc123', 0, base64_encode('first'));
        // Chunk 1 never arrives.

        $target = $this->root . '/assembled.bin';
        $result = $this->upload->assemble('abc123', 2, $target);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('incomplete', $result['error']);
        $this->assertFileDoesNotExist($target);
    }

    public function testUploadIdsThatCouldEscapeTheDirectoryAreRejected(): void
    {
        $rejected = ['../evil', 'a/b', 'a\\b', '', str_repeat('a', 65), 'abc.def', "ab\0c"];

        foreach ($rejected as $id) {
            $this->assertNull(Upload::sanitizeId($id), 'Expected rejection: ' . var_export($id, true));

            $result = $this->upload->storeChunk($id, 0, base64_encode('x'));
            $this->assertFalse($result['ok'], 'Expected storeChunk to refuse: ' . var_export($id, true));
        }

        $this->assertSame('abc-123_XYZ', Upload::sanitizeId('abc-123_XYZ'));
    }

    public function testMalformedBase64IsRejected(): void
    {
        $result = $this->upload->storeChunk('abc123', 0, '!!!not base64!!!');

        $this->assertFalse($result['ok']);
    }

    public function testAssembledArchivesAreOnlyResolvedByTheirGeneratedName(): void
    {
        $name = Upload::archiveName('abc123');
        $this->assertSame('upload-abc123.zip', $name);

        // Nothing on disk yet.
        $this->assertNull($this->upload->resolveArchive($name));

        mkdir($this->root . '/.tm-update', 0777, true);
        file_put_contents($this->root . '/.tm-update/' . $name, 'zip');

        $this->assertSame(
            $this->root . '/.tm-update/' . $name,
            $this->upload->resolveArchive($name)
        );

        foreach ([
            '../../etc/passwd',
            'upload-../../etc/passwd.zip',
            '/etc/passwd',
            'download-123.zip',
            'upload-abc123.php',
            'backup-abc123.zip',
        ] as $hostile) {
            $this->assertNull($this->upload->resolveArchive($hostile), 'Expected rejection: ' . $hostile);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
