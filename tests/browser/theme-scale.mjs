/**
 * Every theme, against a post folder that kept growing.
 *
 * A club or a journal does not stay at three posts. Two faults only appear once
 * a folder is large, and both were present in five themes at two hundred:
 *
 *  - The folder page rendered every post. `/news/p/2` answered 200 and showed
 *    exactly the same list, because only the *homepage* blog mode paginated.
 *  - Every article became a link in the site menu, so the mobile drawer held
 *    over two hundred focus stops before the first line of content.
 *
 * Neither shows in a screenshot of a three-post fixture, so the folder is built
 * here at scale and each theme is held to the same contract.
 *
 * Run inside the Typemill Docker container:
 *   npm run test:browser
 */
import puppeteer from 'puppeteer'
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'

const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')
const CONTENT_DIR = join(TM_ROOT, 'content')
const MEDIA_DIR = join(TM_ROOT, 'media', 'live')

const DIR = '95-scale-news'
const URL_PATH = '/scale-news'
const IMAGE_NAME = 'theme-scale-fixture.png'
const IMAGE_PATH = `media/live/${IMAGE_NAME}`
const POSTS = 200
const PAGE_SIZE = 12

// Rueckenwind fixes its page size in the template; the rest take the setting.
const THEMES = [
    { name: 'atelier', pageSize: PAGE_SIZE },
    { name: 'court', pageSize: PAGE_SIZE },
    { name: 'legible', pageSize: PAGE_SIZE },
    { name: 'lucid', pageSize: PAGE_SIZE },
    { name: 'medium', pageSize: PAGE_SIZE },
    { name: 'prism', pageSize: PAGE_SIZE },
    { name: 'rueckenwind', pageSize: 10 },
]

// What a pager may cost at two hundred posts: both ends, a window around the
// current page, and an ellipsis on either side.
const MAX_CONTROLS = 12

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

/** A 1x1 PNG, so Atelier's wall - which only shows pictures - has entries. */
function writeFixtureImage() {
    mkdirSync(MEDIA_DIR, { recursive: true })
    writeFileSync(
        join(MEDIA_DIR, IMAGE_NAME),
        Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'base64'
        )
    )
}

function writeFolder() {
    const folder = join(CONTENT_DIR, DIR)
    mkdirSync(folder, { recursive: true })
    writeFileSync(join(folder, 'index.md'), '# Scale news\n\nA folder that kept growing.\n')
    writeFileSync(
        join(folder, 'index.yaml'),
        "meta:\n    navtitle: 'Scale news'\n    title: 'Scale news'\n    description: 'A folder that kept growing.'\n    contains: posts\n"
    )

    for (let i = 1; i <= POSTS; i++) {
        const number = String(i).padStart(3, '0')
        const name = `20260101-scale-post-${number}`
        writeFileSync(join(folder, `${name}.md`), `# Scale post ${number}\n\nBody ${number}.\n`)
        writeFileSync(
            join(folder, `${name}.yaml`),
            `meta:\n    title: 'Scale post ${number}'\n    description: 'Update ${number}.'\n`
                + `    heroimage: ${IMAGE_PATH}\n    manualdate: '2026-01-01'\n`
        )
    }

    clearNavigationCache()
}

function removeFixtures() {
    rmSync(join(CONTENT_DIR, DIR), { recursive: true, force: true })
    rmSync(join(MEDIA_DIR, IMAGE_NAME), { force: true })
    clearNavigationCache()
}

/** Point the site at one theme, with a known page size where it is honoured. */
function configure(theme) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    content = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, `theme: ${theme}`)
        : `theme: ${theme}\n${content}`

    const block = [`    ${theme}:`, '        blog: false', `        blogpagesize: ${PAGE_SIZE}`].join('\n')
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

async function read(page, path) {
    const response = await page.goto(`${BASE_URL}${path}`, { waitUntil: 'domcontentloaded' })
    const status = response ? response.status() : 0

    const measured = await page.evaluate((urlPath) => {
        const main = document.querySelector('main') || document.body
        const chrome = Array.from(document.querySelectorAll('header, [data-nav], nav[aria-label]')).filter(
            (element) => !main.contains(element)
        )

        // Pager links live under the folder too - `/scale-news/p/2`, and the
        // previous/next pager of a folder page points at its first child - so
        // only links outside a <nav> count as posts on the page.
        const posts = new Set(
            Array.from(main.querySelectorAll(`a[href*="${urlPath}/"]`))
                .filter((a) => !a.closest('nav'))
                .map((a) => a.getAttribute('href'))
        )
        const menuPostLinks = chrome.reduce(
            (total, element) =>
                total
                + Array.from(element.querySelectorAll(`a[href*="${urlPath}/"]`)).filter(
                    (a) => !/\/p\/\d+$/.test(a.getAttribute('href') || '')
                ).length,
            0
        )
        const controls = main.querySelectorAll('a[href*="/p/"], [aria-current="page"]').length

        return { posts: [...posts], menuPostLinks, controls }
    }, URL_PATH)

    return { status, ...measured }
}

async function assertThemeScales(page, theme, pageSize) {
    configure(theme)
    await page.setViewport({ width: 1280, height: 900 })

    const first = await read(page, URL_PATH)
    assert(first.status === 200, `${theme}: ${URL_PATH} answered with HTTP ${first.status}`)
    assert(
        first.posts.length === pageSize,
        `${theme}: the folder page rendered ${first.posts.length} posts of ${POSTS}, expected ${pageSize}`
    )
    assert(
        first.menuPostLinks === 0,
        `${theme}: the menu holds ${first.menuPostLinks} links to single posts`
    )
    assert(
        first.controls <= MAX_CONTROLS,
        `${theme}: the pager rendered ${first.controls} controls for ${POSTS} posts`
    )

    const second = await read(page, `${URL_PATH}/p/2`)
    assert(second.status === 200, `${theme}: ${URL_PATH}/p/2 answered with HTTP ${second.status}`)
    assert(
        second.posts.length === pageSize,
        `${theme}: page two rendered ${second.posts.length} posts, expected ${pageSize}`
    )
    assert(
        !second.posts.some((href) => first.posts.includes(href)),
        `${theme}: page two repeats the posts of page one, so the pager moves nothing`
    )
    assert(
        second.controls <= MAX_CONTROLS,
        `${theme}: page two rendered ${second.controls} pager controls`
    )

    // A page beyond the end must not fall back to the whole folder.
    const beyond = await read(page, `${URL_PATH}/p/999`)
    assert(beyond.status === 200, `${theme}: ${URL_PATH}/p/999 answered with HTTP ${beyond.status}`)
    assert(
        beyond.posts.length <= pageSize,
        `${theme}: an out-of-range page rendered ${beyond.posts.length} posts`
    )

    return first.controls
}

async function assertDrawerStaysShort(page, theme) {
    configure(theme)
    await page.setViewport({ width: 390, height: 800 })
    await page.goto(`${BASE_URL}${URL_PATH}`, { waitUntil: 'domcontentloaded' })

    const stops = await page.evaluate(() => {
        const toggle = document.querySelector('[data-nav-toggle], [data-nav] button, header button')
        if (toggle) toggle.click()
        const nav = document.querySelector('[data-nav]') || document.querySelector('header')
        return nav ? nav.querySelectorAll('a[href], button:not([disabled])').length : 0
    })

    // The site's own pages, the brand, the toggle and a little slack - never a
    // stop per article.
    assert(stops < 40, `${theme}: the mobile menu holds ${stops} focus stops with ${POSTS} posts`)
    return stops
}

async function main() {
    if (!existsSync(SETTINGS_FILE)) {
        console.error(`settings.yaml not found at ${SETTINGS_FILE}; run npm run test:setup first`)
        process.exit(1)
    }

    const fixturePath = join(CONTENT_DIR, DIR)
    assert(!existsSync(fixturePath), `theme scale: fixture path already exists: ${fixturePath}`)

    const originalSettings = readFileSync(SETTINGS_FILE, 'utf8')
    const launchOptions = {
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    }
    if (process.env.PUPPETEER_EXECUTABLE_PATH) {
        launchOptions.executablePath = process.env.PUPPETEER_EXECUTABLE_PATH
    }

    let browser = null

    try {
        writeFixtureImage()
        writeFolder()
        browser = await puppeteer.launch(launchOptions)
        const page = await browser.newPage()

        for (const { name, pageSize } of THEMES) {
            const controls = await assertThemeScales(page, name, pageSize)
            const stops = await assertDrawerStaysShort(page, name)
            console.log(`ok: theme scale (${name}, ${pageSize} of ${POSTS} posts, ${controls} controls, ${stops} menu stops)`)
        }
    } finally {
        try {
            if (browser) await browser.close()
        } finally {
            writeFileSync(SETTINGS_FILE, originalSettings)
            removeFixtures()
        }
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
