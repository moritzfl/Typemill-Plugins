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

The following server-side executable extensions are blocked from uploading:

`.php` `.php3` `.php4` `.php5` `.php7` `.phtml` `.phar` `.asp` `.aspx` `.jsp` `.jspx` `.cgi`

Everything else is accepted, including uncommon types like `.m3u`, `.epub`, `.mobi`, audio, video, archives, and so on.

The maximum upload size follows Typemill's global `maxfileuploads` setting (defaults to 50 MB if not set).

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
