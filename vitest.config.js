import { defineConfig, loadEnv } from 'vite'

export default defineConfig(({ mode }) => {
    // loadEnv with prefix '' exposes ALL variables (not just VITE_-prefixed ones)
    // so that process.env.TM_USER / TM_PASSWORD are available in tests.
    const env = loadEnv(mode, process.cwd(), '')

    return {
        test: {
            include: ['tests/api/**/*.test.js'],
            environment: 'node',
            // API tests share one mutable Docker Typemill instance.
            fileParallelism: false,
            testTimeout: 15000,
            hookTimeout: 15000,
            env,
        },
    }
})
