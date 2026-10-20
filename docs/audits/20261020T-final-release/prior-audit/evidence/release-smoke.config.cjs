const { defineConfig, devices } = require('/Users/johncollins/Documents/ChatGPT/Barrel/everbranch-quality/node_modules/@playwright/test');

module.exports = defineConfig({
  testDir: __dirname,
  testMatch: 'release-smoke.spec.ts',
  grep: /public header and workflow|core public pages fit|public forms expose|current offer and conditional|keeps the current offer path|supports keyboard workflow|honors reduced motion/,
  timeout: 45000,
  expect: { timeout: 10000 },
  workers: 2,
  retries: 0,
  use: {
    baseURL: 'https://app.theeverbranch.com',
    colorScheme: 'light',
    locale: 'en-US',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'live-chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'live-webkit', use: { ...devices['iPhone 13'] } },
  ],
  outputDir: './release-evidence/live-smoke',
  reporter: [['line'], ['json', { outputFile: './release-evidence/quality-live-smoke.json' }]],
});
