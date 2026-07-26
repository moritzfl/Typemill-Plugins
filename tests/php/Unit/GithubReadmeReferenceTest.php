<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\githubreadme\Models\RepositoryReference;

/**
 * Reading the repository an author typed into the page.
 *
 * The value is written by hand and is used to build a request, so the forms it is
 * plausibly written in are accepted and everything else is refused. Refusing is
 * the important half: a guess would send a request to a host nobody named.
 */
class GithubReadmeReferenceTest extends TestCase
{
    public function testTheFormsAnAuthorPlausiblyWrites(): void
    {
        $accepted = [
            'moritzfl/Typemill-Plugins',
            'https://github.com/moritzfl/Typemill-Plugins',
            'http://github.com/moritzfl/Typemill-Plugins',
            'https://www.github.com/moritzfl/Typemill-Plugins',
            'https://github.com/moritzfl/Typemill-Plugins/',
            'https://github.com/moritzfl/Typemill-Plugins.git',
            'git@github.com:moritzfl/Typemill-Plugins.git',
            '  moritzfl/Typemill-Plugins  ',
        ];

        foreach ($accepted as $value) {
            $reference = RepositoryReference::parse($value);

            $this->assertNotNull($reference, 'Expected to be read: ' . var_export($value, true));
            $this->assertSame('moritzfl', $reference->owner());
            $this->assertSame('Typemill-Plugins', $reference->repository());
        }
    }

    public function testAnythingThatIsNotAGithubRepositoryIsRefused(): void
    {
        $refused = [
            '',
            '   ',
            'moritzfl',
            'moritzfl/',
            '/Typemill-Plugins',
            'moritzfl/Typemill-Plugins/extra',
            'https://gitlab.com/moritzfl/Typemill-Plugins',
            'https://github.evil.com/moritzfl/Typemill-Plugins',
            'https://github.com/../../etc/passwd',
            'javascript:alert(1)',
            'file:///etc/passwd',
            'https://github.com/moritzfl/Typemill-Plugins@evil.com',
            "moritzfl/Typemill\0-Plugins",
        ];

        foreach ($refused as $value) {
            $this->assertNull(
                RepositoryReference::parse($value),
                'Expected to be refused: ' . var_export($value, true)
            );
        }
    }

    /** The address bar carries the branch, and sometimes the file as well. */
    public function testBranchAndFileAreTakenFromAnAddress(): void
    {
        $reference = RepositoryReference::parse('https://github.com/moritzfl/Typemill-Plugins/tree/develop');
        $this->assertSame('develop', $reference->branch());
        $this->assertNull($reference->path());

        $blob = RepositoryReference::parse('https://github.com/moritzfl/Typemill-Plugins/blob/main/docs/usage.md');
        $this->assertSame('main', $blob->branch());
        $this->assertSame('docs/usage.md', $blob->path());
    }

    /** The plugin's own fields are the more specific statement, so they win. */
    public function testTheFieldsWinOverTheAddress(): void
    {
        $reference = RepositoryReference::parse(
            'https://github.com/moritzfl/Typemill-Plugins/tree/develop',
            'release',
            'README.md'
        );

        $this->assertSame('release', $reference->branch());
        $this->assertSame('README.md', $reference->path());
    }

    /**
     * A branch and a file reach a URL, so what they may contain is limited.
     */
    public function testBranchAndFileThatWouldChangeTheRequestAreRefused(): void
    {
        $hostile = [
            ['branch' => '../../etc', 'path' => ''],
            ['branch' => 'main?ref=other', 'path' => ''],
            ['branch' => "main\nX-Injected: 1", 'path' => ''],
            ['branch' => '', 'path' => '../../../etc/passwd'],
            ['branch' => '', 'path' => 'docs/../../secret'],
            ['branch' => '', 'path' => "docs/x\0.md"],
        ];

        foreach ($hostile as $case) {
            $this->assertNull(
                RepositoryReference::parse('moritzfl/Typemill-Plugins', $case['branch'], $case['path']),
                'Expected to be refused: ' . json_encode($case)
            );
        }
    }

    /**
     * Without a file the readme endpoint is used, because it is the only thing
     * that knows what the readme is called and which branch is the default one.
     */
    public function testTheRequestPathFollowsWhatWasNamed(): void
    {
        $this->assertSame(
            '/repos/moritzfl/Typemill-Plugins/readme',
            RepositoryReference::parse('moritzfl/Typemill-Plugins')->apiPath()
        );

        $this->assertSame(
            '/repos/moritzfl/Typemill-Plugins/readme?ref=develop',
            RepositoryReference::parse('moritzfl/Typemill-Plugins', 'develop')->apiPath()
        );

        $this->assertSame(
            '/repos/moritzfl/Typemill-Plugins/contents/docs/usage.md',
            RepositoryReference::parse('moritzfl/Typemill-Plugins', '', 'docs/usage.md')->apiPath()
        );
    }

    /** Two pages pointing at the same file share one stored copy, and no others do. */
    public function testTheCacheKeyFollowsExactlyWhatWasNamed(): void
    {
        $one = RepositoryReference::parse('moritzfl/Typemill-Plugins');
        $same = RepositoryReference::parse('https://github.com/moritzfl/Typemill-Plugins');
        $branch = RepositoryReference::parse('moritzfl/Typemill-Plugins', 'develop');
        $file = RepositoryReference::parse('moritzfl/Typemill-Plugins', '', 'docs/usage.md');

        $this->assertSame($one->cacheKey(), $same->cacheKey());
        $this->assertNotSame($one->cacheKey(), $branch->cacheKey());
        $this->assertNotSame($one->cacheKey(), $file->cacheKey());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $one->cacheKey());
    }
}
