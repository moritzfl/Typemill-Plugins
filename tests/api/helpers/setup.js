// Vitest setup file for all API tests (see vitest.config.js `setupFiles`).
//
// In CI, missing credentials must fail the run instead of letting every
// `it.skipIf(!configured)` test silently skip and go green.
const configured = Boolean(process.env.TM_USER && process.env.TM_PASSWORD)
const requireConfiguredAuth = process.env.CI === 'true' || process.env.REQUIRE_TM_AUTH === 'true'

if (requireConfiguredAuth && !configured) {
    throw new Error(
        'TM_USER and TM_PASSWORD are required for authenticated API tests in CI. ' +
        'Run `npm run test:setup` to provision the Docker instance and write .env.test.'
    )
}
