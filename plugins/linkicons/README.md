# Link Icons — Typemill Plugin

A Typemill plugin that automatically adds small service icons to matching links in rendered HTML.

## Installation

See [Installation in the project README](../../README.md#installation).

## Supported services

### Typemill

- **Typemill** (`typemill.net`)

### Development & open source

- **GitHub** (`github.com`)
- **GitHub Sponsors** (`github.com/sponsors/...`)
- **GitLab** (`gitlab.com`)
- **Codeberg** (`codeberg.org`)
- **Docker Hub** (`hub.docker.com`)
- **npm** (`npmjs.com`)
- **Packagist** (`packagist.org`)
- **crates.io** (`crates.io`)
- **NuGet** (`nuget.org`)
- **RubyGems** (`rubygems.org`)
- **Homebrew** (`brew.sh`, `formulae.brew.sh`)
- **PyPI** (`pypi.org`)
- **Stack Overflow** (`stackoverflow.com`)

### Donations & memberships

- **PayPal** (`paypal.com`, `paypal.me`)
- **Patreon** (`patreon.com`)
- **Ko-fi** (`ko-fi.com`)
- **Buy Me a Coffee** (`buymeacoffee.com`)
- **Liberapay** (`liberapay.com`)
- **Open Collective** (`opencollective.com`)

### Extension marketplaces

- **JetBrains Marketplace** (`plugins.jetbrains.com`)
- **Visual Studio Marketplace** (`marketplace.visualstudio.com`)
- **Chrome Web Store** (`chromewebstore.google.com`, `chrome.google.com/webstore`)
- **Firefox Add-ons** (`addons.mozilla.org/.../firefox/addon/...`)

### Apps & stores

- **App Store** (`apps.apple.com`)
- **Google Play** (`play.google.com`)
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
- **PeerTube** (official domain and common `peertube` instance URLs)
- **Pixelfed** (official domain and common `pixelfed` instance URLs)
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

### Publishing & writing

- **Substack** (`substack.com`)
- **Medium** (`medium.com`)
- **dev.to** (`dev.to`)
- **Hashnode** (`hashnode.com`)

### Communication

- **Discord** (`discord.com`, `discord.gg`)
- **Signal** (`signal.org`, `signal.me`, `signal.group`)
- **Joplin** (`joplinapp.org`, `joplincloud.com`, and self-hosted instances with `joplin` in the hostname)
- **Matrix** (`matrix.to`, `matrix.org`)
- **Element** (`element.io`)
- **Slack** (`slack.com`)
- **Twitch** (`twitch.tv`)
- **WhatsApp** (`whatsapp.com`, `wa.me`)
- **Telegram** (`telegram.org`, `telegram.me`, `t.me`)

### Media & creator

- **YouTube** (`youtube.com`, `youtu.be`)
- **Vimeo** (`vimeo.com`)
- **Qobuz** (`qobuz.com`)
- **Deezer** (`deezer.com`)
- **Apple Music** (`music.apple.com`)
- **TIDAL** (`tidal.com`)
- **Spotify** (`spotify.com`, `open.spotify.com`)
- **SoundCloud** (`soundcloud.com`)
- **Bandcamp** (`bandcamp.com`)

### Podcasts

- **Apple Podcasts** (`podcasts.apple.com`)
- **Pocket Casts** (`pocketcasts.com`)
- **Overcast** (`overcast.fm`)
- **Castbox** (`castbox.fm`)
- **iHeartRadio** (`iheart.com`)

### Design

- **Figma** (`figma.com`)
- **Dribbble** (`dribbble.com`)
- **Behance** (`behance.net`)

### Other

- **Telephone** (`tel:` links)
- **Email** (`mailto:` links)
- **Maps & Addresses** (`maps.google.com`, `google.com/maps/...`, `maps.apple.com`, `openstreetmap.org`, `geo:` URIs)
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

## Icon licensing

**[Simple Icons](https://simpleicons.org/) — [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (public domain)**
Most service icons are sourced from Simple Icons. No attribution is legally required.

**[Material Symbols](https://fonts.google.com/icons) by Google — [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0)**
The external link, internal link, telephone, email, and address/map icons are derived from Material Symbols.

**[PyPI icon](https://icon-icons.com/de/symbol/pypi/132062) via icon-icons.com — [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)**
The PyPI icon is sourced from icon-icons.com and requires attribution under Creative Commons Attribution 4.0.

**Custom icons**
The Typemill, Codeberg, and Nintendo icons were created specifically for this plugin.

**Brand trademarks:** All brand logos are trademarks of their respective owners. The icons are used solely for identification purposes and do not imply endorsement.

## Notes

- The plugin runs on rendered HTML (`onHtmlLoaded`) and updates `<a>` tags directly.
- Icons inherit the current link color (`fill: currentColor`), so they adapt to your theme automatically.
- Existing links that already contain a `link-svc-icon` are left unchanged to avoid duplicates.
- Links whose content is already an image or icon (contains HTML elements) are skipped.
