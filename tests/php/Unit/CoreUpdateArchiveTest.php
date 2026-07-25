<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\coreupdate\Models\Environment;
use Plugins\coreupdate\Models\Installer;
use Plugins\coreupdate\Models\Release;
use ZipArchive;

/**
 * Archive handling for the core updater, exercised against synthetic archives
 * shaped like a real Typemill release.
 *
 * The release published on typemill.net is a complete fresh-install image: it
 * carries content/, media/, settings/ and themes/ alongside system/. Unpacking
 * it wholesale would overwrite a live site, so the decisive test here is that
 * staging takes system/ and nothing else.
 */
class CoreUpdateArchiveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        $this->root = $base . '/coreupdate-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testValidReleaseArchiveIsAccepted(): void
    {
        $zipPath = $this->root . '/release.zip';
        $this->makeZip($zipPath, $this->releaseEntries());

        $result = (new Release(new Environment($this->root)))->inspectArchive($zipPath);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('2.25.0', $result['version']);
        $this->assertSame(80100, $result['php_floor']);
        $this->assertGreaterThan(0, $result['system_entries']);
    }

    public function testArchiveWithoutVendorAutoloadIsRejected(): void
    {
        $entries = $this->releaseEntries();
        unset($entries['system/vendor/autoload.php']);

        $zipPath = $this->root . '/incomplete.zip';
        $this->makeZip($zipPath, $entries);

        $result = (new Release(new Environment($this->root)))->inspectArchive($zipPath);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('system/vendor/autoload.php', $result['error']);
    }

    public function testArchiveWithoutDefaultsYamlIsRejected(): void
    {
        $entries = $this->releaseEntries();
        unset($entries['system/typemill/settings/defaults.yaml']);

        $zipPath = $this->root . '/nodefaults.zip';
        $this->makeZip($zipPath, $entries);

        $result = (new Release(new Environment($this->root)))->inspectArchive($zipPath);

        $this->assertFalse($result['ok']);
    }

    public function testNonZipFileIsRejected(): void
    {
        $path = $this->root . '/not-a-zip.zip';
        file_put_contents($path, 'this is not a zip archive');

        $result = (new Release(new Environment($this->root)))->inspectArchive($path);

        $this->assertFalse($result['ok']);
    }

    public function testArchiveWithTraversalEntryIsRejected(): void
    {
        $entries = $this->releaseEntries();
        $entries['../escaped.php'] = '<?php // should never be unpacked';

        $zipPath = $this->root . '/traversal.zip';
        $this->makeZip($zipPath, $entries);

        if (!$this->archiveContainsTraversalEntry($zipPath)) {
            $this->markTestSkipped('This ZipArchive build normalises traversal entry names on write.');
        }

        $result = (new Release(new Environment($this->root)))->inspectArchive($zipPath);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unsafe path', $result['error']);
    }

    /**
     * The point of the whole plugin: a core update must not touch user data.
     */
    public function testStagingExtractsOnlyTheSystemDirectory(): void
    {
        $zipPath = $this->root . '/release.zip';
        $this->makeZip($zipPath, $this->releaseEntries());

        $installer = new Installer(new Environment($this->root));
        $result = $installer->stage($zipPath);

        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $staging = $installer->stagingPath();
        $this->assertDirectoryExists($staging . '/system/typemill');
        $this->assertFileExists($staging . '/system/vendor/autoload.php');
        $this->assertFileExists($staging . '/system/autoload.php');

        // Everything the fresh-install image also carries must be ignored.
        $this->assertFileDoesNotExist($staging . '/content/index.md');
        $this->assertFileDoesNotExist($staging . '/settings/settings.yaml');
        $this->assertFileDoesNotExist($staging . '/media/live/photo.jpg');
        $this->assertFileDoesNotExist($staging . '/themes/cyanine/cyanine.yaml');
        $this->assertFileDoesNotExist($staging . '/plugins/demo/demo.php');
        $this->assertFileDoesNotExist($staging . '/index.php');
        $this->assertDirectoryDoesNotExist($staging . '/content');
    }

    public function testStagingRejectsAnArchiveWithoutACore(): void
    {
        $zipPath = $this->root . '/nocore.zip';
        $this->makeZip($zipPath, [
            'content/index.md' => '# Hello',
            'index.php' => '<?php',
        ]);

        $result = (new Installer(new Environment($this->root)))->stage($zipPath);

        $this->assertFalse($result['ok']);
    }

    /** A minimal archive shaped like the published release image. */
    private function releaseEntries(): array
    {
        return [
            'system/autoload.php' => '<?php // core autoload',
            'system/typemill/settings/defaults.yaml' => "version: '2.25.0'\ntitle: 'Typemill'\n",
            'system/typemill/Models/Settings.php' => '<?php // core model',
            'system/vendor/autoload.php' => '<?php // composer autoload',
            'system/vendor/composer/platform_check.php' => "<?php\nif (!(PHP_VERSION_ID >= 80100)) {\n    \$issues[] = 'need php';\n}\n",

            // Fresh-install payload that must never be unpacked over a live site.
            'index.php' => '<?php require "system/vendor/autoload.php";',
            'content/index.md' => '# Demo content',
            'settings/settings.yaml' => "title: 'Demo'\n",
            'media/live/photo.jpg' => 'binary',
            'themes/cyanine/cyanine.yaml' => "name: Cyanine\n",
            'plugins/demo/demo.php' => '<?php // demo plugin',
        ];
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
            if (!Release::isSafeEntryName((string) $zip->getNameIndex($i))) {
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
