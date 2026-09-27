<?php

namespace Plugins\siteblocks\Models;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/** Flat-file source of truth. One lock covers revisions, placement and deletion. */
final class Library
{
    public function __construct(private string $root, private string $contentRoot) {}

    public static function validId(string $id): bool { return (bool) preg_match('/^sb_[a-f0-9]{24}$/D', $id); }

    public static function validateMarkdown(string $markdown): void
    {
        if (strlen($markdown) > 200000) { throw new InvalidArgumentException('SB_TOO_LARGE'); }
        if (preg_match('/\[:\s*siteblock-ref\b/i', $markdown)) { throw new InvalidArgumentException('SB_NO_NESTING'); }
    }

    public function get(string $id): array
    {
        if (!self::validId($id)) { throw new InvalidArgumentException('SB_NOT_FOUND'); }
        $row = $this->read('blocks/' . $id . '.yaml');
        if (!$row) { throw new InvalidArgumentException('SB_NOT_FOUND'); }
        return $row;
    }

    public function all(string $scope): array
    {
        $rows = [];
        foreach (glob($this->root . '/blocks/sb_*.yaml') ?: [] as $file) {
            $row = $this->read('blocks/' . basename($file));
            if ($row && $row['scope'] === $scope) { $rows[] = $row; }
        }
        usort($rows, fn ($a, $b) => strnatcasecmp($a['title'], $b['title']));
        return $rows;
    }

    public function save(string $id, string $scope, string $revision, string $title, string $markdown): array
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 160) { throw new InvalidArgumentException('SB_NAME_REQUIRED'); }
        self::validateMarkdown($markdown);
        return $this->locked(function () use ($id, $scope, $revision, $title, $markdown) {
            $row = $id === '' ? ['version' => 1, 'id' => 'sb_' . bin2hex(random_bytes(12)), 'scope' => $scope,
                'published' => null, 'history' => [], 'archived' => false] : $this->current($id, $scope, $revision);
            $row['title'] = $title;
            $row['draft'] = $markdown;
            return $this->writeRow($row);
        });
    }

    public function action(string $id, string $scope, string $revision, string $action, string $history = ''): ?array
    {
        return $this->locked(function () use ($id, $scope, $revision, $action, $history) {
            $row = $this->current($id, $scope, $revision);
            if (in_array($action, ['delete', 'unpublish'], true) && $this->usage($id, $scope)) {
                throw new InvalidArgumentException('SB_IN_USE');
            }
            switch ($action) {
                case 'publish':
                    if ($row['archived'] || trim($row['draft']) === '') { throw new InvalidArgumentException('SB_CANNOT_PUBLISH'); }
                    if ($row['published'] !== null) {
                        $row['history'][] = ['id' => $row['revision'], 'at' => $row['publishedAt'], 'markdown' => $row['published']];
                    }
                    $row['history'] = array_slice($row['history'], -30);
                    $row['published'] = $row['draft'];
                    $row['publishedAt'] = gmdate('c');
                    break;
                case 'unpublish':
                    if ($row['published'] !== null) {
                        $row['history'][] = ['id' => $row['revision'], 'at' => $row['publishedAt'], 'markdown' => $row['published']];
                        $row['history'] = array_slice($row['history'], -30);
                    }
                    $row['published'] = null;
                    break;
                case 'archive': $row['archived'] = true; break;
                case 'unarchive': $row['archived'] = false; break;
                case 'discard': $row['draft'] = $row['published'] ?? ''; break;
                case 'restore':
                    $matches = array_values(array_filter($row['history'], fn ($entry) => $entry['id'] === $history));
                    if (!$matches) { throw new InvalidArgumentException('SB_NOT_FOUND'); }
                    $row['draft'] = $matches[0]['markdown'];
                    break;
                case 'delete':
                    if (!unlink($this->root . '/blocks/' . $id . '.yaml')) { throw new RuntimeException('SB_STORAGE_ERROR'); }
                    return null;
                default: throw new InvalidArgumentException('SB_BAD_ACTION');
            }
            return $this->writeRow($row);
        });
    }

    public function placement(string $scope): array
    {
        return $this->read('placements/' . hash('sha256', $scope) . '.yaml') ?: ['scope' => $scope, 'footer' => '', 'revision' => ''];
    }

    public function place(string $scope, string $id, string $revision): array
    {
        return $this->locked(function () use ($scope, $id, $revision) {
            if ($this->placement($scope)['revision'] !== $revision) { throw new RuntimeException('SB_CONFLICT', 409); }
            if ($id !== '') {
                $row = $this->get($id);
                if ($row['scope'] !== $scope || $row['published'] === null || $row['archived']) { throw new InvalidArgumentException('SB_NOT_AVAILABLE'); }
            }
            $placement = ['scope' => $scope, 'footer' => $id, 'revision' => bin2hex(random_bytes(12))];
            $this->write('placements/' . hash('sha256', $scope) . '.yaml', $placement);
            return $placement;
        });
    }

    /** Derived from actual page sources, including native JSON-array drafts. */
    public function usage(string $id, string $scope): array
    {
        $uses = [];
        if ($this->placement($scope)['footer'] === $id) { $uses[] = ['kind' => 'footer', 'path' => $scope ?: '/']; }
        if (!is_dir($this->contentRoot)) { return $uses; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->contentRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || !in_array($file->getExtension(), ['md', 'txt'], true)) { continue; }
            $relative = substr($file->getPathname(), strlen(rtrim($this->contentRoot, '/')) + 1);
            $project = str_starts_with($relative, '_') ? '/' . explode('/', substr($relative, 1))[0] : '';
            if ($project !== $scope) { continue; }
            $text = file_get_contents($file->getPathname());
            $draft = $file->getExtension() === 'txt' ? json_decode($text, true) : null;
            if (is_array($draft)) { $text = implode("\n\n", array_filter($draft, 'is_string')); }
            if (!preg_match('/\[:siteblock-ref\s+[^\]\r\n]*\bid\s*=\s*["\x27]?' . preg_quote($id, '/') . '(?=["\x27\s:])/', $text)) { continue; }
            $path = preg_replace('/\.(md|txt)$/', '', $relative);
            $parts = array_map(fn ($part) => preg_replace('/^(?:[0-9]+-|_)/', '', $part), explode('/', $path));
            if (end($parts) === 'index') { array_pop($parts); }
            $uses[] = ['kind' => $file->getExtension() === 'md' ? 'published' : 'draft', 'path' => '/' . implode('/', $parts)];
        }
        return $uses;
    }

    public function export(string $scope): array
    {
        return $this->locked(fn () => ['version' => 1, 'scope' => $scope, 'blocks' => $this->all($scope), 'placement' => $this->placement($scope)]);
    }

    /** Import is additive: never overwrite an existing source or publish a draft. */
    public function import(string $scope, array $bundle): int
    {
        return $this->locked(function () use ($scope, $bundle) {
            if (($bundle['version'] ?? null) !== 1 || ($bundle['scope'] ?? null) !== $scope || !is_array($bundle['blocks'] ?? null)) {
                throw new InvalidArgumentException('SB_BAD_IMPORT');
            }
            $rows = []; $ids = [];
            foreach ($bundle['blocks'] as $row) {
                if (!is_array($row) || !is_string($row['id'] ?? null) || !self::validId($row['id']) || isset($ids[$row['id']]) || ($row['scope'] ?? null) !== $scope
                    || !is_string($row['title'] ?? null) || trim($row['title']) === '' || mb_strlen($row['title']) > 160
                    || !is_string($row['draft'] ?? null) || !array_key_exists('published', $row)
                    || ($row['published'] !== null && !is_string($row['published']))
                    || !is_array($row['history'] ?? [])) { throw new InvalidArgumentException('SB_BAD_IMPORT'); }
                if (is_file($this->root . '/blocks/' . $row['id'] . '.yaml')) { throw new RuntimeException('SB_IMPORT_EXISTS', 409); }
                self::validateMarkdown($row['draft']);
                if ($row['published'] !== null) { self::validateMarkdown($row['published']); }
                $cleanHistory = [];
                foreach (array_slice($row['history'] ?? [], -30) as $entry) {
                    if (!is_array($entry) || !is_string($entry['markdown'] ?? null) || !is_string($entry['id'] ?? null) || !is_string($entry['at'] ?? null)) { throw new InvalidArgumentException('SB_BAD_IMPORT'); }
                    self::validateMarkdown($entry['markdown']); $cleanHistory[] = $entry;
                }
                $row['history'] = $cleanHistory;
                $row['archived'] = (bool) ($row['archived'] ?? false);
                $row['publishedAt'] = (string) ($row['publishedAt'] ?? '');
                $ids[$row['id']] = true; $rows[] = $row;
            }
            $footer = $bundle['placement']['footer'] ?? '';
            if (!is_string($footer)) { throw new InvalidArgumentException('SB_BAD_IMPORT'); }
            if ($footer !== '') {
                $match = array_values(array_filter($rows, fn ($row) => $row['id'] === $footer));
                if (!$match || $match[0]['published'] === null) { throw new InvalidArgumentException('SB_BAD_IMPORT'); }
            }
            if ($footer !== '' && $this->placement($scope)['footer'] !== '') { throw new RuntimeException('SB_IMPORT_EXISTS', 409); }
            foreach ($rows as $row) { $this->writeRow($row); }
            if ($footer !== '') { $this->write('placements/' . hash('sha256', $scope) . '.yaml', ['scope' => $scope, 'footer' => $footer, 'revision' => bin2hex(random_bytes(12))]); }
            return count($rows);
        });
    }

    private function current(string $id, string $scope, string $revision): array
    {
        $row = $this->get($id);
        if ($row['scope'] !== $scope) { throw new InvalidArgumentException('SB_NOT_FOUND'); }
        if ($row['revision'] !== $revision) { throw new RuntimeException('SB_CONFLICT', 409); }
        return $row;
    }

    private function writeRow(array $row): array
    {
        $row['revision'] = bin2hex(random_bytes(12)); $row['updatedAt'] = gmdate('c');
        $this->write('blocks/' . $row['id'] . '.yaml', $row);
        return $row;
    }

    private function read(string $path): array
    {
        $file = $this->root . '/' . $path;
        return is_file($file) ? (Yaml::parseFile($file) ?: []) : [];
    }

    private function write(string $path, array $value): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) { throw new RuntimeException('SB_STORAGE_ERROR'); }
        $tmp = tempnam(dirname($file), '.write-');
        try {
            if ($tmp === false || file_put_contents($tmp, Yaml::dump($value, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) === false || !rename($tmp, $file)) { throw new RuntimeException('SB_STORAGE_ERROR'); }
        } finally { if ($tmp && is_file($tmp)) { unlink($tmp); } }
    }

    private function locked(callable $work)
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) { throw new RuntimeException('SB_STORAGE_ERROR'); }
        $lock = fopen($this->root . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('SB_STORAGE_ERROR'); }
        try { return $work(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
