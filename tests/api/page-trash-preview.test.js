import { describe, it, expect, beforeAll, afterAll } from 'vitest'
import { createSession, apiGet, apiPost, apiDelete } from './helpers/auth.js'
import { createMergelyTestPage, cleanupMergelyTestPage, MERGELY_TEST_URL } from './helpers/mergelyTestPage.js'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER
const PASSWORD = process.env.TM_PASSWORD

const configured = Boolean(USERNAME && PASSWORD)
const MARKDOWN = '# Trash Preview Test\n\nPage delete preview marker: tm-page-trash-preview-ok.\n'

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

afterAll(() => {
    cleanupMergelyTestPage()
})

function requireSession() {
    if (sessionError) throw sessionError
}

describe('Page delete → recycle bin preview', () => {
    it.skipIf(!configured)('stores deleted pages in trash with page markdown preview', async () => {
        requireSession()

        createMergelyTestPage()
        await fetch(`${BASE_URL}/`).catch(() => {})

        const saveResp = await apiPost(session, `${BASE_URL}/api/v1/versions/page/save`, {
            url: MERGELY_TEST_URL,
            markdown: MARKDOWN,
        })
        expect(saveResp.status).toBe(200)

        const deleteResp = await apiDelete(session, `${BASE_URL}/api/v1/versions/article`, {
            url: MERGELY_TEST_URL,
        })
        expect(deleteResp.status).toBe(200)

        const systemResp = await apiGet(session, `${BASE_URL}/api/v1/versions/system`)
        expect(systemResp.status).toBe(200)

        const { trash = [] } = await systemResp.json()
        const entry = trash.find((item) => item.url === MERGELY_TEST_URL)
        expect(entry, 'deleted page should appear in trash').toBeTruthy()
        expect(entry.record_type).toBe('page')
        expect(entry.entry_kind).toBe('page')
        expect(entry.previewable).toBe(true)

        const detailResp = await apiGet(
            session,
            `${BASE_URL}/api/v1/versions/trash/version?record_id=${encodeURIComponent(entry.record_id)}&record_type=page&version_id=${encodeURIComponent(entry.version_id)}`
        )
        expect(detailResp.status).toBe(200)

        const { version } = await detailResp.json()
        expect(version.previewable).toBe(true)
        expect(version.preview_kind).toBe('page')
        expect(version.markdown).toContain('tm-page-trash-preview-ok')
        expect(version.rendered_html || '').not.toBe('')

        await apiDelete(session, `${BASE_URL}/api/v1/versions/trash/entry`, {
            record_id: entry.record_id,
            record_type: 'page',
            version_id: entry.version_id,
        })
    })
})
