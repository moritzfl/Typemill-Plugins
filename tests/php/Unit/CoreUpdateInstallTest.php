<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\coreupdate\Models\Environment;
use Plugins\coreupdate\Models\Installer;

/**
 * Installing and rolling back a core.
 *
 * The installer has two routes. Renaming is preferred because it is atomic, but
 * some filesystems refuse to rename directories at all - Docker's overlayfs
 * returns EXDEV for anything still in the image's lower layer - so there is a
 * copy fallback. Both are covered here; the copy route is reached through
 * reflection because in a temporary directory renaming always succeeds.
 */
class CoreUpdateInstallTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // Canonical, because the installer fences recursive deletes against the
        // project root. On macOS sys_get_temp_dir() is below /var, a symlink to
        // /private/var, and an uncanonicalised root would make that fence miss
        // every path - so the tests would never exercise it.
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        $this->root = $base . '/coreupdate-install-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testInstallReplacesTheCoreAndKeepsABackup(): void
    {
        $installer = $this->prepare();
        $result = $installer->install($this->stagedSystem($installer));

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertNewCoreIsInstalled();
        $this->assertBackupHoldsPreviousCore($result['backup']);
    }

    /**
     * The copy route overwrites in place, so files that the new version no
     * longer ships have to be pruned explicitly. Missing that would leave stale
     * core files behind.
     */
    public function testCopyInstallRemovesFilesTheNewVersionDropped(): void
    {
        $installer = $this->prepare();

        $method = new \ReflectionMethod($installer, 'installByCopy');
        $result = $method->invoke($installer, $this->stagedSystem($installer));

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('copy', $result['method']);
        $this->assertNewCoreIsInstalled();
        $this->assertBackupHoldsPreviousCore($result['backup']);
    }

    public function testCopyInstallNeverLeavesTheCoreMissing(): void
    {
        $installer = $this->prepare();

        $method = new \ReflectionMethod($installer, 'installByCopy');
        $method->invoke($installer, $this->stagedSystem($installer));

        // The copy route writes over the live tree rather than moving it away,
        // so the core exists throughout.
        $this->assertDirectoryExists($this->root . '/system');
        $this->assertFileExists($this->root . '/system/vendor/autoload.php');
    }

    public function testRollbackRestoresThePreviousCore(): void
    {
        $installer = $this->prepare();
        $result = $installer->install($this->stagedSystem($installer));
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $rollback = $installer->rollback($result['backup']);

        $this->assertTrue($rollback['ok'], $rollback['error'] ?? '');
        $this->assertSame('2.24.1', $this->installedVersion());
        $this->assertFileExists($this->root . '/system/typemill/Dropped.php');
        $this->assertFileDoesNotExist($this->root . '/system/typemill/Added.php');
    }

    /**
     * Rolling back should not be a one-way door: the version rolled back from
     * is parked under a backup name so it can be restored again.
     */
    public function testTheVersionRolledBackFromStaysRestorable(): void
    {
        $installer = $this->prepare();
        $result = $installer->install($this->stagedSystem($installer));
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $rollback = $installer->rollback($result['backup']);
        $this->assertTrue($rollback['ok'], $rollback['error'] ?? '');
        $this->assertSame('2.24.1', $this->installedVersion());

        // Only the rename route can preserve it; the copy route overwrites the
        // newer core in place.
        if ($rollback['method'] === 'rename') {
            $this->assertContains(
                '2.25.0',
                array_column($installer->listBackups(), 'version'),
                'the core rolled back from should remain restorable'
            );
        }
    }

    public function testBackupsAreListedNewestFirstWithTheirVersion(): void
    {
        $installer = $this->prepare();
        $result = $installer->install($this->stagedSystem($installer));
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $backups = $installer->listBackups();

        $this->assertCount(1, $backups);
        $this->assertSame('2.24.1', $backups[0]['version']);
    }

    /**
     * A backup that is empty or truncated must never be installed. Moving one
     * over the live core would take the site down while reporting success.
     */
    public function testRollbackRefusesAnIncompleteBackup(): void
    {
        $installer = $this->prepare();

        $missingEverything = $this->root . '/.tm-coreupdate/backup-empty';
        mkdir($missingEverything, 0777, true);

        $missingSystem = $this->root . '/.tm-coreupdate/backup-nosystem';
        mkdir($missingSystem . '/system', 0777, true);

        $missingVendor = $this->root . '/.tm-coreupdate/backup-partial';
        $this->writeAbsolute($missingVendor . '/system/typemill/settings/defaults.yaml', "version: '2.20.0'\n");

        foreach ([$missingEverything, $missingSystem, $missingVendor] as $backup) {
            $result = $installer->rollback($backup);

            $this->assertFalse($result['ok'], 'Expected rollback to refuse ' . $backup);
            $this->assertFalse($result['touched'], 'Refusing a backup must not modify anything');
        }

        // The live core is exactly as it was.
        $this->assertSame('2.24.1', $this->installedVersion());
        $this->assertFileExists($this->root . '/system/vendor/autoload.php');
        $this->assertFileExists($this->root . '/system/typemill/Dropped.php');
    }

    public function testInstallRefusesAnIncompleteCore(): void
    {
        $installer = $this->prepare();

        $incomplete = $this->root . '/incomplete-system';
        mkdir($incomplete . '/typemill', 0777, true);

        $result = $installer->install($incomplete);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['touched']);
        $this->assertSame('2.24.1', $this->installedVersion());
    }

    /**
     * Merging prunes whatever the source does not have, so an empty source
     * would erase the destination instead of updating it.
     */
    public function testMergingRefusesASourceThatIsNotACore(): void
    {
        $installer = $this->prepare();
        $method = new \ReflectionMethod($installer, 'mergeTree');

        $emptySource = $this->root . '/empty-source';
        mkdir($emptySource, 0777, true);

        $this->assertFalse($method->invoke($installer, $emptySource, $this->root . '/system'));

        $this->assertFileExists($this->root . '/system/vendor/autoload.php');
        $this->assertFileExists($this->root . '/system/typemill/settings/defaults.yaml');
        $this->assertSame('2.24.1', $this->installedVersion());
    }

    public function testCopyingReportsFailureForAMissingSource(): void
    {
        $installer = $this->prepare();
        $method = new \ReflectionMethod($installer, 'copyTree');

        $this->assertFalse($method->invoke($installer, $this->root . '/does-not-exist', $this->root . '/copy-target'));
    }

    /**
     * scandir() returning false must not read as "an empty directory, copied
     * successfully" - that would produce an empty backup reported as taken.
     */
    public function testCopyingReportsFailureWhenTheSourceCannotBeRead(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root, where permission bits do not restrict access.');
        }

        $installer = $this->prepare();

        $unreadable = $this->root . '/unreadable';
        mkdir($unreadable . '/typemill', 0777, true);
        chmod($unreadable, 0000);

        $method = new \ReflectionMethod($installer, 'copyTree');
        $result = $method->invoke($installer, $unreadable, $this->root . '/copy-target');

        chmod($unreadable, 0777);

        $this->assertFalse($result);
    }

    public function testIncompleteBackupsAreNotOffered(): void
    {
        $installer = $this->prepare();

        $broken = $this->root . '/.tm-coreupdate/backup-broken';
        mkdir($broken . '/system', 0777, true);

        $this->assertSame([], $installer->listBackups());
    }

    private function prepare(): Installer
    {
        // Live core: version 2.24.1, ships Dropped.php.
        $this->write('/system/typemill/settings/defaults.yaml', "version: '2.24.1'\n");
        $this->write('/system/typemill/Dropped.php', '<?php // removed in the next release');
        // A whole directory that the next release drops. Pruning a directory
        // goes through the fenced recursive delete, unlike a single file.
        $this->write('/system/typemill/dropped-dir/Legacy.php', '<?php // directory removed in the next release');
        $this->write('/system/typemill/Kept.php', '<?php // old body');
        $this->write('/system/vendor/autoload.php', '<?php // old autoload');
        $this->write('/system/autoload.php', '<?php // legacy shim');

        // User data that must survive untouched.
        $this->write('/content/index.md', '# My site');
        $this->write('/settings/settings.yaml', "title: 'My site'\n");

        return new Installer(new Environment($this->root));
    }

    private function stagedSystem(Installer $installer): string
    {
        $staged = $installer->stagingPath() . '/system';

        // New core: version 2.25.0, ships Added.php, no Dropped.php.
        $this->writeAbsolute($staged . '/typemill/settings/defaults.yaml', "version: '2.25.0'\n");
        $this->writeAbsolute($staged . '/typemill/Added.php', '<?php // new in this release');
        $this->writeAbsolute($staged . '/typemill/Kept.php', '<?php // new body');
        $this->writeAbsolute($staged . '/vendor/autoload.php', '<?php // new autoload');
        $this->writeAbsolute($staged . '/autoload.php', '<?php // legacy shim');

        return $staged;
    }

    private function assertNewCoreIsInstalled(): void
    {
        $this->assertSame('2.25.0', $this->installedVersion());
        $this->assertFileExists($this->root . '/system/typemill/Added.php');
        $this->assertFileDoesNotExist($this->root . '/system/typemill/Dropped.php');
        $this->assertDirectoryDoesNotExist($this->root . '/system/typemill/dropped-dir');
        $this->assertStringContainsString('new body', (string) file_get_contents($this->root . '/system/typemill/Kept.php'));

        // User data is outside system/ and is never part of the operation.
        $this->assertFileExists($this->root . '/content/index.md');
        $this->assertFileExists($this->root . '/settings/settings.yaml');
    }

    private function assertBackupHoldsPreviousCore(string $backup): void
    {
        $this->assertFileExists($backup . '/system/typemill/Dropped.php');
        $this->assertStringContainsString(
            '2.24.1',
            (string) file_get_contents($backup . '/system/typemill/settings/defaults.yaml')
        );
    }

    private function installedVersion(): ?string
    {
        return Environment::parseVersionFromYaml(
            (string) file_get_contents($this->root . '/system/typemill/settings/defaults.yaml')
        );
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
