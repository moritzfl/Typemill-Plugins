/** Real admin UX: settings scope, preview/save isolation, recovery, keyboard and responsive layouts. */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync, readdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'

const root = process.env.TM_ROOT || '/var/www/html'
const base = process.env.TM_BASE_URL || 'http://127.0.0.1'
const settingsFile = join(root, 'settings/settings.yaml')
const fixture = join(root, 'content/98-designpanel-ux-fixture')
const shots = process.env.DESIGN_SHOTS
const yaml = value => execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo Symfony\\Component\\Yaml\\Yaml::dump(json_decode(stream_get_contents(STDIN),true), 10, 4);`], { input: JSON.stringify(value) })
const parse = bytes => JSON.parse(execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parse(stream_get_contents(STDIN)));`], { input: bytes }))
const clear = () => { for (const name of readdirSync(join(root, 'data/navigation'))) rmSync(join(root, 'data/navigation', name), { recursive: true, force: true }) }
const original = readFileSync(settingsFile)
const userFile = join(root, 'settings/users', (process.env.TM_USER || 'admin') + '.yaml')
const originalUser = readFileSync(userFile)
const settings = parse(original)
assert(!['md', 'yaml', 'txt'].some(ext => existsSync(fixture + '.' + ext)), 'Fixture already exists')
const errors = []
let browser
async function input(page, selector, value) {
    await page.$eval(selector, (node, value) => {
        node.value = value
        node.dispatchEvent(new window.Event('input', { bubbles: true }))
        node.dispatchEvent(new window.Event('change', { bubbles: true }))
    }, value)
}
async function ready(page) {
    await page.waitForFunction(() => document.querySelector('.dp__preview')?.getAttribute('aria-busy') === 'false' && !!document.querySelector('.dp iframe'))
    assert.equal(await page.$('.dp__preview [role=alert]'), null, JSON.stringify(await page.$eval('.dp__preview', node => ({ text: node.innerText, src: node.querySelector('iframe')?.src, loaded: node.querySelector('iframe')?.contentWindow.location.href }))))
}
async function change(page, selector, value) {
    const previous = await page.$eval('.dp iframe', node => node.src)
    await input(page, selector, value)
    await page.waitForFunction(previous => document.querySelector('.dp iframe').src !== previous, {}, previous)
    await ready(page)
}
async function button(page, text) {
    const found = await page.$$eval('.dp button', (nodes, text) => {
        const node = nodes.find(node => node.textContent.trim() === text)
        if (!node || node.disabled) return false
        node.click(); return true
    }, text)
    assert(found, 'Missing enabled button: ' + text)
}
async function screenshot(page, name) {
    if (shots) await page.screenshot({ path: join(shots, name + '.png'), fullPage: true })
}
try {
    if (shots) mkdirSync(shots, { recursive: true })
    settings.theme = 'lucid'
    settings.language = 'en'
    settings.themes ??= {}
    settings.themes.lucid = { ...(settings.themes.lucid || {}), heroTitle: 'A clearer place to create.', heroSubtitle: 'Thoughtful design, familiar tools.', heroEyebrow: 'Your website', showTiles: false, blog: false, typeScale: 100, accentColor: '' }
    writeFileSync(settingsFile, yaml(settings))
    writeFileSync(fixture + '.md', '# Design preview fixture\n\n' + 'Published page text.\n\n'.repeat(30) + '[Home](/)\n')
    writeFileSync(fixture + '.yaml', yaml({ meta: { title: 'Design preview fixture' } }))
    clear()
    browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'], ...(process.env.PUPPETEER_EXECUTABLE_PATH ? { executablePath: process.env.PUPPETEER_EXECUTABLE_PATH } : {}) })
    const page = await browser.newPage()
    page.on('pageerror', error => errors.push(error.message))
    page.on('console', message => {
        if (message.type() === 'error' && !/favicon|designpanel\/(save|preview)|does-not-exist/.test(message.location().url || '')) errors.push(message.text())
    })
    await page.setViewport({ width: 1440, height: 1000 })
    await page.goto(base + '/tm/login', { waitUntil: 'networkidle2' })
    await page.type('[name=username]', process.env.TM_USER || 'admin')
    await page.type('[name=password]', process.env.TM_PASSWORD || 'Test1234!')
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[type=submit]')])
    assert(!page.url().includes('/tm/login'))
    await page.goto(base + '/tm/designpanel', { waitUntil: 'networkidle2' })
    await ready(page)
    assert.match(await page.$eval('.dp__header', node => node.textContent), /Lucid · Settings for the active theme/)
    assert.match(await page.$eval('.dp__intro', node => node.textContent), /You are editing the active theme, not an individual page/)
    assert.doesNotMatch(await page.$eval('.dp', node => node.textContent), /DP_(SCOPE_HELP|SAVE_SCOPE|PREVIEW_SCOPE)/)
    await screenshot(page, 'designer-desktop')

    // Search exposes matching controls and announces an understandable empty state.
    await input(page, '#dp-search', 'headline')
    assert(await page.$eval('#dp-heroTitle', node => node.checkVisibility()))
    await input(page, '#dp-search', 'nothing-matches-this')
    assert.match(await page.$eval('.dp__empty', node => node.textContent), /No matching settings/)
    await input(page, '#dp-search', '')
    await page.$eval('#dp-heroTitle', node => { node.closest('details').open = true })
    await change(page, '#dp-heroTitle', 'A private work in progress')
    assert.match(await page.$eval('.dp__save-state', node => node.textContent), /Unsaved changes/)
    assert.equal(parse(readFileSync(settingsFile)).themes.lucid.heroTitle, 'A clearer place to create.')
    const frame = await (await page.$('.dp iframe')).contentFrame()
    assert.equal(await frame.$eval('h1', node => node.textContent.trim()), 'A private work in progress')
    await screenshot(page, 'designer-unsaved')

    // Invalid numbers must remain unsaved, including when hidden by a search.
    const oldSrc = await page.$eval('.dp iframe', node => node.src)
    await input(page, '#dp-typeScale', '999')
    await page.waitForSelector('.dp__preview [role=alert]')
    assert.equal(await page.$eval('.dp iframe', node => node.src), oldSrc)
    await input(page, '#dp-search', 'headline')
    await button(page, 'Save design')
    await page.waitForFunction(() => document.activeElement?.id === 'dp-typeScale')
    assert.equal(parse(readFileSync(settingsFile)).themes.lucid.typeScale, 100)
    await change(page, '#dp-typeScale', '110')
    await button(page, 'Discard changes')
    await ready(page)
    assert.equal(await page.$eval('#dp-heroTitle', node => node.value), 'A clearer place to create.')

    await change(page, '#dp-accentColor', '#abc')
    assert.equal(await page.$eval('.dp__color input[type=color]', node => node.value), '#aabbcc')
    await page.$eval('.dp__color button', node => node.click())
    await page.waitForFunction(() => document.querySelector('#dp-accentColor').value === '')
    await ready(page)
    assert(await page.$eval('.dp__button--primary', node => node.disabled), 'Resetting the only change must restore the saved state')

    // Preset selection is inert until applied, and reversible before publishing.
    await page.click('.dp__presets summary')
    await page.select('#dp-preset', 'product')
    assert.equal(await page.$eval('#dp-heroTitle', node => node.value), 'A clearer place to create.')
    await button(page, 'Apply to preview')
    await page.waitForFunction(() => document.querySelector('#dp-heroTitle').value === 'Built for what comes next.')
    await ready(page)
    await screenshot(page, 'designer-preset')
    await button(page, 'Discard changes')
    await ready(page)
    await page.click('.dp__presets summary')

    // Editing content opens the native editor while preserving the unsaved Designer.
    await input(page, '#dp-path', '/designpanel-ux-fixture')
    await button(page, 'Go')
    await ready(page)
    let interior = await (await page.$('.dp iframe')).contentFrame()
    await interior.evaluate(() => window.scrollTo(0, 500))
    await change(page, '#dp-typeScale', '105')
    interior = await (await page.$('.dp iframe')).contentFrame()
    assert.equal(await interior.evaluate(() => window.scrollY), 500, 'Preview lost the reading position')
    assert.match(await interior.$eval('h1', node => node.textContent), /Design preview fixture/)
    const interiorUrl = await page.$eval('.dp iframe', node => node.src)
    assert(!(await (await fetch(interiorUrl)).text()).includes('/designpanel/assets/preview.js'), 'Interior preview leaked to anonymous session')
    const newTab = new Promise(resolve => browser.once('targetcreated', resolve))
    await page.click('.dp__preview-meta a')
    const target = await newTab
    const editor = await target.page()
    await editor.waitForSelector('#editor')
    assert.match(editor.url(), /tm\/content\/visual\/designpanel-ux-fixture/)
    await editor.close()
    assert.match(await page.$eval('.dp__save-state', node => node.textContent), /Unsaved changes/)
    await input(page, '#dp-path', 'https://example.com')
    await button(page, 'Go')
    await page.waitForSelector('#dp-path-error')
    assert.match(await page.$eval('.dp__preview-meta a', node => node.href), /designpanel-ux-fixture$/)
    interior = await (await page.$('.dp iframe')).contentFrame()
    await interior.$eval('main a', node => node.click())
    await page.waitForFunction(() => document.querySelector('#dp-path').value === '/')
    await ready(page)
    await input(page, '#dp-path', '/does-not-exist-designpanel-ux')
    await button(page, 'Go')
    await page.waitForFunction(() => document.querySelector('.dp__preview [role=alert]')?.textContent.includes('cannot be previewed'))
    await input(page, '#dp-path', '/')
    await button(page, 'Go')
    await ready(page)

    // Preview failures are actionable. A later preview must not erase a failed save.
    await page.setRequestInterception(true)
    let failPreview = false, failSave = false, holdPreview = false, held = null
    page.on('request', request => {
        if (failPreview && request.url().endsWith('/designpanel/preview')) {
            failPreview = false
            return request.respond({ status: 503, contentType: 'application/json', body: '{}' })
        }
        if (failSave && request.url().endsWith('/designpanel/save')) {
            failSave = false
            return request.respond({ status: 409, contentType: 'application/json', body: JSON.stringify({ message: 'Theme settings changed elsewhere. Reload before saving.' }) })
        }
        if (holdPreview && request.url().endsWith('/designpanel/preview')) { holdPreview = false; held = request; return }
        request.continue()
    })
    failPreview = true
    await input(page, '#dp-typeScale', '106')
    await page.waitForSelector('.dp__preview [role=alert]')
    await screenshot(page, 'designer-preview-error')
    await button(page, 'Retry preview')
    await ready(page)
    failSave = true
    await button(page, 'Save design')
    await page.waitForFunction(() => document.querySelector('.dp > [role=alert]')?.textContent.includes('changed elsewhere'))
    await change(page, '#dp-typeScale', '107')
    assert.match(await page.$eval('.dp > [role=alert]', node => node.textContent), /changed elsewhere/)
    await button(page, 'Discard changes')
    await ready(page)

    // An older response arriving during the debounce must not replace the newer edit.
    holdPreview = true
    await input(page, '#dp-typeScale', '108')
    await page.waitForRequest(request => request.url().endsWith('/designpanel/preview'))
    assert(held)
    await input(page, '#dp-typeScale', '109')
    const staleUrl = base + '/?designpreview=stale-response'
    await held.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ url: staleUrl }) })
    await page.waitForResponse(response => response.url().endsWith('/designpanel/preview') && response.request() !== held)
    assert.notEqual(await page.$eval('.dp iframe', node => node.src), staleUrl)
    await ready(page)
    await button(page, 'Save design')
    await page.waitForFunction(() => document.querySelector('.dp__notice[role=status]')?.textContent.includes('Your changes are live'))
    await ready(page)
    assert.equal(parse(readFileSync(settingsFile)).themes.lucid.typeScale, 109)
    await page.reload({ waitUntil: 'networkidle2' })
    await ready(page)
    assert.equal(await page.$eval('#dp-typeScale', node => node.value), '109')
    console.log('ok: search, validation, presets, discard, editor handoff, errors, race and saved reload')

    // Actual iframe widths, not misleading device names on a fluid viewport.
    for (const value of ['390px', '768px', '1280px']) {
        await page.select('#dp-width', value)
        assert.equal(await page.$eval('.dp iframe', node => node.getBoundingClientRect().width), parseInt(value))
    }
    await page.select('#dp-width', '100%')
    for (const width of [320, 390, 768, 1024, 1440]) {
        await page.setViewport({ width, height: 1000 })
        const dimensions = await page.evaluate(() => {
            const panel = document.querySelector('.dp').getBoundingClientRect()
            return { overflow: document.documentElement.scrollWidth - innerWidth, left: panel.left, right: panel.right }
        })
        assert(dimensions.overflow <= 1 && dimensions.left >= 0 && dimensions.right <= width, JSON.stringify({ width, ...dimensions }))
        await screenshot(page, 'designer-' + width)
        if (width < 1100) {
            await page.click('.dp__view-switch button:last-child')
            assert(await page.$eval('.dp__preview', node => node.checkVisibility()))
            assert(!await page.$eval('.dp__settings', node => node.checkVisibility()))
            await screenshot(page, 'designer-preview-' + width)
            await page.click('.dp__view-switch button:first-child')
        }
    }
    // Native details are keyboard operable and focus remains visible in both palettes.
    await page.focus('.dp__fields summary')
    const opened = await page.$eval('.dp__fields details', node => node.open)
    await page.keyboard.press('Enter')
    assert.equal(await page.$eval('.dp__fields details', node => node.open), !opened)
    assert.equal(await page.$eval('.dp__fields summary', node => getComputedStyle(node).outlineStyle), 'solid')
    writeFileSync(userFile, yaml({ ...parse(originalUser), darkmode: true }))
    await page.reload({ waitUntil: 'networkidle2' })
    await ready(page)
    assert(await page.$eval('html', node => node.classList.contains('dark')))
    assert.equal(await page.$eval('#systemNavigation a.active', node => getComputedStyle(node).backgroundColor), 'rgb(231, 229, 228)', 'The selected navigation item must retain its contrasting surface')
    await page.$eval('#dp-heroTitle', node => { node.closest('details').open = true })
    await screenshot(page, 'designer-dark')
    writeFileSync(userFile, originalUser)
    console.log('ok: 320–1440px layouts, exact device widths, keyboard and dark palette')

    // Real language switch; Designer chrome must use Typemill's translation pipeline.
    settings.language = 'de'
    writeFileSync(settingsFile, yaml(settings)); clear()
    await page.reload({ waitUntil: 'networkidle2' })
    await ready(page)
    assert.match(await page.$eval('.dp', node => node.textContent), /Design speichern/)
    assert.match(await page.$eval('.dp__preview-heading', node => node.textContent), /Private Vorschau/)
    assert.match(await page.$eval('label[for=dp-typeScale]', node => node.textContent), /Textgröße/)
    assert.match(await page.$eval('.dp__intro', node => node.textContent), /Sie bearbeiten das aktive Theme, keine einzelne Seite/)
    assert.match(await page.$eval('.dp__save-state', node => node.textContent), /Theme-Einstellungen, nicht die ausgewählte Seite/)
    assert.match(await page.$eval('.dp__preview-meta', node => node.textContent), /Seitenauswahl ändert nur die Vorschau/)
    await screenshot(page, 'designer-german')

    // Unsupported themes provide recovery links, not an empty workspace.
    settings.theme = 'cyanine'
    writeFileSync(settingsFile, yaml(settings)); clear()
    await page.reload({ waitUntil: 'networkidle2' })
    await page.waitForSelector('.dp > [role=alert]')
    assert.match(await page.$eval('.dp > [role=alert]', node => node.textContent), /unterstütztes Theme/)
    assert.match(await page.$eval('.dp__header a', node => node.href), /\/tm\/themes$/)
    assert.deepEqual(errors.filter(error => !error.includes('422')), [])
    console.log('ok: German UI, unsupported-theme recovery and no unexpected browser errors')
} finally {
    if (browser) await browser.close()
    writeFileSync(settingsFile, original)
    writeFileSync(userFile, originalUser)
    for (const ext of ['md', 'yaml', 'txt']) rmSync(fixture + '.' + ext, { force: true })
    clear()
}
