import { test, expect } from '/Users/johncollins/Documents/ChatGPT/Barrel/everbranch-quality/node_modules/@playwright/test';
import './release-smoke.spec';

// The imported beforeEach permits only GET/HEAD to the public app and fonts.
// Selection is local UI state; these checks never submit an access request.
for (const path of ['/platform/start', '/platform/demo']) {
  test(`released electrician intake remains selectable on ${path}`, async ({ page }) => {
    const writes: string[] = [];
    const errors: string[] = [];
    page.on('request', request => {
      if (!['GET', 'HEAD'].includes(request.method())) writes.push(request.method());
    });
    page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    const industry = page.getByLabel('Business type', { exact: true });
    await industry.selectOption('electrician');
    await expect(industry).toHaveValue('electrician');
    if (path === '/platform/start') {
      await expect(page.locator('[data-public-offer]')).toContainText('Additional text, email, and AI usage fees may apply.');
    } else {
      await expect(page.getByRole('heading', { name: 'See Everbranch in action.' })).toBeVisible();
    }
    expect(writes).toEqual([]);
    expect(errors).toEqual([]);
  });
}
