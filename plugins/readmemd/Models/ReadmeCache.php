<?php

namespace Plugins\readmemd\Models;

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

    /**
     * Where copies were kept before the plugin was renamed, if anywhere.
     *
     * The plugin used to be named after GitHub and wrote to a folder of that
     * name. Those copies are the only reason a page still has its text when
     * GitHub cannot be reached, so a rename must not be what takes it away.
     */
    private ?string $formerDirectory;

    public function __construct(string $directory, ?string $formerDirectory = null)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);

        $former = $formerDirectory === null ? null : rtrim($formerDirectory, DIRECTORY_SEPARATOR);
        $this->formerDirectory = $former === $this->directory ? null : $former;
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
        if ($file === null) {
            return null;
        }

        if (!is_file($file)) {
            $file = $this->inheritedFile($key, $file);
        }

        if ($file === null) {
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

    /**
     * The copy a previous folder holds for this key, taken over on the way.
     *
     * Only ever consulted when nothing is held here, so a newer copy is never
     * replaced by an older one, and only for a key that has already been checked,
     * so the file name cannot be anything but one this cache would write itself.
     * The copy is brought over so the next read finds it here, and is read from
     * where it is if that fails - a data folder that cannot be written to is a
     * reason to serve the page, not to lose it. Nothing deletes the old folder,
     * because nothing here is entitled to.
     */
    private function inheritedFile(string $key, string $target): ?string
    {
        if ($this->formerDirectory === null) {
            return null;
        }

        $former = $this->formerDirectory . DIRECTORY_SEPARATOR . $key . '.json';

        if (!is_file($former) || !is_readable($former)) {
            return null;
        }

        // Whole or not at all, same as write(): a half-copied file would make
        // the next read discard a good former copy forever.
        if (is_dir($this->directory) || @mkdir($this->directory, 0755, true) || is_dir($this->directory)) {
            $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';

            if (@copy($former, $temporary) && @rename($temporary, $target)) {
                return $target;
            }

            @unlink($temporary);
        }

        // The data folder could not be written to; serve from where the copy is.
        return $former;
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
