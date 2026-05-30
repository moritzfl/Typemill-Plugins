/**
 * Authenticates against the Typemill web login endpoint and returns a session
 * object that can be passed to the request helpers below.
 *
 * Typemill uses web-session-based auth for its API:
 *   1. POST /tm/login with form-encoded username + password → redirects on success
 *   2. Send Cookie + X-Session-Auth: true on every API request
 *
 * Success/failure is detected via the Location header of the redirect:
 *   - Redirects to /tm/content/... → login succeeded
 *   - Redirects back to /tm/login  → credentials rejected
 *
 * The X-Session-Auth header tells Typemill's ApiAuthentication middleware to
 * trust the existing session set by the SessionMiddleware, instead of demanding
 * HTTP Basic Auth.
 */
export async function createSession(baseUrl, username, password) {
    const body = new URLSearchParams({
        username,
        password,
        'personal-honey-mail': '',
    })

    const loginResp = await fetch(`${baseUrl}/tm/login`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Referer': `${baseUrl}/tm/login`,
        },
        body: body.toString(),
        redirect: 'manual', // capture redirect + Set-Cookie without following
    })

    // Typemill redirects to /tm/login on failure, /tm/content/... on success.
    const location = loginResp.headers.get('location') ?? ''
    if (!location || location.includes('/tm/login')) {
        throw new Error(
            `Login failed (HTTP ${loginResp.status}, redirected to: ${location || 'nowhere'}). ` +
            `Check TM_USER / TM_PASSWORD in .env.test and that the setup wizard is complete.`
        )
    }

    // Typemill sets the session cookie twice: once to persist the old session
    // (unauthenticated) and again with the newly regenerated authenticated session.
    // Deduplicate by name, keeping the last value — matching real browser behaviour.
    const rawCookies = loginResp.headers.getSetCookie
        ? loginResp.headers.getSetCookie()                               // Node 18.14+
        : (loginResp.headers.get('set-cookie') ?? '').split(/,(?=[^ ])/) // fallback

    const cookieMap = new Map()
    for (const raw of rawCookies) {
        const pair = raw.split(';')[0].trim()
        const eq   = pair.indexOf('=')
        if (eq === -1) continue
        cookieMap.set(pair.slice(0, eq), pair.slice(eq + 1))
    }
    const cookie = [...cookieMap.entries()].map(([k, v]) => `${k}=${v}`).join('; ')

    if (!cookie) {
        throw new Error(`No session cookie returned from POST /tm/login (HTTP ${loginResp.status}).`)
    }

    return { cookie }
}

export function apiRequest(session, url, method = 'GET', body = null) {
    const origin = new URL(url).origin
    const headers = {
        'Content-Type': 'application/json',
        'Cookie': session.cookie,
        'X-Session-Auth': 'true',
        // SecurityMiddleware rejects POST/DELETE without a matching Referer.
        Referer: `${origin}/`,
    }

    const opts = { method, headers }
    if (body !== null) {
        opts.body = JSON.stringify(body)
    }

    return fetch(url, opts)
}

export const apiGet    = (session, url)       => apiRequest(session, url, 'GET')
export const apiPost   = (session, url, body) => apiRequest(session, url, 'POST', body)
export const apiDelete = (session, url, body) => apiRequest(session, url, 'DELETE', body)
