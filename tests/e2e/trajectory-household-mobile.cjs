/* Standalone fictional mobile shell check. No application API or customer records. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const base = path.resolve(__dirname, '../..');
function fixture() {
  let html = fs.readFileSync(path.join(base, 'resources/views/trajectory/index.blade.php'), 'utf8');
  html = html.replace(/@vite\([^\n]+\)/, '<link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/household-mobile.css">');
  html = html.replace(/data-spaces="[^"]*"/, 'data-spaces="[]"');
  html = html.replace(/@foreach\([\s\S]*?@endforeach/, ['overview', 'budget', 'assets', 'bud'].map(x => `<button data-tab="${x}">${x}</button>`).join(''));
  html = html.replace(/@foreach\([\s\S]*?@endforeach/, ['day', 'month', 'quarter', 'year'].map(x => `<button data-range="${x}">${x}</button>`).join(''));
  html = html.replace(/\{\{\s*route\('([^']+)'\)[^}]*\}\}/g, (_, route) => `/${route}`);
  html = html.replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g, (_, asset) => `/${asset}`);
  html = html.replace(/\{\{[\s\S]*?\}\}/g, 'Fictional');
  html = html.replace('Loading your finance spaces…', '<section class="tr-panel"><h2>Fictional source review</h2><div class="tr-table-wrap"><table class="tr-table"><tr><th>Source</th><th>Observation date</th><th>Coverage status</th><th>Review action</th></tr><tr><td>Sample statement</td><td>Example date</td><td>Needs review</td><td>Open source</td></tr></table></div></section>');
  return html.replace('</body>', `<script type="module">import {initHouseholdMobile} from '/household-mobile.js';window.mobile=initHouseholdMobile(document.querySelector('#trajectory'));document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>document.querySelector('#tr-page-title').textContent=b.dataset.tab);</script></body>`);
}
async function main() {
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  const browser = await chromium.launch({headless: true, ...(executablePath ? {executablePath} : {})});
  try {
    const context = await browser.newContext({viewport: {width: 390, height: 844}});
    await context.route('http://trajectory.test/**', async route => {
      const name = new URL(route.request().url()).pathname;
      if (name === '/') return route.fulfill({contentType: 'text/html', body: fixture()});
      if (name === '/welcome.html') {
        const landing = fs.readFileSync(path.join(base, 'resources/views/trajectory/welcome.blade.php'), 'utf8').replace(/\{\{\s*route\('([^']+)'\)[^}]*\}\}/g, (_, name) => '/' + name).replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g, (_, name) => '/' + name);
        return route.fulfill({contentType: 'text/html', body: landing});
      }
      if (['/app.css', '/household-mobile.css', '/household-mobile.js'].includes(name)) return route.fulfill({path: path.join(base, 'resources/js/trajectory', name.slice(1)), contentType: name.endsWith('.css') ? 'text/css' : 'application/javascript'});
      if (name === '/images/marketplace/studio.jpg') return route.fulfill({path: path.join(base, 'public', name)});
      return route.fulfill({status: 404, body: ''});
    });
    const page = await context.newPage();
    await page.goto('http://trajectory.test/');
    await page.waitForFunction(() => !!window.mobile);
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), true);
    await page.locator('#tr-splash-skip').click();
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), false);
    assert.equal(await page.locator('.tr-table-wrap').evaluate(el => el.scrollWidth > el.clientWidth), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.locator('#tr-mobile-drawer').getAttribute('inert'), '');
    await page.locator('#tr-mobile-menu').click();
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('#tr-mobile-drawer').getAttribute('role'), 'dialog');
    assert.equal(await page.locator('.tr-workspace').getAttribute('inert'), '');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    assert.equal(await page.locator('#tr-mobile-menu').evaluate(el => el === document.activeElement), true);
    await page.locator('#tr-mobile-menu').click();
    await page.locator('#tr-mobile-close').focus();
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.locator('#tr-mobile-drawer').evaluate(el => el.contains(document.activeElement)), true);
    await page.locator('[data-tab="budget"]').click();
    assert.equal(await page.locator('#tr-page-title').textContent(), 'budget');
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    assert.equal(await page.locator('[data-range]').count(), 4);
    await page.locator('#tr-mobile-menu').click();
    await page.mouse.click(383, 400);
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    await page.evaluate(() => document.querySelector('#tr-content').setAttribute('aria-busy', 'false'));
    await page.reload();
    await page.waitForFunction(() => !!window.mobile);
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), false);
    await page.setViewportSize({width: 1200, height: 900});
    await page.waitForFunction(() => !document.querySelector('#tr-mobile-drawer').hasAttribute('inert'));
    assert.equal(await page.locator('#tr-mobile-menu').isVisible(), false);
    assert.equal(await page.locator('#tr-mobile-drawer').getAttribute('inert'), null);
    await page.setViewportSize({width: 390, height: 844});
    await page.emulateMedia({reducedMotion: 'reduce'});
    await page.evaluate(() => { sessionStorage.clear(); });
    await page.reload();
    await page.waitForFunction(() => !!window.mobile);
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), false);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.goto('http://trajectory.test/welcome.html');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.getByRole('link', {name: 'Contact us', exact: true}).getAttribute('href'), '/platform.contact');
    assert.equal(await page.locator('img').first().getAttribute('src'), '/images/marketplace/studio.jpg');
    assert.equal(await page.getByRole('link', {name: 'Privacy', exact: true}).getAttribute('href'), '/legal.privacy');
    console.log('26 fictional mobile/landing browser assertions passed: splash, skip, drawer, focus, overlay, responsive controls, reduced motion, local image and genuine contact/legal links.');
  } finally { await browser.close(); }
}
main().catch(error => {console.error(error);process.exitCode = 1;});
