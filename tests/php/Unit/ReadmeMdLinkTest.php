<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\githubreadme\Models\RepositoryLink;
use Plugins\githubreadme\Models\RepositoryReference;

/**
 * The link back to the repository.
 *
 * A readme seldom links to itself, so a site that shows one has to offer the way
 * back. The wording follows the site language, and it is read from the plugin's
 * own language files rather than through Typemill's translator - that one loads
 * plugin files for the admin only and would hand back the key on a public page.
 */
class GithubReadmeLinkTest extends TestCase
{
    private function plugin(): string
    {
        return is_dir('/var/www/html/plugins/githubreadme')
            ? '/var/www/html/plugins/githubreadme'
            : dirname(__DIR__, 3) . '/plugins/githubreadme';
    }

    private function link(): RepositoryLink
    {
        return new RepositoryLink($this->plugin());
    }

    public function testTheWordingFollowsTheSiteLanguage(): void
    {
        $this->assertSame('View on GitHub', $this->link()->label('en'));
        $this->assertSame('Auf GitHub ansehen', $this->link()->label('de'));
    }

    /** A language written as de-DE or de_DE is still German. */
    public function testARegionIsIgnored(): void
    {
        foreach (['de-DE', 'de_DE', 'DE', ' de '] as $language) {
            $this->assertSame(
                'Auf GitHub ansehen',
                $this->link()->label($language),
                'Expected German for ' . var_export($language, true)
            );
        }
    }

    /** A language the plugin has no file for falls back to English, not to a key. */
    public function testAnUnknownLanguageFallsBackToEnglish(): void
    {
        foreach (['fr', 'zz', '', 'nonsense', '../../etc/passwd'] as $language) {
            $this->assertSame(
                'View on GitHub',
                $this->link()->label($language),
                'Expected English for ' . var_export($language, true)
            );
        }
    }

    /** Even with no language files at all, the link still says something. */
    public function testWithoutAnyLanguageFileTheLinkIsStillLabelled(): void
    {
        $empty = new RepositoryLink(sys_get_temp_dir() . '/githubreadme-no-languages');

        $this->assertSame('View on GitHub', $empty->label('de'));
    }

    /** The author's own wording is not second-guessed. */
    public function testTheSettingWinsOverTheTranslation(): void
    {
        $html = $this->link()->html(
            RepositoryReference::parse('moritzfl/Typemill-Plugins'),
            'de',
            'Zum Repository'
        );

        $this->assertStringContainsString('Zum Repository', $html);
        $this->assertStringNotContainsString('Auf GitHub ansehen', $html);
    }

    /**
     * Where the link goes: the page that shows what the reader just read.
     */
    public function testTheLinkPointsAtWhatWasShown(): void
    {
        $link = $this->link();

        $this->assertSame(
            'https://github.com/moritzfl/Typemill-Plugins',
            $link->url(RepositoryReference::parse('moritzfl/Typemill-Plugins'))
        );

        $this->assertSame(
            'https://github.com/moritzfl/Typemill-Plugins/tree/develop',
            $link->url(RepositoryReference::parse('moritzfl/Typemill-Plugins', 'develop'))
        );

        $this->assertSame(
            'https://github.com/moritzfl/Typemill-Plugins/blob/main/docs/usage.md',
            $link->url(RepositoryReference::parse('moritzfl/Typemill-Plugins', 'main', 'docs/usage.md'))
        );

        $this->assertSame(
            'https://github.com/moritzfl/Typemill-Plugins/blob/HEAD/docs/usage.md',
            $link->url(RepositoryReference::parse('moritzfl/Typemill-Plugins', '', 'docs/usage.md'))
        );
    }

    /** A label is text, and text from a setting is not markup. */
    public function testALabelCannotCarryMarkup(): void
    {
        $html = $this->link()->html(
            RepositoryReference::parse('moritzfl/Typemill-Plugins'),
            'en',
            '<script>alert(1)</script>'
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** The link leaves this site, so it cannot hand the new tab a way back. */
    public function testTheLinkIsSafeToFollow(): void
    {
        $html = $this->link()->html(RepositoryReference::parse('moritzfl/Typemill-Plugins'), 'en');

        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('href="https://github.com/moritzfl/Typemill-Plugins"', $html);
    }
}
