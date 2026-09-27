<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\gitupdate\Models\Catalog;
use Plugins\gitupdate\Models\Ledger;
use Plugins\gitupdate\Models\PackageInstaller;
use Plugins\gitupdate\Models\Reference;
use ZipArchive;

class GitUpdateTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            PackageInstaller::deleteTree($this->root);
        }
    }

    public function testRepositoryAndBranchAreCheckedBeforeTheyEnterAUrl(): void
    {
        $this->assertSame('moritzfl/Typemill-Plugins', Reference::repository(''));
        $this->assertSame('moritzfl/Typemill-Plugins', Reference::repository('https://github.com/moritzfl/Typemill-Plugins.git'));
        $this->assertNull(Reference::repository('../etc/passwd'));
        $this->assertNull(Reference::repository('owner/name?x=1'));
        $this->assertSame('main', Reference::branch(''));
        $this->assertNull(Reference::branch('feature/../../main'));
        $this->assertNull(Reference::sha('not-a-sha'));
        $this->assertSame('https://api.github.com', Reference::apiBase(''));
        $this->assertNull(Reference::apiBase('http://api.github.com'));
        $this->assertNull(Reference::token("token\r\nX-Injected: yes"));
    }

    public function testZipballRedirectsStayOnGitHub(): void
    {
        $api = 'https://api.github.com';
        $this->assertTrue(Reference::redirectAllowed($api, 'https://codeload.github.com/moritzfl/Typemill-Plugins/zip/abc'));
        $this->assertTrue(Reference::redirectAllowed($api, 'https://api.github.com/repos/moritzfl/Typemill-Plugins/zipball/abc'));
        $this->assertFalse(Reference::redirectAllowed($api, 'https://evil.example/archive.zip'));
        $this->assertFalse(Reference::redirectAllowed($api, 'http://codeload.github.com/x.zip'));
        $this->assertFalse(Reference::redirectAllowed('https://github.example.com', 'https://codeload.github.com/x.zip'));
    }

    public function testOnlyCompletePluginsAndThemesCountAsPresent(): void
    {
        $paths = [
            'plugins/files/files.php',
            'plugins/files/files.yaml',
            'plugins/files/Models/FileManager.php',
            'plugins/half/half.php',
            'plugins/.gitupdate/staging/files.php',
            'themes/court/court.yaml',
            'themes/court/css/court.css',
            'themes/nope/readme.md',
            'README.md',
        ];

        $this->assertSame(['files'], Catalog::slugsInTree($paths, 'plugin'));
        $this->assertSame(['court'], Catalog::slugsInTree($paths, 'theme'));
    }

    public function testAServerSubsetIsWhatGetsOffered(): void
    {
        $this->root = sys_get_temp_dir() . '/tm_gitupdate_' . uniqid('', true);
        $this->write($this->root . '/plugins/files/files.php', '<?php // files');
        $this->write($this->root . '/plugins/files/files.yaml', "name: File Manager\n");
        $this->write($this->root . '/themes/court/court.yaml', "name: Court\n");
        mkdir($this->root . '/plugins/stranger', 0775, true);

        $installedPlugins = Catalog::installed($this->root, 'plugin');
        $installedThemes = Catalog::installed($this->root, 'theme');
        $remotePlugins = ['files', 'syntax', 'gitupdate'];
        $remoteThemes = ['court', 'lucid'];

        $offered = array_values(array_intersect($remotePlugins, array_keys($installedPlugins)));
        $absent = array_values(array_diff($remotePlugins, array_keys($installedPlugins)));

        $this->assertSame(['files'], $offered);
        $this->assertSame(['syntax', 'gitupdate'], $absent);
        $this->assertArrayNotHasKey('stranger', $installedPlugins);
        $this->assertSame(['court'], array_values(array_intersect($remoteThemes, array_keys($installedThemes))));
        $this->assertSame('File Manager', $installedPlugins['files']['name']);
    }

    public function testAnArchiveReplacesOnlyTheNamedFolder(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->root = sys_get_temp_dir() . '/tm_gitupdate_' . uniqid('', true);
        $this->write($this->root . '/plugins/files/files.php', '<?php // old');
        $this->write($this->root . '/plugins/files/files.yaml', "name: File Manager\n");
        $this->write($this->root . '/plugins/syntax/syntax.php', '<?php // stay');
        $this->write($this->root . '/plugins/syntax/syntax.yaml', "name: Syntax\n");

        $zip = $this->root . '/snapshot.zip';
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('Typemill-Plugins-abc/plugins/files/files.php', '<?php // new');
        $archive->addFromString('Typemill-Plugins-abc/plugins/files/files.yaml', "name: File Manager\n");
        $archive->addFromString('Typemill-Plugins-abc/plugins/syntax/syntax.php', '<?php // should not land');
        $archive->addFromString('Typemill-Plugins-abc/plugins/syntax/syntax.yaml', "name: Syntax\n");
        $archive->addFromString('Typemill-Plugins-abc/plugins/files/../../evil.php', '<?php // no');
        $archive->close();

        $installer = new PackageInstaller($this->root);
        $staged = $installer->stage($zip, 'plugin', 'files');
        $this->assertTrue($staged['ok'], $staged['error'] ?? '');

        $swapped = $installer->swap('plugin', 'files', $staged['path']);
        $this->assertTrue($swapped['ok'], $swapped['error'] ?? '');
        PackageInstaller::deleteTree($staged['staging']);

        $this->assertStringContainsString('new', (string) file_get_contents($this->root . '/plugins/files/files.php'));
        $this->assertStringContainsString('stay', (string) file_get_contents($this->root . '/plugins/syntax/syntax.php'));
        $this->assertFileDoesNotExist($this->root . '/plugins/evil.php');
        $this->assertFileDoesNotExist($this->root . '/evil.php');
    }

    public function testBrokenPhpIsNotInstalled(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->root = sys_get_temp_dir() . '/tm_gitupdate_' . uniqid('', true);
        $this->write($this->root . '/plugins/files/files.php', '<?php // old');
        $this->write($this->root . '/plugins/files/files.yaml', "name: File Manager\n");

        $zip = $this->root . '/snapshot.zip';
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('Wrap/plugins/files/files.php', '<?php function (');
        $archive->addFromString('Wrap/plugins/files/files.yaml', "name: File Manager\n");
        $archive->close();

        $staged = (new PackageInstaller($this->root))->stage($zip, 'plugin', 'files');

        $this->assertFalse($staged['ok']);
        $this->assertSame('gitupdate.err_php', $staged['error_key']);
        $this->assertStringContainsString('old', (string) file_get_contents($this->root . '/plugins/files/files.php'));
    }

    public function testAMissingInstallIsNotCreated(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->root = sys_get_temp_dir() . '/tm_gitupdate_' . uniqid('', true);
        mkdir($this->root . '/plugins', 0775, true);

        $zip = $this->root . '/snapshot.zip';
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('Wrap/plugins/syntax/syntax.php', '<?php // new');
        $archive->addFromString('Wrap/plugins/syntax/syntax.yaml', "name: Syntax\n");
        $archive->close();

        $installer = new PackageInstaller($this->root);
        $staged = $installer->stage($zip, 'plugin', 'syntax');
        $this->assertTrue($staged['ok'], $staged['error'] ?? '');

        $swapped = $installer->swap('plugin', 'syntax', $staged['path']);
        $this->assertFalse($swapped['ok']);
        $this->assertSame('gitupdate.err_not_installed', $swapped['error_key']);
        $this->assertDirectoryDoesNotExist($this->root . '/plugins/syntax');
    }

    public function testTheLedgerRemembersTheCommitThatWasApplied(): void
    {
        $this->root = sys_get_temp_dir() . '/tm_gitupdate_' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $sha = str_repeat('a', 40);
        $ledger = new Ledger($this->root);

        $this->assertNull($ledger->sha('plugin', 'files'));
        $this->assertTrue($ledger->remember('plugin', 'files', $sha));
        $this->assertSame($sha, (new Ledger($this->root))->sha('plugin', 'files'));
        $this->assertNull($ledger->sha('theme', 'files'));
    }

    public function testTheLedgerKeepsTheCommitDateBesideTheId(): void
    {
        $this->root = sys_get_temp_dir() . '/tm_gitupdate_' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $sha = str_repeat('b', 40);
        $date = '2026-03-12T08:30:00Z';
        $ledger = new Ledger($this->root);

        $this->assertTrue($ledger->remember('theme', 'court', $sha, $date));
        $entry = (new Ledger($this->root))->entry('theme', 'court');
        $this->assertSame($sha, $entry['sha']);
        $this->assertSame($date, $entry['date']);
        $this->assertNull(Reference::commitDate('yesterday'));

        file_put_contents(
            $this->root . '/data/gitupdate/applied.json',
            json_encode(['plugin' => ['files' => $sha]])
        );
        $legacy = (new Ledger($this->root))->entry('plugin', 'files');
        $this->assertSame($sha, $legacy['sha']);
        $this->assertNull($legacy['date']);
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, $contents);
    }
}
