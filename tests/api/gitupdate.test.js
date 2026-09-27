import { describe, it, expect, beforeAll } from 'vitest'
import { createSession, apiGet, apiPost } from './helpers/auth.js'

/**
 * The git updater must not touch a server that has not asked for an item, and
 * must not answer an update route without a session. The swap itself is covered
 * by PHPUnit against a temporary tree.
 */
const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER
const PASSWORD = process.env.TM_PASSWORD
const configured = USERNAME && PASSWORD

describe('Git update API', () => {
    let session

    beforeAll(async () => {
        if (configured) {
            session = await createSession(BASE_URL, USERNAME, PASSWORD)
        }
    })

    it.skipIf(!configured)('reports settings without calling GitHub when check is off', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/gitupdate/status?check=0`)
        expect(response.status).toBe(200)

        const body = await response.json()
        expect(body.repository).toBe('moritzfl/Typemill-Plugins')
        expect(body.branch).toBe('main')
        expect(body.head).toBeNull()
        expect(body).not.toHaveProperty('token')
    })

    it.skipIf(!configured)('rejects a name that could escape the plugins directory', async () => {
        for (const slug of ['../evil', 'a/b', '', '.hidden']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/gitupdate/run`, {
                kind: 'plugin',
                slug,
            })
            expect(response.status, `expected rejection for ${JSON.stringify(slug)}`).toBe(422)
        }
    })

    it.skipIf(!configured)('refuses to update a plugin that is not installed', async () => {
        const response = await apiPost(session, `${BASE_URL}/api/v1/gitupdate/run`, {
            kind: 'plugin',
            slug: 'notinstalledplugin',
        })
        expect(response.status).toBe(404)
    })

    it.skipIf(!configured)('requires an authenticated session', async () => {
        const status = await fetch(`${BASE_URL}/api/v1/gitupdate/status?check=0`)
        expect(status.status).toBeGreaterThanOrEqual(400)

        const run = await fetch(`${BASE_URL}/api/v1/gitupdate/run`, {
            method: 'POST',
            redirect: 'manual',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kind: 'plugin', slug: 'files' }),
        })
        expect(run.status).toBeGreaterThanOrEqual(300)
    })
})
