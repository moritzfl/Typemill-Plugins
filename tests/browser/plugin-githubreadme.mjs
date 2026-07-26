/**
 * A page filled from a repository's readme.
 *
 * The promise of this plugin is not that it can fetch a file - anything can do
 * that - but that a page which has been filled once stays filled. GitHub goes
 * down, refuses a server that has used its hourly allowance, and loses
 * repositories; none of that may leave a reader looking at an empty page.
 *
 * So the failure is the main subject here, and it is produced rather than waited
 * for: the API address is pointed at a host that cannot be reached, which is what
 * every one of those situations looks like from inside the site.
 *
 * The live fetch is checked too, but tolerantly. It depends on GitHub answering
 * this machine right now, and a rate-limited run is not a broken plugin.
 *
 * Run inside the Typemill Docker container:
 *   npm run test:browser
 */
import puppeteer from 'puppeteer'
import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync, existsSync, readdirSync, rmSync, mkdirSync, unlinkSync } from 'node:fs'
import { join } from 'node:path'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_ROOT = process.env.TM_ROOT || '/var/www/html'
const USERNAME = process.env.TM_USER || 'admin'
const PASSWORD = process.env.TM_PASSWORD || 'Test1234!'

const SETTINGS_FILE = join(TM_ROOT, 'settings', 'settings.yaml')
const NAV_CACHE = join(TM_ROOT, 'data', 'navigation')
const CONTENT_DIR = join(TM_ROOT, 'content')
const PLUGIN_CACHE = join(TM_ROOT, 'data', 'githubreadme')

const SLUG = '96-github-readme-fixture'
const PAGE_URL = '/github-readme-fixture'

// A small, stable, public repository, and the sentence its readme opens with.
const REPOSITORY = 'typemill/typemill'
const UNREACHABLE_API = 'http://127.0.0.1:9/does-not-listen'

const OWN_TEXT = 'This sentence belongs to the page itself.'
const STORED_TEXT = 'This paragraph came from the stored copy.'

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

/** The key the plugin derives from owner, repository, branch and file. */
function cacheKey(repository, branch = '', path = '') {
    const [owner, name] = repository.split('/')
    return createHash('sha1').update([owner, name, branch, path].join('\n')).digest('hex')
}

function writePage(meta) {
    const lines = Object.entries(meta).map(([key, value]) => `        ${key}: ${value}`)

    writeFileSync(join(CONTENT_DIR, `${SLUG}.md`), `# Fixture\n\n${OWN_TEXT}\n`)
    writeFileSync(
        join(CONTENT_DIR, `${SLUG}.yaml`),
        `meta:\n    title: 'GitHub readme fixture'\n    hide: true\n    noindex: true\ngithub:\n${lines.join('\n')}\n`
    )
    clearNavigationCache()
}

function removePage() {
    for (const ext of ['md', 'yaml', 'txt']) {
        const file = join(CONTENT_DIR, `${SLUG}.${ext}`)
        if (existsSync(file)) {
            unlinkSync(file)
        }
    }
    clearNavigationCache()
}

/** Activate the plugin and give it its settings. */
function configure(pluginSettings) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    const lines = Object.entries(pluginSettings).map(([key, value]) => `        ${key}: ${value}`)
    const block = ['    githubreadme:', '        active: true', ...lines].join('\n') + '\n'
    const existing = /^ {4}githubreadme:\n(?:(?: {5,}.*)?\n)*/m

    if (existing.test(content)) {
        content = content.replace(existing, block)
    } else if (/^plugins:$/m.test(content)) {
        content = content.replace(/^plugins:$/m, `plugins:\n${block.replace(/\n$/, '')}`)
    } else {
        content = `${content.replace(/\n*$/, '')}\nplugins:\n${block}`
    }

    writeFileSync(SETTINGS_FILE, content)
    clearNavigationCache()
}

/** Put a copy on disk as though it had been fetched at a given time. */
function seedStoredCopy(markdown, { checkedSecondsAgo = 0, failure = null } = {}) {
    mkdirSync(PLUGIN_CACHE, { recursive: true })

    const now = Math.floor(Date.now() / 1000)
    const entry = {
        slug: REPOSITORY,
        markdown,
        etag: 'W/"seeded"',
        fetched_at: now - checkedSecondsAgo,
        checked_at: now - checkedSecondsAgo,
        failed_at: failure ? now - checkedSecondsAgo : null,
        failure,
    }

    writeFileSync(join(PLUGIN_CACHE, `${cacheKey(REPOSITORY)}.json`), JSON.stringify(entry, null, 2))
}

function clearStoredCopies() {
    rmSync(PLUGIN_CACHE, { recursive: true, force: true })
}

async function read(page, url = PAGE_URL) {
    const response = await page.goto(`${BASE_URL}${url}`, { waitUntil: 'domcontentloaded' })

    const source = await page.content()

    return {
        status: response ? response.status() : 0,
        // Why a stored copy is being served is left in the page source: on a
        // public page the plugin has neither a session to identify an editor nor
        // its translations, so it says nothing a reader would see.
        diagnostic: /<!-- github-readme: (.+?) -->/.exec(source)?.[1] ?? null,
        ...(await page.evaluate(() => {
            const readme = document.querySelector('.github-readme')
            return {
                origin: readme ? readme.getAttribute('data-origin') : null,
                repository: readme ? readme.getAttribute('data-repository') : null,
                stale: readme ? readme.getAttribute('data-stale') : null,
                readmeText: readme ? (readme.textContent || '').replace(/\s+/g, ' ').trim() : null,
                bodyText: (document.body.textContent || '').replace(/\s+/g, ' ').trim(),
                visibleNote: document.querySelector('.github-readme__note') !== null,
            }
        })),
    }
}

/**
 * The point of the plugin: with GitHub out of reach, the page is still the
 * readme.
 */
async function assertStoredCopyCarriesThePage(page) {
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60, timeout_seconds: 2 })
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' })

    clearStoredCopies()
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 0 })

    const fresh = await read(page)
    assert(fresh.status === 200, `a stored copy: the page answered with HTTP ${fresh.status}`)
    assert(fresh.readmeText !== null, 'a stored copy: the readme was not rendered at all')
    assert(
        fresh.readmeText.includes(STORED_TEXT),
        `a stored copy: the readme did not carry the stored text (got "${fresh.readmeText}")`
    )
    assert(fresh.origin === 'fresh', `a stored copy: expected origin "fresh", got "${fresh.origin}"`)
    assert(fresh.repository === REPOSITORY, `a stored copy: expected the repository to be named, got "${fresh.repository}"`)

    // Now the same copy, old enough that the plugin wants to check it - and
    // GitHub cannot be reached. The page must not change.
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 7200 })

    const stale = await read(page)
    assert(stale.status === 200, `GitHub unreachable: the page answered with HTTP ${stale.status}`)
    assert(
        stale.readmeText !== null && stale.readmeText.includes(STORED_TEXT),
        'GitHub unreachable: the page lost the readme it had'
    )
    assert(stale.origin === 'cache', `GitHub unreachable: expected origin "cache", got "${stale.origin}"`)
    assert(stale.stale === 'true', 'GitHub unreachable: the readme was not marked as a stored copy')
    assert(
        !stale.visibleNote,
        'GitHub unreachable: a reader was shown a message about it'
    )
    assert(
        stale.diagnostic !== null && stale.diagnostic.includes(REPOSITORY),
        'GitHub unreachable: the reason was not left in the page source'
    )
}

/** Replacing means the page's own text gives way; appending means it stays. */
async function assertPlacement(page) {
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 0 })

    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' })
    const replaced = await read(page)
    assert(
        !replaced.bodyText.includes(OWN_TEXT),
        'replace: the page kept its own text as well as the readme'
    )
    assert(replaced.bodyText.includes(STORED_TEXT), 'replace: the readme is missing')

    writePage({ repository: REPOSITORY, position: 'append', droptitle: 'true' })
    const appended = await read(page)
    assert(appended.bodyText.includes(OWN_TEXT), 'append: the page lost its own text')
    assert(appended.bodyText.includes(STORED_TEXT), 'append: the readme is missing')
    assert(
        appended.bodyText.indexOf(OWN_TEXT) < appended.bodyText.indexOf(STORED_TEXT),
        'append: the readme should come after the page'
    )

    writePage({ repository: REPOSITORY, position: 'prepend', droptitle: 'true' })
    const prepended = await read(page)
    assert(
        prepended.bodyText.indexOf(STORED_TEXT) < prepended.bodyText.indexOf(OWN_TEXT),
        'prepend: the readme should come before the page'
    )
}

/**
 * With nothing stored and GitHub unreachable the page keeps its own content: a
 * reader sees the page, not an error.
 */
async function assertNothingStored(page) {
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60, timeout_seconds: 2 })
    writePage({ repository: REPOSITORY, position: 'append', droptitle: 'true' })
    clearStoredCopies()

    const result = await read(page)

    assert(result.status === 200, `nothing stored: the page answered with HTTP ${result.status}`)
    assert(result.bodyText.includes(OWN_TEXT), 'nothing stored: the page lost its own content')
    assert(result.readmeText === null, 'nothing stored: something was rendered as a readme')
    assert(!result.visibleNote, 'nothing stored: a visitor should not be shown the reason')
    assert(
        result.diagnostic !== null,
        'nothing stored: the reason was not left in the page source either, so nobody can find out why'
    )
}

/** A page that names no repository is an ordinary page. */
async function assertPageWithoutRepository(page) {
    writePage({ repository: '', position: 'replace' })

    const result = await read(page)

    assert(result.status === 200, `no repository: the page answered with HTTP ${result.status}`)
    assert(result.bodyText.includes(OWN_TEXT), 'no repository: the page lost its own content')
    assert(result.readmeText === null, 'no repository: a readme was rendered anyway')
}

async function login(page) {
    await page.goto(`${BASE_URL}/tm/login`, { waitUntil: 'networkidle2' })
    await page.type('input[name="username"]', USERNAME, { delay: 10 })
    await page.type('input[name="password"]', PASSWORD, { delay: 10 })

    const honey = await page.$('input[name="personal-honey-mail"]')
    if (honey) {
        await honey.type('', { delay: 0 })
    }

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
        page.click('button[type="submit"], input[type="submit"]'),
    ])

    assert(!page.url().includes('/tm/login'), `Login failed, still on ${page.url()}`)
}

/**
 * The admin has to survive the plugin as well.
 *
 * The settings form is declared in the plugin's yaml, and a field type Typemill
 * cannot draw breaks the screen that renders it. Whether the page's meta tab
 * arrives is asserted precisely in tests/api/githubreadme.test.js, against the
 * endpoint the editor draws from; here it is only about the screens loading.
 *
 * Failed requests are read from the responses rather than from console text: a
 * console message for a failed resource does not say which resource it was, so
 * filtering it by name is not possible.
 */
async function assertAdminAccepts(page) {
    const failed = []
    const onResponse = (response) => {
        const url = response.url()

        if (response.status() >= 400 && url.startsWith(BASE_URL) && !/favicon/i.test(url)) {
            failed.push(`${response.status()} ${url}`)
        }
    }

    await login(page)
    page.on('response', onResponse)

    try {
        await page.goto(`${BASE_URL}/tm/plugins`, { waitUntil: 'networkidle2' })

        const plugins = await page.evaluate(() => document.body.textContent || '')
        assert(plugins.includes('GitHub Readme'), 'the plugin does not appear on the plugins screen')

        await page.goto(`${BASE_URL}/tm/content/visual${PAGE_URL}`, { waitUntil: 'networkidle2' })
        // The editor loads its meta over the API after the document.
        await new Promise((resolve) => setTimeout(resolve, 1500))

        assert(failed.length === 0, `the admin could not load something:\n${failed.join('\n')}`)
    } finally {
        page.off('response', onResponse)
    }
}

/**
 * The live fetch. Tolerant on purpose: this needs GitHub to answer this machine
 * at this moment, and a rate-limited run is not a broken plugin.
 */
async function assertLiveFetch(page) {
    configure({ api_base: 'https://api.github.com', fresh_minutes: 60, timeout_seconds: 8 })
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' })
    clearStoredCopies()

    const result = await read(page)
    assert(result.status === 200, `live fetch: the page answered with HTTP ${result.status}`)

    if (result.origin !== 'network') {
        console.log(`note: GitHub did not serve the readme just now (origin ${result.origin}); the live fetch was not proven`)
        return false
    }

    assert(
        result.readmeText !== null && result.readmeText.length > 200,
        'live fetch: the readme came through almost empty'
    )
    assert(
        existsSync(join(PLUGIN_CACHE, `${cacheKey(REPOSITORY)}.json`)),
        'live fetch: nothing was stored, so the next outage would empty the page'
    )

    // Relative links and pictures in the readme have to point back at GitHub.
    const rewritten = await page.evaluate(() => {
        const readme = document.querySelector('.github-readme')
        const links = Array.from(readme.querySelectorAll('a[href]')).map((a) => a.getAttribute('href'))
        const images = Array.from(readme.querySelectorAll('img[src]')).map((i) => i.getAttribute('src'))
        return {
            relative: [...links, ...images].filter((url) => url && !/^(https?:|mailto:|#)/.test(url)),
            scripts: readme.querySelectorAll('script, style, iframe, form').length,
        }
    })

    assert(
        rewritten.relative.length === 0,
        `live fetch: ${rewritten.relative.length} address(es) still point at this site: ${rewritten.relative.slice(0, 3).join(', ')}`
    )
    assert(rewritten.scripts === 0, 'live fetch: the readme brought markup that should have been removed')

    // Fetched once, then served from disk without asking again.
    const second = await read(page)
    assert(second.origin === 'fresh', `live fetch: the second view asked GitHub again (origin ${second.origin})`)

    return true
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

        await assertStoredCopyCarriesThePage(page)
        console.log('ok: github readme (a stored copy carries the page when GitHub cannot be reached)')

        await assertPlacement(page)
        console.log('ok: github readme (replace, append and prepend)')

        await assertNothingStored(page)
        console.log('ok: github readme (nothing stored: the page keeps its own content)')

        await assertPageWithoutRepository(page)
        console.log('ok: github readme (a page naming no repository is untouched)')

        const proven = await assertLiveFetch(page)
        console.log(`ok: github readme (live fetch${proven ? '' : ', skipped'})`)

        // Last, because it signs in and the pages above are read as a visitor.
        await assertAdminAccepts(page)
        console.log('ok: github readme (the plugins screen and the page meta tab)')
    } finally {
        await browser.close()
        writeFileSync(SETTINGS_FILE, originalSettings)
        removePage()
        clearStoredCopies()
        clearNavigationCache()
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
