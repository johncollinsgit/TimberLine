import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const fixtures = path.join(root, 'storage/framework/testing/freight');

test('desktop and mobile freight operators review an exact cost and cannot rebook unknown attempts', async () => {
    // Real Blade views with fictional database/provider fixtures only.
    execFileSync('php', ['vendor/bin/pest', 'tests/Feature/ManagedWebsite/WebsiteFreightProviderTest.php', '--compact'], {
        cwd: root, env: {...process.env, FREIGHT_PROVIDER_FIXTURES: '1'}, stdio: 'pipe', timeout: 120000,
    });
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const css = Object.entries(manifest).filter(([key]) => key.endsWith('.css') && key.includes('app'))
        .map(([, value]) => fs.readFileSync(path.join(root, 'public/build', value.file), 'utf8')).join('\n');
    const browser = await chromium.launch({headless: true, ...(process.platform === 'win32' ? {channel: 'msedge'} : {})});
    try {
        for (const width of [1440, 390]) {
            for (const view of ['quotes', 'booking', 'unknown']) {
                const page = await browser.newPage({viewport: {width, height: 1000}});
                const raw = fs.readFileSync(path.join(fixtures, `${view}.html`), 'utf8');
                const html = raw.replace('</head>', `<style>${css}</style></head>`);
                let bookingRequest;
                await page.route('**/*', async route => {
                    if (route.request().url() === 'https://freight.test/review') return route.fulfill({contentType: 'text/html', body: html});
                    if (route.request().method() === 'POST' && new URL(route.request().url()).pathname.endsWith('/freight/book')) {
                        bookingRequest = new URLSearchParams(route.request().postData());
                        return route.fulfill({contentType: 'text/html', body: '<p>Fictional intercepted booking submission</p>'});
                    }
                    return route.abort(); // No provider, app, font or third-party traffic.
                });
                await page.goto('https://freight.test/review');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${view} at ${width}px overflows`);
                assert.equal(await page.locator('input[name="api_key"]').inputValue(), '');
                assert.equal(await page.getByRole('heading', {name: 'Freight quotes and booking records'}).count(), 1);
                if (view === 'unknown') {
                    assert.equal(await page.getByRole('button', {name: /Book approved freight/}).count(), 0);
                    assert.match(await page.getByRole('status').innerText(), /outcome is not confirmed/);
                    assert.equal(await page.getByRole('button', {name: 'Check original booking status'}).count(), 1);
                }
                if (view === 'booking') {
                    const form = page.locator('form').filter({has: page.getByRole('button', {name: /Book approved freight/})});
                    await form.scrollIntoViewIfNeeded();
                    if (process.env.FREIGHT_SCREENSHOT_DIR) {
                        fs.mkdirSync(process.env.FREIGHT_SCREENSHOT_DIR, {recursive: true});
                        await page.screenshot({path: path.join(process.env.FREIGHT_SCREENSHOT_DIR, `ltl-booking-${width}.png`)});
                    }
                    await form.locator('[name="ready_time"]').fill('09:00');
                    await form.locator('[name="close_time"]').fill('16:30');
                    assert.equal(await form.evaluate(element => element.checkValidity()), false);
                    await form.locator('[name="confirmed"]').check();
                    assert.equal(await form.evaluate(element => element.checkValidity()), true);
                    await form.getByRole('button', {name: /Book approved freight/}).click();
                    await page.getByText('Fictional intercepted booking submission').waitFor();
                    assert.equal(bookingRequest.get('amount_cents'), '84237');
                    assert.equal(bookingRequest.get('ready_time'), '09:00');
                    assert.equal(bookingRequest.get('confirmed'), '1');
                } else if (process.env.FREIGHT_SCREENSHOT_DIR) {
                    await page.getByRole('heading', {name: view === 'unknown' ? 'Original API operation' : 'Freight account'}).scrollIntoViewIfNeeded();
                    await page.screenshot({path: path.join(process.env.FREIGHT_SCREENSHOT_DIR, `ltl-${view}-${width}.png`)});
                }
                await page.close();
            }
        }
    } finally {
        await browser.close();
    }
});
