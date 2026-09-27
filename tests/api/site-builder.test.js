import { describe, it, expect, beforeAll } from 'vitest'
import { createSession, apiGet, apiPost } from './helpers/auth.js'

const base = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const configured = process.env.TM_USER && process.env.TM_PASSWORD

describe('Site building API boundaries', () => {
    let session
    beforeAll(async () => {
        if (configured) session = await createSession(base, process.env.TM_USER, process.env.TM_PASSWORD)
    })

    it('does not expose design settings or package writes anonymously', async () => {
        expect((await fetch(base + '/api/v1/designpanel/state')).status).toBe(401)
        expect((await fetch(base + '/api/v1/designpanel/pages')).status).toBe(401)
        for (const path of ['/api/v1/designpanel/preview', '/api/v1/designpanel/save', '/api/v1/gitupdate/package']) {
            const result = await fetch(base + path, { method: 'POST', headers: { Referer: base + '/tm/login', 'Content-Type': 'application/json' }, body: '{}' })
            expect(result.status).toBe(401)
        }
    })

    it.skipIf(!configured)('returns editable theme schema without custom CSS or credentials', async () => {
        const response = await apiGet(session, base + '/api/v1/designpanel/state')
        expect(response.status).toBe(200)
        const body = await response.json()
        expect(body.fields.map(field => field.key)).toContain('typeScale')
        expect(body.values).not.toHaveProperty('customcss')
        expect(body.values).not.toHaveProperty('token')
        expect(body.revision).toMatch(/^[a-f0-9]{64}$/)
    })

    it.skipIf(!configured)('rejects external preview paths and stale saves', async () => {
        const state = await (await apiGet(session, base + '/api/v1/designpanel/state')).json()
        const preview = await apiPost(session, base + '/api/v1/designpanel/preview', { theme: state.theme, values: {}, path: '//example.com/' })
        expect(preview.status).toBe(422)
        const save = await apiPost(session, base + '/api/v1/designpanel/save', { theme: state.theme, revision: 'stale', values: {} })
        expect(save.status).toBe(409)
    })

    it.skipIf(!configured)('rejects package traversal and invalid commit pins before network access', async () => {
        for (const payload of [
            { action: 'install', kind: 'plugin', slug: '../escape' },
            { action: 'install', kind: 'plugin', slug: 'not-installed-fixture', sha: 'main' },
            { action: 'unknown', kind: 'theme', slug: 'court' },
        ]) expect((await apiPost(session, base + '/api/v1/gitupdate/package', payload)).status).toBe(422)
    })
})
