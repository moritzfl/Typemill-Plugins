# Core Update — Typemill Plugin

A Typemill plugin that updates Typemill itself from the dashboard, instead of replacing the
`system` folder over FTP by hand.

## Installation

See [Installation in the project README](../../README.md#installation).

Activate **Core Update**, then open **System → Core Update**.

## What it changes

Only the `system` directory is replaced. That directory holds the entire core:

| Path | Replaced |
|------|----------|
| `system/typemill/` | yes — core PHP, templates, admin assets |
| `system/vendor/` | yes — Composer dependencies |
| `system/autoload.php` | yes |
| `content/`, `media/`, `settings/`, `data/`, `cache/`, `plugins/`, `themes/` | **no** |
| `index.php`, `.htaccess` | **no** |

This matches Typemill's [documented manual procedure](https://docs.typemill.net/getting-started/update).

## How an update runs

1. **Preflight** — checks the zip extension, the `system` layout, write access to the project
   root, and free disk space. A failing blocking check stops the update before anything happens.
2. **Download** — fetches the release archive from `typemill.net`. The GitHub tag is deliberately
   not used: it excludes `system/vendor` and would need Composer on the server.
3. **Verify** — rejects unsafe entry paths, caps entry count and uncompressed size, and insists on
   `system/typemill/settings/defaults.yaml` and `system/vendor/autoload.php`. The version and the
   minimum PHP version are read out of the archive, so an incompatible release is refused while the
   site is still untouched.
4. **Stage** — extracts **only** `system/**`. The published archive is a full fresh-install image
   that also contains demo content, media and settings; unpacking it wholesale would overwrite the
   site.
5. **Swap** — renames the current `system` aside into a backup, then renames the staged core into
   place. Both renames happen inside the project root, so each is atomic.
6. **Self-test** — requests the site over HTTP. A 5xx triggers an automatic rollback. A connection
   failure is treated as inconclusive and does not roll back, since the server may simply be unable
   to reach itself.

The previous core is kept, so it can be restored from the same screen.

## API

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/coreupdate/status` | Installed version, latest release, environment checks, stored backups |
| `POST` | `/api/v1/coreupdate/run` | Download, verify and install the current release |
| `POST` | `/api/v1/coreupdate/rollback` | Restore a stored core |
| `DELETE` | `/api/v1/coreupdate/backup` | Delete a stored core |

All routes require an authenticated session. `status` needs `system` / `read`. The three routes that
change something are restricted to administrators (`user` / `update`) rather than `system` /
`update`, because the manager role also holds the latter, and replacing every PHP file on the site
is a far wider blast radius than the settings screens that privilege normally guards.

Add `?check=0` to `status` to skip the request to typemill.net.

Updates are serialised with a lock file, so a second run while one is in progress is refused with
409 rather than deleting the first run's staging directory.

## Working directory

Downloads, staging and backups live in `.tm-coreupdate/` in the project root. It has to sit there:
the swap renames the staged core over `system`, and `rename()` cannot cross filesystems — on a
typical Docker setup `data/` is a bind mount and would fail with `EXDEV`.

The directory is created with an `.htaccess` that denies all access. **On nginx that file is
ignored**, so if you run nginx, block `/.tm-coreupdate` in the server config, or delete stored
backups once you no longer need them.

## Requirements and limits

- PHP `zip` extension.
- PHP must be able to create and rename entries in the project root. If the files belong to an FTP
  user and PHP runs as the web server user, the preflight check reports this and the update is
  refused. There is no FTP fallback.
- Installations older than Typemill 2.23 keep Composer packages in `<root>/vendor` rather than
  `system/vendor`. Those are refused, because replacing only `system` would leave the dependencies
  stale.
- `typemill.net` publishes only the current release, so a specific older version cannot be fetched.
  Rollback therefore relies on the local backup.
- No checksum or signature is published for the release archive. Integrity rests on TLS plus the
  structural checks above, so the trust boundary is typemill.net itself: anyone able to serve a
  different archive from that host could place their own code on the site. The download is capped at
  128 MB and the unpacked size at 256 MB, which limits damage but does not authenticate the source.

## Notes

- OPcache is reset before and after the swap. If OPcache runs with `validate_timestamps` off and
  `opcache_reset()` is unavailable, restart PHP after updating; the environment panel says so.
- Compiled Twig templates in `cache/twig` are cleared after a swap, because they are generated from
  core templates.
- There is a sub-millisecond window during the swap in which `system` does not exist, and a request
  landing exactly there fails. Typemill has no maintenance mode, and this cannot be closed from PHP
  alone.
- On filesystems that refuse to rename directories — Docker's overlayfs returns `EXDEV` for
  directories still in the image's lower layer — the plugin falls back to copying the new core over
  the old one and then removing files the new version no longer ships. That route is not atomic, so
  a full copy of the previous core is taken first.
