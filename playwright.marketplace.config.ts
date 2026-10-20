import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: 'tests/e2e/marketplace', timeout: 30_000,
    use: { baseURL: 'http://127.0.0.1:4178', colorScheme: 'light', screenshot: 'only-on-failure' },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'webkit', use: { ...devices['iPhone 13'] } },
    ],
    webServer: { command: 'APP_ENV=testing php -S 127.0.0.1:4178 -t public scripts/qa/marketplace-visual-router.php', url: 'http://127.0.0.1:4178/qa/marketplace-health', reuseExistingServer: !process.env.CI, timeout: 30_000 },
});
