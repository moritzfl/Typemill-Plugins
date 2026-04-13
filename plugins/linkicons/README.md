# Link Icons — Typemill Plugin

A Typemill plugin that automatically adds small service icons to matching links in rendered HTML.

## Installation

See [Installation in the project README](../../README.md#installation).

## Supported services

### Typemill

- **Typemill** (`typemill.net`)

### Development & open source

- **GitHub** (`github.com`)
- **GitLab** (`gitlab.com`)
- **Codeberg** (`codeberg.org`)
- **Docker Hub** (`hub.docker.com`)
- **PyPI** (`pypi.org`)

### Apps & stores

- **App Store** (`apps.apple.com`)
- **Google Play** (`play.google.com`)
- **JetBrains Marketplace** (`plugins.jetbrains.com`)
- **Microsoft Store** (`apps.microsoft.com`)
- **Flathub** (`flathub.org`)
- **Snapcraft** (`snapcraft.io`)

### Gaming

- **Steam** (`store.steampowered.com`, `steamcommunity.com`)
- **Xbox** (`xbox.com`)
- **PlayStation** (`playstation.com`)
- **Nintendo** (`nintendo.com`)

### Social media

- **Mastodon** (instances detected by URL pattern — `/@username`, common instance domains)
- **Bluesky** (`bsky.app`)
- **Threads** (`threads.net`)
- **Lemmy** (instances detected by URL pattern — `/c/` and `/u/` paths, common instance domains)
- **Reddit** (`reddit.com`)
- **Twitter / X** (`twitter.com`, `x.com`)
- **Facebook** (`facebook.com`)
- **Instagram** (`instagram.com`)
- **LinkedIn** (`linkedin.com`)
- **TikTok** (`tiktok.com`)
- **Pinterest** (`pinterest.com`)
- **Tumblr** (`tumblr.com`)
- **Imgur** (`imgur.com`)
- **Wikipedia** (`wikipedia.org`)

### Communication

- **Discord** (`discord.com`, `discord.gg`)
- **Twitch** (`twitch.tv`)
- **WhatsApp** (`whatsapp.com`, `wa.me`)
- **Telegram** (`telegram.org`, `telegram.me`, `t.me`)

### Other

- **RSS / Atom** (URLs ending in `.rss`, `.atom`, or containing `/feed`, `/rss`, `/atom`)
- **External links** (any link pointing outside the current site)
- **Internal links** (links pointing within the current site)

## Configuration

In plugin settings you can:

- Enable or disable each icon type individually.
- Set icon position to `before` (default) or `after`.

## Usage

Write links as usual in Markdown:

```markdown
[My GitHub](https://github.com/example/repo)
[Project feed](https://example.com/feed.xml)
[@user](https://mastodon.social/@user)
```

On render, matching links get an inline SVG icon added automatically.

## Notes

- The plugin runs on rendered HTML (`onHtmlLoaded`) and updates `<a>` tags directly.
- Icons inherit the current link color (`fill: currentColor`), so they adapt to your theme automatically.
- Existing links that already contain a `link-svc-icon` are left unchanged to avoid duplicates.
- Links whose content is already an image or icon (contains HTML elements) are skipped.
