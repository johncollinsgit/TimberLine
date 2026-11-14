import assert from 'node:assert/strict';
import { createHash, createHmac, webcrypto } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import worker, { handleRequest, MAX_BODY_BYTES, ORIGIN } from './src/index.mjs';

const secret = 'fixture-only-relay-secret-32-bytes-long';
const env = { WEBSITE_ORIGIN_RELAY_ENABLED: 'true', WEBSITE_ORIGIN_RELAY_SECRET: secret };
const timestamp = 1794690000;
const dependencies = { crypto: webcrypto, now: () => timestamp * 1000 };

function harness(response = new Response('customer page')) {
    const calls = [];
    return {
        calls,
        dependencies: {
            ...dependencies,
            fetch: async (...args) => {
                calls.push(args);
                return response;
            },
        },
    };
}

function expectedSignature(method, host, target, body = '') {
    const digest = createHash('sha256').update(body).digest('hex');
    return createHmac('sha256', secret).update(['v1', String(timestamp), method, host, target, digest].join('\n')).digest('hex');
}

function assertNoStore(response) {
    assert.match(response.headers.get('cache-control'), /private, no-store/);
    assert.equal(response.headers.get('cdn-cache-control'), 'no-store');
    assert.equal(response.headers.get('cloudflare-cdn-cache-control'), 'no-store');
}

test('signs the exact public host, method and encoded target for the fixed HTTPS origin', async () => {
    const target = '/catalog/a%2Fb%25?b=2&a=1&a=%2F';
    const { calls, dependencies: deps } = harness();
    const response = await handleRequest(new Request(`https://SHOP.Customer.test${target}`), env, deps);
    assert.equal(response.status, 200);
    assert.equal(await response.text(), 'customer page');
    assert.equal(calls.length, 1);
    const [upstream, options] = calls[0];
    assert.equal(upstream.url, ORIGIN + target);
    assert.equal(upstream.method, 'GET');
    assert.equal(upstream.redirect, 'manual');
    assert.deepEqual(options, { cache: 'no-store' });
    assert.equal(upstream.headers.get('host'), 'app.theeverbranch.com');
    assert.equal(upstream.headers.get('x-everbranch-website-host'), 'shop.customer.test');
    assert.equal(upstream.headers.get('x-everbranch-website-timestamp'), String(timestamp));
    assert.equal(upstream.headers.get('x-everbranch-website-signature'), expectedSignature('GET', 'shop.customer.test', target));
    assert.equal(upstream.headers.get('x-everbranch-website-signature'), '34cd3e6bcb5a49c5f45a1a60e8890f581dde69528af496f03036b07ed29ade64');
    assert.equal(upstream.headers.get('cache-control'), 'no-store');
    assertNoStore(response);
});

test('signs HEAD as HEAD and rejects a nonempty read body before fetching', async () => {
    const { calls, dependencies: deps } = harness(new Response(null));
    const response = await handleRequest(new Request('https://customer.test/about', { method: 'HEAD' }), env, deps);
    assert.equal(response.status, 200);
    assert.equal(calls[0][0].method, 'HEAD');
    assert.equal(calls[0][0].headers.get('x-everbranch-website-signature'), expectedSignature('HEAD', 'customer.test', '/about'));
    const malformedRead = {
        url: 'https://customer.test/', method: 'GET', headers: new Headers(),
        body: new ReadableStream({ start(controller) { controller.enqueue(new Uint8Array([1])); controller.close(); } }),
    };
    assert.equal((await handleRequest(malformedRead, env, deps)).status, 400);
    assert.equal(calls.length, 1);
});

test('uses the raw key bytes without trimming configuration whitespace', async () => {
    const rawSecret = ` ${secret} `;
    const { calls, dependencies: deps } = harness();
    await handleRequest(new Request('https://customer.test/'), { ...env, WEBSITE_ORIGIN_RELAY_SECRET: rawSecret }, deps);
    const digest = createHash('sha256').update('').digest('hex');
    assert.equal(calls[0][0].headers.get('x-everbranch-website-signature'), createHmac('sha256', rawSecret)
        .update(['v1', String(timestamp), 'GET', 'customer.test', '/', digest].join('\n')).digest('hex'));
});

test('keeps bare query markers and never interprets a target as another origin', async () => {
    for (const target of ['/contact?', '//external.test/path?next=https%3A%2F%2Fother.test']) {
        const { calls, dependencies: deps } = harness();
        await handleRequest(new Request(`https://customer.test${target}`), env, deps);
        const [upstream] = calls[0];
        assert.equal(upstream.url, ORIGIN + target);
        assert.equal(upstream.headers.get('x-everbranch-website-signature'), expectedSignature('GET', 'customer.test', target));
    }
});

test('forwards the exact form bytes and preserves host-scoped cookies and browser origin for CSRF', async () => {
    const bytes = new Uint8Array([0, 1, 255, 38, 61, 195, 169]);
    const { calls, dependencies: deps } = harness();
    const response = await handleRequest(new Request('https://customer.test/website/forms/42?from=home', {
        method: 'POST',
        headers: { 'content-type': 'application/octet-stream', cookie: 'session=visitor', origin: 'https://customer.test' },
        body: bytes,
    }), env, deps);
    assert.equal(response.status, 200);
    const [upstream] = calls[0];
    assert.deepEqual(new Uint8Array(await upstream.arrayBuffer()), bytes);
    assert.equal(upstream.headers.get('cookie'), 'session=visitor');
    assert.equal(upstream.headers.get('origin'), 'https://customer.test');
    assert.equal(upstream.headers.get('content-type'), 'application/octet-stream');
    assert.equal(upstream.headers.get('x-everbranch-website-signature'), expectedSignature('POST', 'customer.test', '/website/forms/42?from=home', bytes));
});

test('removes untrusted routing, signing, access and hop headers before adding a fresh envelope', async () => {
    const { calls, dependencies: deps } = harness();
    const response = await handleRequest(new Request('https://customer.test/', { headers: {
        Host: 'victim.test', Forwarded: 'host=victim.test;proto=http', 'X-Forwarded-Host': 'victim.test',
        'X-Forwarded-Proto': 'http', 'X-Forwarded-For': '127.0.0.1', 'X-Everbranch-Website-Host': 'victim.test',
        'X-Everbranch-Website-Timestamp': '0', 'X-Everbranch-Website-Signature': 'forged',
        'X-Everbranch-Website-Other': 'untrusted', Authorization: 'Bearer untrusted',
        'Proxy-Authorization': 'Basic untrusted', 'CF-Access-Jwt-Assertion': 'untrusted',
        'X-Original-Host': 'victim.test', 'X-Original-URL': '/landlord', 'X-Rewrite-URL': '/landlord',
        'X-HTTP-Method-Override': 'DELETE', Connection: 'x-remove', 'X-Remove': 'untrusted',
    } }), env, deps);
    assert.equal(response.status, 200);
    const headers = calls[0][0].headers;
    for (const name of ['forwarded', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-for',
        'x-everbranch-website-other', 'authorization', 'proxy-authorization', 'cf-access-jwt-assertion',
        'x-original-host', 'x-original-url', 'x-rewrite-url', 'x-http-method-override', 'connection', 'x-remove']) {
        assert.equal(headers.has(name), false, name);
    }
    assert.equal(headers.get('x-everbranch-website-host'), 'customer.test');
    assert.equal(headers.get('x-everbranch-website-signature'), expectedSignature('GET', 'customer.test', '/'));
});

test('passes both certificate DCV paths through untouched, including HTTP with relay disabled', async () => {
    for (const path of ['/.well-known/acme-challenge/token', '/.well-known/pki-validation/file.txt']) {
        const request = new Request(`http://customer.test${path}?a=1`);
        const response = new Response('provider DCV');
        const { calls, dependencies: deps } = harness(response);
        assert.equal(await handleRequest(request, {}, deps), response);
        assert.equal(calls[0][0], request);
        assert.equal(calls[0].length, 1);
    }
});

test('redirects public HTTP reads to the same HTTPS URL before signing and refuses HTTP forms', async () => {
    const { calls, dependencies: deps } = harness();
    const redirect = await handleRequest(new Request('http://customer.test/contact?a=1&b=%2F'), {}, deps);
    assert.equal(redirect.status, 308);
    assert.equal(redirect.headers.get('location'), 'https://customer.test/contact?a=1&b=%2F');
    assertNoStore(redirect);
    const form = await handleRequest(new Request('http://customer.test/website/forms/1', { method: 'POST', body: 'x=y' }), env, deps);
    assert.equal(form.status, 400);
    assert.equal(calls.length, 0);
});

test('bypasses first-party requests unchanged and fails closed on direct SaaS fallback host', async () => {
    for (const host of ['theeverbranch.com', 'app.theeverbranch.com', 'www.theeverbranch.com', 'forestry.theeverbranch.com']) {
        const request = new Request(`https://${host}/login`, { headers: { authorization: 'existing-firstparty-flow' } });
        const response = new Response('existing origin');
        const { calls, dependencies: deps } = harness(response);
        assert.equal(await handleRequest(request, {}, deps), response);
        assert.equal(calls[0][0], request);
    }
    const { calls, dependencies: deps } = harness();
    const direct = await handleRequest(new Request('https://sites.theeverbranch.com/', { headers: { 'x-everbranch-website-host': 'customer.test' } }), env, deps);
    assert.equal(direct.status, 404);
    assert.equal(calls.length, 0);
});

test('fails closed with no fetch when readiness or hostname is invalid', async () => {
    for (const config of [{}, { ...env, WEBSITE_ORIGIN_RELAY_ENABLED: 'false' }, { ...env, WEBSITE_ORIGIN_RELAY_SECRET: 'short' }]) {
        const { calls, dependencies: deps } = harness();
        const response = await handleRequest(new Request('https://customer.test/'), config, deps);
        assert.equal(response.status, 503);
        assert.equal(calls.length, 0);
        assertNoStore(response);
    }
    for (const host of ['127.0.0.1', '[::1]', 'localhost', 'customer.test.', 'customer.test:444', 'under_score.test']) {
        const { calls, dependencies: deps } = harness();
        assert.equal((await handleRequest(new Request(`https://${host}/`), env, deps)).status, 400, host);
        assert.equal(calls.length, 0);
    }
});

test('blocks application namespaces and unsupported writes without contacting the origin', async () => {
    for (const path of ['/login', '/landlord/tenants', '/workspace/purchase', '/api/data', '/ready', '/up',
        '/website', '/website/forms/1', '/%61pi/private', '/%2Fapi/private', '/platform/signup', '/settings?x=1',
        '/sanctum/csrf-cookie', '/account/help', '/two-factor-challenge']) {
        const { calls, dependencies: deps } = harness();
        assert.equal((await handleRequest(new Request(`https://customer.test${path}`), env, deps)).status, 404, path);
        assert.equal(calls.length, 0);
    }
    for (const [method, path, status] of [['PUT', '/', 405], ['DELETE', '/website/forms/1', 405],
        ['POST', '/website/forms/0', 404], ['POST', '/website/forms/01', 404], ['POST', '/website/forms/1/extra', 404], ['POST', '/contact', 404]]) {
        const { calls, dependencies: deps } = harness();
        assert.equal((await handleRequest(new Request(`https://customer.test${path}`, { method }), env, deps)).status, status);
        assert.equal(calls.length, 0);
    }
});

test('bounds both declared and streamed form bodies before signing or fetching', async () => {
    const declared = new Request('https://customer.test/website/forms/1', { method: 'POST', headers: { 'content-length': String(MAX_BODY_BYTES + 1) }, body: 'small' });
    const { calls, dependencies: deps } = harness();
    assert.equal((await handleRequest(declared, env, deps)).status, 413);
    let cancelled = false;
    const streamed = new Request('https://customer.test/website/forms/1', {
        method: 'POST', duplex: 'half',
        body: new ReadableStream({
            start(controller) {
                controller.enqueue(new Uint8Array(MAX_BODY_BYTES));
                controller.enqueue(new Uint8Array(1));
            },
            cancel() { cancelled = true; },
        }),
    });
    assert.equal((await handleRequest(streamed, env, deps)).status, 413);
    assert.equal(cancelled, true);
    assert.equal(calls.length, 0);
    const atLimit = new Request('https://customer.test/website/forms/1', { method: 'POST', body: new Uint8Array(MAX_BODY_BYTES) });
    assert.equal((await handleRequest(atLimit, env, deps)).status, 200);
    assert.equal((await calls[0][0].arrayBuffer()).byteLength, MAX_BODY_BYTES);
});

test('never follows origin redirects or caches origin responses and strips signing response headers', async () => {
    const { calls, dependencies: deps } = harness(new Response(null, { status: 302, headers: {
        Location: 'https://customer.test/contact', 'Cache-Control': 'public, max-age=3600', 'X-Everbranch-Website-Signature': 'debug-must-not-escape',
    } }));
    const response = await handleRequest(new Request('https://customer.test/'), env, deps);
    assert.equal(response.status, 302);
    assert.equal(response.headers.get('location'), 'https://customer.test/contact');
    assert.equal(response.headers.has('x-everbranch-website-signature'), false);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0].redirect, 'manual');
    assertNoStore(response);
});

test('returns a generic failure without provider exception or signing material', async () => {
    const response = await handleRequest(new Request('https://customer.test/'), env, {
        ...dependencies, fetch: async () => { throw new Error(`private diagnostic ${secret}`); },
    });
    assert.equal(response.status, 502);
    assert.equal(await response.text(), 'Website temporarily unavailable.');
    assertNoStore(response);
    assert.equal(typeof worker.fetch, 'function');
});

test('deployment defaults cannot silently capture traffic or expose a workers.dev endpoint', async () => {
    const configText = await readFile(new URL('./wrangler.jsonc', import.meta.url), 'utf8');
    const config = JSON.parse(configText.replace(/^\s*\/\/.*$/gm, ''));
    assert.deepEqual(config.routes, []);
    assert.equal(config.workers_dev, false);
    assert.equal(config.preview_urls, false);
    assert.equal(config.vars.WEBSITE_ORIGIN_RELAY_ENABLED, 'false');
    assert.equal(Object.hasOwn(config.vars, 'WEBSITE_ORIGIN_RELAY_SECRET'), false);
});
