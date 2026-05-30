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

describe('Files manager delete → versions trash', () => {
    it.skipIf(!configured)('records deleted files in the recycle bin', async () => {
        requireSession()

        const filename = `tm-trash-test-${Date.now()}.txt`
        const relativePath = filename
        const payload = Buffer.from('recycle bin integration test', 'utf8').toString('base64')

        const uploadResp = await apiPost(session, `${BASE_URL}/api/v1/files/upload`, {
            path: '',
            name: filename,
            file: `data:text/plain;base64,${payload}`,
        })
        expect(uploadResp.status).toBe(200)

        const deleteResp = await apiDelete(session, `${BASE_URL}/api/v1/files/entry`, {
            path: relativePath,
        })
        expect(deleteResp.status).toBe(200)

        const trashResp = await apiGet(session, `${BASE_URL}/api/v1/versions/system`)
        expect(trashResp.status).toBe(200)

        const { trash = [] } = await trashResp.json()
        const match = trash.find((entry) => {
            const path = String(entry.path ?? '')
            const title = String(entry.title ?? '')
            return path.includes(filename) || title === filename
        })

        expect(match, `Expected trash entry for ${filename}`).toBeTruthy()
        expect(match.item_type).toMatch(/asset/)

        if (match?.record_id && match?.version_id) {
            await apiDelete(session, `${BASE_URL}/api/v1/versions/trash/entry`, {
                record_id: match.record_id,
                version_id: match.version_id,
                record_type: 'asset',
            })
        }
    })
})
