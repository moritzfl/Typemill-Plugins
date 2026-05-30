#!/usr/bin/env node
/**
 * Purges expired recycle-bin entries via the Versions plugin API.
 *
 * Requires a running Typemill instance and credentials in .env.test
 * (created by `npm run test:setup`) or TM_BASE_URL / TM_USER / TM_PASSWORD.
 *
 * Usage:
 *   node scripts/versions-retention.mjs
 *
 * Schedule with cron (example — daily at 03:15):
 *   15 3 * * * cd /path/to/typemill-plugins && node scripts/versions-retention.mjs >> /var/log/versions-retention.log 2>&1
 */

import { readFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..')

function loadEnvFile(path) {
    if (!existsSync(path)) {
        return
    }

    for (const line of readFileSync(path, 'utf8').split('\n')) {
        const trimmed = line.trim()
        if (trimmed === '' || trimmed.startsWith('#')) {
            continue
        }

        const eq = trimmed.indexOf('=')
        if (eq === -1) {
            continue
        }

        const key = trimmed.slice(0, eq).trim()
        const value = trimmed.slice(eq + 1).trim()
        if (key && process.env[key] === undefined) {
            process.env[key] = value
        }
    }
}

loadEnvFile(join(REPO_ROOT, '.env.test'))

const BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const USERNAME = process.env.TM_USER
const PASSWORD = process.env.TM_PASSWORD

if (!USERNAME || !PASSWORD) {
    console.error('[versions-retention] TM_USER and TM_PASSWORD are required (see .env.test).')
    process.exit(1)
}

async function createSession() {
    const body = new URLSearchParams({
        username: USERNAME,
        password: PASSWORD,
        'personal-honey-mail': '',
    })

    const loginResp = await fetch(`${BASE_URL}/tm/login`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Referer': `${BASE_URL}/tm/login`,
        },
        body: body.toString(),
        redirect: 'manual',
    })

    const location = loginResp.headers.get('location') ?? ''
    if (!location || location.includes('/tm/login')) {
        throw new Error(`Login failed (HTTP ${loginResp.status}, location: ${location || 'none'})`)
    }

    const rawCookies = loginResp.headers.getSetCookie
        ? loginResp.headers.getSetCookie()
        : (loginResp.headers.get('set-cookie') ?? '').split(/,(?=[^ ])/)

    const cookieMap = new Map()
    for (const raw of rawCookies) {
        const pair = raw.split(';')[0].trim()
        const eq = pair.indexOf('=')
        if (eq === -1) {
            continue
        }
        cookieMap.set(pair.slice(0, eq), pair.slice(eq + 1))
    }

    const cookie = [...cookieMap.entries()].map(([k, v]) => `${k}=${v}`).join('; ')
    if (!cookie) {
        throw new Error('No session cookie returned from login.')
    }

    return cookie
}

async function main() {
    const cookie = await createSession()

    const resp = await fetch(`${BASE_URL}/api/v1/versions/maintenance/retention`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Referer': `${BASE_URL}/tm/versions`,
            'Cookie': cookie,
            'X-Session-Auth': 'true',
        },
    })

    const payload = await resp.json().catch(() => ({}))
    if (!resp.ok) {
        console.error('[versions-retention] Request failed:', resp.status, payload)
        process.exit(1)
    }

    console.log(`[versions-retention] Purged ${payload.purged ?? 0} expired trash entries.`)
}

main().catch((err) => {
    console.error('[versions-retention] ERROR:', err.message)
    process.exit(1)
})
