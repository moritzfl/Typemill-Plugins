<?php

namespace Plugins\gitupdate\Models;

/**
 * The repository an update is allowed to talk to.
 *
 * Rolling releases: the release is the latest commit on the configured branch,
 * not a version tag. The address is checked before it is put into a URL.
 */
final class Reference
{
    public const DEFAULT_REPOSITORY = 'moritzfl/Typemill-Plugins';

    public const DEFAULT_BRANCH = 'main';

    public const DEFAULT_API = 'https://api.github.com';

    public static function repository(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = self::DEFAULT_REPOSITORY;
        }

        if (preg_match('#^https://github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $value, $match) === 1) {
            $value = $match[1] . '/' . $match[2];
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,80}\/[A-Za-z0-9][A-Za-z0-9_.-]{0,100}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function branch(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = self::DEFAULT_BRANCH;
        }

        if (str_contains($value, '..') || str_contains($value, '\\') || str_contains($value, '@')) {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,100}$#', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function sha(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{40}$/', $value) === 1 ? $value : null;
    }

    /**
     * A commit timestamp as GitHub returns it. Anything else is not shown as a date.
     */
    public static function commitDate(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1
            ? $value
            : null;
    }

    public static function apiBase(?string $value): ?string
    {
        $value = rtrim(trim((string) $value), '/');
        if ($value === '') {
            $value = self::DEFAULT_API;
        }

        if (preg_match('#^https://[A-Za-z0-9][A-Za-z0-9.-]*(?::\d{1,5})?$#', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * A token is optional. Anything that could break the request header is refused.
     */
    public static function token(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._-]{8,255}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function isSlug(string $slug): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $slug) === 1;
    }

    public static function kind(?string $kind): ?string
    {
        return $kind === 'plugin' || $kind === 'theme' ? $kind : null;
    }

    public static function folder(string $kind): string
    {
        return $kind === 'theme' ? 'themes' : 'plugins';
    }

    public static function commitUrl(string $api, string $repository, string $branch): string
    {
        return $api . '/repos/' . $repository . '/commits/' . rawurlencode($branch);
    }

    public static function treeUrl(string $api, string $repository, string $sha): string
    {
        return $api . '/repos/' . $repository . '/git/trees/' . $sha . '?recursive=1';
    }

    public static function zipballUrl(string $api, string $repository, string $sha): string
    {
        return $api . '/repos/' . $repository . '/zipball/' . $sha;
    }

    /**
     * GitHub's zipball answers with a redirect to codeload.github.com. Anything
     * else is not the archive we asked for.
     */
    public static function redirectAllowed(string $apiBase, string $nextUrl): bool
    {
        $next = parse_url($nextUrl);
        if (!is_array($next) || ($next['scheme'] ?? '') !== 'https' || !isset($next['host'])) {
            return false;
        }

        $apiHost = strtolower((string) parse_url($apiBase, PHP_URL_HOST));
        $host = strtolower((string) $next['host']);
        if ($host === $apiHost) {
            return true;
        }

        return $apiHost === 'api.github.com' && $host === 'codeload.github.com';
    }
}
