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
From there you can preview a deleted item, restore it to its original location, download it,
or permanently delete it.

Expired recycle-bin entries are removed automatically when they are older than the
configured retention period. Purge runs when something is deleted into the bin or when
someone opens **System → Versions**.

### Exporting version history

- **Single page:** In the page editor **Versions** tab, use **Export history** to download a
  JSON file with the full version record for that page.
- **Entire site:** In **System → Versions**, use **Export all history** to download a ZIP
  archive containing:
  - `manifest.json` — export metadata
  - `pages/*.json` — all page version records
  - `assets/*.json` — deleted asset records
  - `snapshots/` — on-disk snapshot files referenced by trash entries (when present)

Exports are backups for archival or migration. The plugin does not import export files back
into Typemill automatically.

Requires the PHP `zip` extension for full-site export (single-page export is JSON only).

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
