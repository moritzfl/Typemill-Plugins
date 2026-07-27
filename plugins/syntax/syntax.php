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
                ['copy' => $copy],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . ';'
        );
        $this->addJS('/syntax/public/syntax.min.js');
    }
}
