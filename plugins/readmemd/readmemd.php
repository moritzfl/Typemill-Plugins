<?php

namespace Plugins\readmemd;

use Plugins\readmemd\Models\GitHubClient;
use Plugins\readmemd\Models\ReadmeCache;
use Plugins\readmemd\Models\ReadmeRenderer;
use Plugins\readmemd\Models\ReadmeSource;
use Plugins\readmemd\Models\RepositoryLink;
use Plugins\readmemd\Models\RepositoryReference;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Models\StorageWrapper;
use Typemill\Plugin;

/**
 * Fills a page with a repository's readme, so the text is written once and lives
 * where the code lives.
 *
 * A page names a repository in its meta tab and is otherwise left empty. On the
 * frontend the readme is fetched, kept on disk, and rendered into the page.
 *
 * GitHub is the only forge it can read today, which is why the one class that
 * speaks to a forge is called GitHubClient and nothing else is. The plugin, its
 * settings and its fields are named after what they do - a readme - so that
 * Codeberg or GitLab can be added beside it rather than around it.
 *
 * Two details of Typemill shape how this is done. The frontend dispatches
 * `onMarkdownLoaded` *before* the page's meta is read, so the repository is not
 * known yet at that point; the meta arrives with `onMetaLoaded`, and the content
 * can still be changed afterwards at `onHtmlLoaded`. The readme is therefore
 * rendered to HTML by the plugin rather than handed to the core as Markdown.
 */
class readmemd extends Plugin
{
    /** Where the fallback copies live, under Typemill's data folder. */
    private const CACHE_FOLDER = 'readmemd';

    /**
     * What the plugin was called when it was tied to one forge.
     *
     * Kept so that a site which used it under that name keeps working: the pages
     * still carry their settings under the old tab, and the stored copies - the
     * whole reason a page survives GitHub being down - are still on disk under
     * the old folder.
     */
    private const FORMER_NAME = 'githubreadme';

    private const FORMER_META_TAB = 'github';

    /**
     * What a readme needs that an article does not.
     *
     * A theme styles the pictures in an article as photographs: full width of
     * the column, one per line. A readme's pictures are mostly badges and icons
     * that belong at their own size, several to a line - so they are put back to
     * their natural size, and only a picture that is alone in its block is
     * treated as a figure. Alignment written onto a cell or a table is honoured
     * whatever the theme says, because in a readme it is the author speaking.
     *
     * Tables are held to being tables. A theme may well make them display:block
     * to stop a wide one pushing the page sideways - Typemill\'s own themes did
     * exactly that - and a table that is a block has lost its layout: its cells
     * no longer share a row and a centred table sits at the left. The scrolling
     * belongs on the .tm-table wrapper instead, which is added around every table
     * here, so the plugin does not depend on the theme having worked that out.
     *
     * This stylesheet is served after the theme\'s, so these rules win the ties.
     */
    private const CSS = '
.readme-md img{max-width:100%;height:auto;display:inline-block;vertical-align:middle}
.readme-md p>img:only-child,.readme-md figure>img:only-child{display:block;margin-inline:auto}
.readme-md .tm-table{max-width:100%;overflow-x:auto}
.readme-md table{display:table;width:auto;max-width:100%;border-collapse:collapse}
.readme-md table[align="center"]{margin-inline:auto}
.readme-md th[align="left"],.readme-md td[align="left"]{text-align:left}
.readme-md th[align="center"],.readme-md td[align="center"]{text-align:center}
.readme-md th[align="right"],.readme-md td[align="right"]{text-align:right}
.readme-md p[align="center"],.readme-md div[align="center"]{text-align:center}
.readme-md p[align="right"],.readme-md div[align="right"]{text-align:right}
';

    /** The meta tab this plugin adds, and the fields on it. */
    private const META_TAB = 'readme';

    private ?array $pagemeta = null;

    public static function getSubscribedEvents()
    {
        return [
            // The meta carries the repository, and arrives before the HTML.
            'onMetaLoaded' => ['onMetaLoaded', 0],
            'onHtmlLoaded' => ['onHtmlLoaded', 0],
            // Puts the refresh button into the page's readme tab.
            'onTwigLoaded' => ['onTwigLoaded', 0],
        ];
    }

    /**
     * The button that fetches the readme again straight away.
     *
     * Typemill's editor renders a meta tab with the Vue component named after it
     * when one is registered, and falls back to its own generic form otherwise.
     * So a component named tab-readme takes over this tab - and it renders that
     * same generic form inside itself, so the fields keep being drawn and saved
     * by the core, with only the button added.
     */
    public function onTwigLoaded($event)
    {
        if ($this->editorroute) {
            $this->addInlineJS(file_get_contents(__DIR__ . '/js/editor-readmemd.js'));
        }
    }

    public static function addNewRoutes()
    {
        return [
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/readmemd/refresh',
                'name' => 'readmemd.refresh',
                'class' => 'Plugins\readmemd\readmemd:refreshReadme',
                // Whoever may change a page's content may refetch what fills it.
                'resource' => 'content',
                'privilege' => 'update',
            ],
        ];
    }

    /**
     * Fetch the readme now for the repository the tab is showing.
     *
     * The values come from the form rather than from the saved page, so the
     * button reports on what the author is looking at - including a repository
     * they have just typed and not saved yet.
     */
    public function refreshReadme(Request $request, Response $response, $args)
    {
        $params = (array) $request->getParsedBody();

        $reference = RepositoryReference::parse(
            trim((string) ($params['repository'] ?? '')),
            trim((string) ($params['branch'] ?? '')),
            trim((string) ($params['path'] ?? ''))
        );

        if ($reference === null) {
            return $this->jsonResponse($response, ['ok' => false, 'reason' => 'invalid'], 422);
        }

        $result = $this->source($this->getPluginSettings())->refresh($reference);

        return $this->jsonResponse($response, [
            'ok' => $result['origin'] === 'network',
            'origin' => $result['origin'],
            'repository' => $result['slug'],
            'bytes' => $result['markdown'] === null ? 0 : strlen($result['markdown']),
            'kept' => $result['origin'] === 'cache',
            'failure' => $result['failure'],
        ]);
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public function onMetaLoaded($event)
    {
        $meta = $event->getData();

        if (is_array($meta)) {
            $this->pagemeta = $meta;
        }
    }

    public function onHtmlLoaded($event)
    {
        $pagesettings = $this->pageFields();
        $reference = $this->referenceFromMeta($pagesettings);

        if ($reference === null) {
            return;
        }

        $settings = $this->getPluginSettings();

        $result = $this->source($settings)->markdownFor($reference);
        $html = '';

        if ($result['markdown'] !== null && $result['markdown'] !== '') {
            $renderer = new ReadmeRenderer(fn (string $markdown): string => $this->markdownToHtml($markdown));

            $html = $renderer->toHtml(
                $result['markdown'],
                $reference,
                (bool) ($pagesettings['droptitle'] ?? true),
                !empty($settings['allow_html'])
            );
        }

        $this->reportFailure($result);

        $pagehtml = (string) $event->getData();

        if ($html === '') {
            // Nothing fetched and nothing stored: the page keeps whatever it has,
            // so a reader still gets a page.
            $event->setData($pagehtml . $this->diagnostics($result));

            return;
        }

        $this->addInlineCSS(self::CSS);

        // A readme seldom links to its own repository, so the way back is added
        // here - at the top by default, where a reader looks for it.
        $link = $this->repositoryLink($reference, $settings, $pagesettings);

        $readme = '<div class="readme-md"'
            . ' data-repository="' . htmlspecialchars($result['slug'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-origin="' . htmlspecialchars($result['origin'], ENT_QUOTES, 'UTF-8') . '"'
            . ($result['stale'] ? ' data-stale="true"' : '')
            . '>'
            . $link['start']
            . $html
            . $link['end']
            . '</div>';

        $position = (string) ($pagesettings['position'] ?? 'replace');

        $combined = match ($position) {
            'append' => $pagehtml . $readme,
            'prepend' => $readme . $pagehtml,
            default => $readme,
        };

        $event->setData($combined . $this->diagnostics($result));
    }

    /**
     * The page's own settings for this plugin.
     *
     * A page saved while the plugin was named after GitHub keeps its fields under
     * that tab, and Typemill has no reason to move them. They are read here so
     * that renaming the plugin does not empty a page that was already working.
     *
     * The new tab wins whenever it is present, even empty: otherwise an author
     * who opens the page, clears the repository and saves would still see the
     * readme, because Typemill only rewrites the tab that was just saved and the
     * old block would keep answering.
     *
     * @return array<string, mixed>
     */
    private function pageFields(): array
    {
        $current = $this->pagemeta[self::META_TAB] ?? null;

        if (is_array($current)) {
            return $current;
        }

        $former = $this->pagemeta[self::FORMER_META_TAB] ?? null;

        return is_array($former) ? $former : [];
    }

    /** The page's repository field, once, and only if it names a repository. */
    private function referenceFromMeta(?array $fields = null): ?RepositoryReference
    {
        $fields ??= $this->pageFields();

        $repository = trim((string) ($fields['repository'] ?? ''));

        if ($repository === '') {
            return null;
        }

        return RepositoryReference::parse(
            $repository,
            trim((string) ($fields['branch'] ?? '')),
            trim((string) ($fields['path'] ?? ''))
        );
    }

    /**
     * The link to the repository, at whichever end of the readme it was asked
     * for.
     *
     * Whether it appears is the page's business, because it is the page that
     * names a repository. Only the wording is a plugin setting: it reads the
     * same on every page, and repeating it on each one would be a copy to keep
     * in step for nothing.
     *
     * @return array{start: string, end: string}
     */
    private function repositoryLink(RepositoryReference $reference, array $settings, array $pagesettings): array
    {
        $place = trim((string) ($pagesettings['sourcelink'] ?? ''));
        $place = $place !== '' ? $place : 'start';

        if ($place === 'none') {
            return ['start' => '', 'end' => ''];
        }

        $html = (new RepositoryLink(__DIR__))->html(
            $reference,
            (string) ($this->getSettings()['language'] ?? 'en'),
            (string) ($settings['link_label'] ?? '')
        );

        return $place === 'end'
            ? ['start' => '', 'end' => $html]
            : ['start' => $html, 'end' => ''];
    }

    private function source(array $settings): ReadmeSource
    {
        $storage = new StorageWrapper('\Typemill\Models\Storage');
        $storage->createFolder('dataFolder', self::CACHE_FOLDER);

        $folder = rtrim($storage->getFolderPath('dataFolder', self::CACHE_FOLDER), DIRECTORY_SEPARATOR);

        // A site that used this plugin under its former name keeps its stored
        // copies, which are what carry a page when GitHub cannot be reached.
        $cache = new ReadmeCache($folder, dirname($folder) . DIRECTORY_SEPARATOR . self::FORMER_NAME);

        $client = new GitHubClient(
            (string) ($settings['api_base'] ?? GitHubClient::DEFAULT_API_BASE),
            isset($settings['token']) ? (string) $settings['token'] : null,
            (int) ($settings['timeout_seconds'] ?? 5)
        );

        return new ReadmeSource($cache, $client, (int) ($settings['fresh_minutes'] ?? 60));
    }

    /**
     * Why the page is showing a stored copy, written where it can be read
     * without being shown to a reader.
     *
     * Nothing visible is added on purpose. A visitor is not helped by a sentence
     * about a rate limit, and there is no way to address an editor here either:
     * Typemill starts a session only under /tm, /api and /setup, and loads plugin
     * translations only in the admin environment, so on a public page this
     * plugin can neither tell who is looking nor say anything in their language.
     * The page source and the server log are what remain, and both are read by
     * exactly the person who would act on this.
     */
    private function diagnostics(array $result): string
    {
        if ($result['failure'] === null) {
            return '';
        }

        $age = $result['age'] === null ? 'never fetched' : (int) round($result['age'] / 60) . ' minutes old';

        return "\n<!-- readme-md: " . htmlspecialchars(
            $result['slug'] . ' — ' . $result['failure'] . ' (stored copy: ' . $age . ')',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . " -->\n";
    }

    /**
     * Put a failed fetch in the server log, once.
     *
     * Only when GitHub was actually asked: during the quiet minutes after a
     * failure the same reason is still being reported, and logging it on every
     * page view would bury the log in it.
     */
    private function reportFailure(array $result): void
    {
        if ($result['failure'] === null || !$result['attempted']) {
            return;
        }

        error_log(sprintf(
            '[readmemd] %s: %s%s',
            $result['slug'],
            $result['failure'],
            $result['markdown'] === null
                ? ' No stored copy, so the page keeps its own content.'
                : ' Serving the stored copy.'
        ));
    }
}
