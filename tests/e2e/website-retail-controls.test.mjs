import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {chromium, webkit, expect} from '@playwright/test';

const root = fileURLToPath(new URL('../../', import.meta.url));
// Real Blade + shipped JS/CSS. This synthetic server records only fictional
// selections; full application HTTP/tenant/stock checks live in the Pest suite.
async function retailFixture() {
    const submitted = [];
    let origin;
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, origin);
        if (request.method === 'GET' && ['/js/website-retail.js', '/css/website-retail.css'].includes(url.pathname)) {
            response.writeHead(200, {'Content-Type': url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'});
            response.end(fs.readFileSync(path.join(root, 'public', url.pathname))); return;
        }
        const design = /^\/product\/(native|sawyer|gated)$/.exec(url.pathname)?.[1]
            || (/^\/layout\/(starter|native|serif|sawyer)$/.exec(url.pathname)?.[1] ? 'layout-' + url.pathname.split('/').at(-1) : null);
        if (request.method === 'GET' && design) {
            const selected = url.searchParams.get('variant') || '2';
            const rendered = spawnSync('php', ['tests/Fixtures/ManagedWebsite/retail-controls.php', origin, design, selected], {cwd: root, encoding: 'utf8', env: {...process.env, APP_ENV: 'testing', APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', CACHE_STORE: 'array', SESSION_DRIVER: 'array', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', VIEW_COMPILED_PATH: path.join(root, 'storage/framework/views'), TELESCOPE_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', PULSE_ENABLED: 'false'}});
            if (rendered.status !== 0) { response.writeHead(500); response.end('Fictional rendering failed'); return; }
            response.writeHead(200, {'Content-Type': 'text/html'}); response.end(rendered.stdout); return;
        }
        if (request.method === 'GET' && url.pathname === '/products/fictional-linen-shirt') {
            response.writeHead(302, {Location: '/product/native?' + url.searchParams}); response.end(); return;
        }
        if (request.method === 'POST' && /^\/cart\/items\/[1-9][0-9]*$/.test(url.pathname)) {
            let body = ''; for await (const chunk of request) body += chunk;
            submitted.push({path: url.pathname, values: Object.fromEntries(new URLSearchParams(body))});
            response.writeHead(200, {'Content-Type': 'text/html'}); response.end('<p>Fictional bag saved</p>'); return;
        }
        response.writeHead(404); response.end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    return {origin, submitted, close: () => new Promise(resolve => server.close(resolve))};
}

for (const [name, engine] of [['Chromium', chromium], ['WebKit', webkit]]) {
    test(`native layout ${name}: starter sans and explicit legacy typography compute correctly on phone and desktop`, async () => {
        const fixture = await retailFixture(); const browser = await engine.launch({headless: true});
        try {
            const context = await browser.newContext({viewport: {width: 390, height: 900}});
            await context.route('**/*', route => new URL(route.request().url()).origin === fixture.origin ? route.continue() : route.abort());
            const page = await context.newPage();
            const observations = [];
            for (const width of [390, 1440]) {
                await page.setViewportSize({width, height: 900});
                for (const design of ['starter', 'native', 'serif', 'sawyer']) {
                    assert.equal((await page.goto(fixture.origin + '/layout/' + design)).status(), 200, 'Actual complete native Blade layout renders');
                    const observed = await page.evaluate(() => ({body: getComputedStyle(document.body).fontFamily, heading: getComputedStyle(document.querySelector('h1')).fontFamily,
                        token: getComputedStyle(document.documentElement).getPropertyValue('--font').trim(), valid: CSS.supports('font-family', getComputedStyle(document.documentElement).getPropertyValue('--font'))}));
                    observations.push({width, design, ...observed});
                    assert.equal(observed.valid, true, `${design}: the real layout emits a valid CSS font token`);
                    assert.doesNotMatch(observed.body + observed.heading, /Times New Roman/);
                    if (design === 'starter') { assert.match(observed.body, /^Inter,/); assert.equal(observed.heading, observed.body); }
                    if (design === 'native') { assert.match(observed.body, /^ui-sans-serif,/); assert.equal(observed.heading, observed.body); }
                    if (design === 'serif') { assert.match(observed.body, /^Georgia,/); assert.equal(observed.heading, observed.body); }
                    if (design === 'sawyer') { assert.match(observed.body, /DM Sans/); assert.match(observed.heading, /Fraunces/); }
                    await expect(page.getByText('Private draft preview — changes are not public.')).toBeVisible();
                }
            }
            console.log(JSON.stringify({check: 'actual-native-layout-computed-fonts', engine: name, observations}));
            await context.close();
        } finally { await browser.close(); await fixture.close(); }
    });
    for (const width of [320, 390, 1440]) {
        test(`retail ${name} ${width}: both designs retain correct variant, price, stock and keyboard controls`, async () => {
            const fixture = await retailFixture(); const browser = await engine.launch({headless: true});
            try {
                const context = await browser.newContext({viewport: {width, height: 900}, reducedMotion: 'reduce'});
                await context.route('**/*', route => new URL(route.request().url()).origin === fixture.origin ? route.continue() : route.abort());
                const page = await context.newPage(); const errors = []; page.on('pageerror', error => errors.push(error.message));
                for (const design of ['native', 'sawyer']) {
                    assert.equal((await page.goto(`${fixture.origin}/product/${design}`)).status(), 200, 'Actual Blade fixture renders successfully');
                    const select = page.getByLabel('Choose an option');
                    await expect(page.getByRole('button', {name: 'View option', exact: true})).toBeHidden();
                    await expect(select.locator('option')).toHaveCount(100);
                    await select.selectOption('3'); await expect(page.locator('[data-variant-price]')).toHaveText('$3.00');
                    await expect(page.locator('[data-variant-add]')).toBeDisabled();
                    await expect(page.getByRole('status')).toHaveText('Currently unavailable');
                    await select.focus(); await select.press('Home'); await select.press('ArrowDown');
                    await expect(select).toHaveValue('2'); await expect(page.locator('[data-variant-price]')).toHaveText('$39.50');
                    await expect(page.locator('[data-variant-compare]')).toHaveText('$49.50');
                    await expect(page.locator('[data-variant-add]')).toBeEnabled();
                    await expect(page.getByLabel('Quantity')).toHaveAttribute('max', '2');
                    await select.selectOption('100'); await expect(page.locator('[data-variant-price]')).toHaveText('$100.00');
                    await expect(page.locator('[data-variant-compare]')).toBeHidden();
                    await page.getByLabel('Quantity').fill('10'); await select.selectOption('2');
                    await expect(page.getByLabel('Quantity')).toHaveValue('2');
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Controls fit the phone viewport');
                    const controls = await page.locator('select, input[type=number], button:not([hidden])').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().height));
                    assert.ok(controls.every(height => height >= 44), 'Every visible control is touch sized');
                    await page.locator('[data-variant-add]').click(); await page.getByText('Fictional bag saved').waitFor();
                    const posted = fixture.submitted.at(-1); assert.equal(posted.path, '/cart/items/2');
                    assert.equal(posted.values.product_id, '20'); assert.equal(posted.values.quantity, '2');
                    assert.equal(Object.keys(posted.values).some(key => /price|total|currency/.test(key)), false, 'No browser price is submitted');
                }
                assert.deepEqual(errors, []); await context.close();
            } finally { await browser.close(); await fixture.close(); }
        });
    }
    test(`retail ${name}: server-rendered no-JS fallback and gated browsing stay usable`, async () => {
        const fixture = await retailFixture(); const browser = await engine.launch({headless: true});
        try {
            const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 900}});
            await context.route('**/*', route => new URL(route.request().url()).origin === fixture.origin ? route.continue() : route.abort());
            const page = await context.newPage(); assert.equal((await page.goto(fixture.origin + '/product/native')).status(), 200, 'Actual Blade fixture renders successfully');
            await page.getByLabel('Choose an option').selectOption('100');
            await page.getByRole('button', {name: 'View option', exact: true}).click();
            await expect(page.locator('[data-variant-price]')).toHaveText('$100.00');
            await expect(page.locator('[data-variant-cart-form]')).toHaveAttribute('action', fixture.origin + '/cart/items/100');
            await page.goto(fixture.origin + '/product/gated');
            await expect(page.getByText('Online shopping is not available on this address.')).toBeVisible();
            assert.equal(await page.locator('form[method=POST]').count(), 0);
            assert.equal(fixture.submitted.length, 0); await context.close();
        } finally { await browser.close(); await fixture.close(); }
    });
}
