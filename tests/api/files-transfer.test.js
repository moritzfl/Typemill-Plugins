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

describe('Files transfer API', () => {
    it.skipIf(!configured)('moves a file into a folder', async () => {
        requireSession()

        const folderName = `tm-move-${Date.now()}`
        const fileName = `tm-move-file-${Date.now()}.txt`
        const payload = Buffer.from('move test', 'utf8').toString('base64')

        await apiPost(session, `${BASE_URL}/api/v1/files/folder`, {
            path: '',
            name: folderName,
        })

        await apiPost(session, `${BASE_URL}/api/v1/files/upload`, {
            path: '',
            name: fileName,
            file: `data:text/plain;base64,${payload}`,
        })

        try {
            const moveResp = await apiPost(session, `${BASE_URL}/api/v1/files/transfer`, {
                source_path: fileName,
                destination_path: folderName,
                mode: 'move',
            })
            expect(moveResp.status).toBe(200)

            const rootBrowse = await apiGet(session, `${BASE_URL}/api/v1/files/browse?path=`)
            const rootListing = await rootBrowse.json()
            expect((rootListing.files || []).some((file) => file.name === fileName)).toBe(false)

            const folderBrowse = await apiGet(session, `${BASE_URL}/api/v1/files/browse?path=${encodeURIComponent(folderName)}`)
            const folderListing = await folderBrowse.json()
            expect((folderListing.files || []).some((file) => file.name === fileName)).toBe(true)
        } finally {
            await apiDelete(session, `${BASE_URL}/api/v1/files/entry`, { path: `${folderName}/${fileName}` })
            await apiDelete(session, `${BASE_URL}/api/v1/files/entry`, { path: folderName })
        }
    })
})
