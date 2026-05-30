<?php

namespace Plugins\preview\Models;

class TrashPreviewResolver
{
    public function __construct(
        private PreviewSupport $support = new PreviewSupport(),
        private PreviewMetaResolver $fileResolver = new PreviewMetaResolver()
    ) {
    }

    public function isFolderDeletion(array $version): bool
    {
        if (($version['item_type'] ?? '') === 'folder') {
            return true;
        }

        if (($version['metadata']['element_type'] ?? '') === 'folder') {
            return true;
        }

        return $this->isMediaFilesFolderDeletion($version);
    }

    /**
     * @param array<int, array{path:string,size?:int|null}> $snapshotDescriptors
     *
     * @return array{
     *     previewable:bool,
     *     kind?:string,
     *     files?:array<int, array{path:string,name:string,size?:int|null,preview_kind?:string|null}>,
     *     file_count?:int
     * }
     */
    public function resolveFolder(array $snapshotDescriptors): array
    {
        if ($snapshotDescriptors === []) {
            return ['previewable' => false];
        }

        $files = [];
        foreach ($snapshotDescriptors as $descriptor) {
            $path = ltrim(str_replace('\\', '/', (string) ($descriptor['path'] ?? '')), '/');
            if ($path === '') {
                continue;
            }

            $files[] = [
                'path' => $path,
                'name' => basename($path),
                'size' => $descriptor['size'] ?? null,
                'preview_kind' => $this->support->getPreviewKind($path),
            ];
        }

        if ($files === []) {
            return ['previewable' => false];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return [
            'previewable' => true,
            'kind' => 'folder',
            'files' => $files,
            'file_count' => count($files),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $snapshotFiles
     *
     * @return array<int, array{path:string,size?:int|null}>
     */
    public function buildSnapshotDescriptors(array $snapshotFiles): array
    {
        $descriptors = [];

        foreach ($snapshotFiles as $file) {
            $path = ltrim(str_replace('\\', '/', (string) ($file['path'] ?? '')), '/');
            if ($path === '') {
                continue;
            }

            $size = null;
            if (isset($file['snapshot_path']) && is_string($file['snapshot_path']) && is_file($file['snapshot_path'])) {
                $fileSize = filesize($file['snapshot_path']);
                $size = $fileSize === false ? null : $fileSize;
            } elseif (isset($file['content_base64'])) {
                $decoded = base64_decode((string) $file['content_base64'], true);
                $size = $decoded === false ? null : strlen($decoded);
            } elseif (isset($file['content']) && is_string($file['content'])) {
                $size = strlen($file['content']);
            }

            $descriptors[] = [
                'path' => $path,
                'size' => $size,
            ];
        }

        usort($descriptors, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $descriptors;
    }

    private function isMediaFilesFolderDeletion(array $version): bool
    {
        $assetType = $version['asset_type'] ?? $version['metadata']['asset_type'] ?? null;
        if ($assetType !== 'mediafiles') {
            return false;
        }

        $name = ltrim(str_replace('\\', '/', (string) ($version['metadata']['name'] ?? '')), '/');
        if ($name === '') {
            return false;
        }

        $snapshots = $version['snapshot_files'] ?? [];
        if ($snapshots === []) {
            return false;
        }

        if (count($snapshots) === 1) {
            $onlyPath = ltrim(str_replace('\\', '/', (string) ($snapshots[0]['path'] ?? '')), '/');

            return $onlyPath !== $name;
        }

        return true;
    }
}
