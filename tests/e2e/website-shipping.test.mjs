import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium} from 'playwright';

// Execute the shared storefront controls against intercepted fictional HTTP only.
const source = fs.readFileSync(new URL('../../resources/views/managed-website/shipping-fields.blade.php', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const now = new Date('2026-11-08T12:00:00Z');
const quote = {id: 1, carrier: 'FedEx', service: 'GROUND_HOME_DELIVERY', amount_cents: 725, delivery_days: 3,
    expires_at: '2026-11-08T12:20:00Z', mode: 'test'};
const html = `<form><input name="_token" value="fictional"><input name="name" value="Fictional buyer">
<select name="fulfillment_method"><option value="ship">Ship</option><option value="pickup">Pickup</option></select>
<fieldset id="website-shipping-fields" data-rates-url="https://shipping.test/rates" data-checkout-ready="1" data-threshold="0" data-subtotal="2000">
${['name','street1','city','state','zip','country'].map(key => `<input name="shipping_address[${key}]" value="${key === 'country' ? 'US' : 'Fixture'}">`).join('')}
<select name="shipping_address[residential]"><option value="1">Home</option><option value="0">Business</option></select>
<button type="button" data-find-rates>Find shipping options</button><div data-shipping-rates role="status" aria-live="polite"></div></fieldset>
<span data-shipping-price></span><span data-estimated-total></span><button type="submit">Continue to payment</button></form><script>${script}</script>`;

test('desktop and mobile shoppers select, expire, and replace rates without stale payment approval', async () => {
    const browser = await chromium.launch({headless: true, ...(process.platform === 'win32' ? {channel: 'msedge'} : {})});
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport: {width, height: 900}});
            const unexpected = [];
            let requestBody;
            await page.clock.install({time: now});
            await page.route('**/*', async route => {
                const url = route.request().url();
                if (url === 'https://shipping.test/cart') return route.fulfill({contentType: 'text/html', body: html});
                if (url === 'https://shipping.test/rates') {
                    requestBody = route.request().postDataJSON();
                    return route.fulfill({json: {quotes: [quote]}});
                }
                unexpected.push(url);
                return route.abort();
            });
            await page.goto('https://shipping.test/cart');
            const pay = page.getByRole('button', {name: 'Continue to payment'});
            assert.equal(await pay.isDisabled(), true);
            await page.getByRole('button', {name: 'Find shipping options'}).click();
            await page.getByRole('radio').check();
            assert.equal(await pay.isEnabled(), true);
            assert.match(await page.locator('[data-shipping-rates]').innerText(), /3 days after handoff/);
            assert.equal(await page.locator('[data-shipping-price]').innerText(), '$7.25');
            assert.equal(await page.locator('[data-estimated-total]').innerText(), '$27.25');
            assert.equal(requestBody.shipping_address.residential, '1');
            await page.clock.fastForward('20:01');
            assert.equal(await pay.isDisabled(), true);
            assert.equal(await page.getByRole('radio').count(), 0);
            assert.match(await page.locator('[data-shipping-rates]').innerText(), /expired/);
            await page.locator('[name="fulfillment_method"]').selectOption('pickup');
            assert.equal(await pay.isEnabled(), true);
            assert.equal(await page.locator('#website-shipping-fields').isVisible(), false);
            assert.deepEqual(unexpected, []);
            await page.close();
        }
    } finally {
        await browser.close();
    }
});

test('changed addresses abort old rate requests and empty results explain recovery', async () => {
    const browser = await chromium.launch({headless: true, ...(process.platform === 'win32' ? {channel: 'msedge'} : {})});
    try {
        const page = await browser.newPage();
        await page.clock.install({time: now});
        let release, started;
        const requested = new Promise(resolve => {started = resolve;});
        await page.route('**/*', async route => {
            if (route.request().url().endsWith('/cart')) return route.fulfill({contentType: 'text/html', body: html});
            if (route.request().url().endsWith('/rates')) {
                started();
                await new Promise(resolve => {release = resolve;});
                return route.fulfill({json: {quotes: [quote]}}).catch(() => {});
            }
            return route.abort();
        });
        await page.goto('https://shipping.test/cart');
        await page.getByRole('button', {name: 'Find shipping options'}).click();
        await requested;
        await page.locator('[name="shipping_address[zip]"]').fill('78702');
        release();
        await page.waitForTimeout(30);
        assert.equal(await page.getByRole('radio').count(), 0);
        assert.equal(await page.getByRole('button', {name: 'Continue to payment'}).isDisabled(), true);
        await page.unroute('**/*');
        await page.route('**/*', route => route.fulfill({json: {quotes: []}}));
        await page.getByRole('button', {name: 'Find shipping options'}).click();
        await page.getByText(/No shipping options are available/).waitFor();
        assert.match(await page.locator('[data-shipping-rates]').innerText(), /contact the store/);
        assert.equal(await page.getByRole('button', {name: 'Continue to payment'}).isDisabled(), true);
    } finally {
        await browser.close();
    }
});
