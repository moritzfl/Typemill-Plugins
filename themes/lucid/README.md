# Lucid — Typemill Theme

A calm, spacious product-page theme: generous whitespace, large tightly tracked headlines, fully
rounded buttons, full-bleed sections that alternate between white and light grey, rounded section
tiles, and a translucent sticky header.

## Installation

See [Installation in the project README](../../README.md#installation).

Select **Lucid** under **System → Themes**. There is no build step — the stylesheet is plain CSS with
custom properties.

## Design tokens

The theme is built on one strict typographic system rather than ad-hoc values:

| Token | Value |
|---|---|
| Body text | 17px / 1.47, tracking `-0.022em` |
| Type scale | 12 · 14 · 17 · 19 · 21 · 24 · 28 · 32 · 40 · 48 · 56 |
| Heading tracking | `-0.022em` large, `-0.016em` medium |
| Grid width | 1068px, text column 734px |
| Header height | 44px, `saturate(180%) blur(20px)` |
| Buttons | fully rounded |
| Cards / media | 18px radius |
| Light | text `#1d1d1f`, background `#fff`, sections `#f5f5f7`, accent `#0071e3` |
| Dark | text `#f5f5f7`, background `#000`, sections `#1d1d1f`, accent `#2997ff` |

Every token is a CSS custom property on `:root`, so a single declaration in the Custom CSS field
retheme's the whole thing:

```css
:root { --lc-accent: #bf4800; --lc-radius-lg: 8px; }
```

The accent colour and the grid width also have their own settings fields.

## Fonts

**No font files are shipped and no licensed typeface is redistributed.** The stack is:

```css
-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
"Helvetica Neue", Helvetica, Arial, sans-serif
```

`-apple-system` and `BlinkMacSystemFont` are the standard CSS keywords for "the interface font this
operating system already uses", so each platform renders its own system typeface, with named
fallbacks behind them for everything else.

This is deliberate rather than a shortcut. The typefaces these platforms ship are licensed for use
*on* those platforms and may not be redistributed by a website, so self-hosting one would breach its
licence. Going through the system stack gives the same result on the same hardware while shipping
nothing, and there is no font flash and no third-party request.

## Layouts

| Template | Used for |
|---|---|
| `home.twig` | Homepage: hero, page body, one tile per top-level section |
| `blog.twig` | Homepage in blog mode: hero plus a paginated post grid |
| `page.twig` | Content pages, folder listings, prev/next pager |
| `404.twig` | Not found |

Folder pages list their children automatically, and they are given the same treatment as the
navigation panel for that section: the names set large in two columns, turning to the accent on
hover. It is the same set of links, so it should not look like one thing in the header and another
in the page — the rules are shared rather than copied, so the two cannot drift apart. Folders marked
as containing posts render the post grid instead.

## Settings

Grouped in **System → Themes → Lucid**:

- **Homepage Hero** — eyebrow, headline, subheadline, a rounded button and a secondary chevron link
- **Section Tiles** — a tile per top-level section, with heading, intro and link label
- **Blog** — blog mode, source folder, posts per page, post images, intro
- **Layout** — breadcrumb, content width, date format, optional page lead
- **Labels** — every visible string, so the theme can run in any language
- **Colors** — accent colour and Custom CSS
- **Footer** — up to three Markdown columns and a custom copyright line

## Readymades

The theme ships three presets. **System → Themes → Configure → Readymades**, then *load* one and
save. Each fills in the hero, the labels and the footer so the homepage looks finished immediately,
and every value stays editable afterwards.

| Readymade | What it sets up |
|---|---|
| **Product page** | Hero with an eyebrow, a rounded button and a secondary link, section tiles, two footer columns |
| **Documentation** | Breadcrumbs, author and date on every page, folder pages that read as a table of contents |
| **Journal** | Homepage as a grid of posts with images and a short intro above the list |

Own combinations can be saved as readymades from the same panel.

## Monochrome SVG in dark mode

Black-and-white line art vanishes against a dark background. Each SVG the page
loads from this site is read once and, if its source only uses black, white and
transparent, it is flipped in dark mode. Anything with real colour is left
alone, so logos and illustrations keep their palette.

Override the guess per image with a data attribute:

```html
<img src="/media/files/diagram.svg" data-svg-invert="true">   <!-- always flip -->
<img src="/media/files/logo.svg"    data-svg-invert="false">  <!-- never flip -->
```

Cross-origin images are skipped, since their source cannot be read.

## Notes

- Dark mode follows `prefers-color-scheme`. There is no toggle by design: the theme takes the
  reader's system preference at face value.
- The page lead (the description under a title) is **off by default**. Typemill generates
  descriptions from the page body and truncates them, which reads as a sentence that stops
  mid-word. Turn it on when descriptions are written by hand.
- The header shows only the top level of the navigation. Deeper pages are reached through the folder
  listings on the section pages.
- `prefers-reduced-motion` disables the tile hover transitions, and a print stylesheet strips the
  header, footer and pager.
