import { test } from '/Users/johncollins/Documents/ChatGPT/Barrel/everbranch-quality/node_modules/@playwright/test';

// Public production observation only: no forms, provider calls, or analytics writes.
test.beforeEach(async ({ page }) => {
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (!['GET', 'HEAD'].includes(request.method())) return route.abort();
    if (!['app.theeverbranch.com', 'fonts.bunny.net', 'fonts.gstatic.com'].includes(url.hostname)) return route.abort();
    return route.continue();
  });
});

// Reuse the reviewed assertions, selecting only the read-only tests in the config.
import './public-quality.frozen.spec';
import './studio-landing.frozen.spec';
