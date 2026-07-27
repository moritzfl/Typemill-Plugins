<?php

namespace Plugins\syntax;

use Typemill\Plugin;

/**
 * Frontend syntax highlighting via Shiki (GitHub light + dark).
 *
 * Themes keep the panel chrome. Tokens follow the system scheme, or a theme
 * that sets data-code-tokens="dark" / html.dark when its code panel stays dark
 * in light mode.
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

        $this->addCSS('/syntax/css/syntax.css');
        $this->addJS('/syntax/public/syntax.min.js');
    }
}
