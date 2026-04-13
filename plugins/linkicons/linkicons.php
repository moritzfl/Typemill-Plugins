<?php

namespace Plugins\linkicons;

use Typemill\Plugin;

class linkicons extends Plugin
{
    private const ICON_ONLY_LINK_CLASS = 'link-icon-only';

    public static function getSubscribedEvents()
    {
        return [
            'onSettingsLoaded' => ['onSettingsLoaded', 0],
            'onHtmlLoaded' => ['onHtmlLoaded', 0],
        ];
    }

    // Simple domain-based matching
    private const DOMAINS = [
        'typemill.net'           => 'typemill',
        'github.com'             => 'github',
        'gitlab.com'             => 'gitlab',
        'hub.docker.com'         => 'docker',
        'plugins.jetbrains.com'  => 'jetbrainsmarketplace',
        'codeberg.org'           => 'codeberg',
        'wikipedia.org'          => 'wikipedia',
        'youtube.com'            => 'youtube',
        'youtu.be'               => 'youtube',
        'store.steampowered.com' => 'steam',
        'steamcommunity.com'     => 'steam',
        'apps.apple.com'         => 'appstore',
        'play.google.com'        => 'googleplay',
        'flathub.org'            => 'flathub',
        'apps.microsoft.com'     => 'microsoftstore',
        'xbox.com'               => 'xbox',
        'nintendo.com'           => 'nintendo',
        'playstation.com'        => 'playstation',
        'snapcraft.io'           => 'snapcraft',
        'reddit.com'             => 'reddit',
        'imgur.com'              => 'imgur',
        'pypi.org'               => 'pypi',
        'twitter.com'            => 'twitter',
        'x.com'                  => 'twitter',
        'facebook.com'           => 'facebook',
        'instagram.com'          => 'instagram',
        'linkedin.com'           => 'linkedin',
        'tiktok.com'             => 'tiktok',
        'bsky.app'               => 'bluesky',
        'threads.net'            => 'threads',
        'pinterest.com'          => 'pinterest',
        'discord.com'            => 'discord',
        'discord.gg'             => 'discord',
        'twitch.tv'              => 'twitch',
        'tumblr.com'             => 'tumblr',
        'whatsapp.com'           => 'whatsapp',
        'wa.me'                  => 'whatsapp',
        'telegram.org'           => 'telegram',
        'telegram.me'            => 'telegram',
        't.me'                   => 'telegram',
    ];

    // Pattern-based matching (regex against full URL)
    private const PATTERNS = [
        'rss'      => '/\.(rss|atom)(\?.*)?$|\/(feed|rss|atom)(\/|\?|$)/i',
        'mastodon' => '/mastodon|mstdn|todon|\b(toot|troet)\b|\/@[\w.]+(@[\w.]+)?(\/|$)/i',
        'lemmy'    => '/lemmy|feddit|\/[cu]\/[\w.-]+(@[\w.-]+)?(\/|$)/i',
    ];

    private static $icons = [];

    private static function getServiceSettingKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_values(self::DOMAINS),
            array_keys(self::PATTERNS)
        )));
    }

    private static function getDefaultSettings(): array
    {
        $defaults = [
            'position' => 'before',
            'external' => false,
            'internal' => false,
        ];

        foreach (self::getServiceSettingKeys() as $key) {
            $defaults[$key] = true;
        }

        return $defaults;
    }

    private static function normalizeSettings($settings): array
    {
        return array_merge(
            self::getDefaultSettings(),
            is_array($settings) ? $settings : []
        );
    }

    public function onSettingsLoaded($event): void
    {
        $settings = $event->getData();

        if (!is_array($settings)) {
            return;
        }

        $pluginSettings = $settings['plugins']['linkicons'] ?? [];
        $normalizedSettings = self::normalizeSettings($pluginSettings);

        if ($pluginSettings === $normalizedSettings) {
            return;
        }

        $settings['plugins']['linkicons'] = $normalizedSettings;
        $this->container->set('settings', $settings);
        $event->setData($settings);
    }

    private static function normalizeHost(?string $host): ?string
    {
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower(rtrim($host, '.'));
    }

    private static ?string $currentHostCache = null;
    private static bool $currentHostResolved = false;

    private static function getCurrentHost(): ?string
    {
        if (!self::$currentHostResolved) {
            $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $parsedHost = (is_string($serverHost) && $serverHost !== '')
                ? parse_url('http://' . $serverHost, PHP_URL_HOST)
                : null;
            self::$currentHostCache    = self::normalizeHost(is_string($parsedHost) ? $parsedHost : null);
            self::$currentHostResolved = true;
        }

        return self::$currentHostCache;
    }

    private static function getUrlHost(string $url): ?string
    {
        $absoluteUrl = strpos($url, '//') === 0 ? 'http:' . $url : $url;
        $parsedHost  = parse_url($absoluteUrl, PHP_URL_HOST);
        return self::normalizeHost(is_string($parsedHost) ? $parsedHost : null);
    }

    private static function isHttpAbsoluteOrProtocolRelative(string $url): bool
    {
        return preg_match('/^(https?:)?\/\//i', $url) === 1;
    }

    private static function hasUrlScheme(string $url): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+\-.]*:/', $url) === 1;
    }

    private static function isInternalUrl(string $url, ?string $currentHost): bool
    {
        if (self::hasUrlScheme($url) && !preg_match('/^https?:/i', $url)) {
            return false;
        }

        if (!self::isHttpAbsoluteOrProtocolRelative($url)) {
            return true;
        }

        if ($currentHost === null) {
            return false;
        }

        $urlHost = self::getUrlHost($url);
        return $urlHost !== null && $urlHost === $currentHost;
    }

    private static function isExternalUrl(string $url, ?string $currentHost): bool
    {
        if (!self::isHttpAbsoluteOrProtocolRelative($url)) {
            return false;
        }

        if ($currentHost === null) {
            return true;
        }

        $urlHost = self::getUrlHost($url);
        return $urlHost === null || $urlHost !== $currentHost;
    }

    private static function loadIcon(string $key): string
    {
        if (!isset(self::$icons[$key])) {
            $file = __DIR__ . '/icons/' . $key . '.svg';
            self::$icons[$key] = is_file($file) ? trim(file_get_contents($file)) : '';
        }
        return self::$icons[$key];
    }

    private static function addOrAppendSvgStyle(string $svg, string $style): string
    {
        if (preg_match('/<svg\b[^>]*\bstyle=(["\'])(.*?)\1/i', $svg, $m)) {
            $existingStyle = trim($m[2]);
            $mergedStyle = $existingStyle === '' ? $style : rtrim($existingStyle, ';') . ';' . $style;
            return preg_replace(
                '/(<svg\b[^>]*\bstyle=)(["\'])(.*?)\2/i',
                '$1$2' . $mergedStyle . '$2',
                $svg,
                1
            );
        }

        return preg_replace('/<svg\b([^>]*)>/i', '<svg$1 style="' . $style . '">', $svg, 1);
    }

    private static function prepareIconMarkup(string $icon, bool $before, bool $iconOnly): string
    {
        $styles = [
            'display:inline-block',
            'width:1em',
            'height:1em',
            'vertical-align:-0.15em',
            'flex:none',
            'fill:currentColor',
        ];

        if (!$iconOnly) {
            $styles[] = $before ? 'margin-right:0.3em' : 'margin-left:0.3em';
        }

        return self::addOrAppendSvgStyle($icon, implode(';', $styles) . ';');
    }

    private static function addClassToAnchorAttrs(string $attrs, string $class): string
    {
        if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', $attrs, $m)) {
            if (preg_match('/(?:^|\s)' . preg_quote($class, '/') . '(?:\s|$)/', $m[1])) {
                return $attrs;
            }
            return preg_replace(
                '/\bclass\s*=\s*"([^"]*)"/i',
                'class="$1 ' . $class . '"',
                $attrs,
                1
            );
        }

        if (preg_match("/\bclass\s*=\s*'([^']*)'/i", $attrs, $m)) {
            if (preg_match('/(?:^|\s)' . preg_quote($class, '/') . '(?:\s|$)/', $m[1])) {
                return $attrs;
            }
            return preg_replace(
                "/\bclass\s*=\s*'([^']*)'/i",
                "class='$1 " . $class . "'",
                $attrs,
                1
            );
        }

        return $attrs . ' class="' . $class . '"';
    }

    public function onHtmlLoaded($event)
    {
        $html = $event->getData();

        if (!is_string($html)) {
            return;
        }

        $settings        = self::normalizeSettings($this->getPluginSettings());
        $enabledDomains  = [];
        $enabledPatterns = [];
        $fallbackExternal = !empty($settings['external']);
        $fallbackInternal = !empty($settings['internal']);
        $before          = ($settings['position'] ?? 'before') !== 'after';

        foreach (self::DOMAINS as $domain => $key) {
            if (!empty($settings[$key])) {
                $enabledDomains[$domain] = $key;
            }
        }

        foreach (self::PATTERNS as $key => $pattern) {
            if (!empty($settings[$key])) {
                $enabledPatterns[$key] = $pattern;
            }
        }

        if (empty($enabledDomains) && empty($enabledPatterns) && !$fallbackExternal && !$fallbackInternal) {
            return;
        }

        if (strpos($html, '<a') === false) {
            return;
        }

        $currentHost = self::getCurrentHost();
        $modified = false;

        $html = preg_replace_callback(
            '/<a(\s[^>]*)>(.*?)<\/a>/is',
            function ($m) use ($enabledDomains, $enabledPatterns, $fallbackExternal, $fallbackInternal, $before, $currentHost, &$modified) {
                $attrs   = $m[1];
                $content = $m[2];
                $textContent = trim(strip_tags($content));
                $hasHtmlElements = preg_match('/<[^>]+>/', $content) === 1;
                $isEmptyTextOnlyLink = ($textContent === '' && !$hasHtmlElements);

                if (strpos($content, 'link-svc-icon') !== false) {
                    return $m[0];
                }

                // Skip non-text links that already contain elements (e.g. images, SVGs)
                if ($textContent === '' && $hasHtmlElements) {
                    return $m[0];
                }

                if (!preg_match('/href=["\']([^"\']+)["\']/i', $attrs, $href)) {
                    return $m[0];
                }

                $url  = $href[1];
                $icon = null;

                foreach ($enabledDomains as $domain => $key) {
                    if (preg_match('/\/\/(?:[\w.-]+\.)?' . preg_quote($domain, '/') . '(?:\/|:|$)/i', $url)) {
                        $icon = self::loadIcon($key);
                        break;
                    }
                }

                if (!$icon) {
                    foreach ($enabledPatterns as $key => $pattern) {
                        if (preg_match($pattern, $url)) {
                            $icon = self::loadIcon($key);
                            break;
                        }
                    }
                }

                if (!$icon) {
                    if ($fallbackExternal && self::isExternalUrl($url, $currentHost)) {
                        $icon = self::loadIcon('external');
                    } elseif ($fallbackInternal && self::isInternalUrl($url, $currentHost)) {
                        $icon = self::loadIcon('internal');
                    }
                }

                if (!$icon) {
                    return $m[0];
                }

                $icon = self::prepareIconMarkup($icon, $before, $isEmptyTextOnlyLink);
                $modified = true;
                if ($isEmptyTextOnlyLink) {
                    $attrs = self::addClassToAnchorAttrs($attrs, self::ICON_ONLY_LINK_CLASS);
                    return '<a' . $attrs . '>' . $icon . '</a>';
                }

                return $before
                    ? '<a' . $attrs . '>' . $icon . $content . '</a>'
                    : '<a' . $attrs . '>' . $content . $icon . '</a>';
            },
            $html
        );

        if ($modified) {
            $event->setData($html);
        }
    }
}
