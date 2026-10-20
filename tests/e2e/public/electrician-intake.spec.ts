import { test, expect } from '@playwright/test';
for (const path of ['/platform/start', '/platform/demo']) {
    test(`electrician can select the supported industry on ${path}`, async ({ page }) => {
        const posts: string[] = [];
        await page.route('**/*', route => {
            const req = route.request();
            if (req.method() !== 'GET') { posts.push(req.url()); return route.fulfill({status: 204, body: ''}); }
            if (new URL(req.url()).hostname !== '127.0.0.1') return route.abort();
            return route.continue();
        });
        await page.goto(path);
        await page.getByLabel('Business type', { exact: true }).selectOption('electrician');
        await expect(page.getByLabel('Business type', { exact: true })).toHaveValue('electrician');
        if (path === '/platform/start') {
            await expect(page.locator('[data-public-offer]')).toBeVisible();
            await expect(page.locator('[data-public-offer]')).toContainText('Additional text, email, and AI usage fees may apply.');
        } else {
            await expect(page.getByRole('heading', { name: 'See Everbranch in action.' })).toBeVisible();
        }
        expect(posts).toEqual([]);
    });
}
