import { describe, it, expect, beforeAll } from 'vitest'
import { createSession, apiGet, apiPost } from './helpers/auth.js'

/**
 * The page's own GitHub fields.
 *
 * A page names its repository in the meta tab, and that tab is declared in the
 * plugin's yaml. Whether it reaches the editor is decided by the meta endpoint:
 * the editor draws whatever this returns, so a field type Typemill does not know,
 * or a tab that never gets merged, shows up here rather than in a screenshot.
 */
const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER
const PASSWORD = process.env.TM_PASSWORD

const configured = USERNAME && PASSWORD

describe('Readme MD meta fields', () => {
    let session

    beforeAll(async () => {
        if (configured) {
            session = await createSession(BASE_URL, USERNAME, PASSWORD)
        }
    })

    it.skipIf(!configured)('offers the repository fields on a page', async () => {
        const response = await apiGet(session, `${BASE_URL}/api/v1/meta?url=/`)
        expect(response.status).toBe(200)

        const body = await response.json()
        const tabs = body.definitions ?? body.metadefinitions ?? body

        // setup-test-docker activates the plugin; a missing tab is the rename
        // failure this test is here for, not a reason to skip.
        expect(tabs && typeof tabs === 'object' ? tabs.readme : undefined).toBeDefined()

        const fields = tabs.readme.fields ?? {}

        expect(Object.keys(fields)).toEqual(
            expect.arrayContaining(['repository', 'branch', 'path', 'position', 'droptitle'])
        )

        // Types Typemill can actually render; anything else breaks the editor.
        const renderable = ['text', 'textarea', 'checkbox', 'checkboxlist', 'select', 'number', 'image', 'fieldset']
        for (const [name, definition] of Object.entries(fields)) {
            expect(renderable, `${name} uses a field type the editor cannot draw`).toContain(definition.type)
        }

        // The one field that decides where the readme goes has to offer exactly
        // the placements the plugin implements.
        expect(Object.keys(fields.position.options ?? {})).toEqual(
            expect.arrayContaining(['replace', 'append', 'prepend'])
        )
    })

    it.skipIf(!configured)('refuses a refresh for something that is not a repository', async () => {
        for (const repository of ['', 'not a repo', 'https://gitlab.com/a/b', 'moritzfl']) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/readmemd/refresh`, { repository })

            expect(response.status, `expected rejection for ${JSON.stringify(repository)}`).toBe(422)
        }
    })

    it.skipIf(!configured)('refuses a branch or file that would change the request', async () => {
        const hostile = [
            { repository: 'typemill/typemill', branch: '../../etc' },
            { repository: 'typemill/typemill', path: '../../../etc/passwd' },
        ]

        for (const body of hostile) {
            const response = await apiPost(session, `${BASE_URL}/api/v1/readmemd/refresh`, body)

            expect(response.status, `expected rejection for ${JSON.stringify(body)}`).toBe(422)
        }
    })

    it.skipIf(!configured)('cannot be used without a session', async () => {
        // Redirects are not followed: Typemill turns an unauthenticated POST away
        // with a 302 to the login form, and following it would land on a page
        // that answers 200 and read as success.
        for (const headers of [{}, { 'X-Session-Auth': 'true' }]) {
            const response = await fetch(`${BASE_URL}/api/v1/readmemd/refresh`, {
                method: 'POST',
                redirect: 'manual',
                headers: { 'Content-Type': 'application/json', ...headers },
                body: JSON.stringify({ repository: 'typemill/typemill' }),
            })

            expect(
                response.status >= 300,
                `an unauthenticated POST answered ${response.status}`
            ).toBe(true)
        }
    })

    it.skipIf(!configured)('fetches a readme when asked to', async () => {
        const response = await apiPost(session, `${BASE_URL}/api/v1/readmemd/refresh`, {
            repository: 'typemill/typemill',
        })
        expect(response.status).toBe(200)

        const body = await response.json()
        expect(body.repository).toBe('typemill/typemill')

        if (!body.ok) {
            // Needs GitHub to answer this machine right now; a spent hourly
            // allowance is not a broken route.
            console.log(`note: GitHub did not serve the readme just now (${body.failure}); the fetch was not proven`)
            expect(typeof body.failure).toBe('string')
            return
        }

        expect(body.bytes).toBeGreaterThan(200)
        expect(body.origin).toBe('network')
    })
})
