# Design Panel

Enable the plugin, then open **System → Design panel** with a role allowed to
update system settings. The active maintained theme's own fields and readymades
become a live design workspace beside a real frontend preview.

The panel edits only the active theme's settings, never per-page settings or
content. A setting can affect the whole site (such as text size), only the
homepage (such as its hero), or particular page types. Selecting a preview page
does not change the settings being edited. Use **Content** for individual pages.

- Edit colors (with native color pickers), base text scale, hero/CTA copy,
  homepage presentation and footer options supported by the active theme.
- Find controls by searching their labels, descriptions or sections. The panel
  uses Typemill's stone surfaces, square controls and teal accents, including dark mode.
- **Fit to panel** uses the available space. Desktop (1280px), tablet (768px)
  and mobile (390px) use exact viewport widths; wider previews scroll inside the
  canvas. On smaller screens, switch between **Theme settings** and **Private preview**.
- Enter a local page path and press **Go**, or follow a preview link to inspect
  an interior page. Changing a setting keeps the preview's scroll position.
- **Choose preview page** searches published pages by title or path, including hidden
  pages. Paths distinguish identical titles. The chooser uses Typemill's native
  navigation, project and access rules; unpublished or inaccessible branches and
  reference pages are excluded. Escape closes the chooser and returns focus.
- **Start from a preset** shows its description before **Apply to preview**.
  Review the result, then **Save design** or **Discard changes**. Selecting a
  preset alone does not change anything. Applying one can replace theme text.
- **Save design** makes changes live wherever the theme uses those settings. Until then, changes
  stay in this open panel; they are not persistent drafts. Leaving with unsaved
  changes triggers the browser's standard warning.
- **Edit page content** opens that page's native visual editor in a new tab,
  preserving the panel's unsaved changes. Site Blocks adds
  layout forms there; content still follows Typemill's draft/publish workflow.

Supported themes: Atelier, Court, Legible, Lucid, Medium, Prism and Rückenwind.
Switch the active theme through Typemill's Themes screen. The panel reads each
theme's YAML schema; it does not keep another copy of its options. Unexposed
settings, custom CSS and credentials are preserved but not returned in the form.
Readymades only change controls offered by the panel.

Previews are stored in the editor's session for ten minutes, with at most five
snapshots. They never write live theme settings. A random preview URL only works
inside the same logged-in session, carries `noindex` and `no-store`, and cannot be
used as a public share link. Saving writes normal theme settings; those settings,
including the maintained themes' base type scale, also work without this plugin.
A revision check rejects saves if another editor changed the theme meanwhile.

This provides live design preview and native layout editing, not click-to-edit
text on a public page. A general frontend editing overlay with shared undo,
selection and publication state needs a supported core editor bridge. This repo
does not replace Typemill's editor or create a competing publication model.

See [UX review](UX-REVIEW.md) for the design rationale and remaining cross-admin
priorities. `tests/browser/designpanel-ux.mjs` exercises the real Docker admin,
including English/German, mobile, dark mode, errors and save/preview isolation.
