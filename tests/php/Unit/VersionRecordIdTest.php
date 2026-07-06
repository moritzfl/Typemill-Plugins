<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\VersionRecordRepository;
use Typemill\Models\StorageWrapper;

/**
 * Tests for the record-id guard in VersionRecordRepository::isValidRecordId().
 *
 * This is the security boundary that stops a user-supplied record_id from
 * escaping the versions storage folder via path traversal into
 * recursiveDeleteDir() (arbitrary recursive delete) or getFile()/writeFile().
 */
class VersionRecordIdTest extends TestCase
{
    private \ReflectionMethod $method;
    private VersionRecordRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new VersionRecordRepository(new StorageWrapper('\Typemill\Models\Storage'), 'versions');
        $this->method = new \ReflectionMethod($this->repo, 'isValidRecordId');
    }

    public function testSha1RecordIdIsValid(): void
    {
        $this->assertValid(sha1('asset|image|photo.jpg'));
    }

    public function testHexPageIdIsValid(): void
    {
        $this->assertValid(bin2hex(random_bytes(8)));
    }

    public function testEmptyIsInvalid(): void
    {
        $this->assertInvalid('');
    }

    public function testTraversalIsInvalid(): void
    {
        $this->assertInvalid('../../../../content');
    }

    public function testTraversalWithJsonSuffixIsInvalid(): void
    {
        $this->assertInvalid('../../pages/other');
    }

    public function testSlashIsInvalid(): void
    {
        $this->assertInvalid('pages/evil');
    }

    public function testDotDotIsInvalid(): void
    {
        $this->assertInvalid('..');
    }

    public function testNullByteIsInvalid(): void
    {
        $this->assertInvalid("abc\0def");
    }

    public function testDeleteTrashEntryRejectsTraversal(): void
    {
        // Public entry point must refuse a traversal id without touching disk.
        $this->assertFalse($this->repo->deleteTrashEntry('../../../../content', 'page'));
    }

    private function assertValid(string $id): void
    {
        $this->assertTrue($this->method->invoke($this->repo, $id), "Expected valid: \"{$id}\"");
    }

    private function assertInvalid(string $id): void
    {
        $this->assertFalse($this->method->invoke($this->repo, $id), "Expected invalid: \"{$id}\"");
    }
}
