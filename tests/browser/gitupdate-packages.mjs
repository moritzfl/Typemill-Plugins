/** Real dashboard + PHP installer against a deterministic HTTPS repository. */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { createServer } from 'node:https'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync, copyFileSync, rmSync, mkdtempSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const root = process.env.TM_ROOT || '/var/www/html'
const base = process.env.TM_BASE_URL || 'http://127.0.0.1'
const settingsFile = join(root, 'settings/settings.yaml')
const ledgerFile = join(root, 'data/gitupdate/applied.json')
const temp = mkdtempSync('/var/www/tests/gitupdate-fixture-')
const certFile = '/usr/local/share/ca-certificates/gitupdate-browser-fixture.crt'
const sha = 'a'.repeat(40)
const original = readFileSync(settingsFile)
const originalLedger = existsSync(ledgerFile) ? readFileSync(ledgerFile) : null
const packages = [['theme', 'browserfixture'], ['plugin', 'browserfixtureplugin']]
const backupNames = Object.fromEntries(['themes', 'plugins'].map(kind => [kind, new Set(existsSync(join(root, kind, '.gitupdate')) ? readdirSync(join(root, kind, '.gitupdate')) : [])]))
for (const [kind, slug] of packages) assert(!existsSync(join(root, kind + 's', slug)), 'Fixture already exists')
assert(!existsSync(certFile), 'Test CA already exists')
let browser, server
try {
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', temp + '/key.pem', '-out', temp + '/cert.pem', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1'], { stdio: 'pipe' })
    copyFileSync(temp + '/cert.pem', certFile)
    execFileSync('update-ca-certificates', [], { stdio: 'pipe' })
    execFileSync('php', ['-r', `$z=new ZipArchive(); $z->open($argv[1],ZipArchive::CREATE); $z->addFromString('snapshot/themes/browserfixture/browserfixture.yaml',"name: Browser fixture theme\nversion: 1.0.0\n"); $z->addFromString('snapshot/plugins/browserfixtureplugin/browserfixtureplugin.yaml',"name: Browser fixture plugin\nversion: 1.0.0\n"); $z->addFromString('snapshot/plugins/browserfixtureplugin/browserfixtureplugin.php','<?php namespace Plugins\\browserfixtureplugin; class browserfixtureplugin extends \\Typemill\\Plugin { public static function getSubscribedEvents() { return []; } }'); $z->close();`, temp + '/repository.zip'])
    const archive = readFileSync(temp + '/repository.zip')
    server = createServer({ key: readFileSync(temp + '/key.pem'), cert: readFileSync(temp + '/cert.pem') }, (req, res) => {
        if (req.url.includes('/zipball/')) { res.writeHead(200, { 'Content-Type': 'application/zip' }); res.end(archive); return }
        res.setHeader('Content-Type', 'application/json')
        if (req.url.includes('/commits/')) res.end(JSON.stringify({ sha, commit: { message: 'Fixture release', committer: { date: '2026-09-27T00:00:00Z' } } }))
        else if (req.url.includes('/git/trees/')) res.end(JSON.stringify({ truncated: false, tree: [
            'themes/browserfixture/browserfixture.yaml', 'plugins/browserfixtureplugin/browserfixtureplugin.yaml', 'plugins/browserfixtureplugin/browserfixtureplugin.php',
        ].map(path => ({ path, type: 'blob' })) }))
        else { res.statusCode = 404; res.end('{}') }
    })
    await new Promise(resolve => server.listen(9443, '127.0.0.1', resolve))
    execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; $s=Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]); $s['plugins']['gitupdate']=['active'=>true,'repository'=>'test/packages','branch'=>'main','api_base'=>'https://127.0.0.1:9443']; file_put_contents($argv[1],Symfony\\Component\\Yaml\\Yaml::dump($s,10,4));`, settingsFile])
    browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'], ...(process.env.PUPPETEER_EXECUTABLE_PATH ? { executablePath: process.env.PUPPETEER_EXECUTABLE_PATH } : {}) })
    const page = await browser.newPage()
    const errors = []
    page.on('pageerror', error => errors.push(error.message))
    await page.goto(base + '/tm/login', { waitUntil: 'networkidle2' })
    await page.type('[name=username]', 'admin'); await page.type('[name=password]', 'Test1234!')
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[type=submit]')])
    await page.goto(base + '/tm/gitupdate', { waitUntil: 'networkidle2' })
    await page.waitForFunction(() => document.querySelector('.tm-gu-sha')?.textContent === 'aaaaaaa')
    const action = async (slug, text) => {
        assert(await page.$$eval('.tm-gu-item', (rows, slug, text) => {
            const row = rows.find(row => row.querySelector('.tm-gu-item__slug')?.textContent === slug || row.querySelector('span')?.textContent.trim().endsWith('/' + slug))
            const button = [...(row?.querySelectorAll('button') || [])].find(button => button.textContent.trim() === text)
            button?.click(); return !!button
        }, slug, text), 'Missing ' + slug + ': ' + text)
    }
    for (const [kind, slug] of packages) {
        await action(slug, 'Install')
        await page.waitForFunction(slug => [...document.querySelectorAll('.tm-gu-item__slug')].some(node => node.textContent === slug), {}, slug)
        assert(existsSync(join(root, kind + 's', slug, slug + '.yaml')))
        const ledger = JSON.parse(readFileSync(ledgerFile))
        assert.equal(ledger[kind][slug].sha, sha)
        assert.equal(ledger[kind][slug].date, '2026-09-27T00:00:00Z')
        await action(slug, 'Pin commit')
        await page.waitForFunction(slug => [...document.querySelectorAll('.tm-gu-item')].some(node => node.textContent.includes(slug) && node.textContent.includes('Unpin')), {}, slug)
        const update = await page.evaluate(async (kind, slug) => {
            try { await tmaxios.post('/api/v1/gitupdate/run', { kind, slug, force: true }); return 0 }
            catch (e) { return e.response.status }
        }, kind, slug)
        assert.equal(update, 409, 'Forced update bypasses pin')
        await action(slug, 'Unpin')
        await page.waitForFunction(slug => [...document.querySelectorAll('.tm-gu-item')].some(node => node.textContent.includes(slug) && node.textContent.includes('Pin commit')), {}, slug)
        console.log('ok: browse → install → pin → forced-update refusal → unpin (' + kind + ')')
    }
    // Active packages and the updater itself cannot be removed.
    execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; $s=Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]); $s['plugins']['browserfixtureplugin']=['active'=>true,'custom'=>'Retain my settings']; file_put_contents($argv[1],Symfony\\Component\\Yaml\\Yaml::dump($s,10,4));`, settingsFile])
    for (const slug of ['browserfixtureplugin', 'gitupdate']) {
        const result = await page.evaluate(async slug => {
            try { await tmaxios.post('/api/v1/gitupdate/package', { action: 'remove', kind: 'plugin', slug }); return 0 }
            catch (e) { return e.response.status }
        }, slug)
        assert.equal(result, 409)
    }
    execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; $s=Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]); $s['plugins']['browserfixtureplugin']['active']=false; file_put_contents($argv[1],Symfony\\Component\\Yaml\\Yaml::dump($s,10,4));`, settingsFile])
    page.on('dialog', dialog => dialog.accept())
    for (const [kind, slug] of packages) {
        await action(slug, 'Remove')
        await page.waitForFunction(slug => document.querySelector('.tm-gu-sha') && ![...document.querySelectorAll('.tm-gu-item__slug')].some(node => node.textContent === slug), {}, slug)
        assert(!existsSync(join(root, kind + 's', slug)))
        assert(!JSON.parse(readFileSync(ledgerFile))[kind][slug])
    }
    assert.equal((await fetch(base + '/api/v1/gitupdate/package', { method: 'POST', headers: { 'Content-Type': 'application/json', Referer: base }, body: JSON.stringify({ action: 'install', kind: 'theme', slug: 'browserfixture', sha }) })).status, 401)
    await action('browserfixtureplugin', 'Install')
    await page.waitForFunction(() => [...document.querySelectorAll('.tm-gu-item__slug')].some(node => node.textContent === 'browserfixtureplugin'))
    const restored = JSON.parse(execFileSync('php', ['-r', `require '${root}/system/vendor/autoload.php'; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1])['plugins']['browserfixtureplugin']);`, settingsFile]))
    assert.equal(restored.custom, 'Retain my settings'); assert.equal(restored.active, false)
    assert.deepEqual(errors, [])
    console.log('ok: active/self removal rejected; inactive packages removed; anonymous writes rejected')
} finally {
    if (browser) await browser.close()
    if (server) await new Promise(resolve => server.close(resolve))
    writeFileSync(settingsFile, original)
    if (originalLedger) writeFileSync(ledgerFile, originalLedger); else rmSync(ledgerFile, { force: true })
    for (const [kind, slug] of packages) rmSync(join(root, kind + 's', slug), { recursive: true, force: true })
    for (const [kind, slug] of packages) rmSync(join(root, 'data/gitupdate', 'removed-' + kind + '-' + slug + '.json'), { force: true })
    for (const kind of ['themes', 'plugins']) {
        const work = join(root, kind, '.gitupdate')
        if (existsSync(work)) for (const name of readdirSync(work)) {
            if (!backupNames[kind].has(name) && /(?:removed|backup)-browserfixture/.test(name)) rmSync(join(work, name), { recursive: true, force: true })
        }
    }
    rmSync(certFile, { force: true }); execFileSync('update-ca-certificates', [], { stdio: 'pipe' })
    rmSync(temp, { recursive: true, force: true })
}
