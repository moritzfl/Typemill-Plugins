<?php

namespace Plugins\linkbuttons;

use Typemill\Plugin;

class linkbuttons extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onHtmlLoaded' => ['onHtmlLoaded', 0],
        ];
    }

    private const CSS = '
.link-button{
    display:inline-flex;align-items:center;gap:0.4em;
    padding:0.45em 1.1em;
    background:#3893F8;color:#fff!important;
    border-radius:0.4em;font-weight:500;font-size:0.9em;
    text-decoration:none!important;
    transition:background 0.15s;
}
.link-button:hover{background:#2563eb;}
';

    public function onHtmlLoaded($event)
    {
        $html = $event->getData();

        if (!is_string($html) || strpos($html, ']') === false) {
            return;
        }

        $modified = false;

        $html = preg_replace_callback(
            '/\[<a(\s[^>]*)>(.*?)<\/a>\]/is',
            function ($m) use (&$modified) {
                $attrs   = $m[1];
                $content = $m[2];

                if (preg_match('/class=["\']([^"\']*)["\']/', $attrs, $cls)) {
                    $attrs = str_replace($cls[0], 'class="' . trim($cls[1] . ' link-button') . '"', $attrs);
                } else {
                    $attrs .= ' class="link-button"';
                }

                $modified = true;
                return '<a' . $attrs . '>' . $content . '</a>';
            },
            $html
        );

        if ($modified) {
            $this->addInlineCSS(self::CSS);
            $event->setData($html);
        }
    }
}
