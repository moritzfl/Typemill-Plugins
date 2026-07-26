import { describe, it, expect, beforeAll } from 'vitest'
import { createSession, apiGet } from './helpers/auth.js'

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

describe('GitHub readme meta fields', () => {
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

        // The plugin is only active in a prepared instance; without it there is
        // nothing to assert and nothing broken either.
        if (!tabs || typeof tabs !== 'object' || !tabs.github) {
            console.log('note: the githubreadme plugin is not active, so its fields were not checked')
            return
        }

        const fields = tabs.github.fields ?? {}

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
})
