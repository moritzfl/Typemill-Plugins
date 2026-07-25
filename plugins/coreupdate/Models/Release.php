<?php

namespace Plugins\coreupdate\Models;

use ZipArchive;

/**
 * Talks to typemill.net: which version is current, and where the matching
 * release archive lives.
 *
 * The GitHub tag is deliberately not used as a source. It excludes
 * system/vendor, so it would require Composer on the server. The archive
 * published on typemill.net bundles the vendor tree and is therefore the only
 * artifact that can be installed without shell access.
 */
class Release
{
    public const VERSION_CHECK_URL = 'https://typemill.net/api/v1/checkversion';
    public const DOWNLOAD_TEMPLATE = 'https://typemill.net/media/files/typemill-%s.zip';

    /** Guards against decompression bombs. */
    public const MAX_ENTRIES = 8000;
    public const MAX_UNCOMPRESSED_BYTES = 268435456; // 256 MB

    /** Cap on the transfer itself. The published release is around 2.5 MB. */
    public const MAX_DOWNLOAD_BYTES = 134217728; // 128 MB

    /** An archive without these is not a usable Typemill release. */
    public const REQUIRED_ENTRIES = [
        'system/typemill/settings/defaults.yaml',
        'system/vendor/autoload.php',
    ];

    private Environment $environment;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    /**
     * Ask typemill.net for the current release.
     *
     * The endpoint authenticates with a digest of the installation's public
     * key and answers with a bare version string - no download URL, no
     * checksum, no minimum PHP version.
     */
    public function latestVersion(): array
    {
        $result = $this->httpGet(self::VERSION_CHECK_URL, $this->authorizationHeader());

        if ($result['error'] !== null) {
            return ['version' => null, 'error' => $result['error']];
        }

        if ($result['status'] !== 200) {
            return ['version' => null, 'error' => 'typemill.net answered with HTTP ' . $result['status'] . '.'];
        }

        $decoded = json_decode((string) $result['body'], true);
        $version = $decoded['system']['typemill'] ?? null;

        if (!is_string($version) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
            return ['version' => null, 'error' => 'typemill.net returned an unexpected version format.'];
        }

        return ['version' => $version, 'error' => null];
    }

    /**
     * typemill.net publishes each release as typemill-<version without dots>.zip
     * and keeps only the current one, so older versions cannot be fetched.
     */
    public static function downloadUrl(string $version): string
    {
        return sprintf(self::DOWNLOAD_TEMPLATE, str_replace('.', '', $version));
    }

    private function authorizationHeader(): array
    {
        $keyFile = $this->environment->root() . '/settings/public_key.pem';

        if (!is_readable($keyFile)) {
            return [];
        }

        $key = (string) file_get_contents($keyFile);
        if ($key === '') {
            return [];
        }

        return ['Authorization: ' . hash('sha256', substr($key, 0, 50))];
    }

    public function download(string $url, string $targetFile): array
    {
        if (function_exists('curl_init')) {
            return $this->downloadWithCurl($url, $targetFile);
        }

        return $this->downloadWithStream($url, $targetFile);
    }

    private function downloadWithCurl(string $url, string $targetFile): array
    {
        $handle = @fopen($targetFile, 'w');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Could not open ' . $targetFile . ' for writing.', 'bytes' => 0];
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FAILONERROR => false,
            // Refuse an oversized response before it can fill the disk; the
            // archive limits are only checkable once the file is complete.
            CURLOPT_MAXFILESIZE => self::MAX_DOWNLOAD_BYTES,
            CURLOPT_USERAGENT => 'Typemill-CoreUpdate/1.0',
        ]);

        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($success === false) {
            @unlink($targetFile);

            return ['ok' => false, 'error' => 'Download failed: ' . $error, 'bytes' => 0];
        }

        if ($status !== 200) {
            @unlink($targetFile);

            return ['ok' => false, 'error' => 'Download failed with HTTP ' . $status . '.', 'bytes' => 0];
        }

        return ['ok' => true, 'error' => null, 'bytes' => (int) @filesize($targetFile)];
    }

    private function downloadWithStream(string $url, string $targetFile): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 300,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'Typemill-CoreUpdate/1.0',
                'ignore_errors' => true,
            ],
        ]);

        $source = @fopen($url, 'r', false, $context);
        if ($source === false) {
            return ['ok' => false, 'error' => 'Could not open the download URL. Check outbound network access.', 'bytes' => 0];
        }

        // ignore_errors keeps the stream open for 4xx and 5xx too, so the
        // status has to be inspected explicitly. Without this an error page
        // would be written out and then handed on as if it were the archive.
        $status = self::statusFromHeaders($http_response_header ?? []);
        if ($status !== 200) {
            fclose($source);

            return ['ok' => false, 'error' => 'Download failed with HTTP ' . $status . '.', 'bytes' => 0];
        }

        $target = @fopen($targetFile, 'w');
        if ($target === false) {
            fclose($source);

            return ['ok' => false, 'error' => 'Could not open ' . $targetFile . ' for writing.', 'bytes' => 0];
        }

        $bytes = (int) stream_copy_to_stream($source, $target, self::MAX_DOWNLOAD_BYTES);
        fclose($source);
        fclose($target);

        if ($bytes <= 0) {
            @unlink($targetFile);

            return ['ok' => false, 'error' => 'The download was empty.', 'bytes' => 0];
        }

        return ['ok' => true, 'error' => null, 'bytes' => $bytes];
    }

    /**
     * Validate a downloaded archive without extracting anything.
     *
     * Rejects absolute paths and traversal segments (Zip Slip), caps the entry
     * count and the total uncompressed size, insists on the files that identify
     * a Typemill release, and reads the shipped version and PHP floor straight
     * out of the archive.
     */
    public function inspectArchive(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'The PHP zip extension is missing.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'The downloaded file is not a readable ZIP archive.'];
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            return ['ok' => false, 'error' => 'The archive contains more entries than expected (' . $zip->numFiles . ').'];
        }

        $prefix = self::findSystemPrefix($zip);
        if ($prefix === null) {
            $zip->close();

            return ['ok' => false, 'error' => 'The archive does not contain a Typemill core. Expected a system/ directory with typemill/ and vendor/ inside it.'];
        }

        $systemEntries = 0;
        $systemBytes = 0;
        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();

                return ['ok' => false, 'error' => 'The archive contains an unreadable entry.'];
            }

            $name = (string) $stat['name'];

            if (!self::isSafeEntryName($name)) {
                $zip->close();

                return ['ok' => false, 'error' => 'The archive contains an unsafe path: ' . $name];
            }

            $totalBytes += (int) $stat['size'];
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();

                return ['ok' => false, 'error' => 'The archive expands to more than ' . Environment::formatBytes(self::MAX_UNCOMPRESSED_BYTES) . '.'];
            }

            if (self::isSystemEntry($name, $prefix)) {
                $systemEntries++;
                $systemBytes += (int) $stat['size'];
            }
        }

        foreach (self::REQUIRED_ENTRIES as $required) {
            if ($zip->locateName($prefix . $required) === false) {
                $zip->close();

                return ['ok' => false, 'error' => 'The archive is missing ' . $required . ', so it is not a complete Typemill core. Archives from GitHub do not include system/vendor.'];
            }
        }

        $defaults = $zip->getFromName($prefix . 'system/typemill/settings/defaults.yaml');
        $version = is_string($defaults) ? Environment::parseVersionFromYaml($defaults) : null;

        $platformCheck = $zip->getFromName($prefix . 'system/vendor/composer/platform_check.php');
        $phpFloor = is_string($platformCheck) ? Environment::parsePhpFloor($platformCheck) : null;

        $zip->close();

        if ($version === null) {
            return ['ok' => false, 'error' => 'Could not read the version from the archive.'];
        }

        return [
            'ok' => true,
            'error' => null,
            'version' => $version,
            'prefix' => $prefix,
            'php_floor' => $phpFloor,
            'system_entries' => $systemEntries,
            'system_bytes' => $systemBytes,
            'total_bytes' => $totalBytes,
        ];
    }

    public static function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        // Absolute paths, Windows drive letters and UNC paths.
        if (str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('#^[a-zA-Z]:#', $name) === 1) {
            return false;
        }

        $normalised = str_replace('\\', '/', $name);
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function isSystemEntry(string $name, string $prefix = ''): bool
    {
        return str_starts_with($name, $prefix . 'system/') && $name !== $prefix . 'system/';
    }

    /**
     * Locate the core inside an archive.
     *
     * The archive published on typemill.net has `system/` at the root. An
     * archive somebody built themselves may wrap everything in a single
     * directory, so that case is accepted too and the wrapper is reported as a
     * prefix. Anything else is rejected rather than guessed at.
     *
     * Returns the prefix ('' or 'wrapper/'), or null when no core is present.
     */
    public static function findSystemPrefix(ZipArchive $zip): ?string
    {
        if ($zip->locateName('system/typemill/settings/defaults.yaml') !== false) {
            return '';
        }

        $topLevel = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || !self::isSafeEntryName($name)) {
                continue;
            }

            $slash = strpos($name, '/');
            $topLevel[$slash === false ? $name : substr($name, 0, $slash + 1)] = true;

            if (count($topLevel) > 1) {
                return null;
            }
        }

        if (count($topLevel) !== 1) {
            return null;
        }

        $prefix = (string) array_key_first($topLevel);
        if (!str_ends_with($prefix, '/')) {
            return null;
        }

        return $zip->locateName($prefix . 'system/typemill/settings/defaults.yaml') !== false ? $prefix : null;
    }

    private function httpGet(string $url, array $headers = []): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'Typemill-CoreUpdate/1.0',
            ]);

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false) {
                return ['status' => 0, 'body' => null, 'error' => 'Could not reach typemill.net: ' . $error];
            }

            return ['status' => $status, 'body' => $body, 'error' => null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'user_agent' => 'Typemill-CoreUpdate/1.0',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['status' => 0, 'body' => null, 'error' => 'Could not reach typemill.net. Check outbound network access.'];
        }

        return ['status' => self::statusFromHeaders($http_response_header ?? []), 'body' => $body, 'error' => null];
    }

    /**
     * Status code from a stream wrapper's response headers. Redirects leave one
     * status line per hop, so the last one is the one that counts.
     */
    private static function statusFromHeaders(array $headers): int
    {
        $status = 0;

        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
