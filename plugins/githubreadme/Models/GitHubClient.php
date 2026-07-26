<?php

namespace Plugins\githubreadme\Models;

/**
 * One GET against the GitHub API.
 *
 * This runs while a visitor waits for a page, so it is built to give up rather
 * than to insist: short timeouts, a capped response, no retries. It never throws
 * and never reports a partial answer as a good one - the caller decides what to
 * do with a failure, and what it does is fall back on the cache.
 */
class GitHubClient
{
    public const DEFAULT_API_BASE = 'https://api.github.com';

    /** A readme is a text file; anything of this size is not one. */
    public const MAX_RESPONSE_BYTES = 2097152; // 2 MB

    private string $apiBase;
    private ?string $token;
    private int $timeout;

    public function __construct(string $apiBase = self::DEFAULT_API_BASE, ?string $token = null, int $timeout = 5)
    {
        $this->apiBase = rtrim($apiBase !== '' ? $apiBase : self::DEFAULT_API_BASE, '/');
        $this->token = ($token !== null && $token !== '') ? $token : null;
        $this->timeout = max(1, min($timeout, 30));
    }

    /**
     * Fetch a file's contents.
     *
     * `$etag` is what the last answer was tagged with. GitHub replies 304 to it
     * without spending a request from the hourly allowance, which is what keeps
     * a busy site from being locked out.
     *
     * @return array{status: int, markdown: ?string, etag: ?string, error: ?string, rate_limited: bool}
     */
    public function fetchFile(RepositoryReference $reference, ?string $etag = null): array
    {
        $url = $this->apiBase . $reference->apiPath();

        $headers = [
            // The raw type returns the file itself; without it GitHub answers
            // with JSON carrying base64, which is a decode step for nothing.
            'Accept: application/vnd.github.raw',
            'X-GitHub-Api-Version: 2022-11-28',
            // GitHub refuses requests that do not name their sender.
            'User-Agent: Typemill-GithubReadme/1.0',
        ];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        $result = function_exists('curl_init')
            ? $this->getWithCurl($url, $headers)
            : $this->getWithStream($url, $headers);

        return $this->interpret($result);
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: ?string, headers: array<int, string>, error: ?string}
     */
    private function getWithCurl(string $url, array $headers): array
    {
        $curl = curl_init($url);
        $received = [];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_HEADERFUNCTION => static function ($handle, $header) use (&$received) {
                $received[] = trim($header);

                return strlen($header);
            },
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        return [
            'status' => $status,
            'body' => $body === false ? null : (string) $body,
            'headers' => $received,
            'error' => $body === false ? ($error !== '' ? $error : 'The request failed.') : null,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: ?string, headers: array<int, string>, error: ?string}
     */
    private function getWithStream(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'follow_location' => 1,
                'max_redirects' => 3,
                // Keeps the stream open for 304 and 4xx, which are answers this
                // caller has to read rather than errors to be hidden.
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES);
        $received = $http_response_header ?? [];

        if ($body === false && $received === []) {
            return [
                'status' => 0,
                'body' => null,
                'headers' => [],
                'error' => 'GitHub could not be reached.',
            ];
        }

        return [
            'status' => self::statusFromHeaders($received),
            'body' => $body === false ? null : (string) $body,
            'headers' => $received,
            'error' => null,
        ];
    }

    /**
     * @param array{status: int, body: ?string, headers: array<int, string>, error: ?string} $result
     * @return array{status: int, markdown: ?string, etag: ?string, error: ?string, rate_limited: bool}
     */
    private function interpret(array $result): array
    {
        $status = $result['status'];
        $etag = self::headerValue($result['headers'], 'etag');

        if ($result['error'] !== null) {
            return $this->problem($status, $result['error'], false);
        }

        // Nothing has changed since the cached copy was taken. This costs no
        // request from the hourly allowance.
        if ($status === 304) {
            return ['status' => 304, 'markdown' => null, 'etag' => $etag, 'error' => null, 'rate_limited' => false];
        }

        if ($status === 200) {
            $body = (string) $result['body'];

            if ($body === '') {
                return $this->problem($status, 'GitHub returned an empty file.', false);
            }

            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                return $this->problem($status, 'The file is larger than expected.', false);
            }

            return ['status' => 200, 'markdown' => $body, 'etag' => $etag, 'error' => null, 'rate_limited' => false];
        }

        // 403 and 429 are how GitHub says "not now": either the hourly
        // allowance is spent or the request was refused outright. Both mean the
        // cached copy has to carry the page, and that asking again straight
        // away is pointless.
        $remaining = self::headerValue($result['headers'], 'x-ratelimit-remaining');
        $rateLimited = $status === 429 || ($status === 403 && $remaining === '0');

        $reason = match (true) {
            $rateLimited => 'GitHub is refusing further requests for now (rate limit).',
            $status === 404 => 'GitHub has no such repository, branch or file - or it is private and the token cannot see it.',
            $status === 401 => 'GitHub rejected the token.',
            $status === 403 => 'GitHub refused the request.',
            $status >= 500 => 'GitHub answered with an error (HTTP ' . $status . ').',
            default => 'GitHub answered with HTTP ' . $status . '.',
        };

        return $this->problem($status, $reason, $rateLimited);
    }

    /** @return array{status: int, markdown: ?string, etag: ?string, error: ?string, rate_limited: bool} */
    private function problem(int $status, string $error, bool $rateLimited): array
    {
        return [
            'status' => $status,
            'markdown' => null,
            'etag' => null,
            'error' => $error,
            'rate_limited' => $rateLimited,
        ];
    }

    /** @param array<int, string> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name) . ':';

        // Last wins: a redirect leaves one set of headers per hop.
        $value = null;
        foreach ($headers as $header) {
            if (is_string($header) && str_starts_with(strtolower($header), $needle)) {
                $value = trim(substr($header, strlen($needle)));
            }
        }

        return $value;
    }

    /** @param array<int, string> $headers */
    private static function statusFromHeaders(array $headers): int
    {
        $status = 0;

        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }
}
