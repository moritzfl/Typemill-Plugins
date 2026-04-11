# Testing With A Live Typemill Instance

This repo can run a real Typemill instance in Docker for browser-level reproduction work.
No local Typemill checkout is needed — the image is built directly from the Typemill
GitHub repo.

## Prerequisites

- Docker Desktop or a working local Docker engine

## Start The Instance

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

The Compose file pins a specific Typemill release tag (`v2.21.3` at the time of writing).
To test against a different version, update the `context:` line in `docker-compose.typemill.yml`:

```yaml
context: https://github.com/typemill/typemill.git#v2.21.3
```

After changing the tag, rebuild with `--build` to pick it up.

## Persistent Local State

Container state (content, settings, data, cache) is stored under:

```
.docker/typemill/
```

This folder is local and intentionally not committed. It keeps repeated test sessions
fast and avoids re-running setup from scratch every time. For a clean slate, delete
`.docker/typemill/` and bring the stack up again.

## Logging In

On first start, complete the Typemill setup wizard at `http://127.0.0.1:8080/setup`.
The admin credentials you set there are stored in `.docker/typemill/settings/`.

For API calls (e.g. in automated tests or `curl`), Typemill uses session-based auth:

1. POST to `/api/v1/auth` with `{"username": "...", "password": "..."}` and include a
   `Referer: http://127.0.0.1:8080/` header — without it the request is rejected.
2. Store the returned session cookie.
3. Pass `X-Session-Auth: true` on subsequent API requests alongside the cookie.

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
