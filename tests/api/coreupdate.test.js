import { describe, it, expect, beforeAll } from 'vitest'
import { createSession, apiGet, apiPost } from './helpers/auth.js'

/**
 * API surface of the core updater.
 *
 * Everything here is side-effect free: the status endpoint only reads, and the
 * rollback calls use names that must be rejected before anything is touched.
 * The actual swap is covered by the PHPUnit tests, which run against synthetic
 * cores in a temporary directory.
 */
const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER
const PASSWORD = process.env.TM_PASSWORD

const configured = USERNAME && PASSWORD

describe('Core update API', () => {
    let session

    beforeAll(async () => {
        if (configured) {
            session = await createSession(BASE_URL, USERNAME, PASSWORD)
        }
    })

    it.skipIf(!configured)('reports the installed version and environment checks', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/coreupdate/status?check=0`)
        expect(response.status).toBe(200)

        const body = await response.json()

        expect(body.installed).toMatch(/^\d+\.\d+\.\d+$/)
        expect(body.php_version).toMatch(/^\d+\.\d+/)
        expect(Array.isArray(body.preflight)).toBe(true)
        expect(Array.isArray(body.backups)).toBe(true)
        expect(typeof body.blocked).toBe('boolean')
    })

    it.skipIf(!configured)('checks everything the swap depends on', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/coreupdate/status?check=0`)
        const body = await response.json()

        const ids = body.preflight.map((check) => check.id)
        expect(ids).toContain('ziparchive')
        expect(ids).toContain('system_layout')
        expect(ids).toContain('vendor_location')
        expect(ids).toContain('root_writable')
        expect(ids).toContain('disk_space')

        for (const check of body.preflight) {
            expect(typeof check.ok).toBe('boolean')
            expect(typeof check.blocking).toBe('boolean')
            expect(typeof check.detail).toBe('string')
        }
    })

    it.skipIf(!configured)('is able to update this installation', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/coreupdate/status?check=0`)
        const body = await response.json()

        const failing = body.preflight.filter((check) => !check.ok && check.blocking)
        expect(
            failing,
            `blocking checks failed: ${failing.map((c) => `${c.id}: ${c.detail}`).join(' | ')}`
        ).toEqual([])
    })

    it.skipIf(!configured)('rejects a backup name that tries to escape the working directory', async () => {
        for (const name of ['../../etc', 'backup-../../etc', '/etc/passwd', 'not-a-backup']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/coreupdate/rollback`, { backup: name })
            expect(response.status, `expected rejection for ${name}`).toBe(404)
        }
    })

    it.skipIf(!configured)('requires an authenticated session', async () => {
        const response = await fetch(`${BASE_URL}/api/v1/coreupdate/status`)
        expect(response.status).toBeGreaterThanOrEqual(400)
    })
})
