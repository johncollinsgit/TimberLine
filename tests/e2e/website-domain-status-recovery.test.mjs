import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import {chromium, expect} from '@playwright/test';

const view = fs.readFileSync(new URL('../../resources/views/managed-website/domain-guide.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'Execute the actual domain guide script.');

// Owned-read client protocol only. No provider, DNS change, domain mutation,
// account creation or billing action; every non-GET is rejected and recorded.
async function fixture(responses, options = {}) {
    const requests = [], writes = [], errors = [], external = [];
    let origin, checks = 0, reads = 0, heldClosed = 0;
    const plan = '/website?tenant=fictional-domain-owner';
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, origin);
        requests.push({method: request.method, path: url.pathname});
        if (request.method !== 'GET') { writes.push(url.pathname); response.writeHead(405); response.end(); return; }
        if (url.pathname === '/favicon.ico') { response.writeHead(204); response.end(); return; }
        if (url.pathname === '/website/domains/7/status') {
            const result = responses[Math.min(checks++, responses.length - 1)];
            response.writeHead(result.status ?? 200, {'Content-Type': result.html ? 'text/html' : 'application/json'});
            if (options.holdFirst && checks === 1) {
                response.flushHeaders(); response.write('{"status":');
                response.on('close', () => heldClosed++); return;
            }
            response.end(result.html ?? JSON.stringify(result.body)); return;
        }
        if (url.pathname === '/website') {
            reads++;
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(reads > 1 ? '<!doctype html><h1>Saved domains</h1>' : `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><article data-domain-status-url="/website/domains/7/status?tenant=fictional-domain-owner"><p role="status" data-domain-progress>We will check automatically.</p><p hidden data-domain-recovery><a href="${plan}"><span data-domain-recovery-label>Review domains</span></a></p></article><script>${script}</script>`);
            return;
        }
        response.writeHead(404); response.end();
    });
    await new Promise(done => server.listen(0, '127.0.0.1', done));
    origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch(process.platform === 'win32' ? {channel: 'msedge'} : {});
    const page = await browser.newPage({viewport: {width: options.width ?? 390, height: 844}});
    page.setDefaultTimeout(5000);
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
        if (new URL(route.request().url()).origin === origin) return route.continue();
        external.push(new URL(route.request().url()).origin); return route.abort();
    });
    await page.clock.install();
    await page.goto(origin + plan);
    return {
        page, requests, writes, errors, external,
        get checks() { return checks; }, get reads() { return reads; }, get heldClosed() { return heldClosed; },
        async tick(milliseconds, count) { await page.clock.runFor(milliseconds); await expect.poll(() => checks).toBe(count); },
        async close() { await browser.close(); server.closeAllConnections(); await new Promise(done => server.close(done)); },
    };
}

for (const [status, message, action] of [
    [401, 'Sign in to check this domain', 'Sign in'],
    [419, 'Sign in to check this domain', 'Sign in'],
    [403, 'Your access changed', 'Review domains'],
    [404, 'no longer available', 'Review domains'],
    [423, 'setup is paused', 'Review domains'],
]) {
    test(`domain status ${status} stops with truthful recovery and no mutation`, async () => {
        const f = await fixture([{status, body: {message: 'PRIVATE-PROVIDER-PAYLOAD'}}]);
        try {
            await f.tick(15000, 1);
            await expect(f.page.locator('[data-domain-progress]')).toContainText(message);
            const recovery = f.page.getByRole('link', {name: action, exact: true});
            await expect(recovery).toBeVisible();
            await expect(f.page.locator('body')).not.toContainText('PRIVATE-PROVIDER-PAYLOAD');
            await f.page.clock.runFor(120000);
            assert.equal(f.checks, 1, 'Terminal/session failures do not keep polling.');
            await recovery.click();
            await expect(f.page.getByRole('heading', {name: 'Saved domains'})).toBeVisible();
            assert.equal(f.reads, 2, 'Explicit recovery follows only the original owned Website GET.');
            assert.deepEqual(f.writes, []); assert.deepEqual(f.errors, []); assert.deepEqual(f.external, []);
        } finally { await f.close(); }
    });
}

test('disabled domain stops checking and offers owned review', async () => {
    const f = await fixture([{body: {status: 'disabled', message: 'PRIVATE-PROVIDER-PAYLOAD'}}], {width: 1440});
    try {
        await f.tick(15000, 1);
        await expect(f.page.locator('[data-domain-progress]')).toContainText('connection is disabled');
        await expect(f.page.getByRole('link', {name: 'Review domains', exact: true})).toBeVisible();
        await f.page.clock.runFor(120000);
        assert.equal(f.checks, 1); assert.equal(f.reads, 1);
        assert.deepEqual(f.writes, []); assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('an active domain reloads the same owned Website GET once', async () => {
    const f = await fixture([{body: {status: 'active', message: 'Connected'}}]);
    try {
        await f.tick(15000, 1);
        await expect(f.page.getByRole('heading', {name: 'Saved domains'})).toBeVisible();
        assert.equal(f.reads, 2); assert.equal(f.checks, 1);
        assert.deepEqual(f.writes, []); assert.deepEqual(f.errors, []); assert.deepEqual(f.external, []);
    } finally { await f.close(); }
});

test('transient and unreadable status results retry the same domain read', async () => {
    const f = await fixture([{status: 503, body: {}}, {html: '<html>Temporary outage</html>'}, {body: null}, {body: {status: 'pending', message: 'Checking the saved DNS records.'}}]);
    try {
        for (let count = 1; count <= 4; count++) await f.tick(15000, count);
        await expect(f.page.locator('[data-domain-progress]')).toHaveText('Checking the saved DNS records.');
        await expect(f.page.getByRole('link', {name: 'Review domains', exact: true})).toBeHidden();
        assert.equal(f.reads, 1); assert.deepEqual(f.writes, []); assert.deepEqual(f.errors, []); assert.deepEqual(f.external, []);
    } finally { await f.close(); }
});

for (const status of [200, 503]) test(`a held ${status} domain response is closed before the next saved-domain read`, async () => {
    const pending = {status: 'pending', message: 'Checking the saved DNS records.'};
    const f = await fixture([{status, body: pending}, {body: pending}], {holdFirst: true});
    try {
        await f.tick(15000, 1);
        // Unused error bodies close immediately; a successful but incomplete
        // JSON body is bounded by the actual request deadline.
        if (status === 200) await f.page.clock.runFor(10000);
        await expect.poll(() => f.heldClosed).toBe(1);
        await f.tick(15000, 2);
        await expect(f.page.locator('[data-domain-progress]')).toHaveText('Checking the saved DNS records.');
        assert.equal(f.reads, 1); assert.deepEqual(f.writes, []); assert.deepEqual(f.errors, []); assert.deepEqual(f.external, []);
    } finally { await f.close(); }
});
