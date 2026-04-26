<?php

namespace Plugins\files;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Plugin;

class files extends Plugin
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi',
    ];

    public static function setPremiumLicense()
    {
        return false;
    }

    public static function getSubscribedEvents()
    {
        return [
            'onSystemnaviLoaded' => ['onSystemnaviLoaded', 0],
        ];
    }

    public static function addNewRoutes()
    {
        return [
            [
                'httpMethod' => 'get',
                'route'      => '/tm/files',
                'name'       => 'files.admin',
                'class'      => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage',
                'resource'   => 'system',
                'privilege'  => 'view'
            ],
            [
                'httpMethod' => 'post',
                'route'      => '/api/v1/files/chunk',
                'name'       => 'files.chunk',
                'class'      => 'Plugins\files\files:uploadChunk',
                'resource'   => 'system',
                'privilege'  => 'update'
            ],
            [
                'httpMethod' => 'post',
                'route'      => '/api/v1/files/finalize',
                'name'       => 'files.finalize',
                'class'      => 'Plugins\files\files:finalizeUpload',
                'resource'   => 'system',
                'privilege'  => 'update'
            ],
        ];
    }

    public function onSystemnaviLoaded($navidata)
    {
        $this->addSvgSymbol('<symbol id="icon-filemanager" viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-1 8h-3v3h-2v-3h-3v-2h3V9h2v3h3v2z"/></symbol>');

        $navi = $navidata->getData();

        $navi['Files'] = [
            'title'        => 'Files',
            'routename'    => 'files.admin',
            'icon'         => 'icon-filemanager',
            'aclresource'  => 'system',
            'aclprivilege' => 'view'
        ];

        if (trim($this->route, '/') == 'tm/files') {
            $navi['Files']['active'] = true;
            $settings = $this->getSettings();
            $config = [
                'maxFileUploads'      => $settings['maxfileuploads'] ?? null,
                'uploadMaxFilesize'   => ini_get('upload_max_filesize') ?: null,
                'postMaxSize'         => ini_get('post_max_size') ?: null,
                'maxFileUploadsCount' => ini_get('max_file_uploads') ?: null,
            ];
            $configJs = 'const filesConfig = ' . json_encode($config) . ';';
            $template = file_get_contents(__DIR__ . '/js/systemfiles.html');
            $js       = file_get_contents(__DIR__ . '/js/systemfiles.js');
            $this->addInlineJS($configJs . ' const filesTemplate = ' . json_encode($template) . '; ' . $js);
        }

        $navidata->setData($navi);
    }

    public function uploadChunk(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $uploadId = $params['uploadId'] ?? '';
        $index    = isset($params['index']) ? (int)$params['index'] : -1;
        $total    = isset($params['total']) ? (int)$params['total'] : 0;
        $data     = $params['data'] ?? '';

        if (!$uploadId || $index < 0 || $total < 1 || !is_string($data) || $data === '') {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_upload_failed',
            ], 400);
        }

        $tmpDir = $this->getTmpDir();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $chunkPath = $tmpDir . '/' . $this->sanitizeUploadId($uploadId) . '.' . $index;
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_upload_failed',
            ], 400);
        }

        file_put_contents($chunkPath, $decoded);

        return $this->jsonResponse($response, [
            'received' => $index + 1,
            'total'    => $total,
        ]);
    }

    public function finalizeUpload(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $uploadId = $params['uploadId'] ?? '';
        $filename = $params['filename'] ?? '';
        $total    = isset($params['total']) ? (int)$params['total'] : 0;

        if (!$uploadId || !$filename || $total < 1) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_upload_failed',
            ], 400);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            $this->cleanupChunks($uploadId, $total);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_type_not_allowed',
            ], 400);
        }

        $settings = $this->getSettings();
        $maxSize = isset($settings['maxfileuploads']) ? (int)$settings['maxfileuploads'] * 1024 * 1024 : 0;

        $tmpDir = $this->getTmpDir();
        $safeId = $this->sanitizeUploadId($uploadId);
        $tmpFile = $tmpDir . '/' . $safeId . '.final';

        $out = fopen($tmpFile, 'wb');
        if (!$out) {
            $this->cleanupChunks($uploadId, $total);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_store_error',
            ], 500);
        }

        $assembledSize = 0;
        for ($i = 0; $i < $total; $i++) {
            $chunkPath = $tmpDir . '/' . $safeId . '.' . $i;
            if (!file_exists($chunkPath)) {
                fclose($out);
                unlink($tmpFile);
                $this->cleanupChunks($uploadId, $total);
                return $this->jsonResponse($response, [
                    'message' => 'files.msg_upload_failed',
                ], 400);
            }
            $chunkData = file_get_contents($chunkPath);
            fwrite($out, $chunkData);
            $assembledSize += strlen($chunkData);
            unlink($chunkPath);

            if ($maxSize > 0 && $assembledSize > $maxSize) {
                fclose($out);
                unlink($tmpFile);
                return $this->jsonResponse($response, [
                    'message' => 'files.msg_too_large',
                ], 400);
            }
        }
        fclose($out);

        $destDir = $this->getProjectRoot() . '/media/files';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $destPath = $destDir . '/' . $filename;

        if (file_exists($destPath)) {
            unlink($tmpFile);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_filename_missing',
            ], 409);
        }

        if (!rename($tmpFile, $destPath)) {
            unlink($tmpFile);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_store_error',
            ], 500);
        }

        $this->cleanupOldTmpFiles();

        return $this->jsonResponse($response, [
            'message' => 'files.msg_upload_success',
        ]);
    }

    private function cleanupOldTmpFiles(): void
    {
        $tmpDir = $this->getTmpDir();
        if (!is_dir($tmpDir)) {
            return;
        }
        $maxAge = 86400; // 24 hours
        $now = time();
        $entries = scandir($tmpDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $tmpDir . '/' . $entry;
            if (is_file($path) && ($now - filemtime($path)) > $maxAge) {
                unlink($path);
            }
        }
    }

    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function getTmpDir(): string
    {
        return $this->getProjectRoot() . '/media/files/.tmp';
    }

    private function sanitizeUploadId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    private function cleanupChunks(string $uploadId, int $total): void
    {
        $tmpDir = $this->getTmpDir();
        $safeId = $this->sanitizeUploadId($uploadId);
        for ($i = 0; $i < $total; $i++) {
            $path = $tmpDir . '/' . $safeId . '.' . $i;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $final = $tmpDir . '/' . $safeId . '.final';
        if (file_exists($final)) {
            unlink($final);
        }
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[files] Failed to encode JSON response: ' . $e->getMessage());
            $status = 500;
            $json = json_encode([
                'message' => 'Internal server error.',
            ], JSON_THROW_ON_ERROR);
        }

        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function parseIniBytes(string $value): int
    {
        $value = trim($value);
        $num = (float) $value;
        $unit = strtoupper(substr($value, -1));
        switch ($unit) {
            case 'G': return (int) ($num * 1024 * 1024 * 1024);
            case 'M': return (int) ($num * 1024 * 1024);
            case 'K': return (int) ($num * 1024);
            default:  return (int) $num;
        }
    }
}
