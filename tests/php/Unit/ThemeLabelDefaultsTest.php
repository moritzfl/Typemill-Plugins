<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Theme labels must be able to follow the site language.
 *
 * A theme writes its fixed words as `theme.homeLabel|default(translate('Home'))`,
 * which reads: the admin's own wording if they set one, the theme's translation
 * otherwise. Twig only reaches that fallback while the setting is empty.
 *
 * The theme's shipped `settings:` block is not merely a documentation of the
 * defaults - Typemill copies it into settings.yaml for the active theme
 * (Settings.php, "if(!isset($settings['themes']))"), and saving the theme form
 * stores whatever the fields were prefilled with. So an English default shipped
 * beside a translated fallback ends up stored on the site, wins over the
 * translation for good, and a German site keeps saying "Home".
 *
 * The pairs are derived from the templates rather than listed here, so a new
 * label is covered the day it is written.
 */
class ThemeLabelDefaultsTest extends TestCase
{
    /** @return array<string, string> theme name => directory */
    private function themes(): array
    {
        $root = is_dir('/var/www/html/themes')
            ? '/var/www/html/themes'
            : dirname(__DIR__, 3) . '/themes';

        $themes = [];
        foreach ((array) scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) {
                continue;
            }

            if (is_file($root . '/' . $entry . '/' . $entry . '.yaml')) {
                $themes[$entry] = $root . '/' . $entry;
            }
        }

        return $themes;
    }

    /**
     * Settings the templates print through translate(), so a stored value would
     * take the place of the translation.
     *
     * @return array<int, string>
     */
    private function translatedSettings(string $theme, string $directory): array
    {
        $keys = [];

        $templates = array_merge(
            (array) glob($directory . '/*.twig'),
            (array) glob($directory . '/partials/*.twig')
        );

        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);

            // theme.homeLabel|default(translate('Home'))
            // settings.themes.lucid.homeLabel|default(translate('Home'))
            $pattern = '/(?:theme|settings\.themes\.' . preg_quote($theme, '/') . ')'
                . '\.([A-Za-z0-9_]+)\s*\|\s*default\(\s*translate\(/';

            if (preg_match_all($pattern, $source, $matches) > 0) {
                $keys = array_merge($keys, $matches[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The `settings:` block of a theme yaml, as raw key => value strings.
     *
     * Read by hand rather than through a YAML parser: the check is about what
     * the file literally ships, and the block is flat.
     *
     * @return array<string, string>
     */
    private function shippedSettings(string $yaml): array
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $settings = [];
        $inside = false;

        foreach ($lines as $line) {
            if (preg_match('/^settings:\s*$/', $line) === 1) {
                $inside = true;
                continue;
            }

            if (!$inside) {
                continue;
            }

            // A new top-level key ends the block.
            if (preg_match('/^\S/', $line) === 1) {
                break;
            }

            if (preg_match('/^  ([A-Za-z0-9_]+):[ \t]*(.*)$/', $line, $match) === 1) {
                $settings[$match[1]] = trim($match[2]);
            }
        }

        return $settings;
    }

    private function isEmptyValue(string $value): bool
    {
        return $value === '' || $value === "''" || $value === '""' || $value === '{  }' || $value === '[]';
    }

    public function testTranslatedLabelsShipWithoutAnEnglishDefault(): void
    {
        $offences = [];
        $checked = 0;

        foreach ($this->themes() as $theme => $directory) {
            $yaml = (string) file_get_contents($directory . '/' . $theme . '.yaml');
            $shipped = $this->shippedSettings($yaml);

            foreach ($this->translatedSettings($theme, $directory) as $key) {
                if (!array_key_exists($key, $shipped)) {
                    continue;
                }

                $checked++;

                if (!$this->isEmptyValue($shipped[$key])) {
                    $offences[] = sprintf(
                        '%s.yaml ships %s: %s, which wins over translate() for good',
                        $theme,
                        $key,
                        $shipped[$key]
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No translated label settings were found to check');
        $this->assertSame([], $offences, implode("\n", $offences));
    }

    /**
     * Readymades are presets an admin applies with one click, and applying one
     * stores every value in it. A label in there is stored English too.
     */
    public function testReadymadesDoNotStoreEnglishLabels(): void
    {
        $offences = [];

        foreach ($this->themes() as $theme => $directory) {
            $yaml = (string) file_get_contents($directory . '/' . $theme . '.yaml');
            $translated = $this->translatedSettings($theme, $directory);

            if ($translated === [] || preg_match('/^readymades:\s*$/m', $yaml) !== 1) {
                continue;
            }

            $block = preg_split('/^forms:\s*$/m', substr($yaml, (int) strpos($yaml, 'readymades:')))[0] ?? '';

            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if (preg_match('/^      ([A-Za-z0-9_]+):[ \t]*(.*)$/', $line, $match) !== 1) {
                    continue;
                }

                if (in_array($match[1], $translated, true) && !$this->isEmptyValue(trim($match[2]))) {
                    $offences[] = sprintf(
                        '%s.yaml has a readymade setting %s: %s, which stores English on every site that applies it',
                        $theme,
                        $match[1],
                        trim($match[2])
                    );
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", $offences));
    }
}
