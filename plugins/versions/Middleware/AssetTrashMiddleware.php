<?php

namespace Plugins\versions\Middleware;

use Plugins\versions\Models\SnapshotTooLargeException;
use Plugins\versions\Models\VersionStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use Typemill\Models\Settings;

class AssetTrashMiddleware implements MiddlewareInterface
{
    private VersionStore $store;
    private array $pluginSettings;

    public function __construct($params = [])
    {
        $this->store = new VersionStore();

        $settings = new Settings();
        $loadedSettings = $settings->loadSettings();
        $this->pluginSettings = $loadedSettings['plugins']['versions'] ?? [];
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $mediaFilesPath = $this->resolveMediaFilesPath($request);
        if ($mediaFilesPath !== null) {
            return $this->processWithTrashSnapshot(
                $request,
                $handler,
                fn () => $this->store->storeMediaFilesDeletion(
                    $mediaFilesPath,
                    $this->resolveUsername($request),
                    $this->pluginSettings
                )
            );
        }

        $assetType = $this->resolveAssetType($request);
        if (!$assetType) {
            return $handler->handle($request);
        }

        $params = $request->getParsedBody();
        if (!is_array($params)) {
            return $handler->handle($request);
        }

        $name = isset($params['name']) ? basename((string) $params['name']) : '';
        if ($name === '') {
            return $handler->handle($request);
        }

        return $this->processWithTrashSnapshot(
            $request,
            $handler,
            fn () => $this->store->storeAssetDeletion(
                $assetType,
                $name,
                $this->resolveUsername($request),
                $this->pluginSettings
            )
        );
    }

    /**
     * @param callable(): array $createSnapshot
     */
    private function processWithTrashSnapshot(Request $request, RequestHandler $handler, callable $createSnapshot): Response
    {
        try {
            $snapshot = $createSnapshot();
        } catch (SnapshotTooLargeException $exception) {
            if (!$this->requestForceDelete($request)) {
                return $this->tooLargeResponse($exception);
            }

            $snapshot = ['success' => false];
        }

        $recordId = ($snapshot['success'] ?? false) ? (string) ($snapshot['record_id'] ?? '') : '';

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $exception) {
            if ($recordId !== '') {
                $this->store->deleteTrashEntry($recordId, 'asset');
            }

            throw $exception;
        }

        if ($recordId !== '' && $response->getStatusCode() >= 400) {
            $this->store->deleteTrashEntry($recordId, 'asset');
        }

        return $response;
    }

    private function resolveMediaFilesPath(Request $request): ?string
    {
        if (strtoupper($request->getMethod()) !== 'DELETE') {
            return null;
        }

        $path = rtrim(strtolower($request->getUri()->getPath()), '/');
        if (!str_ends_with($path, '/api/v1/files/entry')) {
            return null;
        }

        $params = $request->getParsedBody();
        if (!is_array($params)) {
            $params = [];
        }

        $query = $request->getQueryParams();
        $relativePath = trim((string) ($params['path'] ?? $query['path'] ?? ''));

        return $relativePath !== '' ? $relativePath : null;
    }

    private function resolveAssetType(Request $request): ?string
    {
        if (strtoupper($request->getMethod()) !== 'DELETE') {
            return null;
        }

        $path = rtrim(strtolower($request->getUri()->getPath()), '/');

        if (str_ends_with($path, '/api/v1/file')) {
            return 'file';
        }

        if (str_ends_with($path, '/api/v1/image')) {
            return 'image';
        }

        return null;
    }

    private function resolveUsername(Request $request): string
    {
        $username = trim((string) ($request->getAttribute('c_username') ?? ''));

        return $username !== '' ? $username : 'unknown';
    }

    private function requestForceDelete(Request $request): bool
    {
        $params = $request->getParsedBody();

        return is_array($params) && !empty($params['force_delete']);
    }

    private function tooLargeResponse(SnapshotTooLargeException $exception): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'too_large' => true,
            'message' => $exception->getMessage() . ' It will be permanently deleted without a backup. Do you want to proceed?',
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(409);
    }
}
