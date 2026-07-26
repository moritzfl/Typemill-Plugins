# GitHub Readme — Typemill Plugin

Fills a page with a repository's readme, so the text is written once and lives where the code lives.
GitHub stays the source of truth; the page follows it.

## Installation

See [Installation in the project README](../../README.md#installation).

Activate **GitHub Readme**, then open any page in the editor and use the **github** meta tab.

## Using it

Name the repository on the page and leave the page empty:

| Field | Meaning |
|-------|---------|
| **Repository** | `owner/name`, or the address from the browser — `https://github.com/owner/name`. Empty means the page behaves like any other. |
| **Branch** | Empty follows the repository's default branch. |
| **File** | Empty lets GitHub pick the readme, whatever it is called. Or name another file, such as `docs/usage.md`. |
| **This page's own content** | Replaced by the readme (default), before it, or after it. |
| **Opening heading** | Drop the readme's first heading, because the page already has a title. |

An address that carries a branch or a file works too — `github.com/owner/name/blob/main/docs/usage.md` —
and the two fields win over it when both are given.

## The page does not go empty

This is the part worth knowing. A fetched readme is written to `data/githubreadme/` and that copy is
what the site serves whenever GitHub does not answer:

| Situation | What the reader gets |
|-----------|----------------------|
| Fetched within the freshness window | The stored copy, with no request to GitHub at all |
| Stored copy is older than that | A conditional request; unchanged means the stored copy is simply confirmed |
| GitHub unreachable, timing out, or refusing | **The stored copy**, however old |
| Rate limit reached (60 requests an hour without a token) | **The stored copy** |
| Repository renamed, made private, or deleted | **The stored copy** |
| None of the above and nothing stored yet | The page's own content, unchanged |

Nothing is ever deleted for being old. A readme fetched once keeps the page working for as long as the
site stands, and the only way to lose it is to delete the file.

After a failure GitHub is left alone for five minutes. Without that, a site being refused would spend
every single page view on a request that cannot succeed, and every visitor would wait for its timeout.

## Cost of a page view

At most one request per repository per freshness window (an hour by default), and that request is
conditional — a readme that has not changed costs nothing from the hourly allowance. Within the window
a page view touches nothing but a local file.

Requests are given five seconds. That is deliberately short: it happens while a visitor waits, and a
stored copy is a better answer than a slow one.

## Rate limits and private repositories

GitHub grants an unauthenticated server 60 requests an hour, shared by everything on that address.
With the default settings a site with a handful of readmes stays far below it.

A **token** is only needed to read a private repository, or to raise that allowance. A fine-grained
token with read access to contents is enough. It is stored in `settings.yaml` like every other plugin
setting, so it is only as protected as that file.

## What is done to the readme

A readme is written to be read on github.com and is somebody else's HTML, so two things happen on the
way in:

- **Relative addresses are pointed back at GitHub.** Pictures come from `raw.githubusercontent.com`,
  which serves the file itself; links go to the file's page on `github.com`, which is where a reader of
  a readme expects to land. Anchors are left alone.
- **The markup is walked and limited.** Scripts, styles, frames, objects, forms, event handlers,
  inline `style` attributes and `javascript:`/`data:` addresses are removed. The badges, centred logos
  and `<details>` blocks that readmes are full of are kept, because that is what a readme looks like.
  Switching **Raw HTML** off additionally drops everything Markdown did not produce itself.

Markup that cannot be parsed is not rendered at all.

## Settings

| Setting | Default | Meaning |
|---------|---------|---------|
| Check GitHub at most every | 60 minutes | How long a stored readme counts as current |
| Give up after | 5 seconds | How long to wait for GitHub |
| GitHub token | empty | For private repositories, or a higher allowance |
| API address | `https://api.github.com` | Change only for GitHub Enterprise |
| Raw HTML | on | Keep the HTML a readme contains, sanitised |

## Limits

- The readme is rendered on the **frontend** only. The editor shows the page's own content, because
  that is what the page contains; what a visitor sees is the readme.
- Headings from the readme are not in Typemill's own content structure, so a theme's table of contents
  does not list them.
- There is no "refresh now" button. Shorten the freshness window, or delete the file in
  `data/githubreadme/`.
- Why a stored copy is being served is written into the page source as an HTML comment and to the
  server log, not onto the page. On a public page a plugin can neither tell who is looking — Typemill
  starts a session only under `/tm`, `/api` and `/setup` — nor use its own translations, which are
  loaded for the admin only. A reader is not shown a message about a rate limit either way.
