<?php

namespace Plugins\siteblocks\Models;

use InvalidArgumentException;

/** Versioned, theme-independent data. Never accept arbitrary HTML or CSS. */
final class Block
{
    public const TYPES = ['hero', 'cta', 'columns', 'gallery', 'slideshow', 'masonry', 'collection'];

    public static function decode(string $encoded): array
    {
        if (strlen($encoded) > 65536 || !preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) {
            throw new InvalidArgumentException('Invalid block data.');
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true, 12);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid block data.');
        }
        return self::normalize($data);
    }

    public static function normalize(array $data): array
    {
        if (($data['version'] ?? 1) !== 1 || !in_array($data['type'] ?? '', self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported block type or version.');
        }
        $block = ['version' => 1, 'type' => $data['type']];
        foreach (['title', 'text', 'label', 'tag', 'folder'] as $key) {
            $block[$key] = self::text($data[$key] ?? '');
        }
        $block['url'] = self::url(self::text($data['url'] ?? ''));
        $block['columns'] = max(2, min(4, (int) ($data['columns'] ?? 3)));
        $block['limit'] = max(1, min(24, (int) ($data['limit'] ?? 6)));
        $block['layout'] = ($data['layout'] ?? '') === 'list' ? 'list' : 'cards';
        $block['items'] = [];
        foreach (array_slice(is_array($data['items'] ?? null) ? $data['items'] : [], 0, 24) as $item) {
            if (!is_array($item)) { continue; }
            $clean = [];
            foreach (['title', 'text', 'alt', 'caption', 'label'] as $key) {
                $clean[$key] = self::text($item[$key] ?? '');
            }
            $clean['src'] = self::url(self::text($item['src'] ?? ''), true);
            $clean['url'] = self::url(self::text($item['url'] ?? ''));
            $clean['x'] = max(0, min(100, (int) ($item['x'] ?? 50)));
            $clean['y'] = max(0, min(100, (int) ($item['y'] ?? 50)));
            $block['items'][] = $clean;
        }
        return $block;
    }

    public static function text($value): string
    {
        return is_string($value) ? mb_substr($value, 0, 4000) : '';
    }

    public static function url(string $value, bool $image = false): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\\\\]/', $value)) { return ''; }
        if (str_starts_with($value, 'media/')) { return '/' . $value; }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) { return $value; }
        if (!$image && (str_starts_with($value, '#') || preg_match('/^(mailto|tel):[^<>]+$/iD', $value))) { return $value; }
        return preg_match('#^https?://[^/]+#i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
