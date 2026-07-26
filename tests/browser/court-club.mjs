/**
 * Court under the shape it was built for: a club news folder that keeps growing.
 *
 * Two hundred posts are deliberate. They prove that articles do not become two
 * hundred menu links or two hundred cards, that folder pagination really slices
 * the live navigation, and that its controls stay bounded. The mobile drawer is
 * also measured and driven with the keyboard, because a backdrop-filter once
 * made its fixed panel only as tall as the header.
 */
import puppeteer from 'puppeteer'
import {
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'
const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')
const CONTENT_DIR = join(TM_ROOT, 'content')
const NEWS_DIR = '98-court-news'
const NEWS_URL = '/court-news'
const POST_COUNT = 200
const PAGE_SIZE = 12

function assert(condition, message) {
    if (!condition) {
        throw new Error(message)
    }
}

function contrastRatio(foreground, background) {
    const parse = (color) => (color.match(/[\d.]+/g) || []).slice(0, 3).map(Number)
    const luminance = (color) => {
        const channels = parse(color).map((value) => {
            const channel = value / 255
            return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
        })
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
    }
    const first = luminance(foreground)
    const second = luminance(background)
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05)
}

function clearNavigationCache() {
    if (!existsSync(NAV_CACHE)) {
        return
    }
    for (const entry of readdirSync(NAV_CACHE)) {
        rmSync(join(NAV_CACHE, entry), { force: true, recursive: true })
    }
}

function configure() {
    let content = readFileSync(SETTINGS_FILE, 'utf8')
    content = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, 'theme: court')
        : `theme: court\n${content}`

    const block = [
        '    court:',
        '        blog: false',
        `        blogpagesize: ${PAGE_SIZE}`,
        '        breadcrumb: true',
    ].join('\n')
    const existing = new RegExp(
        '^ {4}court:(?:\\n(?: {5,}.*)?)*\\n|^ {4}court:[^\\n]*\\n',
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

function writeNewsFolder(count = POST_COUNT) {
    const folder = join(CONTENT_DIR, NEWS_DIR)
    mkdirSync(folder, { recursive: true })
    writeFileSync(join(folder, 'index.md'), '# Court News\n\nNews from the club.\n')
    writeFileSync(
        join(folder, 'index.yaml'),
        "meta:\n    navtitle: 'Court News'\n    title: 'Court News'\n    description: 'News from the club.'\n    contains: posts\n"
    )

    for (let i = 1; i <= count; i++) {
        const number = String(i).padStart(3, '0')
        const name = `20260101-court-post-${number}`
        writeFileSync(join(folder, `${name}.md`), `# Court post ${number}\n\nPost ${number} body.\n`)
        writeFileSync(
            join(folder, `${name}.yaml`),
            `meta:\n    title: 'Court post ${number}'\n    description: 'Club update ${number}.'\n    manualdate: '2026-01-01'\n`
        )
    }

    clearNavigationCache()
}

function replaceNewsFolder(count) {
    removeNewsFolder()
    writeNewsFolder(count)
}

function removeNewsFolder() {
    rmSync(join(CONTENT_DIR, NEWS_DIR), { recursive: true, force: true })
    clearNavigationCache()
}

async function open(page, path) {
    const response = await page.goto(`${BASE_URL}${path}`, { waitUntil: 'domcontentloaded' })
    const status = response ? response.status() : 0
    assert(status === 200, `court: ${path} answered with HTTP ${status}`)
}

async function postTitles(page) {
    return page.evaluate(() =>
        Array.from(document.querySelectorAll('.ct-post__title')).map((node) =>
            (node.textContent || '').trim()
        )
    )
}

async function assertFolderPagination(page) {
    await open(page, NEWS_URL)

    const first = await postTitles(page)
    assert(first.length === PAGE_SIZE, `court: page one rendered ${first.length} posts, expected ${PAGE_SIZE}`)

    const firstState = await page.evaluate(() => ({
        next: document.querySelector('.ct-pagination__edge--next')?.getAttribute('href') || null,
        controls: document.querySelectorAll('.ct-pagination a, .ct-pagination [aria-current="page"], .ct-pagination__gap').length,
        canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '',
        headingsAreH2: Array.from(document.querySelectorAll('.ct-post__title')).every(
            (heading) => heading.tagName === 'H2'
        ),
    }))
    assert(firstState.next === `${NEWS_URL}/p/2`, `court: first page points next to "${firstState.next}"`)
    assert(firstState.controls <= 8, `court: first page rendered ${firstState.controls} pagination controls`)
    assert(!firstState.canonical.endsWith('/p/2'), 'court: page one canonicalized to page two')
    assert(firstState.headingsAreH2, 'court: post cards skip from the page h1 to h3')

    await open(page, `${NEWS_URL}/p/2`)
    const second = await postTitles(page)
    assert(second.length === PAGE_SIZE, `court: page two rendered ${second.length} posts, expected ${PAGE_SIZE}`)
    assert(
        !second.some((title) => first.includes(title)),
        'court: page two repeats posts from page one'
    )

    const secondState = await page.evaluate(() => ({
        controls: document.querySelectorAll('.ct-pagination a, .ct-pagination [aria-current="page"], .ct-pagination__gap').length,
        canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '',
    }))
    assert(secondState.controls <= 9, `court: page two rendered ${secondState.controls} pagination controls`)
    assert(secondState.canonical.endsWith(`${NEWS_URL}/p/2`), `court: page two canonical is "${secondState.canonical}"`)

    const lastPage = Math.ceil(POST_COUNT / PAGE_SIZE)
    await open(page, `${NEWS_URL}/p/${lastPage}`)
    const last = await postTitles(page)
    assert(
        last.length === POST_COUNT % PAGE_SIZE,
        `court: last page rendered ${last.length} posts, expected ${POST_COUNT % PAGE_SIZE}`
    )
    const lastControls = await page.evaluate(() =>
        document.querySelectorAll('.ct-pagination a, .ct-pagination [aria-current="page"], .ct-pagination__gap').length
    )
    assert(lastControls <= 8, `court: last page rendered ${lastControls} pagination controls`)

    // An impossible page must not trigger the partial's old fallback to all 200.
    await open(page, `${NEWS_URL}/p/999`)
    const outOfRange = await postTitles(page)
    const outOfRangeState = await page.evaluate(() => ({
        canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '',
        robots: document.querySelector('meta[name="robots"]')?.getAttribute('content') || '',
    }))
    assert(
        outOfRange.length === POST_COUNT % PAGE_SIZE,
        `court: an out-of-range page rendered ${outOfRange.length} posts instead of the last bounded page`
    )
    assert(
        outOfRangeState.canonical.endsWith(`${NEWS_URL}/p/${lastPage}`),
        `court: out-of-range canonical is "${outOfRangeState.canonical}"`
    )
    assert(outOfRangeState.robots === 'noindex,follow', 'court: out-of-range page remains indexable')

    await open(page, `${NEWS_URL}/p/2.5`)
    const alias = await page.evaluate(() => ({
        posts: document.querySelectorAll('.ct-post').length,
        canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '',
        robots: document.querySelector('meta[name="robots"]')?.getAttribute('content') || '',
    }))
    assert(alias.posts === PAGE_SIZE, `court: decimal page alias rendered ${alias.posts} posts`)
    assert(alias.canonical.endsWith(NEWS_URL), `court: decimal page canonical is "${alias.canonical}"`)
    assert(alias.robots === 'noindex,follow', 'court: decimal page alias remains indexable')
}

async function assertPaginationBoundaries(page) {
    for (const count of [0, 1, PAGE_SIZE]) {
        replaceNewsFolder(count)
        await open(page, NEWS_URL)
        const state = await page.evaluate(() => ({
            posts: document.querySelectorAll('.ct-post').length,
            pagination: Boolean(document.querySelector('.ct-pagination')),
        }))
        assert(state.posts === count, `court: ${count} posts rendered as ${state.posts}`)
        assert(!state.pagination, `court: ${count} posts rendered unnecessary pagination`)
    }
}

async function assertNewsDoesNotBecomeAMenu(page) {
    await page.setViewport({ width: 1280, height: 800 })
    await open(page, NEWS_URL)

    const desktop = await page.evaluate((newsUrl) => {
        const link = document.querySelector(`.ct-nav__link[href="${newsUrl}"]`)
        return {
            exists: Boolean(link),
            expands: Boolean(link?.hasAttribute('data-nav-parent')),
            postLinks: document.querySelectorAll(`.ct-nav a[href^="${newsUrl}/"]`).length,
        }
    }, NEWS_URL)
    assert(desktop.exists, 'court: the news folder is missing from desktop navigation')
    assert(!desktop.expands, 'court: the news folder still opens a submenu of posts')
    assert(desktop.postLinks === 0, `court: desktop navigation contains ${desktop.postLinks} post links`)
}

async function assertMobileDrawer(page) {
    await page.setViewport({ width: 390, height: 800 })
    await open(page, NEWS_URL)
    await page.focus('[data-nav-toggle]')
    await page.keyboard.press('Enter')

    const openState = await page.evaluate((newsUrl) => {
        const drawer = document.querySelector('.ct-nav__links')
        const rect = drawer.getBoundingClientRect()
        return {
            expanded: document.querySelector('[data-nav-toggle]')?.getAttribute('aria-expanded'),
            position: getComputedStyle(drawer).position,
            top: Math.round(rect.top),
            bottom: Math.round(rect.bottom),
            viewport: window.innerHeight,
            bodyOverflow: getComputedStyle(document.body).overflow,
            mainInert: document.querySelector('main')?.inert,
            footerInert: document.querySelector('.ct-footer')?.inert,
            postLinks: document.querySelectorAll(`.ct-nav a[href^="${newsUrl}/"]`).length,
            activeInNav: document.querySelector('[data-nav]')?.contains(document.activeElement),
        }
    }, NEWS_URL)

    assert(openState.expanded === 'true', 'court: mobile menu does not report itself open')
    assert(openState.position === 'fixed', `court: mobile drawer position is ${openState.position}`)
    assert(openState.top === 60, `court: mobile drawer starts at ${openState.top}px, expected 60px`)
    assert(
        Math.abs(openState.bottom - openState.viewport) <= 1,
        `court: mobile drawer ends at ${openState.bottom}px in a ${openState.viewport}px viewport`
    )
    assert(openState.bodyOverflow === 'hidden', `court: body overflow is ${openState.bodyOverflow} while open`)
    assert(openState.mainInert && openState.footerInert, 'court: content behind the drawer remains interactive')
    assert(openState.postLinks === 0, `court: mobile drawer contains ${openState.postLinks} post links`)
    assert(openState.activeInNav, 'court: opening the drawer did not move focus into it')

    for (let i = 0; i < 30; i++) {
        await page.keyboard.press('Tab')
        const inNav = await page.evaluate(() =>
            document.querySelector('[data-nav]')?.contains(document.activeElement)
        )
        assert(inNav, `court: Tab ${i + 1} escaped the open drawer`)
    }

    await page.keyboard.press('Escape')
    const closed = await page.evaluate(() => ({
        expanded: document.querySelector('[data-nav-toggle]')?.getAttribute('aria-expanded'),
        focusOnToggle: document.activeElement?.hasAttribute('data-nav-toggle'),
        mainInert: document.querySelector('main')?.inert,
        bodyOverflow: getComputedStyle(document.body).overflow,
    }))
    assert(closed.expanded === 'false', 'court: Escape did not close the drawer')
    assert(closed.focusOnToggle, 'court: Escape did not return focus to the menu button')
    assert(!closed.mainInert, 'court: closing the drawer left page content inert')
    assert(closed.bodyOverflow !== 'hidden', 'court: closing the drawer left body scrolling locked')

    // A drawer must restore state it did not create; another modal may already
    // own the scroll lock or have made the page inert.
    await page.evaluate(() => {
        document.body.style.overflow = 'clip'
        document.querySelector('main').inert = true
    })
    await page.focus('[data-nav-toggle]')
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')
    const restored = await page.evaluate(() => ({
        overflow: document.body.style.overflow,
        mainInert: document.querySelector('main').inert,
    }))
    assert(restored.overflow === 'clip', `court: drawer replaced an existing "${restored.overflow}" scroll lock`)
    assert(restored.mainInert, 'court: drawer cleared inert state owned by another UI')
    await page.evaluate(() => {
        document.body.style.overflow = ''
        document.querySelector('main').inert = false
    })

    // Crossing the desktop breakpoint closes the drawer. Focus must move to a
    // visible desktop link rather than the now-hidden menu button.
    await page.focus('[data-nav-toggle]')
    await page.keyboard.press('Enter')
    await page.focus(`.ct-nav__link[href="${NEWS_URL}"]`)
    await page.setViewport({ width: 1000, height: 800 })
    await new Promise((resolve) => setTimeout(resolve, 100))
    const widened = await page.evaluate(() => {
        const active = document.activeElement
        const rect = active.getBoundingClientRect()
        return {
            expanded: document.querySelector('[data-nav-toggle]')?.getAttribute('aria-expanded'),
            onToggle: active.hasAttribute('data-nav-toggle'),
            inNav: document.querySelector('[data-nav]')?.contains(active),
            visible: rect.width > 0 && rect.height > 0,
            href: active.getAttribute('href'),
        }
    })
    assert(widened.expanded === 'false', 'court: widening the viewport left the drawer open')
    assert(widened.inNav && widened.visible && !widened.onToggle, 'court: breakpoint change hid the focused control')
    assert(widened.href === NEWS_URL, `court: breakpoint change moved focus to "${widened.href}"`)
}

async function assertNextAlwaysUsesTheRightColumn(page) {
    await page.setViewport({ width: 1000, height: 700 })
    await open(page, '/getting-started/edit-the-page-meta')

    const result = await page.evaluate(() => {
        const pager = document.querySelector('.ct-pager')
        const next = pager?.querySelector('.ct-pager__item--next')
        if (!pager || !next) return null

        Array.from(pager.children).forEach((child) => {
            if (child !== next) child.remove()
        })
        const pagerRect = pager.getBoundingClientRect()
        const nextRect = next.getBoundingClientRect()
        return {
            gridColumn: getComputedStyle(next).gridColumnStart,
            inRightHalf: nextRect.left >= pagerRect.left + pagerRect.width / 2 - 1,
        }
    })

    assert(result, 'court: no next-page card was available to test')
    assert(result.gridColumn === '2', `court: Next uses grid column ${result.gridColumn}`)
    assert(result.inRightHalf, 'court: Next alone sits in the left half of the pager')
}

async function assertFullCardTargetAndCurrentFolder(page) {
    await page.setViewport({ width: 1000, height: 700 })
    await open(page, NEWS_URL)
    const card = await page.$('.ct-post')
    assert(card, 'court: no post card exists for card-target testing')
    const box = await card.boundingBox()
    assert(box, 'court: post card has no box')

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.mouse.click(box.x + box.width - 20, box.y + box.height - 20),
    ])
    assert(
        new URL(page.url()).pathname.startsWith(`${NEWS_URL}/court-post-`),
        `court: card whitespace navigated to ${page.url()}`
    )

    const current = await page.evaluate(
        (newsUrl) =>
            document.querySelector(`.ct-nav__link[href="${newsUrl}"]`)?.getAttribute('aria-current') || null,
        NEWS_URL
    )
    assert(current === 'location', `court: a post marks its news folder as aria-current="${current}"`)
}

async function assertDarkHoverContrast(page) {
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }])
    await page.setViewport({ width: 900, height: 700 })
    await open(page, NEWS_URL)

    const card = await page.$('.ct-post')
    assert(card, 'court: no post card exists for hover testing')
    await card.hover()

    const colors = await page.evaluate((element) => {
        const title = element.querySelector('.ct-post__title a')
        return {
            title: getComputedStyle(title).color,
            background: getComputedStyle(element).backgroundColor,
        }
    }, card)
    const ratio = contrastRatio(colors.title, colors.background)
    assert(
        ratio >= 4.5,
        `court: dark hover contrast is ${ratio.toFixed(2)}:1 (${colors.title} on ${colors.background})`
    )
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

    const fixturePath = join(CONTENT_DIR, NEWS_DIR)
    assert(!existsSync(fixturePath), `court: fixture path already exists: ${fixturePath}`)
    let fixtureOwned = false
    let browser = null

    try {
        fixtureOwned = true
        writeNewsFolder()
        configure()
        browser = await puppeteer.launch(launchOptions)
        const page = await browser.newPage()
        await assertFolderPagination(page)
        console.log('ok: court club (200 posts stay paginated)')

        await assertNewsDoesNotBecomeAMenu(page)
        console.log('ok: court club (news posts stay out of the menu)')

        await assertMobileDrawer(page)
        console.log('ok: court club (mobile drawer fills the viewport and traps focus)')

        await assertNextAlwaysUsesTheRightColumn(page)
        console.log('ok: court club (Next alone stays on the right)')

        await assertFullCardTargetAndCurrentFolder(page)
        console.log('ok: court club (post cards are one target and keep News current)')

        await assertDarkHoverContrast(page)
        console.log('ok: court club (dark hover remains legible)')

        await assertPaginationBoundaries(page)
        console.log('ok: court club (0, 1 and 12 posts need no pager)')
    } finally {
        try {
            if (browser) await browser.close()
        } finally {
            writeFileSync(SETTINGS_FILE, originalSettings)
            if (fixtureOwned) removeNewsFolder()
        }
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
