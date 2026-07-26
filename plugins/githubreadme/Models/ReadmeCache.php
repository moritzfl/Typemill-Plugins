<?php

namespace Plugins\githubreadme\Models;

/**
 * The copy of the readme that the site falls back on.
 *
 * This is not a cache in the sense of "throw it away when it expires". It is the
 * page's content: GitHub is the source, and this is what the site serves when
 * the source cannot be reached, refuses to answer, or has gone away entirely.
 * Nothing here ever deletes an entry for being old - staleness is reported and
 * left to the caller, which prefers a stale page to an empty one.
 *
 * Entries are written whole or not at all, so a request that arrives mid-write
 * cannot read half a file.
 */
class ReadmeCache
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{
     *     markdown: string, etag: ?string, fetched_at: int, checked_at: int,
     *     failed_at: ?int, failure: ?string, slug: string
     * }|null
     */
    public function read(string $key): ?array
    {
        $file = $this->fileFor($key);
        if ($file === null || !is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        if (!is_array($decoded) || !isset($decoded['markdown']) || !is_string($decoded['markdown'])) {
            return null;
        }

        return [
            'markdown' => $decoded['markdown'],
            'etag' => isset($decoded['etag']) && is_string($decoded['etag']) ? $decoded['etag'] : null,
            'fetched_at' => (int) ($decoded['fetched_at'] ?? 0),
            'checked_at' => (int) ($decoded['checked_at'] ?? $decoded['fetched_at'] ?? 0),
            'failed_at' => isset($decoded['failed_at']) ? (int) $decoded['failed_at'] : null,
            'failure' => isset($decoded['failure']) && is_string($decoded['failure']) ? $decoded['failure'] : null,
            'slug' => isset($decoded['slug']) && is_string($decoded['slug']) ? $decoded['slug'] : '',
        ];
    }

    /** A fresh copy from GitHub. Clears any remembered failure. */
    public function store(string $key, string $slug, string $markdown, ?string $etag): bool
    {
        return $this->write($key, [
            'slug' => $slug,
            'markdown' => $markdown,
            'etag' => $etag,
            'fetched_at' => time(),
            'checked_at' => time(),
            'failed_at' => null,
            'failure' => null,
        ]);
    }

    /**
     * GitHub confirmed the copy is still current (304), so it counts as freshly
     * checked without being freshly fetched.
     */
    public function confirm(string $key, array $entry, ?string $etag = null): bool
    {
        $entry['checked_at'] = time();
        $entry['failed_at'] = null;
        $entry['failure'] = null;

        if ($etag !== null && $etag !== '') {
            $entry['etag'] = $etag;
        }

        return $this->write($key, $entry);
    }

    /**
     * Remember that GitHub could not be asked, or would not answer.
     *
     * Written next to the content rather than instead of it, so the page keeps
     * its text and the next request knows not to try again immediately. An entry
     * is created even when there is nothing cached yet, because the backoff
     * matters most in exactly that case - a rate-limited site would otherwise
     * spend every page view on a request that cannot succeed.
     */
    public function rememberFailure(string $key, string $slug, string $failure, ?array $entry): bool
    {
        $entry = $entry ?? [
            'slug' => $slug,
            'markdown' => '',
            'etag' => null,
            'fetched_at' => 0,
            'checked_at' => 0,
        ];

        $entry['failed_at'] = time();
        $entry['failure'] = $failure;

        return $this->write($key, $entry);
    }

    /** Seconds since the copy was last confirmed current, or null when there is none. */
    public function age(array $entry): ?int
    {
        $checked = (int) ($entry['checked_at'] ?? 0);

        return $checked > 0 ? max(0, time() - $checked) : null;
    }

    private function write(string $key, array $entry): bool
    {
        $file = $this->fileFor($key);
        if ($file === null) {
            return false;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return false;
        }

        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        // Written beside the target and moved into place, so a reader either
        // sees the previous entry or the new one, never a half of either.
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * The key comes from sha1(), but it is used to build a path, so it is
     * checked rather than trusted.
     */
    private function fileFor(string $key): ?string
    {
        if (preg_match('/^[a-f0-9]{40}$/', $key) !== 1) {
            return null;
        }

        return $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
