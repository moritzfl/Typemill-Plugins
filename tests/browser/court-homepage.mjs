/** Court's club homepage: existing content, useful defaults, bounded news and
 * mobile artwork. Runs against Docker; every fixture is restored in finally. */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { existsSync, mkdirSync, readFileSync, readdirSync, renameSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const ROOT = process.env.TM_ROOT || '/var/www/html'
const SETTINGS = join(ROOT, 'settings/settings.yaml')
const CONTENT = join(ROOT, 'content')
const NEWS = join(CONTENT, '96-court-home-news')
const NEWS_URL = '/court-home-news'
const IMAGE = 'media/live/court-home-fixture.svg'
const TITLE = 'Willkommen bei SUS Sehnde Badminton'
const saved = new Map()

function write(file, content) {
    if (!saved.has(file)) saved.set(file, existsSync(file) ? readFileSync(file) : null)
    // Replace the inode: Docker's bind mount can otherwise serve a newly grown
    // settings file using the old cached size, truncating the YAML mid-line.
    writeFileSync(file + '.court-tmp', content)
    renameSync(file + '.court-tmp', file)
}

function clearCache() {
    const directory = join(ROOT, 'data/navigation')
    if (existsSync(directory)) {
        for (const name of readdirSync(directory)) rmSync(join(directory, name), { recursive: true, force: true })
    }
}

function configure(extra = {}) {
    let settings = saved.get(SETTINGS)?.toString() || readFileSync(SETTINGS, 'utf8')
    for (const [key, value] of Object.entries({ theme: 'court', language: 'de' })) {
        const pattern = new RegExp(`^${key}:.*$`, 'm')
        settings = pattern.test(settings) ? settings.replace(pattern, `${key}: ${value}`) : `${key}: ${value}\n${settings}`
    }
    const options = { blogfolder: NEWS_URL, ...extra }
    const block = '    court:\n' + Object.entries(options).map(([key, value]) => `        ${key}: ${JSON.stringify(value)}\n`).join('')
    const pattern = /^ {4}court:(?:\n(?: {5,}.*)?)*\n|^ {4}court:[^\n]*\n/m
    if (pattern.test(settings)) settings = settings.replace(pattern, block)
    else if (/^themes:$/m.test(settings)) settings = settings.replace(/^themes:$/m, `themes:\n${block.trimEnd()}`)
    else settings += `\nthemes:\n${block}`
    write(SETTINGS, settings)
    clearCache()
}

async function open(page, path = '/') {
    const response = await page.goto(`${BASE_URL}${path}`, { waitUntil: 'networkidle2' })
    assert.equal(response.status(), 200, `${path} must render successfully${response.status() === 200 ? '' : ': ' + await response.text()}`)
}

async function main() {
    assert(!existsSync(NEWS), 'News fixture path is already in use')
    assert(!existsSync(join(ROOT, IMAGE)), 'Image fixture path is already in use')
    let browser
    try {
        mkdirSync(NEWS)
        write(join(ROOT, IMAGE), '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="120"><rect width="180" height="120" fill="white"/><circle cx="90" cy="60" r="40" fill="#0c3169"/></svg>')
        write(join(CONTENT, 'index.md'), `# ${TITLE}\n\n---\n`)
        write(join(CONTENT, 'index.yaml'), `meta:\n    title: ${TITLE}\n    description: Training und Neuigkeiten aus dem Verein.\n    heroimage: ${IMAGE}\n    heroimagealt: Vereinswappen\n`)
        write(join(NEWS, 'index.md'), '# Vereinsnachrichten\n\nNachrichten aus dem Verein.\n')
        write(join(NEWS, 'index.yaml'), 'meta:\n    title: Vereinsnachrichten\n    navtitle: Vereinsnachrichten\n    contains: posts\n')
        for (let i = 1; i <= 4; i++) {
            write(join(NEWS, `2026090${i}-bericht-${i}.md`), `# Bericht ${i}\n\nVereinsbericht ${i}.\n`)
            write(join(NEWS, `2026090${i}-bericht-${i}.yaml`), `meta:\n    title: Bericht ${i}\n    description: Vereinsbericht ${i}.\n    manualdate: '2026-09-0${i}'\n`)
        }
        // A draft must never become a public news preview.
        write(join(NEWS, '20260909-entwurf.txt'), '# Nicht veröffentlicht\n\nEntwurf.\n')
        write(join(NEWS, '20260909-entwurf.yaml'), 'meta:\n    title: Nicht veröffentlicht\n')
        configure()
        browser = await puppeteer.launch({
            headless: true,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
        })
        const page = await browser.newPage()
        const errors = []
        page.on('pageerror', error => errors.push(error.message))
        page.on('console', message => {
            if (message.type() === 'error' && !/favicon/.test(message.location()?.url || '')) errors.push(message.text())
        })
        for (const scheme of ['light', 'dark']) {
            await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }])
            for (const width of [320, 390, 768, 834, 1440]) {
                await page.setViewport({ width, height: 900 })
                await open(page)
                const state = await page.evaluate(() => ({
                    title: document.querySelector('h1')?.textContent.trim(),
                    h1s: document.querySelectorAll('h1').length,
                    crest: document.querySelector('.ct-hero__crest img')?.complete,
                    cta: document.querySelector('.ct-hero__actions .ct-btn')?.getAttribute('href'),
                    firstLink: document.querySelector('.ct-nav__link')?.getAttribute('href'),
                    news: [...document.querySelectorAll('.ct-home-news .ct-post')].map(card => ({
                        title: card.querySelector('.ct-post__title')?.textContent.trim(),
                        heading: card.querySelector('.ct-post__title')?.tagName,
                        links: card.querySelectorAll('a').length,
                        date: card.querySelector('time')?.getAttribute('datetime'),
                    })),
                    heading: document.querySelector('#club-news-title')?.textContent.trim(),
                    separatorHeight: document.querySelector('.ct-home-content')?.getBoundingClientRect().height || 0,
                    overflow: document.documentElement.scrollWidth > innerWidth,
                }))
                assert.equal(state.title, TITLE, 'Existing homepage title must survive the redesign')
                assert.equal(state.h1s, 1)
                assert(state.crest, 'SVG logo must render as a crest')
                assert.equal(state.cta, state.firstLink, 'Unconfigured primary action must lead to a real page')
                assert.equal(state.news.length, 3, 'Only three published news previews belong on the homepage')
                assert.deepEqual(state.news.map(post => post.title), ['Bericht 4', 'Bericht 3', 'Bericht 2'])
                assert(state.news.every(post => post.heading === 'H3' && post.links === 1 && post.date.startsWith('2026-09-')))
                assert.equal(state.heading, 'Neues vom Spielfeld.', 'New labels must follow the site language')
                assert.equal(state.separatorHeight, 0, 'A separator-only home must not create an empty band')
                assert(!state.overflow, `Homepage overflows at ${width}px in ${scheme}`)
                const artwork = await page.evaluate(() => {
                    const panel = document.querySelector('.ct-hero__visual').getBoundingClientRect()
                    // The rotated group's rectangle includes large unpainted
                    // corners. Measure its visible leaves instead.
                    const leaves = [...document.querySelectorAll('.ct-shuttle > g path, .ct-shuttle use')]
                        .map(node => node.getBoundingClientRect())
                    const drawing = {
                        left: Math.min(...leaves.map(rect => rect.left)),
                        right: Math.max(...leaves.map(rect => rect.right)),
                        top: Math.min(...leaves.map(rect => rect.top)),
                        bottom: Math.max(...leaves.map(rect => rect.bottom)),
                    }
                    const label = document.querySelector('.ct-hero__visual-top').getBoundingClientRect()
                    const footer = document.querySelector('.ct-hero__visual-bottom').getBoundingClientRect()
                    return {
                        visible: drawing.right - drawing.left > 40 && drawing.bottom - drawing.top > 80,
                        contained: drawing.left >= panel.left && drawing.right <= panel.right
                            && drawing.top >= panel.top && drawing.bottom <= panel.bottom,
                        separate: drawing.top >= label.bottom && drawing.bottom <= footer.top,
                    }
                })
                assert(artwork.visible && artwork.contained && artwork.separate,
                    `Shuttle is clipped or overlaps the club label/crest at ${width}px in ${scheme}: ${JSON.stringify(artwork)}`)
            }
        }
        console.log('ok: court homepage (published news, CTA, crest, German, 320–1440px, light/dark)')

        // Search injects its mount after Markdown rendering. Keeping this node
        // alive matters even when an otherwise empty body has no visual space.
        const search = await page.evaluate(() => {
            const prose = document.querySelector('.ct-home-content .ct-prose')
            const mount = document.createElement('div')
            mount.id = 'searchresult'
            prose.append(mount)
            const empty = prose.closest('section').getBoundingClientRect().height
            mount.innerHTML = '<p>Gefundene Seiten</p>'
            return { empty, results: prose.closest('section').getBoundingClientRect().height }
        })
        assert.equal(search.empty, 0)
        assert(search.results > 0, 'Search results must expand the homepage content')

        const card = await page.$('.ct-home-news .ct-post')
        await card.scrollIntoView()
        const box = await card.boundingBox()
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.mouse.click(box.x + box.width - 12, box.y + box.height - 12),
        ])
        assert(new URL(page.url()).pathname.startsWith(`${NEWS_URL}/bericht-`), 'Card whitespace must open the post')

        configure({ heroTitle: 'Gemeinsam aufs Feld.', heroBtnLabel: 'Zum Training', heroBtnLink: NEWS_URL, heroImageStyle: 'photo', showTiles: false, showNews: false })
        await open(page)
        assert.equal(await page.$eval('h1', node => node.textContent.trim()), 'Gemeinsam aufs Feld.')
        assert.equal(await page.$eval('.ct-hero__actions .ct-btn', node => node.getAttribute('href')), BASE_URL + NEWS_URL)
        assert(await page.$('.ct-hero__photo'), 'Photo mode must respect the explicit setting, even for SVG')
        assert.equal(await page.$('.ct-hero__crest'), null)
        assert.equal(await page.$('.ct-shortcuts'), null)
        assert.equal(await page.$('.ct-home-news'), null)
        assert.equal(await page.$('a[href="#club-sections"]'), null, 'Disabled sections must not leave a broken anchor')

        configure({ blogfolder: '/missing-court-news' })
        write(join(CONTENT, 'index.yaml'), `meta:\n    title: ${TITLE}\n`)
        write(join(CONTENT, 'index.md'), `# ${TITLE}\n\nEin wirklicher Text über unseren Verein.\n`)
        await open(page)
        assert.equal(await page.$('.ct-home-news'), null, 'A missing folder must not render a phantom news section')
        assert(await page.$('.ct-court-art'), 'No uploaded image must still produce the local artwork')
        assert.equal(await page.$('.ct-hero__photo, .ct-hero__crest'), null)
        assert(await page.$eval('.ct-home-content', node => node.getBoundingClientRect().height > 0), 'Real homepage content must remain visible')
        configure()
        for (const name of readdirSync(NEWS)) {
            if (name.endsWith('.md') && name !== 'index.md') rmSync(join(NEWS, name))
        }
        clearCache()
        await open(page)
        assert.equal(await page.$('.ct-home-news'), null, 'A folder containing only drafts must not appear')
        console.log('ok: court homepage (card click, search mount, overrides, photo, missing/empty news, real body)')

        await open(page, '/tm/login')
        await page.type('input[name="username"]', process.env.TM_USER || 'admin')
        await page.type('input[name="password"]', process.env.TM_PASSWORD || 'Test1234!')
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2' }),
            page.click('button[type="submit"], input[type="submit"]'),
        ])
        assert(!page.url().includes('/tm/login'), 'Real admin login must succeed')
        await open(page, '/tm/themes')
        const court = await page.waitForSelector('#themes li:has(input[name="court"])')
        await (await court.$('button')).click()
        for (const heading of await court.$$('h3')) {
            if (['Homepage Hero', 'News / blog'].includes(await heading.evaluate(node => node.textContent.trim()))) await heading.click()
        }
        await page.waitForFunction(() => document.body.innerText.includes('Homepage image treatment'))
        assert(await page.$eval('body', node => node.innerText.includes('Show the three latest posts')), 'New theme fields must load in the admin')
        assert.deepEqual(errors, [], 'Browser errors occurred')
        console.log('ok: court homepage (real admin login and theme form, no browser errors)')
    } finally {
        try {
            if (browser) await browser.close()
        } finally {
            for (const [file, original] of saved) {
                if (original === null) rmSync(file, { force: true })
                else writeFileSync(file, original)
            }
            rmSync(NEWS, { recursive: true, force: true })
            clearCache()
        }
    }
}

main().catch(error => { console.error(error); process.exitCode = 1 })
