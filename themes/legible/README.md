# Legible — Typemill Theme

An accessibility-first theme. The page is comfortable to read before anyone touches a setting: large
type on a short measure, generous spacing, and colours measured against the WCAG **AAA** ratio rather
than scraping past AA. On top of that the reader gets three controls of their own, because no single
default suits everyone.

## Installation

See [Installation in the project README](../../README.md#installation).

Select **Legible** under **System → Themes**. There is no build step — the stylesheet is plain CSS
with custom properties.

## Reader controls

A **Reading** button in the header opens a panel with three settings:

| Setting | Options |
|---|---|
| **Text size** | Small 17px · Medium 20px · Large 23px · Extra large 27px |
| **Spacing** | Compact · Comfortable · Spacious |
| **Contrast** | System · Light · Dark · Maximum |

**Spacious** applies the letter, word and paragraph spacing named in
[WCAG 1.4.12 Text Spacing](https://www.w3.org/WAI/WCAG22/Understanding/text-spacing.html) — the
values a page is supposed to survive when a reader forces them, offered as a setting instead of
leaving it to a bookmarklet.

**Maximum** is pure black on pure white, 21:1.

The choice is kept in the browser and **applied before the page is painted**, by a small script in
the `<head>`, so a stored preference never flashes the default first. Which options a first-time
reader starts with is set in the theme settings; **Reset to defaults** returns to those, not to
whatever happens to be on screen.

The buttons are named rather than shown as four letter *A*s: an unlabelled glyph gives a screen
reader nothing to read out. Each change is announced through a polite live region, because the
buttons stay put while the page around them changes.

## Contrast

Every colour is measured against the **tinted** surface (`#f2f2f2`), not against white, because the
footer and code blocks sit on it and that is the worse of the two:

| Pairing | Light | Dark | Maximum |
|---|---|---|---|
| Body text | 14.4:1 | 15.8:1 | 21:1 |
| Secondary text | 7.3:1 | 8.4:1 | 21:1 |
| Links | 7.6:1 | 9.4:1 | 11.5:1 |

AAA asks for 7:1 at this size. The lowest pairing anywhere in the theme is **7.3:1**.

If you set your own link colour, check it still clears 7:1 against the background before using it.

## What else the theme does

- **Two skip links** — to the content and to the navigation. Readers who tab past the menu every
  time want the first; readers using the page as an index want the second.
- **A focus ring you cannot miss** — a 3px ring plus a second ring in the opposite tone, so it shows
  against light and dark alike. It is never removed, only replaced.
- **44px targets** — the size WCAG 2.5.5 asks for, on every link and button in the interface.
- **No colour-only meaning** — the current page is underlined as well as coloured, links in running
  text are underlined at rest, and the selected reader option is ticked as well as filled.
- **Left aligned, never justified** — a justified rag opens rivers of white space that are hard
  going for dyslexic readers.
- **No motion** — nothing animates, and `prefers-reduced-motion` switches off what little there is.
- `prefers-contrast: more` firms up the borders without the reader having to find the control.

## Design tokens

| Token | Value |
|---|---|
| Reading face | system sans (or serif, by setting) |
| Base size | 20px, reader adjustable 17–27px |
| Line height | 1.65, reader adjustable 1.5–1.9 |
| Measure | 66ch |
| Light | text `#1a1a1a`, secondary `#4f4f4f`, background `#fff`, links `#0a4a9e` |
| Dark | text `#f2f2f2`, background `#121212`, links `#9ec5ff` |
| Target size | 44px |

Everything is a custom property on `:root` that the Custom CSS field can override:

```css
:root { --lg-measure: 58ch; --lg-link: #005a5a; }
```

## Layouts

| Template | Used for |
|---|---|
| `home.twig` | Title, standfirst, page body, and a list of the top-level sections |
| `blog.twig` | Title plus a paginated post feed |
| `page.twig` | Pages and folders: breadcrumb, article, folder contents, prev/next |
| `404.twig` | Not found |

Lists are `<ul>` of cards with a heading each, so they can be navigated by heading as well as by
link. The whole card is clickable through a stretched pseudo-element on the title link, which keeps
**one** link per entry — a separate "read more" would put two identical links in the tab order.

## Readymades

Three presets. **System → Themes → Configure → Readymades**, then *load* one and save.

| Readymade | What it sets up |
|---|---|
| **Accessible site** | The theme as designed: reader controls, sections on the homepage, breadcrumbs, sans-serif |
| **Easy read** | Larger type and WCAG 1.4.12 spacing as the starting point, on a shorter measure |
| **Long reads** | Serif face, wider measure, homepage as a dated feed of posts |

**Long reads** expects the blog folder to point at your own.

## Fonts

**No font files are shipped.** Both reading faces are the ones already on the device, so nothing is
loaded over the network — no font flash, no third-party request, and no delay before the text can be
read.

## Monochrome SVG in dark mode

Each SVG the page loads from this site is read once and, if its source only uses black, white and
transparent, it is flipped in dark mode. Colour is left alone. Override per image with
`data-svg-invert="true"` or `"false"`.

## Notes

- With JavaScript switched off the panel never opens and the site keeps the defaults from the theme
  settings; the page still follows the system dark mode.
- The header shows only the top level of the navigation, expanding into a panel for sections that
  contain pages. The panel opens on hover, on focus and on the first tap, so the pages inside a
  section stay on the tab order.
- The print stylesheet drops the header, footer and pager, sets the text at 12pt, and spells out the
  target of every external link, since a printed page cannot be clicked.
