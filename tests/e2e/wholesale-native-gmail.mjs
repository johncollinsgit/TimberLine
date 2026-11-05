import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {chromium, webkit} from 'playwright';

const root = new URL('../../', import.meta.url).pathname;
const temp = await mkdtemp(join(tmpdir(), 'wholesale-native-gmail-'));
try {
  const fixture = join(temp, 'fixture.json');
  execFileSync('php', ['vendor/bin/pest', '--compact', 'tests/Feature/Wholesale/WholesaleNativeGmailTest.php', '--filter=the native review shows'], {
    cwd: root, env: {...process.env, WHOLESALE_GMAIL_BROWSER_FIXTURE: fixture}, stdio: 'pipe',
  });
  const data = JSON.parse(await readFile(fixture, 'utf8'));
  const html = data.html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  const script = (await readFile(join(root, 'resources/views/shopify/partials/wholesale-outreach-gmail.blade.php'), 'utf8')).replace(/^\s*<script>/, '').replace(/<\/script>\s*$/, '');
  const manifest = JSON.parse(await readFile(join(root, 'public/build/manifest.json'), 'utf8'));
  const cssFile = Object.values(manifest).find(value => value.src === 'resources/css/app.css')?.file;
  const css = cssFile ? await readFile(join(root, 'public/build', cssFile), 'utf8') : '';
  if (process.env.WHOLESALE_GMAIL_PREVIEW) {
    const head = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Native Gmail sender preview</title><style>'+css+'</style></head><body><div style="padding:12px;background:#f3e7ce;text-align:center">Interactive preview · Fictional customer · No emails are sent</div>';
    const body = html.slice(html.indexOf('<body'), html.indexOf('</body>')).replace(/^<body[^>]*>/, '');
    const mock = `window.shopify={idToken:async()=>"preview-only"};window.fetch=async(url,options)=>({ok:true,json:async()=>url.endsWith('/status')?{configured:true,connected:true,can_disconnect:true,can_review:true,can_send:true,name:'Example Candles',from:'info@example.test',reply_to:'info@example.test',message:'Preview only. Gmail is simulated.'}:url.includes('/review/')?${JSON.stringify(data.review)}:{status:'preview',message:'Preview complete. No email was sent.'}});`;
    await writeFile(process.env.WHOLESALE_GMAIL_PREVIEW, head+body+'<script>'+mock+';'+script+'</script></body></html>');
  }
  for (const [name, engine] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch({headless: true});
    try {
      const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
      await page.route('**/*', route => new URL(route.request().url()).hostname === 'outreach-fixture.invalid'
        ? route.fulfill({contentType: 'text/html', body: html}) : route.abort());
      await page.goto('https://outreach-fixture.invalid/');
      await page.addStyleTag({content: css});
      await page.evaluate(review => {
        window.__review = review; window.__calls = []; window.__lost = false;
        window.shopify = {idToken: async () => 'fictional-shopify-token'};
        window.fetch = async (url, options) => {
          const payload = JSON.parse(options.body);
          window.__calls.push({url, payload, authorization: options.headers.Authorization});
          if (url.endsWith('/status')) return {ok: true, json: async () => ({configured: true, connected: true, can_disconnect: true, can_send: true, name: 'Example Candles', from: 'info@example.test', reply_to: 'info@example.test', message: 'Ready to send from your verified address.'})};
          if (url.includes('/review/')) return {ok: true, json: async () => ({...review, subject: payload.subject})};
          if (url.includes('/send/')) {
            if (window.__lost) throw new Error('Fictional connection interrupted');
            await new Promise(resolve => setTimeout(resolve, 150));
            return {ok: true, json: async () => ({status: 'accepted', message_id: 'message123', message: 'Accepted by Gmail. Delivery has not been confirmed.'})};
          }
          throw new Error('Unexpected fixture endpoint');
        };
      }, data.review);
      await page.addScriptTag({content: script});
      await page.waitForFunction(() => !document.querySelector('[data-gmail-review]').disabled);
      assert.match(await page.locator('[data-gmail-from]').textContent(), /info@example.test/);
      await page.locator('[data-outreach-subject]').fill('A reviewed restock note');
      await page.locator('[data-outreach-message-input]').fill('Hello Robin.\n\nHow has your last selection been selling?');
      await page.locator('[data-gmail-review]').click();
      await page.locator('[data-gmail-dialog]').waitFor({state: 'visible'});
      assert.match(await page.locator('[data-gmail-envelope]').textContent(), /To: buyer@example.test/);
      assert.match(await page.locator('[data-gmail-envelope]').textContent(), /A reviewed restock note/);
      assert.equal(await page.locator('[data-gmail-preview]').getAttribute('sandbox'), '');
      assert.equal(await page.evaluate(() => window.__calls.filter(c => c.url.includes('/send/')).length), 0);
      if (name === 'chromium' && process.env.WHOLESALE_GMAIL_SCREENSHOT) await page.screenshot({path: process.env.WHOLESALE_GMAIL_SCREENSHOT});
      await page.locator('[data-gmail-cancel]').click();
      await page.locator('[data-gmail-review]').click();
      await page.locator('[data-gmail-send]').dblclick({delay: 30});
      await page.waitForFunction(() => document.querySelector('[data-gmail-send-status]').textContent.includes('Accepted by Gmail'));
      assert.equal(await page.evaluate(() => window.__calls.filter(c => c.url.includes('/send/')).length), 1);
      assert.equal(await page.locator('[data-gmail-review]').isDisabled(), true);
      assert.equal(await page.locator('[data-gmail-send]').isDisabled(), true);
      for (const call of await page.evaluate(() => window.__calls)) assert.equal(call.authorization, 'Bearer fictional-shopify-token');
      // New page simulates an uncertain response; its existing review remains frozen and no background retry occurs.
      await page.reload();
      await page.addStyleTag({content: css});
      await page.evaluate(review => {
        window.shopify = {idToken: async () => 'fictional-token'}; window.__sendCount = 0;
        window.fetch = async (url) => {
          if (url.includes('/send/')) { window.__sendCount++; throw new Error('Lost response'); }
          return {ok: true, json: async () => url.endsWith('/status') ? {configured: true, connected: true, can_send: true, message: 'Ready'} : review};
        };
      }, data.review);
      await page.addScriptTag({content: script});
      await page.waitForFunction(() => !document.querySelector('[data-gmail-review]').disabled);
      await page.locator('[data-gmail-review]').click();
      await page.locator('[data-gmail-send]').click();
      await page.waitForFunction(() => document.querySelector('[data-gmail-send-status]').textContent.includes('Send not confirmed'));
      assert.equal(await page.evaluate(() => window.__sendCount), 1);
      assert.equal(await page.locator('[data-gmail-review]').isDisabled(), true);
      await page.setViewportSize({width: 390, height: 844});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
      console.log(`${name}: exact review, JWT auth, explicit send, double-click protection, lost-response hold, and mobile width passed.`);
    } finally {await browser.close();}
  }
} finally {await rm(temp, {recursive: true, force: true});}
