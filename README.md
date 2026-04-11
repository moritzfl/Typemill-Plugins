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

## Plugins

### `htmldeveloper` — HTML Developer Mode

Lets you embed raw HTML, CSS, and JavaScript directly in Typemill content pages using fenced code blocks tagged
`` rawhtml ``. Useful for custom components, iframes, interactive widgets, or any markup that Markdown alone can't
express. Includes a CSP setting to whitelist external domains used by embedded content.

→ See [`plugins/htmldeveloper/README.md`](plugins/htmldeveloper/README.md) for full documentation.

---

### `files` — File Manager

Adds a **Files** page to the Typemill system settings where you can upload files of any type to `media/files/`, browse
all uploads, copy internal paths or full public URLs, download files, and delete them. Solves the problem of getting
non-image files (PDFs, audio, playlists, archives, etc.) onto the server without FTP access.

→ See [`plugins/files/README.md`](plugins/files/README.md) for full documentation.

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
