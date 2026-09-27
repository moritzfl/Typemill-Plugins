<?php

namespace Plugins\designpanel;

use Plugins\designpanel\Models\Schema;
use Typemill\Models\Navigation;
use Typemill\Models\Meta;
use Typemill\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Models\Settings;
use Typemill\Plugin;
use Typemill\Static\Translations;

class designpanel extends Plugin
{
    private const THEMES = ['atelier', 'court', 'legible', 'lucid', 'medium', 'prism', 'rueckenwind'];

    public static function getSubscribedEvents()
    {
        return ['onSystemnaviLoaded' => ['onSystemnaviLoaded', 0],
            'onSessionSegmentsLoaded' => ['onSessionSegmentsLoaded', 0], 'onPageReady' => ['onPageReady', -100]];
    }

    public static function addNewRoutes()
    {
        $routes = [['httpMethod' => 'get', 'route' => '/tm/designpanel', 'name' => 'designpanel.admin',
            'class' => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage', 'resource' => 'system', 'privilege' => 'update']];
        foreach (['state' => 'get', 'pages' => 'get', 'preview' => 'post', 'save' => 'post'] as $method => $verb) {
            $routes[] = ['httpMethod' => $verb, 'route' => '/api/v1/designpanel/' . $method, 'name' => 'designpanel.' . $method,
                'class' => 'Plugins\designpanel\designpanel:' . $method, 'resource' => 'system', 'privilege' => 'update'];
        }
        return $routes;
    }

    public function onSystemnaviLoaded($event)
    {
        $navi = $event->getData();
        $navi['Designpanel'] = ['title' => Translations::translate('Design panel'), 'routename' => 'designpanel.admin',
            'icon' => 'icon-eye', 'aclresource' => 'system', 'aclprivilege' => 'update'];
        if (trim($this->route, '/') === 'tm/designpanel') {
            $navi['Designpanel']['active'] = true;
            $this->addCSS('/designpanel/assets/panel.css');
            $this->addInlineJS('const designPanelTemplate = ' . json_encode(file_get_contents(__DIR__ . '/assets/panel.html')) . ';'
                . file_get_contents(__DIR__ . '/assets/panel.js'));
        }
        $event->setData($navi);
    }

    public function onSessionSegmentsLoaded($event)
    {
        // Core compares prefixes after trimming the route, but uses the original
        // segment length. An empty prefix matches interior pages as well as '/'.
        if (isset($_GET['designpreview'])) { $event->setData(array_merge($event->getData(), [''])); }
    }

    public function onPageReady($event)
    {
        $settings = $this->getSettings();
        $theme = $settings['theme'];
        $token = $_GET['designpreview'] ?? '';
        $preview = is_string($token) ? ($_SESSION['designpanel'][$token] ?? null) : null;
        if (isset($_SESSION['username'], $_SESSION['login']) && $preview && $preview['expires'] > time() && $preview['theme'] === $theme) {
            $settings['themes'][$theme] = $preview['values'];
            $event->setData(array_merge($event->getData(), ['settings' => $settings]));
            $this->addJS('/designpanel/assets/preview.js', 'defer');
            // The preview already lives inside admin. Show the visitor-facing
            // layout without a second, nonfunctional Admin shortcut.
            $this->addInlineCSS('body > div:has(> a[href$="/tm/account"]) { display: none; }');
            $this->addMeta('designpreview', '<meta name="robots" content="noindex,nofollow">');
            header('Cache-Control: private, no-store');
        }
    }

    private function definition(): array
    {
        $settings = $this->getSettings();
        $theme = $settings['theme'];
        if (!in_array($theme, self::THEMES, true)) { throw new \InvalidArgumentException('Select a maintained theme in Themes first.'); }
        $definition = (new Settings())->getObjectSettings('themesFolder', $theme);
        $fields = Schema::fields($definition['forms']['fields'] ?? []);
        return ['theme' => $theme, 'name' => $definition['name'] ?? $theme, 'fields' => $fields, 'presets' => $definition['readymades'] ?? [],
            'values' => array_replace($definition['settings'] ?? [], $settings['themes'][$theme] ?? [])];
    }

    private function revision(string $theme): string
    {
        return hash('sha256', json_encode((new Settings())->getUserSettings()['themes'][$theme] ?? []));
    }

    public function state(Request $request, Response $response, $args)
    {
        try {
            $state = $this->definition();
            $state['revision'] = $this->revision($state['theme']);
            // Only return panel fields, never unrelated theme secrets or custom CSS.
            $keys = array_fill_keys(array_column($state['fields'], 'key'), true);
            $state['values'] = array_intersect_key($state['values'], $keys);
            foreach ($state['presets'] as &$preset) { $preset['settings'] = array_intersect_key($preset['settings'] ?? [], $keys); }
            unset($preset);
            return $this->json($response, $state);
        } catch (\InvalidArgumentException $e) { return $this->json($response, ['message' => $e->getMessage()], 422); }
    }

    public function pages(Request $request, Response $response, $args)
    {
        $settings = $this->getSettings();
        $urlinfo = $this->urlinfo();
        $navigation = new Navigation();
        $meta = new Meta();
        $user = (new User())->setUser((string) $request->getAttribute('c_username'));
        if (!$user) { return $this->json($response, ['message' => 'Could not load pages.'], 403); }
        $restrictions = !empty($settings['pageaccess']) ? [
            'username' => $request->getAttribute('c_username'), 'userrole' => $request->getAttribute('c_userrole'),
            'acl' => $this->container->get('acl'),
        ] : false;
        $paths = ['/'];
        foreach ($navigation->getAllProjects($settings) ?: [] as $project) {
            if (!$project['base']) { $paths[] = '/' . $project['id']; }
        }
        $pages = [];
        foreach ($paths as $projectPath) {
            $navigation = new Navigation();
            $navigation->setProject($settings, $projectPath);
            $tree = $navigation->generateLiveNavigationFromDraft($navigation->getFullDraftNavigation($urlinfo, $settings['langattr'] ?? 'en'));
            if ($user->getValue('folderaccess')) { $tree = $navigation->getAllowedFolders($tree, $user->getValue('folderaccess')); }
            $home = $navigation->getHomepageItem($urlinfo['baseurl']);
            if ($navigation->checkFolderAccess($projectPath, $user->getValue('folderaccess'))) {
                $homeMeta = $meta->getMetaData($home)['meta'] ?? [];
                foreach (['alloweduser', 'allowedrole'] as $key) {
                    if (!empty($homeMeta[$key])) { $home->$key = $homeMeta[$key]; }
                }
                array_unshift($tree, $home);
            }
            // Core rules prune restricted ancestors and unpublished branches.
            // Hidden pages are still useful to an editor and remain selectable.
            $tree = $navigation->removePages($tree, false, $restrictions);
            $walk = function (array $items) use (&$walk, &$pages, $meta): void {
                foreach ($items as $item) {
                    $metadata = $meta->getMetaData($item)['meta'] ?? [];
                    if (!empty($metadata['reference'])) { continue; }
                    $pages[] = ['path' => '/' . trim($item->urlRelWoF, '/'),
                        'title' => (string) ($metadata['title'] ?? $item->name)];
                    if (!empty($item->folderContent)) { $walk($item->folderContent); }
                }
            };
            $walk($tree);
        }
        return $this->json($response, ['pages' => $pages]);
    }

    public function preview(Request $request, Response $response, $args)
    {
        try {
            $state = $this->definition();
            $params = (array) $request->getParsedBody();
            if (($params['theme'] ?? '') !== $state['theme']) { throw new \InvalidArgumentException('The active theme changed. Reload the panel.'); }
            $values = Schema::validate((array) ($params['values'] ?? []), $state['fields']);
            $path = $params['path'] ?? '/';
            if (!is_string($path) || !preg_match('#^/(?!/|tm/|api/)[a-zA-Z0-9/_%-]*$#D', $path) || str_contains($path, '..')) {
                throw new \InvalidArgumentException('Use a local page path.');
            }
            $token = bin2hex(random_bytes(16));
            $previews = array_filter($_SESSION['designpanel'] ?? [], fn ($row) => $row['expires'] > time());
            $previews = array_slice($previews, -4, null, true);
            $previews[$token] = ['theme' => $state['theme'], 'values' => array_replace($state['values'], $values), 'expires' => time() + 600];
            $_SESSION['designpanel'] = $previews;
            return $this->json($response, ['url' => $this->urlinfo()['baseurl'] . $path . '?designpreview=' . $token]);
        } catch (\InvalidArgumentException $e) { return $this->json($response, ['message' => $e->getMessage()], 422); }
    }

    public function save(Request $request, Response $response, $args)
    {
        try {
            $state = $this->definition();
            $params = (array) $request->getParsedBody();
            if (($params['theme'] ?? '') !== $state['theme'] || ($params['revision'] ?? '') !== $this->revision($state['theme'])) {
                return $this->json($response, ['message' => 'Theme settings changed elsewhere. Reload before saving.'], 409);
            }
            $values = Schema::validate((array) ($params['values'] ?? []), $state['fields']);
            $saved = (new Settings())->updateSettings(array_replace($state['values'], $values), 'themes', $state['theme']);
            if (!$saved) { return $this->json($response, ['message' => 'Could not save theme settings.'], 500); }
            unset($_SESSION['designpanel']);
            return $this->json($response, ['message' => 'Theme settings saved.', 'revision' => $this->revision($state['theme'])]);
        } catch (\InvalidArgumentException $e) { return $this->json($response, ['message' => $e->getMessage()], 422); }
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }
}
