// Public Website ingress only. Keep this origin fixed: it is also the TLS SNI.
export const ORIGIN = 'https://app.theeverbranch.com';
export const MAX_BODY_BYTES = 1024 * 1024;

const PLATFORM_HOST = 'theeverbranch.com';
const FALLBACK_HOST = 'sites.theeverbranch.com';
const encoder = new TextEncoder();
const privateSegments = new Set([
    'login', 'logout', 'register', 'auth', 'email', 'password', 'forgot-password',
    'reset-password', 'two-factor', 'two-factor-challenge', 'dashboard', 'website', 'landlord',
    'workspaces', 'workspace', 'settings', 'workflows', 'shopify', 'api',
    'mobile', 'webhooks', 'up', 'ready', 'platform', 'sanctum', 'account',
]);

function noStore(response) {
    const result = new Response(response.body, response);
    result.headers.set('Cache-Control', 'private, no-store, max-age=0');
    result.headers.set('CDN-Cache-Control', 'no-store');
    result.headers.set('Cloudflare-CDN-Cache-Control', 'no-store');
    result.headers.set('Pragma', 'no-cache');
    result.headers.set('Expires', '0');
    // Neither an origin debug header nor the signing envelope belongs downstream.
    for (const name of [...result.headers.keys()]) {
        if (name.startsWith('x-everbranch-website-')) result.headers.delete(name);
    }
    return result;
}

function reject(status, message = 'Not found.') {
    return noStore(new Response(message, {
        status,
        headers: { 'Content-Type': 'text/plain; charset=utf-8', 'X-Content-Type-Options': 'nosniff' },
    }));
}

function validHost(host) {
    return host.length <= 253
        && /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/.test(host)
        && ! /^(?:[0-9]+\.){3}[0-9]+$/.test(host);
}

function isDcv(path) {
    return path.startsWith('/.well-known/acme-challenge/')
        || path.startsWith('/.well-known/pki-validation/');
}

function hex(bytes) {
    return [...new Uint8Array(bytes)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

async function readBody(request) {
    const declaredLength = request.headers.get('content-length');
    if (declaredLength !== null && (! /^[0-9]+$/.test(declaredLength) || Number(declaredLength) > MAX_BODY_BYTES)) {
        throw new RangeError('body limit');
    }
    if (!request.body) return new Uint8Array();

    const reader = request.body.getReader();
    const chunks = [];
    let length = 0;
    try {
        while (true) {
            const chunk = await reader.read();
            if (chunk.done) break;
            length += chunk.value.byteLength;
            if (length > MAX_BODY_BYTES) {
                await reader.cancel();
                throw new RangeError('body limit');
            }
            chunks.push(chunk.value);
        }
    } finally {
        reader.releaseLock();
    }
    const bytes = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.byteLength;
    }
    return bytes;
}

function originHeaders(request) {
    const headers = new Headers(request.headers);
    const connectionHeaders = (headers.get('connection') ?? '').toLowerCase().split(',').map((name) => name.trim());
    for (const name of [...headers.keys()]) {
        if (name === 'forwarded' || name.startsWith('x-forwarded-') || name.startsWith('x-everbranch-website-')
            || name.startsWith('cf-access-') || connectionHeaders.includes(name)
            || ['host', 'authorization', 'proxy-authorization', 'connection', 'proxy-connection',
                'keep-alive', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length',
                'x-original-host', 'x-original-url', 'x-rewrite-url', 'x-http-method-override', 'x-method-override'].includes(name)) {
            headers.delete(name);
        }
    }
    headers.set('Host', new URL(ORIGIN).hostname);
    headers.set('Cache-Control', 'no-store');
    headers.set('Pragma', 'no-cache');
    return headers;
}

/** Dependencies are injectable for deterministic local tests; never supplied by the visitor. */
export async function handleRequest(request, env = {}, dependencies = {}) {
    const originFetch = dependencies.fetch ?? globalThis.fetch;
    const crypto = dependencies.crypto ?? globalThis.crypto;
    const clock = dependencies.now ?? Date.now;
    let url;
    try {
        url = new URL(request.url);
    } catch {
        return reject(400, 'Invalid request.');
    }
    const host = url.hostname.toLowerCase();
    if (url.username || url.password || url.port || !validHost(host) || !['http:', 'https:'].includes(url.protocol)) {
        return reject(400, 'Invalid request.');
    }

    // Routes exclude all first-party surfaces. This is a second safety boundary,
    // not a replacement for the scriptless app route that prevents recursion.
    if (host === FALLBACK_HOST) return reject(404);
    if (host === PLATFORM_HOST || host.endsWith(`.${PLATFORM_HOST}`)) return originFetch(request);

    // Cloudflare owns certificate-validation responses. In particular, HTTP DCV
    // must pass through unchanged before redirects or the relay's readiness gate.
    if (isDcv(url.pathname)) return originFetch(request);

    const method = request.method.toUpperCase();
    if (!['GET', 'HEAD', 'POST'].includes(method)) return reject(405, 'Method not allowed.');
    const publicForm = method === 'POST' && /^\/website\/forms\/[1-9][0-9]*$/.test(url.pathname);
    let firstSegment;
    try {
        firstSegment = decodeURIComponent(url.pathname).replace(/^\/+/, '').split('/')[0].toLowerCase();
    } catch {
        return reject(400, 'Invalid request.');
    }
    if ((method === 'POST' && !publicForm) || (privateSegments.has(firstSegment) && !publicForm)) return reject(404);
    if (url.protocol === 'http:') {
        if (method === 'POST') return reject(400, 'HTTPS required.');
        url.protocol = 'https:';
        return noStore(new Response(null, { status: 308, headers: { Location: url.href } }));
    }

    const secret = env.WEBSITE_ORIGIN_RELAY_SECRET;
    if (env.WEBSITE_ORIGIN_RELAY_ENABLED !== 'true' || typeof secret !== 'string' || encoder.encode(secret).byteLength < 32) {
        return reject(503, 'Website temporarily unavailable.');
    }

    let body;
    try {
        body = await readBody(request);
    } catch (error) {
        return reject(error instanceof RangeError ? 413 : 400, 'Invalid request body.');
    }
    if (method !== 'POST' && body.byteLength !== 0) return reject(400, 'Invalid request body.');

    try {
        // Slice href rather than pathname+search to retain a bare trailing '?'.
        // Sign the exact encoded target sent upstream, without query sorting.
        const target = url.href.slice(url.origin.length);
        if (!target.startsWith('/') || /[\x00-\x20#]/.test(target)) return reject(400, 'Invalid request.');
        const timestamp = String(Math.floor(clock() / 1000));
        const digest = hex(await crypto.subtle.digest('SHA-256', body));
        const message = ['v1', timestamp, method, host, target, digest].join('\n');
        const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
        const signature = hex(await crypto.subtle.sign('HMAC', key, encoder.encode(message)));
        const headers = originHeaders(request);
        headers.set('X-Everbranch-Website-Host', host);
        headers.set('X-Everbranch-Website-Timestamp', timestamp);
        headers.set('X-Everbranch-Website-Signature', signature);
        const upstream = new Request(ORIGIN + target, {
            method,
            headers,
            body: method === 'POST' ? body : undefined,
            redirect: 'manual',
        });
        const response = await originFetch(upstream, { cache: 'no-store' });
        return noStore(response);
    } catch {
        // Never expose provider errors, request content, or signing material.
        return reject(502, 'Website temporarily unavailable.');
    }
}

export default {
    fetch(request, env) {
        return handleRequest(request, env);
    },
};
