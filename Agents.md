# Agents.md — AI Agent Guide: Reproducing Issues via Docker

This document tells AI agents exactly how to spin up and use the Docker test environment for this project, including all
the auth gotchas that cost significant debugging time.

---

## Docker environment

The project ships a Docker-based Typemill instance for local testing.

**Start the container (and provision the test account):**

```bash
npm run test:setup
```

**Base URL:** `http://127.0.0.1:8080`

**Admin UI:** `http://127.0.0.1:8080/tm/login`

**Credentials:** `admin` / `Test1234!`

If the container is already running, just open the URL. Host directories are bind-mounted into the container:

| Host path   | Container path        | Live reload                         |
|-------------|-----------------------|-------------------------------------|
| `plugins/`  | `/var/www/html/plugins` | PHP and JS — edit on host, refresh |
| `themes/`   | `/var/www/html/themes`  | Twig/templates yes; **compiled** `themes/rueckenwind/css/theme.css` only after `npm run build:rueckenwind` |
| `tests/`    | `/var/www/tests` (outside webroot) | Used by PHPUnit in Docker |

Plugin JS (e.g. `plugins/versions/js/editor-versions.js`) is reflected immediately without rebuilding.

---

## Automated tests

After `npm run test:setup`, credentials are written to `.env.test` for Vitest:

| Variable       | Value              |
|----------------|--------------------|
| `TM_BASE_URL`  | `http://127.0.0.1:8080` |
| `TM_USER`      | `admin`            |
| `TM_PASSWORD`  | `Test1234!`        |

**Commands:**

```bash
npm run test:setup      # start Docker, provision admin, activate test plugins, write .env.test
npm run test:api        # Vitest API tests (requires setup + running container)
npm run test:api:watch  # same, in watch mode
npm run test:php        # PHPUnit inside Docker
npm run test:php:local  # PHPUnit on the host (run test:php:local:setup once first)
npm run test            # local PHPUnit + API tests
npm run test:browser    # Puppeteer browser smoke tests in Docker (required for UI-heavy changes)
```

API tests live in `tests/api/` and use `tests/api/helpers/auth.js` for session login with the correct `Referer` and `X-Session-Auth` headers.

`test:setup` also ensures **`versions`**, **`preview`**, **`files`**, **`typemillupdate`**, **`linkbuttons`**, and **`githubreadme`** are active in settings (required for trash, file-manager, preview, core-update API tests, the theme prose layout test, and the readme meta-field test). On a fresh instance it creates minimal `settings.yaml`; on an existing instance it only toggles those plugins and refreshes the test user.

The setup script builds a local Typemill image with PHP **`zip`** baked in (exports and folder ZIP downloads). If you use an older container without it, setup installs `zip` at runtime and reloads Apache.

---

## UI-heavy changes: test full circle

**API and PHPUnit tests are not enough** for work that touches admin UI, Vue templates, drag-and-drop, modals, diff viewers, theme layout, or other browser-only behavior. Those changes must be verified **end to end**:

1. **Docker** — run against the real Typemill admin (`npm run test:setup`, then `http://127.0.0.1:8080`).
2. **Browser automation** — run the Puppeteer smoke suite, or an equivalent headless-browser tool if Puppeteer is unavailable.

```bash
npm run test:setup    # container + plugins + .env.test
npm run test:browser  # Puppeteer inside Docker (Chromium + tests/browser/)
```

Browser tests live in `tests/browser/`:

| File | Covers |
|------|--------|
| `admin-pages.mjs` | Logs in through the real login form, opens admin pages (e.g. **System → Files**, **System → Versions**), and fails on JS console errors or stuck loading states. |
| `theme-prose.mjs` | Renders one fixture page in every **own** theme at four widths and measures the running text: no block may be pulled over the one above it, consecutive paragraphs may not sit flush, nothing with a background (e.g. a Link Buttons button) may overlap text it does not own, and the page may not scroll sideways. |
| `blog-homepage.mjs` | Makes the post list the homepage in each theme, follows the pager to page two (it must stay on this site), and asks for a page of zero posts. |
| `theme-language.mjs` | Switches the site to German and reads the words that come out: labels must follow the site language, not the theme's shipped English. |
| `theme-navigation.mjs` | Opens the mobile drawer with the keyboard and checks where focus goes, then closes it with Escape and checks again. |
| `theme-contrast.mjs` | Hides the text, photographs what is behind it, and measures every run of text against the worst pixel underneath — in light and dark, and on each of Atelier's surfaces. |
| `plugin-githubreadme.mjs` | Points a page at a repository and then takes GitHub away: the stored copy has to carry the page. Also checks placement, a page naming no repository, the live fetch (tolerantly), and that the admin screens load. |

These write their own fixture page and settings and restore both in a `finally` block. Faults of this kind are invisible to the API and PHPUnit suites, which never render a theme: a negative margin only shows once an element paints a background, and contrast is decided by a stack of palettes, surfaces, gradients and scrims that no single declaration reveals.

**Cyanine ships with Typemill and is kept only as a reference**, so it is excluded from these suites — it is not ours to fix.

**When Puppeteer is not an option**, use a comparable tool (Playwright, Selenium, Cypress, etc.) with the same full-circle flow: Docker Typemill → real login → exercise the changed UI → assert no console/page errors and expected DOM state.

Do not mark UI-heavy tasks done after API tests alone unless browser verification (automated or explicit manual steps in Docker) has also passed.

---

## Opening the browser

To open the admin UI from the terminal:

```bash
open "http://127.0.0.1:8080/tm/login"
```

Log in manually via the browser form with `admin` / `Test1234!`. This is the most reliable approach for interactive
testing.

---

## Programmatic login (Python — for scripted API testing)

Typemill's `SecurityMiddleware` **rejects POST requests that lack a `Referer` header** matching the site origin. A plain
`curl` or `requests.post()` without `Referer` will get a 403/redirect. You must:

1. **GET** `/tm/login` → capture the session cookie
2. **POST** `/tm/login` with:
    - Body: `username=admin&password=Test1234!&personal-honey-mail=` (honey-mail field must be present but empty)
    - Header: `Referer: http://127.0.0.1:8080/tm/login`
    - Header: `Content-Type: application/x-www-form-urlencoded`
    - The session cookie from step 1

Example in Python (`urllib`):

```python
import urllib.request
import urllib.parse
import http.cookiejar

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

# Step 1: GET login page to obtain session cookie
opener.open('http://127.0.0.1:8080/tm/login')

# Step 2: POST login form with Referer header
body = urllib.parse.urlencode({
    'username': 'admin',
    'password': 'Test1234!',
    'personal-honey-mail': '',
}).encode()
req = urllib.request.Request('http://127.0.0.1:8080/tm/login', data=body)
req.add_header('Content-Type', 'application/x-www-form-urlencoded')
req.add_header('Referer', 'http://127.0.0.1:8080/tm/login')
opener.open(req)
# Session cookie is now stored in `jar` and sent automatically by `opener`
```

---

## API authentication

Typemill's `SessionMiddleware` requires **both** of the following on every API call:

| Requirement             | Value                                                           |
|-------------------------|-----------------------------------------------------------------|
| Session cookie          | obtained during login (handled automatically by `opener` above) |
| `X-Session-Auth` header | must be set to the string `true`                                |

The `X-Session-Auth: true` header tells Typemill's `ApiAuthentication` middleware to trust the existing web session
cookie instead of requiring HTTP Basic Auth. No token value is needed — the literal string `true` is sufficient.

Example API call after login:

```python
api_req = urllib.request.Request(
    'http://127.0.0.1:8080/api/v1/versions/page?url=/your-page',
)
api_req.add_header('X-Session-Auth', 'true')
api_req.add_header('Accept', 'application/json')
response = opener.open(api_req)
```

---

## Version ID format

Version IDs are **strings**, not integers. They are generated by PHP's `uniqid()` with a prefix:

```
version_69d9e80dbeca8522116777
```

Never use `parseInt()` on these — it returns `NaN`. Always treat them as opaque strings.

Version data is stored server-side at:

```
/var/www/html/data/versions/pages/<page-hash>.json
```

where `<page-hash>` is a hash of the page URL.

Trash and export data live under `/var/www/html/data/versions/` (pages, assets, trash snapshots).

---

## Preview plugin

Row-click preview in **System → Files** and **System → Versions** requires the **Preview** plugin to be active.
`npm run test:setup` activates it automatically.

Preview-related API routes (admin session required):

| Route | Purpose |
|-------|---------|
| `GET /api/v1/preview/file/meta?path=` | Metadata for a file under `media/` |
| `GET /api/v1/preview/file/stream?path=` | Stream bytes for media preview |
| `GET /api/v1/versions/trash/preview?record_id=&version_id=` | Recycle-bin entry preview payload |

---

## Instrumenting JS for browser debugging

For issues in plugin JS (e.g. `plugins/versions/js/editor-versions.js`):

1. Add `console.log(...)` statements directly to the JS file — changes are live-mounted into Docker immediately.
2. Open Safari DevTools: **Develop → Show Web Inspector** (enable Develop menu in Safari → Settings → Advanced first).
3. In Chrome/Firefox: **Cmd+Option+J** opens the console.
4. Hard refresh:
    - **Safari:** `Cmd+Option+R`
    - **Chrome/Firefox:** `Cmd+Shift+R`

---

## Resetting the test account

If the admin credentials stop working, re-run setup — it always overwrites the test user file with the known
credentials:

```bash
npm run test:setup
```

---

## Common gotchas

| Symptom                                     | Cause                                           | Fix                                                    |
|---------------------------------------------|-------------------------------------------------|--------------------------------------------------------|
| POST to `/tm/login` returns 403 or redirect | Missing `Referer` header                        | Add `Referer: http://127.0.0.1:8080/tm/login`          |
| API call returns 401                        | Missing `X-Session-Auth` header                 | Add `X-Session-Auth: true`                             |
| API tests skip all cases                    | `.env.test` missing or empty                    | Run `npm run test:setup`                               |
| Login works in browser but not in script    | `curl` / `requests` strips `Referer` by default | Use explicit header as shown above                     |
| Version ID parsed as `NaN`                  | `parseInt()` on string ID like `version_abc123` | Use the string directly                                |
| JS changes not reflected                    | Cached JS in browser                            | Hard refresh: `Cmd+Option+R` (Safari) or `Cmd+Shift+R` |
| Theme CSS changes not reflected             | Edited `styles/tailwind.css`, not compiled output | Run `npm run build:rueckenwind`                    |
| Export / ZIP download fails in Docker       | PHP `zip` extension missing in Apache           | Re-run `npm run test:setup` (installs + reloads Apache) |
| Preview button/row click does nothing       | Preview plugin inactive                         | Activate Preview or re-run `npm run test:setup`        |
