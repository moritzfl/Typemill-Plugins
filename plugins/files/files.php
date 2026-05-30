<?php

namespace Plugins\files;

use Plugins\files\Models\FileManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Plugin;

class files extends Plugin
{
    private ?FileManager $fileManager = null;
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'exe', 'bat', 'cmd', 'com', 'scr',
        'vbs', 'ps1', 'sh', 'bash', 'zsh', 'htaccess', 'htpasswd',
    ];

    /** MIME types that must never be stored in media/files. */
    private const BLOCKED_MIME_TYPES = [
        'application/x-httpd-php',
        'application/x-php',
        'application/php',
        'text/x-php',
        'text/php',
        'application/x-executable',
        'application/x-msdos-program',
        'application/x-msdownload',
        'application/vnd.microsoft.portable-executable',
        'application/x-sh',
        'application/x-csh',
        'application/java-archive',
    ];

    /** Substrings that indicate a blocked MIME type (checked on the full MIME string). */
    private const BLOCKED_MIME_MARKERS = [
        'php',
        'x-httpd-',
        'msdownload',
        'executable',
    ];

    /**
     * When the filename extension is listed here, the sniffed MIME must match one of the values.
     * Extensions not listed are only checked against the global blocklists above.
     */
    private const EXTENSION_MIME_HINTS = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml'],
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'gz'   => ['application/gzip', 'application/x-gzip'],
        'json' => ['application/json', 'text/plain'],
        'xml'  => ['application/xml', 'text/xml'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
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
            [
                'httpMethod' => 'get',
                'route'      => '/api/v1/files/browse',
                'name'       => 'files.browse',
                'class'      => 'Plugins\files\files:browse',
                'resource'   => 'system',
                'privilege'  => 'view'
            ],
            [
                'httpMethod' => 'post',
                'route'      => '/api/v1/files/folder',
                'name'       => 'files.folder',
                'class'      => 'Plugins\files\files:createFolder',
                'resource'   => 'system',
                'privilege'  => 'update'
            ],
            [
                'httpMethod' => 'delete',
                'route'      => '/api/v1/files/entry',
                'name'       => 'files.entry.delete',
                'class'      => 'Plugins\files\files:deleteEntry',
                'resource'   => 'system',
                'privilege'  => 'update'
            ],
            [
                'httpMethod' => 'post',
                'route'      => '/api/v1/files/upload',
                'name'       => 'files.upload',
                'class'      => 'Plugins\files\files:uploadFile',
                'resource'   => 'system',
                'privilege'  => 'update'
            ],
            [
                'httpMethod' => 'get',
                'route'      => '/api/v1/files/download',
                'name'       => 'files.download',
                'class'      => 'Plugins\files\files:downloadFile',
                'resource'   => 'system',
                'privilege'  => 'view'
            ],
            [
                'httpMethod' => 'get',
                'route'      => '/api/v1/files/download-zip',
                'name'       => 'files.download.zip',
                'class'      => 'Plugins\files\files:downloadFolderZip',
                'resource'   => 'system',
                'privilege'  => 'view'
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

        $safeFilename = $this->sanitizeFilename($filename);
        if ($safeFilename === null) {
            $this->cleanupChunks($uploadId, $total);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_filename_missing',
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

        $validationError = $this->validateUploadedFile($tmpFile, $safeFilename);
        if ($validationError !== null) {
            unlink($tmpFile);
            return $this->jsonResponse($response, [
                'message' => $validationError,
            ], 400);
        }

        $relativeDir = $this->getManager()->normalizeRelativePath($params['path'] ?? '');
        if ($relativeDir === null) {
            unlink($tmpFile);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_folder_invalid',
            ], 400);
        }

        $destDir = $this->getManager()->resolveDirectoryForWrite($relativeDir);
        if ($destDir === null) {
            unlink($tmpFile);
            return $this->jsonResponse($response, [
                'message' => 'files.msg_folder_parent_missing',
            ], 400);
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . $safeFilename;

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

    public function browse(Request $request, Response $response, $args)
    {
        $params = $request->getQueryParams();
        $path = $this->getManager()->normalizeRelativePath($params['path'] ?? '');
        if ($path === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_folder_invalid',
            ], 400);
        }

        $listing = $this->getManager()->browse($path);
        if ($listing === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_folder_parent_missing',
            ], 404);
        }

        if (class_exists(\Plugins\preview\PreviewIntegration::class) && \Plugins\preview\PreviewIntegration::isAvailable()) {
            $listing = $this->appendPreviewFlags($listing);
        }

        return $this->jsonResponse($response, $listing);
    }

    private function appendPreviewFlags(array $listing): array
    {
        $support = \Plugins\preview\PreviewIntegration::support();

        foreach ($listing['files'] as &$file) {
            $kind = $support->getPreviewKind((string) ($file['path'] ?? ''));
            $bytes = (int) ($file['bytes'] ?? 0);
            $file['previewable'] = $kind !== null
                && $bytes > 0
                && $bytes <= $support->maxPreviewBytes($kind);
        }
        unset($file);

        return $listing;
    }

    public function createFolder(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $parentPath = $params['path'] ?? '';
        $name = $params['name'] ?? '';

        $error = $this->getManager()->createFolder($parentPath, $name);
        if ($error !== null) {
            return $this->jsonResponse($response, [
                'message' => $error,
            ], 400);
        }

        return $this->jsonResponse($response, [
            'message' => 'files.msg_folder_created',
        ]);
    }

    public function deleteEntry(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        if (!is_array($params)) {
            $params = [];
        }

        $query = $request->getQueryParams();
        $path = $params['path'] ?? $query['path'] ?? '';

        if (!$this->getManager()->deletePath($path)) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_delete_error',
            ], 400);
        }

        return $this->jsonResponse($response, [
            'message' => 'files.msg_deleted',
        ]);
    }

    public function uploadFile(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();
        $relativeDir = $this->getManager()->normalizeRelativePath($params['path'] ?? '');
        if ($relativeDir === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_folder_invalid',
            ], 400);
        }

        $destDir = $this->getManager()->resolveDirectoryForWrite($relativeDir);
        if ($destDir === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_folder_parent_missing',
            ], 400);
        }

        $filename = $params['name'] ?? '';
        $fileData = $params['file'] ?? '';
        if (!is_string($fileData) || $fileData === '') {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_file_missing',
            ], 400);
        }

        $safeFilename = $this->sanitizeFilename($filename);
        if ($safeFilename === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_filename_missing',
            ], 400);
        }

        $decoded = $this->decodeDataUrl($fileData);
        if ($decoded === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_upload_failed',
            ], 400);
        }

        $settings = $this->getSettings();
        $maxSize = isset($settings['maxfileuploads']) ? (int) $settings['maxfileuploads'] * 1024 * 1024 : 0;
        if ($maxSize > 0 && strlen($decoded) > $maxSize) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_too_large',
            ], 400);
        }

        $tmpDir = $this->getTmpDir();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tmpFile = $tmpDir . '/upload_' . uniqid('', true) . '.bin';
        if (file_put_contents($tmpFile, $decoded) === false) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_store_error',
            ], 500);
        }

        $validationError = $this->validateUploadedFile($tmpFile, $safeFilename);
        if ($validationError !== null) {
            unlink($tmpFile);
            return $this->jsonResponse($response, [
                'message' => $validationError,
            ], 400);
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . $safeFilename;
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

        return $this->jsonResponse($response, [
            'message' => 'files.msg_upload_success',
        ]);
    }

    public function downloadFile(Request $request, Response $response, $args)
    {
        $params = $request->getQueryParams();
        $path = $params['path'] ?? '';
        $download = $this->getManager()->getFileDownload($path);
        if ($download === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_download_error',
            ], 404);
        }

        return $this->fileDownloadResponse($response, $download);
    }

    public function downloadFolderZip(Request $request, Response $response, $args)
    {
        $params = $request->getQueryParams();
        $path = $params['path'] ?? '';
        $download = $this->getManager()->createFolderZip($path);
        if ($download === null) {
            return $this->jsonResponse($response, [
                'message' => 'files.msg_zip_error',
            ], 400);
        }

        return $this->fileDownloadResponse($response, $download);
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

    private function getManager(): FileManager
    {
        if ($this->fileManager === null) {
            $this->fileManager = new FileManager($this->getProjectRoot());
        }

        return $this->fileManager;
    }

    private function getTmpDir(): string
    {
        return $this->getManager()->getTmpDir();
    }

    private function decodeDataUrl(string $value): ?string
    {
        if (str_contains($value, ',')) {
            $value = substr($value, strpos($value, ',') + 1);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }

    private function fileDownloadResponse(Response $response, array $download): Response
    {
        $response->getBody()->write($download['content']);

        return $response
            ->withHeader('Content-Type', $download['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace(['"', '\\'], ['\"', '\\\\'], $download['filename']) . '"');
    }

    private function sanitizeUploadId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    private function sanitizeFilename(string $filename): ?string
    {
        $basename = basename(str_replace('\\', '/', trim($filename)));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return null;
        }

        if (preg_match('/\.\.|[\x00-\x1f]/', $basename)) {
            return null;
        }

        return $basename;
    }

    private function validateUploadedFile(string $path, string $filename): ?string
    {
        if (!is_readable($path) || filesize($path) === 0) {
            return 'files.msg_file_empty';
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return 'files.msg_type_not_allowed';
        }

        $mime = $this->detectMimeType($path);
        if ($mime === null) {
            return null;
        }

        if ($this->isBlockedMimeType($mime)) {
            return 'files.msg_mime_not_allowed';
        }

        if ($extension !== '' && $this->mimeConflictsWithExtension($mime, $extension)) {
            return 'files.msg_mime_not_allowed';
        }

        return null;
    }

    private function detectMimeType(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (!is_string($mime) || $mime === '') {
            return null;
        }

        return strtolower($mime);
    }

    private function isBlockedMimeType(string $mime): bool
    {
        if (in_array($mime, self::BLOCKED_MIME_TYPES, true)) {
            return true;
        }

        foreach (self::BLOCKED_MIME_MARKERS as $marker) {
            if (str_contains($mime, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function mimeConflictsWithExtension(string $mime, string $extension): bool
    {
        if (!isset(self::EXTENSION_MIME_HINTS[$extension])) {
            return false;
        }

        foreach (self::EXTENSION_MIME_HINTS[$extension] as $allowed) {
            if ($mime === $allowed) {
                return false;
            }
        }

        return true;
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
