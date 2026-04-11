# Link Buttons — Typemill Plugin

A Typemill plugin that turns specially wrapped links into styled buttons.

## Installation

See [Installation in the project README](../../README.md#installation).

## Usage

Wrap a normal Markdown link in an extra pair of square brackets:

```markdown
[[Download PDF](/media/files/guide.pdf)]
[[Visit Website](https://example.com)]
```

Rendered output becomes a normal `<a>` element with a `link-button` CSS class.

## Default Styling

The plugin injects inline CSS for `.link-button`:

- Inline-flex layout with centered content
- Blue background with white text
- Rounded corners
- Hover state with a darker blue

You can override the button style in your theme CSS by targeting `.link-button`.

## Notes

- Processing happens after Markdown render (`onHtmlLoaded`).
- If a converted link already has a `class` attribute, `link-button` is appended.
- Only links in the wrapped syntax (`[[...](...)]` pattern) are converted.
