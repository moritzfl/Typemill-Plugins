# Atelier — Typemill Theme

A theme for sites where the pictures are the content: portfolios, photo essays, studio and exhibition
sites. The opening picture fills the window with the navigation floating over it, and every page that
carries a hero image becomes a frame on a wall below.

## Installation

See [Installation in the project README](../../README.md#installation).

Select **Atelier** under **System → Themes**. There is no build step — the stylesheet is plain CSS
with custom properties.

## How the gallery is filled

A page appears on the wall when its **hero image** is set (page meta, *Hero image* field). Pages
without one are skipped rather than drawn as an empty frame, so a half-illustrated folder still looks
deliberate.

| Page | Shows |
|---|---|
| Home | Every top-level section and its children that carry an image |
| A folder | The pages inside it that carry an image |

The picture is used at its stored size. Typemill's `resize()` crops to an exact box and has no
proportional mode, which would flatten a masonry wall into identical rectangles, so the proportion
stays a setting and the cropping is done in CSS.

## Design tokens

| Token | Value |
|---|---|
| Interface | system sans, 13–15px, uppercase, tracking `0.06em` |
| Hero title | `clamp(38px, 7vw, 92px)`, weight 600, tracking `-0.02em` |
| Light | text `#16161a`, background `#fff`, rules `#e4e4e2` |
| Dark | text `#f2f2f0`, background `#0e0e10`, rules `#2a2a2e` |
| Wall gap | 22px (`--at-gap`) |
| Grid proportion | 4 / 3 (`--at-ratio`) |

The proportion, gap, accent, surface and column width are settings; the rest are custom properties on
`:root` that the Custom CSS field can override:

```css
:root { --at-gap: 6px; --at-ratio: 3 / 4; }
```

## Wall layouts

| Setting | Result |
|---|---|
| **Masonry** | Columns of varying height; each picture keeps its own proportion |
| **Grid** | Every picture cropped to one proportion, for a strict wall |

Captions sit **below** the picture, or **over** it, revealed on hover and on keyboard focus.

## Surfaces

**White**, **warm** (a paper tone) and **dark** (a gallery wall). The dark surface is a deliberate
choice rather than the system setting; automatic dark mode still follows `prefers-color-scheme` on
the other two.

## The image viewer

Clicking a picture inside an article opens it full-screen. Every picture in the article forms one
sequence, so the arrows walk the whole page.

- Arrow keys step, `Escape` closes, a click on the backdrop closes
- The pictures are reachable by keyboard: they take focus, `Enter` and `Space` open them, and closing
  returns the focus ring to the picture it came from
- While the viewer is open the tab ring stays inside it and the page behind does not scroll
- Gallery tiles are deliberately left out: they are links to other pages, not pictures to view

## Navigation over a picture

Over a full-bleed hero the bar is transparent with pale lettering. Once the picture scrolls behind
the bar it turns solid, because pale lettering on a pale page cannot be read. The state flips on an
`IntersectionObserver`; the bar keeps its height throughout, so nothing shifts.

## Layouts

| Template | Used for |
|---|---|
| `home.twig` | Full-bleed hero, page body, and the wall of everything with a picture |
| `blog.twig` | Hero plus a paginated post feed |
| `page.twig` | Pages and folders: hero, breadcrumb, article, wall, prev/next |
| `404.twig` | Not found |

## Readymades

Three presets. **System → Themes → Configure → Readymades**, then *load* one and save.

| Readymade | What it sets up |
|---|---|
| **Portfolio** | Full-bleed opening picture, masonry wall, captions below. The layout the theme was built for |
| **Exhibition** | Square grid, tight gaps, captions over the picture, dark surface |
| **Photo journal** | Homepage as a dated wall of entries on a warm paper surface |

**Photo journal** expects the blog folder to point at your own.

## Fonts

**No font files are shipped.** The interface uses the system UI font, so nothing is loaded over the
network — no font flash and no third-party request.

## Monochrome SVG in dark mode

Each SVG the page loads from this site is read once and, if its source only uses black, white and
transparent, it is flipped in dark mode. Colour is left alone. Override per image with
`data-svg-invert="true"` or `"false"`.

## Notes

- Dark mode follows `prefers-color-scheme` unless the surface is set to dark; there is no toggle.
- The header shows only the top level of the navigation, expanding into a small panel for sections
  that contain pages. On touch the first tap reveals the section and the second follows the link.
- The panel on the last entry is anchored to the right, so it cannot hang past the window edge.
- `prefers-reduced-motion` disables transitions; the print stylesheet drops the header, footer and
  the viewer.
