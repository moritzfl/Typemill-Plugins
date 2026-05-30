<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\preview\Models\TrashPreviewResolver;

class TrashPreviewResolverTest extends TestCase
{
    private TrashPreviewResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TrashPreviewResolver();
    }

    public function testDetectsPageFolderDeletion(): void
    {
        $version = [
            'item_type' => 'folder',
            'path_without_type' => 'guides',
            'snapshot_files' => [
                ['path' => 'guides/intro/index.md', 'content' => '# Intro'],
                ['path' => 'guides/intro/index.yaml', 'content' => 'meta: {}'],
            ],
        ];

        $this->assertTrue($this->resolver->isFolderDeletion($version));
    }

    public function testTreatsSinglePageDeleteAsPagePreview(): void
    {
        $version = [
            'item_type' => 'folder',
            'path_without_type' => '99-test-mergely-diff/index',
            'snapshot_files' => [
                ['path' => '99-test-mergely-diff/index.md', 'content' => '# Test'],
                ['path' => '99-test-mergely-diff/index.txt', 'content' => 'Test'],
                ['path' => '99-test-mergely-diff/index.yaml', 'content' => 'meta: {}'],
            ],
        ];

        $this->assertFalse($this->resolver->isFolderDeletion($version));
    }

    public function testDetectsMediaFilesFolderDeletion(): void
    {
        $version = [
            'asset_type' => 'mediafiles',
            'metadata' => ['name' => 'bundle', 'element_type' => 'folder'],
            'snapshot_files' => [
                ['path' => 'bundle/a.txt', 'content' => 'a'],
                ['path' => 'bundle/sub/b.txt', 'content' => 'b'],
            ],
        ];

        $this->assertTrue($this->resolver->isFolderDeletion($version));
    }

    public function testDoesNotTreatSingleFileDeletionAsFolder(): void
    {
        $version = [
            'asset_type' => 'mediafiles',
            'metadata' => ['name' => 'docs/readme.txt'],
            'snapshot_files' => [
                ['path' => 'docs/readme.txt', 'content' => 'hello'],
            ],
        ];

        $this->assertFalse($this->resolver->isFolderDeletion($version));
    }

    public function testResolvesFolderPreviewWithSortedFiles(): void
    {
        $preview = $this->resolver->resolveFolder([
            ['path' => 'bundle/sub/b.txt', 'size' => 2],
            ['path' => 'bundle/a.txt', 'size' => 1],
        ]);

        $this->assertTrue($preview['previewable']);
        $this->assertSame('folder', $preview['kind']);
        $this->assertSame(2, $preview['file_count']);
        $this->assertSame('bundle/a.txt', $preview['files'][0]['path']);
        $this->assertSame('bundle/sub/b.txt', $preview['files'][1]['path']);
        $this->assertSame('text', $preview['files'][0]['preview_kind']);
    }

    public function testResolveEntryKind(): void
    {
        $this->assertSame('page', $this->resolver->resolveEntryKind([
            'item_type' => 'folder',
            'path_without_type' => 'guides/intro/index',
        ]));
        $this->assertSame('folder', $this->resolver->resolveEntryKind([
            'item_type' => 'folder',
            'path_without_type' => 'guides',
        ]));
        $this->assertSame('file', $this->resolver->resolveEntryKind([
            'asset_type' => 'mediafiles',
            'metadata' => ['name' => 'docs/readme.txt'],
            'snapshot_files' => [['path' => 'docs/readme.txt']],
        ]));
        $this->assertSame('folder', $this->resolver->resolveEntryKind([
            'asset_type' => 'mediafiles',
            'metadata' => ['name' => 'bundle', 'element_type' => 'folder'],
            'snapshot_files' => [
                ['path' => 'bundle/a.txt'],
                ['path' => 'bundle/b.txt'],
            ],
        ]));
        $this->assertSame('image', $this->resolver->resolveEntryKind([
            'asset_type' => 'image',
        ]));
    }
}
