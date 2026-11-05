import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {chromium} from 'playwright';

const source = await readFile(new URL('../../resources/views/shopify/partials/wholesale-embedded-mutation-bootstrap.blade.php', import.meta.url), 'utf8');
const script = source.replace(/^\s*<script>/, '').replace(/<\/script>\s*$/, '');
const browser = await chromium.launch({headless: true, ...(process.env.PHOTO_PICKER_CHROMIUM ? {executablePath: process.env.PHOTO_PICKER_CHROMIUM} : {})});
try {
  const page = await browser.newPage();
  await page.route('**/*', route => route.abort());
  await page.setContent(`<form><input name="shopify_session_token" data-wholesale-session-token><input name="due_local_at" value="2027-07-15T09:30"><input name="action" value="reschedule"><button data-wholesale-mutation-button disabled>Record</button><p data-wholesale-verification-help></p></form>`);
  await page.evaluate(() => {
    window.calls = 0; window.fail = false; window.sent = [];
    window.ForestryEmbeddedApp = {getShopifySessionToken: async () => {
      window.calls += 1;
      if (window.fail) throw new Error('Fixture verification failed');
      await new Promise(resolve => setTimeout(resolve, 20));
      return `fixture-token-${window.calls}`;
    }};
    HTMLFormElement.prototype.submit = function () { window.sent.push(Object.fromEntries(new FormData(this))); };
  });
  await page.addScriptTag({content: script});
  await page.waitForFunction(() => !document.querySelector('button').disabled);
  await page.evaluate(() => { window.fail = true; });
  await page.getByRole('button', {name: 'Record'}).click();
  await page.waitForFunction(() => document.querySelector('[data-wholesale-verification-help]').textContent.includes('entries are preserved'));
  assert.equal(await page.locator('[name=due_local_at]').inputValue(), '2027-07-15T09:30');
  assert.deepEqual(await page.evaluate(() => window.sent), []);
  await page.evaluate(() => {
    window.fail = false;
    const form = document.querySelector('form');
    form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
  });
  await page.waitForFunction(() => window.sent.length === 1);
  assert.deepEqual(await page.evaluate(() => window.sent), [{shopify_session_token: 'fixture-token-3', due_local_at: '2027-07-15T09:30', action: 'reschedule'}]);
  console.log('Wholesale decision controls preserve entries, refresh verification on submit and prevent double submission.');
} finally {
  await browser.close();
}
