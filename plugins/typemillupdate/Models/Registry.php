<?php

namespace Plugins\typemillupdate\Models;

use ZipArchive;

/**
 * Talks to plugins.typemill.net: which directory plugins are current, and
 * where the matching zip lives.
 *
 * The directory is the only source. GitHub tags are not used: several listed
 * plugins have no public repo, and the zip the directory serves is the file
 * an admin would otherwise fetch by hand.
 */
class Registry
{
    public const CATALOG_URL = 'https://plugins.typemill.net/api/v1/getplugins';
    public const DOWNLOAD_TEMPLATE = 'https://plugins.typemill.net/download?plugins=%s';

    /** Guards against decompression bombs. Plugin zips are far smaller than a core. */
    public const MAX_ENTRIES = 4000;
    public const MAX_UNCOMPRESSED_BYTES = 67108864; // 64 MB

    private Environment $environment;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public static function downloadUrl(string $slug): string
    {
        return sprintf(self::DOWNLOAD_TEMPLATE, rawurlencode($slug));
    }

    /**
     * Ask the directory for the named plugins.
     *
     * The response is a map of slug => details. A slug that is not listed is
     * simply absent: the directory does not 404 for unknown names.
     *
     * @param array<int, string> $slugs
     * @return array{plugins: array<string, array<string, mixed>>, error: ?string, error_key: ?string, error_params: array}
     */
    public function catalog(array $slugs): array
    {
        $wanted = [];
        foreach ($slugs as $slug) {
            if (is_string($slug) && Environment::isPluginSlug($slug)) {
                $wanted[] = $slug;
            }
        }

        if ($wanted === []) {
            return ['plugins' => [], 'error' => null, 'error_key' => null, 'error_params' => []];
        }

        $url = self::CATALOG_URL . '?plugins=' . rawurlencode(implode(',', $wanted) . ',');
        $result = $this->httpGet($url, $this->authorizationHeader());

        if ($result['error'] !== null) {
            return ['plugins' => []] + self::problem($result['error'], 'err_plugin_check_offline');
        }

        if ($result['status'] !== 200) {
            return ['plugins' => []] + self::problem(
                'plugins.typemill.net answered with HTTP ' . $result['status'] . '.',
                'err_plugin_check_http',
                ['status' => $result['status']]
            );
        }

        $decoded = json_decode((string) $result['body'], true);
        $raw = $decoded['plugins'] ?? null;
        if (!is_array($raw)) {
            return ['plugins' => []] + self::problem(
                'plugins.typemill.net returned an unexpected catalog.',
                'err_plugin_check_format'
            );
        }

        $plugins = [];
        foreach ($raw as $slug => $details) {
            if (!is_string($slug) || !Environment::isPluginSlug($slug) || !is_array($details)) {
                continue;
            }

            $version = $details['version'] ?? null;
            if (!is_string($version) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
                continue;
            }

            $plugins[$slug] = [
                'name' => is_string($details['name'] ?? null) ? $details['name'] : $slug,
                'version' => $version,
                'license' => is_string($details['license'] ?? null) ? $details['license'] : '',
                'homepage' => is_string($details['homepage'] ?? null) ? $details['homepage'] : '',
            ];
        }

        return ['plugins' => $plugins, 'error' => null, 'error_key' => null, 'error_params' => []];
    }

    /**
     * Installed plugins that the directory also lists, with whether a newer
     * zip is available.
     *
     * Plugins this site has that the directory does not know are omitted:
     * there is nothing here that can update them.
     *
     * @param array<string, array{slug: string, name: string, version: ?string}> $installed
     * @param array<string, array<string, mixed>> $catalog
     * @return array<int, array{slug: string, name: string, installed: ?string, latest: string, update_available: bool, license: string, homepage: string}>
     */
    public static function present(array $installed, array $catalog): array
    {
        $out = [];

        foreach ($installed as $slug => $local) {
            if ($slug === 'typemillupdate' || !isset($catalog[$slug])) {
                continue;
            }

            $remote = $catalog[$slug];
            $latest = (string) $remote['version'];
            $have = $local['version'] ?? null;
            $newer = !is_string($have) || $have === '' || version_compare($latest, $have, '>');

            $out[] = [
                'slug' => $slug,
                'name' => (string) ($remote['name'] ?: $local['name']),
                'installed' => $have,
                'latest' => $latest,
                'update_available' => $newer,
                'license' => (string) ($remote['license'] ?? ''),
                'homepage' => (string) ($remote['homepage'] ?? ''),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Validate a directory zip without extracting anything.
     *
     * Rejects absolute paths and traversal (Zip Slip), caps entry count and
     * uncompressed size, insists on {slug}/{slug}.php and {slug}/{slug}.yaml,
     * and refuses anything that is not that plugin folder. A single wrapping
     * directory is accepted, the way a hand-made zip often looks.
     *
     * @return array{ok: bool, error?: string, error_key?: string, error_params?: array, version?: string, prefix?: string, plugin_bytes?: int, plugin_entries?: int}
     */
    public function inspectArchive(string $zipPath, string $slug): array
    {
        if (!Environment::isPluginSlug($slug)) {
            return self::problem('That is not a valid plugin name.', 'err_plugin_slug');
        }

        if (!class_exists('ZipArchive')) {
            return self::problem('The PHP zip extension is missing.', 'err_zip_missing');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return self::problem(
                'The downloaded file is not a readable ZIP archive.',
                'err_archive_unreadable'
            );
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $entries = $zip->numFiles;
            $zip->close();

            return self::problem(
                'The archive contains more entries than expected (' . $entries . ').',
                'err_archive_too_many',
                ['entries' => $entries]
            );
        }

        $prefix = self::findPluginPrefix($zip, $slug);
        if ($prefix === null) {
            $zip->close();

            return self::problem(
                'The archive does not contain the plugin ' . $slug . '.',
                'err_plugin_archive_no_plugin',
                ['slug' => $slug]
            );
        }

        $pluginEntries = 0;
        $pluginBytes = 0;
        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();

                return self::problem(
                    'The archive contains an unreadable entry.',
                    'err_archive_unreadable_entry'
                );
            }

            $name = (string) $stat['name'];

            if (!Release::isSafeEntryName($name)) {
                $zip->close();

                return self::problem(
                    'The archive contains an unsafe path: ' . $name,
                    'err_archive_unsafe_path',
                    ['path' => $name]
                );
            }

            if (!self::belongsToPlugin($name, $slug, $prefix)) {
                $zip->close();

                return self::problem(
                    'The archive contains files outside the plugin ' . $slug . '.',
                    'err_plugin_archive_wrong_slug',
                    ['slug' => $slug]
                );
            }

            $totalBytes += (int) $stat['size'];
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();

                return self::problem(
                    'The archive expands to more than ' . Environment::formatBytes(self::MAX_UNCOMPRESSED_BYTES) . '.',
                    'err_archive_too_big',
                    ['limit' => Environment::formatBytes(self::MAX_UNCOMPRESSED_BYTES)]
                );
            }

            if (self::isPluginEntry($name, $slug, $prefix)) {
                $pluginEntries++;
                $pluginBytes += (int) $stat['size'];
            }
        }

        foreach ([$slug . '/' . $slug . '.yaml', $slug . '/' . $slug . '.php'] as $required) {
            if ($zip->locateName($prefix . $required) === false) {
                $zip->close();

                return self::problem(
                    'The archive is missing ' . $required . ', so it is not a complete plugin.',
                    'err_plugin_archive_missing_entry',
                    ['entry' => $required]
                );
            }
        }

        $yaml = $zip->getFromName($prefix . $slug . '/' . $slug . '.yaml');
        $version = is_string($yaml) ? Environment::parseVersionFromYaml($yaml) : null;
        $zip->close();

        if ($version === null) {
            return self::problem(
                'Could not read the version from the archive.',
                'err_archive_no_version'
            );
        }

        return [
            'ok' => true,
            'error' => null,
            'error_key' => null,
            'error_params' => [],
            'version' => $version,
            'prefix' => $prefix,
            'plugin_entries' => $pluginEntries,
            'plugin_bytes' => $pluginBytes,
        ];
    }

    /**
     * True for the plugin's own files. The wrapping directory, if any, and the
     * plugin folder itself are allowed as zip entries but are not counted as
     * payload.
     */
    public static function belongsToPlugin(string $name, string $slug, string $prefix = ''): bool
    {
        if ($prefix !== '') {
            $trimmed = rtrim($prefix, '/');
            if ($name === $trimmed || $name === $prefix) {
                return true;
            }
            if (!str_starts_with($name, $prefix)) {
                return false;
            }
            $name = substr($name, strlen($prefix));
        }

        if ($name === '' || $name === $slug || $name === $slug . '/') {
            return true;
        }

        return str_starts_with($name, $slug . '/');
    }

    public static function isPluginEntry(string $name, string $slug, string $prefix = ''): bool
    {
        return str_starts_with($name, $prefix . $slug . '/') && $name !== $prefix . $slug . '/';
    }

    /**
     * Locate the plugin folder inside an archive.
     *
     * Directory zips have `{slug}/` at the root. A hand-made zip may wrap
     * that in one directory. Anything else is rejected rather than guessed.
     *
     * @return string|null prefix ('' or 'wrapper/'), or null when the plugin is absent
     */
    public static function findPluginPrefix(ZipArchive $zip, string $slug): ?string
    {
        $yaml = $slug . '/' . $slug . '.yaml';

        if ($zip->locateName($yaml) !== false) {
            return '';
        }

        $topLevel = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || !Release::isSafeEntryName($name)) {
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

        return $zip->locateName($prefix . $yaml) !== false ? $prefix : null;
    }

    private static function problem(string $message, string $key, array $params = []): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'error_key' => 'typemillupdate.' . $key,
            'error_params' => $params,
        ];
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
                CURLOPT_USERAGENT => 'Typemill-Update/1.0',
            ]);

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false) {
                return ['status' => 0, 'body' => null, 'error' => 'Could not reach plugins.typemill.net: ' . $error];
            }

            return ['status' => $status, 'body' => $body, 'error' => null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'user_agent' => 'Typemill-Update/1.0',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['status' => 0, 'body' => null, 'error' => 'Could not reach plugins.typemill.net. Check outbound network access.'];
        }

        return ['status' => self::statusFromHeaders($http_response_header ?? []), 'body' => $body, 'error' => null];
    }

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
