<?php

namespace Plugins\syntax;

use Typemill\Plugin;

/**
 * Frontend syntax highlighting via Shiki (GitHub light + dark).
 *
 * Themes keep the panel chrome. Tokens follow the system scheme, or a theme
 * that sets data-code-tokens="dark" / html.dark when its code panel stays dark
 * in light mode. An optional copy button is painted by the same client script.
 */
class syntax extends Plugin
{
    public static function setPremiumLicense()
    {
        return false;
    }

    public static function getSubscribedEvents()
    {
        return [
            'onTwigLoaded' => 'onTwigLoaded',
        ];
    }

    public function onTwigLoaded()
    {
        if ($this->adminroute) {
            return;
        }

        $settings = $this->getPluginSettings();
        $copy = !isset($settings['copyButton']) || $settings['copyButton'] === 'true';

        $this->addCSS('/syntax/css/syntax.css');
        $this->addInlineJS(
            'window.__SYNTAX__=' . json_encode(
                [
                    'copy' => $copy,
                    'labels' => $this->labels(),
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . ';'
        );
        $this->addJS('/syntax/public/syntax.min.js');
    }

    /**
     * Wording for the copy control on the public page.
     *
     * Typemill's translator only loads plugin language files in the admin, so
     * on the frontend the keys would come back as the label. The files are
     * read here instead, the same way readmemd does for its repository link.
     *
     * @return array{copy: string, copied: string, failed: string}
     */
    private function labels(): array
    {
        $fallback = [
            'copy' => 'Copy code',
            'copied' => 'Copied',
            'failed' => 'Copy failed',
        ];

        $language = strtolower((string) ($this->getSettings()['language'] ?? 'en'));
        $candidates = [];
        if (preg_match('/^([a-z]{2})(?:[_-][a-z]{2})?$/', $language, $match) === 1) {
            $candidates[] = $match[1];
        }
        $candidates[] = 'en';

        $keys = [
            'copy' => 'SYNTAX_COPY',
            'copied' => 'SYNTAX_COPIED',
            'failed' => 'SYNTAX_COPY_FAILED',
        ];

        $out = $fallback;
        foreach ($candidates as $candidate) {
            $file = __DIR__ . DIRECTORY_SEPARATOR . $candidate . '.yaml';
            if (!is_file($file)) {
                continue;
            }
            $parsed = $this->parseLanguageFile($file);
            foreach ($keys as $field => $key) {
                if (isset($parsed[$key]) && $parsed[$key] !== '') {
                    $out[$field] = $parsed[$key];
                }
            }
            break;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function parseLanguageFile(string $file): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+):\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $value = trim(trim($match[2]), '"\'');
            if ($value !== '') {
                $out[$match[1]] = $value;
            }
        }

        return $out;
    }
}
