# Court — Typemill Theme

A modern club homepage built around [SUS Sehnde Badminton](https://sus-sehnde-badminton.de):
bold editorial typography, court navy, shuttle lime and plenty of breathing room.
Original badminton artwork gives the page a sporting identity even without a photo.

## Homepage

- **A clear first action.** The hero uses your existing headline and description.
  Without a configured primary button, it links to the first navigation page —
  training times on the Sehnde site. A matching leaf navigation link is highlighted.
- **Crest or photo.** An SVG hero image becomes a small crest on the illustrated
  court. Other formats fill the visual as a photo. The **Homepage image treatment**
  setting can force either treatment, including a PNG/JPEG crest. No image is
  required; the court artwork is included in the theme.
- **Quick links.** Numbered, full-row links replace description cards. A schedule
  can no longer turn into a flattened table excerpt on the homepage.
- **Your content.** The published homepage body remains editable as usual. A
  lone separator and an empty Search plugin mount do not create a blank band;
  search results and real content still appear.
- **Real news.** Up to three published posts from **Posts folder** (`/news` by
  default) appear with their dates and a link to the full list. This follows the
  folder's post order and loads metadata for only those three posts. Missing or
  empty folders produce no news section. Turn this off with **Club homepage**
  in the news settings, or give it your own heading.

The existing **News homepage** mode remains a paginated post list. It does not
also render the club homepage or duplicate its news previews.

## Site-wide

- Sticky navigation, visible club identity on mobile, keyboard-operated drawer
  with focus containment, Escape, and focus restoration
- One link target per news card; numbered quick links are full-row targets too
- Comfortable reading measure and horizontally scrollable schedule tables
- Automatic light/dark palettes, visible focus states and reduced-motion support
- English/German interface labels follow the site language
- Local system fonts, inline SVG and plain CSS; no build step or external assets
- Configurable navy, accent, width, footer columns and custom CSS

## Graphics

`images/shuttle.svg` is an original vector illustration: sixteen layered
feathers, individual shafts, two rows of binding and a rounded cork cap.
The lime collar inherits the theme accent when included inline. The quiet
court drawing uses doubles-court proportions and singles/service lines.
Directional icons share one SVG partial instead of platform-dependent font glyphs.

For every new or changed illustration or icon, render and inspect it **in the
real page**, not just as SVG source. Review desktop, narrow mobile and tablet,
light/dark, and interactive states where applicable. Check the silhouette,
small details, clipping, contrast and spacing around text and club marks;
revise and render again until those checks pass. The homepage browser test
also guards the painted shuttle's bounds and separation from the crest and labels.

## Setup

1. Activate **Court** under Theme.
2. Put your most useful page first in the navigation, or set a primary hero label
   and link explicitly, such as **Trainingszeiten** → `/trainingszeiten`.
3. Point **Posts folder** at the published news folder (`/news` by default).
4. Upload your site logo in System → Settings. Set the homepage hero image to a
   club crest or photo, and choose its treatment if automatic detection is unsuitable.
5. Optionally apply **Sports club** for a ready-made German headline and actions.

Existing titles, body text, hero overrides and footer content remain in use.
The numbered quick-link design no longer uses the old tile-description/count/action labels.

## Verification

`npm run test:browser` exercises the Docker instance. `court-homepage.mjs` covers
the homepage at 320, 390, 768, 834 and 1440 pixels in light/dark, published/draft news,
missing/empty folders, crest/photo modes, custom settings, full-card clicks,
Search mount visibility, and real admin login/theme settings. Shared suites cover
keyboard navigation, contrast, prose, localization and pagination at scale;
`court-club.mjs` checks Court's canonical URLs and post-card behavior.

## Files

| File | Role |
|------|------|
| `court.yaml` | Defaults, presets and admin fields |
| `layout.twig` | Shell, primary action, CSS overrides and navigation behavior |
| `home.twig` | Club hero, quick links, homepage body and latest news |
| `images/shuttle.svg` | Original, reusable shuttlecock illustration |
| `partials/court-art.twig` | Court lines and inline illustration placement |
| `partials/icon.twig` | Consistent directional SVG icons |
| `page.twig` / `blog.twig` | Content and paginated news |
| `css/court.css` | Styles; no build step |
| `en.yaml` / `de.yaml` | Frontend labels |

GPL-2.0
