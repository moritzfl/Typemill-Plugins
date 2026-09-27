<?php

namespace Plugins\siteblocks\Models;

final class Renderer
{
    public function __construct(private string $base = '') {}

    public function render(array $block): string
    {
        $b = Block::normalize($block);
        $type = $b['type'];
        $html = '<section class="sb sb--' . $type . ' sb--' . $b['layout'] . '" style="--sb-columns:' . $b['columns'] . '">';
        if ($b['title'] !== '') { $html .= '<h2>' . Block::escape($b['title']) . '</h2>'; }
        if ($b['text'] !== '') { $html .= '<p>' . nl2br(Block::escape($b['text'])) . '</p>'; }
        if (in_array($type, ['hero', 'cta'], true)) {
            if ($type === 'hero' && !empty($b['items'][0]['src'])) { $html .= $this->image($b['items'][0]); }
            $html .= $this->link($b['url'], $b['label']);
        } else {
            $html .= '<div class="sb__items">';
            foreach ($b['items'] as $item) {
                if (in_array($type, ['gallery', 'slideshow', 'masonry'], true)) {
                    if ($item['src'] === '') { continue; }
                    $html .= '<figure><a class="sb__zoom" href="' . $this->url($item['src']) . '" aria-label="'
                        . Block::escape($item['alt'] ?: $item['caption'] ?: 'Open image') . '" data-sb-image>' . $this->image($item) . '</a>';
                    if ($item['caption'] !== '') { $html .= '<figcaption>' . Block::escape($item['caption']) . '</figcaption>'; }
                    $html .= '</figure>';
                } else {
                    $html .= '<div class="sb__card">';
                    if ($item['src'] !== '') { $html .= $this->image($item); }
                    if ($item['title'] !== '') { $html .= '<h3>' . Block::escape($item['title']) . '</h3>'; }
                    if ($item['text'] !== '') { $html .= '<p>' . nl2br(Block::escape($item['text'])) . '</p>'; }
                    $html .= $this->link($item['url'], $item['label'] ?: $item['title']) . '</div>';
                }
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    private function image(array $item): string
    {
        return '<img loading="lazy" decoding="async" src="' . $this->url($item['src']) . '" alt="' . Block::escape($item['alt'])
            . '" style="object-position:' . $item['x'] . '% ' . $item['y'] . '%">';
    }

    private function link(string $url, string $label): string
    {
        return $url !== '' && $label !== '' ? '<a class="sb__link" href="' . $this->url($url) . '">' . Block::escape($label) . '</a>' : '';
    }

    private function url(string $url): string
    {
        return Block::escape(str_starts_with($url, '/') ? rtrim($this->base, '/') . $url : $url);
    }
}
