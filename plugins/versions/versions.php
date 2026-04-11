<?php

namespace Plugins\versions;

use Plugins\versions\Middleware\AssetTrashMiddleware;
use Plugins\versions\Models\VersionPreviewRenderer;
use Plugins\versions\Models\VersionStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Events\OnPageDiscard;
use Typemill\Events\OnPagePublished;
use Typemill\Events\OnPageUnpublished;
use Typemill\Events\OnPageUpdated;
use Typemill\Models\Content;
use Typemill\Models\Meta;
use Typemill\Models\Navigation;
use Typemill\Models\Settings;
use Typemill\Models\Sitemap;
use Typemill\Models\User;
use Typemill\Plugin;
use Typemill\Static\Translations;

class versions extends Plugin
{
    public static function setPremiumLicense()
    {
        return false;
    }

    public static function getSubscribedEvents()
    {
        return [
            'onCspLoaded' => ['onCspLoaded', 0],
            'onTwigLoaded' => ['onTwigLoaded', 0],
            'onSystemnaviLoaded' => ['onSystemnaviLoaded', 0],
            'onPageUpdated' => ['onPageUpdated', 0],
            'onPagePublished' => ['onPagePublished', 0],
            'onPageUnpublished' => ['onPageUnpublished', 0],
            'onPageDiscard' => ['onPageDiscard', 0],
        ];
    }

    public static function addNewMiddleware()
    {
        return [
            'classname' => AssetTrashMiddleware::class,
            'params' => [],
        ];
    }

    public static function addNewRoutes()
    {
        return [
            [
                'httpMethod' => 'get',
                'route' => '/tm/versions',
                'name' => 'versions.admin',
                'class' => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/versions/system',
                'name' => 'versions.system.get',
                'class' => 'Plugins\versions\versions:getSystemData',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/versions/system',
                'name' => 'versions.system.save',
                'class' => 'Plugins\versions\versions:saveSystemSettings',
                'resource' => 'system',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'delete',
                'route' => '/api/v1/versions/trash',
                'name' => 'versions.trash.empty',
                'class' => 'Plugins\versions\versions:emptyTrash',
                'resource' => 'system',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'delete',
                'route' => '/api/v1/versions/trash/entry',
                'name' => 'versions.trash.entry.delete',
                'class' => 'Plugins\versions\versions:deleteTrashEntry',
                'resource' => 'system',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/versions/trash/restore',
                'name' => 'versions.trash.restore',
                'class' => 'Plugins\versions\versions:restoreTrashEntry',
                'resource' => 'system',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/versions/trash/version',
                'name' => 'versions.trash.version.get',
                'class' => 'Plugins\versions\versions:getTrashVersion',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/versions/trash/download',
                'name' => 'versions.trash.download',
                'class' => 'Plugins\versions\versions:downloadTrashEntry',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/versions/page',
                'name' => 'versions.page.get',
                'class' => 'Plugins\versions\versions:getPageVersions',
                'resource' => 'mycontent',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/versions/page/version',
                'name' => 'versions.page.version.get',
                'class' => 'Plugins\versions\versions:getPageVersion',
                'resource' => 'mycontent',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/versions/page/restore',
                'name' => 'versions.page.restore',
                'class' => 'Plugins\versions\versions:restorePageVersion',
                'resource' => 'mycontent',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/versions/page/current',
                'name' => 'versions.page.current.get',
                'class' => 'Plugins\versions\versions:getCurrentMarkdown',
                'resource' => 'mycontent',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/versions/page/save',
                'name' => 'versions.page.save',
                'class' => 'Plugins\versions\versions:saveEditedMarkdown',
                'resource' => 'mycontent',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'delete',
                'route' => '/api/v1/versions/article',
                'name' => 'versions.article.delete',
                'class' => 'Plugins\versions\versions:deleteArticle',
                'resource' => 'content',
                'privilege' => 'delete',
            ],
        ];
    }

    public function onTwigLoaded($event)
    {
        if ($this->editorroute) {
            $this->addCSS('/versions/js/mergely.css');
            $this->addJS('/versions/js/mergely.min.js');
            $this->addInlineJS(file_get_contents(__DIR__ . '/js/editor-versions.js'));
        }
    }

    public function onCspLoaded($event)
    {
        $csp = $event->getData();
        if (!is_array($csp)) {
            $csp = [];
        }

        $csp[] = 'blob:';
        $event->setData(array_values(array_unique($csp)));
    }

    public function onSystemnaviLoaded($navidata)
    {
        $this->addSvgSymbol('<symbol id="icon-versions" viewBox="0 0 24 24"><path d="M13 3a9 9 0 1 0 8.95 10h-2.02A7 7 0 1 1 13 5v4l5-5-5-5v4Zm-1 5v6l5 3 .99-1.65L14 13.1V8h-2Z"/></symbol>');

        $navi = $navidata->getData();
        $navi['Versions'] = [
            'title' => Translations::translate('versions.recycle_bin'),
            'routename' => 'versions.admin',
            'icon' => 'icon-versions',
            'aclresource' => 'system',
            'aclprivilege' => 'read',
        ];

        if (trim($this->route, '/') === 'tm/versions') {
            $navi['Versions']['active'] = true;
            $template = file_get_contents(__DIR__ . '/js/systemversions.html');
            $js = file_get_contents(__DIR__ . '/js/systemversions.js');
            $this->addInlineJS('const versionsSystemTemplate = ' . json_encode($template) . '; ' . $js);
        }

        $navidata->setData($navi);
    }

    public function onPageUpdated($event)
    {
        $this->storePageVersion($event, 'update', ['force_new' => false]);
    }

    public function onPagePublished($event)
    {
        $this->storePageVersion($event, 'publish', ['force_new' => true]);
    }

    public function onPageUnpublished($event)
    {
        $this->storePageVersion($event, 'unpublish', ['force_new' => true]);
    }

    public function onPageDiscard($event)
    {
        $this->storePageVersion($event, 'discard', ['force_new' => true]);
    }

    private function storePageVersion($event, string $action, array $options): void
    {
        $data = $event->getData();
        $item = $data['item'] ?? null;
        if (!$item) {
            return;
        }

        $metadata = $data['metadata'] ?? null;
        if ($metadata === null) {
            $meta = new Meta();
            $metadata = $meta->getMetaData($item) ?: [];
        }

        $markdown = $data['newMarkdown'] ?? $data['markdown'] ?? '';

        $this->getStore()->storeVersion(
            $item,
            $metadata,
            $markdown,
            $data['username'] ?? 'unknown',
            $this->getPluginSettings() ?: [],
            $action,
            $options
        );
    }

    public function getSystemData(Request $request, Response $response, $args)
    {
        $pluginSettings = $this->getPluginSettings() ?: [];
        $store = $this->getStore();

        return $this->jsonResponse($response, [
            'settings' => $store->getSettings($pluginSettings),
            'trash' => $store->listDeletedEntries($pluginSettings),
        ]);
    }

    public function saveSystemSettings(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $settingsPayload = $params['settings'] ?? [];

        $pluginSettings = array_merge($this->getPluginSettings() ?: [], $settingsPayload);
        $pluginSettings['active'] = true;

        $settingsModel = new Settings();
        $result = $settingsModel->updateSettings($pluginSettings, 'plugins', 'versions');
        if (!$result) {
            return $this->jsonResponse($response, [
                'message' => 'versions.msg_settings_save_error',
            ], 500);
        }

        return $this->jsonResponse($response, [
            'message' => 'versions.msg_settings_saved',
            'settings' => $this->getStore()->getSettings($pluginSettings),
        ]);
    }

    public function emptyTrash(Request $request, Response $response, $args)
    {
        $result = $this->getStore()->emptyTrash($this->getPluginSettings() ?: []);

        return $this->jsonResponse($response, [
            'message' => 'versions.msg_trash_emptied',
            'deleted' => $result['deleted'],
        ]);
    }

    public function deleteTrashEntry(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $recordId = $params['record_id'] ?? $params['pageid'] ?? null;
        $recordType = $params['record_type'] ?? 'page';

        if (!$recordId) {
            return $this->jsonResponse($response, ['message' => 'versions.msg_page_id_missing'], 400);
        }

        if (!$this->getStore()->deleteTrashEntry((string) $recordId, (string) $recordType)) {
            return $this->jsonResponse($response, ['message' => 'versions.msg_trash_entry_delete_error'], 500);
        }

        return $this->jsonResponse($response, ['message' => 'versions.msg_trash_entry_deleted']);
    }

    public function restoreTrashEntry(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $recordId = $params['record_id'] ?? $params['pageid'] ?? null;
        $recordType = $params['record_type'] ?? 'page';
        $versionId = $params['version_id'] ?? null;
        $force = !empty($params['force']);

        if (!$recordId || !$versionId) {
            return $this->jsonResponse($response, ['message' => 'versions.msg_restore_data_incomplete'], 400);
        }

        $result = $this->getStore()->restoreDeletedEntry((string) $recordId, (string) $versionId, $force, (string) $recordType);
        if (!$result['success']) {
            $status = isset($result['conflicts']) ? 409 : 500;
            return $this->jsonResponse($response, $result, $status);
        }

        if ($recordType !== 'asset') {
            $this->clearFullNavigationAndSitemap();
        }

        return $this->jsonResponse($response, ['message' => 'versions.msg_page_restored']);
    }

    public function getTrashVersion(Request $request, Response $response, $args)
    {
        $recordId = $request->getQueryParams()['record_id'] ?? $request->getQueryParams()['pageid'] ?? false;
        $recordType = $request->getQueryParams()['record_type'] ?? 'page';
        $versionId = $request->getQueryParams()['version_id'] ?? false;

        if (!$recordId || !$versionId) {
            return $this->jsonResponse($response, ['message' => 'versions.msg_restore_data_incomplete'], 400);
        }

        $detail = $this->getStore()->getTrashVersionDetail((string) $recordId, (string) $versionId, (string) $recordType);
        if (!$detail) {
            return $this->jsonResponse($response, ['message' => 'Version not found.'], 404);
        }

        $detail = $this->getPreviewRenderer()->addRenderedPreview($detail);

        return $this->jsonResponse($response, $detail);
    }

    public function downloadTrashEntry(Request $request, Response $response, $args)
    {
        $recordId = $request->getQueryParams()['record_id'] ?? $request->getQueryParams()['pageid'] ?? false;
        $recordType = $request->getQueryParams()['record_type'] ?? 'page';
        $versionId = $request->getQueryParams()['version_id'] ?? false;

        if (!$recordId || !$versionId) {
            return $this->jsonResponse($response, ['message' => 'versions.msg_restore_data_incomplete'], 400);
        }

        $download = $this->getStore()->createTrashDownloadPackage((string) $recordId, (string) $versionId, (string) $recordType);
        if (!$download) {
            return $this->jsonResponse($response, ['message' => 'Download not available.'], 404);
        }

        $response->getBody()->write($download['content']);

        return $response
            ->withHeader('Content-Type', $download['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace(['"', '\\'], ['\"', '\\\\'], $download['filename']) . '"');
    }

    public function getPageVersions(Request $request, Response $response, $args)
    {
        $url = $request->getQueryParams()['url'] ?? false;
        $resolved = $this->resolveItemAndMeta($response, $url);
        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        $item = $resolved['item'];
        $metadata = $resolved['metadata'];

        if ($permissionResponse = $this->guardPageAccess($request, $response, $metadata, 'read')) {
            return $permissionResponse;
        }

        return $this->jsonResponse($response, $this->getStore()->getPageSummariesByItem($item, $metadata));
    }

    public function getPageVersion(Request $request, Response $response, $args)
    {
        $url = $request->getQueryParams()['url'] ?? false;
        $versionId = $request->getQueryParams()['version_id'] ?? false;

        if (!$versionId) {
            return $this->jsonResponse($response, ['message' => 'Version ID is missing.'], 400);
        }

        $resolved = $this->resolveItemAndMeta($response, $url);
        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        if ($permissionResponse = $this->guardPageAccess($request, $response, $resolved['metadata'], 'read')) {
            return $permissionResponse;
        }

        $pageId = $resolved['metadata']['meta']['pageid'] ?? null;
        if (!$pageId) {
            return $this->jsonResponse($response, ['message' => 'Page ID is missing.'], 404);
        }

        $detail = $this->getStore()->getVersionDetailByPageId($pageId, $versionId);
        if (!$detail) {
            return $this->jsonResponse($response, ['message' => 'Version not found.'], 404);
        }

        $detail = $this->getPreviewRenderer()->addRenderedPreview($detail);

        return $this->jsonResponse($response, $detail);
    }

    public function restorePageVersion(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $url = $params['url'] ?? false;
        $versionId = $params['version_id'] ?? false;
        $username = $request->getAttribute('c_username');

        if (!$versionId) {
            return $this->jsonResponse($response, ['message' => 'Version ID is missing.'], 400);
        }

        $resolved = $this->resolveItemAndMeta($response, $url);
        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        $item = $resolved['item'];
        $metadata = $resolved['metadata'];

        if ($permissionResponse = $this->guardPageAccess($request, $response, $metadata, 'update')) {
            return $permissionResponse;
        }

        $result = $this->getStore()->restoreVersionToCurrentPage($item, $metadata, $versionId);
        if (!$result['success']) {
            return $this->jsonResponse($response, ['message' => $result['message']], 500);
        }

        $meta = new Meta();
        $freshMeta = $meta->getMetaData($item) ?: [];
        $freshMarkdown = $this->getStore()->readCurrentMarkdown($item);

        $this->getStore()->storeVersion(
            $item,
            $freshMeta,
            $freshMarkdown,
            $username,
            $this->getPluginSettings() ?: [],
            'restore',
            ['force_new' => true]
        );

        $navigation = new Navigation();
        $navigation->setProject($this->getSettings(), $url, $this->getDispatcher());
        $naviFileName = $navigation->getNaviFileNameForPath($item->path);
        $navigation->clearNavigation([$naviFileName, $naviFileName . '-extended']);

        return $this->jsonResponse($response, [
            'message' => 'Version restored successfully.',
        ]);
    }

    public function getCurrentMarkdown(Request $request, Response $response, $args)
    {
        $url = $request->getQueryParams()['url'] ?? false;

        $resolved = $this->resolveItemAndMeta($response, $url);
        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        if ($permissionResponse = $this->guardPageAccess($request, $response, $resolved['metadata'], 'read')) {
            return $permissionResponse;
        }

        $markdown = $this->getStore()->readCurrentMarkdown($resolved['item']);

        return $this->jsonResponse($response, [
            'markdown' => $markdown,
        ]);
    }

    public function saveEditedMarkdown(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $url = $params['url'] ?? false;
        $markdown = $params['markdown'] ?? null;
        $username = $request->getAttribute('c_username');

        if ($markdown === null) {
            return $this->jsonResponse($response, ['message' => 'Markdown content is missing.'], 400);
        }

        $resolved = $this->resolveItemAndMeta($response, $url);
        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        $item = $resolved['item'];
        $metadata = $resolved['metadata'];

        if ($permissionResponse = $this->guardPageAccess($request, $response, $metadata, 'update')) {
            return $permissionResponse;
        }

        $content = new Content();
        $markdownArray = $content->markdownTextToArray($markdown);
        $result = $content->saveDraftMarkdown($item, $markdownArray);

        if ($result !== true) {
            return $this->jsonResponse($response, ['message' => is_string($result) ? $result : 'Save failed.'], 500);
        }

        $meta = new Meta();
        $freshMeta = $meta->getMetaData($item) ?: [];

        $this->getStore()->storeVersion(
            $item,
            $freshMeta,
            $markdown,
            $username,
            $this->getPluginSettings() ?: [],
            'update',
            ['force_new' => false]
        );

        return $this->jsonResponse($response, [
            'message' => 'Draft saved successfully.',
        ]);
    }

    public function deleteArticle(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $userrole = $request->getAttribute('c_userrole');
        $username = $request->getAttribute('c_username');
        $url = $params['url'] ?? false;

        $resolved = $this->resolveItemAndMeta($response, $url);
        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        $item = $resolved['item'];
        $metadata = $resolved['metadata'];

        if (
            !$this->userroleIsAllowed($userrole, 'content', 'delete') &&
            !$this->userIsAllowed($username, $metadata)
        ) {
            return $this->jsonResponse($response, [
                'message' => Translations::translate('You do not have enough rights.'),
            ], 403);
        }

        $store = $this->getStore();
        $store->storeVersion(
            $item,
            $metadata,
            $store->readCurrentMarkdown($item),
            $username,
            $this->getPluginSettings() ?: [],
            'delete',
            [
                'force_new' => true,
                'snapshot_files' => $store->createSnapshotFiles($item),
            ]
        );

        $urlinfo = $this->container->get('urlinfo');
        $langattr = $this->getSettings()['langattr'];
        $navigation = new Navigation();
        $navigation->setProject($this->getSettings(), $url, $this->getDispatcher());
        $content = new Content($urlinfo['baseurl'], $this->getSettings(), $this->getDispatcher());

        if (($item->elementType ?? 'file') === 'folder') {
            $result = $content->deleteFolder($item);
        } else {
            $result = $content->deletePage($item);
        }

        if ($result !== true) {
            return $this->jsonResponse($response, ['message' => $result], 500);
        }

        if (count($item->keyPathArray) === 1) {
            $navigation->clearNavigation();
        } else {
            $naviFileName = $navigation->whichNaviToDelete($item->path);
            $navigation->clearNavigation([$naviFileName, $naviFileName . '-extended']);
        }

        $draftNavigation = $navigation->getFullDraftNavigation($urlinfo, $langattr);

        if (!isset($this->getSettings()['disableSitemap']) || !$this->getSettings()['disableSitemap']) {
            $sitemap = new Sitemap();
            $sitemap->updateSitemap($draftNavigation, $urlinfo, $navigation->getProject());
        }

        $userModel = new User();
        $user = $userModel->setUser($username);
        if ($user && $user->getValue('folderaccess')) {
            $draftNavigation = $navigation->getAllowedFolders($draftNavigation, $user->getValue('folderaccess'));
        }

        $redirectUrl = $urlinfo['baseurl'] . '/tm/content/' . $this->getSettings()['editor'];

        if ($navigation->getProject() && count($item->keyPathArray) === 1) {
            $redirectUrl .= '/' . $navigation->getProject();
        }

        if (count($item->keyPathArray) > 1) {
            array_pop($item->keyPathArray);
            $parentItem = $navigation->getItemWithKeyPath($draftNavigation, $item->keyPathArray);
            if ($parentItem) {
                $redirectUrl .= $parentItem->urlRelWoF;
            }
        }

        return $this->jsonResponse($response, ['url' => $redirectUrl]);
    }

    private function resolveItemAndMeta(Response $response, $url): array
    {
        $urlinfo = $this->container->get('urlinfo');
        $langattr = $this->getSettings()['langattr'];
        $navigation = new Navigation();
        $navigation->setProject($this->getSettings(), $url, $this->getDispatcher());
        $item = $navigation->getItemForUrl($url, $urlinfo, $langattr);

        if (!$item) {
            return [
                'response' => $this->jsonResponse($response, [
                    'message' => Translations::translate('page not found'),
                ], 404),
            ];
        }

        $meta = new Meta();
        $metadata = $meta->getMetaData($item) ?: [];

        if (
            empty($metadata['meta']['pageid']) ||
            empty($metadata['meta']['owner']) ||
            empty($metadata['meta']['modified'])
        ) {
            $metadata = $meta->addMetaDefaults($metadata, $item, $this->getSettings()['author'], null);
        }

        return [
            'item' => $item,
            'metadata' => $metadata,
        ];
    }

    private function clearFullNavigationAndSitemap(): void
    {
        $navigation = new Navigation();
        $navigation->clearNavigation();

        if (!isset($this->getSettings()['disableSitemap']) || !$this->getSettings()['disableSitemap']) {
            $urlinfo = $this->container->get('urlinfo');
            $draftNavigation = $navigation->getFullDraftNavigation($urlinfo, $this->getSettings()['langattr']);
            $sitemap = new Sitemap();
            $sitemap->updateSitemap($draftNavigation, $urlinfo, null);
        }
    }

    private function guardPageAccess(Request $request, Response $response, array $metadata, string $action): ?Response
    {
        $username = $request->getAttribute('c_username');
        $userrole = $request->getAttribute('c_userrole');

        if ($this->userroleIsAllowed($userrole, 'content', $action)) {
            return null;
        }

        if ($this->userIsAllowed($username, $metadata)) {
            return null;
        }

        return $this->jsonResponse($response, [
            'message' => Translations::translate('You do not have enough rights.'),
        ], 403);
    }

    private function userroleIsAllowed($userrole, string $resource, string $action): bool
    {
        return $this->container->get('acl')->isAllowed($userrole, $resource, $action);
    }

    private function userIsAllowed($username, array $metadata): bool
    {
        if (!isset($metadata['meta']['owner']) || !$metadata['meta']['owner']) {
            return false;
        }

        $allowedUsers = array_map('trim', explode(',', $metadata['meta']['owner']));

        return in_array($username, $allowedUsers, true);
    }

    private ?VersionStore $store = null;

    private function getStore(): VersionStore
    {
        if ($this->store === null) {
            $this->store = new VersionStore();
        }

        return $this->store;
    }

    private function getPreviewRenderer(): VersionPreviewRenderer
    {
        return new VersionPreviewRenderer($this->getSettings(), $this->urlinfo(), $this->getDispatcher());
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
