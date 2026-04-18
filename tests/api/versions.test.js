import { describe, it, expect, beforeAll, afterAll } from 'vitest'
import { createSession, apiGet, apiPost, apiDelete }  from './helpers/auth.js'
import { createLargeFolder, cleanupLargeFolder, LARGE_FOLDER_URL } from './helpers/largeFolder.js'
import { createMergelyTestPage, cleanupMergelyTestPage, MERGELY_TEST_URL } from './helpers/mergelyTestPage.js'

const BASE_URL  = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME  = process.env.TM_USER
const PASSWORD  = process.env.TM_PASSWORD

const configured = Boolean(USERNAME && PASSWORD)
const requireConfiguredAuth = process.env.CI === 'true' || process.env.REQUIRE_TM_AUTH === 'true'

if (requireConfiguredAuth && !configured) {
    throw new Error('TM_USER and TM_PASSWORD are required for authenticated API tests in CI.')
}

// ---------------------------------------------------------------------------
// Access control — these endpoints must refuse unauthenticated calls.
// These tests always run regardless of whether credentials are configured.
// ---------------------------------------------------------------------------

describe('Access control', () => {
    const protectedEndpoints = [
        // page-scoped endpoints
        { method: 'GET',    path: '/api/v1/versions/page?url=/' },
        { method: 'GET',    path: '/api/v1/versions/page/version?url=/&version_id=x' },
        { method: 'GET',    path: '/api/v1/versions/page/current?url=/' },
        { method: 'POST',   path: '/api/v1/versions/page/restore' },
        { method: 'POST',   path: '/api/v1/versions/page/save' },
        { method: 'DELETE', path: '/api/v1/versions/article' },
        // system / trash endpoints
        { method: 'GET',    path: '/api/v1/versions/system' },
        { method: 'POST',   path: '/api/v1/versions/system' },
        { method: 'DELETE', path: '/api/v1/versions/trash' },
        { method: 'DELETE', path: '/api/v1/versions/trash/entry' },
        { method: 'POST',   path: '/api/v1/versions/trash/restore' },
        { method: 'GET',    path: '/api/v1/versions/trash/version?record_id=x&version_id=x' },
        { method: 'GET',    path: '/api/v1/versions/trash/download?record_id=x&version_id=x' },
    ]

    for (const { method, path } of protectedEndpoints) {
        it(`${method} ${path} returns 401 without auth`, async () => {
            const headers = { 'Content-Type': 'application/json' }

            // Typemill's SecurityMiddleware rejects POST requests that lack a
            // Referer header before they even reach ApiAuthentication (redirects
            // to the login page with 302). Add the header so these tests exercise
            // the actual authentication layer and expect 401.
            if (method === 'POST') {
                headers['Referer'] = `${BASE_URL}/`
            }

            const resp = await fetch(`${BASE_URL}${path}`, { method, headers })
            expect(resp.status).toBe(401)
        })
    }
})

// ---------------------------------------------------------------------------
// Authenticated tests — skipped when TM_USER / TM_PASSWORD are not set.
// Copy .env.test.example to .env.test and fill in your credentials to enable.
//
// beforeAll stores any setup error rather than throwing, so that a login
// failure does not cause the always-run access-control tests above to be
// skipped by Vitest's file-level error handling.
// ---------------------------------------------------------------------------

let session
let sessionError

beforeAll(async () => {
    if (!configured) return
    try {
        session = await createSession(BASE_URL, USERNAME, PASSWORD)
    } catch (err) {
        sessionError = err
    }
})

/** Fails the calling test with the stored setup error if auth setup failed. */
function requireSession() {
    if (sessionError) throw sessionError
}

// ---------------------------------------------------------------------------

describe('GET /api/v1/versions/page', () => {
    it.skipIf(!configured)('returns a well-formed response for an existing URL', async () => {
        requireSession()
        const resp = await apiGet(session, `${BASE_URL}/api/v1/versions/page?url=/`)
        // 200 when the page has versions, 4xx when page not found or has none
        expect([200, 400, 404, 422]).toContain(resp.status)

        if (resp.status === 200) {
            const data = await resp.json()
            expect(data).toHaveProperty('versions')
            expect(Array.isArray(data.versions)).toBe(true)
            expect(data).toHaveProperty('pageid')
        }
    })
})

describe('GET /api/v1/versions/trash', () => {
    it.skipIf(!configured)('returns a list (empty array when trash is empty)', async () => {
        requireSession()
        const resp = await apiGet(session, `${BASE_URL}/api/v1/versions/trash`)
        // Some Typemill configurations return 404 when the trash subsystem
        // is not yet initialised; otherwise expect an empty or populated array.
        if (resp.status === 404) return

        expect(resp.status).toBe(200)
        const data = await resp.json()
        expect(Array.isArray(data)).toBe(true)

        for (const entry of data) {
            expect(entry).toHaveProperty('record_id')
            expect(entry).toHaveProperty('version_id')
            expect(entry).toHaveProperty('title')
            expect(entry).toHaveProperty('deleted_at')
        }
    })
})

describe('DELETE /api/v1/versions/article', () => {
    it.skipIf(!configured)('returns a client error for a non-existent URL', async () => {
        requireSession()
        const resp = await apiDelete(session, `${BASE_URL}/api/v1/versions/article`, {
            url: '/this-page-definitely-does-not-exist-phpunit',
            item_id: 'nonexistent',
        })
        expect(resp.status).toBeGreaterThanOrEqual(400)
        expect(resp.status).toBeLessThan(500)
    })
})

// ---------------------------------------------------------------------------
// Large-folder tests — self-contained: create a 501-file fixture on disk
// before the suite runs and clean up afterwards.
// ---------------------------------------------------------------------------

describe('DELETE /api/v1/versions/article — large folder', () => {
    beforeAll(async () => {
        if (!configured || sessionError) return
        createLargeFolder()
        // Pre-warm the navigation cache.  After clearNavigationCache() the very
        // next HTTP request triggers a cold rebuild from disk.  On some systems
        // that first response is unreliable (PHP display_errors output, first-run
        // navigation build quirks, etc.) and can cause $item->elementType to be
        // wrong, bypassing the folder-snapshot path.  A throw-away GET here
        // ensures the 409-test's DELETE always hits a warm, correctly-built cache.
        await fetch(`${BASE_URL}/`).catch(() => {})
    })

    afterAll(() => {
        cleanupLargeFolder()
    })

    it.skipIf(!configured)('returns 409 with too_large flag when folder snapshot exceeds limits', async () => {
        requireSession()
        const resp = await apiDelete(session, `${BASE_URL}/api/v1/versions/article`, {
            url: LARGE_FOLDER_URL,
        })
        expect(resp.status).toBe(409)

        const data = await resp.json()
        expect(data.too_large).toBe(true)
        expect(typeof data.message).toBe('string')
        expect(data.message.length).toBeGreaterThan(0)
    })

    it.skipIf(!configured)('force_delete bypasses snapshot and proceeds', async () => {
        requireSession()
        const resp = await apiDelete(session, `${BASE_URL}/api/v1/versions/article`, {
            url: LARGE_FOLDER_URL,
            force_delete: true,
        })
        expect([200, 204, 404]).toContain(resp.status)
    })
})

// ---------------------------------------------------------------------------
// Mergely diff view — tests that verify the two core interactions:
//
//   1. Selecting a version in the diff view replaces the left side with the
//      version's stored markdown (GET /api/v1/versions/page/version).
//
//   2. Applying the edited right side saves it as the new draft
//      (POST /api/v1/versions/page/save, verified via GET /api/v1/versions/page/current).
//
// Both tests share a single test page created in beforeAll.  They run in
// declaration order (Vitest is serial within a file), so the version-selection
// test runs before the apply-right-side test saves new content — keeping the
// assertions independent.
// ---------------------------------------------------------------------------

// Content used in the version-selection test.  Saved once in beforeAll.
const VERSION_SELECTION_CONTENT = '# Diff View Test\n\nThis content validates the left-side version selection.\n'

// Content used in the apply-right-side test.  Contains a unique marker that
// must survive the markdown array round-trip Typemill performs on save/read.
const APPLY_RIGHT_CONTENT = '# Diff View Test\n\nApply-right-side marker: mergely-apply-right-side-test.\n'

describe('Mergely diff view', () => {
    // Version ID captured in beforeAll after saving VERSION_SELECTION_CONTENT.
    let versionSelectionId

    beforeAll(async () => {
        if (!configured || sessionError) return

        createMergelyTestPage()

        // Pre-warm navigation so the API immediately recognises the new page.
        await fetch(`${BASE_URL}/`).catch(() => {})

        // Save VERSION_SELECTION_CONTENT so we have a version entry to select.
        const saveResp = await apiPost(session, `${BASE_URL}/api/v1/versions/page/save`, {
            url: MERGELY_TEST_URL,
            markdown: VERSION_SELECTION_CONTENT,
        })
        if (saveResp.status !== 200) return

        // Capture the version ID for use in the selection test.
        const listResp = await apiGet(session, `${BASE_URL}/api/v1/versions/page?url=${MERGELY_TEST_URL}`)
        if (listResp.status !== 200) return
        const { versions = [] } = await listResp.json()
        const contentVersion = versions.find(v => !v.event_only)
        if (contentVersion) versionSelectionId = contentVersion.id
    })

    afterAll(() => {
        cleanupMergelyTestPage()
    })

    // -------------------------------------------------------------------------
    // Test 1: version selection replaces the left side
    //
    // The frontend calls GET /api/v1/versions/page/version and sets the Mergely
    // left side to `detail.version.markdown`.  We verify that the endpoint
    // returns the exact markdown that was stored for that version — not the
    // current draft, which may differ.
    // -------------------------------------------------------------------------

    describe('version selection (left side)', () => {
        it.skipIf(!configured)('GET /api/v1/versions/page/version returns the stored markdown for the requested version', async () => {
            requireSession()
            expect(versionSelectionId, 'setup did not produce a content version — check beforeAll').toBeDefined()

            const resp = await apiGet(
                session,
                `${BASE_URL}/api/v1/versions/page/version?url=${MERGELY_TEST_URL}&version_id=${versionSelectionId}`
            )
            expect(resp.status).toBe(200)

            const { version } = await resp.json()
            expect(version).toBeDefined()
            // The version record stores the exact markdown that was posted — not
            // the round-tripped disk content — so we can do an exact comparison.
            expect(version.markdown).toBe(VERSION_SELECTION_CONTENT)
        })
    })

    // -------------------------------------------------------------------------
    // Test 2: applying the edited right side replaces the draft
    //
    // The frontend POSTs the right-side content to /api/v1/versions/page/save.
    // We verify by reading the current draft back via GET /api/v1/versions/page/current.
    // Typemill passes the saved content through a markdown-array round-trip, so
    // we match on a unique substring rather than the full string.
    // -------------------------------------------------------------------------

    describe('apply edited right side (save draft)', () => {
        it.skipIf(!configured)('POST /api/v1/versions/page/save replaces the current draft with the posted content', async () => {
            requireSession()

            const saveResp = await apiPost(session, `${BASE_URL}/api/v1/versions/page/save`, {
                url: MERGELY_TEST_URL,
                markdown: APPLY_RIGHT_CONTENT,
            })
            expect(saveResp.status).toBe(200)

            const currentResp = await apiGet(
                session,
                `${BASE_URL}/api/v1/versions/page/current?url=${MERGELY_TEST_URL}`
            )
            expect(currentResp.status).toBe(200)

            const { markdown } = await currentResp.json()
            // Check for the unique marker to confirm the right-side content was
            // persisted (Typemill's array round-trip preserves paragraph text).
            expect(markdown).toContain('mergely-apply-right-side-test')
        })
    })
})
