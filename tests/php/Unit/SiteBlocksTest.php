<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\siteblocks\Models\Block;
use Plugins\siteblocks\Models\Renderer;
use Plugins\siteblocks\Models\Collection;

class SiteBlocksTest extends TestCase
{
    public function testAuthoredDataCannotBecomeExecutableMarkup(): void
    {
        $html = (new Renderer('/site'))->render(['type' => 'columns', 'title' => '<script>alert(1)</script>',
            'items' => [['src' => 'javascript:alert(1)', 'url' => '//evil.example', 'title' => '<img src=x onerror=alert(1)>']]]);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        foreach (["/\\evil.test", "java\nscript:alert(1)", 'data:image/svg+xml,test'] as $url) {
            self::assertSame('', Block::url($url, true));
        }
    }

    public function testFocalPointsAndSizesAreBoundedAndSubdirectoryMediaResolves(): void
    {
        $block = Block::normalize(['type' => 'gallery', 'columns' => 100, 'limit' => 200,
            'items' => [['src' => 'media/images/photo.jpg', 'x' => -9, 'y' => 120]]]);
        self::assertSame(4, $block['columns']);
        self::assertSame(24, $block['limit']);
        $html = (new Renderer('https://example.test/site'))->render($block);
        self::assertStringContainsString('https://example.test/site/media/images/photo.jpg', $html);
        self::assertStringContainsString('object-position:0% 100%', $html);
    }

    public function testUnicodeSurvivesTheShortcodeAndUnknownVersionsFailClosed(): void
    {
        $data = ['version' => 1, 'type' => 'cta', 'title' => 'Grüße 日本語'];
        $encoded = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        self::assertSame($data['title'], Block::decode($encoded)['title']);
        $this->expectException(\InvalidArgumentException::class);
        Block::normalize(['version' => 2, 'type' => 'cta']);
    }

    public function testCollectionsExcludeHiddenRestrictedAndReferencedContent(): void
    {
        self::assertTrue(Collection::isPublic(['title' => 'Public']));
        foreach (['hide', 'noindex', 'allowedrole', 'alloweduser', 'reference'] as $key) {
            self::assertFalse(Collection::isPublic([$key => 'private']));
        }
        self::assertSame(['club', 'youth'], Collection::tags(' Club, youth, club, '));
    }
}
