<?php

namespace Plugins\typemillupdate;

use Plugins\typemillupdate\Models\Environment;
use Plugins\typemillupdate\Models\Installer;
use Plugins\typemillupdate\Models\Release;
use Plugins\typemillupdate\Models\Upload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Plugin;
use Typemill\Static\Translations;

class typemillupdate extends Plugin
{
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
                'route' => '/tm/typemillupdate',
                'name' => 'typemillupdate.admin',
                'class' => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/typemillupdate/status',
                'name' => 'typemillupdate.status',
                'class' => 'Plugins\typemillupdate\typemillupdate:getStatus',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            // Replacing the core is administrator-only. The `system`/`update`
            // privilege is also held by the manager role, which is appropriate
            // for settings but not for swapping every PHP file on the site.
            // `user`/`update` is what the core itself uses to mean admin only.
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/typemillupdate/run',
                'name' => 'typemillupdate.run',
                'class' => 'Plugins\typemillupdate\typemillupdate:runUpdate',
                'resource' => 'user',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/typemillupdate/upload/chunk',
                'name' => 'typemillupdate.upload.chunk',
                'class' => 'Plugins\typemillupdate\typemillupdate:uploadChunk',
                'resource' => 'user',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/typemillupdate/upload/finalize',
                'name' => 'typemillupdate.upload.finalize',
                'class' => 'Plugins\typemillupdate\typemillupdate:finalizeUpload',
                'resource' => 'user',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/typemillupdate/rollback',
                'name' => 'typemillupdate.rollback',
                'class' => 'Plugins\typemillupdate\typemillupdate:runRollback',
                'resource' => 'user',
                'privilege' => 'update',
            ],
            [
                'httpMethod' => 'delete',
                'route' => '/api/v1/typemillupdate/backup',
                'name' => 'typemillupdate.backup.delete',
                'class' => 'Plugins\typemillupdate\typemillupdate:deleteBackup',
                'resource' => 'user',
                'privilege' => 'update',
            ],
        ];
    }

    public function onSystemnaviLoaded($navidata)
    {
        $this->addSvgSymbol('<symbol id="icon-typemillupdate" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-9 9H0l4 4 4-4H5a7 7 0 1 1 2.05 4.95l-1.42 1.42A9 9 0 1 0 12 3Zm1 4h-2v6l5 3 1-1.64-4-2.36V7Z"/></symbol>');

        $navi = $navidata->getData();
        $navi['Typemillupdate'] = [
            'title' => Translations::translate('typemillupdate.title'),
            'routename' => 'typemillupdate.admin',
            'icon' => 'icon-typemillupdate',
            'aclresource' => 'system',
            'aclprivilege' => 'read',
        ];

        if (trim($this->route, '/') === 'tm/typemillupdate') {
            $navi['Typemillupdate']['active'] = true;

            $template = file_get_contents(__DIR__ . '/js/systemtypemillupdate.html');
            $js = file_get_contents(__DIR__ . '/js/systemtypemillupdate.js');
            $this->addInlineJS('const typemillupdateTemplate = ' . json_encode($template) . '; ' . $js);
        }

        $navidata->setData($navi);
    }

    public function getStatus(Request $request, Response $response, $args)
    {
        $environment = new Environment();
        $installer = new Installer($environment);
        $release = new Release($environment);

        $installed = $environment->installedVersion();
        $checks = $environment->preflight();

        $latest = null;
        $checkError = null;
        if (($request->getQueryParams()['check'] ?? '1') !== '0') {
            $remote = $release->latestVersion();
            $latest = $remote['version'];
            $checkError = $remote['error'];
        }

        return $this->jsonResponse($response, [
            'root' => $environment->root(),
            'installed' => $installed,
            'latest' => $latest,
            'check_error' => $checkError,
            'update_available' => $installed !== null && $latest !== null && version_compare($latest, $installed, '>'),
            'download_url' => $latest !== null ? Release::downloadUrl($latest) : null,
            'preflight' => $checks,
            'blocked' => Environment::isBlocked($checks),
            'php_version' => PHP_VERSION,
            'backups' => $this->presentBackups($installer->listBackups()),
        ]);
    }

    /**
     * Download, verify, stage and swap in a new core.
     *
     * Everything that can fail is done before the live tree is touched: the
     * environment is probed, the archive is downloaded and validated, the PHP
     * floor is read out of the staged vendor tree, and the core is extracted to
     * a staging directory. Only then are the two renames performed.
     */
    public function runUpdate(Request $request, Response $response, $args)
    {
        $params = (array) $request->getParsedBody();
        $force = !empty($params['force']);

        $environment = new Environment();
        $installer = new Installer($environment);
        $release = new Release($environment);

        $installed = $environment->installedVersion();
        if ($installed === null) {
            return $this->jsonResponse($response, ['message' => 'Could not determine the installed Typemill version.'], 500);
        }

        $checks = $environment->preflight();
        if (Environment::isBlocked($checks)) {
            return $this->jsonResponse($response, [
                'message' => 'This installation cannot update itself. See the environment checks.',
                'preflight' => $checks,
            ], 409);
        }

        // An uploaded archive is installed as given. Choosing a specific file is
        // explicit, and reinstalling or going back to an older build is a large
        // part of why uploading exists, so the "already installed" guard that
        // applies to downloads is not applied here.
        $uploadName = isset($params['archive']) && is_string($params['archive']) ? $params['archive'] : '';
        $uploadedArchive = null;

        if ($uploadName !== '') {
            $uploadedArchive = (new Upload($environment))->resolveArchive($uploadName);
            if ($uploadedArchive === null) {
                return $this->jsonResponse($response, ['message' => 'That uploaded archive could not be found. Please upload it again.'], 404);
            }
        }

        $target = null;
        if ($uploadedArchive === null) {
            if (isset($params['version']) && is_string($params['version']) && $params['version'] !== '') {
                if (preg_match('/^\d+\.\d+\.\d+$/', $params['version']) !== 1) {
                    return $this->jsonResponse($response, ['message' => 'That is not a valid version number.'], 422);
                }

                $target = $params['version'];
            }

            if ($target === null) {
                $remote = $release->latestVersion();
                if ($remote['version'] === null) {
                    return $this->jsonResponse($response, ['message' => $remote['error'] ?? 'Could not determine the latest version.'], 502);
                }
                $target = $remote['version'];
            }

            if (!$force && version_compare($target, $installed, '<=')) {
                return $this->jsonResponse($response, [
                    'message' => 'Typemill ' . $installed . ' is already installed.',
                    'message_key' => 'typemillupdate.msg_already_installed',
                    'message_params' => ['version' => $installed],
                    'installed' => $installed,
                    'latest' => $target,
                ], 409);
            }
        }

        if (!$environment->ensureWorkPath()) {
            return $this->jsonResponse($response, [
                'message' => 'Could not create the working directory ' . $environment->workPath() . '.',
                'message_key' => 'typemillupdate.msg_workdir_failed',
                'message_params' => ['path' => $environment->workPath()],
            ], 500);
        }

        // Two runs at once would delete each other's staging directories. The
        // lock is released when the request ends, including if it dies.
        if (!$installer->acquireLock()) {
            return $this->jsonResponse($response, ['message' => 'Another update is already running.'], 409);
        }

        $log = [];

        if ($uploadedArchive !== null) {
            $archive = $uploadedArchive;
            $log[] = 'Using the uploaded archive ' . $uploadName . '.';
        } else {
            $url = Release::downloadUrl($target);
            $archive = $installer->downloadTarget();
            $downloaded = $release->download($url, $archive);
            if (!$downloaded['ok']) {
                $installer->cleanup();

                return $this->jsonResponse($response, ['message' => $downloaded['error'], 'log' => $log], 502);
            }
            $log[] = 'Downloaded ' . Environment::formatBytes((int) $downloaded['bytes']) . ' from ' . $url;
        }

        $meta = $release->inspectArchive($archive);
        if (!$meta['ok']) {
            $installer->cleanup();

            return $this->jsonResponse($response, ['message' => $meta['error'], 'log' => $log], 422);
        }
        $log[] = 'Archive verified: version ' . $meta['version'] . ', ' . $meta['system_entries'] . ' core files.';

        if ($uploadedArchive === null && $meta['version'] !== $target) {
            $installer->cleanup();

            return $this->jsonResponse($response, [
                'message' => 'The archive contains Typemill ' . $meta['version'] . ', but ' . $target . ' was requested.',
                'message_key' => 'typemillupdate.msg_archive_version_mismatch',
                'message_params' => ['found' => $meta['version'], 'requested' => $target],
                'log' => $log,
            ], 422);
        }

        if ($meta['php_floor'] !== null && PHP_VERSION_ID < $meta['php_floor']) {
            $installer->cleanup();

            return $this->jsonResponse($response, [
                'message' => 'Typemill ' . $meta['version'] . ' requires a newer PHP version than ' . PHP_VERSION . '.',
                'message_key' => 'typemillupdate.msg_php_too_old',
                'message_params' => ['version' => $meta['version'], 'php' => PHP_VERSION],
                'log' => $log,
            ], 409);
        }

        $staged = $installer->stage($archive, (string) ($meta['prefix'] ?? ''));
        if (!$staged['ok']) {
            $installer->cleanup();

            return $this->jsonResponse($response, ['message' => $staged['error'], 'log' => $log], 500);
        }
        $log[] = 'Staged the new core in ' . basename($installer->stagingPath()) . '.';

        // Past this point the live installation changes. Slim is not used to
        // build the reply any more: emitting through the framework could
        // autoload a class that has not been loaded yet, which would now be
        // read from the freshly swapped core.
        $swap = $installer->install($staged['path']);
        if (!$swap['ok']) {
            $installer->cleanup();
            $log[] = $swap['error'];

            // When the live core was left modified, the framework can no longer
            // be trusted to build a reply, and this is exactly the message the
            // admin needs to see.
            if (!empty($swap['touched'])) {
                $this->emitAndExit(['ok' => false, 'message' => $swap['error'], 'log' => $log], 500);
            }

            return $this->jsonResponse($response, ['message' => $swap['error'], 'log' => $log], 500);
        }
        $log[] = 'Replaced system/ with Typemill ' . $meta['version']
            . ($swap['method'] === 'copy' ? ' (in-place copy; this filesystem does not allow directory renames).' : ' (atomic rename).');

        if ($installer->clearTwigCache()) {
            $log[] = 'Cleared the compiled Twig cache.';
        }

        $selfTest = $installer->selfTest((string) ($this->urlinfo()['baseurl'] ?? ''));
        $log[] = $selfTest['detail'];

        if ($selfTest['conclusive'] && !$selfTest['ok']) {
            $rollback = $installer->rollback($swap['backup']);
            $log[] = $rollback['ok']
                ? 'Rolled back to Typemill ' . $installed . '.'
                : 'Rollback failed: ' . $rollback['error'];

            if ($rollback['ok']) {
                $installer->cleanup();
            }

            $this->emitAndExit([
                'ok' => false,
                'message' => $rollback['ok']
                    ? 'The updated site did not respond correctly, so the previous version was restored.'
                    : 'The updated site did not respond correctly and the rollback failed. Restore ' . $swap['backup'] . ' manually.',
                'installed' => $rollback['ok'] ? $installed : $meta['version'],
                'log' => $log,
            ], 500);
        }

        $installer->cleanup();
        $log[] = 'Cleaned up staging data.';

        $this->emitAndExit([
            'ok' => true,
            'message' => 'Typemill was updated from ' . $installed . ' to ' . $meta['version'] . '.',
            'message_key' => 'typemillupdate.msg_updated',
            'message_params' => ['from' => $installed, 'to' => $meta['version']],
            'previous' => $installed,
            'installed' => $meta['version'],
            'backup' => basename((string) $swap['backup']),
            'method' => $swap['method'],
            'log' => $log,
        ], 200);
    }

    /**
     * Receive one slice of an uploaded archive.
     */
    public function uploadChunk(Request $request, Response $response, $args)
    {
        $params = (array) $request->getParsedBody();

        $environment = new Environment();
        if (!$environment->ensureWorkPath()) {
            return $this->jsonResponse($response, ['message' => 'Could not create the working directory.'], 500);
        }

        $result = (new Upload($environment))->storeChunk(
            (string) ($params['uploadId'] ?? ''),
            isset($params['index']) ? (int) $params['index'] : -1,
            (string) ($params['data'] ?? '')
        );

        if (!$result['ok']) {
            return $this->jsonResponse($response, ['message' => $result['error']], 400);
        }

        return $this->jsonResponse($response, ['ok' => true]);
    }

    /**
     * Join the uploaded slices and check that the result really is a Typemill
     * core. Nothing is installed here - the caller is told which version was
     * found so it can be confirmed first.
     */
    public function finalizeUpload(Request $request, Response $response, $args)
    {
        $params = (array) $request->getParsedBody();
        $total = isset($params['total']) ? (int) $params['total'] : 0;
        $safeId = Upload::sanitizeId((string) ($params['uploadId'] ?? ''));

        if ($safeId === null || $total < 1) {
            return $this->jsonResponse($response, ['message' => 'Invalid upload.'], 400);
        }

        $environment = new Environment();
        if (!$environment->ensureWorkPath()) {
            return $this->jsonResponse($response, ['message' => 'Could not create the working directory.'], 500);
        }

        $upload = new Upload($environment);
        $name = Upload::archiveName($safeId);
        $target = $environment->workPath() . DIRECTORY_SEPARATOR . $name;

        $assembled = $upload->assemble($safeId, $total, $target);
        if (!$assembled['ok']) {
            return $this->jsonResponse($response, ['message' => $assembled['error']], 400);
        }

        $meta = (new Release($environment))->inspectArchive($target);
        if (!$meta['ok']) {
            @unlink($target);

            return $this->jsonResponse($response, ['message' => $meta['error']], 422);
        }

        if ($meta['php_floor'] !== null && PHP_VERSION_ID < $meta['php_floor']) {
            @unlink($target);

            return $this->jsonResponse($response, [
                'message' => 'That archive contains Typemill ' . $meta['version'] . ', which needs a newer PHP version than ' . PHP_VERSION . '.',
                'message_key' => 'typemillupdate.msg_upload_php_too_old',
                'message_params' => ['version' => $meta['version'], 'php' => PHP_VERSION],
            ], 409);
        }

        $upload->discardOtherArchives($name);
        $installed = $environment->installedVersion();

        return $this->jsonResponse($response, [
            'ok' => true,
            'archive' => $name,
            'version' => $meta['version'],
            'installed' => $installed,
            'is_downgrade' => $installed !== null && version_compare($meta['version'], $installed, '<'),
            'is_same' => $installed !== null && version_compare($meta['version'], $installed, '=='),
            'size' => Environment::formatBytes((int) $assembled['bytes']),
            'core_files' => $meta['system_entries'],
        ]);
    }

    public function runRollback(Request $request, Response $response, $args)
    {
        $params = (array) $request->getParsedBody();
        $name = (string) ($params['backup'] ?? '');

        $environment = new Environment();
        $installer = new Installer($environment);

        $path = $installer->resolveBackupPath($name);
        if ($path === null) {
            return $this->jsonResponse($response, ['message' => 'That backup could not be found.'], 404);
        }

        if (!$environment->ensureWorkPath() || !$installer->acquireLock()) {
            return $this->jsonResponse($response, ['message' => 'Another update is already running.'], 409);
        }

        $previous = $environment->installedVersion();
        $result = $installer->rollback($path);

        if (!$result['ok']) {
            if (!empty($result['touched'])) {
                $this->emitAndExit(['ok' => false, 'message' => $result['error']], 500);
            }

            return $this->jsonResponse($response, ['message' => $result['error']], 500);
        }

        $installer->clearTwigCache();

        $this->emitAndExit([
            'ok' => true,
            'message' => 'Restored the previous core.',
            'previous' => $previous,
            'installed' => $environment->installedVersion(),
            'method' => $result['method'],
        ], 200);
    }

    public function deleteBackup(Request $request, Response $response, $args)
    {
        $params = (array) $request->getParsedBody();
        $name = (string) ($params['backup'] ?? '');

        $installer = new Installer(new Environment());

        if ($installer->resolveBackupPath($name) === null) {
            return $this->jsonResponse($response, ['message' => 'That backup could not be found.'], 404);
        }

        $result = $installer->removeBackup($name);
        if (!$result['ok']) {
            return $this->jsonResponse($response, ['message' => $result['error'] ?? 'Could not delete the backup.'], 500);
        }

        return $this->jsonResponse($response, ['ok' => true]);
    }

    private function presentBackups(array $backups): array
    {
        return array_map(static function (array $backup): array {
            return [
                'name' => $backup['name'],
                'version' => $backup['version'],
                'created' => $backup['created'] > 0 ? date('Y-m-d H:i', $backup['created']) : null,
            ];
        }, $backups);
    }

    /**
     * Write the response without the framework and stop.
     *
     * Used only after the core has been replaced. json_encode, header and echo
     * are language builtins, so nothing has to be autoloaded from the new core
     * to finish the request.
     */
    private function emitAndExit(array $payload, int $status): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json', true, $status);
            header('Cache-Control: no-store');
        }

        echo json_encode($payload);
        exit;
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[typemillupdate] Failed to encode JSON response: ' . $e->getMessage());
            $status = 500;
            $json = '{"message":"Internal server error."}';
        }

        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
