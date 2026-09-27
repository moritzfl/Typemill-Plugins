# Site Blocks

Portable page layouts using Typemill's native visual editor and publishing flow.
Enable **Site Blocks** in Plugins, open a page in the visual editor and choose
**▦ Site layout**. Edit fields, choose media, save the block, then publish the page
normally. Existing layouts open by clicking their preview. Whole blocks use the
editor's normal drag-and-drop; gallery and column items have keyboard-accessible
Move up / Move down controls.

## Blocks

| Layout | Contents |
|---|---|
| Hero | Heading, text, optional image and action |
| CTA | Heading, text and action band |
| Columns | 2–4 cards with text, optional images and links |
| Gallery | Responsive cropped image grid, captions and lightbox |
| Slideshow | Scroll-snap image strip; full-image previous/next in the lightbox |
| Masonry | Natural-ratio images in CSS columns |
| Collection | Latest 1–24 public pages, optionally filtered by folder and tag; cards or list |
| Shared | The site's shared contact/footer columns |

Images use native media selection or an image URL. X/Y focal-point sliders place
the crop, with an immediate preview. Cropping uses CSS `object-fit`, preserving
the original file; this is not an image-resizing service. Images lazy-load and
open at original resolution. Lightboxes use native modal dialogs, Escape, arrow
keys and focus restoration. Slideshows never autoplay. Captions and alternative
text are separate fields. Text is plain text, with line breaks; normal Markdown
blocks remain available around layout blocks.

Layout CSS and gallery behavior ship with the plugin. They work with themes that
render Typemill's content and plugin assets; maintained themes are tested at
mobile and desktop widths. Theme-switching does not change the block data.

## Collections and shared content

Each page's **Siteblocks** meta tab has comma-separated tags. Collections match
one whole tag, case-insensitively, then sort newest-first by manual date (modified
date fallback, URL tie-break). Folder filtering includes descendants. Hidden,
noindex, draft, redirected/referenced and user/role-restricted pages are excluded,
as are descendants of excluded folders. Collections do not expose private-site
content, even to logged-in viewers. Empty collections have no cards.

Set up to three shared columns in Plugins → Site Blocks. Every maintained theme
renders them in its footer; the Shared block can also place them in page content.
Existing theme-specific footer columns remain independent. Site title, logo and
navigation already use Typemill's global settings/page tree; they are not copied
into a second store.

## Storage and theme contract

Blocks live inside ordinary Markdown as a native shortcode:

```text
[:siteblock data="BASE64URL_JSON" :]
```

The payload is UTF-8 JSON encoded with URL-safe Base64, without padding:

```json
{
  "version": 1,
  "type": "columns",
  "title": "Visit us",
  "columns": 2,
  "items": [
    {"title": "Training", "text": "Monday, 18:00", "url": "/training", "label": "Times"},
    {"title": "Contact", "text": "New players welcome", "url": "/contact", "label": "Join us"}
  ]
}
```

Supported top-level fields: `version`, `type`, `title`, `text`, `label`, `url`,
`columns`, `items`; collections also use `folder`, `tag`, `limit`, `layout`.
Items use `title`, `text`, `src`, `alt`, `caption`, `url`, `label`, `x`, `y`.
Unknown fields are ignored; counts, focal points and text lengths are bounded.
HTML and arbitrary style declarations are never accepted. Unsupported versions
show an error without rewriting the original shortcode.

Third-party themes need no block templates. Render `content`,
`assets.renderCSS()` and `assets.renderJS()` as usual. To support shared footers:

```twig
{{ siteblocks_shared|default('')|raw }}
```

The `.sb` class namespace belongs to the plugin. `--sb-columns` and `--sb-gap`
control its grid. Colors and fonts inherit from the theme. With the plugin
disabled the data stays in Markdown, but Typemill cannot render its shortcode.

## Optional page JSON

Enable **Public page JSON** to expose:

```text
GET /siteblocks.json?url=/your-page
```

Schema version 1 returns `url`, `title`, `description`, `tags`, published
`markdown`, decoded layout `blocks`, and hero `media`. It exports source data,
not a second rendering pipeline or resolved dynamic collections. Private,
hidden, noindex, draft and referenced pages return 404; ancestor restrictions
are checked too. Global private-site access disables the endpoint. Metadata is
allowlisted: owners, permissions and plugin settings are not exported. Responses
are `no-store`. The endpoint is disabled by default.

Requires Typemill 2's Blox/shortcode hooks. No core patches, build step, external
service or additional PHP/JS dependency.
