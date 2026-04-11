<?php

namespace Plugins\versions\Middleware;

use Plugins\versions\Models\VersionStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
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

        $snapshot = $this->store->storeAssetDeletion(
            $assetType,
            $name,
            $this->resolveUsername($request),
            $this->pluginSettings
        );

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
        if ($username !== '') {
            return $username;
        }

        $authorization = $request->getHeaderLine('Authorization');
        if (preg_match('/Basic\\s+(.*)$/i', $authorization, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                return trim((string) explode(':', $decoded, 2)[0]) ?: 'unknown';
            }
        }

        return 'unknown';
    }
}
