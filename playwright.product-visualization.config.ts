import {defineConfig} from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e/product-visualization',
  fullyParallel: false,
  workers: 1,
  timeout: 45_000,
  use: {baseURL: 'http://127.0.0.1:8791', viewport: {width: 390, height: 844}},
  projects: [{name: 'chromium', use: {browserName: 'chromium'}}, {name: 'webkit', use: {browserName: 'webkit'}}],
  webServer: {
    command: 'PRODUCT_VISUALIZATION_QA_PORT=8791 node resources/product-visualization/fixture-preview.mjs',
    url: 'http://127.0.0.1:8791',
    reuseExistingServer: false,
  },
});
