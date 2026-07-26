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
import { readFileSync, writeFileSync, existsSync, readdirSync, rmSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'

const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')
const CONTENT_DIR = join(TM_ROOT, 'content')

// A folder of posts that carry no picture at all.
const TEXT_ONLY_DIR = '97-text-only-posts'
const TEXT_ONLY_URL = '/text-only-posts'

// A folder that already holds several posts, so one post per page really pages.
const BLOG_FOLDER = process.env.TM_BLOG_FOLDER || '/news'

const THEMES = ['atelier', 'court', 'legible', 'lucid', 'medium', 'prism']

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
function configure(theme, pageSize, folder = BLOG_FOLDER) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    content = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, `theme: ${theme}`)
        : `theme: ${theme}\n${content}`

    const block = [
        `    ${theme}:`,
        '        blog: true',
        `        blogfolder: '${folder}'`,
        `        blogpagesize: ${pageSize}`,
    ].join('\n')

    // Replace this theme's own block, or add one under themes:. A continuation
    // line is anything indented deeper than the theme name, or empty.
    const existing = new RegExp(
        `^ {4}${theme}:(?:\\n(?: {5,}.*)?)*\\n|^ {4}${theme}:[^\\n]*\\n`,
        'm'
    )

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

function writeTextOnlyFolder() {
    const folder = join(CONTENT_DIR, TEXT_ONLY_DIR)
    mkdirSync(folder, { recursive: true })
    writeFileSync(join(folder, 'index.md'), '# Text only\n\nPosts without a single picture.\n')
    writeFileSync(join(folder, 'index.yaml'), "meta:\n    title: 'Text only'\n    contains: posts\n")

    for (const [i, name] of ['20240101-first', '20240102-second'].entries()) {
        writeFileSync(join(folder, `${name}.md`), `# Post ${i + 1}\n\nText only, no picture.\n`)
        writeFileSync(join(folder, `${name}.yaml`), `meta:\n    title: 'Post ${i + 1}'\n`)
    }

    clearNavigationCache()
}

function removeTextOnlyFolder() {
    rmSync(join(CONTENT_DIR, TEXT_ONLY_DIR), { recursive: true, force: true })
    clearNavigationCache()
}

/**
 * Atelier's wall only shows entries that carry a picture, and it counts its
 * pages from those, so a folder of text-only posts leaves the page empty. An
 * empty page must stay a page: the gallery partial falls back to the current
 * page's own children when it is given no entries, and an empty list must not
 * be mistaken for none - that fallback answered 500 on the homepage.
 */
async function assertTextOnlyJournal(page) {
    configure('atelier', 12, TEXT_ONLY_URL)

    const status = await fetchStatus(page, `${BASE_URL}/`)
    assert(status === 200, `atelier: a journal of text-only posts answered with HTTP ${status}`)

    const tiles = await page.evaluate(() => document.querySelectorAll('.at-tile').length)
    assert(
        tiles === 0,
        `atelier: a journal of text-only posts showed ${tiles} tiles, which cannot be its own posts`
    )
}

/**
 * A blog folder that is not in the navigation - mistyped, or hidden - leaves the
 * page list with no content at all. That is a settings mistake, and it has to
 * read as one rather than as a server error.
 */
async function assertMissingFolder(page, theme) {
    configure(theme, 10, '/no-such-folder-at-all')

    const status = await fetchStatus(page, `${BASE_URL}/`)
    assert(status === 200, `${theme}: a blog folder that does not exist answered with HTTP ${status}`)
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

    if (theme === 'court') {
        const postsOnPageTwo = await page.evaluate(() => document.querySelectorAll('.ct-post').length)
        assert(postsOnPageTwo === 1, `court: page two rendered ${postsOnPageTwo} posts, expected 1`)

        const canonical = await page.evaluate(() =>
            document.querySelector('link[rel="canonical"]')?.getAttribute('href') || ''
        )
        const canonicalPath = new URL(canonical, BASE_URL).pathname
        const basePath = new URL(BASE_URL).pathname.replace(/\/$/, '')
        assert(
            canonicalPath === `${basePath}/p/2`,
            `court: page-two canonical path is "${canonicalPath}", not "${basePath}/p/2"`
        )

        const pageNumbers = await page.evaluate(() =>
            Array.from(document.querySelectorAll('.ct-pagination__page'))
                .map((element) => Number((element.textContent || '').trim()))
                .filter(Number.isFinite)
        )
        const lastPage = Math.max(...pageNumbers)
        await fetchStatus(page, `${BASE_URL}/p/999`)
        const invalid = await page.evaluate(() => ({
            canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '',
            robots: document.querySelector('meta[name="robots"]')?.getAttribute('content') || '',
        }))
        assert(
            new URL(invalid.canonical, BASE_URL).pathname === `${basePath}/p/${lastPage}`,
            `court: out-of-range homepage canonical is "${invalid.canonical}"`
        )
        assert(invalid.robots === 'noindex,follow', 'court: out-of-range homepage remains indexable')
    }

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

        for (const theme of THEMES) {
            await assertMissingFolder(page, theme)
        }
        console.log('ok: blog homepage (a folder that does not exist, all themes)')

        writeTextOnlyFolder()
        await assertTextOnlyJournal(page)
        console.log('ok: blog homepage (atelier, a journal of text-only posts)')
    } finally {
        await browser.close()
        writeFileSync(SETTINGS_FILE, originalSettings)
        removeTextOnlyFolder()
        clearNavigationCache()
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
