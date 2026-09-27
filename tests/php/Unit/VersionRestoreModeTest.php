<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\VersionStore;

class VersionRestoreModeTest extends TestCase
{
    public function testAnEventOnlyVersionIsNotRestoredAsAnEmptyPage(): void
    {
        $this->assertNull(VersionStore::restoreWriteMode([
            'event_only' => true,
            'markdown' => null,
            'status' => 'published',
        ], true, true));
    }

    public function testAContributorRestoresPublishedTextAsADraft(): void
    {
        $this->assertSame('draft', VersionStore::restoreWriteMode([
            'markdown' => '# Kept',
            'status' => 'published',
        ], false, false));
    }

    public function testPublishAndUnpublishStayBehindTheirPrivileges(): void
    {
        $published = ['markdown' => '# Live', 'status' => 'published'];
        $unpublished = ['markdown' => '# Draft', 'status' => 'unpublished'];

        $this->assertSame('publish', VersionStore::restoreWriteMode($published, true, false));
        $this->assertSame('draft', VersionStore::restoreWriteMode($unpublished, true, false));
        $this->assertSame('unpublish', VersionStore::restoreWriteMode($unpublished, false, true));
    }
}
