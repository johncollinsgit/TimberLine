const LEGACY_HOST = 'backstage.theforestrystudio.com';
const STAFF_HOME = 'https://admin.shopify.com/store/modernforestrywholesale/apps/mf-wholesale-backstage/shopify/app/wholesale';
const DESTINATIONS = new Map([
    ['/login', 'https://app.theeverbranch.com/login'],
    ['/forgot-password', 'https://app.theeverbranch.com/forgot-password'],
    ['/shopify/app/wholesale', STAFF_HOME],
    ['/shopify/app/wholesale/labels', `${STAFF_HOME}/labels`],
]);

const WELCOME = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modern Forestry Backstage</title>
<meta name="robots" content="noindex,nofollow">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#eff3ed;color:#173c31;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6}main{width:100%;max-width:700px;background:#fff;border:1px solid #d4ded3;border-radius:20px;padding:52px;box-shadow:0 18px 60px #173c3109}.brand{font-size:12px;font-weight:750;letter-spacing:.18em;text-transform:uppercase;color:#526b59}h1{font-size:clamp(32px,6vw,48px);line-height:1.1;letter-spacing:-.04em;margin:20px 0}p{margin:0 0 24px;color:#4a6155}.primary{display:block;text-align:center;background:#214f3c;color:white;padding:15px 20px;border-radius:9px;text-decoration:none;font-weight:700}.primary:hover{background:#173c31}.customers{border-top:1px solid #dce4da;margin-top:32px;padding-top:24px}h2{font-size:18px;margin:0 0 8px}.shops{display:flex;flex-wrap:wrap;gap:12px}.shops a{flex:1;min-width:190px;padding:12px 16px;border:1px solid #b7caba;border-radius:9px;text-align:center;text-decoration:none;color:#214f3c;font-weight:650}a:focus-visible{outline:3px solid #a8721d;outline-offset:4px}footer{font-size:14px;color:#526b59;margin-top:30px}footer a{color:#214f3c}@media(max-width:520px){main{padding:30px 24px;border-radius:14px}body{padding:16px}.shops{display:grid}.shops a{min-width:0}}
</style>
</head>
<body><main>
<div class="brand">Modern Forestry</div>
<h1>Welcome to Backstage.</h1>
<p>Open the wholesale staff workspace through Shopify. Sign in with your staff account when prompted.</p>
<a class="primary" href="${STAFF_HOME}">Open staff workspace</a>
<section class="customers" aria-labelledby="customer-heading">
<h2 id="customer-heading">Looking for candles?</h2>
<p>Visit our retail or wholesale store to shop.</p>
<div class="shops"><a href="https://theforestrystudio.com/">Shop candles</a><a href="https://modernforestrywholesale.com/">Shop wholesale</a></div>
</section>
<footer>Need a hand? <a href="mailto:info@theforestrystudio.com">Contact Modern Forestry</a></footer>
</main></body></html>`;

export async function handleRequest(request, originFetch = fetch) {
    const url = new URL(request.url);
    if (url.hostname !== LEGACY_HOST || !['GET', 'HEAD'].includes(request.method)) {
        return originFetch(request);
    }

    // Only the retired public entry points are handled here. The application
    // keeps rejecting legacy runtime hosts, unknown paths and state changes.
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const headers = {
        'Referrer-Policy': 'no-referrer',
        'X-Content-Type-Options': 'nosniff',
        'X-Robots-Tag': 'noindex, nofollow',
    };

    if (path === '/') {
        return new Response(request.method === 'HEAD' ? null : WELCOME, {
            status: 200,
            headers: {
                ...headers,
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'public, max-age=300',
                'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            },
        });
    }

    const destination = DESTINATIONS.get(path);
    if (destination) {
        // Do not forward tokens, return URLs or other stale request context
        // across origins. Shopify/current login establish their own session.
        return new Response(null, {
            status: 302,
            headers: { ...headers, Location: destination, 'Cache-Control': 'no-store' },
        });
    }

    return originFetch(request);
}

export default {
    fetch(request) {
        return handleRequest(request);
    },
};
