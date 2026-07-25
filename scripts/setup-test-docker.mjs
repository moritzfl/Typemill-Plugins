#!/usr/bin/env node
/**
 * Provisions a Typemill Docker instance for automated API testing.
 *
 * What it does:
 *   1. Starts the Typemill Docker container (if not running).
 *   2. Waits for the instance to respond over HTTP.
 *   3. Writes the minimal settings + admin user that Typemill needs to skip
 *      the setup wizard — using PHP inside the container so password hashing
 *      and file paths are handled correctly.
 *   4. Writes .env.test with the test credentials so `npm run test:api` works
 *      without any manual configuration.
 *   5. Verifies that the admin login actually succeeds.
 *
 * The script is idempotent: if settings/settings.yaml and the admin user file
 * already exist inside the container, provisioning is skipped.
 *
 * Credentials are fixed test-only values; do not use them in production.
 */

import { execSync, spawnSync } from 'node:child_process'
import { existsSync, writeFileSync, readFileSync, copyFileSync, mkdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname  = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT  = join(__dirname, '..')

const COMPOSE_FILE  = join(REPO_ROOT, 'docker-compose.typemill.yml')
const ENV_TEST_FILE = join(REPO_ROOT, '.env.test')

// Volume-mounted paths on the host — used to detect existing provisioning.
const SETTINGS_DIR  = join(REPO_ROOT, '.docker/typemill/settings')
const SETTINGS_FILE = join(SETTINGS_DIR, 'settings.yaml')
const USERS_DIR     = join(SETTINGS_DIR, 'users')
const KEY_TARGET    = join(SETTINGS_DIR, 'public_key.pem')
const KEY_FIXTURE   = join(REPO_ROOT, 'tests/fixtures/public_key.pem')

// Fixed test-only credentials.
const TM_BASE_URL = process.env.TM_BASE_URL || 'http://127.0.0.1:8080'
const TM_USER     = 'admin'
const TM_PASSWORD = 'Test1234!'

// ---------------------------------------------------------------------------

function log(msg) { console.log(`[setup] ${msg}`) }
function die(msg) { console.error(`[setup] ERROR: ${msg}`); process.exit(1) }

function ensureTestPluginsActive() {
    if (!existsSync(SETTINGS_FILE)) {
        return
    }

    let content = readFileSync(SETTINGS_FILE, 'utf8')
    for (const plugin of ['versions', 'preview', 'files', 'typemillupdate', 'linkbuttons']) {
        // Anchor continuation lines to deeper indentation than the plugin key,
        // so the match can never run into the next plugin's block.
        const blockRe = new RegExp(
            `(^([ \\t]+)${plugin}:[ \\t]*\\n(?:\\2[ \\t]+[^\\n]+\\n)*?\\2[ \\t]+active:[ \\t]*)(true|false)`,
            'm'
        )
        if (blockRe.test(content)) {
            content = content.replace(blockRe, '$1true')
            continue
        }

        const keyRe = new RegExp(`^([ \\t]+)${plugin}:[ \\t]*\\n`, 'm')
        const keyMatch = content.match(keyRe)
        if (keyMatch) {
            // Block exists but has no active key — insert one below the plugin key.
            content = content.replace(keyRe, `${keyMatch[0]}${keyMatch[1]}    active: true\n`)
            continue
        }

        content = content.replace(
            /^plugins:\s*\n/m,
            `plugins:\n    ${plugin}:\n        active: true\n`
        )
    }

    writeFileSync(SETTINGS_FILE, content)
}

// ---------------------------------------------------------------------------
// 1. Start container
// ---------------------------------------------------------------------------

log('Starting Docker container...')

const upResult = spawnSync(
    'docker',
    ['compose', '-f', COMPOSE_FILE, 'up', '-d'],
    { stdio: 'inherit' }
)

if (upResult.status !== 0) {
    die('docker compose up failed. Is Docker running?')
}

// ---------------------------------------------------------------------------
// 2. Wait for HTTP response
// ---------------------------------------------------------------------------

log('Waiting for Typemill to respond...')

const MAX_WAIT_MS = 90_000
const POLL_MS     = 1_500
const deadline    = Date.now() + MAX_WAIT_MS

let reachable = false
while (Date.now() < deadline) {
    try {
        const resp = await fetch(TM_BASE_URL, {
            signal: AbortSignal.timeout(2_000),
            redirect: 'manual',
        })
        if (resp.status < 600) { reachable = true; break }
    } catch { /* not yet */ }
    await new Promise(r => setTimeout(r, POLL_MS))
}

if (!reachable) {
    die(`Typemill did not respond within ${MAX_WAIT_MS / 1000}s at ${TM_BASE_URL}.`)
}

// ---------------------------------------------------------------------------
// 2b. PHP zip extension (folder ZIP downloads, versions export)
// ---------------------------------------------------------------------------

log('Ensuring PHP zip extension...')
const zipCheck = spawnSync(
    'docker',
    ['compose', '-f', COMPOSE_FILE, 'exec', '-T', 'typemill', 'php', '-r', "echo class_exists('ZipArchive') ? 'yes' : 'no';"],
    { encoding: 'utf8' }
)
if ((zipCheck.stdout || '').trim() !== 'yes') {
    const zipInstall = spawnSync(
        'docker',
        [
            'compose', '-f', COMPOSE_FILE, 'exec', '-T', 'typemill', 'sh', '-c',
            'apt-get update -qq && apt-get install -y -qq libzip-dev zlib1g-dev && docker-php-ext-install zip',
        ],
        { stdio: 'inherit' }
    )
    if (zipInstall.status !== 0) {
        die('Failed to install PHP zip extension in the Typemill container.')
    }

    // mod_php loads extensions at Apache start — reload after a runtime install.
    const apacheReload = spawnSync(
        'docker',
        ['compose', '-f', COMPOSE_FILE, 'exec', '-T', 'typemill', 'apachectl', 'graceful'],
        { stdio: 'inherit' }
    )
    if (apacheReload.status !== 0) {
        die('Failed to reload Apache after installing the PHP zip extension.')
    }
}

// ---------------------------------------------------------------------------
// 2c. Composer vendor (Typemill v2.23+ image may not ship vendor at runtime)
// ---------------------------------------------------------------------------

log('Ensuring Composer vendor directory...')
const vendorCheck = spawnSync(
    'docker',
    // vendor location moved to system/vendor in newer Typemill versions; accept either
    ['compose', '-f', COMPOSE_FILE, 'exec', '-T', 'typemill', 'sh', '-c',
        'test -f /var/www/html/vendor/autoload.php || test -f /var/www/html/system/vendor/autoload.php'],
    { encoding: 'utf8' }
)
if (vendorCheck.status !== 0) {
    const vendorInstall = spawnSync(
        'docker',
        [
            'compose', '-f', COMPOSE_FILE, 'exec', '-T', 'typemill', 'sh', '-c',
            'cd /var/www/html && php composer.phar install --no-interaction --no-dev',
        ],
        { stdio: 'inherit' }
    )
    if (vendorInstall.status !== 0) {
        die('Failed to install Composer dependencies in the Typemill container.')
    }
}

// ---------------------------------------------------------------------------
// 3. Provision settings + admin user
//
// settings.yaml is only written when it does not exist, so manually configured
// instances keep their settings (theme, plugins, etc.).
//
// The test user file is always written/overwritten — it is test-only state
// managed entirely by this script.
// ---------------------------------------------------------------------------

const settingsExist = existsSync(SETTINGS_FILE)

// Always ensure plugins required for automated tests are active.
ensureTestPluginsActive()

const php = `<?php
$user     = ${JSON.stringify(TM_USER)};
$password = ${JSON.stringify(TM_PASSWORD)};
$hash     = password_hash($password, PASSWORD_DEFAULT, ['cost' => 10]);

$dir      = '/var/www/html/settings';
$usersDir = $dir . '/users';

if (!is_dir($usersDir)) {
    mkdir($usersDir, 0755, true);
}

// Create settings.yaml only when it does not already exist.
// This preserves any manual configuration (themes, other plugins, etc.)
// while ensuring the versions plugin is active on a fresh instance.
if (!file_exists($dir . '/settings.yaml')) {
    $settings = implode("\\n", [
        "language: en",
        "author: $user",
        "plugins:",
        "    versions:",
        "        active: true",
        "    preview:",
        "        active: true",
        "    files:",
        "        active: true",
        "    typemillupdate:",
        "        active: true",
        "    linkbuttons:",
        "        active: true",
        "",
    ]);
    file_put_contents($dir . '/settings.yaml', $settings);
    echo "created-settings\\n";
} else {
    echo "kept-settings\\n";
}

// Always write the test user (create or overwrite) so the known test
// credentials are always in sync with what this script expects.
$userYaml = implode("\\n", [
    "username: $user",
    "email: admin@example.com",
    "userrole: administrator",
    "password: " . $hash,
    "",
]);
file_put_contents($usersDir . '/' . $user . '.yaml', $userYaml);
echo "ok\\n";
`

log(settingsExist ? 'Settings exist — keeping them, updating test user...' : 'Provisioning Typemill settings and admin user...')

let output
try {
    output = execSync(
        `docker compose -f "${COMPOSE_FILE}" exec -T typemill php`,
        { input: php, stdio: ['pipe', 'pipe', 'pipe'] }
    ).toString().trim()
} catch (err) {
    die(`PHP provisioning failed:\n${err.stderr?.toString() ?? err.message}`)
}

if (!output.endsWith('ok')) {
    die(`PHP provisioning returned unexpected output: ${output}`)
}

log('Test user ready.')

// ---------------------------------------------------------------------------
// 4. Copy public_key.pem (used by Typemill's license / system-version check)
// ---------------------------------------------------------------------------

if (!existsSync(KEY_TARGET) && existsSync(KEY_FIXTURE)) {
    mkdirSync(SETTINGS_DIR, { recursive: true })
    copyFileSync(KEY_FIXTURE, KEY_TARGET)
    log('Copied public_key.pem from fixtures.')
}

// ---------------------------------------------------------------------------
// 5. Write .env.test
// ---------------------------------------------------------------------------

const envContent = [
    `TM_BASE_URL=${TM_BASE_URL}`,
    `TM_USER=${TM_USER}`,
    `TM_PASSWORD=${TM_PASSWORD}`,
    '',
].join('\n')

writeFileSync(ENV_TEST_FILE, envContent)
log('.env.test written.')

// ---------------------------------------------------------------------------
// 6. Verify login
// ---------------------------------------------------------------------------

log('Verifying login...')

let loginResp
try {
    loginResp = await fetch(`${TM_BASE_URL}/tm/login`, {
        method:   'POST',
        headers:  {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Referer':      `${TM_BASE_URL}/tm/login`,
        },
        body:     new URLSearchParams({ username: TM_USER, password: TM_PASSWORD, 'personal-honey-mail': '' }).toString(),
        redirect: 'manual',
    })
} catch (err) {
    die(`Login request failed: ${err.message}`)
}

const location = loginResp.headers.get('location') ?? ''

if (!location || location.includes('/tm/login')) {
    die(
        `Login verification failed (HTTP ${loginResp.status}, redirected to: ${location || 'nowhere'}).\n` +
        `       The container may still be initialising — wait a moment and retry:\n` +
        `         npm run test:setup`
    )
}

log(`Login verified (→ ${location})`)
log('')
log('Setup complete. You can now run:')
log('  npm run test:api        — run API tests once')
log('  npm run test:api:watch  — run API tests in watch mode')
log('  npm run test:php        — run PHP unit tests in Docker')
