<?php

namespace Plugins\htmldeveloper;

use Typemill\Plugin;

class htmldeveloper extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onCspLoaded'  => ['onCspLoaded', 0],
            'onHtmlLoaded' => ['onHtmlLoaded', 0],
        ];
    }

    /**
     * Add domains to Typemill's Content Security Policy so that external
     * resources inside rawhtml blocks are allowed to load.
     *
     * Configure allowed domains in the plugin settings in the Typemill admin
     * (Plugins → HTML Developer Mode → Allowed External Domains).
     *
     * One domain per line, or comma-separated. Use 'https:' to allow all
     * external HTTPS resources at once.
     */
    public function onCspLoaded($event)
    {
        $settings = $this->getPluginSettings();
        $raw      = isset($settings['csp_domains']) ? trim($settings['csp_domains']) : '';

        if ($raw === '') {
            return;
        }

        $domains = array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $raw))));

        $event->setData(array_merge($event->getData(), $domains));
    }

    /**
     * Find every <pre><code class="language-rawhtml">…</code></pre> block
     * in the final HTML and replace it with the decoded raw HTML content.
     *
     * Parsedown always renders fenced code blocks as <pre><code> regardless
     * of safe mode, so this is the most reliable interception point.
     */
    public function onHtmlLoaded($event)
    {
        $html = $event->getData();

        if (!is_string($html) || strpos($html, 'language-rawhtml') === false) {
            return;
        }

        $result = preg_replace_callback(
            '/<pre[^>]*>\s*<code[^>]*class="[^"]*language-rawhtml[^"]*"[^>]*>(.*?)<\/code>\s*<\/pre>/s',
            function ($matches) {
                return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $html
        );

        if ($result !== null && $result !== $html) {
            $event->setData($result);
        }
    }
}
