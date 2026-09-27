# Git Update

Updates plugins and themes from a git repository, but only the ones already installed on this server. Every commit on the configured branch is a release: "latest" is that branch's newest commit, not a version tag.

The default repository is [moritzfl/Typemill-Plugins](https://github.com/moritzfl/Typemill-Plugins), branch `main`. A server that only has Court and Files will only update those two. Missing packages are offered separately for explicit installation.

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

## Package installation and pins

System → Git Update also lists packages not yet installed from the configured
repository. Filter the list and choose **Install** to fetch exactly the displayed
commit. Activate the plugin or theme in Typemill afterwards. The repository tree
is the registry: `plugins/<slug>/<slug>.php` + `<slug>.yaml`, or
`themes/<slug>/<slug>.yaml`. No Composer process or separate registry service is
needed. Only install from repositories whose code you trust.

**Pin commit** keeps an already synced package at that commit; both individual
and bulk updates respect pins, including forced updates. **Unpin** resumes updates.
**Remove** works only for inactive packages; Git Update cannot remove itself.
Removed files are kept under `plugins/.gitupdate/removed-*` or
`themes/.gitupdate/removed-*`. Settings are archived in `data/gitupdate/removed-*.json`
and restored on reinstallation (plugins stay inactive); this survives Typemill's
automatic cleanup of absent plugin settings. Content remains. Package actions
require the administrator-level `user:update` permission and share the update lock.

Bulk updates still only replace installed folders. Installation is an explicit
action, never a side effect of checking or updating.
