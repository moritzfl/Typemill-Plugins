<?php

namespace Plugins\sitefiles;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Models\Content;
use Typemill\Models\Meta;
use Typemill\Models\Navigation;
use Typemill\Models\StorageWrapper;
use Typemill\Models\Sitemap;
use Typemill\Plugin;

class sitefiles extends Plugin
{
    private array $metaCache = [];

    public static function setPremiumLicense()
    {
        return false;
    }

    public static function getSubscribedEvents()
    {
        return [
            'onPageReady' => ['onPageReady', 0],
        ];
    }

    public static function addNewRoutes()
    {
        return [
            [
                'httpMethod' => 'get',
                'route' => '/robots.txt',
                'name' => 'sitefiles.robots',
                'class' => 'Plugins\sitefiles\sitefiles:robots',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/sitemap.xml',
                'name' => 'sitefiles.sitemap',
                'class' => 'Plugins\sitefiles\sitefiles:sitemap',
            ],
        ];
    }

    public function onPageReady($event): void
    {
        if ($this->adminroute) {
            return;
        }

        $context = $this->resolveCurrentPageContext();
        if ($context === null) {
            return;
        }

        $this->prepareSeo(
            $context['item'],
            $context['metadata'],
            $context['title'],
            $context['breadcrumb'],
            $context['settings'],
            $context['logo'],
            $context['content_html']
        );
    }

    public function robots(Request $request, Response $response, $args)
    {
        $settings = $this->getPluginSettings() ?: [];
        $baseurl = rtrim($this->urlinfo()['baseurl'] ?? '', '/');

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /tm/',
        ];

        $extraRules = trim((string) ($settings['extra_rules'] ?? ''));
        if ($extraRules !== '') {
            $lines[] = '';
            foreach (preg_split('/\R+/', $extraRules) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        if ($baseurl !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $baseurl . '/sitemap.xml';
        }

        $response->getBody()->write(implode("\n", $lines) . "\n");

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function sitemap(Request $request, Response $response, $args)
    {
        $storage = new StorageWrapper('\Typemill\Models\Storage');

        $filename = $this->resolveSitemapFilename($storage);
        $sitemap = $filename ? $storage->getFile('cacheFolder', '', $filename) : false;

        if ($sitemap === false) {
            $this->generateSitemap();
            $filename = $this->resolveSitemapFilename($storage);
            $sitemap = $filename ? $storage->getFile('cacheFolder', '', $filename) : false;
        }

        if ($sitemap === false) {
            $response->getBody()->write('Sitemap is not available.');

            return $response
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/plain; charset=UTF-8');
        }

        $response->getBody()->write($sitemap);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function prepareSeo($item = null, $metatabs = [], $title = '', $breadcrumb = [], $settings = [], $logo = '', $contentHtml = ''): array
    {
        if (!is_array($settings) || empty($settings)) {
            $settings = $this->getSettings();
        }

        $pluginSettings = $this->getPluginSettings() ?: [];
        $meta = $this->extractMeta($metatabs);
        $baseurl = rtrim($this->urlinfo()['baseurl'] ?? '', '/');
        $siteName = $this->normalizeText($settings['title'] ?? '');
        $siteDescription = $this->resolveSiteDescription($item, $settings, $pluginSettings);
        $description = $this->normalizeText($meta['description'] ?? '');

        if ($description === '') {
            $description = $siteDescription;
        }

        $pageTitle = $this->normalizeText($meta['title'] ?? $title);
        if ($pageTitle === '') {
            $pageTitle = $siteName;
        }

        $canonical = '';
        if (is_object($item) && !empty($item->urlAbs)) {
            $canonical = (string) $item->urlAbs;
        }
        if ($canonical === '') {
            $canonical = $baseurl !== '' ? $baseurl . '/' : '/';
        }

        $author = $this->normalizeText($meta['author'] ?? $settings['author'] ?? '');
        $language = $this->normalizeText($settings['langattr'] ?? $settings['language'] ?? '');
        $isHome = $this->isHomeItem($item);
        $isArticle = $this->isArticlePage($item);
        $ogType = $isHome ? 'website' : ($isArticle ? 'article' : 'website');
        $socialImage = $this->resolveSocialImageData($item, $meta, $settings, $pluginSettings, (string) $logo, (string) $contentHtml, $pageTitle);
        $publisher = $this->resolvePublisher($item, $settings, $pluginSettings, $siteName, (string) $logo);

        $seo = [
            'title' => $pageTitle,
            'description' => $description,
            'author' => $author,
            'canonical' => $canonical,
            'site_name' => $siteName,
            'site_description' => $siteDescription,
            'language' => $language,
            'og_type' => $ogType,
            'schema_type' => $this->resolveSchemaType($item, $isArticle),
            'is_home' => $isHome,
            'is_article' => $isArticle,
            'image' => $socialImage,
            'publisher' => $publisher,
        ];

        $this->applySeoMeta($seo, $meta, is_array($breadcrumb) ? $breadcrumb : []);

        return $seo;
    }

    public function resolveHero($item = null, $metatabs = [], $title = ''): array
    {
        $meta = $this->extractMeta($metatabs);
        $hero = $this->resolveHeroImageData($item, $meta, true);

        return [
            'image' => $hero['path'] ?? '',
            'alt' => !empty($hero['alt']) ? $hero['alt'] : $this->normalizeText($title),
            'source' => $hero['source'] ?? null,
        ];
    }

    private function resolveCurrentPageContext(): ?array
    {
        $settings = $this->getSettings();
        $urlinfo = $this->urlinfo();
        $route = $this->normalizeFrontendRoute($urlinfo['route'] ?? '/');
        $contentRoute = $this->stripPaginationSegment($route);
        $langattr = $settings['langattr'] ?? '';

        $navigation = new Navigation();
        $navigation->setProject($settings, $contentRoute, $this->getDispatcher());

        $draftNavigation = $navigation->getFullDraftNavigation($urlinfo, $langattr);
        $item = $navigation->getItemForUrl($contentRoute, $urlinfo, $langattr);

        if (!$item) {
            return null;
        }

        $breadcrumb = [];
        if ($draftNavigation && !empty($item->keyPathArray)) {
            $breadcrumb = $navigation->getBreadcrumb($draftNavigation, $item->keyPathArray) ?: [];
        }

        $content = new Content($urlinfo['baseurl'] ?? '', $settings, $this->getDispatcher());
        $metaModel = new Meta();
        $metadata = $this->loadMetadataForItem($metaModel, $content, $item, $settings['author'] ?? '');
        $markdownArray = $this->loadMarkdownArrayForItem($content, $item);

        if (($metadata['meta']['referencetype'] ?? '') === 'copy' && !empty($metadata['meta']['reference'])) {
            $referenceItem = $this->resolveReferenceItem(
                $navigation,
                $draftNavigation,
                (string) $metadata['meta']['reference'],
                $urlinfo,
                $langattr
            );

            if ($referenceItem) {
                $metadata = $this->loadMetadataForItem($metaModel, $content, $referenceItem, $settings['author'] ?? '');
                $markdownArray = $this->loadMarkdownArrayForItem($content, $referenceItem);
            }
        }

        $title = !empty($markdownArray) ? $content->getTitle($markdownArray) : ($metadata['meta']['title'] ?? '');
        $contentHtml = $this->buildContentHtmlFromMarkdown($content, $markdownArray);

        return [
            'item' => $item,
            'metadata' => $metadata,
            'title' => $this->normalizeText($title),
            'breadcrumb' => is_array($breadcrumb) ? $breadcrumb : [],
            'settings' => $settings,
            'logo' => $this->resolveLogoPath($content, $settings),
            'content_html' => $contentHtml,
        ];
    }

    private function loadMetadataForItem(Meta $metaModel, Content $content, $item, string $defaultAuthor): array
    {
        $markdownArray = $this->loadMarkdownArrayForItem($content, $item);
        $metadata = $metaModel->getMetaData($item);
        $metadata = $metaModel->addMetaDefaults($metadata, $item, $defaultAuthor);

        return $metaModel->addMetaTitleDescription($metadata, $item, $markdownArray);
    }

    private function loadMarkdownArrayForItem(Content $content, $item): array
    {
        $markdown = (string) $content->getLiveMarkdown($item);
        if ($markdown === '') {
            return [];
        }

        return $content->markdownTextToArray($markdown);
    }

    private function buildContentHtmlFromMarkdown(Content $content, array $markdownArray): string
    {
        if (empty($markdownArray)) {
            return '';
        }

        $bodyBlocks = $markdownArray;
        array_shift($bodyBlocks);

        if (empty($bodyBlocks)) {
            return '';
        }

        $bodyMarkdown = $content->markdownArrayToText($bodyBlocks);
        $contentArray = $content->getContentArray($bodyMarkdown);

        return $content->getContentHtml($contentArray);
    }

    private function resolveReferenceItem(Navigation $navigation, $draftNavigation, string $reference, array $urlinfo, string $langattr)
    {
        $pageInfo = $navigation->getPageInfoForUrl($reference, $urlinfo, $langattr);
        if (!$pageInfo || !$draftNavigation) {
            return null;
        }

        $keyPathArray = explode('.', $pageInfo['keyPath']);

        return $navigation->getItemWithKeyPath($draftNavigation, $keyPathArray);
    }

    private function resolveLogoPath(Content $content, array $settings): string
    {
        $logo = trim((string) ($settings['logo'] ?? ''));
        if ($logo === '' || !$content->checkLogoFile($logo)) {
            return '';
        }

        return $logo;
    }

    private function normalizeFrontendRoute($route): string
    {
        $route = is_string($route) ? trim($route) : '/';
        if ($route === '') {
            return '/';
        }

        if (!str_starts_with($route, '/')) {
            $route = '/' . $route;
        }

        return $route;
    }

    private function stripPaginationSegment(string $route): string
    {
        $stripped = preg_replace('#/p/\d+/?$#', '', $route);
        if (!is_string($stripped) || $stripped === '') {
            return '/';
        }

        return $stripped;
    }

    private function generateSitemap(): void
    {
        $settings = $this->getSettings();
        $urlinfo = $this->urlinfo();

        $navigation = new Navigation();
        $liveNavigation = $navigation->getLiveNavigation($urlinfo, $settings['langattr'] ?? '');

        if (!$liveNavigation) {
            return;
        }

        $sitemap = new Sitemap();
        $sitemap->updateSitemap($liveNavigation, $urlinfo);
    }

    private function resolveSitemapFilename(StorageWrapper $storage): ?string
    {
        if ($storage->checkFile('cacheFolder', '', 'sitemap.xml')) {
            return 'sitemap.xml';
        }

        $cacheFolder = rtrim($storage->getFolderPath('cacheFolder'), DIRECTORY_SEPARATOR);
        $matches = glob($cacheFolder . DIRECTORY_SEPARATOR . 'sitemap-*.xml') ?: [];

        if (count($matches) === 1) {
            return basename($matches[0]);
        }

        return null;
    }

    private function extractMeta($metatabs): array
    {
        if (is_array($metatabs) && isset($metatabs['meta']) && is_array($metatabs['meta'])) {
            return $metatabs['meta'];
        }

        return [];
    }

    private function resolveSiteDescription($item, array $settings, array $pluginSettings): string
    {
        $description = $this->normalizeText($pluginSettings['site_description'] ?? '');
        if ($description !== '') {
            return $description;
        }

        $homeMeta = $this->loadHomeMeta($item);
        $description = $this->normalizeText($homeMeta['description'] ?? '');
        if ($description !== '') {
            return $description;
        }

        $theme = $settings['theme'] ?? null;
        if ($theme && !empty($settings['themes'][$theme]['heroSubtitle'])) {
            return $this->normalizeText($settings['themes'][$theme]['heroSubtitle']);
        }

        return '';
    }

    private function resolveSocialImageData($item, array $meta, array $settings, array $pluginSettings, string $logo, string $contentHtml, string $pageTitle): array
    {
        $hero = $this->resolveHeroImageData($item, $meta, true);
        if (!empty($hero['path'])) {
            $alt = !empty($hero['alt']) ? $hero['alt'] : $pageTitle;

            return $this->finalizeImageData($hero['path'], $alt, $hero['source'] ?? 'hero');
        }

        $inline = $this->extractFirstImageFromHtml($contentHtml);
        if (!empty($inline['path'])) {
            $alt = !empty($inline['alt']) ? $inline['alt'] : $pageTitle;

            return $this->finalizeImageData($inline['path'], $alt, 'content');
        }

        $fallback = $this->resolveDefaultShareImage($item, $settings, $pluginSettings, $logo, $pageTitle);
        if (!empty($fallback['path'])) {
            $alt = !empty($fallback['alt']) ? $fallback['alt'] : $pageTitle;

            return $this->finalizeImageData($fallback['path'], $alt, $fallback['source'] ?? 'default');
        }

        return [];
    }

    private function resolveHeroImageData($item, array $meta, bool $inherit): array
    {
        if (!empty($meta['heroimage'])) {
            return [
                'path' => trim((string) $meta['heroimage']),
                'alt' => $this->normalizeText($meta['heroimagealt'] ?? ''),
                'source' => 'page',
            ];
        }

        if (!$inherit || !is_object($item)) {
            return [];
        }

        foreach ($this->getAncestorMetaChain($item, false) as $ancestorMeta) {
            if (!empty($ancestorMeta['heroimage'])) {
                return [
                    'path' => trim((string) $ancestorMeta['heroimage']),
                    'alt' => $this->normalizeText($ancestorMeta['heroimagealt'] ?? ''),
                    'source' => 'ancestor',
                ];
            }
        }

        return [];
    }

    private function resolveDefaultShareImage($item, array $settings, array $pluginSettings, string $logo, string $pageTitle): array
    {
        $defaultImage = trim((string) ($pluginSettings['default_share_image'] ?? ''));
        if ($defaultImage !== '') {
            return [
                'path' => $defaultImage,
                'alt' => $pageTitle,
                'source' => 'plugin-default',
            ];
        }

        $homeMeta = $this->loadHomeMeta($item);
        if (!empty($homeMeta['heroimage'])) {
            return [
                'path' => trim((string) $homeMeta['heroimage']),
                'alt' => $this->normalizeText($homeMeta['heroimagealt'] ?? $pageTitle),
                'source' => 'home',
            ];
        }

        $publisherLogo = trim((string) ($pluginSettings['publisher_logo'] ?? ''));
        if ($publisherLogo !== '') {
            return [
                'path' => $publisherLogo,
                'alt' => $pageTitle,
                'source' => 'publisher-logo',
            ];
        }

        if ($logo !== '') {
            return [
                'path' => $logo,
                'alt' => $pageTitle,
                'source' => 'logo',
            ];
        }

        return [];
    }

    private function resolvePublisher($item, array $settings, array $pluginSettings, string $siteName, string $logo): array
    {
        $type = $this->normalizeText($pluginSettings['publisher_type'] ?? 'Organization');
        if ($type !== 'Person') {
            $type = 'Organization';
        }

        $name = $this->normalizeText($pluginSettings['publisher_name'] ?? '');
        if ($name === '') {
            $name = $siteName;
        }

        $imagePath = trim((string) ($pluginSettings['publisher_logo'] ?? ''));
        if ($imagePath === '' && $logo !== '') {
            $imagePath = $logo;
        }

        return [
            'type' => $type,
            'name' => $name,
            'image' => $imagePath !== '' ? $this->toAbsoluteUrl($imagePath) : '',
            'same_as' => $this->parseSameAs($pluginSettings['same_as'] ?? ''),
        ];
    }

    private function applySeoMeta(array $seo, array $meta, array $breadcrumb): void
    {
        if ($seo['site_name'] !== '') {
            $this->addMeta('og_site_name', '<meta property="og:site_name" content="' . $this->escape($seo['site_name']) . '">');
        }

        if ($seo['title'] !== '') {
            $escapedTitle = $this->escape($seo['title']);
            $this->addMeta('og_title', '<meta property="og:title" content="' . $escapedTitle . '">');
            $this->addMeta('twitter_title', '<meta name="twitter:title" content="' . $escapedTitle . '">');
        }

        if ($seo['description'] !== '') {
            $escapedDescription = $this->escape($seo['description']);
            $this->addMeta('og_description', '<meta property="og:description" content="' . $escapedDescription . '">');
            $this->addMeta('twitter_description', '<meta name="twitter:description" content="' . $escapedDescription . '">');
        }

        if ($seo['og_type'] !== '') {
            $this->addMeta('og_type', '<meta property="og:type" content="' . $this->escape($seo['og_type']) . '">');
        }

        if ($seo['canonical'] !== '') {
            $this->addMeta('og_url', '<meta property="og:url" content="' . $this->escape($seo['canonical']) . '">');
        }

        if ($seo['language'] !== '') {
            $this->addMeta('og_locale', '<meta property="og:locale" content="' . $this->escape($seo['language']) . '">');
        }

        if (!empty($seo['image']['url'])) {
            $this->addMeta('og_image', '<meta property="og:image" content="' . $this->escape($seo['image']['url']) . '">');
            $this->addMeta('twitter_image', '<meta name="twitter:image" content="' . $this->escape($seo['image']['url']) . '">');

            $cardType = 'summary_large_image';
            $this->addMeta('twitter_card', '<meta name="twitter:card" content="' . $cardType . '">');

            if (!empty($seo['image']['alt'])) {
                $escapedAlt = $this->escape($seo['image']['alt']);
                $this->addMeta('og_image_alt', '<meta property="og:image:alt" content="' . $escapedAlt . '">');
                $this->addMeta('twitter_image_alt', '<meta name="twitter:image:alt" content="' . $escapedAlt . '">');
            }
        } else {
            $this->addMeta('twitter_card', '<meta name="twitter:card" content="summary">');
        }

        if ($seo['is_article']) {
            $published = trim((string) ($meta['manualdate'] ?? $meta['created'] ?? ''));
            $modified = trim((string) ($meta['modified'] ?? ''));

            if ($published !== '') {
                $this->addMeta('article_published_time', '<meta property="article:published_time" content="' . $this->escape($published) . '">');
            }
            if ($modified !== '') {
                $this->addMeta('article_modified_time', '<meta property="article:modified_time" content="' . $this->escape($modified) . '">');
            }
            if ($seo['author'] !== '') {
                $this->addMeta('article_author', '<meta property="article:author" content="' . $this->escape($seo['author']) . '">');
            }
        }

        $schema = $this->buildSchema($seo, $meta, $breadcrumb);
        if ($schema !== null) {
            $this->addMeta(
                'sitefiles_schema',
                '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
            );
        }
    }

    private function buildSchema(array $seo, array $meta, array $breadcrumb): ?array
    {
        if ($seo['canonical'] === '' || $seo['site_name'] === '') {
            return null;
        }

        $websiteId = rtrim($this->urlinfo()['baseurl'] ?? '', '/') . '/#website';
        $publisherId = rtrim($this->urlinfo()['baseurl'] ?? '', '/') . '/#publisher';
        $pageId = $seo['canonical'] . '#page';
        $graph = [];

        $website = [
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'url' => rtrim($this->urlinfo()['baseurl'] ?? '', '/') . '/',
            'name' => $seo['site_name'],
        ];

        if ($seo['site_description'] !== '') {
            $website['description'] = $seo['site_description'];
        }
        if ($seo['language'] !== '') {
            $website['inLanguage'] = $seo['language'];
        }
        if (!empty($seo['publisher']['name'])) {
            $website['publisher'] = ['@id' => $publisherId];
        }

        $graph[] = $website;

        if (!empty($seo['publisher']['name'])) {
            $publisher = [
                '@type' => $seo['publisher']['type'],
                '@id' => $publisherId,
                'name' => $seo['publisher']['name'],
                'url' => rtrim($this->urlinfo()['baseurl'] ?? '', '/') . '/',
            ];

            if (!empty($seo['publisher']['same_as'])) {
                $publisher['sameAs'] = $seo['publisher']['same_as'];
            }

            if (!empty($seo['publisher']['image'])) {
                if ($seo['publisher']['type'] === 'Organization') {
                    $publisher['logo'] = [
                        '@type' => 'ImageObject',
                        'url' => $seo['publisher']['image'],
                    ];
                } else {
                    $publisher['image'] = $seo['publisher']['image'];
                }
            }

            $graph[] = $publisher;
        }

        $page = [
            '@type' => $seo['schema_type'],
            '@id' => $pageId,
            'url' => $seo['canonical'],
            'name' => $seo['title'],
            'isPartOf' => ['@id' => $websiteId],
        ];

        if ($seo['description'] !== '') {
            $page['description'] = $seo['description'];
        }
        if ($seo['language'] !== '') {
            $page['inLanguage'] = $seo['language'];
        }
        if (!empty($seo['image']['url'])) {
            $page['image'] = $seo['image']['url'];
        }
        if (!empty($meta['modified'])) {
            $page['dateModified'] = $meta['modified'];
        }
        if (!empty($meta['manualdate']) || !empty($meta['created'])) {
            $page['datePublished'] = $meta['manualdate'] ?? $meta['created'];
        }

        if ($seo['schema_type'] === 'Article') {
            $page['headline'] = $seo['title'];
            $page['mainEntityOfPage'] = $seo['canonical'];
            if (!empty($seo['author'])) {
                $page['author'] = [
                    '@type' => 'Person',
                    'name' => $seo['author'],
                ];
            }
            if (!empty($seo['publisher']['name'])) {
                $page['publisher'] = ['@id' => $publisherId];
            }
        }

        $breadcrumbSchema = $this->buildBreadcrumbSchema($breadcrumb, $seo['canonical']);
        if ($breadcrumbSchema !== null) {
            $page['breadcrumb'] = ['@id' => $breadcrumbSchema['@id']];
            $graph[] = $breadcrumbSchema;
        }

        $graph[] = $page;

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    private function buildBreadcrumbSchema(array $breadcrumb, string $canonical): ?array
    {
        $baseurl = rtrim($this->urlinfo()['baseurl'] ?? '', '/');
        if ($baseurl === '') {
            return null;
        }

        $items = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $baseurl . '/',
            ],
        ];

        $position = 2;
        foreach ($breadcrumb as $crumb) {
            if (!is_object($crumb) || empty($crumb->name)) {
                continue;
            }

            $url = !empty($crumb->urlAbs)
                ? (string) $crumb->urlAbs
                : $this->toAbsoluteUrl((string) ($crumb->urlRel ?? $canonical));

            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $this->normalizeText($crumb->name),
                'item' => $url,
            ];
        }

        if (count($items) < 2) {
            return null;
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    private function isHomeItem($item): bool
    {
        if (!is_object($item) || empty($item->pathWithoutType)) {
            return false;
        }

        return $this->isHomePath((string) $item->pathWithoutType);
    }

    private function isHomePath(string $path): bool
    {
        $segments = $this->splitPath($path);
        if (!empty($segments) && str_starts_with($segments[0], '_')) {
            array_shift($segments);
        }

        return $segments === ['index'];
    }

    private function isArticlePage($item): bool
    {
        if (!is_object($item) || ($item->elementType ?? '') !== 'file' || empty($item->pathWithoutType)) {
            return false;
        }

        foreach ($this->getAncestorMetaChain($item) as $ancestorMeta) {
            if (($ancestorMeta['contains'] ?? null) === 'posts') {
                return true;
            }
        }

        return false;
    }

    private function resolveSchemaType($item, bool $isArticle): string
    {
        if ($this->isHomeItem($item)) {
            return 'WebPage';
        }

        if ($isArticle) {
            return 'Article';
        }

        if (is_object($item) && ($item->elementType ?? '') === 'folder') {
            return 'CollectionPage';
        }

        return 'WebPage';
    }

    private function getAncestorMetaChain($item, bool $includeHome = true): array
    {
        $chain = [];
        foreach ($this->getAncestorIndexPaths($item, $includeHome) as $path) {
            $meta = $this->loadMetaByPath($path);
            if (!empty($meta)) {
                $chain[] = $meta;
            }
        }

        return $chain;
    }

    private function getAncestorIndexPaths($item, bool $includeHome = true): array
    {
        if (!is_object($item) || empty($item->pathWithoutType)) {
            return [];
        }

        $segments = $this->splitPath((string) $item->pathWithoutType);
        if (empty($segments)) {
            return [];
        }

        $projectPrefix = '';
        if (str_starts_with($segments[0], '_')) {
            $projectPrefix = array_shift($segments);
        }

        $isFolderPage = end($segments) === 'index';
        if ($isFolderPage) {
            array_pop($segments);
            array_pop($segments);
        } else {
            array_pop($segments);
        }

        $paths = [];
        while (!empty($segments)) {
            $candidate = $segments;
            $candidate[] = 'index';
            $paths[] = $this->joinPath($candidate, $projectPrefix);
            array_pop($segments);
        }

        if ($includeHome && !$this->isHomeItem($item)) {
            $paths[] = $this->joinPath(['index'], $projectPrefix);
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private function loadHomeMeta($item): array
    {
        $projectPrefix = '';
        if (is_object($item) && !empty($item->pathWithoutType)) {
            $segments = $this->splitPath((string) $item->pathWithoutType);
            if (!empty($segments) && str_starts_with($segments[0], '_')) {
                $projectPrefix = array_shift($segments);
            }
        }

        return $this->loadMetaByPath($this->joinPath(['index'], $projectPrefix));
    }

    private function loadMetaByPath(string $path): array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }

        if (array_key_exists($path, $this->metaCache)) {
            return $this->metaCache[$path];
        }

        $storage = new StorageWrapper('\Typemill\Models\Storage');
        $metadata = $storage->getYaml('contentFolder', '', $path . '.yaml');
        $meta = is_array($metadata) && isset($metadata['meta']) && is_array($metadata['meta'])
            ? $metadata['meta']
            : [];

        $this->metaCache[$path] = $meta;

        return $meta;
    }

    private function splitPath(string $path): array
    {
        return array_values(array_filter(preg_split('#[\\\\/]#', trim($path, '/\\')) ?: []));
    }

    private function joinPath(array $segments, string $projectPrefix = ''): string
    {
        $parts = $segments;
        if ($projectPrefix !== '') {
            array_unshift($parts, $projectPrefix);
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function extractFirstImageFromHtml(string $contentHtml): array
    {
        if ($contentHtml === '') {
            return [];
        }

        if (!preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>/i', $contentHtml, $match)) {
            return [];
        }

        $tag = $match[0];
        $src = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($src === '' || str_starts_with($src, 'data:')) {
            return [];
        }

        $alt = '';
        if (preg_match('/\balt=(["\'])(.*?)\1/i', $tag, $altMatch)) {
            $alt = html_entity_decode($altMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return [
            'path' => $src,
            'alt' => $this->normalizeText($alt),
        ];
    }

    private function finalizeImageData(string $path, string $alt, string $source): array
    {
        $url = $this->toAbsoluteUrl($path);
        if ($url === '') {
            return [];
        }

        return [
            'url' => $url,
            'alt' => trim($alt),
            'source' => $source,
        ];
    }

    private function toAbsoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, 'data:')) {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $baseurl = rtrim($this->urlinfo()['baseurl'] ?? '', '/');
        if ($baseurl === '') {
            return $path;
        }

        return $baseurl . '/' . ltrim($path, '/');
    }

    private function parseSameAs($raw): array
    {
        $items = preg_split('/[\r\n,]+/', trim((string) $raw)) ?: [];
        $sameAs = [];

        foreach ($items as $item) {
            $url = trim($item);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $sameAs[] = $url;
            }
        }

        return array_values(array_unique($sameAs));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function normalizeText($value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value ?? '');
    }

}
