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
- **Entire history** — opens a chooser where you pick which **media subfolders** to include (for example `files`, `live`, `original`, `thumbs`, `custom`) and whether to include **recycle bin data**. Content pages and version history for active pages are always included.

#### From the recycle bin

Open **System → Versions** and click **Export History** to open the same chooser and download the **Entire history** ZIP without opening a page first.

#### What is in the ZIP export?

The **Entire history** archive is a **content backup**. It does not include Typemill configuration (settings, themes, plugins, or cache).

| Path in ZIP | What it is |
|-------------|------------|
| `content/` | All current pages and folders (always included) |
| `media/<folder>/` | Only the media subfolders you selected in the export dialog |
| `versions/pages/` | Version history for active pages (always included). Deleted pages are included only when **Include recycle bin data** is checked. |
| `versions/assets/` | Deleted asset records from the recycle bin (only when recycle bin is included) |
| `versions/snapshots/` | Snapshot files used by the recycle bin (only when recycle bin is included) |
| `manifest.json` | Export metadata (format version, selected media folders, recycle bin flag, file counts, timestamp) |

Maximum export size is 200 MB. Very large sites may need to export in smaller parts or increase server limits.

#### Getting your data back

**While the site is running**, use the admin UI — you do not need an export file:

- **Older page content** — open the page, go to **Versions**, click **Compare & Restore** on the version you want, then **Restore left** (or edit the right panel and **Save as draft**).
- **Deleted page or file** — open **System → Versions**, find the item in the recycle bin, and click **Restore**.

Exports are for **backups**, moving to another server, or disaster recovery. There is no **Import** button — copy the files back into place on the Typemill server, then use the admin as needed.

**From a single-page JSON export**

1. Find the `record_id` field in the JSON file.
2. Copy the file to `data/versions/pages/<record_id>.json`.
3. Open that page in the admin → **Versions** and use **Compare & Restore** if you need an older revision.

**From an Entire history ZIP**

1. Extract the archive on your computer.
2. Copy the folders onto the Typemill server (stop Typemill first, or back up the existing folders):
   - `content/` → Typemill `content/`
   - `media/` → Typemill `media/`
   - `versions/` → Typemill `data/versions/` (merge `pages/`, `assets/`, and `snapshots/`)
3. Start Typemill again. Your pages and media files are live immediately. For version timelines and recycle-bin entries, open **Versions** on a page or **System → Versions** and use **Compare & Restore** / **Restore** as usual.

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

Export requires the **`system` + `read`** permission (Typemill **manager** and **administrator** roles). Editors and authors without system access receive HTTP 403 if they call these routes directly.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/versions/page/export?url=<page-url>` | GET | JSON export for one page |
| `/api/v1/versions/export/options` | GET | Lists available media subfolders and default export options |
| `/api/v1/versions/export?media=files,live&include_recycle_bin=1` | GET | ZIP backup with selected media folders and optional recycle bin data |
