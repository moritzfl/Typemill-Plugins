/**
 * Theme labels have to follow the site language.
 *
 * Every theme renders its fixed words - Home, Previous, Next, Breadcrumb - as
 * `theme.someLabel|default(translate('Home'))`, which reads: the admin's own
 * wording if they set one, the theme's translation otherwise. That fallback is
 * only reachable while the setting is empty, so an English default shipped in
 * the theme's `settings:` block quietly disables the whole mechanism and a
 * German site keeps saying "Home".
 *
 * The defaults are empty now, and this checks the result where it shows: on a
 * rendered page in German.
 *
 * Run inside the Typemill Docker container:
 *   npm run test:browser
 */
import puppeteer from 'puppeteer'
import { readFileSync, writeFileSync, existsSync, readdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'

const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')

// A page below a folder, so it has a breadcrumb and neighbours to page to.
const PAGE_URL = process.env.TM_SUBPAGE || '/getting-started/edit-the-page-meta'

const THEMES = ['atelier', 'legible', 'lucid', 'medium', 'prism']

// What the theme's own de.yaml says. If a theme ever ships different German,
// this is the list to change.
const GERMAN = {
    home: 'Start',
    breadcrumb: 'Brotkrümelnavigation',
    previous: 'Zurück',
    next: 'Weiter',
}

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

/**
 * Switch to one theme in German, with that theme's own settings cleared.
 *
 * The block is emptied on purpose: a stored label would be the admin's choice
 * and would rightly win over the translation, which is precisely what must not
 * be assumed here.
 */
function configure(theme) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    content = /^language:.*$/m.test(content)
        ? content.replace(/^language:.*$/m, 'language: de')
        : `language: de\n${content}`

    content = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, `theme: ${theme}`)
        : `theme: ${theme}\n${content}`

    const block = `    ${theme}:\n        breadcrumb: true\n`
    const existing = new RegExp(`^ {4}${theme}:\\n(?:(?: {5,}.*)?\\n)*`, 'm')

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

function readLabels() {
    const breadcrumb = document.querySelector('nav[class*="breadcrumb"]')
    const pager = document.querySelector('[class*="pager"]')

    return {
        breadcrumbLabel: breadcrumb ? breadcrumb.getAttribute('aria-label') : null,
        breadcrumbHome: breadcrumb ? (breadcrumb.querySelector('a')?.textContent || '').trim() : null,
        pagerText: pager ? (pager.textContent || '').replace(/\s+/g, ' ').trim() : '',
    }
}

async function assertGermanLabels(page, theme) {
    configure(theme)

    const response = await page.goto(`${BASE_URL}${PAGE_URL}`, { waitUntil: 'domcontentloaded' })
    const status = response ? response.status() : 0
    assert(status === 200, `${theme}: ${PAGE_URL} answered with HTTP ${status}`)

    const labels = await page.evaluate(readLabels)

    assert(labels.breadcrumbHome !== null, `${theme}: no breadcrumb was rendered, so nothing was proven`)
    assert(
        labels.breadcrumbHome === GERMAN.home,
        `${theme}: the breadcrumb says "${labels.breadcrumbHome}" on a German site, not "${GERMAN.home}"`
    )
    assert(
        labels.breadcrumbLabel === GERMAN.breadcrumb,
        `${theme}: the breadcrumb is announced as "${labels.breadcrumbLabel}", not "${GERMAN.breadcrumb}"`
    )

    // The pager only appears when the page has neighbours.
    if (labels.pagerText) {
        assert(
            !/\b(Previous|Next)\b/.test(labels.pagerText),
            `${theme}: the pager still reads English on a German site ("${labels.pagerText}")`
        )
        assert(
            labels.pagerText.includes(GERMAN.previous) || labels.pagerText.includes(GERMAN.next),
            `${theme}: the pager says "${labels.pagerText}", which carries neither "${GERMAN.previous}" nor "${GERMAN.next}"`
        )
    }

    return labels.breadcrumbHome
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
        const page = await browser.newPage()

        for (const theme of THEMES) {
            const home = await assertGermanLabels(page, theme)
            console.log(`ok: theme language (${theme}, breadcrumb reads "${home}")`)
        }
    } finally {
        await browser.close()
        writeFileSync(SETTINGS_FILE, originalSettings)
        clearNavigationCache()
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
