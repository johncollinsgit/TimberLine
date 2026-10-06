import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
    testDir: "tests/e2e/public",
    timeout: 30_000,
    expect: { timeout: 8_000 },
    use: {
        baseURL: "http://127.0.0.1:4177",
        colorScheme: "light",
        locale: "en-US",
        screenshot: "only-on-failure",
    },
    projects: [
        { name: "desktop-chromium", use: { ...devices["Desktop Chrome"] } },
        { name: "mobile-safari", use: { ...devices["iPhone 13"] } },
    ],
    webServer: {
        command: "sh scripts/qa/serve-public-visual.sh",
        url: "http://127.0.0.1:4177/platform/promo",
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
