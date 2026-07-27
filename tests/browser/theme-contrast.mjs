/**
 * Text has to be readable against what it actually lands on.
 *
 * Contrast in these themes is not decided by one declaration but by a stack of
 * them: a palette, a dark-mode palette, a surface chosen in the settings, a
 * gradient, a scrim, an opacity. Reading any one of those tells you nothing,
 * which is how Atelier ended up painting near-white text on near-white paper
 * whenever the warm surface met a dark system theme.
 *
 * So the colours are measured where they end up: the browser is asked for the
 * computed colour of real elements and for the background actually behind them,
 * including gradients, which are sampled from a screenshot.
 *
 * WCAG 1.4.3: 4.5:1 for body text, 3:1 for large text (24px, or 18.66px bold).
 *
 * Run inside the Typemill Docker container:
 *   npm run test:browser
 */
import puppeteer from 'puppeteer'
import { readFileSync, writeFileSync, existsSync, readdirSync, rmSync, unlinkSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'

const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')
const CONTENT_DIR = join(TM_ROOT, 'content')

const PAGE_URL = process.env.TM_SUBPAGE || '/getting-started/edit-the-page-meta'

// Code is the quietest text a theme sets: a tinted panel, a smaller size and,
// in several themes, a deliberately faded colour. That is exactly where a
// palette slips below the floor without anyone noticing, so a page of it is
// written here rather than hoping the demo content contains some.
const CODE_SLUG = '94-theme-code-fixture'
const CODE_URL = '/theme-code-fixture'
const CODE_MARKDOWN = `# Theme code fixture

A paragraph that mentions \`inline code\` in the middle of a sentence, and a
second mention of \`~/.config/opencode/opencode.json\` to make it longer.

\`\`\`json
{
  "plugin": ["opencode-codex-memory@0.4.1"],
  "enabled": true,
  "retries": 3
}
\`\`\`

\`\`\`
~/.local/share/opencode/
  memory.db            # the plugin's own database
  memories/            # one recap per past session
\`\`\`

* A list item with \`inline code\` in it
* A plain list item

> A quote that carries \`inline code\` too.
`

/**
 * Each case is a theme, the settings it needs, and the page to look at.
 * Atelier is measured on all three of its surfaces, because the surface is a
 * background and the palette that meets it is chosen separately.
 */
const CASES = [
    { theme: 'atelier', settings: { surface: 'white' } },
    { theme: 'atelier', settings: { surface: 'warm' } },
    { theme: 'atelier', settings: { surface: 'dark' } },
    { theme: 'legible', settings: {} },
    { theme: 'lucid', settings: {} },
    { theme: 'medium', settings: {} },
    { theme: 'prism', settings: {} },
    { theme: 'court', settings: {} },
    { theme: 'rueckenwind', settings: {} },
]

// The homepage carries the hero, the buttons and the section cards; the
// subpage carries the running text, the breadcrumb and the pager, and the code
// fixture carries the panels that running text never touches.
const URLS = ['/', PAGE_URL, CODE_URL]

const SCHEMES = ['light', 'dark']

function assert(condition, message) {
    if (!condition) {
        throw new Error(message)
    }
}

function clearNavigationCache() {
    if (!existsSync(NAV_CACHE)) {
        return
    }
    for (const entry of readdirSync(NAV_CACHE)) {
        rmSync(join(NAV_CACHE, entry), { force: true, recursive: true })
    }
}

function writeCodeFixture() {
    writeFileSync(join(CONTENT_DIR, `${CODE_SLUG}.md`), CODE_MARKDOWN)
    writeFileSync(
        join(CONTENT_DIR, `${CODE_SLUG}.yaml`),
        "meta:\n    title: 'Theme code fixture'\n    hide: true\n    noindex: true\n"
    )
    clearNavigationCache()
}

function removeCodeFixture() {
    for (const extension of ['md', 'yaml', 'txt']) {
        const file = join(CONTENT_DIR, `${CODE_SLUG}.${extension}`)
        if (existsSync(file)) {
            unlinkSync(file)
        }
    }
    clearNavigationCache()
}

function configure(theme, settings) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    content = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, `theme: ${theme}`)
        : `theme: ${theme}\n${content}`

    const lines = Object.entries(settings).map(([key, value]) => `        ${key}: ${value}`)
    const block = [`    ${theme}:`, ...lines].join('\n') + '\n'
    const existing = new RegExp(
        `^ {4}${theme}:(?:\\n(?: {5,}.*)?)*\\n|^ {4}${theme}:[^\\n]*\\n`,
        'm'
    )

    if (existing.test(content)) {
        content = content.replace(existing, block)
    } else if (/^themes:$/m.test(content)) {
        content = content.replace(/^themes:$/m, `themes:\n${block.replace(/\n$/, '')}`)
    } else {
        content = `${content.replace(/\n*$/, '')}\nthemes:\n${block}`
    }

    writeFileSync(SETTINGS_FILE, content)
    clearNavigationCache()
}

/**
 * Every piece of text worth measuring: the colour it is painted in, its box,
 * and enough of its type to know which threshold applies.
 *
 * What is behind it is deliberately not worked out from the DOM. A floating
 * navigation sits on a picture, a hero sits on a gradient under a scrim, and
 * walking up the tree for a background colour answers "white" for both. The
 * caller hides this text and photographs what was underneath instead.
 */
function collectText() {
    // Colours are read back through a canvas rather than parsed: a Tailwind
    // theme states them in oklch(), and picking the numbers out of that string
    // yields nonsense. The browser converts anything it can paint.
    const swatch = document.createElement('canvas')
    swatch.width = 1
    swatch.height = 1
    const brush = swatch.getContext('2d', { willReadFrequently: true })

    const parse = (value) => {
        brush.clearRect(0, 0, 1, 1)
        brush.fillStyle = value
        brush.fillRect(0, 0, 1, 1)
        const data = brush.getImageData(0, 0, 1, 1).data
        return [data[0], data[1], data[2], data[3] / 255]
    }

    const results = []
    const seen = new Set()

    const candidates = document.querySelectorAll(
        'main p, article p, .at-prose p, .lg-prose p, .md-prose p, .lc-prose p, .pr-prose p,'
        // Code is text too, and it is the text themes fade hardest: a tinted
        // panel, a smaller size and a quieter colour.
        + ' main pre, article pre, main code, article code,'
        + ' time, nav a, footer a, footer p, .at-label, .at-tile__meta, .lc-post__date, .lc-pager__label,'
        + ' .md-byline__meta, .md-post__meta, .pr-post__date, .pr-eyebrow, .pr-hero__subtitle,'
        + ' .lc-btn, .pr-btn, .md-btn, .lg-pager__label, [class*="breadcrumb"] a'
    )

    let index = 0

    for (const node of candidates) {
        const text = (node.textContent || '').trim()
        if (!text) continue

        const rect = node.getBoundingClientRect()
        if (rect.width < 2 || rect.height < 2) continue
        if (rect.bottom < 0 || rect.top > window.innerHeight) continue
        if (rect.right < 0 || rect.left > window.innerWidth) continue

        const style = getComputedStyle(node)
        if (style.visibility === 'hidden' || style.display === 'none') continue

        // A colour written into the page by whoever wrote the page is their
        // decision, not the theme's, and no theme can be held to it.
        if (/(^|;)\s*color\s*:/i.test(node.getAttribute('style') || '')) continue

        const key = node.tagName + '|' + text.slice(0, 24) + '|' + Math.round(rect.top)
        if (seen.has(key)) continue
        seen.add(key)

        const size = parseFloat(style.fontSize)
        const weight = parseInt(style.fontWeight, 10) || 400
        const large = size >= 24 || (size >= 18.66 && weight >= 700)

        node.setAttribute('data-contrast-probe', String(index))

        const painted = parse(style.color)

        // Text on a panel of its own - a code block, a chip - sits on that
        // panel, not on whatever is behind the panel. Those are photographed
        // in a second pass that clears the ink but keeps the panel painted.
        const own = parse(style.backgroundColor)
        const paintsOwnBackground = own[3] > 0.05 || style.backgroundImage !== 'none'
        if (paintsOwnBackground) {
            node.setAttribute('data-contrast-panel', '')
        }

        // A rounded panel's bounding box includes its corners, and the corners
        // are the page behind it. Sampling those answers "light text on white"
        // for a panel that is in fact dark, so the sample is pulled inside the
        // curve.
        const radius = Math.max(
            parseFloat(style.borderTopLeftRadius) || 0,
            parseFloat(style.borderBottomRightRadius) || 0
        )
        const inset = paintsOwnBackground ? Math.min(Math.ceil(radius) + 2, 24) : 0
        const sample = {
            x: Math.max(0, rect.x + inset),
            y: Math.max(0, rect.y + inset),
            width: Math.max(1, Math.min(rect.width - inset * 2, window.innerWidth - Math.max(0, rect.x + inset))),
            height: Math.max(1, Math.min(rect.height - inset * 2, window.innerHeight - Math.max(0, rect.y + inset))),
        }

        results.push({
            index: index++,
            text: text.slice(0, 40),
            selector: node.tagName.toLowerCase() + (node.className ? '.' + String(node.className).split(' ')[0] : ''),
            color: painted.slice(0, 3),
            panel: paintsOwnBackground,
            // Faded text is really its colour mixed with whatever is behind it,
            // whether the fade comes from opacity or from the colour's alpha.
            opacity: (parseFloat(style.opacity) || 1) * painted[3],
            large,
            rect: paintsOwnBackground ? sample : {
                x: Math.max(0, rect.x),
                y: Math.max(0, rect.y),
                width: Math.min(rect.width, window.innerWidth - Math.max(0, rect.x)),
                height: Math.min(rect.height, window.innerHeight - Math.max(0, rect.y)),
            },
        })
    }

    return results
}

/**
 * Hide the measured text so the background behind it can be photographed.
 *
 * Panels keep their ink here: a code block's own background is what the code
 * inside it sits on, and hiding the block would take that background away and
 * answer for the page behind it instead. Panels are measured in the ink pass.
 */
function hideProbes() {
    const style = document.createElement('style')
    style.id = 'contrast-probe-style'
    style.textContent = '[data-contrast-probe]:not([data-contrast-panel]) { visibility: hidden !important; }'
    document.head.appendChild(style)
}

/**
 * Clear the ink but keep every panel painted.
 *
 * `visibility: hidden` would take an element's own background with it, and a
 * code block is exactly a panel whose background is the thing its text sits on;
 * measuring that text against the page behind the panel answers the wrong
 * question. Descendants are included so a paragraph does not keep the inline
 * code inside it painted while the paragraph itself goes clear.
 */
function hideInk() {
    const style = document.createElement('style')
    style.id = 'contrast-ink-style'
    style.textContent = '[data-contrast-probe], [data-contrast-probe] * {'
        + ' color: transparent !important; text-shadow: none !important;'
        + ' text-decoration-color: transparent !important; }'
    document.head.appendChild(style)
}

function showInk() {
    const style = document.getElementById('contrast-ink-style')
    if (style) style.remove()
}

function removeProbeStyle() {
    const style = document.getElementById('contrast-probe-style')
    if (style) style.remove()
}

function showProbes() {
    const style = document.getElementById('contrast-probe-style')
    if (style) style.remove()
    document.querySelectorAll('[data-contrast-probe]').forEach((node) => {
        node.removeAttribute('data-contrast-probe')
        node.removeAttribute('data-contrast-panel')
    })
}

/**
 * The lightest and darkest pixel behind each box, read from one screenshot
 * taken with the text hidden. Done inside the page so only a handful of numbers
 * cross back, rather than a megabyte of pixels.
 */
async function extremesPerRect(base64, rects) {
    const blob = await (await fetch('data:image/png;base64,' + base64)).blob()
    const bitmap = await createImageBitmap(blob)
    const canvas = document.createElement('canvas')
    canvas.width = bitmap.width
    canvas.height = bitmap.height
    const context = canvas.getContext('2d', { willReadFrequently: true })
    context.drawImage(bitmap, 0, 0)

    const channel = (v) => {
        const s = v / 255
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
    }
    const luminance = (r, g, b) => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)

    return rects.map((rect) => {
        const x = Math.max(0, Math.floor(rect.x))
        const y = Math.max(0, Math.floor(rect.y))
        const width = Math.max(1, Math.min(Math.ceil(rect.width), canvas.width - x))
        const height = Math.max(1, Math.min(Math.ceil(rect.height), canvas.height - y))

        const data = context.getImageData(x, y, width, height).data

        let lightest = [255, 255, 255]
        let darkest = [0, 0, 0]
        let high = -1
        let low = 2

        for (let i = 0; i < data.length; i += 4) {
            const l = luminance(data[i], data[i + 1], data[i + 2])
            if (l > high) {
                high = l
                lightest = [data[i], data[i + 1], data[i + 2]]
            }
            if (l < low) {
                low = l
                darkest = [data[i], data[i + 1], data[i + 2]]
            }
        }

        return { lightest, darkest }
    })
}

const luminance = ([r, g, b]) => {
    const channel = (v) => {
        const s = v / 255
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
    }
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

const contrast = (a, b) => {
    const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x)
    return (l1 + 0.05) / (l2 + 0.05)
}

const blend = (color, background, alpha) =>
    color.map((c, i) => Math.round(alpha * c + (1 - alpha) * background[i]))

async function assertContrast(page, label) {
    const measured = await page.evaluate(collectText)
    assert(measured.length > 0, `${label}: no text was found to measure`)

    await page.evaluate(hideProbes)
    const shot = await page.screenshot({ encoding: 'base64' })
    const extremes = await page.evaluate(
        extremesPerRect,
        shot,
        measured.map((entry) => entry.rect)
    )
    await page.evaluate(removeProbeStyle)

    // Panels are measured against themselves rather than against the page they
    // are laid on, so they need their own photograph.
    if (measured.some((entry) => entry.panel)) {
        await page.evaluate(hideInk)
        const panelShot = await page.screenshot({ encoding: 'base64' })
        const panelExtremes = await page.evaluate(
            extremesPerRect,
            panelShot,
            measured.map((entry) => entry.rect)
        )
        await page.evaluate(showInk)

        for (const [i, entry] of measured.entries()) {
            if (entry.panel) extremes[i] = panelExtremes[i]
        }
    }

    await page.evaluate(showProbes)

    const failures = []

    for (const [i, entry] of measured.entries()) {
        const required = entry.large ? 3 : 4.5

        // Both extremes of what is behind the box, because a gradient or a
        // picture is not one colour, and the text has to clear the worst of it.
        const worst = [extremes[i].lightest, extremes[i].darkest]
            .map((background) => ({
                background,
                ratio: contrast(blend(entry.color, background, entry.opacity), background),
            }))
            .sort((a, b) => a.ratio - b.ratio)[0]

        if (worst.ratio < required - 0.05) {
            failures.push(
                `  ${entry.selector} "${entry.text}" rgb(${entry.color})`
                + (entry.opacity < 1 ? ` at ${entry.opacity} opacity` : '')
                + ` on rgb(${worst.background}) = ${worst.ratio.toFixed(2)}:1, needs ${required}:1`
            )
        }
    }

    assert(failures.length === 0, `${label}: text below the contrast floor:\n${failures.join('\n')}`)

    return measured.length
}

async function main() {
    if (!existsSync(SETTINGS_FILE)) {
        console.error(`settings.yaml not found at ${SETTINGS_FILE}; run npm run test:setup first`)
        process.exit(1)
    }

    const originalSettings = readFileSync(SETTINGS_FILE, 'utf8')

    const launchOptions = {
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    }
    if (process.env.PUPPETEER_EXECUTABLE_PATH) {
        launchOptions.executablePath = process.env.PUPPETEER_EXECUTABLE_PATH
    }

    const browser = await puppeteer.launch(launchOptions)

    try {
        writeCodeFixture()
        const page = await browser.newPage()
        await page.setViewport({ width: 1280, height: 900 })

        for (const scheme of SCHEMES) {
            await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }])

            for (const testCase of CASES) {
                configure(testCase.theme, testCase.settings)

                let counted = 0

                for (const url of URLS) {
                    const label = `${testCase.theme}`
                        + (Object.keys(testCase.settings).length ? ` (${JSON.stringify(testCase.settings)})` : '')
                        + ` in ${scheme} at ${url}`

                    await page.goto(`${BASE_URL}${url}`, { waitUntil: 'networkidle2' })
                    counted += await assertContrast(page, label)
                }

                const label = `${testCase.theme}`
                    + (Object.keys(testCase.settings).length ? ` (${JSON.stringify(testCase.settings)})` : '')
                console.log(`ok: contrast (${label} in ${scheme}, ${counted} runs of text)`)
            }
        }
    } finally {
        await browser.close()
        writeFileSync(SETTINGS_FILE, originalSettings)
        removeCodeFixture()
        clearNavigationCache()
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
