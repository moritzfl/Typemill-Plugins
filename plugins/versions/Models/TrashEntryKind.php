<?php

namespace Plugins\versions\Models;

class TrashEntryKind
{
    public static function resolve(?array $version, string $recordType = 'page'): string
    {
        if (!$version) {
            return $recordType === 'asset' ? 'file' : 'page';
        }

        if (class_exists(\Plugins\preview\PreviewIntegration::class)
            && \Plugins\preview\PreviewIntegration::isAvailable()) {
            $resolver = \Plugins\preview\PreviewIntegration::trashPreviewResolver();
            if ($resolver !== null) {
                return $resolver->resolveEntryKind($version);
            }
        }

        return self::resolveWithoutPreview($version, $recordType);
    }

    private static function resolveWithoutPreview(array $version, string $recordType): string
    {
        $assetType = $version['asset_type'] ?? $version['metadata']['asset_type'] ?? null;
        if ($assetType === 'image') {
            return 'image';
        }

        if ($assetType === 'mediafiles') {
            return (($version['metadata']['element_type'] ?? '') === 'folder') ? 'folder' : 'file';
        }

        if ($recordType === 'asset') {
            return 'file';
        }

        $pathWithoutType = ltrim(str_replace('\\', '/', (string) ($version['path_without_type'] ?? '')), '/');
        if (($version['item_type'] ?? '') === 'folder'
            && !($pathWithoutType !== '' && str_ends_with($pathWithoutType, '/index'))) {
            return 'folder';
        }

        return 'page';
    }
}
