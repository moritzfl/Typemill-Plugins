# Prism — Typemill Theme

A vivid, developer-facing product theme. Where [Lucid](../lucid/README.md) is calm and spacious,
Prism is dense and colourful: an angled gradient hero with the navigation floating over it, a
saturated indigo accent, deep navy bands, blue-tinted greys, tight corner radii, layered shadows and
terminal-style code blocks.

## Installation

See [Installation in the project README](../../README.md#installation).

Select **Prism** under **System → Themes**. There is no build step — the stylesheet is plain CSS with
custom properties.

## Design tokens

| Token | Value |
|---|---|
| Body text | 17px / 1.6, tracking `-0.01em` |
| Headline | up to 64px, weight 800, tracking `-0.035em` |
| Grid width | 1080px, reading column 720px |
| Header height | 64px, transparent over the hero |
| Radii | 2 · 4 · 6 · 16px, plus fully rounded buttons |
| Accent ramp | `#665efd` → `#533afd` → `#4032c8`, deep `#1c1e54` |
| Neutrals | `#f8fafd` · `#e5edf5` · `#64748d` · `#1a2c44` · `#061b31` |
| Gradient | `linear-gradient(90deg, #7232f1, #fb76fa 50%, #ffcf5e)` |
| Shadows | layered: a tight contact shadow over a soft ambient one |

Every token is a CSS custom property on `:root`. The accent colour, the hero gradient and the grid
width each have their own settings field, and anything else can be overridden in Custom CSS:

```css
:root {
  --pr-accent: #0f9d58;
  --pr-gradient: linear-gradient(90deg, #0f9d58, #00bcd4 60%, #8bc34a);
}
```

## The hero

The homepage, the blog listing and the 404 page open with a full-bleed gradient band whose lower
edge is cut on a diagonal, and the navigation sits on top of it with a transparent background and
white links. Everywhere else the bar is solid with a hairline border.

Set **Hero background** to solid if the gradient is too loud for the site; the layout is unchanged.

## Layouts

| Template | Used for |
|---|---|
| `home.twig` | Hero, page body, numbered section cards, optional dark call-to-action band |
| `blog.twig` | Hero plus a paginated post grid |
| `page.twig` | Content pages, folder listings, prev/next pager |
| `404.twig` | Not found, on a full-height hero |

## Settings

Grouped in **System → Themes → Prism**:

- **Homepage Hero** — eyebrow, headline, subheadline, two buttons, solid or gradient background
- **Homepage Cards** — a numbered card per top-level section
- **Homepage Call to Action** — a dark band at the foot of the homepage; leave the heading blank to
  hide it
- **Blog** — blog mode, source folder, posts per page, post images, intro
- **Layout** — breadcrumb, content width, date format, optional page lead
- **Colors** — accent, hero gradient, Custom CSS
- **Labels** — every visible string
- **Footer** — up to three Markdown columns and a custom copyright line

## Fonts

**No font files are shipped.** The stack starts with the standard CSS keywords for the operating
system's own interface font (`-apple-system`, `BlinkMacSystemFont`), then `Segoe UI` and `Roboto`,
falling back to Helvetica and Arial. Code uses the platform's monospace font.

Nothing is loaded over the network, so there is no font flash, no third-party request, and no
licensed typeface is redistributed.

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

- Dark mode follows `prefers-color-scheme`: the navy palette deepens and the accent lightens so it
  stays readable on dark.
- The page lead (the description under a title) is **off by default**. Typemill generates
  descriptions from the page body and truncates them, which reads as a sentence that stops mid-word.
- The header shows only the top level of the navigation; deeper pages are reached through the folder
  listings.
- `prefers-reduced-motion` disables the card and button transitions. The print stylesheet removes the
  gradient, the diagonal cut, the header and the footer.
