/** Long names, article images and one-target cards across the quieter themes.
 * Run serially: every fixture and setting is restored in finally.
 */
import puppeteer from 'puppeteer'
import assert from 'node:assert/strict'
import { existsSync, readFileSync, writeFileSync, renameSync, readdirSync, rmSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'

const root = process.env.TM_ROOT || '/var/www/html'
const base = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const settings = join(root, 'settings/settings.yaml')
const stem = join(root, 'content/98-theme-polish-fixture')
const url = '/theme-polish-fixture'
const gallery = join(root, 'content/97-theme-polish-gallery')
const galleryUrl = '/theme-polish-gallery'
const brand = 'An independent journal of design, technology and everyday discoveries'
const title = 'EditorialDesignAndPublishingWithoutUnnecessaryComplications'
const themes = ['atelier', 'legible', 'lucid', 'medium', 'prism', 'rueckenwind']

function write(path, content) {
    writeFileSync(path + '.polish-tmp', content)
    renameSync(path + '.polish-tmp', path)
}
function clear() {
    for (const entry of readdirSync(join(root, 'data/navigation'))) {
        rmSync(join(root, 'data/navigation', entry), { recursive: true, force: true })
    }
}

async function main() {
    const original = readFileSync(settings)
    const homeFiles = ['index.md', 'index.yaml'].map(name => join(root, 'content', name))
    const originalHome = homeFiles.map(file => readFileSync(file))
    for (const ext of ['md', 'yaml', 'txt']) assert(!existsSync(stem + '.' + ext), 'Fixture already exists')
    assert(!existsSync(gallery), 'Gallery fixture already exists')
    let browser
    const failures = []
    const errors = []
    try {
        mkdirSync(gallery)
        write(join(gallery, 'index.md'), '# Selected writing\n\nA small collection of illustrated notes.\n')
        write(join(gallery, 'index.yaml'), 'meta:\n    title: Selected writing\n    navtitle: Gallery\n    contains: posts\n    hide: true\n    noindex: true\n')
        write(join(gallery, '20260927-note.md'), '# A quieter website\n\nThe article behind the thumbnail.\n')
        write(join(gallery, '20260927-note.yaml'), 'meta:\n    title: A quieter website\n    heroimage: themes/atelier/atelier.png\n    heroimagealt: Theme preview\n    noindex: true\n')
        write(stem + '.md', `# ${title}\n\nA page should fit its screen even when a title or site name is unusually long.\n\n## A clear next step\n\nA second paragraph keeps the ordinary reading layout in view.\n`)
        write(stem + '.yaml', `meta:\n    title: ${title}\n    navtitle: Review\n    hide: true\n    noindex: true\n`)
        browser = await puppeteer.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
            ...(process.env.PUPPETEER_EXECUTABLE_PATH ? { executablePath: process.env.PUPPETEER_EXECUTABLE_PATH } : {}),
        })
        const page = await browser.newPage()
        page.on('pageerror', error => errors.push(error.message))
        for (const theme of themes) {
            const yaml = original.toString().replace(/^theme:.*$/m, `theme: ${theme}`)
                .replace(/^title:.*$/m, `title: ${brand}`).replace(/^logo:.*$/m, 'logo: ""')
            write(settings, yaml)
            clear()
            for (const width of [320, 390, 834, 1440]) {
                await page.setViewport({ width, height: 900 })
                const res = await page.goto(base + url, { waitUntil: 'networkidle2' })
                assert.equal(res.status(), 200)
                const result = await page.evaluate(() => {
                    const heading = document.querySelector('h1')
                    const header = document.querySelector('header')
                    const brand = header.querySelector('a')
                    const toggle = header.querySelector('[data-nav-toggle], #mobile-menu-btn')
                    const box = element => element.getBoundingClientRect()
                    const within = rect => rect.left >= -1 && rect.right <= innerWidth + 1
                    const visible = toggle && box(toggle).width > 0
                    const scroll = document.querySelector('.content-scroll') || document.documentElement
                    return {
                        overflow: scroll.scrollWidth - scroll.clientWidth,
                        heading: heading?.textContent.trim(),
                        brandFits: within(box(brand)) && box(brand).bottom <= box(header).bottom + 1,
                        toggleFits: !visible || (within(box(toggle)) && box(toggle).width >= 44
                            && (box(brand).right <= box(toggle).left || box(brand).bottom <= box(toggle).top)),
                    }
                })
                if (result.overflow > 1 || !result.brandFits || !result.toggleFits || result.heading !== title) {
                    failures.push(`${theme} @${width}: ${JSON.stringify(result)}`)
                }
            }
            console.log(`checked: long names and mobile controls (${theme})`)

            if (theme === 'atelier' || theme === 'medium') {
                await page.setViewport({ width: 390, height: 900 })
                await page.goto(base + galleryUrl, { waitUntil: 'networkidle2' })
                const card = theme === 'atelier' ? '.at-tile' : '.md-post'
                assert.equal(await page.$$eval(`${card} a`, links => links.length), 1, `${theme}: duplicate card links`)
                const target = await page.$eval(`${card} a`, link => link.href)
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2' }),
                    page.click(`${card} img`),
                ])
                assert.equal(page.url(), target, `${theme}: thumbnail is not clickable`)
                if (theme === 'medium') {
                    assert(await page.$eval('.md-article__hero', figure => {
                        const image = figure.querySelector('img').getBoundingClientRect()
                        const column = figure.parentElement.getBoundingClientRect()
                        return Math.abs(image.left - column.left) < 1 && Math.abs(image.right - column.right) < 1
                    }), 'Medium: hero image is indented from the reading column')
                }
                console.log(`ok: single-target image cards (${theme})`)
            }

            if (theme === 'legible') {
                for (const [width, height] of [[320, 740], [667, 375]]) {
                    await page.setViewport({ width, height })
                    await page.goto(base + url, { waitUntil: 'networkidle2' })
                    await page.click('[data-reader-toggle]')
                    await page.click('[data-reader-value="xl"]')
                    await page.click('[data-reader-value="spacious"]')
                    await page.click('[data-reader-value="high"]')
                    assert(await page.$eval('[data-reader-panel]', panel => {
                        const rect = panel.getBoundingClientRect()
                        return rect.top >= 0 && rect.bottom <= innerHeight && rect.left >= 0
                            && rect.right <= innerWidth && panel.scrollWidth <= panel.clientWidth + 1
                    }), 'Legible: reader controls overflow with large text')
                    await page.click('[data-reader-reset]')
                    await page.click('[data-reader-close]')
                    assert(await page.$eval('[data-reader-toggle]', button => button === document.activeElement), 'Reader close loses focus')
                }
                console.log('ok: reader controls, extra-large type, spacious, maximum contrast, portrait/landscape')
            }

            if (theme === 'rueckenwind') {
                // Exercise the no-hero path independently of local presets.
                const withoutThemeSettings = yaml.replace(/^themes:\n(?:[ \t]+.*\n|\n)*/m, '')
                write(settings, withoutThemeSettings + '\nthemes:\n    rueckenwind:\n        heroTitle: ""\n        heroSubtitle: ""\n        heroBtnLabel: ""\n        heroBtnLink: ""\n')
                write(homeFiles[0], '# A clear homepage title\n')
                write(homeFiles[1], 'meta:\n    title: A clear homepage title\n')
                clear()
                await page.goto(base + '/', { waitUntil: 'networkidle2' })
                assert.deepEqual(await page.$$eval('main h1', nodes => nodes.map(node => node.textContent.trim())), ['A clear homepage title'])
                console.log('ok: rueckenwind homepage title without hero or body')
            }
        }
        assert.deepEqual(errors, [], 'Browser errors')
        assert.deepEqual(failures, [], 'Long names break the layout')
    } finally {
        try {
            if (browser) await browser.close()
        } finally {
            write(settings, original)
            homeFiles.forEach((file, i) => write(file, originalHome[i]))
            for (const ext of ['md', 'yaml', 'txt']) rmSync(stem + '.' + ext, { force: true })
            rmSync(gallery, { recursive: true, force: true })
            clear()
        }
    }
}

main().catch(error => {
    console.error(error)
    process.exitCode = 1
})
