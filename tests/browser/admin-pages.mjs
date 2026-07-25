/**
 * Browser smoke test for admin plugin pages (Files + Recycle Bin).
 *
 * Run inside the Typemill Docker container:
 *   npm run test:browser
 */
import puppeteer from 'puppeteer'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER || 'admin'
const PASSWORD = process.env.TM_PASSWORD || 'Test1234!'

const pages = [
    {
        name: 'Files',
        path: '/tm/files',
        readySelector: '.tm-files',
        contentSelector: '.tm-files-content',
    },
    {
        name: 'Recycle Bin',
        path: '/tm/versions',
        readySelector: '.tm-trash',
        contentSelector: '.tm-trash-content',
    },
]

function assert(condition, message) {
    if (!condition) {
        throw new Error(message)
    }
}

async function login(page) {
    await page.goto(`${BASE_URL}/tm/login`, { waitUntil: 'networkidle2' })

    await page.waitForSelector('input[name="username"]', { timeout: 15000 })
    await page.type('input[name="username"]', USERNAME, { delay: 10 })
    await page.type('input[name="password"]', PASSWORD, { delay: 10 })

    const honeyField = await page.$('input[name="personal-honey-mail"]')
    if (honeyField) {
        await honeyField.type('', { delay: 0 })
    }

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type="submit"], input[type="submit"]'),
    ])

    const url = page.url()
    assert(!url.includes('/tm/login'), `Login failed, still on login page: ${url}`)
}

async function assertPageLoads(page, spec) {
    const consoleErrors = []
    const pageErrors = []

    page.on('console', (message) => {
        if (message.type() === 'error') {
            // Append the source URL so failed resource loads can be filtered precisely.
            const location = message.location()?.url || ''
            consoleErrors.push(`${message.text()} ${location}`.trim())
        }
    })
    page.on('pageerror', (error) => {
        pageErrors.push(error.message)
    })

    await page.goto(`${BASE_URL}${spec.path}`, { waitUntil: 'networkidle2' })
    await page.waitForSelector(spec.readySelector, { timeout: 15000 })
    await page.waitForSelector(spec.contentSelector, { timeout: 15000 })

    const loadingVisible = await page.evaluate((selector) => {
        const root = document.querySelector(selector)
        if (!root) {
            return true
        }
        const loading = root.querySelector('.tm-files-loading, .tm-trash-loading')
        return loading ? loading.offsetParent !== null : false
    }, spec.contentSelector)

    assert(!loadingVisible, `${spec.name} stayed in loading state`)

    const hasMountedContent = await page.evaluate((selector) => {
        const root = document.querySelector(selector)
        if (!root) {
            return false
        }

        return Boolean(
            root.querySelector('.tm-files-table, .tm-files-empty, .tm-trash-table, .tm-trash-empty')
        )
    }, spec.contentSelector)

    assert(hasMountedContent, `${spec.name} did not render list/empty content`)

    // Only favicon load failures are harmless; any other failed resource load
    // (e.g. a missing plugin JS/CSS asset) must fail the smoke test.
    const criticalErrors = [...pageErrors, ...consoleErrors].filter((entry) => {
        if (/DevTools/i.test(entry)) return false
        if (/Failed to load resource/i.test(entry) && /favicon/i.test(entry)) return false
        return true
    })

    assert(criticalErrors.length === 0, `${spec.name} console errors:\n${criticalErrors.join('\n')}`)
}

/**
 * Find a real content page to open in the editor.
 *
 * Discovered from the editor's own navigation rather than hardcoded, so the
 * test does not depend on which demo content the instance was seeded with.
 */
async function findFirstEditablePage(page) {
    await page.goto(`${BASE_URL}/tm/content/visual`, { waitUntil: 'networkidle2' })
    await page.waitForSelector('#publisher', { timeout: 15000 })

    return page.evaluate(() => {
        const isPageLink = (href) =>
            href
            && href.includes('/tm/content/')
            && !/\/tm\/content\/(visual|raw)\/?(\?.*)?$/.test(href)

        const link = Array.from(document.querySelectorAll('a[href]'))
            .map((anchor) => anchor.getAttribute('href'))
            .find(isPageLink)

        if (!link) {
            return null
        }

        return link.startsWith('http') ? new URL(link).pathname : link
    })
}

/**
 * The versions plugin replaces the core publisher's deleteArticle so deleted
 * pages are snapshotted into the recycle bin first. That override patches core
 * Vue internals, so it can break silently when core, Vue, or the Vue build
 * flavour changes - it already did once, in production Vue builds, where
 * app._instance is not assigned.
 *
 * The check is behavioural: tmaxios.delete is swapped for a spy that records
 * its arguments and returns a promise that never settles, so no request is ever
 * issued and no page is deleted.
 */
async function assertDeleteOverride(page, editorPath, label) {
    const consoleErrors = []
    const pageErrors = []
    const onConsole = (message) => {
        if (message.type() === 'error') {
            const location = message.location()?.url || ''
            consoleErrors.push(`${message.text()} ${location}`.trim())
        }
    }
    const onPageError = (error) => pageErrors.push(error.message)

    page.on('console', onConsole)
    page.on('pageerror', onPageError)

    try {
        await page.goto(`${BASE_URL}${editorPath}`, { waitUntil: 'networkidle2' })
        await page.waitForSelector('#publisher', { timeout: 15000 })

        // The override attaches on a timeout, so wait for it instead of sleeping.
        await page
            .waitForFunction(
                () => typeof publisher !== 'undefined'
                    && publisher
                    && publisher._versionsDeleteOverridden === true,
                { timeout: 10000 }
            )
            .catch(() => {
                throw new Error(`${label}: versions delete override was never attached`)
            })

        const result = await page.evaluate(() => {
            const instance = publisher._instance
                || (publisher._container && publisher._container._vnode
                    ? publisher._container._vnode.component
                    : null)

            if (!instance || !instance.ctx) {
                return { error: 'publisher instance could not be resolved' }
            }

            const deleteArticle = instance.ctx.deleteArticle
            if (typeof deleteArticle !== 'function') {
                return { error: 'deleteArticle is not a function' }
            }

            const originalDelete = tmaxios.delete
            const captured = []
            tmaxios.delete = function (url, config) {
                captured.push({ url: url, data: config && config.data ? config.data : null })
                return new Promise(function () {})
            }

            let threw = null
            try {
                deleteArticle.call(instance.proxy, false)
            } catch (error) {
                threw = String(error && error.message ? error.message : error)
            } finally {
                tmaxios.delete = originalDelete
            }

            return {
                threw: threw,
                calledUrl: captured.length ? captured[0].url : null,
                payloadKeys: captured.length && captured[0].data ? Object.keys(captured[0].data) : [],
            }
        })

        assert(!result.error, `${label}: ${result.error}`)
        assert(!result.threw, `${label}: deleteArticle threw: ${result.threw}`)
        assert(
            result.calledUrl === '/api/v1/versions/article',
            `${label}: deleteArticle called ${result.calledUrl}, expected /api/v1/versions/article`
        )
        assert(
            result.payloadKeys.includes('force_delete'),
            `${label}: delete payload lacks force_delete, so the snapshot-too-large `
                + `confirmation is unreachable (payload: ${result.payloadKeys.join(', ') || 'none'})`
        )

        const criticalErrors = [...pageErrors, ...consoleErrors].filter((entry) => {
            if (/DevTools/i.test(entry)) return false
            if (/Failed to load resource/i.test(entry) && /favicon/i.test(entry)) return false
            return true
        })

        assert(criticalErrors.length === 0, `${label} console errors:\n${criticalErrors.join('\n')}`)
    } finally {
        page.off('console', onConsole)
        page.off('pageerror', onPageError)
    }
}

async function main() {
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
        await login(page)

        for (const spec of pages) {
            await assertPageLoads(page, spec)
            console.log(`ok: ${spec.name}`)
        }

        const editorPath = await findFirstEditablePage(page)
        assert(editorPath, 'Could not find a content page link in the editor navigation')

        for (const editor of ['visual', 'raw']) {
            const path = editorPath.replace(/\/tm\/content\/(visual|raw)\//, `/tm/content/${editor}/`)
            await assertDeleteOverride(page, path, `Editor delete override (${editor})`)
            console.log(`ok: Editor delete override (${editor})`)
        }
    } finally {
        await browser.close()
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
