<?php

namespace Plugins\githubreadme\Models;

/**
 * A pointer to one file in one GitHub repository.
 *
 * The repository is written by hand in the page's meta tab, so every form it is
 * plausibly written in is accepted - the bare `owner/name`, the address from the
 * browser's location bar, the one `git clone` prints - and everything else is
 * refused rather than guessed at. A wrong guess would send a request to a host
 * the author never named.
 */
class RepositoryReference
{
    /**
     * GitHub allows letters, digits, hyphen, underscore and dot in both owner
     * and repository names, and neither may begin with a dot.
     */
    private const NAME = '[A-Za-z0-9_-](?:[A-Za-z0-9._-]*[A-Za-z0-9_-])?';

    private string $owner;
    private string $repository;
    private ?string $branch;
    private ?string $path;

    private function __construct(string $owner, string $repository, ?string $branch, ?string $path)
    {
        $this->owner = $owner;
        $this->repository = $repository;
        $this->branch = $branch;
        $this->path = $path;
    }

    /**
     * Read a reference, or null when the value does not name a GitHub
     * repository.
     *
     * `$branch` and `$path` come from their own fields and win over anything the
     * address contains, because they are the more specific statement.
     */
    public static function parse(string $value, string $branch = '', string $path = ''): ?self
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $branchFromUrl = null;
        $owner = null;
        $repository = null;

        $name = self::NAME;

        // owner/name, as written in the plugin's own field.
        if (preg_match('#^(' . $name . ')/(' . $name . ')$#', $value, $match) === 1) {
            $owner = $match[1];
            $repository = $match[2];
        }

        // The address bar: https://github.com/owner/name, optionally followed by
        // /tree/<branch> or /blob/<branch>/<file>, and optionally with .git.
        if ($owner === null && preg_match(
            '#^(?:https?://)?(?:www\.)?github\.com/(' . $name . ')/(' . $name . ')(?:\.git)?'
            . '(?:/(?:tree|blob)/([^/\#?]+)(?:/([^\#?]*))?)?/?(?:[\#?].*)?$#',
            $value,
            $match
        ) === 1) {
            $owner = $match[1];
            $repository = $match[2];
            $branchFromUrl = ($match[3] ?? '') !== '' ? urldecode($match[3]) : null;

            if (($match[4] ?? '') !== '') {
                $path = $path !== '' ? $path : urldecode($match[4]);
            }
        }

        // What `git clone` prints: git@github.com:owner/name.git
        if ($owner === null && preg_match('#^git@github\.com:(' . $name . ')/(' . $name . ')(?:\.git)?$#', $value, $match) === 1) {
            $owner = $match[1];
            $repository = $match[2];
        }

        if ($owner === null || $repository === null) {
            return null;
        }

        // A dot is a legal character in a repository name, so the name pattern
        // swallows the .git that a clone address ends with. GitHub does not allow
        // a repository to be called that, so a trailing .git is always a suffix.
        if (strlen($repository) > 4 && strtolower(substr($repository, -4)) === '.git') {
            $repository = substr($repository, 0, -4);
        }

        $branch = self::cleanBranch($branch !== '' ? $branch : (string) $branchFromUrl);
        $path = self::cleanPath($path);

        if ($path === false || $branch === false) {
            return null;
        }

        return new self($owner, $repository, $branch, $path);
    }

    /**
     * A branch name reaches a URL, so the characters that would change what the
     * URL means are refused rather than encoded away.
     */
    private static function cleanBranch(string $branch)
    {
        $branch = trim($branch);

        if ($branch === '') {
            return null;
        }

        return preg_match('#^[A-Za-z0-9._/-]+$#', $branch) === 1 && !str_contains($branch, '..')
            ? $branch
            : false;
    }

    /** A path inside the repository, refused when it tries to climb out of it. */
    private static function cleanPath(string $path)
    {
        $path = trim(trim($path), '/');

        if ($path === '') {
            return null;
        }

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9._/-]+$#', $path) === 1 ? $path : false;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    /** The branch the author named, or null to let GitHub pick the default one. */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /** The file the author named, or null for whatever GitHub calls the readme. */
    public function path(): ?string
    {
        return $this->path;
    }

    public function slug(): string
    {
        return $this->owner . '/' . $this->repository;
    }

    /** Stable across requests, and safe as a file name. */
    public function cacheKey(): string
    {
        return sha1(implode("\n", [$this->owner, $this->repository, (string) $this->branch, (string) $this->path]));
    }

    /**
     * Where the API is asked for the file.
     *
     * Without a path this is the readme endpoint, which is the only way to learn
     * what the readme is called - GitHub accepts README.md, readme.rst, and
     * several more - and which follows the default branch on its own.
     */
    public function apiPath(): string
    {
        $base = '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repository);

        $target = $this->path === null
            ? $base . '/readme'
            : $base . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $this->path)));

        return $this->branch === null
            ? $target
            : $target . '?ref=' . rawurlencode($this->branch);
    }

    public function webUrl(): string
    {
        return 'https://github.com/' . $this->owner . '/' . $this->repository;
    }
}
