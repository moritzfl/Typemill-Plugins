<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\readmemd\Models\ReadmeRenderer;
use Plugins\readmemd\Models\RepositoryReference;

/**
 * Making somebody else's readme fit on this site.
 *
 * A readme is written to be read on github.com, so its relative links and
 * pictures point at files in the repository; here they would point at pages of
 * this site that do not exist. And it is somebody else's HTML, which is allowed
 * to contain rather more than an article should.
 *
 * The markdown step is not exercised here - that is Typemill's Parsedown - so a
 * converter that returns the markup unchanged stands in for it, and every test
 * states the HTML it means to talk about.
 */
class ReadmeMdRendererTest extends TestCase
{
    private function renderer(): ReadmeRenderer
    {
        return new ReadmeRenderer(static fn (string $markdown): string => $markdown);
    }

    private function reference(string $value = 'moritzfl/Typemill-Plugins', string $branch = '', string $path = ''): RepositoryReference
    {
        return RepositoryReference::parse($value, $branch, $path);
    }

    private function render(string $html, ?RepositoryReference $reference = null, bool $allowHtml = true): string
    {
        return $this->renderer()->toHtml($html, $reference ?? $this->reference(), false, $allowHtml);
    }

    /**
     * A picture has to come from the address that serves the file itself.
     * github.com would serve its page about the file, which is not an image.
     */
    public function testRelativePicturesComeFromTheRawAddress(): void
    {
        $html = $this->render('<p><img src="docs/screenshot.png" alt="A screenshot"></p>');

        $this->assertStringContainsString(
            'https://raw.githubusercontent.com/moritzfl/Typemill-Plugins/HEAD/docs/screenshot.png',
            $html
        );
        $this->assertStringContainsString('alt="A screenshot"', $html);
    }

    /** A link is meant to land a reader on the file's page, which is github.com. */
    public function testRelativeLinksPointAtTheRepository(): void
    {
        $html = $this->render('<p><a href="CONTRIBUTING.md">How to help</a></p>');

        $this->assertStringContainsString(
            'https://github.com/moritzfl/Typemill-Plugins/blob/HEAD/CONTRIBUTING.md',
            $html
        );
    }

    /** A named branch is used instead of HEAD, so the page matches the branch. */
    public function testANamedBranchIsUsed(): void
    {
        $html = $this->render(
            '<p><img src="logo.png"><a href="docs/x.md">x</a></p>',
            $this->reference('moritzfl/Typemill-Plugins', 'develop')
        );

        $this->assertStringContainsString('raw.githubusercontent.com/moritzfl/Typemill-Plugins/develop/logo.png', $html);
        $this->assertStringContainsString('/blob/develop/docs/x.md', $html);
    }

    /**
     * A readme deeper in the repository resolves its neighbours against its own
     * directory, and a leading slash against the repository root.
     */
    public function testPathsResolveAgainstTheFilesOwnDirectory(): void
    {
        $reference = $this->reference('moritzfl/Typemill-Plugins', 'main', 'docs/guide/usage.md');

        $html = $this->render(
            '<p><img src="images/one.png"><img src="../two.png"><img src="/root.png"></p>',
            $reference
        );

        $this->assertStringContainsString('/main/docs/guide/images/one.png', $html);
        $this->assertStringContainsString('/main/docs/two.png', $html);
        $this->assertStringContainsString('/main/root.png', $html);
    }

    /** Absolute addresses, anchors and mail links are already where they belong. */
    public function testAddressesThatAreAlreadyAbsoluteAreLeftAlone(): void
    {
        $html = $this->render(
            '<p><a href="https://typemill.net">Typemill</a>'
            . '<a href="#usage">Usage</a>'
            . '<a href="mailto:me@example.com">Mail</a>'
            . '<img src="//img.shields.io/badge.svg"></p>'
        );

        $this->assertStringContainsString('href="https://typemill.net"', $html);
        $this->assertStringContainsString('href="#usage"', $html);
        $this->assertStringContainsString('href="mailto:me@example.com"', $html);
        // Protocol-relative keeps its host but is pinned to https.
        $this->assertStringContainsString('src="https://img.shields.io/badge.svg"', $html);
    }

    /** A link that leaves the site cannot hand the new tab a way back to it. */
    public function testLinksThatLeaveTheSiteSaySo(): void
    {
        $html = $this->render('<p><a href="https://typemill.net">Typemill</a></p>');

        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    /**
     * The part that matters most: a readme is not trusted to run anything.
     */
    public function testNothingInAReadmeCanRunOrLoadCode(): void
    {
        $hostile = '<p>Text</p>'
            . '<script>alert(1)</script>'
            . '<style>body{display:none}</style>'
            . '<iframe src="https://evil.example"></iframe>'
            . '<object data="x.swf"></object>'
            . '<embed src="x.swf">'
            . '<form action="https://evil.example"><input name="a"><button>Go</button></form>'
            . '<img src="x.png" onerror="alert(2)">'
            . '<a href="javascript:alert(3)">Click</a>'
            . '<a href="vbscript:msgbox(4)">Click</a>'
            . '<p onclick="alert(5)" onmouseover="alert(6)">Hover</p>'
            . '<div style="position:fixed;inset:0">Cover</div>';

        $html = $this->render($hostile);

        $this->assertStringContainsString('Text', $html, 'The readme itself must survive');

        foreach (['<script', '<style', '<iframe', '<object', '<embed', '<form', '<input', '<button'] as $tag) {
            $this->assertStringNotContainsString($tag, $html, $tag . ' survived');
        }

        foreach (['onerror', 'onclick', 'onmouseover', 'javascript:', 'vbscript:', 'style='] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html, $fragment . ' survived');
        }
    }

    /** A data: URL carries its payload inline, so it is not a link to anywhere. */
    public function testInlineDataIsNotAnAddress(): void
    {
        $html = $this->render(
            '<p><a href="data:text/html;base64,PHNjcmlwdD4=">Click</a>'
            . '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="></p>'
        );

        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('data:image/svg', $html);
    }

    /** The badges and centred logos a readme is full of are written as HTML. */
    public function testTheHtmlAReadmeNormallyUsesSurvives(): void
    {
        $html = $this->render(
            '<p align="center"><img src="logo.png" width="120"></p>'
            . '<details><summary>More</summary><p>Hidden</p></details>'
            . '<p>Press <kbd>Ctrl</kbd></p>'
            . '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>B</td></tr></tbody></table>'
        );

        $this->assertStringContainsString('align="center"', $html);
        $this->assertStringContainsString('width="120"', $html);
        $this->assertStringContainsString('<details>', $html);
        $this->assertStringContainsString('<kbd>', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    /** With raw HTML switched off, only what Markdown itself produces is kept. */
    public function testRawHtmlCanBeStrippedEntirely(): void
    {
        $html = $this->render(
            '<p>Kept</p><details><summary>Gone</summary><p>Inner</p></details><p>Also kept</p>',
            null,
            false
        );

        $this->assertStringContainsString('Kept', $html);
        $this->assertStringContainsString('Inner', $html, 'The text inside must survive its wrapper');
        $this->assertStringContainsString('Also kept', $html);
        $this->assertStringNotContainsString('<details>', $html);
        $this->assertStringNotContainsString('<summary>', $html);
    }

    /**
     * The page has a title of its own, and a readme opens with the project's
     * name, so printing both reads as a mistake.
     */
    public function testTheOpeningHeadingCanBeDropped(): void
    {
        $this->assertSame(
            "Body text\n",
            ReadmeRenderer::withoutLeadingHeading("# Project\n\nBody text\n")
        );

        $this->assertSame(
            "Body text\n",
            ReadmeRenderer::withoutLeadingHeading("Project\n=======\n\nBody text\n")
        );
    }

    /** Only the first heading, and only when nothing comes before it. */
    public function testHeadingsFurtherDownAreKept(): void
    {
        $markdown = "Intro line\n\n# Not the first thing\n\nBody\n";
        $this->assertSame($markdown, ReadmeRenderer::withoutLeadingHeading($markdown));

        $kept = "# One\n\n## Two\n\nBody\n";
        $this->assertStringContainsString('## Two', ReadmeRenderer::withoutLeadingHeading($kept));
    }

    /** Markup that cannot be parsed cannot be checked, so it is not shown. */
    public function testAnEmptyReadmeRendersNothing(): void
    {
        $this->assertSame('', $this->render(''));
        $this->assertSame('', $this->render('   '));
    }

    /**
     * Markdown writes a column's alignment as a style attribute, and style
     * attributes are removed. The alignment has to survive that as the
     * attribute that says the same thing.
     */
    public function testColumnAlignmentSurvivesTheRemovalOfStyles(): void
    {
        $html = $this->render(
            '<table><thead><tr>'
            . '<th>Left</th>'
            . '<th style="text-align: center;">Middle</th>'
            . '<th style="text-align: right;">Right</th>'
            . '</tr></thead><tbody><tr>'
            . '<td style="text-align: center;">2</td>'
            . '</tr></tbody></table>'
        );

        $this->assertStringNotContainsString('style=', $html, 'the style attribute should be gone');
        $this->assertStringContainsString('<th align="center">Middle</th>', $html);
        $this->assertStringContainsString('<th align="right">Right</th>', $html);
        $this->assertStringContainsString('<td align="center">2</td>', $html);
    }

    /** Anything else in a style attribute still goes. */
    public function testOnlyTheAlignmentIsTakenOutOfAStyle(): void
    {
        $html = $this->render('<td style="position:fixed;inset:0;text-align:center">x</td>');

        $this->assertStringNotContainsString('position', $html);
        $this->assertStringNotContainsString('inset', $html);
        $this->assertStringContainsString('align="center"', $html);
    }

    /** An align an author wrote themselves is not replaced by one from a style. */
    public function testAnExistingAlignIsLeftAlone(): void
    {
        $html = $this->render('<td align="right" style="text-align:center">x</td>');

        $this->assertStringContainsString('align="right"', $html);
        $this->assertStringNotContainsString('align="center"', $html);
    }

    /**
     * A table is as wide as its columns need. Themes hang the horizontal
     * scrolling off the container Typemill's own renderer provides, so a table
     * written as raw HTML in a readme needs that container too - otherwise it
     * pushes the whole page sideways.
     */
    public function testRawTablesGetTheContainerThemesScrollWith(): void
    {
        $html = $this->render('<table align="center"><tr><td>Only cell</td></tr></table>');

        $this->assertStringContainsString('<div class="tm-table">', $html);
        $this->assertStringContainsString('align="center"', $html, 'the table keeps centring itself');

        // The table stays a table: its own layout is what puts cells in a row.
        $this->assertStringNotContainsString('display', $html);
    }

    /** A markdown table already arrives wrapped, and is not wrapped twice. */
    public function testAWrappedTableIsNotWrappedAgain(): void
    {
        $html = $this->render('<div class="tm-table"><table><tr><td>x</td></tr></table></div>');

        $this->assertSame(1, substr_count($html, 'tm-table'));
    }
}
