<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\githubreadme\Models\GitHubClient;
use Plugins\githubreadme\Models\ReadmeCache;
use Plugins\githubreadme\Models\ReadmeSource;
use Plugins\githubreadme\Models\RepositoryReference;

/**
 * What the page gets when GitHub does not cooperate.
 *
 * The promise of the plugin is that a page which has been filled once stays
 * filled - GitHub being unreachable, rate-limiting the site, or having lost the
 * repository must never leave a reader with an empty page. That promise is the
 * whole subject here, so every way GitHub can decline is asked for by name.
 *
 * GitHub is never actually called: the client is replaced by one that answers
 * from a script, and counts how often it was asked.
 */
class GithubReadmeSourceTest extends TestCase
{
    private string $directory;
    private RepositoryReference $reference;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->directory = $base . '/githubreadme-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);

        $this->reference = RepositoryReference::parse('moritzfl/Typemill-Plugins');
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function cache(): ReadmeCache
    {
        return new ReadmeCache($this->directory);
    }

    /** @param array<int, array<string, mixed>> $answers */
    private function client(array $answers): GitHubClient
    {
        return new class($answers) extends GitHubClient {
            public int $calls = 0;
            /** @var array<int, array<string, mixed>> */
            private array $answers;

            public function __construct(array $answers)
            {
                parent::__construct();
                $this->answers = $answers;
            }

            public function fetchFile(RepositoryReference $reference, ?string $etag = null): array
            {
                $this->calls++;
                $answer = array_shift($this->answers) ?? ['status' => 0, 'error' => 'No answer left.'];

                return $answer + [
                    'status' => 0,
                    'markdown' => null,
                    'etag' => null,
                    'error' => null,
                    'rate_limited' => false,
                ];
            }
        };
    }

    /** Pretend the stored copy was taken, and any failure recorded, long ago. */
    private function ageStoredCopy(int $seconds): void
    {
        $file = $this->directory . '/' . $this->reference->cacheKey() . '.json';
        $entry = json_decode((string) file_get_contents($file), true);

        foreach (['fetched_at', 'checked_at', 'failed_at'] as $field) {
            if (!empty($entry[$field])) {
                $entry[$field] = (int) $entry[$field] - $seconds;
            }
        }

        file_put_contents($file, json_encode($entry));
    }

    public function testTheFirstViewFetchesAndStoresTheReadme(): void
    {
        $client = $this->client([['status' => 200, 'markdown' => '# Hello', 'etag' => 'W/"abc"']]);
        $result = (new ReadmeSource($this->cache(), $client))->markdownFor($this->reference);

        $this->assertSame('# Hello', $result['markdown']);
        $this->assertSame('network', $result['origin']);
        $this->assertFalse($result['stale']);
        $this->assertNull($result['failure']);
        $this->assertSame(1, $client->calls);

        $this->assertNotNull($this->cache()->read($this->reference->cacheKey()));
    }

    /** Within the freshness window GitHub is not asked at all. */
    public function testASecondViewDoesNotAskGithubAgain(): void
    {
        $this->cache()->store($this->reference->cacheKey(), $this->reference->slug(), '# Stored', 'W/"abc"');

        $client = $this->client([]);
        $result = (new ReadmeSource($this->cache(), $client, 60))->markdownFor($this->reference);

        $this->assertSame('# Stored', $result['markdown']);
        $this->assertSame('fresh', $result['origin']);
        $this->assertSame(0, $client->calls, 'A fresh copy must not cost a request');
    }

    /** An unchanged readme is confirmed, which costs nothing from the allowance. */
    public function testAnUnchangedReadmeIsConfirmedRatherThanRefetched(): void
    {
        $this->cache()->store($this->reference->cacheKey(), $this->reference->slug(), '# Stored', 'W/"abc"');
        $this->ageStoredCopy(3600);

        $client = $this->client([['status' => 304, 'etag' => 'W/"abc"']]);
        $result = (new ReadmeSource($this->cache(), $client, 1))->markdownFor($this->reference);

        $this->assertSame('# Stored', $result['markdown']);
        $this->assertSame('confirmed', $result['origin']);
        $this->assertFalse($result['stale']);
        $this->assertSame(1, $client->calls);

        // Confirmed means fresh again: the next view asks for nothing.
        $again = $this->client([]);
        $this->assertSame('fresh', (new ReadmeSource($this->cache(), $again, 60))->markdownFor($this->reference)['origin']);
        $this->assertSame(0, $again->calls);
    }

    /** The promise. Every way GitHub can decline still leaves the page filled. */
    #[DataProvider('waysGithubDeclines')]
    public function testAFailingGithubStillLeavesThePageFilled(array $answer, string $because): void
    {
        $this->cache()->store($this->reference->cacheKey(), $this->reference->slug(), '# Stored', 'W/"abc"');
        $this->ageStoredCopy(3600);

        $client = $this->client([$answer]);
        $result = (new ReadmeSource($this->cache(), $client, 1))->markdownFor($this->reference);

        $this->assertSame('# Stored', $result['markdown'], 'The page lost its content when ' . $because);
        $this->assertSame('cache', $result['origin']);
        $this->assertTrue($result['stale']);
        $this->assertNotNull($result['failure'], 'The reason must be available to the admin');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function waysGithubDeclines(): array
    {
        return [
            'the network is down' => [['status' => 0, 'error' => 'GitHub could not be reached.'], 'the network was down'],
            'the request timed out' => [['status' => 0, 'error' => 'Operation timed out'], 'the request timed out'],
            'the allowance is spent' => [['status' => 403, 'error' => 'rate limit', 'rate_limited' => true], 'the rate limit was reached'],
            'too many requests' => [['status' => 429, 'error' => 'rate limit', 'rate_limited' => true], 'GitHub asked for fewer requests'],
            'the token was rejected' => [['status' => 401, 'error' => 'bad token'], 'the token was rejected'],
            'the repository is gone' => [['status' => 404, 'error' => 'not found'], 'the repository had gone away'],
            'github is broken' => [['status' => 500, 'error' => 'server error'], 'GitHub answered with an error'],
            'the answer was empty' => [['status' => 200, 'markdown' => null, 'error' => 'GitHub returned an empty file.'], 'GitHub answered with nothing'],
        ];
    }

    /**
     * With nothing stored there is nothing to show, and the page keeps its own
     * content. The reason travels with it so it can be seen in the admin.
     */
    public function testWithoutAStoredCopyTheReasonIsReported(): void
    {
        $client = $this->client([['status' => 403, 'error' => 'rate limit', 'rate_limited' => true]]);
        $result = (new ReadmeSource($this->cache(), $client, 60))->markdownFor($this->reference);

        $this->assertNull($result['markdown']);
        $this->assertSame('none', $result['origin']);
        $this->assertNotNull($result['failure']);
    }

    /**
     * A refused site must not spend every page view on the same doomed request:
     * a visitor would wait for the timeout every time.
     */
    public function testAfterAFailureGithubIsLeftAloneForAWhile(): void
    {
        $this->cache()->store($this->reference->cacheKey(), $this->reference->slug(), '# Stored', 'W/"abc"');
        $this->ageStoredCopy(3600);

        $client = $this->client([
            ['status' => 500, 'error' => 'server error'],
            ['status' => 200, 'markdown' => '# Should not be asked for yet'],
        ]);

        $source = new ReadmeSource($this->cache(), $client, 1);

        $first = $source->markdownFor($this->reference);
        $this->assertSame('cache', $first['origin']);
        $this->assertSame(1, $client->calls);

        $second = (new ReadmeSource($this->cache(), $client, 1))->markdownFor($this->reference);
        $this->assertSame('# Stored', $second['markdown']);
        $this->assertSame('cache', $second['origin']);
        $this->assertSame(1, $client->calls, 'GitHub was asked again during the backoff');
    }

    /** Once the backoff has passed, GitHub is tried again. */
    public function testTheBackoffEnds(): void
    {
        $this->cache()->store($this->reference->cacheKey(), $this->reference->slug(), '# Stored', 'W/"abc"');
        $this->ageStoredCopy(3600);

        $failing = $this->client([['status' => 500, 'error' => 'server error']]);
        (new ReadmeSource($this->cache(), $failing, 1))->markdownFor($this->reference);

        $this->ageStoredCopy(ReadmeSource::RETRY_AFTER_FAILURE_SECONDS + 60);

        $recovered = $this->client([['status' => 200, 'markdown' => '# New']]);
        $result = (new ReadmeSource($this->cache(), $recovered, 1))->markdownFor($this->reference);

        $this->assertSame('# New', $result['markdown']);
        $this->assertSame('network', $result['origin']);
        $this->assertSame(1, $recovered->calls);
    }

    /** A recovered fetch clears the failure, so the note disappears with it. */
    public function testARecoveredFetchClearsTheFailure(): void
    {
        $this->cache()->rememberFailure($this->reference->cacheKey(), $this->reference->slug(), 'rate limit', null);

        // Past the backoff, or the failure itself would stop the retry - which is
        // the subject of its own test above.
        $this->ageStoredCopy(ReadmeSource::RETRY_AFTER_FAILURE_SECONDS + 60);

        $client = $this->client([['status' => 200, 'markdown' => '# Back']]);
        $result = (new ReadmeSource($this->cache(), $client, 60))->markdownFor($this->reference);

        $this->assertSame('network', $result['origin']);
        $this->assertNull($result['failure']);

        $entry = $this->cache()->read($this->reference->cacheKey());
        $this->assertNull($entry['failed_at']);
        $this->assertNull($entry['failure']);
    }

    /** Half a file is not a readme: it reads as nothing stored, not as content. */
    public function testATruncatedStoredCopyIsIgnored(): void
    {
        file_put_contents(
            $this->directory . '/' . $this->reference->cacheKey() . '.json',
            '{"markdown": "# Half a fi'
        );

        $this->assertNull($this->cache()->read($this->reference->cacheKey()));

        $client = $this->client([['status' => 200, 'markdown' => '# Whole']]);
        $result = (new ReadmeSource($this->cache(), $client, 60))->markdownFor($this->reference);

        $this->assertSame('# Whole', $result['markdown']);
    }

    /** The key builds a file name, so a made-up one must not reach the disk. */
    public function testOnlyRealKeysReachTheDisk(): void
    {
        $cache = $this->cache();

        foreach (['../escape', 'not-a-sha', '', str_repeat('g', 40)] as $key) {
            $this->assertFalse($cache->store($key, 'owner/name', '# No', null));
            $this->assertNull($cache->read($key));
        }
    }
}
