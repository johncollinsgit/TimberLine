import { test, expect, Page } from '@playwright/test';
import fs from 'node:fs';

async function fixture(page: Page, name = 'owner') {
    const errors: string[] = [], posts: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', response => {
        if (new URL(response.url()).hostname === '127.0.0.1' && response.status() >= 400) errors.push(`HTTP ${response.status()}: ${response.url()}`);
    });
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
    await expect(page.locator('body')).toHaveCSS('font-family', /sans-serif/);
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

for (const role of ['owner', 'member']) for (const width of [390, 820, 1440]) {
    test(`premium software shows owned and gated state for ${role} at ${width}px without a purchase action`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 1000 });
        const { errors, posts } = await fixture(page, `premium-${role}`);
        await page.getByRole('tab', { name: 'Your branches', exact: true }).click();
        const market = page.locator('[data-premium-product="markets_makers"]');
        await expect(market).toBeVisible();
        await expect(market).toHaveAttribute('data-premium-owned', '1');
        await expect(market).toHaveAttribute('data-premium-state', 'gated');
        await expect(market.getByText('Waiting for rollout', { exact: true })).toBeVisible();
        await expect(market.getByRole('link')).toHaveCount(0);
        await market.getByText('What happens next', { exact: true }).click();
        await expect(market.getByText('Workspace access does not confirm payment setup or external connections.', { exact: false })).toBeVisible();
        const team = page.locator('[data-premium-product="teams"]');
        await expect(team).toHaveAttribute('data-premium-state', 'available');
        await expect(team.getByRole('link', { name: 'Open Teams' })).toBeVisible();
        await team.getByText('What happens next', { exact: true }).click();
        await expect(team.getByText('Setup needed', { exact: false })).toBeVisible();
        const trajectory = page.locator('[data-premium-product="trajectory"]');
        await expect(trajectory).toHaveAttribute('data-premium-state', role === 'owner' ? 'available' : 'restricted');
        await expect(trajectory.getByRole('link', { name: 'Open Trajectory' })).toHaveCount(role === 'owner' ? 1 : 0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
        if (role === 'owner' && width === 1440) {
            await page.locator('.branch-directory__premium-grid').screenshot({ path: `storage/framework/testing/branch-settings/premium-${width}-${testInfo.project.name}.png` });
        } else if (role === 'owner' && width === 390) {
            await market.screenshot({ path: `storage/framework/testing/branch-settings/premium-${width}-${testInfo.project.name}-market.png` });
        }
        await page.getByRole('tab', { name: 'Setup', exact: true }).click();
        await expect(team).toBeVisible(); await expect(market).toBeVisible();
        await page.getByRole('tab', { name: 'Add software', exact: true }).click();
        await expect(market).toBeHidden(); await expect(team).toBeHidden();
        await expect(page.getByText('Your advanced software is already listed in Your branches.', { exact: true })).toBeVisible();
        expect(posts).toEqual([]); expect(errors).toEqual([]);
    });
}

test('unknown software verification never becomes a not-owned or Open offer', async ({ page }) => {
    const { errors, posts } = await fixture(page, 'premium-unknown');
    await page.getByRole('tab', { name: 'Your branches', exact: true }).click();
    const rows = page.locator('[data-premium-product]');
    for (const row of await rows.all()) {
        await expect(row).toBeVisible();
        await expect(row).toHaveAttribute('data-premium-owned', 'unknown');
        await expect(row).toHaveAttribute('data-premium-state', 'unknown');
        await expect(row.getByRole('link')).toHaveCount(0);
    }
    await expect(page.getByText('Software status could not be confirmed.', { exact: false })).toBeVisible();
    expect(posts).toEqual([]); expect(errors).toEqual([]);
});
