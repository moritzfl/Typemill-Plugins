/** Real library UI, native authoring, publication isolation and project/role boundaries. */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync, readdirSync, rmSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'

const root = process.env.TM_ROOT || '/var/www/html'
const base = process.env.TM_BASE_URL || 'http://127.0.0.1'
const yaml = value => execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo Symfony\\Component\\Yaml\\Yaml::dump(json_decode(stream_get_contents(STDIN),true), 12, 4);`], { input: JSON.stringify(value) })
const parse = bytes => JSON.parse(execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parse(stream_get_contents(STDIN)));`], { input: bytes }))
const settingsFile = join(root, 'settings/settings.yaml')
const originalSettings = readFileSync(settingsFile)
const adminFile = join(root, 'settings/users/admin.yaml')
const originalAdmin = readFileSync(adminFile)
const lib = join(root, 'data/siteblocks')
const backup = join(root, 'cache/siteblocks-test-backup')
assert(!existsSync(backup), 'stale backup must be inspected')
if (existsSync(lib)) execFileSync('cp', ['-R', lib, backup])
const hadLibrary = existsSync(lib)
const clear = () => { for (const name of readdirSync(join(root, 'data/navigation'))) rmSync(join(root, 'data/navigation', name), { recursive: true, force: true }) }
const paths = ['96-library-one', '95-library-two', '94-library-copy'].map(name => join(root, 'content', name))
const projectPath = join(root, 'content/_sbtest')
assert(!existsSync(projectPath))
for (const path of paths) assert(!existsSync(path + '.md'))
const browser = await puppeteer.launch({ executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] })
const page = await browser.newPage()
const errors = []
page.on('pageerror', error => errors.push(error.message))
page.on('console', msg => { if (msg.type() === 'error' && !msg.location().url.includes('/system/author/img/favicon-')) errors.push(msg.text()) })
page.on('response', response => { if (response.status() >= 400) console.log('HTTP', response.status(), response.url()) })
const api = (method, endpoint, body) => page.evaluate(async (method, endpoint, body) => {
    try { const response = await tmaxios({ method, url: '/api/v1/siteblocks/' + endpoint, data: body }); return { status: response.status, data: response.data } }
    catch (error) { return { status: error.response?.status, data: error.response?.data } }
}, method, endpoint, body)
const ok = async (endpoint, body) => { const result = await api('post', endpoint, body); assert.equal(result.status, 200, JSON.stringify(result)); return result.data }
const click = async text => {
    const button = await page.evaluateHandle(text => [...document.querySelectorAll('.sbl button')].find(button => button.textContent.trim() === text), text)
    assert(button.asElement(), 'button: ' + text)
    await button.asElement().click()
}
const idle = () => page.waitForFunction(() => document.querySelector('.sbl__primary') && !document.querySelector('.sbl').textContent.includes('Loading…'))
try {
    const settings = parse(originalSettings); settings.theme = 'atelier'; settings.language = 'en'; settings.plugins.siteblocks.active = true
    settings.projects = 'projects'; settings.baseprojectid = 'main'; settings.baseprojectlabel = 'Main'; settings.projectinstances = { ...(settings.projectinstances || {}), sbtest: 'Test project' }
    writeFileSync(settingsFile, yaml(settings))
    const admin = parse(originalAdmin); admin.userlanguage = 'en'; writeFileSync(adminFile, yaml(admin))
    for (const [index, path] of paths.entries()) { writeFileSync(path + '.md', '# Library fixture ' + index); writeFileSync(path + '.yaml', yaml({ meta: { title: 'Library fixture ' + index, navtitle: 'Library fixture ' + index, owner: 'admin' } })) }
    clear()
    await page.goto(base + '/tm/login', { waitUntil: 'networkidle0' })
    await page.type('#username', 'admin'); await page.type('#password', 'Test1234!')
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('input[type=submit],button[type=submit]')])
    await page.goto(base + '/tm/siteblocks', { waitUntil: 'networkidle0' })
    assert.equal(errors.length, 0, errors.join('\n'))
    await page.waitForSelector('.sbl'); await idle()
    assert.equal(await page.$eval('.sbl h1', element => element.textContent), 'Content blocks')
    await click('Create block'); await idle()
    await page.type('#sb-title', 'Contact fixture')
    await click('Markdown'); await page.type('#sb-markdown', '## Contact fixture\n\nPublished alpha.\n\n- First\n- Second')
    await click('Save draft'); await idle()
    let row = (await api('get', 'state')).data.blocks.find(row => row.title === 'Contact fixture')
    assert(row); assert.equal(row.published, null)
    await click('Publish'); await idle()
    row = (await api('get', 'state')).data.blocks.find(item => item.id === row.id)
    assert.match(row.published, /Published alpha/)
    const ref = `[:siteblock-ref id="${row.id}" :]`
    writeFileSync(paths[0] + '.md', '# One\n\n' + ref)
    writeFileSync(paths[1] + '.md', '# Two\n\n' + ref)
    writeFileSync(paths[1] + '.txt', JSON.stringify(['# Two', ref]))
    writeFileSync(paths[2] + '.md', '# Copy\n\n' + row.published)
    clear()
    let state = (await api('get', 'state')).data
    await ok('placement', { footer: row.id, revision: state.placement.revision })
    const live = async path => (await fetch(base + path)).text()
    assert.match(await live('/library-one'), /Published alpha/)
    row = (await ok('save', { id: row.id, revision: row.revision, title: 'Renamed contact', markdown: '## Contact fixture\n\nPrivate beta.' })).block
    assert(!((await live('/library-one')).includes('Private beta')))
    const preview = await ok('preview', { id: row.id, path: '/library-one' })
    const privateResponse = await page.evaluate(async url => { const response = await fetch(url); return { html: await response.text(), cache: response.headers.get('Cache-Control') } }, preview.url)
    assert.match(privateResponse.html, /Private beta/); assert.match(privateResponse.cache, /no-store/)
    assert(!((await fetch(preview.url).then(response => response.text())).includes('Private beta')))
    row = (await ok('action', { id: row.id, revision: row.revision, action: 'publish' })).block
    for (const path of ['/library-one', '/library-two']) assert.match(await live(path), /Private beta/)
    const copied = await live('/library-copy'); assert.match(copied, /Published alpha/)
    state = (await api('get', 'state')).data
    assert.equal(state.blocks.find(item => item.id === row.id).usage.length, 4)
    assert.equal((await api('post', 'action', { id: row.id, revision: row.revision, action: 'delete' })).status, 422)
    assert.equal((await api('post', 'save', { id: row.id, revision: 'stale', title: 'Lost', markdown: 'Lost' })).status, 409)
    // Native authoring must expose linked insertion and copy using the published catalog.
    await page.setViewport({ width: 1440, height: 1000 })
    await page.goto(base + '/tm/content/visual/library-one', { waitUntil: 'networkidle0' })
    await page.waitForSelector('#editor .sb-reference'); await page.click('#editor .sb-reference')
    await page.waitForSelector('.sb-reference-editor select')
    await page.waitForFunction(() => document.querySelector('.sb-reference-editor').textContent.includes('Renamed contact'))
    assert.match(await page.$eval('.sb-reference-editor', el => el.textContent), /Linked block/)
    await page.$eval('.sb-reference-editor', node => [...node.querySelectorAll('button')].find(button => button.textContent.trim() === 'Detach as copy').click())
    await page.waitForFunction(() => !document.querySelector('.sb-reference-editor'))
    await page.waitForNetworkIdle({ idleTime: 200 })
    const detached = readFileSync(paths[0] + '.txt', 'utf8')
    assert.match(detached, /Private beta/); assert(!detached.includes('siteblock-ref'))
    assert.match(readFileSync(paths[0] + '.md', 'utf8'), /siteblock-ref/, 'Detaching must only change the page draft')
    await page.click('[title="Block from library"]')
    await page.waitForSelector('.sb-reference-editor select')
    await page.waitForFunction(id => !!document.querySelector('.sb-reference-editor option[value="' + id + '"]'), {}, row.id)
    await page.select('.sb-reference-editor select', row.id)
    assert.match(await page.$eval('.sb-reference-editor', node => node.textContent), /Insert as copy/)
    await page.$eval('.sb-reference-editor', node => [...node.querySelectorAll('button')].find(button => button.textContent.trim() === 'Insert linked').click())
    await page.waitForFunction(() => !document.querySelector('.sb-reference-editor'))
    assert.match(readFileSync(paths[0] + '.txt', 'utf8'), /siteblock-ref/)
    await page.click('.edit-buttons .cancel')
    await page.click('[title="Block from library"]')
    await page.waitForSelector('.sb-reference-editor select')
    await page.waitForFunction(id => !!document.querySelector('.sb-reference-editor option[value="' + id + '"]'), {}, row.id)
    await page.select('.sb-reference-editor select', row.id)
    const beforeCopy = JSON.parse(readFileSync(paths[0] + '.txt', 'utf8')).length
    await page.$eval('.sb-reference-editor', node => [...node.querySelectorAll('button')].find(button => button.textContent.trim() === 'Insert as copy').click())
    await page.waitForFunction(() => !document.querySelector('.sb-reference-editor'))
    assert(JSON.parse(readFileSync(paths[0] + '.txt', 'utf8')).length > beforeCopy)
    await page.goto(base + '/tm/siteblocks?id=' + row.id, { waitUntil: 'networkidle0' }); await idle()
    await click('Visual'); await idle()
    await click('Edit 2')
    await page.$eval('.sbl .iformat', textarea => {
        textarea.focus(); textarea.setSelectionRange(0, 7)
        textarea.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }))
    })
    await page.waitForSelector('#formatBar', { visible: true })
    await page.click('#formatBar .inlineFormatItem:first-child')
    assert.match(await page.$eval('.sbl .iformat', textarea => textarea.value), /\*\*Private\*\*/)
    await click('+ Site layout')
    await page.waitForSelector('.sb-editor select')
    await page.select('.sb-editor select', 'cta')
    await page.type('.sb-editor input', 'Library layout')
    await click('Save draft'); await idle()
    row = (await api('get', 'state')).data.blocks.find(item => item.id === row.id)
    assert.match(row.draft, /siteblock data=/)
    const parsed = await ok('parse', { markdown: row.draft }); assert.match(parsed.html, /Library layout/)
    // Error recovery must keep the edited draft and reject a stale revision.
    await click('Markdown')
    await page.type('#sb-markdown', '\n\nUnsaved browser draft.')
    const remote = (await ok('save', { id: row.id, revision: row.revision, title: row.title, markdown: row.draft + '\n\nRemote change.' })).block
    await click('Save draft'); await idle()
    assert.match(await page.$eval('.sbl__error', node => node.textContent), /changed elsewhere/)
    assert.match(await page.$eval('#sb-markdown', node => node.value), /Unsaved browser draft/)
    page.once('dialog', dialog => dialog.accept())
    await click('Reload'); await idle()
    await click('Markdown')
    assert.match(await page.$eval('#sb-markdown', node => node.value), /Remote change/)
    assert(!(await page.$eval('#sb-markdown', node => node.value)).includes('Unsaved browser draft'))
    row = remote
    // Ten named entries stay absent from public content until deliberately placed.
    for (let i = 0; i < 9; i++) await ok('save', { title: 'Unused fixture ' + i, markdown: 'UNPLACED_SECRET_' + i })
    assert((await api('get', 'state')).data.blocks.length >= 10)
    assert(!(await live('/library-two')).includes('UNPLACED_SECRET'))
    const projected = (await ok('save', { scope: '/sbtest', title: 'Project source', markdown: 'PROJECT_CONTENT_ONLY' })).block
    const projectedLive = (await ok('action', { scope: '/sbtest', id: projected.id, revision: projected.revision, action: 'publish' })).block
    assert.equal((await api('post', 'save', { id: projected.id, revision: projectedLive.revision, title: 'Wrong scope', markdown: 'Wrong' })).status, 422)
    assert.equal((await api('post', 'placement', { footer: projected.id, revision: state.placement.revision })).status, 422)
    mkdirSync(projectPath, { recursive: true }); writeFileSync(join(projectPath, 'index.md'), '# Project\n\n' + ref + '\n\n' + `[:siteblock-ref id="${projected.id}" :]`); clear()
    const projectedHtml = await live('/sbtest')
    assert.match(projectedHtml, /PROJECT_CONTENT_ONLY/); assert(!projectedHtml.includes('Private beta'))
    const projectCatalog = (await api('get', 'catalog?path=/tm/content/visual/sbtest')).data
    assert.equal(projectCatalog.scope, '/sbtest'); assert(projectCatalog.blocks.every(block => block.id !== row.id))
    // Folder-bound authors can insert published content, but cannot administer sources.
    writeFileSync(adminFile, yaml({ ...admin, userrole: 'editor', folderaccess: 'library-one' }))
    assert.equal((await api('get', 'state')).status, 403)
    assert.equal((await api('post', 'save', { title: 'Forbidden', markdown: 'Forbidden' })).status, 403)
    const authorCatalog = await api('get', 'catalog?path=/tm/content/visual/library-one')
    assert.equal(authorCatalog.status, 200); assert.equal(authorCatalog.data.manage, false)
    assert(authorCatalog.data.blocks.every(block => !('draft' in block)))
    assert.equal((await api('get', 'catalog?path=/tm/content/visual/library-two')).status, 403)
    writeFileSync(adminFile, yaml(admin))
    // Restore an export with the same IDs, publication, history and placement.
    const bundle = (await api('get', 'export')).data
    bundle.blocks = bundle.blocks.filter(block => block.id === row.id)
    for (const path of paths.slice(0, 2)) { writeFileSync(path + '.md', '# Unlinked'); rmSync(path + '.txt', { force: true }) }
    let placement = (await api('get', 'state')).data.placement
    placement = (await ok('placement', { footer: '', revision: placement.revision })).placement
    await ok('action', { id: row.id, revision: row.revision, action: 'delete' })
    await ok('import', { bundle })
    row = (await api('get', 'state')).data.blocks.find(item => item.id === row.id)
    assert.equal(row.published, bundle.blocks[0].published); assert.deepEqual(row.history, bundle.blocks[0].history)
    assert.equal((await api('get', 'state')).data.placement.footer, row.id)
    row = (await ok('action', { id: row.id, revision: row.revision, action: 'archive' })).block
    assert.match(await live('/library-copy'), /Private beta/, 'Archiving broke an existing footer use')
    assert.equal((await api('post', 'action', { id: row.id, revision: row.revision, action: 'unpublish' })).status, 422)
    row = (await ok('action', { id: row.id, revision: row.revision, action: 'unarchive' })).block
    // Website areas use the published source; UI change cannot publish its draft.
    await page.goto(base + '/tm/siteblocks?id=' + row.id, { waitUntil: 'networkidle0' }); await idle()
    await click('Website areas')
    await page.select('.sbl section select', '')
    await click('Apply placement'); await idle()
    assert(!(await live('/library-copy')).includes('Private beta'))
    await page.select('.sbl section select', row.id)
    await click('Apply placement'); await idle()
    assert.match(await live('/library-copy'), /Private beta/); assert(!(await live('/library-copy')).includes('Remote change'))
    await click('Library')
    await page.waitForNetworkIdle({ idleTime: 200 })
    // Real media dialog: focus stays inside and Escape restores its trigger.
    await click('+ Image'); await page.waitForSelector('.sbl__media[open]')
    await page.keyboard.press('Tab')
    assert(await page.$eval('.sbl__media', node => node.contains(document.activeElement)))
    await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('.sbl__media'))
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), '+ Image')
    await page.setViewport({ width: 1440, height: 1000 })
    if (process.env.LIBRARY_SHOTS) {
        mkdirSync(process.env.LIBRARY_SHOTS, { recursive: true })
        await page.screenshot({ path: join(process.env.LIBRARY_SHOTS, 'library-en-desktop.png'), fullPage: true })
    }
    settings.language = 'de'; writeFileSync(settingsFile, yaml(settings)); writeFileSync(adminFile, yaml({ ...admin, darkmode: true }))
    await page.reload({ waitUntil: 'networkidle0' }); await idle()
    assert.equal(await page.$eval('.sbl h1', element => element.textContent), 'Bausteine')
    assert(!(await page.$eval('.sbl', element => element.textContent)).match(/SB_[A-Z_]+/))
    for (const width of [1440, 390]) {
        await page.setViewport({ width, height: 1000 })
        assert(await page.$eval('.sbl', node => node.scrollWidth <= node.clientWidth + 1), 'Mobile library overflows')
        if (process.env.LIBRARY_SHOTS) await page.screenshot({ path: join(process.env.LIBRARY_SHOTS, `library-de-dark-${width}.png`), fullPage: true })
    }
    assert.deepEqual(errors.filter(error => !/422|409|403/.test(error)), [])
    console.log('PASS library: UI, drafts, linked/copy flows, conflicts, projects, roles, restore, media, German and mobile dark mode')
} catch (error) {
    console.log(await page.$eval('.sbl', element => element.innerText).catch(() => 'Library DOM missing'))
    console.log(await page.$eval('#publisher', element => element.innerText).catch(() => ''))
    await page.screenshot({ path: join(root, 'cache/library-failure.png'), fullPage: true })
    console.log(errors)
    throw error
} finally {
    await browser.close()
    writeFileSync(settingsFile, originalSettings); writeFileSync(adminFile, originalAdmin)
    for (const path of paths) for (const ext of ['.md', '.txt', '.yaml']) rmSync(path + ext, { force: true })
    rmSync(projectPath, { recursive: true, force: true })
    rmSync(lib, { recursive: true, force: true }); if (hadLibrary) execFileSync('cp', ['-R', backup, lib]); rmSync(backup, { recursive: true, force: true })
    clear()
}
