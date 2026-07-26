<?php

namespace Plugins\githubreadme\Models;

/**
 * The "view this on GitHub" line that the readme itself does not carry.
 *
 * A readme rarely links to its own repository - on github.com it is already
 * there - so on a site that shows the readme the way back has to be added.
 *
 * The wording is translated here rather than through Typemill's translator,
 * which loads plugin language files for the admin only: on a public page it
 * would hand back the key. The files are read straight from the plugin folder
 * instead, in Typemill's own format, so adding a language is adding a file.
 */
class RepositoryLink
{
    /** Shown when no language file has a wording for the site's language. */
    private const FALLBACK = 'View on GitHub';

    private const KEY = 'GITHUBREADME_VIEW_ON_GITHUB';

    private string $directory;

    /** @param string $directory The plugin's own folder, where <lang>.yaml lives. */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $language The site's language, as set in the settings.
     * @param string $custom   Wording from the settings, which wins over the
     *                         translation: an author's own words are not
     *                         second-guessed.
     */
    public function html(RepositoryReference $reference, string $language, string $custom = ''): string
    {
        $label = trim($custom) !== '' ? trim($custom) : $this->label($language);
        $url = $this->url($reference);

        return '<p class="github-readme__source">'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
            . ' rel="noopener noreferrer"'
            . ' class="github-readme__source-link">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</a></p>';
    }

    /**
     * Where the link goes: the file when one was named, the repository when the
     * readme was left to GitHub - which is the page that shows the readme.
     */
    public function url(RepositoryReference $reference): string
    {
        if ($reference->path() === null) {
            return $reference->branch() === null
                ? $reference->webUrl()
                : $reference->webUrl() . '/tree/' . $reference->branch();
        }

        return $reference->webUrl() . '/blob/' . ($reference->branch() ?? 'HEAD') . '/' . $reference->path();
    }

    public function label(string $language): string
    {
        $language = strtolower(trim($language));

        // A language may be written as de-DE; the file is de.yaml.
        $candidates = [];
        if (preg_match('/^([a-z]{2})(?:[_-][a-z]{2})?$/', $language, $match) === 1) {
            $candidates[] = $match[1];
        }
        $candidates[] = 'en';

        foreach ($candidates as $candidate) {
            $label = $this->fromFile($candidate);

            if ($label !== null) {
                return $label;
            }
        }

        return self::FALLBACK;
    }

    /**
     * Read one wording out of a language file.
     *
     * Parsed by line rather than with a YAML library: the files are flat
     * KEY: "value" lists, and this runs on every page view that shows a readme.
     */
    private function fromFile(string $language): ?string
    {
        if (preg_match('/^[a-z]{2}$/', $language) !== 1) {
            return null;
        }

        $file = $this->directory . DIRECTORY_SEPARATOR . $language . '.yaml';

        if (!is_file($file)) {
            return null;
        }

        foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^' . self::KEY . ':\s*(.*)$/', $line, $match) === 1) {
                $label = trim(trim($match[1]), '"\'');

                return $label !== '' ? $label : null;
            }
        }

        return null;
    }
}
