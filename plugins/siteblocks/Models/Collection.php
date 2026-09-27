<?php

namespace Plugins\siteblocks\Models;

use Typemill\Models\Navigation;
use Typemill\Models\Meta;

/** Read public, published pages only, including every ancestor's restrictions. */
final class Collection
{
    private array $pages = [];

    public function __construct(array $settings, array $urlinfo, string $route = '/')
    {
        if (!empty($settings['access'])) { return; }
        $navigation = new Navigation();
        $navigation->setProject($settings, $route);
        $tree = $navigation->getLiveNavigation($urlinfo, $settings['langattr'] ?? 'en') ?: [];
        $this->walk($tree);
        // Typemill keeps the homepage outside its navigation.
        if ($route === '/') {
            $home = $navigation->getHomepageItem($urlinfo['baseurl']);
            if ($home) { $this->walk([$home]); }
        }
    }

    private function walk(array $tree): void
    {
        $metaModel = new Meta();
        foreach ($tree as $item) {
            $metadata = $metaModel->getMetaData($item);
            $meta = $metadata['meta'] ?? [];
            if (!self::isPublic($meta) || !empty($item->hide) || ($item->status ?? '') === 'unpublished') { continue; }
            $url = '/' . trim($item->urlRelWoF ?? $item->urlRel ?? '', '/');
            $this->pages[$url] = ['item' => $item, 'meta' => $meta, 'tags' => self::tags($metadata['siteblocks']['tags'] ?? '')];
            if (!empty($item->folderContent)) { $this->walk($item->folderContent); }
        }
    }

    public static function isPublic(array $meta): bool
    {
        return empty($meta['hide']) && empty($meta['noindex']) && empty($meta['alloweduser'])
            && empty($meta['allowedrole']) && empty($meta['reference']);
    }

    public static function tags($value): array
    {
        return is_string($value) ? array_values(array_unique(array_filter(array_map('trim', explode(',', mb_strtolower($value)))))) : [];
    }

    public function page(string $url): ?array
    {
        return $this->pages['/' . trim($url, '/')] ?? null;
    }

    public function select(string $folder, string $tag, int $limit): array
    {
        $prefix = '/' . trim($folder, '/');
        $rows = [];
        foreach ($this->pages as $url => $row) {
            if (($row['item']->elementType ?? '') === 'folder' || $url === '/' || $url === $prefix) { continue; }
            if ($prefix !== '/' && !str_starts_with($url, $prefix . '/')) { continue; }
            if ($tag !== '' && !in_array(mb_strtolower(trim($tag)), $row['tags'], true)) { continue; }
            $meta = $row['meta'];
            $rows[] = [
                'title' => $meta['title'] ?? $row['item']->name ?? '',
                'text' => $meta['description'] ?? '',
                'src' => $meta['heroimage'] ?? '',
                'alt' => $meta['heroimagealt'] ?? '',
                'url' => $url,
                'date' => strtotime((string) ($meta['manualdate'] ?? '')) ?: (int) ($meta['modified'] ?? 0),
            ];
        }
        usort($rows, fn ($a, $b) => $b['date'] <=> $a['date'] ?: strcmp($a['url'], $b['url']));
        return array_slice($rows, 0, max(1, min(24, $limit)));
    }
}
