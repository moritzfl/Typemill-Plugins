# Admin and site-building UX review

## Goal

An editor should be able to answer three questions without experimenting:
**What am I changing? Who can see it? What happens when I save?**

The visual reference is Typemill's own Themes, Plugins and Content screens:
stone surfaces, square form fields, restrained borders, bold headings, dark
primary buttons and teal accents. Designer needs more horizontal room for a
preview, but should still feel like the same admin.

## Review method

Reviewed the real Docker admin, the theme schemas, native Site Blocks editing,
Files, Recycle Bin and the maintained-theme browser contracts. Captured before
screenshots of Themes, Plugins, Content, Files, Recycle Bin and Designer.
Compared actual rendered Designer states at 320, 390, 768, 1024 and 1440px,
including unsaved changes, presets, request failures, German and admin dark mode.

This is an expert walkthrough and automated regression review, not a usability
study with first-time users. Competitor observations below come from their
published documentation, not a hands-on test of their dashboards.

## Competitor lessons

| Reference | Useful pattern | Application here |
| --- | --- | --- |
| [Automad: publishing](https://www.v2.automad.org/user-guide/publishing) | Drafts and public content have explicit states; publishing is scoped to the current section. | Show saved/unsaved state and say that saving updates the live theme across the site. Keep Typemill's separate content publishing flow explicit. |
| [Automad: global defaults](https://www.v2.automad.org/user-guide/global-vs-pagedata) | Explain the distinction between global values and page-specific data. | Label Designer as site-wide; place the native page-editor handoff next to the previewed page. |
| [Automad: layouts](https://www.v2.automad.org/user-guide/layouts) | Page composition has a named, content-oriented home in the block editor. | Guide page text and layout-block work to Content, rather than implying the iframe supports inline text editing. |
| [Kirby: fields](https://getkirby.com/docs/guide/blueprints/fields) | Schema-driven labels, help and validation support an editor-specific interface. | Keep the active theme's schema authoritative; expose checkbox explanations, connect help text to controls, add search and reveal invalid fields. |

Automad's autosaved drafts are a different persistence model. Designer's private
preview is deliberately described as unsaved; it must not promise recovery after
closing the browser.

## Findings addressed

| Priority | Finding | Implemented response |
| --- | --- | --- |
| P1 | Saving theme settings and saving/publishing page content were visually mixed together. | Separate site-wide save bar, publication explanation and page-specific editor handoff. |
| P1 | Interior-page previews silently lost private settings. Core's session matcher treated `/` as a homepage-only prefix. | Start the preview session with the correct empty prefix; verify real interior-page rendering and anonymous-session isolation. |
| P1 | A preview response could replace a newer edit during the debounce interval; successful previews could erase save failures. | Invalidate responses immediately when editing. Separate persistent save errors from preview status. |
| P2 | Generic browser controls and rounded outlines looked unlike the surrounding admin. | Native-looking square fields, stone surfaces, teal section accents, matching heading and button hierarchy. |
| P2 | Presets changed multiple settings as soon as they were selected, without showing their description. | Explicit selection → description → Apply to preview → Save flow; discard restores the saved baseline. |
| P2 | “Desktop” actually meant whatever width remained beside the controls. | Name the fluid mode “Fit to panel”; offer exact 1280/768/390px viewports with canvas-local overflow. |
| P2 | Long lists made settings difficult to find; checkbox explanatory labels were dropped. | Search by translated labels, help and sections; retain checkbox explanations and accessible help associations. |
| P2 | Validation and preview failures lacked useful feedback. | Native numeric/color validation, focus/reveal on invalid saves, visible loading/error/retry states, request and iframe timeouts. |
| P2 | Refreshing a design change jumped away from the section being inspected. | Preserve the same page's scroll position, including Rückenwind's content scroller. |
| P2 | Opening Content navigated away from unsaved design work. | Open the native editor in a clearly labelled new tab. |
| P2 | Mobile stacked two large workspaces and required nested scrolling to find the preview. | Settings/Preview view switch with a single visible work area. |
| P2 | Designer chrome bypassed Typemill translations and dark-mode styling. | Route UI text through the core translation filter, add German chrome/common field labels and explicit dark tokens. |
| P3 | The embedded page showed a second Admin shortcut that could not be used. | Hide that shortcut only in authenticated design previews. |

## Remaining general UX priorities

These are concrete follow-up product tasks, not claims that all admin workflows
have been redesigned in this change.

### Follow-up implemented

- **Footer discovery:** “Where is my footer?” opens the active theme's footer
  controls or the native Site Blocks settings form. That form explains immediate
  site-wide saving and links back to Themes and, when active, Designer. Shared
  blocks link to the same form for users with system-update permission.
- **Site Blocks guidance:** all eight layouts have a contextual explanation;
  the draft → publish workflow and plain-text formatting are explicit. Controls
  now match the native admin palette, including dark mode and German. The media
  library uses a native modal dialog with focus containment/return. Native block
  Save/Cancel controls remain in flow, clear of the fixed publisher toolbar.
- **Page selection:** “Choose page” provides title/path search and distinguishes
  duplicate titles with their paths. The server reuses core navigation and access
  filters, including configured projects, restricted/unpublished ancestors and
  editor folder permissions. Reference pages are omitted. Hidden but accessible
  published pages remain selectable. No secondary page index is introduced.

### Still open

1. **P2 — Finish theme schema localization at its source.** Designer now translates
   labels when a translation exists. Theme-specific descriptions, option labels
   and preset copy are still partly English, as are native Themes forms. Put
   comprehensive translations in the theme definitions/language pipeline rather
   than maintaining a second Designer-only schema.
2. **P3 — Clarify Files' boundaries.** Files manages `media/files`, whereas block
   imagery is selected through Typemill's media library. The subtitle is accurate,
   but a visible link to image management would reduce wrong-screen searches.
3. **P3 — Reduce Recycle Bin toolbar competition.** The ordinary task is restoring
   an item, yet Export Site Content and the filled red Empty trash button dominate
   the first row. Consider moving whole-site export to a site-tools group and
   making destructive cleanup a quieter secondary action.
4. **P2 — Fix the shared admin navigation's dark active state upstream.** The
   core navigation combines both `dark:bg-stone-700` and `dark:bg-stone-200` on
   its active link, with dark text. The Docker screenshot exposed dark text on
   the dark surface. Designer now explicitly restores the intended light active
   surface on its page; the shared component needs the same correction in core.

## Verification and visual evidence

The regression suite is `tests/browser/designpanel-ux.mjs`, integrated into
`npm run test:browser`. It uses the real login, previews, native content editor,
persisted theme settings and user dark-mode preference. Network failures and
out-of-order responses are deliberately injected; unexpected page/console errors
fail the run. Fixtures and settings are restored in `finally`.

`site-authoring-ux.mjs` covers the next-priority workflows above, including real
native plugin saving without touching theme settings, page-access boundaries,
editor drafts, translated guidance and native dialog keyboard behavior. Its
screenshots include `page-chooser.png`, `shared-footer-settings.png`,
`siteblocks-guidance.png`, `siteblocks-german-dark.png`, `siteblocks-mobile.png`
and `page-chooser-mobile-german.png`.

`site-builder.mjs` separately covers all seven maintained themes, block creation,
editing, draft isolation, publication, media selection, galleries, public JSON,
private design previews and conflicting-save protection. Shared theme tests cover
navigation, focus, language, contrast, content flow and scale. Cyanine is a core
reference theme, not a maintained theme.

Screenshots are generated artifacts under `.docker/typemill/cache/ux-review/`:

- `before-designpanel.png`, `before-themes.png`, `before-files.png`, `before-versions.png`
- `designer-desktop.png`, `designer-unsaved.png`, `designer-preset.png`
- `designer-preview-error.png`, `designer-dark.png`, `designer-german.png`
- `designer-{width}.png`, `designer-preview-{width}.png` for responsive states

See `README_TESTING.md` for reproducible Docker commands. The screenshots are
review evidence, not golden-image assertions tied to fixture content or fonts.
