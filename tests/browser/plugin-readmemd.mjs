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
const PLUGIN_CACHE = join(TM_ROOT, 'data', 'readmemd')

const SLUG = '96-readme-md-fixture'
const PAGE_URL = '/readme-md-fixture'

// A small, stable, public repository, and the sentence its readme opens with.
const REPOSITORY = 'typemill/typemill'
const UNREACHABLE_API = 'http://127.0.0.1:9/does-not-listen'

const OWN_TEXT = 'This sentence belongs to the page itself.'
const STORED_TEXT = 'This paragraph came from the stored copy.'

/**
 * A readme shaped like the ones this is for: a layout table that centres a logo
 * and a row of badges, and a markdown table with a centred column. Both are the
 * things a theme's own prose styling tends to flatten.
 */
const README_WITH_TABLES = `# Stored readme

${STORED_TEXT}

<table align="center">
  <tr>
    <td align="center">
      <img src="logo.png" alt="Logo" width="96" />
      <br />
      <a href="https://example.com/marketplace">On the marketplace</a>
      <br />
      <img src="https://img.shields.io/badge/one-1-blue.svg" alt="Badge one" />
      <img src="https://img.shields.io/badge/two-2-green.svg" alt="Badge two" />
      <img src="https://img.shields.io/badge/three-3-red.svg" alt="Badge three" />
    </td>
  </tr>
</table>

| Provider | Quota | Proxy |
|---|:-:|--:|
| OpenAI | yes | yes |
`

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

/**
 * @param meta   the plugin's fields for the page
 * @param tab    the meta block they are written under. `github` is what pages
 *               saved before the plugin was renamed still carry.
 */
function writePage(meta, tab = 'readme') {
    const lines = Object.entries(meta).map(([key, value]) => `        ${key}: ${value}`)

    writeFileSync(join(CONTENT_DIR, `${SLUG}.md`), `# Fixture\n\n${OWN_TEXT}\n`)
    writeFileSync(
        join(CONTENT_DIR, `${SLUG}.yaml`),
        `meta:\n    title: 'Readme fixture'\n    hide: true\n    noindex: true\n${tab}:\n${lines.join('\n')}\n`
    )
    clearNavigationCache()
}

function removePage() {
    for (const slug of [SLUG, '96-github-readme-fixture']) {
        for (const ext of ['md', 'yaml', 'txt']) {
            const file = join(CONTENT_DIR, `${slug}.${ext}`)
            if (existsSync(file)) {
                unlinkSync(file)
            }
        }
    }
    clearNavigationCache()
}

/** Activate the plugin and give it its settings. */
function configure(pluginSettings) {
    let content = readFileSync(SETTINGS_FILE, 'utf8')

    const lines = Object.entries(pluginSettings).map(([key, value]) => `        ${key}: ${value}`)
    const block = ['    readmemd:', '        active: true', ...lines].join('\n') + '\n'
    const existing = /^ {4}readmemd:\n(?:(?: {5,}.*)?\n)*/m

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
    // Pre-rename suite left copies under the former folder name; they would be
    // inherited and make "nothing stored" look filled.
    rmSync(join(TM_ROOT, 'data', 'githubreadme'), { recursive: true, force: true })
}

async function read(page, url = PAGE_URL) {
    const response = await page.goto(`${BASE_URL}${url}`, { waitUntil: 'domcontentloaded' })

    const source = await page.content()

    return {
        status: response ? response.status() : 0,
        // Why a stored copy is being served is left in the page source: on a
        // public page the plugin has neither a session to identify an editor nor
        // its translations, so it says nothing a reader would see.
        diagnostic: /<!-- readme-md: (.+?) -->/.exec(source)?.[1] ?? null,
        ...(await page.evaluate(() => {
            const readme = document.querySelector('.readme-md')
            return {
                origin: readme ? readme.getAttribute('data-origin') : null,
                repository: readme ? readme.getAttribute('data-repository') : null,
                stale: readme ? readme.getAttribute('data-stale') : null,
                readmeText: readme ? (readme.textContent || '').replace(/\s+/g, ' ').trim() : null,
                bodyText: (document.body.textContent || '').replace(/\s+/g, ' ').trim(),
                visibleNote: document.querySelector('.readme-md__note') !== null,
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

/**
 * A page written before the plugin was renamed keeps working.
 *
 * The plugin used to be named after GitHub, and Typemill stores a page's fields
 * under the name of the tab they were entered on. Renaming the plugin must not be
 * what empties a page: the old block is still read.
 *
 * Once the new tab is present, even empty, it wins: otherwise clearing the
 * repository in the editor could never turn the readme off, because Typemill only
 * rewrites the tab that was just saved.
 */
async function assertPageFromTheFormerTab(page) {
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60, timeout_seconds: 2 })
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' }, 'github')

    clearStoredCopies()
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 0 })

    const shown = await read(page)
    assert(shown.status === 200, `the former tab: the page answered with HTTP ${shown.status}`)
    assert(
        shown.readmeText !== null && shown.readmeText.includes(STORED_TEXT),
        'the former tab: a page saved before the rename lost its readme'
    )
    assert(
        shown.repository === REPOSITORY,
        `the former tab: expected the repository to be named, got "${shown.repository}"`
    )

    // Both tabs present, new one empty: the author cleared the field.
    writeFileSync(
        join(CONTENT_DIR, `${SLUG}.yaml`),
        `meta:\n    title: 'Readme fixture'\n    hide: true\n    noindex: true\n` +
            `readme:\n        repository: ''\n` +
            `github:\n        repository: ${REPOSITORY}\n        position: replace\n`
    )
    clearNavigationCache()

    const cleared = await read(page)
    assert(
        cleared.readmeText === null,
        'the former tab: clearing the repository in the new tab left the readme on'
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

/**
 * A readme keeps the shape its author gave it.
 *
 * A theme styles an article: pictures are photographs, one to a line and as wide
 * as the column, and table cells read from the left. A readme is not written like
 * that — it centres a logo, puts three badges in a row, and centres a column with
 * markdown's own |:-:| — and all of that has to survive being put on the page.
 */
async function assertReadmeKeepsItsShape(page) {
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60, timeout_seconds: 2 })
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' })
    seedStoredCopy(README_WITH_TABLES, { checkedSecondsAgo: 0 })

    await page.setViewport({ width: 1280, height: 900 })
    const result = await read(page)
    assert(result.status === 200, `shape: the page answered with HTTP ${result.status}`)

    const shape = await page.evaluate(() => {
        const readme = document.querySelector('.readme-md')
        const layout = readme.querySelector('table[align="center"]')
        const cell = layout ? layout.querySelector('td') : null
        const badges = Array.from(readme.querySelectorAll('img')).filter((img) =>
            /^Badge /.test(img.getAttribute('alt') || '')
        )
        const centredColumn = Array.from(readme.querySelectorAll('th')).find(
            (th) => (th.textContent || '').trim() === 'Quota'
        )

        const tops = badges.map((b) => Math.round(b.getBoundingClientRect().top))

        return {
            layoutTable: Boolean(layout),
            tableDisplay: layout ? getComputedStyle(layout).display : null,
            cellAlign: cell ? getComputedStyle(cell).textAlign : null,
            badgeCount: badges.length,
            badgesOnOneLine: tops.length > 1 && tops.every((top) => Math.abs(top - tops[0]) <= 2),
            columnAlign: centredColumn ? getComputedStyle(centredColumn).textAlign : null,
            wrapped: readme.querySelectorAll('.tm-table').length,
            tables: readme.querySelectorAll('table').length,
            overflow: Math.round(document.documentElement.scrollWidth - document.documentElement.clientWidth),
        }
    })

    assert(shape.layoutTable, 'shape: the centred layout table did not survive')
    assert(
        shape.tableDisplay === 'table',
        `shape: the table is displayed as "${shape.tableDisplay}", so its cells no longer share a row`
    )
    assert(
        /center/.test(shape.cellAlign || ''),
        `shape: the centred cell reads as "${shape.cellAlign}" - a theme's table rule has overruled the readme`
    )
    assert(shape.badgeCount === 3, `shape: expected three badges, found ${shape.badgeCount}`)
    assert(shape.badgesOnOneLine, 'shape: the badges were put on separate lines, as photographs would be')
    assert(
        /center/.test(shape.columnAlign || ''),
        `shape: markdown's centred column reads as "${shape.columnAlign}"; that alignment is written as a style, and styles are stripped`
    )
    assert(
        shape.wrapped === shape.tables,
        `shape: ${shape.tables - shape.wrapped} table(s) lack the container a theme scrolls with`
    )
    assert(shape.overflow <= 1, `shape: the readme pushes the page sideways by ${shape.overflow}px`)

    // The same readme on a phone must not push the page sideways either.
    await page.setViewport({ width: 390, height: 900 })
    await read(page)
    const narrow = await page.evaluate(() =>
        Math.round(document.documentElement.scrollWidth - document.documentElement.clientWidth)
    )
    assert(narrow <= 1, `shape: on a narrow screen the readme pushes the page sideways by ${narrow}px`)

    await page.setViewport({ width: 1280, height: 900 })
}

function setLanguage(language) {
    const content = readFileSync(SETTINGS_FILE, 'utf8')

    writeFileSync(
        SETTINGS_FILE,
        /^language:.*$/m.test(content)
            ? content.replace(/^language:.*$/m, `language: ${language}`)
            : `language: ${language}\n${content}`
    )
    clearNavigationCache()
}

async function readLink(page) {
    await page.goto(`${BASE_URL}${PAGE_URL}`, { waitUntil: 'domcontentloaded' })

    return page.evaluate(() => {
        const link = document.querySelector('.readme-md__source-link')
        const paragraph = document.querySelector('.readme-md__source')
        const readme = document.querySelector('.readme-md')

        let beforeReadme = null
        if (paragraph && readme) {
            const text = (readme.textContent || '')
            beforeReadme = text.indexOf((paragraph.textContent || '').trim()) < text.indexOf('stored copy')
        }

        return {
            href: link ? link.getAttribute('href') : null,
            label: link ? (link.textContent || '').trim() : null,
            beforeReadme,
        }
    })
}

/**
 * The way back to the repository, which a readme seldom carries itself.
 */
async function assertRepositoryLink(page) {
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 0 })
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60 })

    // Whether the link appears is the page's business - it is the page that names
    // a repository - so all three placements are set there.
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true', sourcelink: 'start' })

    const start = await readLink(page)
    assert(start.href === `https://github.com/${REPOSITORY}`, `link: points at ${start.href}`)
    assert(start.label === 'View on GitHub', `link: reads "${start.label}"`)
    assert(start.beforeReadme, 'link: should come before the readme')

    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true', sourcelink: 'end' })
    const end = await readLink(page)
    assert(end.href !== null, 'link: disappeared when asked for below the readme')
    assert(end.beforeReadme === false, 'link: should come after the readme')

    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true', sourcelink: 'none' })
    assert((await readLink(page)).href === null, 'link: still shown after being switched off')

    // A page that says nothing about it gets the link, which is the useful
    // default for a page whose whole purpose is to show a readme.
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' })
    assert((await readLink(page)).href !== null, 'link: a page that said nothing about it lost the link')

    // The wording is a plugin setting, and follows the site language.
    setLanguage('de')

    const german = await readLink(page)
    assert(german.label === 'Auf GitHub ansehen', `link: on a German site it reads "${german.label}"`)

    setLanguage('en')

    // An author's own words are not second-guessed.
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60, link_label: 'Zum Repository' })
    const custom = await readLink(page)
    assert(custom.label === 'Zum Repository', `link: the setting was ignored, it reads "${custom.label}"`)
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

    // Already signed in: Typemill sends the login page on to the editor, and
    // there is no form to fill in.
    if (!page.url().includes('/tm/login')) {
        return
    }

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
 * arrives is asserted precisely in tests/api/readmemd.test.js, against the
 * endpoint the editor draws from; here it is only about the screens loading.
 *
 * Failed requests are read from the responses rather than from console text: a
 * console message for a failed resource does not say which resource it was, so
 * filtering it by name is not possible.
 */
/**
 * The refresh button in the page's readme tab.
 *
 * The readme is stored and only checked now and then, which is what keeps a page
 * working when GitHub does not answer - and also what stops a change on GitHub
 * from showing at once. The button is the way to ask for it now.
 *
 * Typemill's editor draws a meta tab with the Vue component named after it, so
 * this proves two things at once: that the component took the tab over, and that
 * the core's own fields are still drawn inside it.
 */
async function assertRefreshButton(page) {
    // Unreachable, so the answer is the failure - and the stored copy has to
    // survive being refreshed at the wrong moment.
    configure({ api_base: UNREACHABLE_API, fresh_minutes: 60, timeout_seconds: 2 })
    writePage({ repository: REPOSITORY, position: 'replace', droptitle: 'true' })
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 0 })

    await login(page)
    await page.goto(`${BASE_URL}/tm/content/visual${PAGE_URL}`, { waitUntil: 'networkidle2' })

    // Open the tab this plugin adds.
    const opened = await page.evaluate(() => {
        const tab = Array.from(document.querySelectorAll('a, button, li, span'))
            .find((node) => (node.textContent || '').trim().toLowerCase() === 'readme')

        if (!tab) {
            return false
        }

        tab.click()
        return true
    })
    assert(opened, 'refresh: no readme tab was offered in the editor')

    await page.waitForSelector('[data-readmemd-note], button', { timeout: 10000 }).catch(() => {})
    await new Promise((resolve) => setTimeout(resolve, 500))

    const tab = await page.evaluate(() => {
        const button = document.querySelector('[data-readmemd-refresh]')

        return {
            hasButton: Boolean(button),
            // The core's own fields have to still be there: the component wraps
            // the generic form rather than replacing it.
            hasRepositoryField: Boolean(
                document.querySelector('input[name="repository"], input#repository, [id*="repository"]')
            ),
            fieldCount: document.querySelectorAll('input, select, textarea').length,
        }
    })

    assert(tab.hasButton, 'refresh: the button is not in the readme tab')

    /*
     * The button must not sit against the text beside it. Worth measuring rather
     * than trusting: Typemill's admin stylesheet is a trimmed Tailwind build with
     * no gap-* utilities, so a class-based flex gap silently does nothing.
     */
    const spacing = await page.evaluate(() => {
        const button = document.querySelector('[data-readmemd-refresh]')
        const help = button.parentElement.querySelector('span')

        if (!help) {
            return { gap: null }
        }

        const b = button.getBoundingClientRect()
        const h = help.getBoundingClientRect()
        const panel = button.parentElement.getBoundingClientRect()

        return {
            // Side by side on a wide screen, so the gap is horizontal.
            gap: Math.round(h.left - b.right),
            padding: Math.round(b.left - panel.left),
            buttonHeight: Math.round(b.height),
        }
    })

    assert(spacing.gap !== null, 'refresh: no help text was rendered beside the button')
    assert(
        spacing.gap >= 12,
        `refresh: only ${spacing.gap}px between the button and the text beside it`
    )
    assert(
        spacing.padding >= 12,
        `refresh: the button sits ${spacing.padding}px from the edge of its panel`
    )
    assert(
        spacing.buttonHeight >= 32,
        `refresh: the button is only ${spacing.buttonHeight}px tall, so its padding was lost too`
    )
    assert(
        tab.hasRepositoryField || tab.fieldCount > 3,
        `refresh: the tab lost the fields it is supposed to keep (found ${tab.fieldCount} inputs)`
    )

    // Press it. GitHub is unreachable, so this must report the failure and say
    // the page is still complete - and the stored copy must be untouched.
    const said = await page.evaluate(async () => {
        const button = document.querySelector('[data-readmemd-refresh]')
        button.click()

        const deadline = Date.now() + 15000
        while (Date.now() < deadline) {
            const note = document.querySelector('[data-readmemd-note]')
            if (note && (note.textContent || '').trim() !== '') {
                return (note.textContent || '').trim()
            }
            await new Promise((r) => setTimeout(r, 200))
        }

        return null
    })

    assert(said !== null, 'refresh: pressing the button said nothing at all')
    assert(
        /stored copy is unchanged|nicht geantwortet/i.test(said),
        `refresh: expected to be told the stored copy survived, got "${said}"`
    )

    const stored = readFileSync(join(PLUGIN_CACHE, `${cacheKey(REPOSITORY)}.json`), 'utf8')
    assert(
        stored.includes(STORED_TEXT),
        'refresh: a failed refresh threw away the copy that was keeping the page filled'
    )

    // Now the point of the button: a stored copy that is still well inside its
    // freshness window would not be checked for another hour, and pressing the
    // button has to fetch anyway.
    configure({ api_base: 'https://api.github.com', fresh_minutes: 600, timeout_seconds: 10 })
    seedStoredCopy(`# Stored readme\n\n${STORED_TEXT}\n`, { checkedSecondsAgo: 0 })

    await page.goto(`${BASE_URL}/tm/content/visual${PAGE_URL}`, { waitUntil: 'networkidle2' })
    await page.evaluate(() => {
        const tab = Array.from(document.querySelectorAll('a, button, li, span'))
            .find((node) => (node.textContent || '').trim().toLowerCase() === 'readme')
        if (tab) tab.click()
    })
    await new Promise((resolve) => setTimeout(resolve, 500))

    const fetched = await page.evaluate(async () => {
        const button = document.querySelector('[data-readmemd-refresh]')
        if (!button) return null

        button.click()

        const deadline = Date.now() + 20000
        while (Date.now() < deadline) {
            const note = document.querySelector('[data-readmemd-note]')
            if (note && (note.textContent || '').trim() !== '') {
                return (note.textContent || '').trim()
            }
            await new Promise((r) => setTimeout(r, 200))
        }

        return null
    })

    if (fetched === null || !/Fetched|Geladen/i.test(fetched)) {
        console.log(`note: GitHub did not serve the readme just now ("${fetched}"); the forced fetch was not proven`)
        return
    }

    // The page must now show what was fetched, not the copy that was fresh.
    const shown = await read(page)
    assert(
        shown.readmeText !== null && !shown.readmeText.includes(STORED_TEXT),
        'refresh: the page still shows the old copy after a successful refresh'
    )
    assert(
        shown.origin === 'fresh',
        `refresh: expected the page to serve the newly stored copy, got origin "${shown.origin}"`
    )
}

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
        assert(plugins.includes('Readme MD'), 'the plugin does not appear on the plugins screen')

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
        const readme = document.querySelector('.readme-md')
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
        console.log('ok: readme md (a stored copy carries the page when GitHub cannot be reached)')

        await assertPageFromTheFormerTab(page)
        console.log('ok: readme md (a page saved under the former tab still works)')

        await assertPlacement(page)
        console.log('ok: readme md (replace, append and prepend)')

        await assertReadmeKeepsItsShape(page)
        console.log('ok: readme md (centred tables, badges in a row, aligned columns)')

        await assertRepositoryLink(page)
        console.log('ok: readme md (the link back to the repository)')

        await assertNothingStored(page)
        console.log('ok: readme md (nothing stored: the page keeps its own content)')

        await assertPageWithoutRepository(page)
        console.log('ok: readme md (a page naming no repository is untouched)')

        const proven = await assertLiveFetch(page)
        console.log(`ok: readme md (live fetch${proven ? '' : ', skipped'})`)

        // Last, because these sign in and the pages above are read as a visitor.
        await assertAdminAccepts(page)
        console.log('ok: readme md (the plugins screen and the page meta tab)')

        await assertRefreshButton(page)
        console.log('ok: readme md (the refresh button in the readme tab)')
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
