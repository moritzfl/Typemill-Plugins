<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\TrashEntryKind;

class TrashEntryKindTest extends TestCase
{
    public function testResolvesPageWithoutPreviewPlugin(): void
    {
        $kind = TrashEntryKind::resolve([
            'item_type' => 'folder',
            'path_without_type' => '99-test/index',
        ], 'page');

        $this->assertSame('page', $kind);
    }

    public function testResolvesContentFolderWithoutPreviewPlugin(): void
    {
        $kind = TrashEntryKind::resolve([
            'item_type' => 'folder',
            'path_without_type' => 'guides',
        ], 'page');

        $this->assertSame('folder', $kind);
    }

    public function testResolvesMediaFileWithoutPreviewPlugin(): void
    {
        $kind = TrashEntryKind::resolve([
            'asset_type' => 'mediafiles',
            'metadata' => ['name' => 'readme.txt', 'element_type' => 'file'],
        ], 'asset');

        $this->assertSame('file', $kind);
    }
}
