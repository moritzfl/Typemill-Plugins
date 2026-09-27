<?php

namespace Plugins\gitupdate\Models;

/**
 * One read of the latest commit, the tree, and the zip of that commit.
 *
 * Redirects are followed by hand. GitHub's zipball hops to codeload.github.com;
 * any other host is refused, and the token is not sent on that hop.
 */
final class GitHub
{
    public const MAX_DOWNLOAD_BYTES = 67108864; // 64 MB

    public function __construct(
        private string $apiBase,
        private string $repository,
        private ?string $token
    ) {
    }

    /**
     * @return array{ok: bool, sha?: string, message?: string, date?: string, error?: string, error_key?: string}
     */
    public function head(string $branch): array
    {
        $url = Reference::commitUrl($this->apiBase, $this->repository, $branch);
        $result = $this->get($url, true);
        if (!$result['ok']) {
            return $result;
        }

        $decoded = json_decode((string) $result['body'], true);
        $sha = is_array($decoded) ? Reference::sha($decoded['sha'] ?? null) : null;
        if ($sha === null) {
            return self::problem('GitHub did not return a commit.', 'err_commit');
        }

        $message = '';
        $date = '';
        if (is_array($decoded['commit'] ?? null)) {
            $message = trim((string) ($decoded['commit']['message'] ?? ''));
            $date = (string) ($decoded['commit']['committer']['date'] ?? $decoded['commit']['author']['date'] ?? '');
        }

        $line = preg_split('/\R/', $message)[0] ?? '';

        return [
            'ok' => true,
            'sha' => $sha,
            'message' => $line,
            'date' => $date,
            'error' => null,
            'error_key' => null,
        ];
    }

    /**
     * @return array{ok: bool, plugins?: list<string>, themes?: list<string>, error?: string, error_key?: string}
     */
    public function catalog(string $sha): array
    {
        $result = $this->get(Reference::treeUrl($this->apiBase, $this->repository, $sha), true);
        if (!$result['ok']) {
            return $result;
        }

        $decoded = json_decode((string) $result['body'], true);
        if (!is_array($decoded)) {
            return self::problem('GitHub did not return a file tree.', 'err_tree');
        }

        $parsed = Catalog::fromTreePayload($decoded);
        if ($parsed['truncated']) {
            return self::problem('The repository tree was truncated, so the update was stopped.', 'err_tree');
        }

        return [
            'ok' => true,
            'plugins' => $parsed['plugins'],
            'themes' => $parsed['themes'],
            'error' => null,
            'error_key' => null,
        ];
    }

    /**
     * @return array{ok: bool, bytes?: int, error?: string, error_key?: string}
     */
    public function download(string $sha, string $targetFile): array
    {
        $url = Reference::zipballUrl($this->apiBase, $this->repository, $sha);
        $headers = $this->headers(true);
        $host = strtolower((string) parse_url($this->apiBase, PHP_URL_HOST));

        for ($hop = 0; $hop < 4; $hop++) {
            $result = $this->transfer($url, $headers, $targetFile);
            $status = $result['status'];

            if ($status >= 300 && $status < 400) {
                $next = $result['location'] ?? '';
                @unlink($targetFile);
                if (!Reference::redirectAllowed($this->apiBase, $next)) {
                    return self::problem('The download redirected to an unexpected host.', 'err_redirect');
                }
                $nextHost = strtolower((string) parse_url($next, PHP_URL_HOST));
                if ($nextHost !== $host) {
                    $headers = $this->headers(false);
                }
                $url = $next;
                continue;
            }

            if (!$result['ok']) {
                @unlink($targetFile);

                return $result;
            }

            $bytes = (int) @filesize($targetFile);
            if ($bytes < 1 || $bytes > self::MAX_DOWNLOAD_BYTES) {
                @unlink($targetFile);

                return self::problem('The download was empty or larger than expected.', 'err_download');
            }

            return ['ok' => true, 'bytes' => $bytes, 'error' => null, 'error_key' => null];
        }

        @unlink($targetFile);

        return self::problem('The download redirected too many times.', 'err_redirect');
    }

    /**
     * @return array{ok: bool, status: int, body?: string, location?: string, error?: string, error_key?: string}
     */
    private function get(string $url, bool $withToken): array
    {
        if (!function_exists('curl_init')) {
            return self::problem('PHP curl is required to reach GitHub.', 'err_curl');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers($withToken),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Typemill-GitUpdate/1.0',
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            return self::problem('GitHub could not be reached. ' . $error, 'err_offline');
        }

        if ($status === 401 || $status === 403 || $status === 404) {
            return self::problem(self::githubMessage((string) $body, $status), 'err_github');
        }

        if ($status !== 200) {
            return self::problem('GitHub answered with HTTP ' . $status . '.', 'err_github');
        }

        return ['ok' => true, 'status' => $status, 'body' => (string) $body, 'error' => null, 'error_key' => null];
    }

    /**
     * @param list<string> $headers
     * @return array{ok: bool, status: int, location?: string, error?: string, error_key?: string}
     */
    private function transfer(string $url, array $headers, string $targetFile): array
    {
        if (!function_exists('curl_init')) {
            return self::problem('PHP curl is required to reach GitHub.', 'err_curl') + ['status' => 0];
        }

        $handle = @fopen($targetFile, 'wb');
        if ($handle === false) {
            return self::problem('Could not store the download.', 'err_download') + ['status' => 0];
        }

        $location = '';
        $written = 0;
        $aborted = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Typemill-GitUpdate/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, $header) use (&$location) {
                if (preg_match('/^Location:\s*(\S+)/i', $header, $match) === 1) {
                    $location = trim($match[1]);
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use ($handle, &$written, &$aborted) {
                $written += strlen($chunk);
                if ($written > self::MAX_DOWNLOAD_BYTES) {
                    $aborted = true;

                    return 0;
                }

                return fwrite($handle, $chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($aborted) {
            return self::problem('The download was larger than expected.', 'err_download') + ['status' => $status];
        }

        if ($ok === false && $status < 300) {
            return self::problem('Download failed: ' . $error, 'err_download') + ['status' => $status];
        }

        if ($status >= 300 && $status < 400) {
            return ['ok' => false, 'status' => $status, 'location' => $location, 'error' => null, 'error_key' => null];
        }

        if ($status !== 200) {
            return self::problem('Download failed with HTTP ' . $status . '.', 'err_download') + ['status' => $status];
        }

        return ['ok' => true, 'status' => 200, 'error' => null, 'error_key' => null];
    }

    /**
     * @return list<string>
     */
    private function headers(bool $withToken): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Typemill-GitUpdate/1.0',
        ];
        if ($withToken && $this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }

    private static function githubMessage(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
        if ($message === '') {
            return 'GitHub answered with HTTP ' . $status . '.';
        }

        return 'GitHub answered with HTTP ' . $status . ': ' . $message;
    }

    /**
     * @return array{ok: false, error: string, error_key: string}
     */
    private static function problem(string $error, string $key): array
    {
        return ['ok' => false, 'error' => $error, 'error_key' => 'gitupdate.' . $key];
    }
}
