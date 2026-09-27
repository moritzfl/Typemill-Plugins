<?php

namespace Plugins\gitupdate\Models;

/**
 * Which plugins and themes this server has, and which of those the repository
 * also contains.
 *
 * A server is expected to hold a subset. Bulk updates use only that subset;
 * missing packages appear separately for explicit installation.
 */
final class Catalog
{
    /**
     * @param list<string> $paths Paths from a git tree, using forward slashes.
     * @return list<string>
     */
    public static function slugsInTree(array $paths, string $kind): array
    {
        $folder = Reference::folder($kind);
        $prefix = $folder . '/';
        $files = [];

        foreach ($paths as $path) {
            $path = str_replace('\\', '/', (string) $path);
            if (!str_starts_with($path, $prefix)) {
                continue;
            }

            $rest = substr($path, strlen($prefix));
            $slug = explode('/', $rest, 2)[0];
            if (!Reference::isSlug($slug)) {
                continue;
            }

            $files[$slug][$path] = true;
        }

        $ready = [];
        foreach ($files as $slug => $present) {
            if ($kind === 'plugin') {
                $php = $prefix . $slug . '/' . $slug . '.php';
                $yaml = $prefix . $slug . '/' . $slug . '.yaml';
                if (isset($present[$php], $present[$yaml])) {
                    $ready[] = $slug;
                }
                continue;
            }

            if (isset($present[$prefix . $slug . '/' . $slug . '.yaml'])) {
                $ready[] = $slug;
            }
        }

        sort($ready);

        return $ready;
    }

    /**
     * @param array<string, true> $tree index of path => true
     * @return array{plugins: list<string>, themes: list<string>, truncated: bool}
     */
    public static function fromTreePayload(array $payload): array
    {
        $paths = [];
        foreach ($payload['tree'] ?? [] as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? '') === 'tree') {
                continue;
            }
            if (isset($entry['path']) && is_string($entry['path'])) {
                $paths[] = $entry['path'];
            }
        }

        return [
            'plugins' => self::slugsInTree($paths, 'plugin'),
            'themes' => self::slugsInTree($paths, 'theme'),
            'truncated' => !empty($payload['truncated']),
        ];
    }

    /**
     * @return array<string, array{slug: string, name: string}>
     */
    public static function installed(string $root, string $kind): array
    {
        $directory = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Reference::folder($kind);
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (!is_string($entry) || !Reference::isSlug($entry)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_dir($path) || !self::looksInstalled($path, $kind, $entry)) {
                continue;
            }

            $found[$entry] = [
                'slug' => $entry,
                'name' => self::displayName($path, $kind, $entry),
            ];
        }

        ksort($found);

        return $found;
    }

    public static function looksInstalled(string $path, string $kind, string $slug): bool
    {
        $yaml = $path . DIRECTORY_SEPARATOR . $slug . '.yaml';
        if (!is_file($yaml)) {
            return false;
        }

        if ($kind === 'plugin' && !is_file($path . DIRECTORY_SEPARATOR . $slug . '.php')) {
            return false;
        }

        return true;
    }

    public static function displayName(string $path, string $kind, string $slug): string
    {
        $yaml = @file_get_contents($path . DIRECTORY_SEPARATOR . $slug . '.yaml');
        if (is_string($yaml) && preg_match('/^name:\s*[\'"]?(.+?)[\'"]?\s*$/m', $yaml, $match) === 1) {
            $name = trim($match[1]);
            if ($name !== '') {
                return $name;
            }
        }

        return $slug;
    }
}
