import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const fixtures = path.join(root, 'storage/framework/testing/freight');

test('pre-order freight setup is usable on desktop and mobile without purchasing', async () => {
    execFileSync('php', ['vendor/bin/pest', 'tests/Feature/ManagedWebsite/WebsiteFreightSetupTest.php', '--compact'], {
        cwd: root, env: {...process.env, FREIGHT_SETUP_FIXTURES: '1'}, stdio: 'pipe', timeout: 120000,
    });
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const css = Object.entries(manifest).filter(([key]) => key.endsWith('.css') && key.includes('app'))
        .map(([, value]) => fs.readFileSync(path.join(root, 'public/build', value.file), 'utf8')).join('\n');
    const browser = await chromium.launch({headless: true, ...(process.platform === 'win32' ? {channel: 'msedge'} : {})});
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            let saved;
            const html = fs.readFileSync(path.join(fixtures, 'setup.html'), 'utf8').replace('</head>', `<style>${css}</style></head>`);
            await page.route('**/*', route => {
                if (route.request().url() === 'https://freight.test/setup') return route.fulfill({contentType: 'text/html', body: html});
                if (route.request().method() === 'POST' && new URL(route.request().url()).pathname.endsWith('/commerce/freight')) {
                    saved = new URLSearchParams(route.request().postData());
                    return route.fulfill({contentType: 'text/html', body: '<p>Fictional account check only</p>'});
                }
                return route.abort();
            });
            await page.goto('https://freight.test/setup');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `setup at ${width}px overflows`);
            await page.getByRole('heading', {name: 'Set up pallet freight'}).waitFor();
            await page.getByText('Awaiting launch approval', {exact: false}).waitFor();
            await page.locator('summary').filter({hasText: 'Connect a freight account'}).click();
            const form = page.locator('form').filter({has: page.getByRole('button', {name: 'Verify and save freight account'})});
            await form.locator('[name="label"]').fill('Fictional freight account');
            await form.locator('[name="api_key"]').fill('fictional_freight_setup_key_12345');
            assert.equal(await form.evaluate(element => element.checkValidity()), false);
            await form.locator('[name="billing_confirmed"]').check();
            if (process.env.FREIGHT_SCREENSHOT_DIR) {
                fs.mkdirSync(process.env.FREIGHT_SCREENSHOT_DIR, {recursive: true});
                await page.screenshot({path: path.join(process.env.FREIGHT_SCREENSHOT_DIR, `ltl-setup-${width}.png`)});
            }
            await form.getByRole('button', {name: 'Verify and save freight account'}).click();
            await page.getByText('Fictional account check only').waitFor();
            assert.equal(saved.get('billing_confirmed'), '1');
            assert.equal(saved.get('label'), 'Fictional freight account');
            await page.close();
        }
    } finally {
        await browser.close();
    }
});

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
            for (const view of ['quotes', 'booking', 'booked', 'unknown']) {
                const page = await browser.newPage({viewport: {width, height: 1000}});
                const raw = fs.readFileSync(path.join(fixtures, `${view}.html`), 'utf8');
                const html = raw.replace('</head>', `<style>${css}</style></head>`);
                let bookingRequest;
                let handoffRequest;
                await page.route('**/*', async route => {
                    if (route.request().url() === 'https://freight.test/review') return route.fulfill({contentType: 'text/html', body: html});
                    if (route.request().method() === 'POST' && new URL(route.request().url()).pathname.endsWith('/freight/book')) {
                        bookingRequest = new URLSearchParams(route.request().postData());
                        return route.fulfill({contentType: 'text/html', body: '<p>Fictional intercepted booking submission</p>'});
                    }
                    if (route.request().method() === 'POST' && new URL(route.request().url()).pathname.endsWith('/freight/handoff')) {
                        handoffRequest = new URLSearchParams(route.request().postData());
                        return route.fulfill({contentType: 'text/html', body: '<p>Fictional intercepted handoff submission</p>'});
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
                } else if (view === 'booked') {
                    const details = page.locator('details').filter({has: page.locator('summary').filter({hasText: 'Confirm physical carrier handoff'})});
                    await details.locator('summary').click();
                    const form = details.locator('form');
                    await form.scrollIntoViewIfNeeded();
                    await form.locator('[name="evidence_reference"]').fill('Fictional signed BOL reference');
                    assert.equal(await form.evaluate(element => element.checkValidity()), false);
                    await form.locator('[name="confirmed"]').check();
                    if (process.env.FREIGHT_SCREENSHOT_DIR) {
                        await page.screenshot({path: path.join(process.env.FREIGHT_SCREENSHOT_DIR, `ltl-handoff-${width}.png`)});
                    }
                    await form.getByRole('button', {name: 'Record physical handoff'}).click();
                    await page.getByText('Fictional intercepted handoff submission').waitFor();
                    assert.equal(handoffRequest.get('evidence_reference'), 'Fictional signed BOL reference');
                    assert.equal(handoffRequest.get('confirmed'), '1');
                    assert.equal(bookingRequest, undefined);
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
