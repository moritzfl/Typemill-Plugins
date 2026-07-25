# Medium — Typemill Theme

A long-form reading theme. One goal: comfortable reading of running text. A serif column at 21px on
a 1.58 line-height, held to a 680px measure, near-black on white, with the interface kept quiet so
the article carries the page.

## Installation

See [Installation in the project README](../../README.md#installation).

Select **Medium** under **System → Themes**. There is no build step — the stylesheet is plain CSS
with custom properties.

## Design tokens

| Token | Value |
|---|---|
| Article text | 21px / 1.58, serif, tracking `-0.003em` |
| Measure | 680px (the reading column, not the page) |
| Article title | up to 42px, serif, weight 700 |
| Interface | system sans at 14–16px |
| Header | 57px, thin rule, no shadow |
| Light | text `#242424`, secondary `#6b6b6b`, background `#fff`, rules `#e6e6e6` |
| Dark | text `#e6e6e6`, background `#121212`, rules `#2f2f2f` |

Column width, body size and the accent are settings; everything else is a custom property on
`:root` that the Custom CSS field can override:

```css
:root { --md-measure: 760px; --md-serif: "Iowan Old Style", Georgia, serif; }
```

There is also a **Typeface** switch that sets the article in the interface sans-serif instead, for
sites where a serif is wrong.

## Byline and reading time

Articles carry a byline: a round monogram with the author's initial, the author, the date, and an
estimated reading time. The estimate is counted from the rendered article at 200 words a minute, so
it stays correct when the page is edited rather than being stored and going stale. The monogram, the
reading time and the byline as a whole can each be switched off.

## Tags

A page with a `tags` field in its meta renders them as pills at the foot of the article. Comma
separated:

```yaml
tags: 'typemill, writing, notes'
```

## Layouts

| Template | Used for |
|---|---|
| `home.twig` | Masthead, page body, and a feed of the top-level sections |
| `blog.twig` | Masthead plus a paginated post feed |
| `page.twig` | Articles, folder listings, tags, prev/next |
| `404.twig` | Not found |

Post rows put the text on the left and a small square thumbnail on the right, rather than a grid of
cards.

## Readymades

Three presets. **System → Themes → Configure → Readymades**, then *load* one and save.

| Readymade | What it sets up |
|---|---|
| **Publication** | Masthead, section feed, byline with author, date and reading time |
| **Single-author blog** | Homepage as a post feed with thumbnails |
| **Bare essays** | No monogram, no reading time, no breadcrumb, slightly wider measure |

**Single-author blog** expects the blog folder to point at your own.

## Fonts

**No font files are shipped.** The article uses the serif faces that ship with desktop and mobile
systems, and the interface uses the system UI font:

```css
Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, "Times New Roman", serif
```

Nothing is loaded over the network, so there is no font flash and no third-party request.

## Monochrome SVG in dark mode

Each SVG the page loads from this site is read once and, if its source only uses black, white and
transparent, it is flipped in dark mode. Colour is left alone. Override per image with
`data-svg-invert="true"` or `"false"`.

## Notes

- Dark mode follows `prefers-color-scheme`; there is no toggle.
- The standfirst under a title is **off by default**. Typemill generates descriptions from the page
  body and truncates them, which reads as a sentence that stops mid-word.
- The header shows only the top level of the navigation, expanding into a small panel for sections
  that contain pages. On touch the first tap reveals the section and the second follows the link.
- Section breaks (`---`) render as three spaced dots rather than a rule.
- `prefers-reduced-motion` disables transitions; the print stylesheet drops the header, footer,
  pager and tags and sets the text at 12pt.
