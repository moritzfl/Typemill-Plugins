/** Guided local layouts and the permission-aware Designer page chooser. */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync, readdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'

const root = process.env.TM_ROOT || '/var/www/html'
const base = process.env.TM_BASE_URL || 'http://127.0.0.1'
const settingsFile = join(root, 'settings/settings.yaml')
const userFile = join(root, 'settings/users', (process.env.TM_USER || 'admin') + '.yaml')
const fixture = join(root, 'content/96-authoring-ux')
const blocked = join(root, 'content/95-authoring-blocked')
const draft = join(root, 'content/94-authoring-draft')
const shots = process.env.AUTHORING_SHOTS
const yaml = value => execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo Symfony\\Component\\Yaml\\Yaml::dump(json_decode(stream_get_contents(STDIN),true), 10, 4);`], { input: JSON.stringify(value) })
const parse = bytes => JSON.parse(execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parse(stream_get_contents(STDIN)));`], { input: bytes }))
const clear = () => { for (const name of readdirSync(join(root, 'data/navigation'))) rmSync(join(root, 'data/navigation', name), { recursive: true, force: true }) }
const original = readFileSync(settingsFile), originalUser = readFileSync(userFile)
const settings = parse(original)
const shortcode = block => `[:siteblock data="${Buffer.from(JSON.stringify({ version: 1, ...block })).toString('base64url')}" :]`
const content = '# Authoring guide\n\n' + shortcode({ type: 'cta', title: 'A useful next step', text: 'A published call to action.', label: 'Contact', url: '/authoring-ux/contact' }) + '\n'
for (const path of [fixture, blocked, draft]) assert(!existsSync(path), 'Fixture exists: ' + path)
let browser
const errors = []
const input = (page, selector, value) => page.$eval(selector, (node, value) => {
    node.value = value
    node.dispatchEvent(new window.Event('input', { bubbles: true }))
    node.dispatchEvent(new window.Event('change', { bubbles: true }))
}, value)
const snapshot = async (page, name) => { if (shots) await page.screenshot({ path: join(shots, name + '.png'), fullPage: !await page.$('dialog[open]') }) }
async function editorField(page, label, value) {
    await page.$$eval('.sb-editor label', (nodes, label, value) => {
        const node = nodes.find(node => node.textContent.trim().startsWith(label))?.querySelector('input,textarea,select')
        if (!node) throw Error('Missing field: ' + label)
        node.value = value
        node.dispatchEvent(new window.Event('input', { bubbles: true }))
        node.dispatchEvent(new window.Event('change', { bubbles: true }))
    }, label, value)
}
async function editorButton(page, text) {
    await page.$$eval('.sb-editor button', (nodes, text) => {
        const node = nodes.find(node => node.textContent.trim() === text)
        if (!node) throw Error('Missing button: ' + text)
        node.click()
    }, text)
}
async function designReady(page) {
    await page.waitForFunction(() => document.querySelector('.dp__preview')?.getAttribute('aria-busy') === 'false' && !!document.querySelector('.dp iframe'))
    assert.equal(await page.$('.dp [role=alert]'), null)
}
try {
    if (shots) mkdirSync(shots, { recursive: true })
    settings.theme = 'lucid'; settings.language = 'en'; settings.pageaccess = true
    settings.plugins.siteblocks = { ...(settings.plugins.siteblocks || {}), active: true }
    settings.plugins.designpanel = { active: true }
    writeFileSync(settingsFile, yaml(settings))
    for (const folder of [fixture, blocked, draft]) mkdirSync(folder)
    writeFileSync(join(fixture, 'index.md'), content)
    writeFileSync(join(fixture, 'index.yaml'), yaml({ meta: { title: 'Authoring guide' } }))
    for (const [slug, meta, ext] of [
        ['01-contact', { title: 'Contact the club' }, 'md'],
        ['02-other-contact', { title: 'Contact the club', hide: true }, 'md'],
        ['03-unpublished', { title: 'UNPUBLISHED TITLE' }, 'txt'],
        ['04-restricted', { title: 'FORBIDDEN TITLE', alloweduser: 'someone-else' }, 'md'],
        ['05-administrator', { title: 'Administrator page', allowedrole: 'administrator' }, 'md'],
    ]) {
        writeFileSync(join(fixture, slug + '.' + ext), '# ' + meta.title + '\n')
        writeFileSync(join(fixture, slug + '.yaml'), yaml({ meta }))
    }
    writeFileSync(join(blocked, 'index.md'), '# Restricted parent\n')
    writeFileSync(join(blocked, 'index.yaml'), yaml({ meta: { title: 'RESTRICTED PARENT', alloweduser: 'someone-else' } }))
    writeFileSync(join(blocked, '01-child.md'), '# CHILD OF RESTRICTED PARENT\n')
    writeFileSync(join(draft, 'index.txt'), '# Draft parent\n')
    writeFileSync(join(draft, '01-child.md'), '# CHILD OF DRAFT PARENT\n')
    clear()
    browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'], ...(process.env.PUPPETEER_EXECUTABLE_PATH ? { executablePath: process.env.PUPPETEER_EXECUTABLE_PATH } : {}) })
    const page = await browser.newPage()
    page.on('pageerror', error => errors.push(error.message))
    page.on('console', message => { if (message.type() === 'error' && !/favicon|designpanel\/pages/.test(message.location().url || '')) errors.push(message.text()) })
    await page.setViewport({ width: 1440, height: 1000 })
    await page.goto(base + '/tm/login', { waitUntil: 'networkidle2' })
    await page.type('[name=username]', process.env.TM_USER || 'admin')
    await page.type('[name=password]', process.env.TM_PASSWORD || 'Test1234!')
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[type=submit]')])
    assert(!page.url().includes('/tm/login'))
    await page.goto(base + '/tm/designpanel', { waitUntil: 'networkidle2' })
    await designReady(page)
    const pages = await page.evaluate(async () => (await tmaxios.get('/api/v1/designpanel/pages')).data.pages)
    assert(pages.some(row => row.path === '/'))
    assert.equal(pages.filter(row => row.title === 'Contact the club').length, 2, 'Titles must not collapse distinct pages')
    assert(pages.some(row => row.title === 'Administrator page'), 'Permitted restricted page missing')
    assert(!JSON.stringify(pages).match(/UNPUBLISHED|FORBIDDEN|RESTRICTED PARENT|DRAFT PARENT|authoring-blocked|authoring-draft/))
    for (const row of pages) assert.deepEqual(Object.keys(row).sort(), ['path', 'title'])
    assert.equal((await fetch(base + '/api/v1/designpanel/pages')).status, 401)
    writeFileSync(userFile, yaml({ ...parse(originalUser), folderaccess: 'authoring-ux' }))
    const limited = await page.evaluate(async () => (await tmaxios.get('/api/v1/designpanel/pages')).data.pages)
    assert(limited.every(row => row.path === '/' || row.path.startsWith('/authoring-ux')), 'Folder access leaked other titles')
    writeFileSync(userFile, originalUser)
    await page.click('.dp__preview-heading button')
    await page.waitForSelector('.dp__page-dialog[open] .dp__page-results button')
    assert.equal(await page.evaluate(() => document.activeElement.id), 'dp-page-search')
    await input(page, '#dp-page-search', 'Contact the club')
    assert.equal(await page.$$('.dp__page-results button').then(nodes => nodes.length), 2)
    await snapshot(page, 'page-chooser')
    await page.keyboard.press('Escape')
    await page.waitForFunction(() => !document.querySelector('.dp__page-dialog').open)
    assert(await page.$eval('.dp__preview-heading button', node => node === document.activeElement), 'Chooser lost focus on Escape: ' + await page.evaluate(() => document.activeElement.outerHTML.slice(0,300)))
    let failPages = true
    const failOnce = request => {
        if (failPages && request.url().endsWith('/designpanel/pages')) {
            failPages = false
            return request.respond({ status: 503, contentType: 'application/json', body: '{}' })
        }
        request.continue()
    }
    await page.setRequestInterception(true)
    page.on('request', failOnce)
    await page.click('.dp__preview-heading button')
    await page.waitForSelector('.dp__page-dialog [role=alert]')
    assert.match(await page.$eval('.dp__page-dialog [role=alert]', node => node.textContent), /Could not load pages/)
    await page.click('.dp__page-dialog [role=alert] button')
    await page.waitForSelector('.dp__page-dialog[open] .dp__page-results button')
    await page.setRequestInterception(false)
    page.off('request', failOnce)
    await page.focus('#dp-page-search')
    await input(page, '#dp-page-search', 'no-such-page-title')
    assert.match(await page.$eval('.dp__page-dialog [role=status]', node => node.textContent), /No matching pages/)
    await input(page, '#dp-page-search', '/authoring-ux/contact')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Enter')
    await page.waitForFunction(() => document.querySelector('#dp-path').value === '/authoring-ux/contact')
    await designReady(page)
    assert(!await page.$eval('.dp__page-dialog', node => node.open))
    console.log('ok: native page chooser, title/path search, duplicate titles, keyboard, drafts and access boundaries')

    await page.goto(base + '/tm/content/visual/authoring-ux', { waitUntil: 'networkidle2' })
    await page.click('#editor .sb--cta')
    await page.waitForSelector('.sb-editor')
    assert.match(await page.$eval('.sb-editor__workflow', node => node.textContent), /page draft.*Publish the page/)
    const guides = new Set()
    for (const layout of ['hero', 'cta', 'columns', 'gallery', 'slideshow', 'masonry', 'collection']) {
        await editorField(page, 'Layout', layout)
        guides.add(await page.$eval('.sb-editor__guide', node => node.textContent))
    }
    assert.equal(guides.size, 7)
    await editorField(page, 'Layout', 'gallery')
    await editorButton(page, 'Add item')
    // Use a real click to verify focus is returned by the native modal dialog.
    // The native publisher is fixed at the viewport bottom. Scroll the control
    // clear of it before using real pointer input, as an editor would.
    await page.$eval('.sb-editor__item button', node => node.scrollIntoView({ block: 'center', behavior: 'instant' }))
    const mediaLoaded = page.waitForResponse(response => response.url().includes('/api/v1/images') && response.request().method() === 'GET')
    await page.click('.sb-editor__item button')
    await page.waitForSelector('.sb-editor__media[open]')
    assert.equal((await mediaLoaded).status(), 200)
    await page.waitForFunction(() => !document.querySelector('.sb-editor__media .list-enter-active'))
    await page.keyboard.down('Shift')
    await page.keyboard.press('Tab')
    await page.keyboard.up('Shift')
    // Native dialogs may traverse browser chrome (reported as body), but never
    // allow focus onto the inert page behind them. Tab returns to the dialog.
    assert(await page.$eval('.sb-editor__media', node => node.contains(document.activeElement) || document.activeElement === document.body), 'Media dialog focused the background page')
    await page.keyboard.press('Tab')
    assert(await page.$eval('.sb-editor__media', node => node.contains(document.activeElement)), 'Tab did not return to the media dialog')
    await snapshot(page, 'siteblocks-media')
    await page.keyboard.press('Escape')
    await page.waitForFunction(() => !document.querySelector('.sb-editor__media'))
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Choose from media')
    assert(await page.$('.sb-editor'), 'Closing the media picker cancelled the block')
    await editorField(page, 'Layout', 'cta')
    await editorField(page, 'Heading', 'Guided editing')
    await snapshot(page, 'siteblocks-guidance')
    await page.click('.blox-editor .save')
    await page.waitForFunction(() => !document.querySelector('.sb-editor'))
    assert.equal(readFileSync(join(fixture, 'index.md'), 'utf8'), content, 'Saving a block published the page')
    await page.reload({ waitUntil: 'networkidle2' })
    assert.match(await page.$eval('#editor', node => node.textContent), /Guided editing/)
    console.log('ok: seven layout explanations, media focus and draft-only block saving')

    // German content-editor chrome and plugin form, with the actual account dark preference.
    const current = parse(readFileSync(settingsFile)); current.language = 'de'
    writeFileSync(settingsFile, yaml(current))
    writeFileSync(userFile, yaml({ ...parse(originalUser), darkmode: true }))
    await page.reload({ waitUntil: 'networkidle2' })
    await page.click('#editor .sb--cta')
    await page.waitForSelector('.sb-editor')
    assert.match(await page.$eval('.sb-editor legend', node => node.textContent), /Seitenlayout/)
    assert.match(await page.$eval('.sb-editor__workflow', node => node.textContent), /Seitenentwurf/)
    assert.match(await page.$eval('.sb-editor__guide', node => node.textContent), /nächsten Schritt/)
    for (const layout of ['hero', 'cta', 'columns', 'gallery', 'slideshow', 'masonry', 'collection']) {
        await editorField(page, 'Layout', layout)
        assert(!guides.has(await page.$eval('.sb-editor__guide', node => node.textContent)), 'Layout guide is still English: ' + layout)
    }
    await editorField(page, 'Layout', 'cta')
    await snapshot(page, 'siteblocks-german-dark')
    await page.setViewport({ width: 390, height: 1000 })
    await snapshot(page, 'siteblocks-mobile')
    assert(await page.$eval('.sb-editor', node => node.getBoundingClientRect().right <= innerWidth), 'Block form overflows on mobile')
    await page.click('.blox-editor .cancel')
    await page.goto(base + '/tm/designpanel', { waitUntil: 'networkidle2' })
    await designReady(page)
    await page.click('.dp__view-switch button:last-child')
    await page.click('.dp__preview-heading button')
    await page.waitForSelector('.dp__page-dialog[open] .dp__page-results button')
    assert.match(await page.$eval('#dp-pages-title', node => node.textContent), /Seite für die Vorschau/)
    await snapshot(page, 'page-chooser-mobile-german')
    assert(await page.$eval('.dp__page-dialog', node => node.getBoundingClientRect().right <= innerWidth))
    // Content editing does not grant Designer access.
    writeFileSync(userFile, yaml({ ...parse(originalUser), userrole: 'editor' }))
    await page.goto(base + '/tm/content/visual/authoring-ux', { waitUntil: 'networkidle2' })
    await page.click('#editor .sb--cta')
    await page.waitForSelector('.sb-editor')
    const forbidden = await page.evaluate(async () => {
        try { return (await tmaxios.get('/api/v1/designpanel/pages')).status }
        catch (error) { return error.response.status }
    })
    assert.equal(forbidden, 403)
    // Core only loads translations for active plugins and the selected locale.
    // The settings form must remain readable before activation or without a locale file.
    writeFileSync(userFile, originalUser)
    for (const [language, active] of [['en', false], ['fr', true]]) {
        const fallback = parse(readFileSync(settingsFile))
        fallback.language = language; fallback.plugins.siteblocks.active = active
        writeFileSync(settingsFile, yaml(fallback))
        await page.goto(base + '/tm/plugins', { waitUntil: 'networkidle2' })
        await page.$eval('#plugins input[name="siteblocks"]', node => node.closest('li').querySelector('button').click())
        await page.waitForSelector('[name=expose_json]')
        assert.equal(await page.$('[name=shared_title1]'), null)
    }
    console.log('ok: readable settings help with inactive plugin and untranslated locale')
    assert.deepEqual(errors, [])
    console.log('ok: German editor/settings/chooser, native dark mode, mobile layouts and no browser errors')
} finally {
    if (browser) await browser.close()
    writeFileSync(settingsFile, original); writeFileSync(userFile, originalUser)
    for (const path of [fixture, blocked, draft]) rmSync(path, { recursive: true, force: true })
    clear()
}
