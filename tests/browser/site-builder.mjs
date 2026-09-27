/** Native editor round-trip, portable rendering, private design previews, public JSON boundaries. */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync, readdirSync, rmSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { createHash } from 'node:crypto'

const root = process.env.TM_ROOT || '/var/www/html'
const base = process.env.TM_BASE_URL || 'http://127.0.0.1'
const settingsFile = join(root, 'settings/settings.yaml')
const fixture = join(root, 'content/98-site-builder-fixture')
const folder = join(root, 'content/97-site-builder-news')
const path = '/site-builder-fixture'
const themes = ['atelier', 'court', 'legible', 'lucid', 'medium', 'prism', 'rueckenwind']
const shots = process.env.BUILDER_SHOTS
if (shots) mkdirSync(shots, { recursive: true })
const yaml = value => execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo Symfony\\Component\\Yaml\\Yaml::dump(json_decode(stream_get_contents(STDIN),true), 10, 4);`], { input: JSON.stringify(value) })
const parse = bytes => JSON.parse(execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parse(stream_get_contents(STDIN)));`], { input: bytes }))
const clear = () => { for (const name of readdirSync(join(root, 'data/navigation'))) rmSync(join(root, 'data/navigation', name), { recursive: true, force: true }) }
const shortcode = block => `[:siteblock data="${Buffer.from(JSON.stringify({ version: 1, ...block })).toString('base64url')}" :]`
const item = { src: '/themes/atelier/atelier.png', alt: 'Atelier preview', caption: 'A responsive image', x: 25, y: 75, title: 'First card', text: 'A useful description.', url: '/site-builder-fixture', label: 'Read more' }
const blocks = ['hero', 'cta', 'columns', 'gallery', 'slideshow', 'masonry', 'collection'].map(type => ({
    type, title: `${type} fixture`, text: 'Shared layouts keep the same content across themes.', columns: 3,
    label: 'Contact', url: path, items: [item, { ...item, title: 'Second card' }], folder: '/site-builder-news', tag: 'club', limit: 2,
}))
const authoredMarkdown = '# Site builder fixture\n\n' + blocks.map(shortcode).join('\n\n') + '\n'
async function login(page) {
    await page.goto(base + '/tm/login', { waitUntil: 'networkidle2' })
    await page.type('[name=username]', process.env.TM_USER || 'admin')
    await page.type('[name=password]', process.env.TM_PASSWORD || 'Test1234!')
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[type=submit]')])
    assert(!page.url().includes('/tm/login'))
}
async function clickText(page, selector, text) {
    const clicked = await page.$$eval(selector, (nodes, text) => {
        const node = nodes.find(node => node.textContent.trim() === text)
        node?.click(); return !!node
    }, text)
    assert(clicked, `Missing button: ${text}`)
}
async function field(page, label, value, scope) {
    await page.$$eval(`${scope} label`, (labels, label, value) => {
        const match = labels.find(node => node.textContent.trim().startsWith(label))
        const node = match?.control || match?.querySelector('input,textarea,select')
        if (!node) throw Error('Missing field: ' + label)
        node.value = value; node.dispatchEvent(new window.Event('input', { bubbles: true })); node.dispatchEvent(new window.Event('change', { bubbles: true }))
    }, label, value)
}
const original = readFileSync(settingsFile)
const settings = parse(original)
const libraryId = 'sb_1234567890abcdef12345678'
const libraryFile = join(root, 'data/siteblocks/blocks', libraryId + '.yaml')
const placementFile = join(root, 'data/siteblocks/placements', createHash('sha256').update('').digest('hex') + '.yaml')
const originalPlacement = existsSync(placementFile) ? readFileSync(placementFile) : null
assert(!existsSync(libraryFile), 'Library fixture exists')
assert(!existsSync(folder) && !existsSync(fixture + '.md'), 'Fixture already exists')
let browser
try {
    settings.plugins.siteblocks = { active: true, expose_json: true }
    mkdirSync(join(root, 'data/siteblocks/blocks'), { recursive: true })
    mkdirSync(join(root, 'data/siteblocks/placements'), { recursive: true })
    const footer = '## Club contact\n\nOne shared address\n\n[Email us](mailto:club@example.test)'
    writeFileSync(libraryFile, yaml({ version: 1, id: libraryId, scope: '', title: 'Contact', draft: footer, published: footer, history: [], revision: 'fixture', archived: false }))
    writeFileSync(placementFile, yaml({ scope: '', footer: libraryId, revision: 'fixture' }))
    settings.plugins.designpanel = { active: true }
    settings.theme = 'court'
    // Keep previews independent of optional imagery in the local site's gallery.
    settings.themes ??= {}
    for (const theme of themes) {
        settings.themes[theme] = { ...(settings.themes[theme] || {}), showGallery: false, showTiles: false, showCards: false, blog: false, showNews: false }
    }
    writeFileSync(settingsFile, yaml(settings))
    writeFileSync(fixture + '.md', authoredMarkdown)
    writeFileSync(fixture + '.yaml', yaml({ meta: { title: 'Site builder fixture' } }))
    mkdirSync(folder)
    writeFileSync(join(folder, 'index.md'), '# Club news\n')
    writeFileSync(join(folder, 'index.yaml'), yaml({ meta: { title: 'Club news', contains: 'posts' } }))
    for (const [name, meta, tags] of [
        ['20260901-public', { title: 'Public club news', manualdate: '2026-09-01' }, 'club, youth'],
        ['20260902-other', { title: 'Other category' }, 'other'],
        ['20260903-private', { title: 'PRIVATE CONTENT', allowedrole: 'administrator' }, 'club'],
        ['20260904-hidden', { title: 'HIDDEN CONTENT', hide: true }, 'club'],
        ['20260905-draft', { title: 'DRAFT CONTENT' }, 'club'],
    ]) {
        writeFileSync(join(folder, name + (name.endsWith('draft') ? '.txt' : '.md')), '# ' + meta.title + '\n\nBody.\n')
        writeFileSync(join(folder, name + '.yaml'), yaml({ meta, siteblocks: { tags } }))
    }
    clear()
    browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'], ...(process.env.PUPPETEER_EXECUTABLE_PATH ? { executablePath: process.env.PUPPETEER_EXECUTABLE_PATH } : {}) })
    const page = await browser.newPage()
    const errors = []
    page.on('pageerror', error => errors.push(error.message))
    page.on('console', message => { if (message.type() === 'error' && !/favicon/.test(message.location().url || '')) errors.push(message.text() + ' ' + message.location().url) })
    for (const theme of process.env.SB_UI_ONLY ? [] : themes) {
        settings.theme = theme
        writeFileSync(settingsFile, yaml(settings)); clear()
        for (const width of [390, 1440]) {
            await page.setViewport({ width, height: 1000 })
            const response = await page.goto(base + path, { waitUntil: 'networkidle2' })
            assert.equal(response.status(), 200)
            assert.equal(await page.$$eval('main .sb', nodes => nodes.length), 7, theme)
            await page.$$eval('.sb img', images => Promise.all(images.map(image => { image.loading = 'eager'; return image.decode().catch(() => {}); })))
            const measurements = await page.evaluate(() => {
                const blocks = [...document.querySelectorAll('main .sb')]
                const box = node => node.getBoundingClientRect()
                const scroller = document.querySelector('.content-scroll') || document.documentElement
                return { overflow: scroller.scrollWidth - scroller.clientWidth,
                    overlaps: blocks.some((node, i) => i && box(node).top < box(blocks[i - 1]).bottom - 1),
                    collection: document.querySelector('.sb--collection').textContent,
                    contact: document.querySelector('footer .sb-reference')?.textContent,
                    images: [...document.querySelectorAll('.sb img')].every(image => image.complete && image.naturalWidth > 0),
                    focal: getComputedStyle(document.querySelector('.sb--gallery img')).objectPosition,
                }
            })
            assert(measurements.overflow <= 1 && !measurements.overlaps && measurements.images, `${theme}/${width}: ${JSON.stringify(measurements)}`)
            assert.match(measurements.collection, /Public club news/)
            assert(!/PRIVATE|HIDDEN|DRAFT|Other category/.test(measurements.collection))
            assert.match(measurements.contact, /One shared address/)
            assert.equal(measurements.focal, '25% 75%')
            await page.click('.sb--slideshow .sb__controls button:last-child')
            await page.waitForFunction(() => document.querySelector('.sb--slideshow .sb__controls span')?.textContent === '2 / 2')
            await page.click('.sb--gallery [data-sb-image]')
            await page.waitForSelector('.sb-lightbox[open]')
            await page.keyboard.press('ArrowRight')
            assert.match(await page.$eval('.sb-lightbox p', node => node.textContent), /2 \/ 2/)
            await page.keyboard.press('Escape')
            assert(await page.$eval('.sb--gallery [data-sb-image]', node => node === document.activeElement), 'Lightbox lost focus')
            if (shots && theme === 'court') await (await page.$('.sb--gallery')).screenshot({ path: join(shots, `gallery-${width}.png`) })
        }
        console.log('ok: portable blocks, crops, collection, shared footer and lightbox (' + theme + ')')
    }
    const json = await (await fetch(base + '/siteblocks.json?url=' + path)).json()
    assert.equal(json.schema_version, 1); assert.equal(json.blocks.length, 7)
    assert(!JSON.stringify(json).includes('owner'))
    const published = await fetch(base + '/siteblocks.json?url=/site-builder-news/public')
    assert.equal(published.status, 200)
    assert.equal((await published.json()).title, 'Public club news')
    for (const url of ['/site-builder-news/private', '/site-builder-news/hidden', '/site-builder-news/draft']) {
        assert.equal((await fetch(base + '/siteblocks.json?url=' + url)).status, 404)
    }
    // Restrictions on a folder must also hide otherwise-public children.
    writeFileSync(join(folder, 'index.yaml'), yaml({ meta: { title: 'Club news', allowedrole: 'administrator', contains: 'posts' } })); clear()
    assert.equal((await fetch(base + '/siteblocks.json?url=/site-builder-news/public')).status, 404)
    console.log('ok: public JSON excludes drafts, hidden pages and restricted ancestors')

    settings.theme = 'court'; writeFileSync(settingsFile, yaml(settings)); clear()
    await page.setViewport({ width: 1600, height: 1000 })
    await login(page)
    await page.goto(base + '/tm/content/visual' + path, { waitUntil: 'networkidle2' })
    await page.click('#editor .sb--cta')
    await page.waitForSelector('.sb-editor')
    await field(page, 'Heading', 'Edited through native blocks', '.sb-editor')
    if (shots) await (await page.$('.sb-editor')).screenshot({ path: join(shots, 'block-editor.png') })
    await page.click('.blox-editor .save')
    await page.waitForFunction(() => !document.querySelector('.sb-editor'))
    await page.reload({ waitUntil: 'networkidle2' })
    assert.match(await page.$eval('#editor', node => node.textContent), /Edited through native blocks/)
    // Saving a block creates a draft. The published page must stay untouched.
    assert.equal(readFileSync(fixture + '.md', 'utf8'), authoredMarkdown)
    assert.match(readFileSync(fixture + '.txt', 'utf8'), /siteblock/)
    await page.click('[title="Site layout"]')
    await page.waitForSelector('.sb-editor')
    await field(page, 'Layout', 'gallery', '.sb-editor')
    await clickText(page, '.sb-editor button', 'Choose from media')
    await page.waitForSelector('.sb-editor__media')
    await clickText(page, '.sb-editor__media button', 'Close library')
    await field(page, 'Layout', 'cta', '.sb-editor')
    await field(page, 'Heading', 'A newly composed block', '.sb-editor')
    await page.click('.edit-buttons .save')
    await page.waitForFunction(() => !document.querySelector('.sb-editor'))
    await page.click('.edit-buttons .cancel')
    const publishedResponse = page.waitForResponse(response => response.url().includes('/api/v1/article/publish') && response.request().method() === 'POST')
    await page.click('#publisher button')
    assert.equal((await publishedResponse).status(), 200)
    const liveHtml = await (await fetch(base + path)).text()
    assert(liveHtml.includes('Edited through native blocks') && liveHtml.includes('A newly composed block'))
    assert.equal((await (await fetch(base + '/siteblocks.json?url=' + path)).json()).blocks.length, 8)
    console.log('ok: native block creation/edit/save/reload/publish, draft isolation and media picker')

    for (const theme of themes) {
        settings.theme = theme; writeFileSync(settingsFile, yaml(settings)); clear()
        await page.goto(base + '/tm/designpanel', { waitUntil: 'networkidle2' })
        await page.waitForSelector('.dp iframe', { timeout: 15000 })
        assert.match(await page.$eval('.dp header p', node => node.textContent), /Settings for the active theme/)
        assert.equal(await page.$('.dp [role=alert]'), null)
        console.log('ok: design schema + private preview (' + theme + ')')
    }
    settings.theme = 'court'; writeFileSync(settingsFile, yaml(settings)); clear()
    await page.goto(base + '/tm/designpanel', { waitUntil: 'networkidle2' })
    await page.waitForSelector('.dp iframe')
    const oldSrc = await page.$eval('iframe', node => node.src)
    await field(page, 'Headline', 'Private design preview', '.dp__fields')
    await page.waitForFunction(src => document.querySelector('.dp iframe').src !== src, {}, oldSrc)
    let frame = await (await page.$('.dp iframe')).contentFrame()
    await frame.waitForFunction(() => document.querySelector('h1')?.textContent.trim() === 'Private design preview')
    const previewUrl = await page.$eval('.dp iframe', node => node.src)
    assert(!(await (await fetch(previewUrl)).text()).includes('Private design preview'), 'Preview leaked to another session')
    assert.match(await page.evaluate(async url => (await fetch(url)).headers.get('cache-control'), previewUrl), /no-store/)
    assert.notEqual(parse(readFileSync(settingsFile)).themes.court.heroTitle, 'Private design preview', 'Preview saved live settings')
    await field(page, 'Text size', '110', '.dp__fields')
    await page.waitForFunction(() => document.querySelector('.dp__fields input[type=number]').value === '110')
    await clickText(page, '.dp__toolbar button', 'Save design')
    await page.waitForFunction(() => [...document.querySelectorAll('.dp [role=status]')].some(node => node.textContent === 'Design saved. Your changes are live.'))
    assert.equal(parse(readFileSync(settingsFile)).themes.court.heroTitle, 'Private design preview')
    assert.equal(parse(readFileSync(settingsFile)).themes.court.typeScale, 110)
    frame = await (await page.$('.dp iframe')).contentFrame()
    await frame.waitForFunction(() => Math.abs(parseFloat(getComputedStyle(document.documentElement).fontSize) - 17.6) < .1)
    if (shots) await page.screenshot({ path: join(shots, 'design-panel.png'), fullPage: true })
    const conflict = await page.evaluate(async () => {
        try { await tmaxios.post('/api/v1/designpanel/save', { theme: 'court', revision: 'stale', values: {} }); return 0 }
        catch (error) { return error.response.status }
    })
    assert.equal(conflict, 409)
    // Expected failed request above is not a frontend fault.
    assert.deepEqual(errors.filter(error => !error.includes('409')), [])
    console.log('ok: private live preview, save, type scale and conflicting-edit protection')
} finally {
    if (browser) await browser.close()
    writeFileSync(settingsFile, original)
    rmSync(libraryFile, { force: true })
    if (originalPlacement) writeFileSync(placementFile, originalPlacement)
    else rmSync(placementFile, { force: true })
    for (const ext of ['md', 'yaml', 'txt']) rmSync(fixture + '.' + ext, { force: true })
    rmSync(folder, { force: true, recursive: true }); clear()
}
