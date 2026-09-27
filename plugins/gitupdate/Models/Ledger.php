<?php

namespace Plugins\gitupdate\Models;

/**
 * The commit each installed folder was last replaced with.
 *
 * A missing entry means this server has never been synced, so an update is
 * offered. The file lives under data/, which the download route does not serve.
 */
final class Ledger
{
    private string $file;

    public function __construct(string $root)
    {
        $dir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'gitupdate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->file = $dir . DIRECTORY_SEPARATOR . 'applied.json';
    }

    public function sha(string $kind, string $slug): ?string
    {
        return $this->entry($kind, $slug)['sha'];
    }

    /**
     * @return array{sha: ?string, date: ?string}
     */
    public function entry(string $kind, string $slug): array
    {
        $row = $this->read()[$kind][$slug] ?? null;
        if (!is_array($row)) {
            return ['sha' => null, 'date' => null];
        }

        return [
            'sha' => Reference::sha($row['sha'] ?? null),
            'date' => Reference::commitDate($row['date'] ?? null),
        ];
    }

    public function remember(string $kind, string $slug, string $sha, ?string $date = null): bool
    {
        $sha = Reference::sha($sha);
        if ($sha === null || Reference::kind($kind) === null || !Reference::isSlug($slug)) {
            return false;
        }

        $data = $this->read();
        $data[$kind][$slug] = [
            'sha' => $sha,
            'date' => Reference::commitDate($date),
            'pinned' => $data[$kind][$slug]['pinned'] ?? false,
        ];

        return $this->write($data);
    }

    public function pinned(string $kind, string $slug): bool
    {
        return !empty($this->read()[$kind][$slug]['pinned']);
    }

    public function pin(string $kind, string $slug, bool $pinned): bool
    {
        $data = $this->read();
        if (!isset($data[$kind][$slug])) {
            return false;
        }
        $data[$kind][$slug]['pinned'] = $pinned;
        return $this->write($data);
    }

    public function forget(string $kind, string $slug): bool
    {
        $data = $this->read();
        unset($data[$kind][$slug]);
        return $this->write($data);
    }

    private function write(array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $temporary = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * @return array{plugin: array<string, array{sha: string, date: ?string}>, theme: array<string, array{sha: string, date: ?string}>}
     */
    private function read(): array
    {
        $empty = ['plugin' => [], 'theme' => []];
        if (!is_file($this->file)) {
            return $empty;
        }

        $decoded = json_decode((string) @file_get_contents($this->file), true);
        if (!is_array($decoded)) {
            return $empty;
        }

        foreach (['plugin', 'theme'] as $kind) {
            $rows = $decoded[$kind] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $slug => $row) {
                if (!is_string($slug) || !Reference::isSlug($slug)) {
                    continue;
                }
                // Older ledgers stored the sha as a string and had no date.
                $sha = is_string($row) ? $row : (is_array($row) ? ($row['sha'] ?? null) : null);
                $sha = Reference::sha(is_string($sha) ? $sha : null);
                if ($sha === null) {
                    continue;
                }
                $date = is_array($row) ? Reference::commitDate($row['date'] ?? null) : null;
                $empty[$kind][$slug] = ['sha' => $sha, 'date' => $date, 'pinned' => is_array($row) && !empty($row['pinned'])];
            }
        }

        return $empty;
    }
}
