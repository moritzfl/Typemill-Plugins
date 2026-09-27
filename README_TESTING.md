# Testing With A Live Typemill Instance

## Automated Tests

The repo includes PHP, API and real-browser test layers.

### PHP Unit Tests

Tests for pure PHP logic — path validation, ID generation, snapshot size limits.

#### Option A: inside the Docker container (recommended)

The container provides the full Typemill environment, so all plugin dependencies are available.

**First-time setup** (downloads the PHPUnit phar into the container):

```bash
docker compose -f docker-compose.typemill.yml up -d
npm run test:php:setup
```

**Run the tests:**

```bash
npm run test:php
```

#### Option B: locally, without Docker

The test suite ships with a `StorageWrapper` stub so it can run without a Typemill checkout.
You only need PHP 8.2+ and Composer available locally.

**First-time setup** (installs PHPUnit into `tests/php/vendor/`):

```bash
npm run test:php:local:setup
```

**Run the tests:**

```bash
npm run test:php:local
```

Note: filesystem-touching tests (e.g. `SnapshotLimitTest`) create temporary directories
under `sys_get_temp_dir()` and clean up after themselves.

### API Integration Tests (run from the host)

Tests for HTTP endpoints — access control, response shapes, auth flow.

**One-time setup** (starts Docker, provisions Typemill, writes `.env.test`):

```bash
npm install
npm run test:setup
```

This is fully automated — no browser wizard required. The script creates a local
Typemill instance with a fixed test admin account and writes the credentials to
`.env.test` so subsequent test runs work without any manual steps.

**Run the tests:**

```bash
npm run test:api
```

Watch mode for development:

```bash
npm run test:api:watch
```

Browser suite (Puppeteer inside Docker — admin flows, maintained themes and site-building features):

```bash
npm run test:browser
```

`site-builder.mjs` exercises native block creation, drafts and publishing,
portable layouts, shared footers, public-JSON restrictions and private design
previews. `gitupdate-packages.mjs` installs, pins and removes real fixture packages
through the dashboard using a temporary local HTTPS repository. It installs a
temporary test CA in the disposable Docker container and removes it in `finally`.
`designpanel-ux.mjs` covers settings search, preset application, private interior-page
previews, scroll retention, validation, save/discard, failed requests, stale responses,
native-editor handoff, 320–1440px layouts, mobile view switching, dark mode and German.
`site-authoring-ux.mjs` covers title-based page selection (including duplicate titles,
draft and restricted ancestors, folder permissions and keyboard operation),
all seven local block-layout explanations, the media
dialog, draft-only saving, German and dark/mobile layouts. Set `AUTHORING_SHOTS` to
a container directory to capture those screens, using the same pattern as `DESIGN_SHOTS`.

`siteblocks-library.mjs` covers the reusable-content library, native authoring,
linked/copy insertion, private drafts and previews, shared publication, usage,
revision conflicts, project/role isolation, JSON restore, footer placement and
German/mobile/dark layouts. Set `LIBRARY_SHOTS` for screenshots. The PHPUnit export
round-trip also verifies restoration of library IDs, drafts, published content,
page references, footer assignments and media from the complete site archive.

The library suite also exercises the installed Blox editor itself: click-to-edit,
inline formatting, block-level draft saves, cancellation, inserting before the
first block, deletion, pointer drag-and-drop, native media selection and stale
write recovery without losing the active input. Switching editors must not discard
even a one-character pending edit.
The suites restore their settings and content fixtures. Run browser suites
serially: theme/settings fixtures share one Typemill instance.

To capture the Designer's visual states after browser dependencies are installed:

```bash
docker compose -f docker-compose.typemill.yml exec -T \
  -e TM_BASE_URL=http://127.0.0.1 \
  -e PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
  -e DESIGN_SHOTS=/var/www/html/cache/ux-review \
  -w /var/www/tests/browser typemill node designpanel-ux.mjs
```

If the runner installed a fallback Chromium, use that executable instead.
Screenshots appear locally in `.docker/typemill/cache/ux-review/`.

### Run PHP and API Tests

```bash
npm test
```

---

This repo can run a real Typemill instance in Docker for browser-level reproduction work.
No local Typemill checkout is needed — the image is built directly from the Typemill
GitHub repo.

## Prerequisites

- Docker Desktop or a working local Docker engine

## Start The Instance

`npm run test:setup` handles starting the container. To start it manually:

```bash
docker compose -f docker-compose.typemill.yml up -d --build
```

The first run takes a minute to clone and build Typemill. Subsequent starts are instant
(Docker caches the built image).

The mapped URL is:

```
http://127.0.0.1:8080
```

This repo's `plugins/` and `themes/` directories are mounted live into the container,
so local changes are reflected immediately without rebuilding.

## Typemill Version

The Compose file pins a specific Typemill release tag (`v2.25.0` at the time of writing).
To test against a different version, update the `TYPEMILL_VERSION` build argument in
`docker-compose.typemill.yml`:

```yaml
build:
  context: ./docker/typemill
  args:
    TYPEMILL_VERSION: v2.25.0
```

After changing the tag, rebuild with `--build` to pick it up.

## Persistent Local State

Container state (content, settings, data, cache) is stored under:

```
.docker/typemill/
```

This folder is local and intentionally not committed. It keeps repeated test sessions
fast and avoids re-running setup from scratch every time. For a clean slate, delete
`.docker/typemill/` and bring the stack up again, then re-run `npm run test:setup`.

## Logging In

`npm run test:setup` provisions a test admin account automatically. The credentials
are written to `.env.test` and to `.docker/typemill/settings/users/`:

```
Username: admin
Password: Test1234!
```

For API calls (e.g. in automated tests or `curl`), Typemill uses session-based auth:

1. POST to `/tm/login` with form-encoded body (`username=...&password=...`) and a
   `Referer: http://127.0.0.1:8080/tm/login` header. Use `redirect: manual` to
   capture the `Set-Cookie` header without following the redirect.
2. Store the returned session cookie.
3. Pass `Cookie: <session>` and `X-Session-Auth: true` on subsequent API requests.

The `X-Session-Auth` header signals to Typemill's `ApiAuthentication` middleware
that the request carries a valid web session, bypassing the HTTP Basic Auth check.

## Typical Reproduction Flow

1. Start the stack with Docker Compose.
2. Open the Typemill admin in a browser.
3. Log in and navigate to the page or plugin area you want to test.
4. Make the smallest content change needed to reproduce the issue.
5. Validate the fix in the real UI, not just with unit-level reasoning.

For editor/plugin issues, prefer testing the exact admin route where the feature runs.
For the versions plugin, the relevant path is the raw editor view for a page.

## Fast Iteration Notes

- PHP and JS changes in `plugins/` and `themes/` are reflected immediately (volume mount).
- If a UI asset seems stale, do a hard refresh in the browser first.
- If backend state gets confusing, stop the stack, inspect `.docker/typemill/`, and restart.

## Useful Commands

Start:

```bash
docker compose -f docker-compose.typemill.yml up -d --build
```

Stop:

```bash
docker compose -f docker-compose.typemill.yml down
```

View logs:

```bash
docker compose -f docker-compose.typemill.yml logs -f
```

Clean slate (wipes all local Typemill state):

```bash
docker compose -f docker-compose.typemill.yml down
rm -rf .docker/typemill
docker compose -f docker-compose.typemill.yml up -d --build
```

## What To Verify

When testing a bugfix, verify all of the following where relevant:

- The target UI renders without browser console errors.
- The broken interaction can be reproduced before the fix and no longer reproduces after it.
- The visible result matches the expected user behavior, not just a silent absence of errors.
- Saving or follow-up actions still work after the main interaction succeeds.

This setup is intended for reproduction-first debugging: run the actual Typemill UI,
trigger the issue in the browser, then confirm the fix there before considering the task done.
