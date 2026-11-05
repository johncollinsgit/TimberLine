import assert from 'node:assert/strict';
import test from 'node:test';
import worker, {handleRequest} from './src/index.js';

const host = 'https://backstage.theforestrystudio.com';
const staff = 'https://admin.shopify.com/store/modernforestrywholesale/apps/mf-wholesale-backstage/shopify/app/wholesale';
const noOrigin = () => { throw new Error('Entry pages must not call the application'); };

test('welcome page serves useful staff and customer destinations without a session', async () => {
    const response = await handleRequest(new Request(`${host}/?token=private-fixture`), noOrigin);
    const html = await response.text();
    assert.equal(response.status, 200);
    for (const href of [staff, 'https://theforestrystudio.com/', 'https://modernforestrywholesale.com/', 'mailto:info@theforestrystudio.com']) {
        assert.ok(html.includes(`href="${href}"`));
    }
    assert.ok(!html.includes('private-fixture'));
    assert.equal(response.headers.get('Set-Cookie'), null);
    assert.equal(response.headers.get('Referrer-Policy'), 'no-referrer');
});

test('HEAD returns the welcome headers without a document body', async () => {
    const response = await handleRequest(new Request(`${host}/`, {method: 'HEAD'}), noOrigin);
    assert.equal(response.status, 200);
    assert.equal(await response.text(), '');
    assert.match(response.headers.get('Content-Type'), /text\/html/);
});

for (const [path, destination] of [
    ['/login', 'https://app.theeverbranch.com/login'],
    ['/forgot-password', 'https://app.theeverbranch.com/forgot-password'],
    ['/shopify/app/wholesale', staff],
    ['/shopify/app/wholesale/labels', `${staff}/labels`],
]) {
    test(`${path} hands off to the fixed current entry without forwarding query data`, async () => {
        for (const method of ['GET', 'HEAD']) {
            const request = new Request(`${host}${path}/?next=https://untrusted.invalid&hmac=private-fixture`, {method});
            const response = await handleRequest(request, noOrigin);
            assert.equal(response.status, 302);
            assert.equal(response.headers.get('Location'), destination);
            assert.equal(response.headers.get('Cache-Control'), 'no-store');
            assert.equal(await response.text(), '');
        }
    });
}

for (const url of [`${host}/missing`, `${host}/shopify/callback/wholesale`, `${host}/api/orders`, 'https://app.theeverbranch.com/login', 'https://other.theforestrystudio.com/']) {
    test(`unhandled path or host retains its existing origin response: ${url}`, async () => {
        const request = new Request(url);
        const origin = new Response('Existing origin response', {status: 404});
        let calls = 0;
        const response = await handleRequest(request, async original => {
            calls++;
            assert.equal(original, request);
            return origin;
        });
        assert.equal(response, origin);
        assert.equal(calls, 1);
    });
}

test('POST requests are not redirected, replayed or processed by the entry worker', async () => {
    const request = new Request(`${host}/login`, {method: 'POST', body: 'fixture'});
    const response = await handleRequest(request, async original => {
        assert.equal(original, request);
        assert.equal(await original.text(), 'fixture');
        return new Response('Existing rejection', {status: 404});
    });
    assert.equal(response.status, 404);
});

test('the Cloudflare entrypoint does not mistake runtime bindings for the origin fetch function', async t => {
    const request = new Request(`${host}/unknown`);
    t.mock.method(globalThis, 'fetch', async original => {
        assert.equal(original, request);
        return new Response('Origin response', {status: 404});
    });
    const response = await worker.fetch(request, {RUNTIME_BINDING: 'fixture'}, {});
    assert.equal(response.status, 404);
    assert.equal(await response.text(), 'Origin response');
});
