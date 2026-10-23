import { chromium, webkit } from 'playwright';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const output = path.resolve(process.env.PROPOSAL_EVIDENCE_DIR || '.tmp/proposal-evidence');
await mkdir(output, { recursive: true });
const html = await readFile(path.join(root, '.tmp/proposal-preview.html'), 'utf8');
const engine = process.env.PROPOSAL_BROWSER_ENGINE || 'chromium';
assert.ok(['chromium', 'webkit'].includes(engine));
const browser = await (engine === 'webkit' ? webkit : chromium).launch({ headless: true });
const results = [];
try {
  for (const viewport of [
    { name: 'desktop', width: 1440, height: 1100 },
    { name: 'ipad', width: 834, height: 1194 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    const page = await browser.newPage({ viewport, deviceScaleFactor: 1 });
    const errors = [];
    const requests = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
    await page.setContent(html, { waitUntil: 'load' });
    assert.equal(await page.locator('h1').innerText(), 'A proposal for Lees-McRae College');
    assert.equal(await page.locator('.journey-path > li').count(), 6);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    assert.equal(overflow, false, `${viewport.name} has horizontal overflow`);
    const storyBeforePricing = await page.evaluate(() => {
      const story = document.querySelector('.journey');
      const price = [...document.querySelectorAll('h2')].find(node => node.textContent.includes('Choose the scope'));
      return !!(story.compareDocumentPosition(price) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    assert.equal(storyBeforePricing, true);
    await page.screenshot({ path: path.join(output, `${viewport.name}-full.png`), fullPage: true });
    await page.screenshot({ path: path.join(output, `${viewport.name}-hero.png`) });
    await page.locator('label[data-proposal-section-label="approach"]').click();
    assert.equal(await page.locator('.proposal-section-panel:visible').count(), 1);
    assert.equal(await page.locator('[data-proposal-panel="approach"]').isVisible(), true);
    await page.locator('.journey-overview').screenshot({ path: path.join(output, `${viewport.name}-journey-overview.png`) });
    const network = page.locator('.department-network:visible');
    if (await network.count()) await network.screenshot({ path: path.join(output, `${viewport.name}-department-network.png`) });
    await page.locator('label[data-proposal-section-label="scope"]').click();
    assert.equal(await page.locator('.proposal-section-panel:visible').count(), 1);
    assert.equal(await page.locator('[data-proposal-panel="scope"]').isVisible(), true);
    await page.locator('.grid').first().screenshot({ path: path.join(output, `${viewport.name}-pricing.png`) });
    assert.deepEqual(errors, []);
    assert.deepEqual(requests, []);
    results.push({ viewport, horizontalOverflow: overflow, javascriptErrors: errors, externalRequests: requests, phases: 6, storyBeforePricing });
    await page.close();
  }
} finally {
  await browser.close();
}
await writeFile(path.join(output, 'browser-results.json'), JSON.stringify({ scope: 'Static rendered local test fixture; no device installation, production acceptance or server interaction', results }, null, 2));
console.log(JSON.stringify({ output, viewports: results.length, errors: 0, externalRequests: 0 }));
