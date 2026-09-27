<?php

use PHPUnit\Framework\TestCase;
use Plugins\siteblocks\Models\Library;

final class SiteBlocksLibraryTest extends TestCase
{
    private string $root;
    private Library $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sb-library-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/content', 0775, true);
        $this->library = new Library($this->root . '/data', $this->root . '/content');
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->root);
    }

    public function testDraftPublicationHistoryAndStableIdentity(): void
    {
        $row = $this->library->save('', '', '', 'Contact', 'First version');
        self::assertNull($row['published']);
        $row = $this->library->action($row['id'], '', $row['revision'], 'publish');
        $renamed = $this->library->save($row['id'], '', $row['revision'], 'Renamed', 'Second version');
        self::assertSame('First version', $renamed['published']);
        $live = $this->library->action($row['id'], '', $renamed['revision'], 'publish');
        $restored = $this->library->action($row['id'], '', $live['revision'], 'restore', $live['history'][0]['id']);
        self::assertSame('First version', $restored['draft']);
        self::assertSame('Second version', $restored['published']);
        self::assertSame($row['id'], $restored['id']);
    }

    public function testStaleWriterCannotOverwriteDraft(): void
    {
        $row = $this->library->save('', '', '', 'Contact', 'Initial');
        $this->library->save($row['id'], '', $row['revision'], 'Contact', 'Changed');
        $this->expectExceptionMessage('SB_CONFLICT');
        $this->library->save($row['id'], '', $row['revision'], 'Contact', 'Stale');
    }

    public function testUsageIncludesPublishedDraftAndFooterAndGuardsDeletion(): void
    {
        $row = $this->library->save('', '', '', 'Contact', 'Text');
        $row = $this->library->action($row['id'], '', $row['revision'], 'publish');
        $ref = '[:siteblock-ref id="' . $row['id'] . '" :]';
        file_put_contents($this->root . '/content/01-first.md', $ref);
        file_put_contents($this->root . '/content/02-second.txt', json_encode(['# Draft', $ref]));
        $this->library->place('', $row['id'], '');
        self::assertCount(3, $this->library->usage($row['id'], ''));
        $row = $this->library->action($row['id'], '', $row['revision'], 'archive');
        self::assertSame('Text', $row['published']);
        $this->expectExceptionMessage('SB_IN_USE');
        $this->library->action($row['id'], '', $row['revision'], 'delete');
    }

    public function testProjectsCannotReadOrPlaceEachOthersContent(): void
    {
        $row = $this->library->save('', '/de', '', 'Kontakt', 'Deutsch');
        $row = $this->library->action($row['id'], '/de', $row['revision'], 'publish');
        self::assertSame([], $this->library->all(''));
        $this->expectExceptionMessage('SB_NOT_AVAILABLE');
        $this->library->place('', $row['id'], '');
    }

    public function testExportImportRestoresIdsContentHistoryAndPlacement(): void
    {
        $row = $this->library->save('', '', '', 'Contact', 'One');
        $row = $this->library->action($row['id'], '', $row['revision'], 'publish');
        $row = $this->library->save($row['id'], '', $row['revision'], 'Contact', 'Two');
        $row = $this->library->action($row['id'], '', $row['revision'], 'publish');
        $this->library->place('', $row['id'], '');
        $bundle = $this->library->export('');
        $restored = new Library($this->root . '/restored', $this->root . '/content');
        self::assertSame(1, $restored->import('', $bundle));
        self::assertSame($row['history'], $restored->get($row['id'])['history']);
        self::assertSame('Two', $restored->get($row['id'])['published']);
        self::assertSame($row['id'], $restored->placement('')['footer']);
        $this->expectExceptionMessage('SB_IMPORT_EXISTS');
        $restored->import('', $bundle);
    }

    public function testNestedReferencesAreRejected(): void
    {
        $this->expectExceptionMessage('SB_NO_NESTING');
        $this->library->save('', '', '', 'Nested', '[:siteblock-ref id="sb_012345678901234567890123" :]');
    }
}
