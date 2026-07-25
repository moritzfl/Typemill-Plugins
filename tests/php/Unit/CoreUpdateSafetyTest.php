<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\coreupdate\Models\Environment;
use Plugins\coreupdate\Models\Release;

/**
 * Guards for the pure decisions the core updater makes before it touches
 * anything: which archive entries may be unpacked, which version an archive
 * claims, and which PHP version it demands.
 */
class CoreUpdateSafetyTest extends TestCase
{
    /**
     * Entry names decide where files land during extraction, so this is the
     * Zip Slip boundary.
     */
    public function testTraversalAndAbsoluteEntryNamesAreRejected(): void
    {
        $rejected = [
            '../evil.php',
            'system/../../evil.php',
            'system/typemill/../../../evil.php',
            '/etc/passwd',
            '\\windows\\system32',
            'C:/windows/system32',
            '..',
            '',
            "system/evil\0.php",
        ];

        foreach ($rejected as $name) {
            $this->assertFalse(
                Release::isSafeEntryName($name),
                'Expected entry to be rejected: ' . var_export($name, true)
            );
        }
    }

    public function testOrdinaryEntryNamesAreAccepted(): void
    {
        $accepted = [
            'system/autoload.php',
            'system/typemill/settings/defaults.yaml',
            'system/vendor/composer/autoload_real.php',
            'content/index.md',
            'a..b/file.txt',
        ];

        foreach ($accepted as $name) {
            $this->assertTrue(
                Release::isSafeEntryName($name),
                'Expected entry to be accepted: ' . var_export($name, true)
            );
        }
    }

    /**
     * The release archive is a full fresh-install image. Only system/ may ever
     * be unpacked; everything else would overwrite the live site.
     */
    public function testOnlySystemEntriesCountAsCore(): void
    {
        $this->assertTrue(Release::isSystemEntry('system/autoload.php'));
        $this->assertTrue(Release::isSystemEntry('system/typemill/settings/defaults.yaml'));

        $this->assertFalse(Release::isSystemEntry('system/'));
        $this->assertFalse(Release::isSystemEntry('content/index.md'));
        $this->assertFalse(Release::isSystemEntry('settings/settings.yaml'));
        $this->assertFalse(Release::isSystemEntry('media/live/photo.jpg'));
        $this->assertFalse(Release::isSystemEntry('plugins/versions/versions.php'));
        $this->assertFalse(Release::isSystemEntry('themes/cyanine/cyanine.yaml'));
        $this->assertFalse(Release::isSystemEntry('index.php'));
        $this->assertFalse(Release::isSystemEntry('systemx/file.php'));
    }

    public function testDownloadUrlStripsDotsFromVersion(): void
    {
        $this->assertSame(
            'https://typemill.net/media/files/typemill-2250.zip',
            Release::downloadUrl('2.25.0')
        );
        $this->assertSame(
            'https://typemill.net/media/files/typemill-2242.zip',
            Release::downloadUrl('2.24.2')
        );
    }

    public function testVersionIsReadFromDefaultsYaml(): void
    {
        $yaml = "version: '2.25.0'\ntitle: 'Typemill'\n";
        $this->assertSame('2.25.0', Environment::parseVersionFromYaml($yaml));

        $unquoted = "version: 2.24.1\n";
        $this->assertSame('2.24.1', Environment::parseVersionFromYaml($unquoted));

        $this->assertNull(Environment::parseVersionFromYaml("title: 'Typemill'\n"));
    }

    /**
     * A release may raise its PHP floor. The floor is read from the vendor tree
     * inside the archive, before the swap, so an incompatible update aborts
     * while the site is still intact.
     */
    public function testPhpFloorIsReadFromPlatformCheck(): void
    {
        $platformCheck = <<<'PHP'
<?php
$issues = array();

if (!(PHP_VERSION_ID >= 80100)) {
    $issues[] = 'Your Composer dependencies require a PHP version ">= 8.1.0".';
}
PHP;

        $this->assertSame(80100, Environment::parsePhpFloor($platformCheck));
        $this->assertNull(Environment::parsePhpFloor('<?php // nothing here'));
    }

    public function testBlockedOnlyWhenABlockingCheckFails(): void
    {
        $passing = [
            ['id' => 'a', 'ok' => true, 'blocking' => true, 'detail' => ''],
            ['id' => 'b', 'ok' => false, 'blocking' => false, 'detail' => ''],
        ];
        $this->assertFalse(Environment::isBlocked($passing));

        $failing = [
            ['id' => 'a', 'ok' => true, 'blocking' => true, 'detail' => ''],
            ['id' => 'b', 'ok' => false, 'blocking' => true, 'detail' => ''],
        ];
        $this->assertTrue(Environment::isBlocked($failing));
    }
}
