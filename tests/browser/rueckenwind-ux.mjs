/** Rueckenwind's drawer controls, dropdown Escape, long hero and print layout.
 * Run serially against the Docker fixture; settings and content are restored.
 * Desktop dropdown checks run when Rueckenwind's top navigation is configured.
 */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'
const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')
const FIXTURE_PATH = join(TM_ROOT, 'content', '98-rueckenwind-ux-fixture')
const FIXTURE_URL = '/rueckenwind-ux-fixture'
const TITLE = 'A long handbook title that must remain readable above its complete description. '.repeat(3).trim()
const DESCRIPTION = 'Long hero descriptions must grow the image panel instead of disappearing above its clipped edge. '.repeat(8).trim()
const END_MARKER = 'Rueckenwind print end marker.'

function clearNavigationCache() {
    if (!existsSync(NAV_CACHE)) return
    for (const entry of readdirSync(NAV_CACHE)) {
        rmSync(join(NAV_CACHE, entry), { recursive: true, force: true })
    }
}

async function assertDrawer(page) {
    const size = await page.$eval('#mobile-menu-btn', (button) => {
        const rect = button.getBoundingClientRect()
        return { width: rect.width, height: rect.height }
    })
    assert(size.width >= 44 && size.height >= 44, `menu target is ${size.width}x${size.height}`)

    for (const method of ['pointer', 'keyboard', 'Escape']) {
        await page.focus('#mobile-menu-btn')
        await page.keyboard.press('Enter')
        const opened = await page.evaluate(() => {
            const sidebar = document.querySelector('#sidebar')
            const close = sidebar.querySelector('[data-sidebar-close]')
            const firstLink = Array.from(sidebar.querySelectorAll('a[href]'))
                .find((link) => link.getClientRects().length)
            const rect = close?.getBoundingClientRect()
            return {
                firstLinkFocused: Boolean(firstLink && document.activeElement === firstLink),
                closeUsable: Boolean(rect && rect.width >= 44 && rect.height >= 44
                    && close.getAttribute('aria-label') && !close.closest('[inert]')),
                isolated: document.querySelector('#topbar').inert && document.querySelector('.content-scroll').inert,
                toggleHidden: getComputedStyle(document.querySelector('#mobile-menu-btn')).visibility === 'hidden',
            }
        })
        assert(opened.firstLinkFocused, 'opening the drawer must still focus its first link')
        assert(opened.closeUsable, 'drawer needs its own named, usable 44px Close control')
        assert(opened.isolated && opened.toggleHidden, 'background must be inert without a visually live toggle')

        await page.focus('[data-sidebar-close]')
        await page.keyboard.down('Shift')
        await page.keyboard.press('Tab')
        await page.keyboard.up('Shift')
        assert(await page.$eval('#sidebar', (sidebar) => sidebar.contains(document.activeElement)), 'reverse Tab escaped the drawer')
        await page.keyboard.press('Tab')
        assert(await page.$eval('[data-sidebar-close]', (close) => document.activeElement === close), 'forward Tab missed Close at the trap boundary')

        if (method === 'pointer') await page.click('[data-sidebar-close]')
        else await page.keyboard.press(method === 'keyboard' ? 'Enter' : 'Escape')

        const closed = await page.evaluate(() => ({
            expanded: document.querySelector('#mobile-menu-btn').getAttribute('aria-expanded'),
            focused: document.activeElement === document.querySelector('#mobile-menu-btn'),
            isolated: document.querySelector('#topbar').inert || document.querySelector('.content-scroll').inert,
            visible: getComputedStyle(document.querySelector('#mobile-menu-btn')).visibility !== 'hidden',
        }))
        assert.equal(closed.expanded, 'false', `${method} did not close the drawer`)
        assert(closed.focused && closed.visible && !closed.isolated, `${method} did not restore focus and background access`)
    }

    await page.keyboard.press('Enter')
    await page.focus('[data-sidebar-close]')
    await page.setViewport({ width: 1280, height: 900 })
    await page.waitForFunction(() => document.querySelector('#mobile-menu-btn').getAttribute('aria-expanded') === 'false')
    assert(await page.evaluate(() => document.activeElement !== document.body
        && document.activeElement.getClientRects().length > 0
        && !document.activeElement.closest('[inert]')), 'widening left focus in the hidden drawer controls')
    console.log('ok: rueckenwind drawer (Close, Escape, focus trap and breakpoint)')
}

async function assertDesktopDropdown(page) {
    if (!await page.$('#topbar nav')) {
        console.log('skip: rueckenwind desktop dropdown (navposition is not top)')
        return
    }
    const selector = `[data-topnav-parent][href$="${FIXTURE_URL}"]`
    const parent = await page.$(selector)
    assert(parent, 'top navigation did not render the fixture folder dropdown')
    await parent.hover()
    await parent.focus()
    await page.keyboard.press('ArrowDown')
    assert(await parent.evaluate((link) => link.getAttribute('aria-expanded') === 'true'
        && document.getElementById(link.getAttribute('aria-controls')).contains(document.activeElement)), 'ArrowDown did not enter the dropdown')

    // Both hover and focus remain inside the item when Escape closes it.
    await page.keyboard.press('Escape')
    await page.waitForFunction((selector) => {
        const link = document.querySelector(selector)
        return link.getAttribute('aria-expanded') === 'false'
            && getComputedStyle(document.getElementById(link.getAttribute('aria-controls'))).visibility === 'hidden'
            && document.activeElement === link
    }, {}, selector)
    await page.keyboard.press('Tab')
    assert(await parent.evaluate((link) => !document.getElementById(link.getAttribute('aria-controls')).contains(document.activeElement)), 'Tab entered the escaped dropdown')

    await parent.focus()
    await page.keyboard.press('ArrowDown')
    await page.setViewport({ width: 390, height: 800 })
    await page.waitForFunction(() => document.activeElement === document.querySelector('#mobile-menu-btn'))
    assert(await parent.evaluate((link) => link.getAttribute('aria-expanded') === 'false'), 'mobile breakpoint retained the desktop dropdown')
    console.log('ok: rueckenwind desktop dropdown (Escape defeats hover/focus, responsive focus)')
}

async function assertHeroAndTables(page) {
    for (const width of [390, 1280]) {
        await page.setViewport({ width, height: 900 })
        const result = await page.evaluate(() => {
            const image = document.querySelector('main section img')
            const panel = image?.parentElement
            if (!panel) return null
            const box = panel.getBoundingClientRect()
            const copy = Array.from(panel.querySelectorAll('h1, p'))
            const table = document.querySelector('main .prose table')
            const scroll = document.querySelector('.content-scroll')
            if (table) table.scrollLeft = table.scrollWidth
            return {
                title: panel.querySelector('h1')?.textContent.trim(),
                description: panel.querySelector('p')?.textContent.trim(),
                imageLoaded: image.complete && image.naturalWidth > 0,
                height: box.height,
                copyContained: copy.every((element) => {
                    const rect = element.getBoundingClientRect()
                    return rect.top >= box.top && rect.bottom <= box.bottom
                        && rect.left >= box.left && rect.right <= box.right
                }),
                overflow: scroll.scrollWidth - scroll.clientWidth,
                tableScrollable: Boolean(table && getComputedStyle(table).overflowX === 'auto'),
                tableOverflows: Boolean(table && table.scrollWidth > table.clientWidth),
                tableScrolled: Boolean(table && table.scrollLeft > 0),
                altsPresent: Array.from(document.images).every((img) => img.hasAttribute('alt')),
                homeNamed: Boolean(document.querySelector('#breadcrumb a')?.getAttribute('aria-label')),
            }
        })
        assert(result, 'fixture did not render the image hero')
        assert.equal(result.title, TITLE)
        assert.equal(result.description, DESCRIPTION)
        assert(result.imageLoaded && result.copyContained && result.height > 256, `hero clips long copy at ${width}px`)
        assert(result.overflow <= 1 && result.tableScrollable, `table overflows the content scroller at ${width}px`)
        if (width === 390) assert(result.tableOverflows && result.tableScrolled, 'fixture did not exercise horizontal table scrolling')
        assert(result.altsPresent && result.homeNamed, 'image alt or breadcrumb home name missing')
    }
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }])
    assert(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior === 'auto'
        && parseFloat(getComputedStyle(document.querySelector('[data-sidebar-close]')).transitionDuration) < 0.001), 'reduced motion must disable smooth scrolling and long transitions')
    console.log('ok: rueckenwind long hero, table containment, names and reduced motion')
}

async function assertPrint(page) {
    await page.emulateMediaType('print')
    const result = await page.evaluate((marker) => {
        const end = Array.from(document.querySelectorAll('main .prose p')).find((p) => p.textContent.trim() === marker)
        if (!end) return null
        const bottom = end.getBoundingClientRect().bottom
        const clipped = []
        for (let node = end.parentElement; node && node !== document.documentElement; node = node.parentElement) {
            if (getComputedStyle(node).overflowY !== 'visible' || node.getBoundingClientRect().bottom < bottom - 1) {
                clipped.push(node.className || node.tagName)
            }
        }
        return { clipped, height: document.body.getBoundingClientRect().height, viewport: innerHeight }
    }, END_MARKER)
    assert(result, 'long document end marker missing')
    assert.deepEqual(result.clipped, [], 'print leaves a clipping ancestor above the document')
    assert(result.height > result.viewport * 2, 'print body remains constrained to the viewport')
    console.log('ok: rueckenwind print releases document ancestors')
}

async function main() {
    assert(existsSync(SETTINGS_FILE), `settings.yaml not found at ${SETTINGS_FILE}; run test:setup first`)
    assert(!existsSync(FIXTURE_PATH), `fixture path already exists: ${FIXTURE_PATH}`)
    const originalSettings = readFileSync(SETTINGS_FILE)
    const launchOptions = {
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    }
    if (process.env.PUPPETEER_EXECUTABLE_PATH) launchOptions.executablePath = process.env.PUPPETEER_EXECUTABLE_PATH
    let browser
    let fixtureOwned = false
    try {
        mkdirSync(FIXTURE_PATH)
        fixtureOwned = true
        const table = '| Setting | Value | Scope | Notes |\n|---|---|---|---|\n'
            + `| VeryLongSettingName${'X'.repeat(80)} | Enabled | Handbook | Readable without widening the document |\n`
        writeFileSync(join(FIXTURE_PATH, 'index.md'), `# ${TITLE}\n\n${table}\n\n`
            + Array.from({ length: 50 }, (_, i) => `## Section ${i + 1}\n\nLong documentation keeps flowing onto printed pages, including its final paragraph.\n`).join('\n')
            + `\n${END_MARKER}\n`)
        writeFileSync(join(FIXTURE_PATH, 'index.yaml'), `meta:\n    title: ${JSON.stringify(TITLE)}\n    navtitle: UX\n    description: ${JSON.stringify(DESCRIPTION)}\n    heroimage: themes/rueckenwind/rueckenwind.png\n    contains: pages\n    noindex: true\n`)
        writeFileSync(join(FIXTURE_PATH, '01-details.md'), '# Details\n\nA child for desktop dropdown testing.\n')
        writeFileSync(join(FIXTURE_PATH, '01-details.yaml'), 'meta:\n    title: Details\n    noindex: true\n')
        const settings = originalSettings.toString('utf8')
        writeFileSync(SETTINGS_FILE, /^theme:.*$/m.test(settings)
            ? settings.replace(/^theme:.*$/m, 'theme: rueckenwind') : `theme: rueckenwind\n${settings}`)
        clearNavigationCache()

        browser = await puppeteer.launch(launchOptions)
        const page = await browser.newPage()
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        page.on('console', (message) => {
            if (message.type() !== 'error') return
            const loc = message.location()?.url || ''
            if (/favicon\.ico|Failed to load resource: the server responded with a status of 404/i.test(`${message.text()} ${loc}`)) return
            errors.push(`${message.text()} ${loc}`.trim())
        })
        await page.setViewport({ width: 390, height: 800 })
        const response = await page.goto(`${BASE_URL}${FIXTURE_URL}`, { waitUntil: 'networkidle2' })
        assert.equal(response?.status(), 200, 'fixture did not load')
        await assertDrawer(page)
        await assertDesktopDropdown(page)
        await assertHeroAndTables(page)
        await assertPrint(page)
        assert.deepEqual(errors, [], 'browser errors')
    } finally {
        try {
            if (browser) await browser.close()
        } finally {
            writeFileSync(SETTINGS_FILE, originalSettings)
            if (fixtureOwned) rmSync(FIXTURE_PATH, { recursive: true, force: true })
            clearNavigationCache()
        }
    }
}

main().catch((error) => {
    console.error(error)
    process.exitCode = 1
})
