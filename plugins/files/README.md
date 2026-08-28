# Files — Typemill Plugin

A Typemill plugin that adds a folder-based file manager to the system settings. Create nested folders under
`media/files`, upload and download files in any subfolder, move or copy entries between folders, copy links,
delete files or whole folder trees, and export folders as ZIP archives — all from the admin GUI.

## Installation

See [Installation in the project README](../../README.md#installation).

For click-to-preview in the file list, also activate the **Preview** plugin.

## Usage

Open **System → Files** in the admin area.

### Folders and navigation

- Use **breadcrumbs** at the top to jump to any parent folder.
- Click a **folder row** to open it, or use **..** to go up one level.
- **New folder** creates a subfolder in the current directory.

### Uploading files

Drop files onto the upload zone or click it to open a file picker. Uploads go into the **currently open folder**.
Multiple files are uploaded sequentially with per-file status in the transfer panel.

Small files use `POST /api/v1/files/upload`. Large files (when the base64 payload would exceed PHP `post_max_size`)
use chunked upload (`/api/v1/files/chunk` + `/api/v1/files/finalize`).

### Moving and copying

- Use **Move to** or **Copy to** in a row's action menu to open a folder picker modal.
- Drag a file or folder onto another folder row to move it there.
- Hold **Shift** while dropping to copy instead of move.

When a file is moved, its recorded uploader is preserved. Copies record the current user as uploader.

### Preview

When the **Preview** plugin is active, click a previewable file row to open an inline preview modal.

### File and folder actions

| Action | What it does |
|--------|----------------|
| **Copy Path** | Relative path, e.g. `media/files/docs/guide.pdf` |
| **Copy URL** | Full public URL for external sharing |
| **Download** | Authenticated download of a single file |
| **Download ZIP** | Downloads a folder and all nested contents as a `.zip` (max 200 MB total) |
| **Move to / Copy to** | Relocate or duplicate a file or folder tree |
| **Delete** | Removes a file or folder (folders are deleted recursively after confirmation) |

The file list also shows an **Uploaded by** column when upload metadata is available.

## Security

Uploads are validated on the server when stored (direct upload and chunked finalize):

1. **Paths** — Relative paths are normalized; `..`, `.tmp`, and hidden names (leading `.`) are rejected. Writes stay inside `media/files/`.
2. **Filename** — Only the base name is kept (`basename`). Paths with `..`, null bytes, directory separators, or a leading `.` are rejected.
3. **Extension blocklist** — Script, executable, and HTML extensions are rejected.
4. **MIME sniffing** — When PHP `fileinfo` is available, content is inspected with `finfo`. Blocked MIME types and extension mismatches are rejected.

The maximum upload size follows Typemill's global `maxfileuploads` setting.

### Production deployments

The plugin does **not** scan uploads for malware. On internet-facing production sites you should:

- Treat **System → Files** as a trusted-admin feature only.
- Run **antivirus or malware scanning** on `media/files/` at the OS or storage layer.
- Serve user-supplied files with **`Content-Disposition: attachment`** or from a separate domain/CDN where appropriate.
- Keep PHP's **`fileinfo`** and **`zip`** (ZipArchive) extensions enabled for best ZIP export support.

## Plugin API routes

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/files/browse?path=` | List folders and files in a directory |
| `POST` | `/api/v1/files/folder` | Create folder (`path`, `name`) |
| `DELETE` | `/api/v1/files/entry` | Delete file or folder (`path`) |
| `POST` | `/api/v1/files/upload` | Upload file to folder (`path`, `name`, `file` data URL) |
| `POST` | `/api/v1/files/chunk` | Chunked upload (large files) |
| `POST` | `/api/v1/files/finalize` | Assemble chunks into folder (`path`, `filename`, …) |
| `POST` | `/api/v1/files/transfer` | Move or copy file/folder (`path`, `destination`, `mode`) |
| `GET` | `/api/v1/files/download?path=` | Download a file |
| `GET` | `/api/v1/files/download-zip?path=` | Download folder as ZIP |

Admin page: `GET /tm/files`
