# Preview — Typemill Plugin

A Typemill plugin that provides a shared in-admin preview modal for the file manager,
recycle bin, and other system plugins.

## Installation

See [Installation in the project README](../../README.md#installation).

Activate **Preview** alongside **Files** and/or **Versions** to enable row-click preview
in those admin screens.

## Usage

When the plugin is active:

- **System → Files** — click a previewable file row to open its content or media inline.
- **System → Versions** — click a previewable recycle-bin entry to inspect a deleted page,
  text file, or media asset before restoring or deleting it permanently.

The modal supports:

- Rendered Markdown and HTML (with a toggle to raw source when available)
- Syntax-friendly text previews for common source and config formats
- Inline images, audio, video, and PDF (within size limits)

Non-previewable entries (unknown extensions, folders, etc.) are not clickable.

## Preview API

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/preview/file/meta?path=` | Preview metadata for a file under `media/` |
| `GET` | `/api/v1/preview/file/stream?path=` | Stream file bytes for media preview |

Both routes require an authenticated admin session.

## Notes

- Preview is read-only; it does not modify files or recycle-bin entries.
- The plugin adds `blob:` to the admin CSP so media previews can use object URLs.
- Large text and media previews are capped server-side to keep the admin UI responsive.
