# Files — Typemill Plugin

A Typemill plugin that adds a file manager to the system settings. Upload any file type to the `media/files` folder,
browse your uploads, copy links, download, and delete — all from a clean GUI without touching FTP.

## Installation

See [Installation in the project README](../../README.md#installation).

## Usage

Open **System → Files** in the admin area.

### Uploading files

Drop files onto the upload zone or click it to open a file picker. Multiple files can be selected at once — they are
uploaded sequentially and a status indicator shows progress for each file.

Files land directly in `media/files/` and are immediately available.

### File list

All files in `media/files/` are listed with their name, size, and upload date. Use the filter input to search by
filename.

### Actions per file

| Button        | What it does                                                                                                            |
|---------------|-------------------------------------------------------------------------------------------------------------------------|
| **Copy Path** | Copies the relative path, e.g. `media/files/filename.ext`. Use this to reference the file in Typemill content.          |
| **Copy URL**  | Copies the full public URL, e.g. `https://yoursite.com/media/files/filename.ext`. Use this to share or link externally. |
| Download icon | Downloads the file through the browser.                                                                                 |
| Delete icon   | Asks for confirmation, then permanently deletes the file.                                                               |

When you copy a path or URL, a toast notification slides up from the bottom of the screen showing the exact string that
was copied.

## Security

Uploads are validated on the server when a chunked upload is finalized:

1. **Filename** — Only the base name is kept (`basename`). Paths with `..`, null bytes, or directory separators are rejected.
2. **Extension blocklist** — Server-side script and executable extensions are rejected (for example `.php`, `.phtml`, `.phar`, `.asp`, `.jsp`, `.exe`, `.sh`, `.bat`, `.htaccess`).
3. **MIME sniffing** — When PHP's `fileinfo` extension is available, the assembled file content is inspected with `finfo` (magic bytes), not just the filename. Blocked MIME types include PHP, generic executables, and shell scripts. For common extensions (`.pdf`, `.jpg`, `.png`, `.zip`, and others), the sniffed type must match the expected type so a script cannot be uploaded as `document.pdf`.

Unlisted extensions (for example `.m3u`, `.epub`, `.mobi`) are still allowed if they pass the global MIME blocklist.

The maximum upload size follows Typemill's global `maxfileuploads` setting (defaults to 50 MB if not set).

### Production deployments

The plugin does **not** scan uploads for malware. On internet-facing production sites you should:

- Treat **System → Files** as a trusted-admin feature only (Typemill already requires authentication).
- Run **antivirus or malware scanning** on `media/files/` at the OS or storage layer (for example ClamAV on upload or via scheduled scans).
- Serve `media/files/` with **`Content-Disposition: attachment`** or from a separate domain/CDN if files are user-supplied, so browsers do not execute disguised content in the same origin as your site.
- Keep PHP's **`fileinfo`** extension enabled so MIME sniffing stays active.

MIME and extension checks reduce obvious upload abuse; they are not a substitute for virus scanning on production systems.

## API routes

The plugin only registers the admin page route `/tm/files`.

For listing, uploading, and deleting files it uses Typemill's core file APIs:

| Method   | Path             | Purpose                             |
|----------|------------------|-------------------------------------|
| `GET`    | `/api/v1/files`  | List all files in `media/files/`    |
| `POST`   | `/api/v1/file`   | Upload and publish a file           |
| `DELETE` | `/api/v1/file`   | Delete a file by name               |

This keeps the files manager aligned with medialib and allows optional integrations such as the versions recycle bin
to intercept one standard delete route.
