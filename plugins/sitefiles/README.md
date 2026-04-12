# Site Files — Typemill Plugin

A Typemill plugin that serves a public `robots.txt`, exposes the generated sitemap at `/sitemap.xml`, and adds
reliable social metadata plus `schema.org` output based on existing Typemill page metadata.

## Installation

See [Installation in the project README](../../README.md#installation).

## What It Does

The plugin registers two public frontend routes and augments frontend pages with SEO metadata:

| Feature        | Output                                                                 |
|----------------|------------------------------------------------------------------------|
| `/robots.txt`  | A plain-text robots file generated from the current site base URL      |
| `/sitemap.xml` | The Typemill sitemap XML served from the root path instead of `/cache` |
| Social tags    | Open Graph and Twitter-compatible tags for title, description, and image |
| `schema.org`   | JSON-LD for `WebSite`, publisher, breadcrumb, and page/article data    |

This is useful because Typemill itself generates the sitemap in `cache/sitemap.xml`, but many crawlers, tools, and
hosting setups expect the sitemap at `/sitemap.xml`.

The metadata layer reuses Typemill's existing page fields like `title`, `description`, `heroimage`, `heroimagealt`,
`manualdate`, and `author` instead of introducing a second set of page-level SEO inputs.

## Usage

Activate the plugin in **Plugins**. No further setup is required.

After activation, the following URLs should work immediately:

```text
https://yoursite.com/robots.txt
https://yoursite.com/sitemap.xml
```

On content pages, the plugin also emits:

- Open Graph title, description, URL, and image tags.
- Twitter-compatible title, description, card, and image tags.
- JSON-LD for the website, publisher, breadcrumbs, and either `WebPage`, `CollectionPage`, or `Article`.

Image selection follows this fallback order:

1. Current page `heroimage`
2. A suitable image from the next higher site section
3. First inline content image
4. Configured default share image
5. Homepage hero image or site logo

## Robots.txt Output

The generated `robots.txt` contains:

```text
User-agent: *
Allow: /
Disallow: /tm/

Sitemap: https://yoursite.com/sitemap.xml
```

The sitemap URL is derived from Typemill's current `baseurl`, so it follows the active site domain automatically.

## Configuration

The plugin provides the following settings:

| Setting               | Purpose                                                                     |
|-----------------------|-----------------------------------------------------------------------------|
| `extra_rules`         | Appends additional raw lines to `robots.txt`                                |
| `site_description`    | Fallback site description for schema and social metadata                    |
| `default_share_image` | Global fallback image for social previews                                   |
| `publisher_type`      | Schema publisher type (`Organization` or `Person`)                          |
| `publisher_name`      | Optional override for the schema publisher name                             |
| `publisher_logo`      | Optional logo or portrait for the schema publisher                          |
| `same_as`             | One absolute URL per line for publisher profile links in `schema.org` data  |

Example `extra_rules` value:

```text
Disallow: /private/
Crawl-delay: 10
```

Each non-empty line is appended verbatim below the default rules.

The admin area path `/tm/` is always disallowed by the plugin.

## Metadata Behavior

The plugin supplements Typemill's existing frontend metadata instead of replacing the content model:

- It reuses existing page `title` and `description`.
- It reuses `heroimage` and `heroimagealt` for social previews.
- It can use an image from the next higher site section when the page itself has none.
- It classifies pages inside `contains: posts` folders as `Article` in JSON-LD.
- It leaves 404 pages alone and only augments real content pages.

## Sitemap Behavior

The plugin does not replace Typemill's sitemap generator. Instead it:

1. Tries to read the existing sitemap from Typemill's cache folder.
2. If the sitemap file is missing, it triggers Typemill's own sitemap generation once.
3. Returns the XML at `/sitemap.xml`.

This keeps the sitemap content aligned with Typemill core while making it available at a more standard public URL.

## Notes

- The plugin does not add new page-editing fields; it relies on Typemill core metadata and only adds global plugin settings where Typemill has no equivalent.
- Responses are served with a short public cache header (`max-age=300`).
- `Article` schema is inferred from Typemill structure: pages inside folders with `contains: posts` become `Article`; other pages stay `WebPage` or `CollectionPage`.
- The plugin supplements existing `<title>`, `<meta name="description">`, and canonical tags with social tags and structured data; it does not replace theme-defined head tags.
