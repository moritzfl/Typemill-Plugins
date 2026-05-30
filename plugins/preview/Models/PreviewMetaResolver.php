<?php

namespace Plugins\preview\Models;

class PreviewMetaResolver
{
    public function __construct(private PreviewSupport $support = new PreviewSupport())
    {
    }

    /**
     * @return array{previewable:bool,kind?:string,filename?:string,mime_type?:string,text?:string}
     */
    public function resolve(string $path, string $content): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return ['previewable' => false];
        }

        $kind = $this->support->getPreviewKind($path);
        if ($kind === null) {
            return ['previewable' => false];
        }

        $size = strlen($content);
        if ($size === 0 || $size > $this->support->maxPreviewBytes($kind)) {
            return ['previewable' => false];
        }

        if ($kind === 'text' && !$this->support->isLikelyTextContent($content)) {
            return ['previewable' => false];
        }

        $meta = [
            'previewable' => true,
            'kind' => $kind,
            'filename' => basename($path),
            'mime_type' => $this->support->guessMimeType($path),
        ];

        if ($kind === 'text') {
            $meta['text'] = $content;
        }

        return $meta;
    }
}
