<?php

namespace Plugins\githubreadme;

use Plugins\githubreadme\Models\GitHubClient;
use Plugins\githubreadme\Models\ReadmeCache;
use Plugins\githubreadme\Models\ReadmeRenderer;
use Plugins\githubreadme\Models\ReadmeSource;
use Plugins\githubreadme\Models\RepositoryReference;
use Typemill\Models\StorageWrapper;
use Typemill\Plugin;

/**
 * Fills a page with a repository's readme, so the text is written once and lives
 * where the code lives.
 *
 * A page names a repository in its meta tab and is otherwise left empty. On the
 * frontend the readme is fetched, kept on disk, and rendered into the page.
 *
 * Two details of Typemill shape how this is done. The frontend dispatches
 * `onMarkdownLoaded` *before* the page's meta is read, so the repository is not
 * known yet at that point; the meta arrives with `onMetaLoaded`, and the content
 * can still be changed afterwards at `onHtmlLoaded`. The readme is therefore
 * rendered to HTML by the plugin rather than handed to the core as Markdown.
 */
class githubreadme extends Plugin
{
    /** Where the fallback copies live, under Typemill's data folder. */
    private const CACHE_FOLDER = 'githubreadme';

    /** The meta tab this plugin adds, and the fields on it. */
    private const META_TAB = 'github';

    private ?array $pagemeta = null;

    public static function getSubscribedEvents()
    {
        return [
            // The meta carries the repository, and arrives before the HTML.
            'onMetaLoaded' => ['onMetaLoaded', 0],
            'onHtmlLoaded' => ['onHtmlLoaded', 0],
        ];
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
        $reference = $this->referenceFromMeta();

        if ($reference === null) {
            return;
        }

        $settings = $this->getPluginSettings();
        $pagesettings = $this->pagemeta[self::META_TAB] ?? [];

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

        $readme = '<div class="github-readme"'
            . ' data-repository="' . htmlspecialchars($result['slug'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-origin="' . htmlspecialchars($result['origin'], ENT_QUOTES, 'UTF-8') . '"'
            . ($result['stale'] ? ' data-stale="true"' : '')
            . '>'
            . $html
            . '</div>';

        $position = (string) ($pagesettings['position'] ?? 'replace');

        $combined = match ($position) {
            'append' => $pagehtml . $readme,
            'prepend' => $readme . $pagehtml,
            default => $readme,
        };

        $event->setData($combined . $this->diagnostics($result));
    }

    /** The page's repository field, once, and only if it names a repository. */
    private function referenceFromMeta(): ?RepositoryReference
    {
        $fields = $this->pagemeta[self::META_TAB] ?? null;

        if (!is_array($fields)) {
            return null;
        }

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

    private function source(array $settings): ReadmeSource
    {
        $storage = new StorageWrapper('\Typemill\Models\Storage');
        $storage->createFolder('dataFolder', self::CACHE_FOLDER);

        $cache = new ReadmeCache($storage->getFolderPath('dataFolder', self::CACHE_FOLDER));

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

        return "\n<!-- github-readme: " . htmlspecialchars(
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
            '[githubreadme] %s: %s%s',
            $result['slug'],
            $result['failure'],
            $result['markdown'] === null
                ? ' No stored copy, so the page keeps its own content.'
                : ' Serving the stored copy.'
        ));
    }
}
