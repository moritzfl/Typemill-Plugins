/**
 * The mobile menu, operated by keyboard.
 *
 * Every theme puts the navigation before the menu button in the document, so
 * the button is passed *after* the links it reveals. Opening the drawer without
 * moving focus therefore hands the next Tab to whatever follows the header, and
 * the menu that was just opened is skipped entirely - it is visible, and out of
 * reach.
 *
 * The way back matters just as much. Escape used to return focus to the section
 * link that was open, which the closing drawer had just hidden, so the focus
 * ring vanished into a `display: none` element.
 *
 * A drawer that *covers* the page owes more than that. While it is open the page
 * behind it is decoration: Tab must not walk into it, it must not be announced,
 * and the scroll it locked must be handed back exactly as it was found. Themes
 * whose menu expands inline rather than covering the page are held to none of
 * this - there is nothing behind an inline disclosure to escape into.
 *
 * All of it is invisible to a screenshot, so it is driven here for real: open
 * with the keyboard, look at where focus went, press Escape, look again.
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

// Narrow enough for every theme to be in drawer mode.
const VIEWPORT = { width: 390, height: 800 }

const THEMES = ['atelier', 'court', 'legible', 'lucid', 'medium', 'prism']

// Rueckenwind's drawer is a sidebar with its own toggle and markup.
const RUECKENWIND = {
    name: 'rueckenwind',
    drawer: '#sidebar',
    toggle: '#mobile-menu-btn',
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

function setTheme(name) {
    const content = readFileSync(SETTINGS_FILE, 'utf8')
    const next = /^theme:.*$/m.test(content)
        ? content.replace(/^theme:.*$/m, `theme: ${name}`)
        : `theme: ${name}\n${content}`
    writeFileSync(SETTINGS_FILE, next)
    clearNavigationCache()
}

/** Where focus is, and whether that element can actually be seen. */
function describeFocus() {
    const active = document.activeElement
    if (!active || active === document.body) {
        return { tag: 'body', visible: false, inNav: false, isToggle: false }
    }

    const rect = active.getBoundingClientRect()
    const style = getComputedStyle(active)
    const nav = document.querySelector('[data-nav]')

    return {
        tag: active.tagName.toLowerCase(),
        text: (active.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40),
        visible:
            rect.width > 0 &&
            rect.height > 0 &&
            style.visibility !== 'hidden' &&
            style.display !== 'none',
        inNav: Boolean(nav && nav.contains(active)),
        isToggle: active.hasAttribute('data-nav-toggle'),
    }
}

async function assertDrawerKeyboard(page, theme) {
    setTheme(theme)

    await page.setViewport(VIEWPORT)
    const response = await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' })
    const status = response ? response.status() : 0
    assert(status === 200, `${theme}: the homepage answered with HTTP ${status}`)

    const hasToggle = await page.evaluate(() => Boolean(document.querySelector('[data-nav-toggle]')))
    assert(hasToggle, `${theme}: no menu button was rendered, so nothing was proven`)

    // Open the way a keyboard user does: focus the button, press Enter.
    await page.focus('[data-nav-toggle]')
    await page.keyboard.press('Enter')

    const opened = await page.evaluate(describeFocus)
    assert(
        opened.inNav && opened.visible && !opened.isToggle && opened.tag === 'a',
        `${theme}: opening the drawer left focus on <${opened.tag}> "${opened.text || ''}" `
            + `(still the menu button: ${opened.isToggle}) - the links come before the button, `
            + 'so the menu is visible and the next Tab goes straight past it'
    )

    const expanded = await page.evaluate(() =>
        document.querySelector('[data-nav-toggle]').getAttribute('aria-expanded')
    )
    assert(expanded === 'true', `${theme}: the menu button reports aria-expanded="${expanded}" while open`)

    // Open a section too, if there is one: closing the drawer hides its link,
    // which is where focus used to be sent.
    const sectionOpened = await page.evaluate(() => {
        const parent = document.querySelector('[data-nav] [data-nav-parent]')
        if (!parent) {
            return false
        }
        parent.focus()
        parent.click()
        return true
    })

    // Escape closes it, and focus must land somewhere it can be seen.
    await page.keyboard.press('Escape')

    const closed = await page.evaluate(describeFocus)
    assert(
        closed.visible,
        `${theme}: after Escape focus sits on a hidden <${closed.tag}> "${closed.text || ''}"`
            + (sectionOpened ? ' - the closing drawer took that element with it' : '')
    )
    assert(
        closed.isToggle,
        `${theme}: after Escape focus went to <${closed.tag}> "${closed.text || ''}", not back to the menu button`
    )

    const collapsed = await page.evaluate(() =>
        document.querySelector('[data-nav-toggle]').getAttribute('aria-expanded')
    )
    assert(collapsed === 'false', `${theme}: the menu button still reports aria-expanded="${collapsed}" after Escape`)
}

/** Is the open drawer laid over the page, or does it expand inline? */
function describeDrawer(drawerSelector) {
    const drawer = drawerSelector
        ? document.querySelector(drawerSelector)
        : (document.querySelector('[data-nav]') || document.querySelector('header'))?.querySelector('ul')

    if (!drawer) {
        return { found: false }
    }

    const style = getComputedStyle(drawer)
    const rect = drawer.getBoundingClientRect()

    return {
        found: true,
        overlay: style.position === 'fixed',
        bottom: Math.round(rect.bottom),
        viewport: window.innerHeight,
        bodyOverflow: getComputedStyle(document.body).overflow,
    }
}

/**
 * Can anything behind the drawer still be reached?
 *
 * Asked of the document rather than of one property: `inert` on an ancestor
 * makes its descendants inert without setting the property on them, and a
 * theme may mark a scroll container rather than <main>. Focus is put back into
 * the drawer afterwards, so the tab order can be walked from a known place.
 */
function probeBackgroundReachable(drawerSelector) {
    const target = document.querySelector('main a[href], main button:not([disabled])')
    let reachable = false

    if (target) {
        target.focus()
        reachable = document.activeElement === target
    }

    const nav = drawerSelector
        ? document.querySelector(drawerSelector)
        : document.querySelector('[data-nav]') || document.querySelector('header')
    const first = nav
        ? Array.prototype.slice.call(nav.querySelectorAll('a[href], button:not([disabled])'))
              .filter((element) => element.getClientRects().length > 0)[0]
        : null
    if (first) first.focus()

    return { reachable, refocused: Boolean(first) }
}

/**
 * The contract for a drawer that covers the page.
 *
 * `open`/`close` are theme-specific because the sidebar themes have their own
 * toggle; everything asserted afterwards is the same for all of them.
 */
async function assertOverlayDrawer(page, theme, { drawer = null, toggle = '[data-nav-toggle]' } = {}) {
    setTheme(theme)
    await page.setViewport(VIEWPORT)

    const response = await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' })
    const status = response ? response.status() : 0
    assert(status === 200, `${theme}: the homepage answered with HTTP ${status}`)

    const hasToggle = await page.evaluate((selector) => Boolean(document.querySelector(selector)), toggle)
    assert(hasToggle, `${theme}: no menu button was rendered, so nothing was proven`)

    // Somebody else's scroll lock must survive the drawer opening and closing.
    await page.evaluate(() => {
        document.body.style.overflow = 'clip'
    })

    await page.focus(toggle)
    await page.keyboard.press('Enter')

    const opened = await page.evaluate(describeDrawer, drawer)
    assert(opened.found, `${theme}: the drawer could not be found`)

    if (!opened.overlay) {
        // An inline menu leaves the page in reach on purpose.
        await page.keyboard.press('Escape')
        await page.evaluate(() => {
            document.body.style.overflow = ''
        })
        return 'inline'
    }

    assert(
        Math.abs(opened.bottom - opened.viewport) <= 2,
        `${theme}: the open drawer ends at ${opened.bottom}px in a ${opened.viewport}px viewport`
    )
    assert(
        ['hidden', 'clip'].includes(opened.bodyOverflow),
        `${theme}: the page still scrolls behind the open drawer (overflow: ${opened.bodyOverflow})`
    )

    const background = await page.evaluate(probeBackgroundReachable, drawer)
    assert(!background.reachable, `${theme}: the page behind the open drawer can still be focused`)
    assert(background.refocused, `${theme}: the open drawer offers nothing to focus`)

    for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab')
        const contained = await page.evaluate((selector) => {
            const nav = selector
                ? document.querySelector(selector)
                : document.querySelector('[data-nav]') || document.querySelector('header')
            return Boolean(nav && nav.contains(document.activeElement))
        }, drawer)
        assert(contained, `${theme}: Tab ${i + 1} left the open drawer for the page behind it`)
    }

    await page.keyboard.press('Escape')

    const closed = await page.evaluate((selector) => ({
        expanded: document.querySelector(selector)?.getAttribute('aria-expanded'),
        onToggle: document.activeElement === document.querySelector(selector),
        bodyOverflow: document.body.style.overflow,
        backgroundReachable: (() => {
            const target = document.querySelector('main a[href], main button:not([disabled])')
            if (!target) return false
            target.focus()
            return document.activeElement === target
        })(),
    }), toggle)

    assert(closed.expanded === 'false', `${theme}: Escape did not close the drawer`)
    assert(closed.onToggle, `${theme}: Escape did not return focus to the menu button`)
    assert(
        closed.bodyOverflow === 'clip',
        `${theme}: closing replaced the page's own scroll lock with "${closed.bodyOverflow}"`
    )
    assert(closed.backgroundReachable, `${theme}: the page stayed unreachable after the drawer closed`)

    await page.evaluate(() => {
        document.body.style.overflow = ''
    })

    return 'overlay'
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
            await assertDrawerKeyboard(page, theme)
            console.log(`ok: mobile menu keyboard (${theme})`)
        }

        for (const theme of THEMES) {
            const kind = await assertOverlayDrawer(page, theme)
            console.log(`ok: mobile menu contains the page (${theme}, ${kind})`)
        }

        const kind = await assertOverlayDrawer(page, RUECKENWIND.name, {
            drawer: RUECKENWIND.drawer,
            toggle: RUECKENWIND.toggle,
        })
        console.log(`ok: mobile menu contains the page (${RUECKENWIND.name}, ${kind})`)
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
