# Typemill Update — Typemill Plugin

A Typemill plugin that updates Typemill itself from the dashboard, instead of replacing the
`system` folder over FTP by hand, and that updates plugins listed in the
[Typemill plugin directory](https://plugins.typemill.net).

## Installation

See [Installation in the project README](../../README.md#installation).

Activate **Typemill Update**, then open **System → Typemill Update**.

## What it changes

Only the `system` directory is replaced. That directory holds the entire core:

| Path | Replaced |
|------|----------|
| `system/typemill/` | yes — core PHP, templates, admin assets |
| `system/vendor/` | yes — Composer dependencies |
| `system/autoload.php` | yes |
| `content/`, `media/`, `settings/`, `data/`, `cache/`, `themes/` | **no** |
| `plugins/{slug}/` for a plugin listed in the Typemill directory | **yes**, when that plugin is updated |
| other `plugins/` folders | **no** |
| `index.php`, `.htaccess` | **no** |

A core update matches Typemill's [documented manual procedure](https://docs.typemill.net/getting-started/update).
Plugin updates replace the same folder an admin would replace over FTP when the
author panel shows an update banner.

## How an update runs

1. **Preflight** — checks the zip extension, the `system` layout, write access to the project
   root, and free disk space. A failing blocking check stops the update before anything happens.
2. **Download** — fetches the release archive from `typemill.net`. The GitHub tag is deliberately
   not used: it excludes `system/vendor` and would need Composer on the server.
3. **Verify** — rejects unsafe entry paths, caps entry count and uncompressed size, and insists on
   the three files the site boots from: `system/autoload.php`,
   `system/typemill/settings/defaults.yaml` and `system/vendor/autoload.php`. The version and the
   minimum PHP version are read out of the archive, so an incompatible release is refused while the
   site is still untouched. The space the unpacked core really needs is claimed and released once
   the archive has been read, because the preflight figure is only an estimate from the version
   being replaced.
4. **Stage** — extracts **only** `system/**`. The published archive is a full fresh-install image
   that also contains demo content, media and settings; unpacking it wholesale would overwrite the
   site.
5. **Swap** — renames the current `system` aside into a backup, then renames the staged core into
   place. Both renames happen inside the project root, so each is atomic. If the filesystem refuses
   to rename the core, the update stops there with nothing changed.
6. **Self-test** — requests the site over HTTP. A 5xx triggers an automatic rollback. A connection
   failure is treated as inconclusive and does not roll back, since the server may simply be unable
   to reach itself.

The previous core is kept, so it can be restored from the same screen.

## Plugins from the directory

The same screen lists installed plugins that also appear in the Typemill
directory, and offers an update when the directory has a newer version. Plugins
this site has that the directory does not know — including this updater itself —
are left alone.

An update downloads `https://plugins.typemill.net/download?plugins={slug}`,
checks that the zip is exactly that plugin (`{slug}/{slug}.php` and
`{slug}/{slug}.yaml`, no Zip Slip, no extra top-level files), then renames the
live folder aside and the staged folder into its place. Staging and the backup
live under `plugins/.tm-update/`, on the same filesystem as the plugin: when
`plugins/` is a bind mount, a rename from the project-root working directory
would fail with `EXDEV`.

Settings for the plugin stay in `settings.yaml`. A MAKER or BUSINESS plugin can
still be downloaded; activation remains a licence check in Typemill itself.

## Installing from a file

The screen also accepts a Typemill ZIP directly. This covers the cases the download cannot:

- installing a **specific version**, for example going back to an older release — typemill.net only
  publishes the current one, so it cannot be fetched
- servers with **no outbound network access**
- testing a **custom or pre-release build**

The file is checked exactly like a downloaded one, and the version found inside it is shown for
confirmation before anything is replaced. Installing the same version again, or an older one, is
allowed here — that is much of the point — and the dialog says which case it is.

Two practical details:

- **The archive must contain `system/vendor`.** Downloads from GitHub do not: the tag excludes the
  Composer dependencies, so a GitHub ZIP is rejected with that explanation. Use the ZIP from
  typemill.net, or a copy of a working installation.
- An archive that wraps everything in a **single top-level directory** is accepted; the wrapper is
  detected and ignored.

Uploads are sent in 512 KB slices as base64 rather than as a form upload, because PHP's default
`upload_max_filesize` is 2 MB while a release archive is around 2.5 MB. No server configuration
change is needed.

## API

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/typemillupdate/status` | Installed version, latest release, environment checks, stored backups, directory plugins |
| `POST` | `/api/v1/typemillupdate/run` | Install. Downloads the current release, or installs a previously uploaded archive when given `archive` |
| `POST` | `/api/v1/typemillupdate/plugin` | Update one installed directory plugin. Body: `{ "plugin": "search" }` |
| `POST` | `/api/v1/typemillupdate/upload/chunk` | Receive one base64 slice of an archive |
| `POST` | `/api/v1/typemillupdate/upload/finalize` | Assemble and verify the upload, and report the version it contains |
| `POST` | `/api/v1/typemillupdate/rollback` | Restore a stored core |
| `DELETE` | `/api/v1/typemillupdate/backup` | Delete a stored core |

All routes require an authenticated session. `status` needs `system` / `read`. The three routes that
change something are restricted to administrators (`user` / `update`) rather than `system` /
`update`, because the manager role also holds the latter, and replacing every PHP file on the site
is a far wider blast radius than the settings screens that privilege normally guards.

Add `?check=0` to `status` to skip the requests to typemill.net and
plugins.typemill.net.

Updates, rollbacks and backup deletions are serialised with a lock file, so a second one while
another is in progress is refused with 409 rather than deleting the first one's staging directory or
pulling a backup out from under a restore.

Uploads cannot take that lock — they arrive over many requests — so they are never deleted by
ownership. Chunks from an upload the browser abandoned, and archives that were never confirmed, are
swept once they are six hours old.

## Working directory

Core downloads, staging and backups live in `.tm-update/` in the project root. It has to sit there:
the swap renames the staged core over `system`, and `rename()` cannot cross filesystems — on a
typical Docker setup `data/` is a bind mount and would fail with `EXDEV`.

Plugin staging and backups live in `plugins/.tm-update/` for the same reason: `plugins/` is often
its own mount, and the live plugin folder has to be renamed on that filesystem.

Both directories are created with an `.htaccess` that denies all access. **On nginx that file is
ignored**, so if you run nginx, block `/.tm-update` and `/plugins/.tm-update` in the server config,
or delete stored backups once you no longer need them.

## Requirements and limits

- PHP `zip` extension.
- PHP must be able to create and rename entries in the project root. If the files belong to an FTP
  user and PHP runs as the web server user, the preflight check reports this and the update is
  refused. There is no FTP fallback.
- The filesystem must allow `system` itself to be renamed. Docker's overlayfs returns `EXDEV` for
  directories still in the image's lower layer, and some network mounts behave similarly. There is
  no copy fallback: replacing a core file by file cannot be undone in one step, so a failure part
  way through would leave a half-replaced core. The update stops instead, before anything is
  touched, and the site keeps running on the version it has.
- Installations older than Typemill 2.23 keep Composer packages in `<root>/vendor` rather than
  `system/vendor`. Those are refused, because replacing only `system` would leave the dependencies
  stale.
- `typemill.net` publishes only the current release, so a specific older version cannot be fetched.
  Rollback therefore relies on the local backup.
- PHP must be able to create and rename entries in `plugins/` for a plugin update. That check is
  separate from the core preflight: a site that cannot rename `system` can still update plugins.
- No checksum or signature is published for the release archive or for directory plugins. Integrity
  rests on TLS plus the structural checks above, so the trust boundary is typemill.net /
  plugins.typemill.net: anyone able to serve a different archive from those hosts could place their
  own code on the site. Core downloads are capped at 128 MB and 256 MB unpacked; plugin zips at
  64 MB unpacked.

## Notes

- OPcache is reset before and after the swap. If OPcache runs with `validate_timestamps` off and
  `opcache_reset()` is unavailable, restart PHP after updating; the environment panel says so.
- Compiled Twig templates in `cache/twig` are cleared after a swap, because they are generated from
  core templates.
- There is a sub-millisecond window during the swap in which `system` does not exist, and a request
  landing exactly there fails. Typemill has no maintenance mode, and this cannot be closed from PHP
  alone.
