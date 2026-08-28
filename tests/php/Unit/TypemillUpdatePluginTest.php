<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\typemillupdate\Models\Environment;
use Plugins\typemillupdate\Models\Installer;
use Plugins\typemillupdate\Models\PluginInstaller;
use Plugins\typemillupdate\Models\Registry;
use ZipArchive;

/**
 * Directory plugin updates: catalog matching, archive checks, and the swap.
 *
 * HTTP against plugins.typemill.net is not exercised here. The catalog is a
 * map, the zip is synthetic, and the installer runs against a temporary
 * plugins folder.
 */
class TypemillUpdatePluginTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->root = $base . '/typemillupdate-plugin-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPluginSlugsRejectTraversalAndDots(): void
    {
        $this->assertTrue(Environment::isPluginSlug('search'));
        $this->assertTrue(Environment::isPluginSlug('figureSwipe'));
        $this->assertTrue(Environment::isPluginSlug('cookieconsent'));

        foreach (['../evil', 'a/b', '.hidden', '', 'has.dot', 'typemill update'] as $slug) {
            $this->assertFalse(Environment::isPluginSlug($slug), $slug);
        }
    }

    public function testPresentKeepsOnlyDirectoryPluginsAndFlagsUpdates(): void
    {
        $installed = [
            'search' => ['slug' => 'search', 'name' => 'Search', 'version' => '2.1.0'],
            'versions' => ['slug' => 'versions', 'name' => 'Versions', 'version' => '1.0.0'],
            'typemillupdate' => ['slug' => 'typemillupdate', 'name' => 'Typemill Update', 'version' => '1.1.0'],
            'highlight' => ['slug' => 'highlight', 'name' => 'Highlight', 'version' => '2.2.0'],
        ];
        $catalog = [
            'search' => ['name' => 'Search', 'version' => '2.2.0', 'license' => 'MIT', 'homepage' => ''],
            'highlight' => ['name' => 'Highlight', 'version' => '2.2.0', 'license' => 'BSD', 'homepage' => ''],
        ];

        $present = Registry::present($installed, $catalog);
        $bySlug = [];
        foreach ($present as $row) {
            $bySlug[$row['slug']] = $row;
        }

        $this->assertArrayHasKey('search', $bySlug);
        $this->assertTrue($bySlug['search']['update_available']);
        $this->assertSame('2.2.0', $bySlug['search']['latest']);

        $this->assertArrayHasKey('highlight', $bySlug);
        $this->assertFalse($bySlug['highlight']['update_available']);

        $this->assertArrayNotHasKey('versions', $bySlug);
        $this->assertArrayNotHasKey('typemillupdate', $bySlug);

        $unknown = Registry::present(
            ['search' => ['slug' => 'search', 'name' => 'Search', 'version' => null]],
            ['search' => ['name' => 'Search', 'version' => '2.2.0', 'license' => 'MIT', 'homepage' => '']]
        );
        $this->assertTrue($unknown[0]['update_available']);
    }

    public function testValidPluginArchiveIsAccepted(): void
    {
        $zipPath = $this->root . '/search.zip';
        $this->makeZip($zipPath, $this->pluginEntries('search', '2.2.0'));

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('2.2.0', $result['version']);
        $this->assertSame('', $result['prefix']);
        $this->assertGreaterThan(0, $result['plugin_entries']);
    }

    public function testWrappedPluginArchiveIsAccepted(): void
    {
        $zipPath = $this->root . '/wrapped.zip';
        $wrapped = [];
        foreach ($this->pluginEntries('search', '2.2.0') as $name => $content) {
            $wrapped['search-2.2.0/' . $name] = $content;
        }
        $this->makeZip($zipPath, $wrapped);

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('search-2.2.0/', $result['prefix']);
    }

    public function testArchiveWithoutThePluginPhpIsRejected(): void
    {
        $entries = $this->pluginEntries('search', '2.2.0');
        unset($entries['search/search.php']);
        $zipPath = $this->root . '/nophp.zip';
        $this->makeZip($zipPath, $entries);

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertFalse($result['ok']);
        $this->assertSame('typemillupdate.err_plugin_archive_missing_entry', $result['error_key']);
    }

    public function testArchiveWithoutThePluginYamlIsRejected(): void
    {
        $entries = $this->pluginEntries('search', '2.2.0');
        unset($entries['search/search.yaml']);
        $zipPath = $this->root . '/noyaml.zip';
        $this->makeZip($zipPath, $entries);

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertFalse($result['ok']);
        $this->assertSame('typemillupdate.err_plugin_archive_no_plugin', $result['error_key']);
    }

    public function testArchiveWithFilesOutsideThePluginIsRejected(): void
    {
        $entries = $this->pluginEntries('search', '2.2.0');
        $entries['evil.php'] = '<?php';
        $zipPath = $this->root . '/extra.zip';
        $this->makeZip($zipPath, $entries);

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertFalse($result['ok']);
        $this->assertSame('typemillupdate.err_plugin_archive_wrong_slug', $result['error_key']);
    }

    public function testArchiveForADifferentPluginIsRejected(): void
    {
        $zipPath = $this->root . '/highlight.zip';
        $this->makeZip($zipPath, $this->pluginEntries('highlight', '2.2.0'));

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertFalse($result['ok']);
    }

    public function testArchiveWithTraversalEntryIsRejected(): void
    {
        $entries = $this->pluginEntries('search', '2.2.0');
        $entries['../escaped.php'] = '<?php';
        $zipPath = $this->root . '/traversal.zip';
        $this->makeZip($zipPath, $entries);

        if (!$this->archiveContainsTraversalEntry($zipPath)) {
            $this->markTestSkipped('This ZipArchive build normalises traversal entry names on write.');
        }

        $result = (new Registry(new Environment($this->root)))->inspectArchive($zipPath, 'search');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unsafe path', $result['error']);
    }

    public function testStagingExtractsOnlyTheNamedPlugin(): void
    {
        $zipPath = $this->root . '/search.zip';
        $this->makeZip($zipPath, $this->pluginEntries('search', '2.2.0') + [
            'search/extra.txt' => 'ok',
        ]);

        $environment = new Environment($this->root);
        mkdir($environment->pluginsPath(), 0777, true);
        $installer = new PluginInstaller($environment, new Installer($environment));
        $result = $installer->stage($zipPath, 'search');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertFileExists($result['path'] . '/search.php');
        $this->assertFileExists($result['path'] . '/search.yaml');
        $this->assertFileDoesNotExist($installer->stagingPath() . '/evil.php');
    }

    public function testInstallReplacesThePluginAndKeepsABackup(): void
    {
        $this->writePlugin('search', '2.1.0', 'old body');
        $this->writePlugin('versions', '1.0.0', 'leave me');
        $this->write('/content/index.md', '# My site');

        $environment = new Environment($this->root);
        $core = new Installer($environment);
        $installer = new PluginInstaller($environment, $core);

        $staged = $installer->stagingPath() . '/search';
        $this->writeAbsolute($staged . '/search.php', '<?php // new body');
        $this->writeAbsolute($staged . '/search.yaml', "name: Search\nversion: '2.2.0'\n");

        $result = $installer->install('search', $staged);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertStringContainsString('new body', (string) file_get_contents($this->root . '/plugins/search/search.php'));
        $this->assertSame(
            '2.2.0',
            Environment::parseVersionFromYaml((string) file_get_contents($this->root . '/plugins/search/search.yaml'))
        );
        $this->assertStringContainsString('old body', (string) file_get_contents($result['backup'] . '/search.php'));
        $this->assertFileExists($this->root . '/plugins/versions/versions.php');
        $this->assertFileExists($this->root . '/content/index.md');
    }

    public function testInstallRefusesAnIncompleteStagedPlugin(): void
    {
        $this->writePlugin('search', '2.1.0', 'old body');

        $environment = new Environment($this->root);
        $installer = new PluginInstaller($environment, new Installer($environment));
        $staged = $installer->stagingPath() . '/search';
        mkdir($staged, 0777, true);

        $result = $installer->install('search', $staged);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['touched']);
        $this->assertStringContainsString('old body', (string) file_get_contents($this->root . '/plugins/search/search.php'));
    }

    public function testInstalledPluginsSkipsHiddenFolders(): void
    {
        $this->writePlugin('search', '2.1.0', 'search');
        $this->writePlugin('typemillupdate', '1.1.0', 'self');
        mkdir($this->root . '/plugins/.tm-update', 0777, true);
        $this->writeAbsolute($this->root . '/plugins/.tm-update/.tm-update.php', '<?php');
        $this->writeAbsolute($this->root . '/plugins/.tm-update/.tm-update.yaml', "name: Hidden\nversion: '1.0.0'\n");

        $found = (new Environment($this->root))->installedPlugins();

        $this->assertArrayHasKey('search', $found);
        $this->assertArrayHasKey('typemillupdate', $found);
        $this->assertArrayNotHasKey('.tm-update', $found);
        $this->assertSame('2.1.0', $found['search']['version']);
    }

    public function testBelongingEntriesStayInsideThePluginFolder(): void
    {
        $this->assertTrue(Registry::belongsToPlugin('search/search.php', 'search'));
        $this->assertTrue(Registry::belongsToPlugin('search/', 'search'));
        $this->assertFalse(Registry::belongsToPlugin('evil.php', 'search'));
        $this->assertFalse(Registry::belongsToPlugin('search-extra/x.php', 'search'));
        $this->assertTrue(Registry::belongsToPlugin('wrap/', 'search', 'wrap/'));
        $this->assertTrue(Registry::belongsToPlugin('wrap/search/search.php', 'search', 'wrap/'));
        $this->assertFalse(Registry::belongsToPlugin('wrap/other/x.php', 'search', 'wrap/'));
    }

    private function pluginEntries(string $slug, string $version): array
    {
        return [
            $slug . '/' . $slug . '.php' => '<?php // plugin',
            $slug . '/' . $slug . '.yaml' => "name: " . ucfirst($slug) . "\nversion: '" . $version . "'\n",
            $slug . '/README.md' => 'readme',
        ];
    }

    private function writePlugin(string $slug, string $version, string $body): void
    {
        $this->write('/plugins/' . $slug . '/' . $slug . '.php', '<?php // ' . $body);
        $this->write('/plugins/' . $slug . '/' . $slug . '.yaml', "name: " . ucfirst($slug) . "\nversion: '" . $version . "'\n");
    }

    private function write(string $relative, string $contents): void
    {
        $this->writeAbsolute($this->root . $relative, $contents);
    }

    private function writeAbsolute(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function makeZip(string $path, array $entries): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    private function archiveContainsTraversalEntry(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!\Plugins\typemillupdate\Models\Release::isSafeEntryName((string) $zip->getNameIndex($i))) {
                $zip->close();

                return true;
            }
        }

        $zip->close();

        return false;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
