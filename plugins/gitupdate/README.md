# Git Update

Updates plugins and themes from a git repository, but only the ones already installed on this server. Every commit on the configured branch is a release: "latest" is that branch's newest commit, not a version tag.

The default repository is [moritzfl/Typemill-Plugins](https://github.com/moritzfl/Typemill-Plugins), branch `main`. A server that only has Court and Files will only be offered those two. Nothing in the repository that is missing here is installed.

## What it replaces

| Path | Replaced |
|------|----------|
| `plugins/{slug}/` when that plugin is installed and present in the repository | yes |
| `themes/{slug}/` when that theme is installed and present in the repository | yes |
| plugins or themes that are not installed | no |
| `content/`, `media/`, `settings/`, `data/`, `system/` | no |

Settings for a plugin stay in `settings.yaml`. The previous folder is renamed aside under `plugins/.gitupdate/` or `themes/.gitupdate/` before the new one is moved into place. A symlink is not replaced. Plugin PHP is parsed before the swap; a file that does not parse is not installed.

This plugin can replace itself. Reload the page afterwards.

A GitHub token is only needed for a private repository, or when the unauthenticated rate limit is too low. Downloads follow GitHub's redirect to `codeload.github.com` and refuse any other host. The token is not sent on that hop.
