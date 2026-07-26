<?php

namespace Plugins\readmemd\Models;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Turns a readme into HTML that belongs on this site.
 *
 * Two things have to happen on the way. A readme is written to be read on
 * github.com, so every relative link and picture in it points at a file in the
 * repository, not at a page here; those are pointed back at GitHub. And a readme
 * is somebody else's HTML, so what it may contain is limited - the document is
 * parsed and walked rather than pattern-matched, because a denylist applied with
 * regular expressions is a denylist with holes.
 */
class ReadmeRenderer
{
    /**
     * Removed with their contents: none of them belong in an article, and each
     * is a way to run something or to load something from elsewhere.
     */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'textarea', 'meta', 'link', 'base',
        // Inline SVG is kept - readmes draw badges with it - but this is the one
        // element inside it that can carry a document of its own.
        'foreignObject',
    ];

    /** Only these can carry a URL, and only to somewhere sensible. */
    private const URL_ATTRIBUTES = ['href', 'src', 'srcset', 'poster', 'action', 'formaction', 'data', 'xlink:href'];

    /** @var callable(string): string */
    private $markdownToHtml;

    /** @param callable(string): string $markdownToHtml */
    public function __construct(callable $markdownToHtml)
    {
        $this->markdownToHtml = $markdownToHtml;
    }

    /**
     * @param bool $dropTitle    Drop the readme's own first heading, because the
     *                           page already has a title of its own.
     * @param bool $allowHtml    Keep the raw HTML a readme carries - badges are
     *                           written that way - or strip it out entirely.
     */
    public function toHtml(string $markdown, RepositoryReference $reference, bool $dropTitle = true, bool $allowHtml = true): string
    {
        if ($dropTitle) {
            $markdown = self::withoutLeadingHeading($markdown);
        }

        $html = ($this->markdownToHtml)($markdown);

        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        return $this->rewriteAndClean($html, $reference, $allowHtml);
    }

    /**
     * Remove the first heading, and only if it is the first thing in the file.
     *
     * A readme usually opens with the project's name, which is what the page is
     * called as well; printing it twice reads like a mistake. Anything further
     * down is part of the text and stays.
     */
    public static function withoutLeadingHeading(string $markdown): string
    {
        $withoutBom = preg_replace('/^\xEF\xBB\xBF/', '', $markdown) ?? $markdown;

        // An HTML comment or a badge paragraph may come first; only a heading is
        // dropped, and only before any other content.
        $pattern = '/^\s*(?:#\s+[^\n]*|[^\n]+\n=+)\s*(?:\n|$)/';

        return preg_replace($pattern, '', $withoutBom, 1) ?? $withoutBom;
    }

    private function rewriteAndClean(string $html, RepositoryReference $reference, bool $allowHtml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        // Fragment, not a document: the wrapper gives libxml a single root and a
        // declared encoding, and is unwrapped again afterwards.
        $wrapped = '<?xml encoding="UTF-8"?><div id="tm-readme-md">' . $html . '</div>';

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Unparseable markup is not shown at all: it cannot be checked, and
            // an unchecked readme is not worth the risk of rendering.
            return '';
        }

        $root = $document->getElementById('tm-readme-md');
        if ($root === null) {
            return '';
        }

        $this->removeForbidden($document);
        $this->cleanAttributes($document, $reference);
        $this->wrapTables($document);

        if (!$allowHtml) {
            // Everything that survived is still markup this site did not write.
            // With raw HTML switched off, only what Markdown itself produced
            // stays, which is what stripping unknown elements amounts to.
            $this->unwrapRawHtml($document);
        }

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $document->saveHTML($child);
        }

        return trim($inner);
    }

    private function removeForbidden(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $query = implode(' | ', array_map(static fn (string $tag): string => '//' . $tag, self::FORBIDDEN_ELEMENTS));

        // Collected first: removing while iterating a live node list skips nodes.
        $doomed = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $doomed[] = $node;
        }

        foreach ($doomed as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function cleanAttributes(DOMDocument $document, RepositoryReference $reference): void
    {
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//*') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            // Snapshot: removing attributes mutates the collection being read.
            $attributes = [];
            foreach ($element->attributes ?? [] as $attribute) {
                $attributes[] = $attribute->nodeName;
            }

            foreach ($attributes as $name) {
                $lower = strtolower($name);
                $value = (string) $element->getAttribute($name);

                // Every on*-handler, whatever it is called.
                if (str_starts_with($lower, 'on')) {
                    $element->removeAttribute($name);
                    continue;
                }

                if ($lower === 'style') {
                    // A style attribute can load and position things, so it goes
                    // - but the alignment of a table cell is carried in it.
                    // Markdown's own |:-:| becomes style="text-align: center",
                    // so dropping the lot would silently left-align every
                    // centred column in the file. It is kept as the attribute
                    // that says the same thing and nothing else.
                    $this->keepTextAlign($element, $value);
                    $element->removeAttribute($name);
                    continue;
                }

                if (in_array($lower, self::URL_ATTRIBUTES, true)) {
                    $resolved = $this->resolveUrl($value, $element, $reference);

                    if ($resolved === null) {
                        $element->removeAttribute($name);
                        continue;
                    }

                    $element->setAttribute($name, $resolved);
                }
            }

            // A link that now leaves this site says so, and cannot hand the new
            // tab a reference back to this one.
            if (strtolower($element->tagName) === 'a' && str_starts_with($element->getAttribute('href'), 'http')) {
                $element->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }
    }

    /**
     * Carry a text-align out of a style attribute and into an align attribute.
     *
     * Only on the elements where alignment is a statement about content rather
     * than a piece of design, and only the three values that mean anything
     * there.
     */
    private function keepTextAlign(DOMElement $element, string $style): void
    {
        $alignable = ['td', 'th', 'table', 'p', 'div'];

        if (!in_array(strtolower($element->tagName), $alignable, true) || $element->hasAttribute('align')) {
            return;
        }

        if (preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right)\s*(?:;|$)/i', $style, $match) === 1) {
            $element->setAttribute('align', strtolower($match[1]));
        }
    }

    /**
     * Put every table in the container Typemill's own renderer gives them.
     *
     * A theme hangs the horizontal scrolling off .tm-table, because a table is
     * as wide as its columns need and a phone is not. Markdown tables arrive
     * wrapped already; a table written as raw HTML in the readme does not, and
     * without the wrapper it pushes the whole page sideways.
     */
    private function wrapTables(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $tables = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            $parent = $table->parentNode;

            if ($parent instanceof DOMElement
                && strtolower($parent->tagName) === 'div'
                && str_contains($parent->getAttribute('class'), 'tm-table')) {
                continue;
            }

            $tables[] = $table;
        }

        foreach ($tables as $table) {
            $parent = $table->parentNode;
            if ($parent === null) {
                continue;
            }

            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'tm-table');

            $parent->replaceChild($wrapper, $table);
            $wrapper->appendChild($table);
        }
    }

    /**
     * Where a URL in the readme should point once the readme is on this site.
     *
     * Returns null for a URL that should not survive at all.
     */
    private function resolveUrl(string $value, DOMElement $element, RepositoryReference $reference): ?string
    {
        $url = trim($value);

        if ($url === '') {
            return null;
        }

        // srcset is a list; each candidate is resolved on its own.
        if (strtolower($element->tagName) === 'img' && $element->hasAttribute('srcset') && $url === $element->getAttribute('srcset')) {
            $candidates = [];
            foreach (explode(',', $url) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate)) ?: [];
                $target = $this->resolveSingleUrl((string) ($parts[0] ?? ''), $element, $reference);

                if ($target !== null) {
                    $parts[0] = $target;
                    $candidates[] = implode(' ', $parts);
                }
            }

            return $candidates === [] ? null : implode(', ', $candidates);
        }

        return $this->resolveSingleUrl($url, $element, $reference);
    }

    private function resolveSingleUrl(string $url, DOMElement $element, RepositoryReference $reference): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Points inside the page it is on.
        if (str_starts_with($url, '#')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== '') {
            // Anything that executes, or that carries its payload inline, is not
            // a link to somewhere.
            $allowed = ['http', 'https', 'mailto'];

            return in_array($scheme, $allowed, true) ? $url : null;
        }

        // Protocol-relative: keep the host, pin the scheme.
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $this->absoluteRepositoryUrl($url, $element, $reference);
    }

    /**
     * A path relative to the readme becomes a path in the repository.
     *
     * Pictures have to come from raw.githubusercontent.com, which serves the
     * file itself; github.com would serve its page about the file. Links go to
     * that page on purpose, because that is what a reader of the readme expects
     * to land on.
     */
    private function absoluteRepositoryUrl(string $url, DOMElement $element, RepositoryReference $reference): string
    {
        $reference->path();
        $ref = $reference->branch() ?? 'HEAD';
        $directory = $reference->path() !== null ? trim((string) dirname($reference->path()), '.') : '';
        $directory = trim($directory, '/');

        $path = ltrim($url, '/');

        // A path written as /docs/x.md in a readme means the repository root.
        if (!str_starts_with($url, '/') && $directory !== '') {
            $path = $directory . '/' . $path;
        }

        // Resolve any . and .. the readme used to walk the repository.
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $clean = implode('/', $segments);
        $isImage = strtolower($element->tagName) === 'img' || strtolower($element->tagName) === 'source';

        $base = $isImage
            ? 'https://raw.githubusercontent.com/' . $reference->owner() . '/' . $reference->repository() . '/' . $ref . '/'
            : $reference->webUrl() . '/blob/' . $ref . '/';

        return $base . $clean;
    }

    /**
     * With raw HTML switched off, keep only the elements Markdown itself
     * produces and unwrap the rest, so their text survives but their markup
     * does not.
     */
    private function unwrapRawHtml(DOMDocument $document): void
    {
        $allowed = [
            'p', 'a', 'em', 'strong', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'br', 'img', 'del', 's',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'div', 'span',
        ];

        $xpath = new DOMXPath($document);
        $unknown = [];

        foreach ($xpath->query('//*') ?: [] as $element) {
            if ($element instanceof DOMElement
                && $element->getAttribute('id') !== 'tm-readme-md'
                && !in_array(strtolower($element->tagName), $allowed, true)) {
                $unknown[] = $element;
            }
        }

        // Deepest first, so unwrapping a parent cannot orphan a child still to
        // be handled.
        foreach (array_reverse($unknown) as $element) {
            $parent = $element->parentNode;
            if ($parent === null) {
                continue;
            }

            while ($element->firstChild !== null) {
                $parent->insertBefore($element->firstChild, $element);
            }

            $parent->removeChild($element);
        }
    }
}
