<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\preview\Models\MarkdownPreviewRenderer;

class MarkdownPreviewRendererTest extends TestCase
{
    public function testHtmlFilesDoNotBecomeRenderedHtml(): void
    {
        $renderer = new MarkdownPreviewRenderer([], ['baseurl' => ''], new class {
            public function dispatch($event, $name = null)
            {
                return $event;
            }
        });

        $payload = $renderer->addRenderedHtml([
            'preview_kind' => 'text',
            'preview_filename' => 'note.html',
            'markdown' => '<script>alert(1)</script><p>Hi</p>',
        ]);

        $this->assertSame('', $payload['rendered_html']);
    }
}
