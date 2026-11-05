// @ts-check
const {defineConfig, devices} = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    testMatch: '*.spec.js',
    // one storage for all tests: one after another
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 30000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8091',
        trace: 'retain-on-failure',
    },
    projects: [{name: 'chromium', use: {...devices['Desktop Chrome'], viewport: {width: 1280, height: 1600}}}],
});
