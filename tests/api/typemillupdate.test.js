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
        const response = await apiGet(session, `${BASE_URL}/api/v1/typemillupdate/status?check=0`)
        expect(response.status).toBe(200)

        const body = await response.json()

        expect(body.installed).toMatch(/^\d+\.\d+\.\d+$/)
        expect(body.php_version).toMatch(/^\d+\.\d+/)
        expect(Array.isArray(body.preflight)).toBe(true)
        expect(Array.isArray(body.backups)).toBe(true)
        expect(typeof body.blocked).toBe('boolean')
        expect(Array.isArray(body.plugins)).toBe(true)
        expect(typeof body.plugin_blocked).toBe('boolean')

        // The panel hides the actions it may not use, so it has to be told.
        expect(body.can_update).toBe(true)
    })

    it.skipIf(!configured)('checks everything the swap depends on', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/typemillupdate/status?check=0`)
        const body = await response.json()

        const ids = body.preflight.map((check) => check.id)
        expect(ids).toContain('ziparchive')
        expect(ids).toContain('system_layout')
        expect(ids).toContain('vendor_location')
        expect(ids).toContain('root_writable')
        expect(ids).toContain('usable_space')

        for (const check of body.preflight) {
            expect(typeof check.ok).toBe('boolean')
            expect(typeof check.blocking).toBe('boolean')
            expect(typeof check.detail).toBe('string')
        }
    })

    it.skipIf(!configured)('is able to update this installation', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/typemillupdate/status?check=0`)
        const body = await response.json()

        const failing = body.preflight.filter((check) => !check.ok && check.blocking)
        expect(
            failing,
            `blocking checks failed: ${failing.map((c) => `${c.id}: ${c.detail}`).join(' | ')}`
        ).toEqual([])
        expect(body.plugin_blocked).toBe(false)
    })

    it.skipIf(!configured)('rejects a backup name that tries to escape the working directory', async () => {
        for (const name of ['../../etc', 'backup-../../etc', '/etc/passwd', 'not-a-backup']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/rollback`, { backup: name })
            expect(response.status, `expected rejection for ${name}`).toBe(404)
        }
    })

    it.skipIf(!configured)('rejects upload ids that could escape the working directory', async () => {
        for (const uploadId of ['../evil', 'a/b', '', 'has.dot']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/upload/chunk`, {
                uploadId,
                index: 0,
                total: 1,
                data: Buffer.from('test').toString('base64'),
            })
            expect(response.status, `expected rejection for ${JSON.stringify(uploadId)}`).toBe(400)
        }
    })

    it.skipIf(!configured)('refuses to install an archive that was never uploaded', async () => {
        for (const archive of ['upload-doesnotexist.zip', '../../etc/passwd', 'download-1.zip']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/run`, { archive })
            expect(response.status, `expected rejection for ${archive}`).toBe(404)
        }
    })

    it.skipIf(!configured)('rejects an upload that is not a Typemill core', async () => {
        const uploadId = 'apitest' + Math.random().toString(36).slice(2, 10)

        const chunk = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/upload/chunk`, {
            uploadId,
            index: 0,
            total: 1,
            data: Buffer.from('this is not a zip archive at all').toString('base64'),
        })
        expect(chunk.status).toBe(200)

        const finalize = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/upload/finalize`, {
            uploadId,
            total: 1,
        })
        expect(finalize.status).toBe(422)

        const body = await finalize.json()
        expect(body.message).toMatch(/ZIP|archive/i)

        // Carried as a key, so the admin reads it in their own language.
        expect(body.message_key).toBe('typemillupdate.err_archive_unreadable')
    })

    it.skipIf(!configured)('names the missing piece when an upload never finished', async () => {
        const uploadId = 'apitest' + Math.random().toString(36).slice(2, 10)

        await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/upload/chunk`, {
            uploadId,
            index: 0,
            total: 2,
            data: Buffer.from('first half').toString('base64'),
        })

        const finalize = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/upload/finalize`, {
            uploadId,
            total: 2,
        })
        expect(finalize.status).toBe(400)

        const body = await finalize.json()
        expect(body.message_key).toBe('typemillupdate.err_upload_incomplete')
        expect(body.message_params).toEqual({ chunk: 1 })
    })

    it.skipIf(!configured)('rejects a plugin name that could escape the plugins directory', async () => {
        for (const plugin of ['../evil', 'a/b', '', 'has.dot', '.hidden']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/plugin`, { plugin })
            expect(response.status, `expected rejection for ${JSON.stringify(plugin)}`).toBe(422)
        }
    })

    it.skipIf(!configured)('refuses to update a plugin that is not installed', async () => {
        const response = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/plugin`, { plugin: 'search' })
        expect(response.status).toBe(404)

        const body = await response.json()
        expect(body.message_key).toBe('typemillupdate.msg_plugin_not_installed')
    })

    it.skipIf(!configured)('refuses to replace this updater', async () => {
        const response = await apiPost(session, `${BASE_URL}/api/v1/typemillupdate/plugin`, { plugin: 'typemillupdate' })
        expect(response.status).toBe(409)

        const body = await response.json()
        expect(body.message_key).toBe('typemillupdate.msg_plugin_self')
    })

    it.skipIf(!configured)('requires an authenticated session', async () => {
        const response = await fetch(`${BASE_URL}/api/v1/typemillupdate/status`)
        expect(response.status).toBeGreaterThanOrEqual(400)

        const plugin = await fetch(`${BASE_URL}/api/v1/typemillupdate/plugin`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plugin: 'search' }),
        })
        expect(plugin.status).toBeGreaterThanOrEqual(400)
    })
})
