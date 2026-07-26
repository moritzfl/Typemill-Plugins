<?php

namespace Plugins\githubreadme\Models;

/**
 * Decides what the page gets: GitHub's answer, or the copy on disk.
 *
 * The rule is that a page which has ever been filled stays filled. Everything
 * here follows from it:
 *
 *  - A fresh copy is served without asking GitHub at all.
 *  - A stale copy is checked against GitHub, and the check is conditional, so a
 *    readme that has not changed costs nothing from the hourly allowance.
 *  - A failure of any kind - no network, rate limit, 404, a repository that was
 *    deleted - serves the copy that is there, however old.
 *  - After a failure GitHub is left alone for a while. A site that is being
 *    refused would otherwise spend every single page view on a doomed request,
 *    and every visitor would wait for its timeout.
 *  - Only when there is no copy at all does the page keep its own content, and
 *    then the reason is reported so it can be seen in the admin.
 */
class ReadmeSource
{
    /** How long to leave GitHub alone after it could not or would not answer. */
    public const RETRY_AFTER_FAILURE_SECONDS = 300;

    private ReadmeCache $cache;
    private GitHubClient $client;
    private int $freshSeconds;

    public function __construct(ReadmeCache $cache, GitHubClient $client, int $freshMinutes = 60)
    {
        $this->cache = $cache;
        $this->client = $client;
        $this->freshSeconds = max(1, $freshMinutes) * 60;
    }

    /**
     * @return array{
     *     markdown: ?string, origin: string, age: ?int, stale: bool,
     *     failure: ?string, attempted: bool, slug: string
     * }
     *   `origin` is one of: fresh, network, confirmed, cache, none. `attempted`
     *   says whether GitHub was actually asked, which is what separates a first
     *   failure worth recording from the quiet minutes that follow it.
     */
    public function markdownFor(RepositoryReference $reference): array
    {
        $key = $reference->cacheKey();
        $entry = $this->cache->read($key);
        $cached = ($entry !== null && $entry['markdown'] !== '') ? $entry : null;

        // Recently fetched or confirmed: serve it and leave GitHub alone.
        if ($cached !== null && !$this->isStale($cached)) {
            return $this->answer($cached['markdown'], 'fresh', $this->cache->age($cached), false, null, false, $reference);
        }

        // Recently failed: serve what there is rather than wait for the same
        // failure again. With nothing cached this is what keeps a rate-limited
        // site from making every visitor wait for a timeout.
        if ($entry !== null && $this->isInBackoff($entry)) {
            return $cached !== null
                ? $this->answer($cached['markdown'], 'cache', $this->cache->age($cached), true, $entry['failure'], false, $reference)
                : $this->answer(null, 'none', null, true, $entry['failure'], false, $reference);
        }

        $response = $this->client->fetchFile($reference, $cached['etag'] ?? null);

        if ($response['status'] === 200 && $response['markdown'] !== null) {
            $this->cache->store($key, $reference->slug(), $response['markdown'], $response['etag']);

            return $this->answer($response['markdown'], 'network', 0, false, null, true, $reference);
        }

        if ($response['status'] === 304 && $cached !== null) {
            $this->cache->confirm($key, $cached, $response['etag']);

            return $this->answer($cached['markdown'], 'confirmed', 0, false, null, true, $reference);
        }

        $failure = $response['error'] ?? 'GitHub could not be reached.';
        $this->cache->rememberFailure($key, $reference->slug(), $failure, $entry);

        // The whole point: a failure does not empty the page.
        if ($cached !== null) {
            return $this->answer($cached['markdown'], 'cache', $this->cache->age($cached), true, $failure, true, $reference);
        }

        return $this->answer(null, 'none', null, true, $failure, true, $reference);
    }

    /**
     * Fetch now, whatever the stored copy says.
     *
     * For the button in the editor: somebody has changed the readme and wants to
     * see it, so neither the freshness window nor the quiet minutes after a
     * failure apply, and the request is unconditional - being told "not modified"
     * is not an answer when the point is to be given the file.
     *
     * A refresh that fails takes nothing away. The stored copy stays exactly as
     * it was, because a page that was filled must not empty because somebody
     * pressed a button at the wrong moment.
     *
     * @return array{markdown: ?string, origin: string, age: ?int, stale: bool, failure: ?string, attempted: bool, slug: string}
     */
    public function refresh(RepositoryReference $reference): array
    {
        $key = $reference->cacheKey();
        $entry = $this->cache->read($key);
        $cached = ($entry !== null && $entry['markdown'] !== '') ? $entry : null;

        $response = $this->client->fetchFile($reference, null);

        if ($response['status'] === 200 && $response['markdown'] !== null) {
            $this->cache->store($key, $reference->slug(), $response['markdown'], $response['etag']);

            return $this->answer($response['markdown'], 'network', 0, false, null, true, $reference);
        }

        $failure = $response['error'] ?? 'GitHub could not be reached.';
        $this->cache->rememberFailure($key, $reference->slug(), $failure, $entry);

        if ($cached !== null) {
            return $this->answer($cached['markdown'], 'cache', $this->cache->age($cached), true, $failure, true, $reference);
        }

        return $this->answer(null, 'none', null, true, $failure, true, $reference);
    }

    private function isStale(array $entry): bool
    {
        $age = $this->cache->age($entry);

        return $age === null || $age > $this->freshSeconds;
    }

    private function isInBackoff(array $entry): bool
    {
        $failedAt = $entry['failed_at'] ?? null;

        return $failedAt !== null && (time() - (int) $failedAt) < self::RETRY_AFTER_FAILURE_SECONDS;
    }

    /** @return array{markdown: ?string, origin: string, age: ?int, stale: bool, failure: ?string, attempted: bool, slug: string} */
    private function answer(?string $markdown, string $origin, ?int $age, bool $stale, ?string $failure, bool $attempted, RepositoryReference $reference): array
    {
        return [
            'markdown' => $markdown,
            'origin' => $origin,
            'age' => $age,
            'stale' => $stale,
            'failure' => $failure,
            'attempted' => $attempted,
            'slug' => $reference->slug(),
        ];
    }
}
