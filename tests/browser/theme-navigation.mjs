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
