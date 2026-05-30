<?php

namespace Plugins\preview\Models;

class PreviewSupport
{
    private const TEXT_EXTENSIONS = [
        'txt', 'text', 'md', 'markdown', 'json', 'xml', 'csv', 'tsv', 'yaml', 'yml',
        'html', 'htm', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'cjs', 'ts', 'tsx',
        'jsx', 'log', 'ini', 'cfg', 'conf', 'env', 'svg',
    ];

    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'apng',
    ];

    private const AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'weba',
    ];

    private const VIDEO_EXTENSIONS = [
        'mp4', 'webm', 'ogv', 'mov', 'm4v',
    ];

    private const PDF_EXTENSIONS = ['pdf'];

    public const MAX_TEXT_PREVIEW_BYTES = 512 * 1024;
    public const MAX_MEDIA_PREVIEW_BYTES = 10 * 1024 * 1024;

    public function isPreviewablePath(string $path): bool
    {
        return $this->getPreviewKind($path) !== null;
    }

    public function getPreviewKind(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return 'text';
        }
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            return 'audio';
        }
        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }
        if (in_array($extension, self::PDF_EXTENSIONS, true)) {
            return 'pdf';
        }

        return null;
    }

    public function guessMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'apng' => 'image/apng',
            'svg' => 'image/svg+xml',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg', 'oga' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'opus' => 'audio/opus',
            'weba' => 'audio/webm',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            'mov' => 'video/quicktime',
            'pdf' => 'application/pdf',
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js', 'mjs', 'cjs' => 'text/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'md', 'markdown' => 'text/markdown',
            'yaml', 'yml' => 'application/yaml',
            default => 'text/plain',
        };
    }

    public function isMarkdownPath(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['md', 'markdown'], true);
    }

    public function isHtmlPath(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['html', 'htm'], true);
    }

    public function maxPreviewBytes(string $kind): int
    {
        return $kind === 'text' ? self::MAX_TEXT_PREVIEW_BYTES : self::MAX_MEDIA_PREVIEW_BYTES;
    }

    public function isLikelyTextContent(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        if (str_contains($content, "\0")) {
            return false;
        }

        return mb_check_encoding($content, 'UTF-8');
    }
}
