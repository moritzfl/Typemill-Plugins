/**
 * Creates and tears down a large content folder fixture inside the running
 * Typemill Docker container.
 *
 * Files are created and removed via `docker exec` + PHP, which guarantees
 * they are immediately visible to Typemill without any volume-mount sync delay.
 *
 * The folder contains 501 files — one more than MAX_SNAPSHOT_FILES (500) —
 * so any attempt to snapshot it triggers SnapshotTooLargeException → HTTP 409.
 *
 * Typemill navigation uses a number prefix for ordering (e.g. "01-about").
 * We use "99-" to sort last and avoid conflicting with real content.
 * The URL strips that prefix: 99-test-large-folder → /test-large-folder.
 */

import { execSync }                      from 'node:child_process'
import { existsSync, readdirSync, rmSync } from 'node:fs'
import { join, dirname }                 from 'node:path'
import { fileURLToPath }                 from 'node:url'

const __dirname   = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT   = join(__dirname, '../../..')

const COMPOSE_FILE  = join(REPO_ROOT, 'docker-compose.typemill.yml')
const NAVI_DIR      = join(REPO_ROOT, '.docker/typemill/data/navigation')

const FOLDER_NAME   = '99-test-large-folder'
const CTR_PATH      = `/var/www/html/content/${FOLDER_NAME}`

export const LARGE_FOLDER_URL = '/test-large-folder'   // URL Typemill exposes

function ensureTypemillContainerRunning() {
    try {
        const state = execSync(
            `docker compose -f "${COMPOSE_FILE}" ps --status running --services typemill`,
            { stdio: ['pipe', 'pipe', 'pipe'] }
        ).toString().trim()

        if (state !== 'typemill') {
            throw new Error('typemill container is not running')
        }
    } catch (error) {
        throw new Error(`Typemill Docker container is not ready: ${error.message}`)
    }
}

function execInTypemill(command, options = {}) {
    ensureTypemillContainerRunning()

    try {
        return execSync(command, { stdio: ['pipe', 'pipe', 'pipe'], ...options })
    } catch (error) {
        const stderr = error.stderr ? error.stderr.toString().trim() : error.message
        throw new Error(`Typemill Docker command failed: ${stderr}`)
    }
}

/**
 * Creates the fixture inside the container via docker exec.
 * PHP is piped via stdin (docker exec -T) to avoid shell quoting issues.
 * Writing all 502 files in one PHP execution is fast and guarantees immediate
 * visibility to Typemill without any volume-mount sync concerns.
 */
export function createLargeFolder() {
    const php = [
        `$dir = '${CTR_PATH}';`,
        `mkdir($dir, 0755, true);`,
        `file_put_contents($dir . '/index.md', "# Test fixture\nAutomated test - safe to delete.\n");`,
        `for ($i = 1; $i <= 501; $i++) {`,
        `  file_put_contents($dir . '/filler-' . str_pad($i, 4, '0', STR_PAD_LEFT) . '.txt', "x\n");`,
        `}`,
        `echo count(glob($dir . '/*'));`,
    ].join(' ')

    const count = execInTypemill(
        `docker compose -f "${COMPOSE_FILE}" exec -T typemill php`,
        { input: `<?php ${php}` }
    ).toString().trim()

    if (parseInt(count) < 502) {
        throw new Error(`createLargeFolder: expected 502 files, got ${count}`)
    }

    clearNavigationCache()
}

/**
 * Removes the fixture. Safe to call even if the folder was already deleted
 * by the force_delete test.
 */
export function cleanupLargeFolder() {
    try {
        execInTypemill(
            `docker compose -f "${COMPOSE_FILE}" exec -T typemill rm -rf "${CTR_PATH}"`,
        )
    } catch {
        // Folder may already be gone — that is fine.
    }
    clearNavigationCache()
}

/**
 * Deletes all serialised navigation .txt files from data/navigation/ so that
 * the next request rebuilds navigation from the filesystem (picking up our new
 * folder or dropping the deleted one).
 */
function clearNavigationCache() {
    if (!existsSync(NAVI_DIR)) return
    for (const file of readdirSync(NAVI_DIR)) {
        if (file.endsWith('.txt')) {
            rmSync(join(NAVI_DIR, file), { force: true })
        }
    }
}
