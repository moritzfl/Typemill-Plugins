# Versions — Typemill Plugin

A Typemill plugin that records a version history for every content page and keeps deleted
pages and assets in a recycle bin so they can be recovered.

## Installation

See [Installation in the project README](../../README.md#installation).

## Usage

### Browsing version history

A **Versions** tab appears in the page editor for every content page. It lists all recorded
versions in reverse-chronological order. Each entry shows the action that triggered it
(update / publish / unpublish / discard), the author, and a line diff summary (`+N / -N`).

Status events that did not change content — for example publishing a page that was already
up to date — appear as compact oneliners in the timeline instead of full version entries.

### Comparing and restoring versions

Click **Compare & Restore** on any version to open a full-screen side-by-side diff viewer
(powered by [Mergely](https://mergely.com), LGPL). The selected version appears on the left;
the current draft on the right.

- Use the dropdown above the left panel to switch to a different version.
- **Restore left** saves the left-side version as the new draft.
- **Save as draft** saves the right-side content as the new draft.

The right-side editor is editable, so you can merge changes manually before saving.

### Recycle bin

Deleted pages and deleted assets are moved to the recycle bin at **System → Versions**.
From there you can click a previewable row to inspect it (requires the **Preview** plugin),
restore it to its original location, download it, or permanently delete it.

Expired recycle-bin entries are removed automatically when they are older than the
configured retention period. Purge runs when something is deleted into the bin or when
someone opens **System → Versions**.

### Exporting version history

#### From the page editor

Open the **Versions** tab on any page and click **Export history**. A dialog lets you choose:

- **This page only** — downloads a JSON file with the full version record for that page.
- **Entire history** — downloads a ZIP archive with version data for the whole site (see below).

#### From the recycle bin

Open **System → Versions** and click **Export History** to download the same **Entire history** ZIP without opening a page first.

#### What is in the ZIP export?

The **Entire history** archive is a backup of all version-related data:

- `manifest.json` — export metadata
- `pages/*.json` — version records for every page
- `assets/*.json` — deleted asset records from the recycle bin
- `snapshots/` — snapshot files referenced by trash entries (when present)

#### Getting your data back

**While the site is running**, use the admin UI — you do not need an export file:

- **Older page content** — open the page, go to **Versions**, click **Compare & Restore** on the version you want, then **Restore left** (or edit the right panel and **Save as draft**).
- **Deleted page or file** — open **System → Versions**, find the item in the recycle bin, and click **Restore**.

Exports are for **backups**, moving data between servers, or recovery after the plugin data was lost. There is no **Import** button; you restore the files manually, then use the admin UI as above.

**From a single-page JSON export**

1. Find the `record_id` field in the JSON file.
2. Copy the file to `data/versions/pages/<record_id>.json` on your Typemill server (create the `pages` folder if needed).
3. In the admin, open that page → **Versions**. The history from the backup should appear; use **Compare & Restore** to write an older version back to the live page.

**From an Entire history ZIP**

1. Extract the archive.
2. Copy its contents into Typemill's `data/versions/` folder, merging with existing files:
   - `pages/*.json` → `data/versions/pages/`
   - `assets/*.json` → `data/versions/assets/`
   - `snapshots/` → `data/versions/snapshots/`
3. Open **System → Versions** for recycle-bin entries, or open individual pages → **Versions** for page history. Use **Restore** or **Compare & Restore** to put content back on the site.

Stop Typemill or take a backup of `data/versions/` before overwriting files. Restoring plugin data brings back version history and recycle-bin entries; live page files under `content/` are updated only when you restore through the admin.

ZIP export uses PHP's `zip` extension when available; otherwise a built-in fallback is used. The **This page only** export is always a single JSON file and does not require ZIP support.

## Configuration

Open **System → Versions** to adjust:

- **Retention days** — how long deleted items are kept before being purged (default: 30).
- **Group hours** — saves within this window by the same author are merged into a single
  version entry, so rapid editing sessions don't flood the history (default: 24).
- **Max versions** — maximum number of versions stored per page; the oldest are dropped
  when this limit is exceeded (default: 50).

## Notes

- Versions are stored as flat JSON files alongside your content — no database required.
- A new version is only created when the page content actually changes. Publish and
  unpublish events without a content change are recorded as lightweight event entries.
- The discard action (reverting a draft to the last published state) always creates a new
  version, since it undoes edits that may not have been versioned yet.
- Deleting a page through the Typemill editor is intercepted by the plugin, which snapshots
  all page files before deletion and stores them in the recycle bin.

### Export API

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/versions/page/export?url=<page-url>` | GET | JSON export for one page |
| `/api/v1/versions/export` | GET | ZIP export of all version data |
