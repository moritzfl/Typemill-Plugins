# Site Blocks

Reusable Markdown content under **System → Content blocks** (**Bausteine** in
German), plus portable layouts in Typemill's native visual editor. No paid plugins
or external services required.

## Content library

Create named blocks in the library. Names are internal; headings belong in the
content. The visual editor is Typemill's installed **Blox editor**: click a preview
to edit, save or cancel individual blocks, insert between blocks, and drag to
reorder. Its configured content types, formatting tools and media components are
available alongside Site Blocks layouts. Markdown mode supports larger documents.
Saving an individual block immediately saves a private library draft;
**Publish** updates all linked uses.
Creating a block does not place it anywhere automatically.

- Search and filter by draft, published, unpublished changes or archived status.
- **Used in** lists published pages, page drafts and footer placement.
- **Preview draft** renders standalone content and creates a ten-minute,
  session-bound private URL for an existing page. Only existing references to
  that block are replaced; normal visitors still receive published content.
- **Website areas → Footer** assigns a published block to the additional theme
  footer. Applying placement is immediate and does not publish drafts.
- Publication history retains the last 30 previous published versions.
  Restoring a version creates a draft. Page versions restore references, not
  historical versions of their source blocks.
- Archive hides blocks from new selections while preserving existing uses.
  Unpublish/delete refuses blocks still used in page sources or the footer.
- Projects have separate libraries and footer assignments, without cross-project
  fallback. Simultaneous edits use revision checks; reload resolves conflicts.

In the page editor choose **↗ Block from library**. **Insert linked** saves a
stable reference and follows future source publications. **Insert as copy** or
**Detach as copy** puts ordinary Markdown into the page draft; later source
changes do not affect it. Publishing that page remains a separate action.

The `siteblocks` ACL resource has `read`, `update`, `publish` and `delete` rights.
Managers inherit all four by default. Page authors can select published blocks
within their allowed folders without gaining source-management rights.

## Backup and restore

**Export library** downloads project-scoped JSON with IDs, drafts, publications,
history and footer placement. **Import library** validates that project and
refuses existing IDs or conflicting footer assignments. It preserves references
and does not promote drafts into publications.

For a complete site backup, use **Recycle Bin → Export**, including every media
folder used by your blocks. The archive contains `content/`, selected `media/`
folders and `data/siteblocks/`. To restore a site, restore these directories to
the matching Typemill directories together; maintain filesystem ownership and
clear `data/navigation/`. Alternatively, restore media to their original paths
and import the library JSON into the same project. JSON alone does not contain
image bytes.

Site Blocks 2 replaces the former three-column plugin settings and `shared`
layout. It does not migrate or render that retired data.

## Local page layouts

Portable page layouts using Typemill's native visual editor and publishing flow.
Enable **Site Blocks** in Plugins, open a page in the visual editor and choose
**▦ Site layout**. Edit fields, choose media, save the block, then publish the page
normally. Existing layouts open by clicking their preview. Whole blocks use the
editor's normal drag-and-drop; gallery and column items have keyboard-accessible
Move up / Move down controls.

The layout selector explains each layout before you fill it in. The editor uses
Typemill-style form controls, supports admin dark mode and German, and explicitly
distinguishes saving a block to a draft from publishing the page. The media picker
is a modal dialog with keyboard focus containment and Escape/Close focus return.
Text fields contain plain text; use ordinary text blocks for Markdown formatting.

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

## Collections

Each page's **Siteblocks** meta tab has comma-separated tags. Collections match
one whole tag, case-insensitively, then sort newest-first by manual date (modified
date fallback, URL tie-break). Folder filtering includes descendants. Hidden,
noindex, draft, redirected/referenced and user/role-restricted pages are excluded,
as are descendants of excluded folders. Collections do not expose private-site
content, even to logged-in viewers. Empty collections have no cards.

Theme-specific footer settings remain independent of library placement. Site
title, logo and navigation use Typemill's global settings/page tree.

## Storage and theme contract

Blocks live inside ordinary Markdown as a native shortcode:

```text
[:siteblock data="BASE64URL_JSON" :]
```

Linked library content uses `[:siteblock-ref id="sb_…" :]`. Sources live under
`data/siteblocks/blocks/`, placements under `data/siteblocks/placements/`.
Atomic YAML writes and a shared lock protect library revisions and placements.
References resolve on each render; published source updates do not depend on a
shared HTML cache. Nested library references are rejected.

The admin loads the installed `vue-blox-config.js`, `vue-blox.js` and
`vue-blox-components.js` unchanged. A scoped transport adapter sends block edits
to the library API, with the same revision checks as Markdown saves. Its event
bus and Vue instance are disposed when leaving the editor. No core files are
patched and no editor fork is bundled. Blox's reserved page-title slot is hidden
and excluded from stored content; the library name remains internal.

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
{{ siteblocks_footer|default('')|raw }}
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
