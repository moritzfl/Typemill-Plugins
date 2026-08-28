<?php

namespace Plugins\preview\Models;

use Typemill\Models\StorageWrapper;

class PreviewFileService
{
    public function __construct(
        private StorageWrapper $storage = new StorageWrapper('\Typemill\Models\Storage'),
        private PreviewSupport $support = new PreviewSupport(),
        private PreviewMetaResolver $metaResolver = new PreviewMetaResolver()
    ) {
    }

    public function normalizeRelativePath(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if ($segment === '.tmp' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        return $path;
    }

    public function resolveAbsolutePath(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $base = rtrim($this->storage->getFolderPath('fileFolder'), DIRECTORY_SEPARATOR);
        $absolute = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($absolute);
        $rootReal = realpath($base);
        if ($resolved === false || $rootReal === false || !is_file($resolved)) {
            return null;
        }

        $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;
        if ($resolved !== $rootReal && !str_starts_with($resolved, $rootPrefix)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array{previewable:bool,title?:string,preview_kind?:string,preview_mime?:string,preview_filename?:string,markdown?:string,rendered_html?:string}|null
     */
    public function buildFilePreviewPayload(string $relativePath): ?array
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $absolute = $this->resolveAbsolutePath($relativePath);
        if ($absolute === null) {
            return null;
        }

        $kind = $this->support->getPreviewKind($relativePath);
        if ($kind === null) {
            return ['previewable' => false, 'title' => basename($relativePath)];
        }

        $maxBytes = $this->support->maxPreviewBytes($kind);
        $size = filesize($absolute);
        if ($size === false || $size === 0 || $size > $maxBytes) {
            return ['previewable' => false, 'title' => basename($relativePath)];
        }

        if ($kind !== 'text') {
            return [
                'previewable' => true,
                'title' => basename($relativePath),
                'preview_kind' => $kind,
                'preview_mime' => $this->support->guessMimeType($relativePath),
                'preview_filename' => basename($relativePath),
            ];
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            return null;
        }

        $meta = $this->metaResolver->resolve($relativePath, $content);
        if (!$meta['previewable']) {
            return ['previewable' => false, 'title' => basename($relativePath)];
        }

        return [
            'previewable' => true,
            'title' => basename($relativePath),
            'preview_kind' => 'text',
            'preview_mime' => $meta['mime_type'],
            'preview_filename' => $meta['filename'],
            'markdown' => $meta['text'] ?? '',
        ];
    }

    /**
     * @return array{filename:string,content:string,mime_type:string}|null
     */
    public function buildFileStreamPayload(string $relativePath): ?array
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $absolute = $this->resolveAbsolutePath($relativePath);
        if ($absolute === null) {
            return null;
        }

        $kind = $this->support->getPreviewKind($relativePath);
        if ($kind === null || $kind === 'text') {
            return null;
        }

        $size = filesize($absolute);
        if ($size === false || $size === 0 || $size > $this->support->maxPreviewBytes($kind)) {
            return null;
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            return null;
        }

        return [
            'filename' => basename($relativePath),
            'content' => $content,
            'mime_type' => $this->support->guessMimeType($relativePath),
        ];
    }
}
