# Court — Typemill Theme

A sports-club theme for small Verein sites: navy court band, schedule-first
tables, a compact top bar and news cards with a side stripe.

Designed around sites like [SUS Sehnde Badminton](https://www.sus-sehnde-badminton.de)
— a handful of pages, a training schedule, and a news folder — but generic enough
for any club section.

## Look

- **Court navy** hero and footer (default `#0c3169`, overridable)
- **Shuttle lime** buttons and accents (default `#c5d92a`, overridable)
- Warm paper page background; automatic dark mode
- Court-line marks in the hero (CSS only)
- Tables keep table layout and scroll inside `.tm-table` — for Trainingszeiten
- System font stack; no webfonts

## Suggested setup

1. Activate **Court** under Theme.
2. Apply the **Sports club** readymade, or fill the hero yourself:
   - Primary button → `/trainingszeiten`
   - Secondary link → `/news`
3. Point **Posts folder** at your news section (`/news` by default).
4. Upload the club crest as the site logo (System → Settings).
5. Optionally set **Court** and **Accent** colours to match your crest.

## Files

| File | Role |
|------|------|
| `court.yaml` | Meta, defaults, readymades, admin form |
| `layout.twig` | Shell, CSS vars, nav JS |
| `home.twig` | Hero + tiles |
| `page.twig` / `blog.twig` | Content and news list |
| `css/court.css` | Styles (plain CSS, no build step) |
| `en.yaml` / `de.yaml` | Frontend labels |

## Licence

GPL-2.0
