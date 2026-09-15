import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { chromium } from 'playwright';

// Exercise the production event handler in a real browser. The formAction DOM
// property is the current document URL when a button has no formaction attribute.
test('embedded approval denial and resend post to their decision endpoints', async () => {
    const template = await readFile(new URL('../../resources/views/shopify/wholesale-applications-show.blade.php', import.meta.url), 'utf8');
    const script = template.match(/<script>([\s\S]*?)<\/script>/)[1];
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.route('https://wholesale.test/**', route => route.fulfill({
            contentType: 'text/html', body: '<html><body></body></html>',
        }));
        await page.goto('https://wholesale.test/shopify/app/wholesale/applications/6');
        await page.setContent(`
            <form action="/shopify/app/wholesale/applications/6/approve" data-embedded-approval-form>
                <input data-embedded-session-token-input>
                <button id="approve" data-embedded-approval-button>Approve</button>
                <button id="resend" formaction="/shopify/app/wholesale/applications/6/resend-activation" data-embedded-approval-button>Resend</button>
            </form>
            <form action="/shopify/app/wholesale/applications/6/reject" data-embedded-approval-form>
                <input data-embedded-session-token-input>
                <button id="deny" data-embedded-approval-button>Deny</button>
            </form>
            <p data-embedded-approval-help></p>
        `);
        await page.evaluate(() => {
            window.ForestryEmbeddedApp = { getShopifySessionToken: async () => 'internal-browser-test-token' };
            window.posts = [];
            window.fetch = async (url, options) => {
                window.posts.push({ path: new URL(url, location.href).pathname, token: options.headers.Authorization });
                return new Response(JSON.stringify({ ok: false, message: 'QA response' }), { status: 422 });
            };
        });
        await page.addScriptTag({ content: script });
        for (const id of ['approve', 'deny', 'resend']) {
            await page.locator('#'+id).click();
            await page.waitForFunction(() => !document.querySelector('#approve').disabled);
        }
        const posts = await page.evaluate(() => window.posts);
        assert.deepEqual(posts.map(post => post.path), [
            '/shopify/app/wholesale/applications/6/approve',
            '/shopify/app/wholesale/applications/6/reject',
            '/shopify/app/wholesale/applications/6/resend-activation',
        ]);
        assert.ok(posts.every(post => post.token === 'Bearer internal-browser-test-token'));
    } finally {
        await browser.close();
    }
});
