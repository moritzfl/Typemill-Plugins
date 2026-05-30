<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\preview\Models\PreviewSupport;

class PreviewSupportTest extends TestCase
{
    private PreviewSupport $support;

    protected function setUp(): void
    {
        $this->support = new PreviewSupport();
    }

    public function testDetectsTextImageAudioVideoAndPdf(): void
    {
        $this->assertSame('text', $this->support->getPreviewKind('notes/readme.md'));
        $this->assertSame('image', $this->support->getPreviewKind('photo.jpg'));
        $this->assertSame('audio', $this->support->getPreviewKind('track.mp3'));
        $this->assertSame('video', $this->support->getPreviewKind('clip.mp4'));
        $this->assertSame('pdf', $this->support->getPreviewKind('manual.pdf'));
    }

    public function testRejectsUnknownExtensions(): void
    {
        $this->assertNull($this->support->getPreviewKind('archive.zip'));
        $this->assertFalse($this->support->isPreviewablePath('archive.zip'));
    }

    public function testGuessesMimeTypes(): void
    {
        $this->assertSame('text/markdown', $this->support->guessMimeType('readme.md'));
        $this->assertSame('image/png', $this->support->guessMimeType('icon.png'));
        $this->assertSame('video/mp4', $this->support->guessMimeType('clip.mp4'));
    }
}
