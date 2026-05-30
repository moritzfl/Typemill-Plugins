import { describe, it, expect, beforeAll } from 'vitest'
import { createSession, apiGet, apiPost, apiDelete } from './helpers/auth.js'

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER
const PASSWORD = process.env.TM_PASSWORD

const configured = Boolean(USERNAME && PASSWORD)

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

function requireSession() {
    if (sessionError) throw sessionError
}

describe('Files preview API', () => {
    it.skipIf(!configured)('marks previewable files in browse and serves preview meta', async () => {
        requireSession()

        const filename = `tm-preview-test-${Date.now()}.txt`
        const content = '# Preview test\n\nHello from files preview.'
        const payload = Buffer.from(content, 'utf8').toString('base64')

        const uploadResp = await apiPost(session, `${BASE_URL}/api/v1/files/upload`, {
            path: '',
            name: filename,
            file: `data:text/plain;base64,${payload}`,
        })
        expect(uploadResp.status).toBe(200)

        try {
            const browseResp = await apiGet(session, `${BASE_URL}/api/v1/files/browse?path=`)
            expect(browseResp.status).toBe(200)

            const listing = await browseResp.json()
            const file = (listing.files || []).find((entry) => entry.name === filename)
            expect(file, `Expected uploaded file ${filename} in browse listing`).toBeTruthy()
            expect(file.previewable).toBe(true)

            const metaResp = await apiGet(
                session,
                `${BASE_URL}/api/v1/preview/file/meta?path=${encodeURIComponent(filename)}`
            )
            expect(metaResp.status).toBe(200)

            const { preview } = await metaResp.json()
            expect(preview.previewable).toBe(true)
            expect(preview.preview_kind).toBe('text')
            expect(preview.markdown).toContain('Hello from files preview')
        } finally {
            await apiDelete(session, `${BASE_URL}/api/v1/files/entry`, { path: filename })
        }
    })
})
