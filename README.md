# Typemill Plugins and Themes

Custom themes and plugins for [Typemill](https://typemill.net), a flat-file CMS.

---

## Themes

### `rueckenwind` — Rückenwind

A clean theme built with Tailwind CSS. Features a sticky top bar, collapsible sidebar navigation, automatic dark mode
with a Light / Dark / System toggle, an optional homepage hero, blog mode, per-page meta bar (author, date, edit link,
print button), customizable accent colors, and up to three Markdown footer columns. SVG images containing only
black/white/transparent colors are automatically inverted in dark mode.

→ See [`themes/rueckenwind/README.md`](themes/rueckenwind/README.md) for full documentation.

---

### `lucid` — Lucid

A calm, spacious product-page theme: a translucent sticky header, full-bleed sections that alternate between white
and light grey, large tightly tracked headlines, fully rounded buttons, rounded section tiles, and automatic dark
mode. Ships no font files — the system font stack lets each platform render its own interface typeface. Plain CSS
with custom properties, so there is no build step.

→ See [`themes/lucid/README.md`](themes/lucid/README.md) for full documentation.

---

### `prism` — Prism

A vivid, developer-facing product theme: an angled gradient hero with the navigation floating over
it, a saturated indigo accent, deep navy bands, blue-tinted greys, tight corner radii, layered
shadows and terminal-style code blocks. Numbered section cards and an optional dark call-to-action
band on the homepage. Ships no font files, and automatic dark mode deepens the navy palette. Plain
CSS with custom properties, so there is no build step.

→ See [`themes/prism/README.md`](themes/prism/README.md) for full documentation.

---

## Plugins

### `htmldeveloper` — HTML Developer Mode

Lets you embed raw HTML, CSS, and JavaScript directly in Typemill content pages using fenced code blocks tagged
`` rawhtml ``. Useful for custom components, iframes, interactive widgets, or any markup that Markdown alone can't
express. Includes a CSP setting to whitelist external domains used by embedded content.

→ See [`plugins/htmldeveloper/README.md`](plugins/htmldeveloper/README.md) for full documentation.

---

### `typemillupdate` — Typemill Update

Updates Typemill itself from the dashboard instead of replacing the `system` folder over FTP by hand. Downloads the
official release archive from typemill.net, verifies it, and replaces **only** the `system` folder — content, media,
settings, data, plugins, and themes are never touched. The previous core is kept so it can be restored, and an
environment panel says up front whether the installation is able to update itself at all.

→ See [`plugins/typemillupdate/README.md`](plugins/typemillupdate/README.md) for full documentation.

---

### `files` — File Manager

Adds a **Files** page to the Typemill system settings where you can upload files of any type to `media/files/`, browse
all uploads, move or copy files and folders (including drag-and-drop), copy internal paths or full public URLs,
download files, export folders as ZIP, and delete them. Solves the problem of getting non-image files (PDFs, audio,
playlists, archives, etc.) onto the server without FTP access.

→ See [`plugins/files/README.md`](plugins/files/README.md) for full documentation.

---

### `sitefiles` — Site Files

Adds two public frontend routes for search-engine facing site files: `robots.txt` and `sitemap.xml`. The plugin
generates a simple `robots.txt` from the current site URL and exposes Typemill's cached sitemap at the conventional
root path `/sitemap.xml`.

→ See [`plugins/sitefiles/README.md`](plugins/sitefiles/README.md) for full documentation.

---

### `linkicons` — Link Icons

Automatically prepends (or appends) small service icons to matching links in rendered HTML. Supports GitHub, GitLab,
Docker Hub, Codeberg, RSS/Atom links, and Mastodon profile URLs. You can toggle each icon type and choose icon position
in plugin settings.

→ See [`plugins/linkicons/README.md`](plugins/linkicons/README.md) for full documentation.

---

### `linkbuttons` — Link Buttons

Renders markdown links wrapped in outer square brackets as styled buttons.  
Syntax: `[[Button text](https://example.com)]`

→ See [`plugins/linkbuttons/README.md`](plugins/linkbuttons/README.md) for full documentation.

---

### `versions` — Versions

Records a full version history for every content page. Each save, publish, unpublish,
and discard is stored. A **Versions** tab in the page editor lets you browse the history,
compare any two states in a side-by-side diff viewer (Mergely), restore older versions,
and save manually merged content as a new draft. Deleted pages and assets are held in a
recycle bin under **System → Versions** where they can be previewed, restored, or
permanently removed.

→ See [`plugins/versions/README.md`](plugins/versions/README.md) for full documentation.

---

### `preview` — Preview

Shared in-admin preview for files and recycle-bin entries. When active, **System → Files**
and **System → Versions** let you click a previewable row to open a modal with rendered
Markdown, syntax-highlighted text, or inline media (images, audio, video, PDF).

→ See [`plugins/preview/README.md`](plugins/preview/README.md) for full documentation.

---

## Installation

### Themes

1. Copy the theme folder into Typemill's `themes/` directory.
2. Log in to the Typemill admin area.
3. Go to **Themes** and activate the theme.

### Plugins

1. Copy the plugin folder into Typemill's `plugins/` directory.
2. Log in to the Typemill admin area.
3. Go to **Plugins** and activate the plugin.

## Frontend Maintenance

Frontend tooling is managed from the repository root.

```bash
npm install
```

Useful commands:

- `npm run build:rueckenwind` rebuilds `themes/rueckenwind/css/theme.css`
- `npm run watch:rueckenwind` rebuilds the theme stylesheet on changes
- `npm run update:mergely` refreshes `plugins/versions/js/mergely.min.js` from the installed npm package
- `npm run sync:frontend` runs both the theme build and the Mergely sync

## Testing

Local test tooling lives in this repository:

- `npm run test:setup` — start the Docker Typemill instance and provision the test admin account
- `npm run test:php` — run PHP unit tests inside Docker
- `npm run test:api` — run API integration tests (Vitest)
- `npm run test` — run local PHP unit tests and API tests

See [`AGENTS.md`](AGENTS.md) for Docker URLs, login details, and API authentication notes.

## License

Plugins and themes in this repository (except where noted otherwise) are licensed under the
[GNU General Public License v2.0](LICENSE).

Bundled third-party components keep their own licenses — for example, the Versions plugin
ships [Mergely](https://mergely.com) under LGPL.
