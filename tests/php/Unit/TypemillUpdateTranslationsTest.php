<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\typemillupdate\Models\Environment;

/**
 * Every sentence the updater puts in front of an administrator has to exist in
 * both languages.
 *
 * The panel resolves a key to a string and falls back to the English sentence
 * the backend sent when there is no entry for it, so a missing key is silent:
 * the German admin simply reads English, and nothing fails. This closes that
 * gap by checking the keys the code really uses - the literal ones in the
 * sources, and the environment-check labels, which are assembled at runtime and
 * are therefore taken from a real run rather than from a regex.
 */
class TypemillUpdateTranslationsTest extends TestCase
{
    private function pluginPath(): string
    {
        return is_dir('/var/www/html/plugins/typemillupdate')
            ? '/var/www/html/plugins/typemillupdate'
            : dirname(__DIR__, 3) . '/plugins/typemillupdate';
    }

    /**
     * The language files are flat `KEY: value` lists, so they are read as such;
     * this keeps the test independent of a YAML library.
     *
     * @return array<string, string>
     */
    private function language(string $file): array
    {
        $entries = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+):\s*(.*)$/', $line, $match) === 1) {
                $entries[$match[1]] = trim($match[2], " \"'");
            }
        }

        return $entries;
    }

    /** `typemillupdate.err_upload_empty` is `TYPEMILLUPDATE_ERR_UPLOAD_EMPTY`. */
    private function toLanguageKey(string $key): string
    {
        return strtoupper(str_replace('.', '_', $key));
    }

    /** @return array<int, string> */
    private function referencedKeys(): array
    {
        $keys = [];

        $php = array_merge(
            (array) glob($this->pluginPath() . '/*.php'),
            (array) glob($this->pluginPath() . '/Models/*.php')
        );

        foreach ($php as $source) {
            $code = (string) file_get_contents($source);

            // Written out in full. Only msg_ and err_ keys: route names live in
            // the same namespace ('typemillupdate.status' and the like) and are
            // not sentences.
            if (preg_match_all('/[\'"]typemillupdate\.((?:msg|err)_[a-z0-9_]+)[\'"]/', $code, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[] = 'typemillupdate.' . $key;
                }
            }

            // Assembled by the failure helpers, which take the key without its
            // prefix: self::problem($sentence, 'err_upload_empty'). Matched at
            // the call and bounded to the statement, so the helper's own body -
            // which names 'error' and 'error_key' - is not mistaken for a key.
            if (preg_match_all('/self::problem\([^;]*?,\s*\'([a-z0-9_]+)\'/', $code, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[] = 'typemillupdate.' . $key;
                }
            }
        }

        // The panel's own wording, which is only ever named in the template and
        // the script.
        foreach ((array) glob($this->pluginPath() . '/js/*') as $source) {
            if (preg_match_all('/[\'"]typemillupdate\.([a-z0-9_]+)[\'"]/', (string) file_get_contents($source), $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[] = 'typemillupdate.' . $key;
                }
            }
        }

        // The environment checks build their own keys, so they are read from a
        // real run instead of from the source.
        $root = sys_get_temp_dir() . '/typemillupdate-translations-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            foreach ((new Environment($root))->preflight() as $check) {
                if (($check['label'] ?? '') !== '') {
                    $keys[] = $check['label'];
                }
            }
        } finally {
            foreach ((array) glob($root . '/*') as $leftover) {
                is_dir($leftover) ? @rmdir($leftover) : @unlink($leftover);
            }
            @rmdir($root);
        }

        return array_values(array_unique($keys));
    }

    public function testEveryKeyTheCodeUsesExistsInBothLanguages(): void
    {
        $english = $this->language($this->pluginPath() . '/en.yaml');
        $german = $this->language($this->pluginPath() . '/de.yaml');
        $referenced = $this->referencedKeys();

        $this->assertNotEmpty($referenced, 'No translation keys were found in the plugin');

        $missing = [];

        foreach ($referenced as $key) {
            $languageKey = $this->toLanguageKey($key);

            if (!array_key_exists($languageKey, $english)) {
                $missing[] = $languageKey . ' (used as ' . $key . ') is missing from en.yaml';
            }
            if (!array_key_exists($languageKey, $german)) {
                $missing[] = $languageKey . ' (used as ' . $key . ') is missing from de.yaml';
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function testBothLanguagesCarryTheSameKeys(): void
    {
        $english = array_keys($this->language($this->pluginPath() . '/en.yaml'));
        $german = array_keys($this->language($this->pluginPath() . '/de.yaml'));

        sort($english);
        sort($german);

        $this->assertSame(
            [],
            array_values(array_diff($english, $german)),
            'Present in en.yaml but not in de.yaml'
        );
        $this->assertSame(
            [],
            array_values(array_diff($german, $english)),
            'Present in de.yaml but not in en.yaml'
        );
    }

    /**
     * A translation carries the values the panel substitutes into it. If one
     * language drops a placeholder, that language loses the path or the size the
     * sentence was about.
     */
    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        $english = $this->language($this->pluginPath() . '/en.yaml');
        $german = $this->language($this->pluginPath() . '/de.yaml');

        $placeholders = static function (string $text): array {
            preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $matches);
            $names = array_unique($matches[1]);
            sort($names);

            return $names;
        };

        $mismatched = [];

        foreach ($english as $key => $text) {
            if (!isset($german[$key])) {
                continue;
            }

            $one = $placeholders($text);
            $other = $placeholders($german[$key]);

            if ($one !== $other) {
                $mismatched[] = sprintf(
                    '%s: en has {%s}, de has {%s}',
                    $key,
                    implode('}, {', $one) ?: '-',
                    implode('}, {', $other) ?: '-'
                );
            }
        }

        $this->assertSame([], $mismatched, implode("\n", $mismatched));
    }
}
