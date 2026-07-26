/**
 * The blog homepage of every theme that offers one.
 *
 * Two faults live here and nowhere else, because both need the post list to be
 * the *homepage* rather than a folder page:
 *
 *  - The homepage's own URL is `/`, so a pager built as `item.urlRel ~ '/p/2'`
 *    produces `//p/2`. A browser reads that as a scheme-relative URL - `p` is
 *    the host - and page two of the blog leaves the site altogether.
 *  - Twig substitutes a default only for an *empty* value, and zero is not
 *    empty, so a page size of zero survives `|default(10)` and reaches the
 *    division that derives the page count.
 *
 * Neither is visible to the API or PHPUnit suites, which never render a theme,
 * nor to the prose suite, which renders an ordinary page. So the pager is
 * followed here for real: page two has to answer 200 and come from this site.
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

// A folder that already holds several posts, so one post per page really pages.
const BLOG_FOLDER = process.env.TM_BLOG_FOLDER || '/news'

const THEMES = ['atelier', 'legible', 'lucid', 'medium', 'prism']

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
 * Point the site at one theme's blog homepage.
 *
 * The settings file is edited as text rather than parsed: a YAML round trip
 * would rewrite the whole file, and this has to put it back byte for byte.
 */
function configure(theme, pageSize) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    content = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, `theme: ${theme}`)
        : `theme: ${theme}\n${content}`

    const block = [
        `    ${theme}:`,
        '        blog: true',
        `        blogfolder: '${BLOG_FOLDER}'`,
        `        blogpagesize: ${pageSize}`,
    ].join('\n')

    // Replace this theme's own block, or add one under themes:. A continuation
    // line is anything indented deeper than the theme name, or empty.
    const existing = new RegExp(`^ {4}${theme}:\\n(?:(?: {5,}.*)?\\n)*`, 'm')

    if (existing.test(content)) {
        content = content.replace(existing, `${block}\n`)
    } else if (/^themes:$/m.test(content)) {
        content = content.replace(/^themes:$/m, `themes:\n${block}`)
    } else {
        content = `${content.replace(/\n*$/, '')}\nthemes:\n${block}\n`
    }

    writeFileSync(SETTINGS_FILE, content)
    clearNavigationCache()
}

async function fetchStatus(page, url) {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded' })
    return response ? response.status() : 0
}

async function assertBlogHomepage(page, theme) {
    configure(theme, 1)

    const status = await fetchStatus(page, `${BASE_URL}/`)
    assert(status === 200, `${theme}: the blog homepage answered with HTTP ${status}`)

    const pager = await page.evaluate(() => {
        const link = Array.from(document.querySelectorAll('a[href*="/p/"]')).find((a) =>
            /\/p\/\d+$/.test(a.getAttribute('href') || '')
        )
        return link ? { href: link.getAttribute('href'), resolved: link.href } : null
    })

    assert(pager, `${theme}: the blog homepage rendered no pagination, so nothing was proven`)
    assert(
        !pager.href.startsWith('//'),
        `${theme}: pagination points off the site (href "${pager.href}" resolves to ${pager.resolved})`
    )

    const origin = new URL(BASE_URL).origin
    assert(
        pager.resolved.startsWith(origin),
        `${theme}: pagination leaves the site (href "${pager.href}" resolves to ${pager.resolved})`
    )

    const second = await fetchStatus(page, pager.resolved)
    assert(second === 200, `${theme}: ${pager.resolved} answered with HTTP ${second}`)

    // Zero survives Twig's default filter and used to divide by zero.
    configure(theme, 0)
    const zero = await fetchStatus(page, `${BASE_URL}/`)
    assert(zero === 200, `${theme}: a page size of zero answered with HTTP ${zero}`)

    return pager.href
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
            const href = await assertBlogHomepage(page, theme)
            console.log(`ok: blog homepage (${theme}, pager ${href})`)
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
