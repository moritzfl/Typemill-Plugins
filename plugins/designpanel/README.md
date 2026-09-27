# Design Panel

Enable the plugin, then open **System → Design panel** with a role allowed to
update system settings. The active maintained theme's own fields and readymades
become a live design workspace beside a real frontend preview.

- Edit colors (with native color pickers), base text scale, hero/CTA copy,
  homepage presentation and footer options supported by the active theme.
- Preview desktop, tablet and mobile widths. Enter a local page path or follow
  a preview link to inspect an interior page.
- Apply a readymade, adjust it, then **Save design** or **Discard changes**.
- **Edit page content** opens that page's native visual editor. Site Blocks adds
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
