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
 *
 * window.__SYNTAX__ may set { copy: true } so each block gets a copy control.
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

const ICON_COPY = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5.75 1.75h6.5a1 1 0 0 1 1 1v6.5a1 1 0 0 1-1 1h-6.5a1 1 0 0 1-1-1v-6.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.4"/><path d="M3.75 5.25h-.5a1 1 0 0 0-1 1v6.5a1 1 0 0 0 1 1h6.5a1 1 0 0 0 1-1v-.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>'
const ICON_OK = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 8.5 6.5 11.5 12.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'

function config() {
    const raw = typeof window !== 'undefined' ? window.__SYNTAX__ : null
    const labels = raw && raw.labels && typeof raw.labels === 'object' ? raw.labels : {}
    return {
        copy: !raw || raw.copy !== false,
        labels: {
            copy: typeof labels.copy === 'string' && labels.copy ? labels.copy : 'Copy code',
            copied: typeof labels.copied === 'string' && labels.copied ? labels.copied : 'Copied',
            failed: typeof labels.failed === 'string' && labels.failed ? labels.failed : 'Copy failed',
        },
    }
}

function languageOf(block) {
    const classes = block.className || ''
    const match = classes.match(/(?:language|lang)-([a-z0-9_+#-]+)/i)
    if (!match) return null
    const raw = match[1].toLowerCase()
    return ALIASES[raw] || raw
}

function plainTextOf(pre) {
    // Prefer the <code> node so a copy button's label is never included.
    const code = pre.querySelector('code')
    return (code ? code.textContent : pre.textContent) || ''
}

async function writeClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text)
        return
    }
    const area = document.createElement('textarea')
    area.value = text
    area.setAttribute('readonly', '')
    area.style.position = 'fixed'
    area.style.top = '-9999px'
    document.body.appendChild(area)
    area.select()
    try {
        if (!document.execCommand('copy')) {
            throw new Error('copy failed')
        }
    } finally {
        area.remove()
    }
}

function attachCopy(pre, labels) {
    if (pre.closest('.syntax-block')) return pre

    const shell = document.createElement('div')
    shell.className = 'syntax-block'

    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'syntax-copy'
    button.setAttribute('aria-label', labels.copy)
    button.innerHTML = ICON_COPY

    let resetTimer = 0
    button.addEventListener('click', async () => {
        try {
            await writeClipboard(plainTextOf(pre))
            button.classList.add('is-copied')
            button.setAttribute('aria-label', labels.copied)
            button.innerHTML = ICON_OK
            window.clearTimeout(resetTimer)
            resetTimer = window.setTimeout(() => {
                button.classList.remove('is-copied')
                button.setAttribute('aria-label', labels.copy)
                button.innerHTML = ICON_COPY
            }, 1600)
        } catch {
            button.setAttribute('aria-label', labels.failed)
        }
    })

    const parent = pre.parentNode
    if (!parent) return pre
    parent.insertBefore(shell, pre)
    shell.appendChild(button)
    shell.appendChild(pre)
    return pre
}

function highlightBlock(highlighter, pre, code, options) {
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
    if (options.copy) attachCopy(next, options.labels)
}

async function run() {
    const options = config()
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
            highlightBlock(highlighter, pre, code, options)
        } catch {
            // Leave the plain block alone if a grammar misbehaves; still offer copy.
            if (options.copy) attachCopy(pre, options.labels)
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
