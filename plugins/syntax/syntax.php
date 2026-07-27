<?php

namespace Plugins\syntax;

use Typemill\Plugin;

/**
 * Frontend syntax highlighting via Shiki.
 *
 * Themes keep the panel chrome. Tokens follow a chosen light/dark pair under
 * the system scheme, or a theme that sets data-code-tokens="dark" / html.dark
 * when its code panel stays dark in light mode. Optional copy button, line
 * numbers and word wrap are painted by the same client script.
 */
class syntax extends Plugin
{
    /** @var list<string> */
    private const PAIRS = [
        'github-hc',
        'github',
        'one',
        'catppuccin',
        'vitesse',
        'rose-pine',
        'solarized',
        'gruvbox',
    ];

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

        $settings = $this->getPluginSettings() ?: [];
        $pair = (string) ($settings['pair'] ?? 'github-hc');
        if (!in_array($pair, self::PAIRS, true)) {
            $pair = 'github-hc';
        }

        $this->addCSS('/syntax/css/syntax.css');
        $this->addInlineJS(
            'window.__SYNTAX__=' . json_encode(
                [
                    'pair' => $pair,
                    'copy' => $this->flag($settings, 'copyButton', true),
                    'lines' => $this->flag($settings, 'lineNumbers', false),
                    'wrap' => $this->flag($settings, 'wordWrap', false),
                    'labels' => $this->labels(),
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . ';'
        );
        $this->addJS('/syntax/public/syntax.min.js');
    }

    /**
     * Checkbox that defaults on or off when the key has never been saved.
     *
     * @param array<string, mixed> $settings
     */
    private function flag(array $settings, string $key, bool $default): bool
    {
        return array_key_exists($key, $settings)
            ? !empty($settings[$key])
            : $default;
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
