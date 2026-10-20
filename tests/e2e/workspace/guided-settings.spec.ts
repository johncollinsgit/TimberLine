import { test, expect, Page } from '@playwright/test';
import fs from 'node:fs';

async function settingsFixture(page: Page, initial = 'settings-owner') {
    const errors: string[] = [], writes: {url: string; body: any}[] = [];
    const load = (name: string) => fs.readFileSync(`storage/framework/testing/guided-settings/${name}.html`, 'utf8')
        .replaceAll('http://guided-settings-quality.theeverbranch.com', 'http://127.0.0.1:4177').replaceAll('http://localhost', 'http://127.0.0.1:4177');
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (new URL(response.url()).hostname === '127.0.0.1' && response.status() >= 400) errors.push(`HTTP ${response.status()}: ${response.url()}`); });
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (request.method() !== 'GET') {
            writes.push({url: url.pathname, body: request.postDataJSON()});
            if (url.pathname === '/workspace/create/draft') return route.fulfill({ json: { saved: true, revision: writes.length + 2 } });
            return route.abort();
        }
        if (url.hostname !== '127.0.0.1') return route.abort();
        const name = url.pathname === '/qa/guided-settings' ? initial
            : url.pathname === '/workspace/settings/business' ? url.searchParams.get('step') || 'business'
            : url.pathname === '/workspace/setup' ? 'setup'
            : url.pathname === '/workspace/settings' ? (url.searchParams.get('q') === 'logo' ? 'search-logo' : 'settings-owner') : null;
        return name ? route.fulfill({ contentType: 'text/html', body: load(name) }) : route.continue();
    });
    await page.goto('/qa/guided-settings');
    await expect(page.locator('body')).toHaveCSS('font-family', /sans-serif/);
    return {errors, writes};
}

for (const width of [390, 1024, 1440]) {
    test(`settings search and short profile steps at ${width}px`, async ({ page }, info) => {
        await page.setViewportSize({ width, height: 1000 });
        const {errors, writes} = await settingsFixture(page);
        await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
        await page.screenshot({ path: `storage/framework/testing/guided-settings/hub-${width}-${info.project.name}.png`, fullPage: true });
        await page.getByRole('searchbox', { name: 'Search settings' }).fill('logo');
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Brand & appearance' })).toBeVisible();
        await expect(page.locator('#settings-results')).toBeFocused();
        await page.getByRole('link', { name: 'Clear', exact: true }).click();
        await page.getByRole('link').filter({ has: page.getByRole('heading', { name: 'Business profile', exact: true }) }).click();
        await expect(page.getByRole('heading', { name: 'What kind of organization are you?' })).toBeVisible();
        await expect(page.getByLabel('Organization type')).toHaveValue('field_service');
        await expect(page.locator('#business-specialties option')).toHaveCount(5);
        await page.getByRole('link', { name: '2 · How you work' }).click();
        await expect(page.getByLabel('What do you offer?')).toHaveValue('both');
        await page.getByRole('link', { name: '3 · Your first goal' }).click();
        await expect(page.getByRole('heading', { name: 'What would you like to accomplish first?' })).toBeVisible();
        await page.getByText('What will changing this profile affect?').click();
        await expect(page.getByText('It changes suggested next steps and guidance.', { exact: false })).toBeVisible();
        expect(writes).toEqual([]); expect(errors).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.evaluate(() => { for (const el of document.querySelectorAll<HTMLElement>('*')) if (el.scrollTop) el.scrollTop = 0; });
        await page.screenshot({ path: `storage/framework/testing/guided-settings/profile-${width}-${info.project.name}.png`, fullPage: true });
    });
}

test('member settings contain no owner-only profile or financial destinations', async ({ page }) => {
    const {errors,writes} = await settingsFixture(page, 'settings-member');
    await expect(page.getByRole('heading', { name: 'Business profile', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Agreements & billing', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Password', exact: true })).toBeVisible();
    expect(errors).toEqual([]); expect(writes).toEqual([]);
});

test('setup guide separates real progress optional reminders and personal preferences', async ({ page }) => {
    const {errors,writes} = await settingsFixture(page, 'setup');
    await expect(page.getByRole('heading', { name: 'A good place to start' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /starting steps complete/ })).toBeVisible();
    await page.getByText('Control your setup guidance').click();
    await expect(page.getByLabel('Hide the setup reminder on my home screen')).toBeVisible();
    expect(errors).toEqual([]); expect(writes).toEqual([]);
});

test('new owner resumes a saved questionnaire and only saves an account draft', async ({ page }) => {
    const {errors,writes} = await settingsFixture(page, 'new-owner-popup');
    await expect(page.locator('[data-step="3"]')).toBeVisible();
    await expect(page.locator('[data-name-input]')).toHaveValue('Fictional Artisan Studio');
    await page.locator('[data-pick-team="2_5"]').click();
    await expect.poll(() => writes.length).toBeGreaterThan(0);
    await expect(page.getByRole('status').filter({ hasText: 'Progress saved to your account' })).toBeVisible();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Skip for now', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Skip for now', exact: true }).click();
    await expect(page.locator('[data-step="5"]')).toBeVisible();
    expect(writes.every(row => row.url === '/workspace/create/draft' && row.body.fields.workspace_name === 'Fictional Artisan Studio')).toBe(true);
    expect(errors).toEqual([]);
});


test('the alternate first-login page also restores saved answers and reports draft saves', async ({ page }) => {
    const {errors,writes} = await settingsFixture(page, 'new-owner-page');
    await expect(page.locator('input[name="workspace_name"]')).toHaveValue('Fictional Artisan Studio');
    await page.locator('input[name="workspace_name"]').fill('Fictional Restored Business');
    await expect.poll(() => writes.length).toBeGreaterThan(0);
    await expect(page.getByRole('status').filter({ hasText: 'Progress saved to your account' })).toBeVisible();
    expect(writes.every(row => row.url === '/workspace/create/draft' && row.body.fields.workspace_name === 'Fictional Restored Business')).toBe(true);
    expect(errors).toEqual([]);
});
