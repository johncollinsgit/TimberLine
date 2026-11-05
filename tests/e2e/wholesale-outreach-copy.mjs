import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtemp, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {chromium, webkit} from 'playwright';

const root = new URL('../../', import.meta.url).pathname;
const temporary = await mkdtemp(join(tmpdir(), 'wholesale-outreach-copy-'));
try {
  const fixture = join(temporary, 'draft.html');
  execFileSync('php', ['vendor/bin/pest', '--compact', 'tests/Feature/Wholesale/WholesaleCustomerOutreachDraftTest.php', '--filter=branded drafts match current product IDs'], {
    cwd: root, env: {...process.env, WHOLESALE_OUTREACH_BROWSER_FIXTURE: fixture}, stdio: 'pipe',
  });
  const html = (await readFile(fixture, 'utf8')).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  const behavior = (await readFile(new URL('../../resources/views/shopify/partials/wholesale-outreach-copy.blade.php', import.meta.url), 'utf8')).replace(/^\s*<script>/, '').replace(/<\/script>\s*$/, '');
  for (const [name, engine] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch({headless: true});
    try {
      const page = await browser.newPage({viewport: {width: 1200, height: 1000}});
      await page.route('**/*', route => new URL(route.request().url()).hostname === 'outreach-fixture.invalid'
        ? route.fulfill({contentType: 'text/html', body: html}) : route.abort());
      await page.goto('https://outreach-fixture.invalid/');
      await page.evaluate(() => {
        window.__copied = []; window.__rejectClipboard = false; window.__fallbackAllowed = false;
        window.ClipboardItem = class {
          constructor(parts) { this.parts = parts; }
          getType(type) { return Promise.resolve(this.parts[type]); }
        };
        Object.defineProperty(navigator, 'clipboard', {configurable: true, value: {write: async items => {
          if (window.__rejectClipboard) throw new Error('Fixture clipboard denied');
          window.__copied.push(await (await items[0].getType('text/html')).text());
        }}});
        document.execCommand = () => {
          const container = document.createElement('div');
          container.append(window.getSelection().getRangeAt(0).cloneContents());
          window.__fallbackHtml = container.innerHTML;
          return window.__fallbackAllowed;
        };
      });
      await page.addScriptTag({content: behavior});
      await page.locator('[data-outreach-message-input]').fill('A personal note.\n\n<img src=x onerror="window.__injected=true">');
      await page.locator('[data-outreach-subject]').fill('Your next scent & size mix');
      const gmail = new URL(await page.locator('[data-outreach-gmail]').getAttribute('href'));
      assert.equal(gmail.hostname, 'mail.google.com');
      assert.equal(gmail.searchParams.get('to'), 'buyer@example.com');
      assert.equal(gmail.searchParams.get('su'), 'Your next scent & size mix');
      await page.locator('[data-outreach-copy]').click();
      await page.waitForFunction(() => window.__copied.length === 1);
      const copied = await page.evaluate(() => window.__copied[0]);
      assert.match(copied, /https:\/\/cdn.shopify.com\/forest.png/);
      assert.match(copied, /https:\/\/wholesale.example.com\/products\/cedar/);
      assert.match(copied, /A personal note/);
      assert.match(copied, /&lt;img src=x/);
      assert.doesNotMatch(copied, /data-outreach-copy|data-wholesale-outreach-editor/);
      assert.equal(await page.evaluate(() => window.__injected), undefined);
      await page.evaluate(() => { window.__rejectClipboard = true; });
      await page.locator('[data-outreach-copy]').click();
      await page.waitForFunction(() => document.querySelector('[data-outreach-copy-status]').textContent.includes('email is selected'));
      assert.match(await page.evaluate(() => window.getSelection().toString()), /A personal note/);
      await page.evaluate(() => { window.__fallbackAllowed = true; });
      await page.locator('[data-outreach-copy]').click();
      await page.waitForFunction(() => document.querySelector('[data-outreach-copy-status]').textContent.includes('Formatted email copied'));
      assert.match(await page.evaluate(() => window.__fallbackHtml), /cdn.shopify.com\/forest.png/);
      console.log(`${name}: branded photos/links copy, edits escape safely, Gmail recipient/subject stay accurate, and clipboard denial preserves the message.`);
    } finally {await browser.close();}
  }
} finally {await rm(temporary, {recursive: true, force: true});}
