import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: 'tests/e2e/workspace', timeout: 30_000,
    use: { baseURL: 'http://127.0.0.1:4177', colorScheme: 'light', screenshot: 'only-on-failure' },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'webkit', use: { ...devices['iPhone 13'] } },
    ],
    webServer: { command: 'sh scripts/qa/serve-public-visual.sh', url: 'http://127.0.0.1:4177/platform/promo', reuseExistingServer: !process.env.CI, timeout: 30_000 },
});
