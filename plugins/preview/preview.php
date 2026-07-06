<?php

namespace Plugins\preview;

use Plugins\preview\Models\MarkdownPreviewRenderer;
use Plugins\preview\Models\PreviewFileService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Plugin;

class preview extends Plugin
{
    public static function setPremiumLicense()
    {
        return false;
    }

    public static function getSubscribedEvents()
    {
        return [
            'onCspLoaded' => ['onCspLoaded', 0],
            'onSystemnaviLoaded' => ['onSystemnaviLoaded', 10],
        ];
    }

    public static function addNewRoutes()
    {
        return [
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/preview/file/meta',
                'name' => 'preview.file.meta',
                'class' => 'Plugins\preview\preview:getFilePreviewMeta',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/preview/file/stream',
                'name' => 'preview.file.stream',
                'class' => 'Plugins\preview\preview:streamFilePreview',
                'resource' => 'system',
                'privilege' => 'read',
            ],
        ];
    }

    public function onCspLoaded($event)
    {
        $csp = $event->getData();
        if (!is_array($csp)) {
            $csp = [];
        }

        $csp[] = 'blob:';
        $event->setData(array_values(array_unique($csp)));
    }

    public function onSystemnaviLoaded($navidata)
    {
        $route = trim($this->route, '/');
        if (!in_array($route, ['tm/files', 'tm/versions'], true)) {
            return;
        }

        $styles = file_get_contents(__DIR__ . '/js/preview-styles.js');
        $template = file_get_contents(__DIR__ . '/js/preview-modal.html');
        $js = file_get_contents(__DIR__ . '/js/preview.js');
        $this->addInlineJS($styles . ' const previewModalTemplate = ' . json_encode($template) . '; ' . $js);
    }

    public function getFilePreviewMeta(Request $request, Response $response, $args)
    {
        $path = (string) ($request->getQueryParams()['path'] ?? '');
        $service = new PreviewFileService();
        $payload = $service->buildFilePreviewPayload($path);
        if ($payload === null) {
            return $this->jsonResponse($response, ['message' => 'preview.msg_not_found'], 404);
        }

        if (($payload['previewable'] ?? false) && ($payload['preview_kind'] ?? null) === 'text') {
            $renderer = $this->getMarkdownRenderer();
            $payload = $renderer->addRenderedHtml(array_merge($payload, [
                'preview_kind' => 'text',
            ]));
        }

        return $this->jsonResponse($response, ['preview' => $payload]);
    }

    public function streamFilePreview(Request $request, Response $response, $args)
    {
        $path = (string) ($request->getQueryParams()['path'] ?? '');
        $service = new PreviewFileService();
        $stream = $service->buildFileStreamPayload($path);
        if ($stream === null) {
            return $this->jsonResponse($response, ['message' => 'preview.msg_not_available'], 404);
        }

        return $this->inlineFileResponse($response, $stream);
    }

    private function getMarkdownRenderer(): MarkdownPreviewRenderer
    {
        return PreviewIntegration::markdownRenderer(
            $this->getSettings(),
            $this->urlinfo(),
            $this->getDispatcher()
        );
    }

    private function inlineFileResponse(Response $response, array $file): Response
    {
        $response->getBody()->write($file['content']);
        $filename = str_replace(['\\', '"'], ['\\\\', '\"'], $file['filename']);

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
