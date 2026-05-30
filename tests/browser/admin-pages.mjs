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
            consoleErrors.push(message.text())
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

    const criticalErrors = [...pageErrors, ...consoleErrors].filter((entry) => {
        return !/favicon|DevTools|Failed to load resource.*404/i.test(entry)
    })

    assert(criticalErrors.length === 0, `${spec.name} console errors:\n${criticalErrors.join('\n')}`)
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
    } finally {
        await browser.close()
    }
}

main().catch((error) => {
    console.error(error.message || error)
    process.exit(1)
})
