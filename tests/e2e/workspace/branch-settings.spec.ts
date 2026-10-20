import { test, expect, Page } from '@playwright/test';
import fs from 'node:fs';

async function fixture(page: Page, name = 'owner') {
    const errors: string[] = [], posts: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    const html = fs.readFileSync(`storage/framework/testing/branch-settings/${name}.html`, 'utf8')
        .replaceAll('http://branch-settings-quality.theeverbranch.com', 'http://127.0.0.1:4177');
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (request.method() !== 'GET') { posts.push(request.url()); return route.fulfill({ status: 204, body: '' }); }
        if (url.hostname !== '127.0.0.1') return route.abort();
        if (url.pathname === '/qa/branches') return route.fulfill({ contentType: 'text/html', body: html });
        return route.continue();
    });
    await page.goto('/qa/branches');
    await expect(page.getByRole('tab', { name: 'Your plan', exact: true })).toHaveAttribute('aria-selected', 'true');
    return { errors, posts };
}

for (const width of [390, 820, 1440]) {
    test(`tabs, setup guide and cancellation at ${width}px`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        const { errors, posts } = await fixture(page);
        const plan = page.getByRole('tab', { name: 'Your plan', exact: true });
        await plan.focus(); await plan.press('ArrowRight');
        await expect(page.getByRole('tab', { name: 'Your branches', exact: true })).toBeFocused();
        await expect(page).toHaveURL(/tab=branches/);
        await page.getByRole('tab', { name: 'Add software', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Separate premium software' })).toBeVisible();
        await expect(page.locator('.branch-directory__premium')).toHaveCount(3);
        await page.getByRole('tab', { name: 'Setup', exact: true }).click();
        const pending = page.locator('[data-branch-card][data-pending="1"]');
        await expect(pending).toBeVisible();
        const opener = pending.getByRole('button', { name: 'View setup guide' });
        await opener.click();
        const dialog = page.locator('dialog[open]');
        await expect(dialog).toBeVisible();
        for (let i = 0; i < 8; i++) {
            await page.keyboard.press('Tab');
            expect(await dialog.evaluate(el => el.contains(document.activeElement)), JSON.stringify({errors, state: await page.evaluate(() => ({active: document.activeElement?.outerHTML.slice(0,300)}))})).toBe(true);
        }
        await expect(dialog.getByText('Step 1 of 3 · Your choice')).toBeVisible();
        await expect(dialog.locator('form')).toHaveCount(0);
        await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
        await expect(dialog.getByText('Step 2 of 3 · Prepare')).toBeVisible();
        await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
        await expect(dialog.getByText('Your request is awaiting review.', { exact: false })).toBeVisible();
        await expect(dialog.locator('form')).toHaveCount(0);
        await dialog.getByRole('button', { name: 'Back', exact: true }).click();
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0); await expect(opener).toBeFocused();
        expect(posts).toEqual([]); expect(errors).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
        if (width === 390 || width === 1440) {
            await page.getByRole('tab', { name: 'Your plan', exact: true }).click();
            await page.evaluate(() => { for (const el of document.querySelectorAll<HTMLElement>('*')) { if (el.scrollTop) el.scrollTop = 0; } });
            await page.screenshot({ path: `storage/framework/testing/branch-settings/plan-${width}-${testInfo.project.name}.png`, fullPage: true });
        }
    });
}

test('member guides expose no final mutation action', async ({ page }) => {
    const { errors, posts } = await fixture(page, 'member');
    await expect(page.getByRole('link', { name: 'Review your agreement and receipts' })).toHaveCount(0);
    await page.getByRole('tab', { name: 'Add software', exact: true }).click();
    const opener = page.getByRole('button', { name: 'View setup guide', exact: true }).first();
    await opener.click();
    const dialog = page.locator('dialog[open]');
    for (let i=0; i<2; i++) await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(dialog.locator('form')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(opener).toBeFocused(); expect(posts).toEqual([]); expect(errors).toEqual([]);
});
