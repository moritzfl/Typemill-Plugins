<?php

namespace Plugins\preview;

use Plugins\preview\Models\MarkdownPreviewRenderer;
use Plugins\preview\Models\PreviewMetaResolver;
use Plugins\preview\Models\PreviewSupport;
use Plugins\preview\Models\TrashPreviewResolver;
use Typemill\Models\Settings;

class PreviewIntegration
{
    public static function isActive(): bool
    {
        if (!class_exists(Settings::class)) {
            return true;
        }

        $settings = new Settings();
        $loaded = $settings->loadSettings();

        return !empty($loaded['plugins']['preview']['active']);
    }

    public static function isAvailable(): bool
    {
        return class_exists(PreviewSupport::class) && self::isActive();
    }

    public static function support(): PreviewSupport
    {
        return new PreviewSupport();
    }

    public static function metaResolver(): PreviewMetaResolver
    {
        return new PreviewMetaResolver(self::support());
    }

    public static function trashPreviewResolver(): ?TrashPreviewResolver
    {
        if (!self::isAvailable()) {
            return null;
        }

        return new TrashPreviewResolver(self::support(), self::metaResolver());
    }

    public static function markdownRenderer(array $settings, array $urlinfo, object $dispatcher): MarkdownPreviewRenderer
    {
        return new MarkdownPreviewRenderer($settings, $urlinfo, $dispatcher, self::support());
    }
}
