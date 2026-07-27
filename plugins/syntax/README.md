# Syntax

Highlights fenced code blocks with [Shiki](https://shiki.style) and paints the
tokens in **GitHub light and dark high-contrast** at the same time. Which set
shows follows the system colour scheme — no theme picker, no second stylesheet.
The high-contrast pair is still GitHub's own palette; the default pair leaves a
few tokens (the orange "variable" especially) under the WCAG AA floor on a light
code panel.

Typemill's stock Highlight plugin (highlight.js) loads one single-scheme
stylesheet. Pick light and dark mode is unreadable; pick dark and light mode
is. Shiki's dual-theme output carries both palettes on every token as CSS
variables, so one build covers both.

## Install

1. Copy the `syntax` folder into your Typemill `plugins/` directory.
2. Activate **Syntax** under System → Plugins.
3. Deactivate the stock **Highlight** plugin if it is also installed — both
   would try to colour the same blocks.

The browser script is pre-built at `public/syntax.min.js`. To rebuild after
changing languages or the client:

```bash
cd plugins/syntax
npm install
npm run build
```

## Settings

| Setting | Default | Meaning |
|---------|---------|---------|
| Copy button | on | A control on each code block that copies the plain source |

## How themes cooperate

The plugin colours **tokens only**. The surrounding panel (`pre` background,
padding, radius, border) stays with the theme — Shiki's own background is
cleared on purpose.

Most themes need nothing else.

Themes whose code panel is **dark in both schemes** (terminal look, deep navy
block, …) should mark the document so the dark tokens stay on in light mode:

```html
<html data-code-tokens="dark">
```

or the same attribute on `<body>`. Class-based dark mode is also recognised
(`html.dark`). The reverse escape hatch is `data-code-tokens="light"`.

## Languages

A curated set is bundled (no extra network fetch, no WASM): bash, c, c++, css,
diff, docker, go, html, ini, java, javascript/jsx, json, kotlin, markdown, php,
python, ruby, rust, scss, shell, sql, toml, typescript/tsx, xml, yaml. Unknown
fences fall back to plain text. Common aliases (`js`, `ts`, `yml`, `sh`, …) are
mapped automatically.

## Why Shiki

- Dual themes are a first-class feature, not a CSS override fight.
- TextMate grammars (same family as VS Code) — more accurate than highlight.js
  for most languages.
- JavaScript regex engine — no `onig.wasm`, works under strict CSP.
- Clear split: plugin owns tokens, theme owns the panel.

## License

Plugin code is GPL-2.0, same as the other plugins in this repository. Shiki is
MIT; the bundled GitHub themes follow their upstream licenses.
