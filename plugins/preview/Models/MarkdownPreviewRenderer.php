<?php

namespace Plugins\preview\Models;

use Typemill\Events\OnContentArrayLoaded;
use Typemill\Events\OnHtmlLoaded;
use Typemill\Models\Content;

class MarkdownPreviewRenderer
{
    public function __construct(
        private array $settings,
        private array $urlinfo,
        private object $dispatcher,
        private PreviewSupport $support = new PreviewSupport()
    ) {
    }

    public function renderMarkdown(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        try {
            $content = new Content($this->urlinfo['baseurl'] ?? '', $this->settings, $this->dispatcher);
            $markdownArray = $content->markdownTextToArray($markdown);

            if (count($markdownArray) === 0) {
                return '';
            }

            array_shift($markdownArray);
            if (count($markdownArray) === 0) {
                return '';
            }

            $body = $content->markdownArrayToText($markdownArray);
            $contentArray = $content->getContentArray($body);
            $contentArray = $this->dispatcher->dispatch(new OnContentArrayLoaded($contentArray), 'onContentArrayLoaded')->getData();

            $contentHtml = $content->getContentHtml($contentArray);

            return $this->dispatcher->dispatch(new OnHtmlLoaded($contentHtml), 'onHtmlLoaded')->getData();
        } catch (\Throwable $e) {
            error_log('[preview] Markdown rendering failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * @param array{preview_kind?:string,preview_filename?:string,markdown?:string} $payload
     */
    public function addRenderedHtml(array $payload): array
    {
        $previewKind = $payload['preview_kind'] ?? null;
        $filename = (string) ($payload['preview_filename'] ?? '');
        $markdown = trim((string) ($payload['markdown'] ?? ''));

        if ($previewKind === 'text') {
            if ($this->support->isMarkdownPath($filename)) {
                $payload['rendered_html'] = $this->renderMarkdown($markdown);
            } elseif ($this->support->isHtmlPath($filename)) {
                $payload['rendered_html'] = $markdown;
            } else {
                $payload['rendered_html'] = '';
            }

            return $payload;
        }

        if ($previewKind === 'page' || $previewKind === null) {
            $payload['rendered_html'] = $this->renderMarkdown($markdown);
        }

        return $payload;
    }
}
