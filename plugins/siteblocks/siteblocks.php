<?php

namespace Plugins\siteblocks;

use Plugins\siteblocks\Models\Block;
use Plugins\siteblocks\Models\Collection;
use Plugins\siteblocks\Models\Renderer;
use Plugins\siteblocks\Models\Library;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Models\Content;
use Typemill\Models\User;
use Typemill\Models\Navigation;
use Typemill\Models\StorageWrapper;
use Typemill\Plugin;
use Typemill\Static\Translations;

class siteblocks extends Plugin
{
    private ?Collection $collection = null;
    private bool $renderingLibrary = false;
    private ?string $renderScope = null;

    public static function getSubscribedEvents()
    {
        return ['onTwigLoaded' => ['onTwigLoaded', 0], 'onShortcodeFound' => ['onShortcodeFound', 0],
            'onSystemnaviLoaded' => ['onSystemnaviLoaded', 0], 'onResourcesLoaded' => ['onResourcesLoaded', 0],
            'onRolesPermissionsLoaded' => ['onRolesPermissionsLoaded', 0], 'onSessionSegmentsLoaded' => ['onSessionSegmentsLoaded', 0]];
    }

    public static function addNewRoutes()
    {
        $routes = [['httpMethod' => 'get', 'route' => '/siteblocks.json', 'name' => 'siteblocks.json',
            'class' => 'Plugins\siteblocks\siteblocks:pageJson'],
            ['httpMethod' => 'get', 'route' => '/tm/siteblocks', 'name' => 'siteblocks.admin',
                'class' => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage', 'resource' => 'siteblocks', 'privilege' => 'read']];
        foreach (['state' => ['get', 'siteblocks', 'read'], 'save' => ['post', 'siteblocks', 'update'],
            'action' => ['post', 'siteblocks', 'read'], 'placement' => ['post', 'siteblocks', 'publish'],
            'preview' => ['post', 'siteblocks', 'read'], 'parse' => ['post', 'siteblocks', 'read'],
            'catalog' => ['get', 'mycontent', 'create'], 'export' => ['get', 'siteblocks', 'read'],
            'import' => ['post', 'siteblocks', 'publish']] as $method => [$verb, $resource, $privilege]) {
            $routes[] = ['httpMethod' => $verb, 'route' => '/api/v1/siteblocks/' . $method, 'name' => 'siteblocks.' . $method,
                'class' => 'Plugins\siteblocks\siteblocks:' . $method, 'resource' => $resource, 'privilege' => $privilege];
        }
        return $routes;
    }

    public function onResourcesLoaded($event) { $event->setData(array_values(array_unique(array_merge($event->getData(), ['siteblocks'])))); }
    public function onRolesPermissionsLoaded($event)
    {
        $roles = $event->getData();
        if (isset($roles['manager']) && !isset($roles['manager']['permissions']['siteblocks'])) {
            $roles['manager']['permissions']['siteblocks'] = ['read', 'update', 'publish', 'delete'];
        }
        $event->setData($roles);
    }

    public function onSystemnaviLoaded($event)
    {
        $navi = $event->getData();
        $navi['Siteblocks'] = ['title' => 'Content blocks', 'routename' => 'siteblocks.admin', 'icon' => 'icon-list',
            'aclresource' => 'siteblocks', 'aclprivilege' => 'read'];
        if (trim($this->route, '/') === 'tm/siteblocks') {
            $navi['Siteblocks']['active'] = true;
            $this->addCSS('/siteblocks/assets/editor.css');
            $this->addCSS('/siteblocks/assets/library.css');
            $this->addInlineJS('const siteBlocksTemplate = ' . json_encode(file_get_contents(__DIR__ . '/assets/library.html')) . ';'
                . file_get_contents(__DIR__ . '/assets/library.js') . ";\n"
                . file_get_contents(dirname(__DIR__, 2) . '/system/typemill/author/js/vue-blox-components.js') . ";\n"
                . file_get_contents(__DIR__ . '/assets/editor.js'));
        }
        $event->setData($navi);
    }

    public function onSessionSegmentsLoaded($event)
    {
        if (isset($_GET['blockpreview'])) { $event->setData(array_merge($event->getData(), [''])); }
    }

    public function onTwigLoaded($event)
    {
        $this->addCSS('/siteblocks/assets/blocks.css');
        if ($this->adminroute) { $this->addJS('/siteblocks/assets/messages.js'); }
        if ($this->editorroute) {
            $this->addCSS('/siteblocks/assets/editor.css');
            $this->addBloxConfigJS('/siteblocks/assets/config.js');
            $this->addJS('/siteblocks/assets/editor.js');
            $this->addJS('/siteblocks/assets/reference.js');
        } elseif (!$this->adminroute) {
            $this->addJS('/siteblocks/assets/blocks.js', 'defer');
        }
        $scope = $this->scopeForPath('/' . trim($this->route, '/'));
        $id = $this->library()->placement($scope)['footer'];
        $this->addTwigGlobal('siteblocks_footer', $id ? $this->renderReference($id, $scope) : '');
        if (isset($_GET['blockpreview'])) {
            header('Cache-Control: private, no-store');
            $this->addMeta('blockpreview', '<meta name="robots" content="noindex,nofollow">');
        }
    }

    public function onShortcodeFound($event)
    {
        $shortcode = $event->getData();
        if (!is_array($shortcode)) { return; }
        if (($shortcode['name'] ?? '') === 'siteblock-ref') {
            $scope = $this->renderScope ?? $this->scopeForPath($this->contentPath());
            $html = $this->renderingLibrary ? '' : $this->renderReference((string) ($shortcode['params']['id'] ?? ''), $scope);
            if ($html === '' && !$this->renderingLibrary && ($this->editorroute || str_starts_with(trim($this->route, '/'), 'api/'))) {
                $html = '<div class="sb-reference">' . Block::escape(Translations::translate('Linked block unavailable')) . '</div>';
            }
            $event->setData($html);
            return;
        }
        if (($shortcode['name'] ?? '') !== 'siteblock') { return; }
        try {
            $block = Block::decode((string) ($shortcode['params']['data'] ?? ''));
            if ($block['type'] === 'collection') {
                $this->collection ??= new Collection($this->getSettings(), $this->urlinfo(), $this->renderScope ?: $this->contentPath());
                $block['items'] = $this->collection->select($block['folder'], $block['tag'], $block['limit']);
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

    private function library(): Library
    {
        $storage = new StorageWrapper($this->getSettings()['storage']);
        return new Library(rtrim($storage->getFolderPath('dataFolder'), '/') . '/siteblocks', rtrim($storage->getFolderPath('contentFolder'), '/'));
    }

    private function scopeForPath(string $path): string
    {
        $project = (new Navigation())->getProjectFromUrl($path, $this->getSettings());
        return $project ? '/' . strtolower($project) : '';
    }

    private function scopes(): array
    {
        $scopes = [['id' => '', 'title' => $this->getSettings()['baseprojectlabel'] ?? 'Default site']];
        foreach ((new Navigation())->getAllProjects($this->getSettings()) ?: [] as $project) {
            if (!$project['base']) { $scopes[] = ['id' => '/' . $project['id'], 'title' => $project['label']]; }
        }
        return $scopes;
    }

    private function contentPath(): string
    {
        if (str_starts_with($this->route, 'api/')) {
            $body = json_decode(file_get_contents('php://input'), true);
            return is_string($body['url'] ?? null) ? $body['url'] : '/';
        }
        return '/' . trim($this->route, '/');
    }

    private function renderMarkdown(string $markdown, string $scope): string
    {
        if (trim($markdown) === '') { return ''; }
        $previous = $this->renderScope; $this->renderScope = $scope;
        $content = new Content($this->urlinfo()['baseurl'], $this->getSettings(), $this->container->get('dispatcher'));
        try { return $content->getContentHtml($content->getContentArray($markdown)); }
        finally { $this->renderScope = $previous; }
    }

    private function renderReference(string $id, string $scope): string
    {
        try {
            $row = $this->library()->get($id);
            if ($row['scope'] !== $scope) { return ''; }
            $markdown = $row['published'];
            $token = $_GET['blockpreview'] ?? '';
            $preview = is_string($token) ? ($_SESSION['siteblocks_preview'][$token] ?? null) : null;
            if ($preview && $preview['expires'] > time() && $preview['id'] === $id && $preview['scope'] === $scope && isset($_SESSION['login'], $_SESSION['username'])) {
                $user = (new User())->setUser($_SESSION['username']);
                if ($user && $this->container->get('acl')->isAllowed($user->getValue('userrole'), 'siteblocks', 'read')) { $markdown = $preview['markdown']; }
            }
            if ($markdown === null) { return ''; }
            $this->renderingLibrary = true;
            try { return '<div class="sb-reference" data-sb-ref="' . $id . '">' . $this->renderMarkdown($markdown, $scope) . '</div>'; }
            finally { $this->renderingLibrary = false; }
        } catch (\InvalidArgumentException $e) { return ''; }
    }

    private function api(Request $request, Response $response, callable $work): Response
    {
        try {
            $params = array_merge($request->getQueryParams(), (array) $request->getParsedBody());
            foreach (['scope', 'id', 'revision', 'title', 'markdown', 'action', 'history', 'footer', 'path'] as $field) {
                if (isset($params[$field]) && !is_string($params[$field])) { throw new \InvalidArgumentException('SB_BAD_INPUT'); }
            }
            $scope = $params['scope'] ?? '';
            if (!is_string($scope) || !in_array($scope, array_column($this->scopes(), 'id'), true)) { throw new \InvalidArgumentException('SB_BAD_SCOPE'); }
            return $this->json($response, $work($params, $scope));
        } catch (\InvalidArgumentException $e) { return $this->json($response, ['message' => $e->getMessage()], 422); }
        catch (\RuntimeException $e) { return $this->json($response, ['message' => $e->getMessage()], $e->getCode() === 409 ? 409 : 500); }
    }

    private function allowed(Request $request, string $action): bool
    {
        return $this->container->get('acl')->isAllowed($request->getAttribute('c_userrole'), 'siteblocks', $action);
    }

    public function state(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, function ($params, $scope) use ($request) {
            $library = $this->library(); $rows = $library->all($scope);
            foreach ($rows as &$row) { $row['usage'] = $library->usage($row['id'], $scope); }
            return ['blocks' => $rows, 'placement' => $library->placement($scope), 'scopes' => $this->scopes(),
                'permissions' => ['update' => $this->allowed($request, 'update'), 'publish' => $this->allowed($request, 'publish'), 'delete' => $this->allowed($request, 'delete')],
                'footerSupported' => in_array($this->getSettings()['theme'], ['atelier', 'court', 'legible', 'lucid', 'medium', 'prism', 'rueckenwind'], true)];
        });
    }

    public function save(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, fn ($p, $scope) => ['block' => $this->library()->save((string) ($p['id'] ?? ''), $scope,
            (string) ($p['revision'] ?? ''), (string) ($p['title'] ?? ''), (string) ($p['markdown'] ?? ''))]);
    }

    public function action(Request $request, Response $response, $args)
    {
        $p = (array) $request->getParsedBody();
        $right = in_array($p['action'] ?? '', ['publish', 'unpublish'], true) ? 'publish' : (($p['action'] ?? '') === 'delete' ? 'delete' : 'update');
        if (!$this->allowed($request, $right)) { return $this->json($response, ['message' => 'SB_FORBIDDEN'], 403); }
        return $this->api($request, $response, fn ($p, $scope) => ['block' => $this->library()->action((string) ($p['id'] ?? ''), $scope,
            (string) ($p['revision'] ?? ''), (string) ($p['action'] ?? ''), (string) ($p['history'] ?? ''))]);
    }

    public function placement(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, fn ($p, $scope) => ['placement' => $this->library()->place($scope, (string) ($p['footer'] ?? ''), (string) ($p['revision'] ?? ''))]);
    }

    public function parse(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, function ($p, $scope) {
            $markdown = (string) ($p['markdown'] ?? ''); Library::validateMarkdown($markdown);
            $content = new Content($this->urlinfo()['baseurl'], $this->getSettings(), $this->container->get('dispatcher'));
            return ['parts' => trim($markdown) === '' ? [] : array_values($content->markdownTextToArray($markdown)), 'html' => $this->renderMarkdown($markdown, $scope)];
        });
    }

    public function preview(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, function ($p, $scope) {
            $row = $this->library()->get((string) ($p['id'] ?? ''));
            if ($row['scope'] !== $scope) { throw new \InvalidArgumentException('SB_NOT_FOUND'); }
            $markdown = (string) ($p['markdown'] ?? $row['draft']); Library::validateMarkdown($markdown);
            $path = (string) ($p['path'] ?? ($scope ?: '/'));
            if (!preg_match('#^/(?!/|tm(?:/|$)|api(?:/|$)|media(?:/|$))[a-zA-Z0-9/_%-]*$#D', $path) || str_contains($path, '..') || $this->scopeForPath($path) !== $scope) { throw new \InvalidArgumentException('SB_BAD_SCOPE'); }
            $token = bin2hex(random_bytes(16));
            $_SESSION['siteblocks_preview'] = array_slice(array_filter($_SESSION['siteblocks_preview'] ?? [], fn ($row) => $row['expires'] > time()), -4, null, true);
            $_SESSION['siteblocks_preview'][$token] = ['id' => $row['id'], 'scope' => $scope, 'markdown' => $markdown, 'expires' => time() + 600];
            return ['html' => $this->renderMarkdown($markdown, $scope), 'url' => $this->urlinfo()['baseurl'] . $path . '?blockpreview=' . $token];
        });
    }

    public function catalog(Request $request, Response $response, $args)
    {
        $path = $request->getQueryParams()['path'] ?? '/';
        if (!is_string($path)) { return $this->json($response, ['message' => 'SB_BAD_SCOPE'], 422); }
        $user = (new User())->setUser((string) $request->getAttribute('c_username'));
        $contentPath = preg_replace('#^/?tm/content/(?:visual|raw)#', '', $path) ?: '/';
        if (!$user || !(new Navigation())->checkFolderAccess($contentPath, $user->getValue('folderaccess'))) { return $this->json($response, ['message' => 'SB_FORBIDDEN'], 403); }
        $scope = $this->scopeForPath($contentPath); $rows = [];
        foreach ($this->library()->all($scope) as $row) {
            if ($row['published'] !== null) { $rows[] = ['id' => $row['id'], 'title' => $row['title'], 'markdown' => $row['published'],
                'archived' => $row['archived'], 'html' => $this->renderMarkdown($row['published'], $scope)]; }
        }
        return $this->json($response, ['blocks' => $rows, 'scope' => $scope, 'manage' => $this->allowed($request, 'read')]);
    }

    public function export(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, fn ($p, $scope) => $this->library()->export($scope));
    }

    public function import(Request $request, Response $response, $args)
    {
        return $this->api($request, $response, fn ($p, $scope) => ['count' => $this->library()->import($scope, (array) ($p['bundle'] ?? []))]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'private, no-store')->withStatus($status);
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
