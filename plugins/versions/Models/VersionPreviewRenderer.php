<?php

namespace Plugins\versions\Models;

use Typemill\Events\OnContentArrayLoaded;
use Typemill\Events\OnHtmlLoaded;
use Typemill\Models\Content;

class VersionPreviewRenderer
{
    private array $settings;
    private array $urlinfo;
    private $dispatcher;

    public function __construct(array $settings, array $urlinfo, $dispatcher)
    {
        $this->settings = $settings;
        $this->urlinfo = $urlinfo;
        $this->dispatcher = $dispatcher;
    }

    public function addRenderedPreview(array $detail): array
    {
        if (!isset($detail['version']) || !is_array($detail['version'])) {
            return $detail;
        }

        $detail['version']['rendered_html'] = $this->renderVersionHtml($detail['version']);

        return $detail;
    }

    private function renderVersionHtml(array $version): string
    {
        $markdown = trim((string) ($version['markdown'] ?? ''));
        if ($markdown === '') {
            return '';
        }

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
    }
}
