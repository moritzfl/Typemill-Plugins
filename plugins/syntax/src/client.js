/**
 * Client-side Shiki highlighter for Typemill content pages.
 *
 * Dual GitHub themes are baked into each token as CSS variables. The matching
 * stylesheet (css/syntax.css) picks light or dark from the system scheme, or
 * from data-code-tokens / html.dark when a theme keeps a dark panel in light
 * mode. The panel chrome stays with the theme.
 *
 * Languages are the set a docs / portfolio site actually hits. Adding one is a
 * one-line import plus a rebuild — the full Shiki catalogue is not shipped.
 */
import { createHighlighterCore } from 'shiki/core'
import { createJavaScriptRegexEngine } from 'shiki/engine/javascript'

// High-contrast variants: GitHub's default prettylights leave a few tokens
// (the orange "variable" especially) under 4.5:1 on a light panel.
import githubLight from '@shikijs/themes/github-light-high-contrast'
import githubDark from '@shikijs/themes/github-dark-high-contrast'

import langBash from '@shikijs/langs/bash'
import langCss from '@shikijs/langs/css'
import langDiff from '@shikijs/langs/diff'
import langHtml from '@shikijs/langs/html'
import langJava from '@shikijs/langs/java'
import langJs from '@shikijs/langs/javascript'
import langJson from '@shikijs/langs/json'
import langKotlin from '@shikijs/langs/kotlin'
import langMd from '@shikijs/langs/markdown'
import langPhp from '@shikijs/langs/php'
import langPython from '@shikijs/langs/python'
import langShell from '@shikijs/langs/shellscript'
import langTs from '@shikijs/langs/typescript'
import langXml from '@shikijs/langs/xml'
import langYaml from '@shikijs/langs/yaml'

const LANGS = [
    langBash, langCss, langDiff, langHtml, langJava, langJs, langJson,
    langKotlin, langMd, langPhp, langPython, langShell, langTs, langXml, langYaml,
]

// Common aliases Typemill / GitHub fences use that Shiki names differently.
const ALIASES = {
    js: 'javascript',
    ts: 'typescript',
    py: 'python',
    sh: 'bash',
    shell: 'shellscript',
    zsh: 'bash',
    yml: 'yaml',
    md: 'markdown',
    text: 'txt',
    plain: 'txt',
    plaintext: 'txt',
}

function languageOf(block) {
    const classes = block.className || ''
    const match = classes.match(/(?:language|lang)-([a-z0-9_+#-]+)/i)
    if (!match) return null
    const raw = match[1].toLowerCase()
    return ALIASES[raw] || raw
}

function highlightBlock(highlighter, pre, code) {
    const lang = languageOf(code)
    const source = code.textContent || ''
    const loaded = lang && highlighter.getLoadedLanguages().includes(lang)
    const html = highlighter.codeToHtml(source, {
        lang: loaded ? lang : 'text',
        themes: {
            light: 'github-light-high-contrast',
            dark: 'github-dark-high-contrast',
        },
        defaultColor: false,
    })

    const wrap = document.createElement('div')
    wrap.innerHTML = html.trim()
    const next = wrap.firstElementChild
    if (!next) return

    // Keep any classes the theme or another plugin put on the original <pre>
    // (for example a copy-button host), minus the language tag Shiki replaces.
    const keep = Array.from(pre.classList).filter((name) => !/^(language|lang)-/i.test(name))
    if (keep.length) next.classList.add(...keep)

    pre.replaceWith(next)
}

async function run() {
    const blocks = Array.from(document.querySelectorAll('pre code'))
    if (!blocks.length) return

    const highlighter = await createHighlighterCore({
        themes: [githubLight, githubDark],
        langs: LANGS,
        // No WASM: works under strict CSP and without a separate asset fetch.
        engine: createJavaScriptRegexEngine(),
    })

    for (const code of blocks) {
        const pre = code.parentElement
        if (!pre || pre.tagName !== 'PRE') continue
        if (pre.classList.contains('shiki')) continue
        try {
            highlightBlock(highlighter, pre, code)
        } catch {
            // Leave the plain block alone if a grammar misbehaves.
        }
    }

    highlighter.dispose()
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        run()
    })
} else {
    run()
}
