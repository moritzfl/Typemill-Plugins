import { defineConfig } from 'vitest/config'
import { readFileSync } from 'node:fs'

// Minimal .env.test loader (KEY=VALUE lines) so process.env.TM_USER /
// TM_PASSWORD are available in tests without depending directly on vite.
function loadEnvTest() {
    try {
        return Object.fromEntries(
            readFileSync('.env.test', 'utf8')
                .split('\n')
                .filter((line) => line.includes('=') && !line.trimStart().startsWith('#'))
                .map((line) => [
                    line.slice(0, line.indexOf('=')).trim(),
                    line.slice(line.indexOf('=') + 1).trim(),
                ])
        )
    } catch {
        return {}
    }
}

export default defineConfig({
    test: {
        include: ['tests/api/**/*.test.js'],
        environment: 'node',
        // API tests share one mutable Docker Typemill instance.
        fileParallelism: false,
        testTimeout: 15000,
        hookTimeout: 15000,
        env: loadEnvTest(),
        setupFiles: ['tests/api/helpers/setup.js'],
    },
})
