import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import {chromium, expect} from '@playwright/test';

const view = fs.readFileSync(new URL('../../resources/views/onboarding/self-service-purchase.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'Execute the production purchase-status script.');

// Client protocol only: actual inline production script, fictional loopback
// status responses, no provider, database, checkout submission or paid grants.
async function fixture(responses, width = 390, options = {}) {
    const requests = [], writes = [];
    let origin, checks = 0, planReads = 0;
    const plan = '/workspace/purchase?tenant=fictional-retained-owner';
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, origin);
        requests.push({method: request.method, path: url.pathname});
        if (request.method !== 'GET') { writes.push(url.pathname); response.writeHead(405); response.end(); return; }
        if (url.pathname === '/favicon.ico') { response.writeHead(204); response.end(); return; }
        if (url.pathname === '/workspace/purchase/status') {
            const result = responses[Math.min(checks++, responses.length - 1)];
            response.writeHead(result.status ?? 200, {'Content-Type': result.html ? 'text/html' : 'application/json'});
            response.end(result.html ?? JSON.stringify(result.body)); return;
        }
        if (url.pathname === '/workspace/purchase') {
            planReads++;
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(planReads > 1 ? '<!doctype html><h1>Saved plan review</h1>' : `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><main><h1>Your trial</h1><div data-purchase-pending data-status-url="/workspace/purchase/status?tenant=fictional-retained-owner"><p role="status" data-payment-status>Waiting for payment confirmation.</p><p hidden data-payment-recovery><a href="${plan}"><span data-payment-recovery-label>Check payment</span></a></p></div></main><script>${script}</script>`);
            return;
        }
        response.writeHead(404); response.end();
    });
    await new Promise(done => server.listen(0, '127.0.0.1', done));
    origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch(process.platform === 'win32' ? {channel: 'msedge'} : {});
    const page = await browser.newPage({viewport: {width, height: 900}});
    page.setDefaultTimeout(5000);
    const errors = [], external = [];
    let attempts = 0, aborted = false;
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (new URL(request.url()).pathname === '/workspace/purchase/status') attempts++; });
    await page.route('**/*', route => {
        if (new URL(route.request().url()).origin === origin) return route.continue();
        external.push(new URL(route.request().url()).origin); return route.abort();
    });
    if (options.abortFirstStatus) await page.route('**/workspace/purchase/status?**', route => {
        if (aborted) return route.continue();
        aborted = true; return route.abort('failed');
    });
    await page.clock.install();
    await page.goto(origin + plan);
    return {
        page, requests, writes, errors, external,
        get checks() { return checks; }, get attempts() { return attempts; }, get planReads() { return planReads; },
        async tick(milliseconds, count) { await page.clock.runFor(milliseconds); await expect.poll(() => checks).toBe(count); },
        async close() { await browser.close(); await new Promise(done => server.close(done)); },
    };
}

for (const body of [{paid: true, pending: false}, {paid: false, pending: false}]) {
    test(`a confirmed nonpending status returns to the saved plan with paid=${body.paid}`, async () => {
        const f = await fixture([{body}]);
        try {
            await f.tick(2000, 1);
            await expect(f.page.getByRole('heading', {name: 'Saved plan review'})).toBeVisible();
            assert.equal(f.planReads, 2, 'Only reload the existing owner-scoped plan GET.');
            assert.deepEqual(f.writes, []);
            assert.deepEqual(f.errors, []);
            assert.deepEqual(f.external, []);
        } finally { await f.close(); }
    });
}

for (const status of [401, 419]) {
    test(`an expired ${status} session offers sign-in through the saved plan without posting`, async () => {
        const f = await fixture([{status, body: {message: 'Fictional expired session'}}]);
        try {
            await f.tick(2000, 1);
            const recovery = f.page.getByRole('link', {name: 'Sign in', exact: true});
            await expect(recovery).toBeVisible();
            await expect(f.page.locator('[data-payment-status]')).toContainText('saved checkout');
            await f.page.clock.runFor(60000);
            assert.equal(f.checks, 1, 'Authentication failure stops background polling.');
            await recovery.click();
            await expect(f.page.getByRole('heading', {name: 'Saved plan review'})).toBeVisible();
            assert.equal(f.planReads, 2);
            assert.deepEqual(f.writes, []);
            assert.deepEqual(f.errors, []);
        } finally { await f.close(); }
    });
}

test('a network failure retries the same read without submitting or replacing checkout', async () => {
    const f = await fixture([{body: {paid: false, pending: true}}], 390, {abortFirstStatus: true});
    try {
        await f.page.clock.runFor(2000);
        await expect.poll(() => f.attempts).toBe(1);
        assert.equal(f.checks, 0);
        await f.tick(5000, 1);
        assert.equal(f.attempts, 2);
        assert.equal(f.planReads, 1);
        await expect(f.page.locator('[data-payment-status]')).toHaveText('Waiting for payment confirmation.');
        assert.deepEqual(f.writes, []);
        assert.deepEqual(f.errors, []);
        assert.deepEqual(f.external, []);
    } finally { await f.close(); }
});

test('unknown, transient and forbidden results retain the original bounded pending checks', async () => {
    const f = await fixture([
        {status: 503, body: {message: 'Fictional temporary failure'}}, {html: '<html>Fictional interrupted response</html>'},
        {status: 403, body: {paid: true, pending: false}},
        {body: {paid: 'true', pending: 'false'}}, {body: {paid: false, pending: true}},
    ]);
    try {
        for (let count = 1; count <= 24; count++) {
            await f.tick(count === 1 ? 2000 : 5000, count);
        }
        await f.page.clock.runFor(5000);
        await expect(f.page.locator('[data-payment-status]')).toContainText('Confirmation is taking longer');
        await expect(f.page.getByRole('link', {name: 'Check payment', exact: true})).toBeVisible();
        await f.page.clock.runFor(60000);
        assert.equal(f.checks, 24, 'The original 24 scheduled status checks are not extended.');
        assert.equal(f.planReads, 1, 'Unknown/forbidden results never reload into a claimed paid state.');
        assert.deepEqual(f.writes, []);
        assert.deepEqual(f.errors, []);
        assert.deepEqual(f.external, []);
    } finally { await f.close(); }
});
