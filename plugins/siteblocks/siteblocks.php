<?php

namespace Plugins\siteblocks;

use Plugins\siteblocks\Models\Block;
use Plugins\siteblocks\Models\Collection;
use Plugins\siteblocks\Models\Renderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Models\Content;
use Typemill\Plugin;

class siteblocks extends Plugin
{
    private ?Collection $collection = null;

    public static function getSubscribedEvents()
    {
        return ['onTwigLoaded' => ['onTwigLoaded', 0], 'onShortcodeFound' => ['onShortcodeFound', 0]];
    }

    public static function addNewRoutes()
    {
        return [['httpMethod' => 'get', 'route' => '/siteblocks.json', 'name' => 'siteblocks.json',
            'class' => 'Plugins\siteblocks\siteblocks:pageJson']];
    }

    public function onTwigLoaded($event)
    {
        $this->addCSS('/siteblocks/assets/blocks.css');
        if ($this->editorroute) {
            $this->addBloxConfigJS('/siteblocks/assets/config.js');
            $this->addJS('/siteblocks/assets/editor.js');
        } elseif (!$this->adminroute) {
            $this->addJS('/siteblocks/assets/blocks.js', 'defer');
        }
        $shared = $this->shared();
        $this->addTwigGlobal('siteblocks_shared', $shared['items'] ? $this->renderer()->render($shared) : '');
    }

    public function onShortcodeFound($event)
    {
        $shortcode = $event->getData();
        if (!is_array($shortcode) || ($shortcode['name'] ?? '') !== 'siteblock') { return; }
        try {
            $block = Block::decode((string) ($shortcode['params']['data'] ?? ''));
            if ($block['type'] === 'collection') {
                $this->collection ??= new Collection($this->getSettings(), $this->urlinfo(), '/' . trim($this->route, '/'));
                $block['items'] = $this->collection->select($block['folder'], $block['tag'], $block['limit']);
            } elseif ($block['type'] === 'shared') {
                $block = $this->shared();
            }
            $event->setData($this->renderer()->render($block));
        } catch (\InvalidArgumentException $e) {
            $event->setData('<p class="sb sb--error">' . Block::escape($e->getMessage()) . '</p>');
        }
    }

    private function renderer(): Renderer
    {
        return new Renderer($this->urlinfo()['baseurl'] ?? '');
    }

    private function shared(): array
    {
        $settings = $this->getPluginSettings() ?: [];
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            if (!empty($settings['shared_title' . $i]) || !empty($settings['shared_text' . $i])
                || (!empty($settings['shared_label' . $i]) && !empty($settings['shared_url' . $i]))) {
                $items[] = ['title' => $settings['shared_title' . $i] ?? '', 'text' => $settings['shared_text' . $i] ?? '',
                    'url' => $settings['shared_url' . $i] ?? '', 'label' => $settings['shared_label' . $i] ?? ''];
            }
        }
        return ['version' => 1, 'type' => 'shared', 'items' => $items];
    }

    public function pageJson(Request $request, Response $response, $args)
    {
        $settings = $this->getSettings();
        $url = $request->getQueryParams()['url'] ?? '/';
        $row = null;
        if (!empty(($this->getPluginSettings() ?: [])['expose_json']) && empty($settings['access']) && is_string($url)) {
            $row = (new Collection($settings, $this->urlinfo(), $url))->page($url);
        }
        $payload = ['error' => 'Page not found.'];
        if ($row) {
            $markdown = (new Content())->getLiveMarkdown($row['item']);
            if (!is_string($markdown)) {
                $response->getBody()->write(json_encode($payload));
                return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withStatus(404);
            }
            $blocks = [];
            preg_match_all('/\[:siteblock data="([A-Za-z0-9_-]+)"\s*:\]/', $markdown, $matches);
            foreach ($matches[1] as $encoded) {
                try { $blocks[] = Block::decode($encoded); } catch (\InvalidArgumentException $e) { /* Invalid blocks are not exported. */ }
            }
            $meta = $row['meta'];
            $payload = ['schema_version' => 1, 'url' => '/' . trim($url, '/'), 'title' => $meta['title'] ?? '',
                'description' => $meta['description'] ?? '', 'tags' => $row['tags'], 'markdown' => $markdown,
                'blocks' => $blocks, 'media' => ['hero' => Block::url($meta['heroimage'] ?? '', true), 'alt' => $meta['heroimagealt'] ?? '']];
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')->withStatus($row ? 200 : 404);
    }
}
