<?php

namespace Plugins\gitupdate;

use Plugins\gitupdate\Models\Catalog;
use Plugins\gitupdate\Models\GitHub;
use Plugins\gitupdate\Models\Ledger;
use Plugins\gitupdate\Models\PackageInstaller;
use Plugins\gitupdate\Models\Reference;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Typemill\Plugin;
use Typemill\Static\Translations;

/**
 * Rolls installed plugins and themes forward to the latest commit of a git
 * repository. Every commit is a release. Folders the server does not have are
 * left alone.
 */
class gitupdate extends Plugin
{
    private $lockHandle = null;

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
                'route' => '/tm/gitupdate',
                'name' => 'gitupdate.admin',
                'class' => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'get',
                'route' => '/api/v1/gitupdate/status',
                'name' => 'gitupdate.status',
                'class' => 'Plugins\gitupdate\gitupdate:getStatus',
                'resource' => 'system',
                'privilege' => 'read',
            ],
            [
                'httpMethod' => 'post',
                'route' => '/api/v1/gitupdate/run',
                'name' => 'gitupdate.run',
                'class' => 'Plugins\gitupdate\gitupdate:run',
                'resource' => 'user',
                'privilege' => 'update',
            ],
        ];
    }

    public function onSystemnaviLoaded($navidata)
    {
        $this->addSvgSymbol('<symbol id="icon-gitupdate" viewBox="0 0 24 24"><path d="M6 3v12a3 3 0 1 0 2 2.83V5h7.17A3 3 0 1 0 17 3H8a2 2 0 0 0-2 0Zm12 3a1 1 0 1 1 0-2 1 1 0 0 1 0 2ZM6 19a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm9-7v4.17A3 3 0 1 0 17 19v-7h-2Z"/></symbol>');

        $navi = $navidata->getData();
        $navi['Gitupdate'] = [
            'title' => Translations::translate('gitupdate.title'),
            'routename' => 'gitupdate.admin',
            'icon' => 'icon-gitupdate',
            'aclresource' => 'system',
            'aclprivilege' => 'read',
        ];

        if (trim($this->route, '/') === 'tm/gitupdate') {
            $navi['Gitupdate']['active'] = true;
            $template = file_get_contents(__DIR__ . '/js/systemgitupdate.html');
            $js = file_get_contents(__DIR__ . '/js/systemgitupdate.js');
            $this->addInlineJS('const gitupdateTemplate = ' . json_encode($template) . '; ' . $js);
        }

        $navidata->setData($navi);
    }

    public function getStatus(Request $request, Response $response, $args)
    {
        $settings = $this->configured();
        if (isset($settings['error'])) {
            return $this->jsonResponse($response, $settings, 422);
        }

        $root = self::detectRoot();
        $payload = [
            'repository' => $settings['repository'],
            'branch' => $settings['branch'],
            'can_update' => $this->mayUpdate($request),
            'blocked' => !class_exists(\ZipArchive::class),
            'head' => null,
            'items' => [],
            'absent' => [],
            'error' => null,
            'error_key' => null,
        ];

        if (($request->getQueryParams()['check'] ?? '1') === '0') {
            return $this->jsonResponse($response, $payload);
        }

        $github = new GitHub($settings['api'], $settings['repository'], $settings['token']);
        $head = $github->head($settings['branch']);
        if (!$head['ok']) {
            $payload['error'] = $head['error'];
            $payload['error_key'] = $head['error_key'];

            return $this->jsonResponse($response, $payload);
        }

        $catalog = $github->catalog($head['sha']);
        if (!$catalog['ok']) {
            $payload['head'] = self::presentHead($head);
            $payload['error'] = $catalog['error'];
            $payload['error_key'] = $catalog['error_key'];

            return $this->jsonResponse($response, $payload);
        }

        $payload['head'] = self::presentHead($head);
        $listed = $this->listItems($root, $catalog, $head['sha'], $github, (string) ($head['date'] ?? ''));
        $payload['items'] = $listed['items'];
        $payload['absent'] = $listed['absent'];

        return $this->jsonResponse($response, $payload);
    }

    public function run(Request $request, Response $response, $args)
    {
        $settings = $this->configured();
        if (isset($settings['error'])) {
            return $this->jsonResponse($response, $settings, 422);
        }

        if (!class_exists(\ZipArchive::class)) {
            return $this->jsonResponse($response, [
                'message' => 'The PHP zip extension is missing.',
                'message_key' => 'gitupdate.err_zip',
            ], 409);
        }

        $params = (array) $request->getParsedBody();
        $all = !empty($params['all']);
        $kind = Reference::kind(isset($params['kind']) && is_string($params['kind']) ? $params['kind'] : null);
        $slug = isset($params['slug']) && is_string($params['slug']) ? $params['slug'] : '';
        $force = !empty($params['force']);

        if (!$all && ($kind === null || !Reference::isSlug($slug))) {
            return $this->jsonResponse($response, [
                'message' => 'That is not a valid name.',
                'message_key' => 'gitupdate.err_slug',
            ], 422);
        }

        $root = self::detectRoot();
        if (!$all && !isset(Catalog::installed($root, $kind)[$slug])) {
            return $this->jsonResponse($response, [
                'message' => $slug . ' is not installed.',
                'message_key' => 'gitupdate.err_not_installed',
            ], 404);
        }

        if (!$this->acquireLock($root)) {
            return $this->jsonResponse($response, [
                'message' => 'Another update is already running.',
                'message_key' => 'gitupdate.err_lock',
            ], 409);
        }

        $github = new GitHub($settings['api'], $settings['repository'], $settings['token']);
        $head = $github->head($settings['branch']);
        if (!$head['ok']) {
            $this->releaseLock();

            return $this->jsonResponse($response, [
                'message' => $head['error'],
                'message_key' => $head['error_key'],
            ], 502);
        }

        $catalog = $github->catalog($head['sha']);
        if (!$catalog['ok']) {
            $this->releaseLock();

            return $this->jsonResponse($response, [
                'message' => $catalog['error'],
                'message_key' => $catalog['error_key'],
            ], 502);
        }

        $wanted = $all
            ? $this->outdated($root, $catalog, $head['sha'], $force)
            : [['kind' => $kind, 'slug' => $slug]];

        if (!$all) {
            $reason = $this->rejectOne($root, $catalog, $kind, $slug, $head['sha'], $force);
            if ($reason !== null) {
                $this->releaseLock();

                return $this->jsonResponse($response, $reason['body'], $reason['status']);
            }
        }

        if ($wanted === []) {
            $this->releaseLock();

            return $this->jsonResponse($response, [
                'message' => 'Everything installed from this repository is already at the latest commit.',
                'message_key' => 'gitupdate.msg_current',
                'head' => self::presentHead($head),
                'updated' => [],
            ]);
        }

        $archive = rtrim($root, DIRECTORY_SEPARATOR) . '/data/gitupdate/archive-' . $head['sha'] . '.zip';
        @mkdir(dirname($archive), 0755, true);
        $downloaded = $github->download($head['sha'], $archive);
        if (!$downloaded['ok']) {
            $this->releaseLock();

            return $this->jsonResponse($response, [
                'message' => $downloaded['error'],
                'message_key' => $downloaded['error_key'],
            ], 502);
        }

        $installer = new PackageInstaller($root);
        $ledger = new Ledger($root);
        $updated = [];
        $failure = null;

        foreach ($this->selfLast($wanted) as $item) {
            $staged = $installer->stage($archive, $item['kind'], $item['slug']);
            if (!$staged['ok']) {
                $failure = $staged + ['slug' => $item['slug'], 'kind' => $item['kind']];
                break;
            }

            $swapped = $installer->swap($item['kind'], $item['slug'], $staged['path']);
            if (!empty($staged['staging'])) {
                PackageInstaller::deleteTree($staged['staging']);
            }
            if (!$swapped['ok']) {
                $failure = $swapped + ['slug' => $item['slug'], 'kind' => $item['kind']];
                break;
            }

            $ledger->remember($item['kind'], $item['slug'], $head['sha'], $head['date'] ?? null);
            $updated[] = $item['kind'] . '/' . $item['slug'];
        }

        @unlink($archive);
        $this->releaseLock();

        if ($failure !== null) {
            return $this->jsonResponse($response, [
                'message' => $failure['error'] ?? 'The update failed.',
                'message_key' => $failure['error_key'] ?? null,
                'failed' => ($failure['kind'] ?? '') . '/' . ($failure['slug'] ?? ''),
                'updated' => $updated,
                'head' => self::presentHead($head),
            ], 422);
        }

        return $this->jsonResponse($response, [
            'message' => 'Updated to ' . substr($head['sha'], 0, 7) . '.',
            'message_key' => 'gitupdate.msg_done',
            'updated' => $updated,
            'head' => self::presentHead($head),
            'reload' => in_array('plugin/gitupdate', $updated, true),
        ]);
    }

    /**
     * @return array{repository: string, branch: string, api: string, token: ?string}|array{error: string, error_key: string}
     */
    private function configured(): array
    {
        $settings = $this->getPluginSettings() ?: [];
        $repository = Reference::repository($settings['repository'] ?? null);
        $branch = Reference::branch($settings['branch'] ?? null);
        $api = Reference::apiBase($settings['api_base'] ?? null);
        $rawToken = trim((string) ($settings['token'] ?? ''));
        $token = $rawToken === '' ? null : Reference::token($rawToken);

        if ($repository === null || $branch === null || $api === null || ($rawToken !== '' && $token === null)) {
            return [
                'error' => 'The repository settings are not usable.',
                'error_key' => 'gitupdate.err_settings',
            ];
        }

        return [
            'repository' => $repository,
            'branch' => $branch,
            'api' => $api,
            'token' => $token,
        ];
    }

    /**
     * @param array{plugins: list<string>, themes: list<string>} $catalog
     * @return array{items: list<array<string, mixed>>, absent: list<string>}
     */
    private function listItems(string $root, array $catalog, string $sha, ?GitHub $github = null, string $headDate = ''): array
    {
        $ledger = new Ledger($root);
        $rows = [];
        $absent = [];
        $missing = [];

        foreach (['plugin', 'theme'] as $kind) {
            $installed = Catalog::installed($root, $kind);
            $remote = $kind === 'theme' ? $catalog['themes'] : $catalog['plugins'];
            foreach ($remote as $slug) {
                if (!isset($installed[$slug])) {
                    $absent[] = $kind . '/' . $slug;
                    continue;
                }
                $applied = $ledger->entry($kind, $slug);
                if ($applied['sha'] !== null && $applied['date'] === null && $applied['sha'] !== $sha) {
                    $missing[$applied['sha']] = true;
                }
                $rows[] = [
                    'kind' => $kind,
                    'slug' => $slug,
                    'name' => $installed[$slug]['name'],
                    'applied' => $applied,
                ];
            }
        }

        $lookedUp = $github !== null && $missing !== [] ? $github->dates(array_keys($missing)) : [];
        $headDate = Reference::commitDate($headDate);
        $items = [];
        foreach ($rows as $row) {
            $appliedSha = $row['applied']['sha'];
            $appliedDate = $row['applied']['date'];
            if ($appliedDate === null && $appliedSha !== null) {
                $appliedDate = $appliedSha === $sha ? $headDate : ($lookedUp[$appliedSha] ?? null);
                if ($appliedDate !== null) {
                    $ledger->remember($row['kind'], $row['slug'], $appliedSha, $appliedDate);
                }
            }
            $items[] = [
                'kind' => $row['kind'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'applied' => $appliedSha !== null ? substr($appliedSha, 0, 7) : null,
                'applied_date' => $appliedDate,
                'update_available' => $appliedSha !== $sha,
            ];
        }

        return ['items' => $items, 'absent' => $absent];
    }

    /**
     * @param array{plugins: list<string>, themes: list<string>} $catalog
     * @return list<array{kind: string, slug: string}>
     */
    private function outdated(string $root, array $catalog, string $sha, bool $force): array
    {
        $wanted = [];
        foreach ($this->listItems($root, $catalog, $sha)['items'] as $item) {
            if ($force || $item['update_available']) {
                $wanted[] = ['kind' => $item['kind'], 'slug' => $item['slug']];
            }
        }

        return $wanted;
    }

    /**
     * @param array{plugins: list<string>, themes: list<string>} $catalog
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function rejectOne(string $root, array $catalog, string $kind, string $slug, string $sha, bool $force): ?array
    {
        $installed = Catalog::installed($root, $kind);
        if (!isset($installed[$slug])) {
            return [
                'status' => 404,
                'body' => [
                    'message' => $slug . ' is not installed.',
                    'message_key' => 'gitupdate.err_not_installed',
                ],
            ];
        }

        $remote = $kind === 'theme' ? $catalog['themes'] : $catalog['plugins'];
        if (!in_array($slug, $remote, true)) {
            return [
                'status' => 404,
                'body' => [
                    'message' => $slug . ' is not in this repository.',
                    'message_key' => 'gitupdate.err_not_in_repo',
                ],
            ];
        }

        $applied = (new Ledger($root))->sha($kind, $slug);
        if (!$force && $applied === $sha) {
            return [
                'status' => 409,
                'body' => [
                    'message' => $slug . ' is already at the latest commit.',
                    'message_key' => 'gitupdate.msg_current',
                ],
            ];
        }

        return null;
    }

    /**
     * @param list<array{kind: string, slug: string}> $items
     * @return list<array{kind: string, slug: string}>
     */
    private function selfLast(array $items): array
    {
        $rest = [];
        $self = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'plugin' && $item['slug'] === 'gitupdate') {
                $self[] = $item;
                continue;
            }
            $rest[] = $item;
        }

        return array_merge($rest, $self);
    }

    /**
     * @param array{sha: string, message?: string, date?: string} $head
     * @return array{sha: string, short: string, message: string, date: string}
     */
    private static function presentHead(array $head): array
    {
        return [
            'sha' => $head['sha'],
            'short' => substr($head['sha'], 0, 7),
            'message' => (string) ($head['message'] ?? ''),
            'date' => Reference::commitDate($head['date'] ?? null),
        ];
    }

    public static function detectRoot(): string
    {
        $candidates = [dirname(__DIR__, 2)];
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd;
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim((string) $candidate, DIRECTORY_SEPARATOR);
            if ($candidate !== '' && is_dir($candidate . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'typemill')) {
                $real = realpath($candidate);

                return rtrim($real !== false ? $real : $candidate, DIRECTORY_SEPARATOR);
            }
        }

        return rtrim((string) $candidates[0], DIRECTORY_SEPARATOR);
    }

    private function acquireLock(string $root): bool
    {
        $dir = rtrim($root, DIRECTORY_SEPARATOR) . '/data/gitupdate';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $handle = @fopen($dir . '/update.lock', 'c');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    private function mayUpdate(Request $request): bool
    {
        try {
            return (bool) $this->container->get('acl')->isAllowed(
                $request->getAttribute('c_userrole'),
                'user',
                'update'
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
