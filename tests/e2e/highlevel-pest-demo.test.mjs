import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from '@playwright/test';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/highlevel/pest-demo.js'];
const screenshotDir = process.env.FLEET_PEST_SCREENSHOT_DIR;

for (const browserType of [chromium, webkit]) {
    test(`${browserType.name()}: 250-truck demo workflow, storage, accessibility, responsive states and no external actions`, { timeout: 120000 }, async () => {
        const unexpected = [], errors = [];
        const server = createServer((req, res) => {
            if (req.url === '/demo') {
                res.setHeader('Content-Type', 'text/html');
                return res.end(`<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fleet pest preview</title>${(entry.css || []).map(css => `<link rel="stylesheet" href="/build/${css}">`).join('')}</head><body><div id="pest-demo"></div><script type="module" src="/build/${entry.file}"></script></body></html>`);
            }
            if (req.url.startsWith('/build/') && !req.url.includes('..')) {
                res.setHeader('Content-Type', req.url.endsWith('.css') ? 'text/css' : 'application/javascript');
                return res.end(readFileSync(`public${req.url}`));
            }
            unexpected.push(req.url); res.statusCode = 404; res.end();
        });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = `http://127.0.0.1:${server.address().port}`;
        let browser;
        try { browser = await browserType.launch(); }
        catch (error) { await new Promise(resolve => server.close(resolve)); throw error; }
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('pageerror', err => errors.push(err.message));
        await page.route('**/*', async route => {
            const req = route.request();
            if (!req.url().startsWith(origin) || req.method() !== 'GET') { unexpected.push(`${req.method()} ${req.url()}`); return route.abort(); }
            await route.continue();
        });
        const nav = name => page.getByRole('navigation', { name: 'Fleet navigation' }).getByRole('button', { name, exact: true }).click();
        const shot = async name => {
            if (screenshotDir && browserType.name() === 'chromium') {
                mkdirSync(screenshotDir, { recursive: true });
                await page.screenshot({ path: `${screenshotDir}/${name}.png`, fullPage: true });
            }
        };
        try {
            const started = Date.now();
            await page.goto(`${origin}/demo`);
            await page.getByRole('heading', { name: 'Overview', exact: true }).waitFor();
            assert(Date.now() - started < 10000, 'local synthetic demo should render promptly');
            assert.match(await page.locator('.filter-count').innerText(), /250 of 250/);
            await shot('desktop-overview');
            await page.getByRole('button', { name: 'Open urgent callback', exact: true }).click();
            await page.getByRole('button', { name: 'Suggest crews', exact: true }).click();
            assert.match(await page.locator('.dispatch-results').innerText(), /eligible crews|straight-line|stop slots|No overlapping/);
            await page.getByRole('button', { name: 'Assign in demo', exact: true }).first().click();
            await page.getByRole('status').filter({ hasText: 'Demo assignment saved' }).waitFor();
            const assigned = await page.locator('.job-detail').innerText();
            assert.match(assigned, /Assigned truck: Truck/);
            await shot('desktop-dispatch');
            await page.getByRole('button', { name: 'Preview customer message', exact: true }).click();
            await page.getByRole('dialog').waitFor();
            assert.match(await page.getByRole('dialog').innerText(), /Preview only · not sent|no send action/);
            assert.equal(await page.getByRole('dialog').getByRole('button').count(), 1);
            await shot('message-preview');
            await page.keyboard.press('Escape');
            await nav('Overview');
            await page.locator('#attention-kind').selectOption('Out of service');
            await page.locator('.attention-row').first().click();
            assert.match(await page.locator('.vehicle-panel').innerText(), /Out of service/);
            await nav('Fleet map');
            assert.equal(await page.locator('.vehicle-row').count(), 25);
            assert.equal(await page.locator('.map-cluster').count(), 4);
            await page.locator('.fleet-list').getByRole('button', { name: 'Next', exact: true }).click();
            assert.match(await page.locator('.vehicle-panel').innerText(), /Truck 051/);
            await page.getByRole('button', { name: 'Open Greenville, 63 trucks', exact: true }).click();
            assert.match(await page.locator('.filter-count').innerText(), /63 of 250/);
            await page.getByRole('button', { name: /Open North,/ }).click();
            assert.equal(await page.locator('.map-pin').count(), 16);
            await shot('desktop-fleet');
            await page.getByRole('button', { name: 'Clear', exact: true }).click();
            await page.getByLabel('Search', { exact: true }).fill('Truck 250');
            assert.equal(await page.locator('.vehicle-row').count(), 1);
            assert.match(await page.locator('.vehicle-panel').innerText(), /Truck 250/);
            await page.getByLabel('Search', { exact: true }).fill('no-matching-truck');
            await page.getByRole('heading', { name: 'No trucks match these filters' }).waitFor();
            await shot('empty-search');
            await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
            await nav('Setup');
            assert.equal(await page.locator('[data-setup]:disabled').count(), 4);
            for (let i = 0; i < 5; i++) await page.locator(`[data-setup="${i}"]`).click();
            assert.match(await page.locator('#demo-view').innerText(), /5 of 5 rehearsal steps complete/);
            await shot('desktop-setup');
            await nav('Settings');
            await page.getByLabel('Distance units', { exact: true }).selectOption('km');
            await page.getByLabel('Default branch on reload', { exact: true }).selectOption('Greer');
            await nav('Overview'); await nav('Settings'); // interrupted preference editing is preserved
            assert.equal(await page.getByLabel('Distance units', { exact: true }).inputValue(), 'km');
            await page.getByLabel(/Simulate a provider outage/).check();
            await page.getByRole('button', { name: 'Save demo preferences', exact: true }).click();
            await shot('desktop-settings');
            await page.reload();
            await page.getByRole('heading', { name: 'Overview', exact: true }).waitFor();
            assert.equal(await page.getByLabel('Branch', { exact: true }).inputValue(), 'Greer');
            await page.getByText('Simulated provider outage', { exact: true }).waitFor();
            await page.getByRole('button', { name: 'Clear', exact: true }).click();
            await nav('Maintenance');
            await page.getByRole('button', { name: 'Record demo service', exact: true }).first().click();
            await page.getByLabel('Service notes / example invoice reference').fill('Fictional invoice DEMO-42; inspection completed.');
            await page.getByRole('button', { name: 'Save demo service', exact: true }).click();
            await page.getByText('Fictional invoice DEMO-42; inspection completed.', { exact: true }).waitFor();
            await nav('Health alerts');
            await page.getByRole('button', { name: 'Review demo alert', exact: true }).first().click();
            await page.getByLabel('Action taken / review reason').fill('Fictional battery inspection booked.');
            await page.getByRole('button', { name: 'Save demo review', exact: true }).click();
            await page.getByText('Fictional battery inspection booked.', { exact: true }).waitFor();
            await nav('Trips & routes');
            await page.locator('.ops-item summary').first().click();
            await page.getByRole('button', { name: 'Record demo review' }).first().click();
            await page.getByLabel('Action taken / review reason').fill('Fictional approved office detour.');
            await page.getByRole('button', { name: 'Save demo review', exact: true }).click();
            await nav('Connection health');
            assert.equal(await page.locator('tbody tr').count(), 50);
            for (const [width, height] of [[1440, 1000], [1024, 768], [768, 1024], [390, 844]]) {
                await page.setViewportSize({ width, height });
                for (const label of ['Overview', 'Fleet map', 'Jobs & dispatch', 'Trips & routes', 'Maintenance', 'Health alerts', 'Connection health', 'Setup', 'Settings']) {
                    await nav(label);
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${label} overflow at ${width}`);
                    assert.equal(await page.locator('.tabs').evaluate(el => {
                        const active = el.querySelector('[aria-current="page"]').getBoundingClientRect(), nav = el.getBoundingClientRect();
                        return active.left >= nav.left - 1 && active.right <= nav.right + 1;
                    }), true, `active navigation visible: ${label} at ${width}`);
                    if ([1440, 768, 390].includes(width)) await shot(`${width === 1440 ? 'desktop' : width === 768 ? 'tablet' : 'mobile'}-${label.toLowerCase().replaceAll(' ', '-').replaceAll('&', 'and')}`);
                }
                if (width === 768) await shot('tablet-settings');
            }
            await page.getByRole('button', { name: 'Reset demo', exact: true }).click();
            await page.getByRole('button', { name: 'Cancel', exact: true }).click();
            assert.equal(await page.getByLabel('Distance units', { exact: true }).inputValue(), 'km');
            await page.getByRole('button', { name: 'Reset demo', exact: true }).click();
            await page.getByRole('button', { name: 'Reset fictional demo', exact: true }).click();
            await page.reload();
            await page.getByRole('heading', { name: 'Overview', exact: true }).waitFor();
            assert.equal(await page.getByText('Simulated provider outage', { exact: true }).count(), 0);
            await nav('Setup'); assert.match(await page.locator('#demo-view').innerText(), /0 of 5/);
            await page.evaluate(() => sessionStorage.setItem('everbranch.fleet.pest-demo.v1', '{invalid'));
            await page.reload();
            await page.getByRole('status').filter({ hasText: 'Fresh fictional examples loaded' }).waitFor();
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => ['A', 'BUTTON', 'INPUT', 'SELECT'].includes(document.activeElement.tagName)), true);
            await nav('Jobs & dispatch');
            await page.getByRole('button', { name: 'Suggest crews', exact: true }).focus();
            await page.keyboard.press('Enter');
            await page.locator('.dispatch-results').waitFor();
            await page.getByRole('button', { name: 'Preview customer message', exact: true }).focus();
            await page.keyboard.press('Enter');
            await page.getByRole('dialog').waitFor();
            await page.keyboard.press('Escape');
            assert.equal(await page.getByRole('dialog').isVisible(), false);
            assert.equal(await page.getByRole('button', { name: 'Preview customer message', exact: true }).evaluate(el => el === document.activeElement), true);
            await page.addInitScript(() => { Storage.prototype.setItem = () => { throw new Error('Storage disabled for fixture'); }; });
            await page.reload();
            await nav('Setup'); await page.locator('[data-setup="0"]').click();
            await page.getByRole('status').filter({ hasText: 'Changes are in memory only' }).waitFor();
            await page.reload(); await nav('Setup');
            assert.match(await page.locator('#demo-view').innerText(), /0 of 5/);
            assert.deepEqual(errors, []);
            assert.deepEqual(unexpected, []);
        } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
    });
}
