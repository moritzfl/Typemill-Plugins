<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\versions\Models\VersionStore;

/**
 * Tests for VersionStore settings sanitization helpers:
 *   - getSettings()       — validates and returns plugin settings with defaults
 *   - sanitizeInteger()   — clamps integers to a [min, max] range
 *   - sanitizeArchiveName() — produces safe filenames for zip downloads
 */
class VersionStoreSettingsTest extends TestCase
{
    private VersionStore $store;

    protected function setUp(): void
    {
        $this->store = new VersionStore();
    }

    // -------------------------------------------------------------------------
    // getSettings — defaults
    // -------------------------------------------------------------------------

    public function testGetSettingsReturnsDefaultsForEmptyInput(): void
    {
        $settings = $this->store->getSettings([]);

        $this->assertSame(30,   $settings['retention_days']);
        $this->assertSame(24,   $settings['group_hours']);
        $this->assertSame(50,   $settings['max_versions']);
    }

    public function testGetSettingsPassesThroughValidValues(): void
    {
        $settings = $this->store->getSettings([
            'retention_days' => 7,
            'group_hours'    => 6,
            'max_versions'   => 100,
        ]);

        $this->assertSame(7,   $settings['retention_days']);
        $this->assertSame(6,   $settings['group_hours']);
        $this->assertSame(100, $settings['max_versions']);
    }

    public function testGetSettingsClampsRetentionDaysBelowMin(): void
    {
        $settings = $this->store->getSettings(['retention_days' => 0]);
        $this->assertSame(1, $settings['retention_days']);
    }

    public function testGetSettingsClampsRetentionDaysAboveMax(): void
    {
        $settings = $this->store->getSettings(['retention_days' => 9999]);
        $this->assertSame(3650, $settings['retention_days']);
    }

    public function testGetSettingsClampsGroupHoursBelowMin(): void
    {
        $settings = $this->store->getSettings(['group_hours' => 0]);
        $this->assertSame(1, $settings['group_hours']);
    }

    public function testGetSettingsClampsGroupHoursAboveMax(): void
    {
        $settings = $this->store->getSettings(['group_hours' => 999]);
        $this->assertSame(120, $settings['group_hours']);
    }

    public function testGetSettingsClampsMaxVersionsBelowMin(): void
    {
        $settings = $this->store->getSettings(['max_versions' => 0]);
        $this->assertSame(1, $settings['max_versions']);
    }

    public function testGetSettingsClampsMaxVersionsAboveMax(): void
    {
        $settings = $this->store->getSettings(['max_versions' => 9999]);
        $this->assertSame(1000, $settings['max_versions']);
    }

    public function testGetSettingsFallsBackToDefaultForNonNumericValue(): void
    {
        $settings = $this->store->getSettings(['retention_days' => 'many']);
        $this->assertSame(30, $settings['retention_days']);
    }

    public function testGetSettingsAcceptsStringNumericValues(): void
    {
        // HTML form data comes in as strings.
        $settings = $this->store->getSettings(['max_versions' => '10']);
        $this->assertSame(10, $settings['max_versions']);
    }

    // -------------------------------------------------------------------------
    // sanitizeInteger — direct boundary tests via reflection
    // -------------------------------------------------------------------------

    public function testSanitizeIntegerReturnsValueWithinRange(): void
    {
        $this->assertSame(5, $this->sanitize(5, 1, 10, 7));
    }

    public function testSanitizeIntegerClampsToMin(): void
    {
        $this->assertSame(1, $this->sanitize(0, 1, 10, 7));
    }

    public function testSanitizeIntegerClampsToMax(): void
    {
        $this->assertSame(10, $this->sanitize(99, 1, 10, 7));
    }

    public function testSanitizeIntegerReturnsDefaultForNonNumeric(): void
    {
        $this->assertSame(7, $this->sanitize('nope', 1, 10, 7));
    }

    public function testSanitizeIntegerAcceptsExactMin(): void
    {
        $this->assertSame(1, $this->sanitize(1, 1, 10, 7));
    }

    public function testSanitizeIntegerAcceptsExactMax(): void
    {
        $this->assertSame(10, $this->sanitize(10, 1, 10, 7));
    }

    public function testSanitizeIntegerCastsFloatToInt(): void
    {
        $this->assertSame(3, $this->sanitize(3.9, 1, 10, 7));
    }

    // -------------------------------------------------------------------------
    // sanitizeArchiveName — via reflection
    // -------------------------------------------------------------------------

    public function testNormalNameIsReturnedUnchanged(): void
    {
        $this->assertSame('my-page', $this->archiveName('my-page'));
    }

    public function testSpacesAreReplacedWithHyphens(): void
    {
        $this->assertSame('my-page', $this->archiveName('my page'));
    }

    public function testSlashIsReplaced(): void
    {
        $this->assertSame('foo-bar', $this->archiveName('foo/bar'));
    }

    public function testDotsArePreserved(): void
    {
        $this->assertSame('page.v1', $this->archiveName('page.v1'));
    }

    public function testUppercaseIsPreserved(): void
    {
        $this->assertSame('MyPage', $this->archiveName('MyPage'));
    }

    public function testLeadingAndTrailingHyphensAreTrimmed(): void
    {
        $this->assertSame('foo', $this->archiveName('-foo-'));
    }

    public function testLeadingAndTrailingDotsAreTrimmed(): void
    {
        $this->assertSame('foo', $this->archiveName('.foo.'));
    }

    public function testEmptyStringReturnsFallback(): void
    {
        $this->assertSame('trash-entry', $this->archiveName(''));
    }

    public function testWhitespaceOnlyReturnsFallback(): void
    {
        $this->assertSame('trash-entry', $this->archiveName('   '));
    }

    public function testAllSpecialCharsReturnFallback(): void
    {
        $this->assertSame('trash-entry', $this->archiveName('!!!'));
    }

    public function testMultipleConsecutiveSpecialCharsCollapseToOneHyphen(): void
    {
        $result = $this->archiveName('foo  bar');
        // Two spaces → single hyphen
        $this->assertSame('foo-bar', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sanitize(mixed $value, int $min, int $max, int $default): int
    {
        $method = new \ReflectionMethod($this->store, 'sanitizeInteger');
        return $method->invoke($this->store, $value, $min, $max, $default);
    }

    private function archiveName(string $value): string
    {
        $downloads = new \Plugins\versions\Models\TrashDownloadService(
            new \Plugins\versions\Models\AssetVersionStore(
                new \Typemill\Models\StorageWrapper('\Typemill\Models\Storage'),
                new \Plugins\versions\Models\VersionRecordRepository(
                    new \Typemill\Models\StorageWrapper('\Typemill\Models\Storage'),
                    'versions'
                ),
                new \Plugins\versions\Models\LineDiff()
            )
        );

        return $downloads->sanitizeArchiveName($value);
    }
}
