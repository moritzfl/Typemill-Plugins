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
const DESKTOP = { width: 1280, height: 800 }

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

async function pressTab(page, shift = false) {
    if (!shift) {
        await page.keyboard.press('Tab')
        return
    }
    await page.keyboard.down('Shift')
    try {
        await page.keyboard.press('Tab')
    } finally {
        await page.keyboard.up('Shift')
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

/** Layout boxes alone do not prove visibility: collapsed accordions keep them. */
function describeFocus({ drawer = null, toggle = '[data-nav-toggle]' } = {}) {
    const active = document.activeElement
    if (!active || active === document.body) {
        return { tag: 'body', visible: false, inNav: false, isToggle: false }
    }

    const rect = active.getBoundingClientRect()
    const style = getComputedStyle(active)
    const nav = document.querySelector(drawer || '[data-nav]')
    const closedPanel = Array.from(document.querySelectorAll(
        '[data-nav-parent][aria-controls], [data-topnav-parent][aria-controls], #sidebar [data-nav-toggle][aria-controls]'
    )).find((control) => control !== active
        && control.getAttribute('aria-expanded') === 'false'
        && document.getElementById(control.getAttribute('aria-controls'))?.contains(active))

    return {
        tag: active.tagName.toLowerCase(),
        text: (active.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40),
        visible:
            rect.width > 0 &&
            rect.height > 0 &&
            style.visibility !== 'hidden' &&
            style.display !== 'none' &&
            !active.closest('[inert]') &&
            !closedPanel,
        inNav: Boolean(nav && nav.contains(active)),
        isToggle: active === document.querySelector(toggle),
        closedPanel: closedPanel?.getAttribute('aria-controls') || null,
    }
}

async function settleNavigation(page) {
    await page.evaluate(async () => {
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
        const animations = Array.from(document.querySelectorAll('header, [data-nav], #sidebar'))
            .flatMap((root) => root.getAnimations({ subtree: true }))
            .filter((animation) => animation.effect?.getComputedTiming().iterations !== Infinity)
        await Promise.all(animations.map((animation) => animation.finished.catch(() => {})))
    })
}

async function assertClosedPanelsUnreachable(page, theme, { drawer = null, toggle = '[data-nav-toggle]' } = {}) {
    const reachable = await page.evaluate(({ drawer, toggle }) => {
        const previous = document.activeElement
        const controls = Array.from(document.querySelectorAll(
            '[data-nav-parent][aria-controls], [data-topnav-parent][aria-controls], #sidebar [data-nav-toggle][aria-controls]'
        ))
        const button = document.querySelector(toggle)
        // A desktop menu button is hidden; its list is deliberately still usable.
        if (button?.checkVisibility({ checkVisibilityCSS: true })) controls.push(button)
        for (const control of controls) {
            if (control.getAttribute('aria-expanded') !== 'false') continue
            const panel = document.getElementById(control.getAttribute('aria-controls'))
                || (control === button ? document.querySelector(drawer || '[data-nav] ul') : null)
            for (const target of panel?.querySelectorAll('a[href], button, input, [tabindex]') || []) {
                target.focus()
                if (document.activeElement === target) {
                    const result = `${panel.id || 'drawer'}: ${target.textContent.trim().slice(0, 60)}`
                    previous.focus()
                    return result
                }
            }
        }
        previous.focus()
        return null
    }, { drawer, toggle })
    assert(!reachable, `${theme}: a closed panel still accepts focus (${reachable})`)
}

async function assertDrawerKeyboard(page, theme, options = {}) {
    const { drawer = null, toggle = '[data-nav-toggle]' } = options
    setTheme(theme)

    await page.setViewport(VIEWPORT)
    const response = await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' })
    const status = response ? response.status() : 0
    assert(status === 200, `${theme}: the homepage answered with HTTP ${status}`)

    const hasToggle = await page.evaluate((selector) => Boolean(document.querySelector(selector)), toggle)
    assert(hasToggle, `${theme}: no menu button was rendered, so nothing was proven`)
    await assertClosedPanelsUnreachable(page, theme, options)
    const targetSize = await page.$eval(toggle, (button) => {
        const rect = button.getBoundingClientRect()
        return { width: rect.width, height: rect.height }
    })
    assert(targetSize.width >= 44 && targetSize.height >= 44,
        `${theme}: menu target is ${targetSize.width}x${targetSize.height}, smaller than 44x44`)

    // Open the way a keyboard user does: focus the button, press Enter.
    await page.focus(toggle)
    await page.keyboard.press('Enter')
    await settleNavigation(page)

    const opened = await page.evaluate(describeFocus, options)
    assert(
        opened.inNav && opened.visible && !opened.isToggle,
        `${theme}: opening the drawer left focus on <${opened.tag}> "${opened.text || ''}" `
            + `(still the menu button: ${opened.isToggle}) - the links come before the button, `
            + 'so the menu is visible and the next Tab goes straight past it'
    )

    const expanded = await page.$eval(toggle, (button) => button.getAttribute('aria-expanded'))
    assert(expanded === 'true', `${theme}: the menu button reports aria-expanded="${expanded}" while open`)
    await assertClosedPanelsUnreachable(page, theme, options)

    // Inline menus may leave the navigation, but neither kind may enter a closed section.
    for (const shift of [false, true]) {
        const key = shift ? 'Shift+Tab' : 'Tab'
        for (let i = 0; i < 25; i++) {
            await pressTab(page, shift)
            const focus = await page.evaluate(describeFocus, options)
            assert(!focus.closedPanel, `${theme}: ${key} entered closed panel ${focus.closedPanel}`)
        }
    }

    // Open a section too: closing the drawer hides its link,
    // which is where focus used to be sent.
    const section = await page.evaluate((drawer) => {
        const parent = document.querySelector(drawer
            ? `${drawer} [data-nav-toggle][aria-controls]`
            : '[data-nav] [data-nav-parent]')
        if (!parent) {
            return { opened: false }
        }
        parent.focus()
        if (drawer) {
            if (parent.getAttribute('aria-expanded') !== 'true') parent.click()
            return { opened: parent.getAttribute('aria-expanded') === 'true' }
        }
        // Observe cancellation after the theme handles each tap, then suppress
        // actual navigation so the second tap does not leave the test page.
        const tap = () => {
            let prevented
            document.addEventListener('click', (event) => {
                prevented = event.defaultPrevented
                event.preventDefault()
            }, { once: true })
            parent.dispatchEvent(new PointerEvent('click', { bubbles: true, cancelable: true, pointerType: 'touch' }))
            return prevented
        }
        const firstPrevented = tap()
        const secondPrevented = tap()
        return { opened: parent.getAttribute('aria-expanded') === 'true', firstPrevented, secondPrevented }
    }, drawer)
    assert(section.opened, `${theme}: no section was opened, so accordion closure was not tested`)
    if (!drawer) {
        assert(section.firstPrevented && !section.secondPrevented,
            `${theme}: first tap must expand, second tap must follow the section link`)
    }
    await settleNavigation(page)

    // Escape closes it, and focus must land somewhere it can be seen.
    await page.keyboard.press('Escape')
    await settleNavigation(page)

    const closed = await page.evaluate(describeFocus, options)
    assert(
        closed.visible,
        `${theme}: after Escape focus sits on a hidden <${closed.tag}> "${closed.text || ''}"`
            + ' - the closing drawer took that element with it'
    )
    assert(
        closed.isToggle,
        `${theme}: after Escape focus went to <${closed.tag}> "${closed.text || ''}", not back to the menu button`
    )

    const collapsed = await page.$eval(toggle, (button) => button.getAttribute('aria-expanded'))
    assert(collapsed === 'false', `${theme}: the menu button still reports aria-expanded="${collapsed}" after Escape`)
    await assertClosedPanelsUnreachable(page, theme, options)
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
              .filter((element) => !element.closest('[inert]')
                  && element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }))[0]
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
    await settleNavigation(page)

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

    for (const shift of [false, true]) {
        const key = shift ? 'Shift+Tab' : 'Tab'
        for (let i = 0; i < 25; i++) {
            await pressTab(page, shift)
            const focus = await page.evaluate(describeFocus, { drawer, toggle })
            assert(focus.inNav && focus.visible,
                `${theme}: ${key} ${i + 1} left the open drawer or focused hidden content (${focus.text})`)
        }
    }

    await page.keyboard.press('Escape')
    await settleNavigation(page)

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

async function focusSectionChild(page, theme, selector) {
    const panelId = await page.$eval(selector, (parent) => {
        parent.blur()
        parent.focus()
        if (parent.getAttribute('aria-expanded') !== 'true') {
            // Let the theme handle a first tap, but never navigate away on failure.
            parent.addEventListener('click', (event) => event.preventDefault(), { once: true })
            parent.dispatchEvent(new PointerEvent('click', { bubbles: true, cancelable: true, pointerType: 'touch' }))
        }
        return parent.getAttribute('aria-controls')
    })
    await settleNavigation(page)
    const focused = await page.evaluate((id) => {
        const link = document.getElementById(id)?.querySelector('a[href]')
        link?.focus()
        return Boolean(link && document.activeElement === link)
    }, panelId)
    assert(focused, `${theme}: no open section link could be focused, so nothing was proven`)
    return panelId
}

async function assertDesktopDisclosure(page, theme, options = {}) {
    setTheme(theme)
    await page.setViewport(DESKTOP)
    await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' })
    const selector = options.drawer ? '[data-topnav-parent]' : '[data-nav-parent]'
    // Rueckenwind's persistent sidebar is not a desktop dropdown.
    if (options.drawer && !await page.$(selector)) return 'sidebar'
    assert(await page.$(selector), `${theme}: no desktop section was rendered`)

    const panelId = await focusSectionChild(page, theme, selector)
    const opened = await page.evaluate(describeFocus, options)
    assert(opened.visible, `${theme}: the expanded desktop section focused invisible content`)
    await page.keyboard.press('Escape')
    await settleNavigation(page)

    const closed = await page.evaluate((id) => {
        const panel = document.getElementById(id)
        const parent = Array.from(document.querySelectorAll('[aria-controls]'))
            .find((control) => control.getAttribute('aria-controls') === id)
        const rect = panel.getBoundingClientRect()
        return {
            expanded: parent.getAttribute('aria-expanded'),
            onParent: document.activeElement === parent,
            // Deliberately independent of inert/ARIA: Court's CSS used to keep painting it.
            hidden: !panel.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })
                || rect.width === 0 || rect.height === 0,
        }
    }, panelId)
    assert(closed.expanded === 'false' && closed.hidden,
        `${theme}: desktop Escape left the panel visible or expanded (${JSON.stringify(closed)})`)
    assert(closed.onParent, `${theme}: desktop Escape did not return focus to the section trigger`)
    await assertClosedPanelsUnreachable(page, theme, options)
    return 'dropdown'
}

async function assertResponsiveFocus(page, theme, options = {}) {
    const { drawer = null, toggle = '[data-nav-toggle]' } = options
    // Start from a desktop navigation link, not the brand which stays visible.
    await page.setViewport(DESKTOP)
    await page.evaluate((drawer) => {
        const root = drawer
            ? document.querySelector('[data-topnav-parent]')?.closest('nav') || document.querySelector(drawer)
            : document.querySelector('[data-nav]')
        const nav = root.matches('nav') ? root : root.querySelector('nav')
        nav.querySelector('a[href]').focus()
    }, drawer)
    assert((await page.evaluate(describeFocus, options)).tag === 'a', `${theme}: desktop link was not focused`)

    await page.setViewport(VIEWPORT)
    await settleNavigation(page)
    assert((await page.evaluate(describeFocus, options)).visible,
        `${theme}: desktop-to-mobile resize stranded navigation focus`)

    await page.focus(toggle)
    await page.setViewport(DESKTOP)
    await settleNavigation(page)
    assert((await page.evaluate(describeFocus, options)).visible,
        `${theme}: mobile-to-desktop resize left focus on the hidden menu button`)

    // A submenu descendant can retain a layout box after its panel is collapsed.
    await page.setViewport(VIEWPORT)
    await settleNavigation(page)
    await page.focus(toggle)
    await page.keyboard.press('Enter')
    await settleNavigation(page)
    await focusSectionChild(page, theme, drawer
        ? `${drawer} [data-nav-toggle][aria-controls]` : '[data-nav-parent]')
    await page.setViewport(DESKTOP)
    await settleNavigation(page)
    assert((await page.evaluate(describeFocus, options)).visible,
        `${theme}: mobile-to-desktop resize stranded focus in a closed section`)

    const desktopParent = drawer && !await page.$('[data-topnav-parent]')
        ? `${drawer} [data-nav-toggle][aria-controls]`
        : drawer ? '[data-topnav-parent]' : '[data-nav-parent]'
    await focusSectionChild(page, theme, desktopParent)
    await page.setViewport(VIEWPORT)
    await settleNavigation(page)
    assert((await page.evaluate(describeFocus, options)).visible,
        `${theme}: desktop-to-mobile resize stranded focus in a hidden section`)
    await assertClosedPanelsUnreachable(page, theme, options)

    const background = await page.$('main a[href], main button:not([disabled])')
    assert(background, `${theme}: no background focus target was rendered`)
    await background.focus()
    for (const viewport of [DESKTOP, VIEWPORT]) {
        await page.setViewport(viewport)
        await settleNavigation(page)
        assert(await page.evaluate((target) => document.activeElement === target, background),
            `${theme}: resize stole focus from the page`)
    }
    await background.dispose()
}

async function assertReducedMotion(page, theme, options = {}) {
    const toggle = options.toggle || '[data-nav-toggle]'
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }])
    try {
        for (const viewport of [DESKTOP, VIEWPORT]) {
            await page.setViewport(viewport)
            await settleNavigation(page)
            if (viewport === VIEWPORT) {
                await page.focus(toggle)
                await page.keyboard.press('Enter')
            }
            const selector = options.drawer
                ? viewport === VIEWPORT ? `${options.drawer} [data-nav-toggle][aria-controls]` : '[data-topnav-parent]'
                : '[data-nav-parent]'
            if (await page.$(selector)) await focusSectionChild(page, theme, selector)
            const motion = await page.evaluate(() => {
                const problems = []
                const roots = document.querySelectorAll('[data-nav], .lg-header, #topbar, #sidebar')
                for (const root of roots) {
                    for (const element of [root, ...root.querySelectorAll('*')]) {
                        for (const pseudo of [null, '::before', '::after']) {
                            const style = getComputedStyle(element, pseudo)
                            for (const property of ['transitionDuration', 'transitionDelay', 'animationDuration', 'animationDelay']) {
                                const times = style[property].split(',').map((value) =>
                                    Math.abs(parseFloat(value)) * (value.trim().endsWith('ms') ? 1 : 1000))
                                if (times.some((time) => time > 1)) {
                                    problems.push(`${element.tagName}.${element.className}${pseudo || ''}: ${property}=${style[property]}`)
                                }
                            }
                        }
                    }
                }
                if (getComputedStyle(document.documentElement).scrollBehavior === 'smooth') problems.push('smooth scrolling')
                return problems.slice(0, 8)
            })
            assert(!motion.length,
                `${theme}: reduced motion retains durations/delays at ${viewport.width}px: ${motion.join('; ')}`)
            await page.keyboard.press('Escape')
            await settleNavigation(page)
        }
    } finally {
        await page.emulateMediaFeatures([])
    }
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

        await assertDrawerKeyboard(page, RUECKENWIND.name, RUECKENWIND)
        console.log(`ok: mobile menu keyboard (${RUECKENWIND.name})`)

        for (const theme of [...THEMES, RUECKENWIND.name]) {
            const options = theme === RUECKENWIND.name ? RUECKENWIND : {}
            const desktop = await assertDesktopDisclosure(page, theme, options)
            await assertResponsiveFocus(page, theme, options)
            await assertReducedMotion(page, theme, options)
            console.log(`ok: desktop Escape, responsive focus, reduced motion (${theme}, ${desktop})`)
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
