import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { createServer } from 'node:http';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const html = readFileSync(process.env.SEARCH_DEMO_HTML || new URL('../../resources/demos/lees-mcrae-campus.html', import.meta.url), 'utf8').replace(/<(script|style)(?=[\s>])/gi, '<$1 nonce="search-fixture"');
const csp = "default-src 'none'; script-src 'nonce-search-fixture'; style-src 'nonce-search-fixture'; style-src-attr 'unsafe-inline'; img-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; worker-src 'none'; font-src 'none'";
const server = createServer((request, response) => { if (request.url === '/favicon.ico') { response.writeHead(204); response.end(); return; } response.writeHead(200, { 'Content-Type': 'text/html', 'Content-Security-Policy': csp }); response.end(html); });
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
const evidence = resolve(process.env.SEARCH_EVIDENCE || '../search-evidence'); mkdirSync(evidence, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: process.env.DEMO_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
const errors = [], violations = [], requests = [];
let checks = 0; const check = (ok, name) => { assert.ok(ok, name); console.log('ok ' + ++checks + ' - ' + name); };
try {
  for (const viewport of [{ width: 1600, height: 1050 }, { width: 1280, height: 900 }, { width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    page.on('pageerror', error => { errors.push(error.message); console.error('Browser error:', error.message); });
    page.on('console', message => { if (message.type() === 'error') console.error('Console:', message.text()); });
    await page.addInitScript(() => { document.addEventListener('securitypolicyviolation', event => { globalThis.__violations ||= []; globalThis.__violations.push(event.violatedDirective); }); });
    page.on('request', request => requests.push({ method: request.method(), url: request.url() }));
    await page.goto(origin);
    await page.locator('#campus-global-query').waitFor({ timeout: 5000 }).catch(async error => { console.error(await page.evaluate(() => ({ toast: document.querySelector('#toast')?.textContent, slot: document.querySelector('#campus-search-slot')?.outerHTML, tools: !!globalThis.mountCampusSearch, engine: !!globalThis.EverbranchSearch, state: typeof data, body: document.body.innerText.slice(-500) }))); throw error; });
    const input = page.locator('#campus-global-query'), panel = page.locator('#campus-instant-search .eb-search-panel');
    if (process.env.SEARCH_LEGACY_SMOKE === '1') { await input.fill('pipe'); await page.locator('.eb-search-option').first().waitFor(); check(await page.locator('.eb-search-option').count() > 0, 'regenerated pinned demo supports instant search at ' + viewport.width); await context.close(); continue; }
    await page.locator('#persona').selectOption('admin');
    await input.focus(); await input.fill('J05');
    await page.locator('.eb-search-option').first().waitFor();
    check((await page.locator('.eb-search-option').allTextContents()).some(text => /pipe/i.test(text)), 'instant exact ID results without submit at ' + viewport.width);
    check(await input.getAttribute('aria-expanded') === 'true', 'combobox exposes expanded state');
    await input.press('ArrowDown');
    check(!!(await input.getAttribute('aria-activedescendant')), 'arrow navigation announces active result');
    await input.press('Escape'); check(!await panel.isVisible(), 'Escape closes palette and restores query focus');
    await input.fill('pipe'); await page.locator('.eb-search-option').first().waitFor();
    check(await page.evaluate(() => { const before = document.querySelector('#campus-global-query'); document.querySelector('nav [data-go="home"]').click(); return before === document.querySelector('#campus-global-query') && before.value === 'pipe' && before === document.activeElement; }), 'workspace rerender preserves palette node, query and focus');
    await page.screenshot({ path: resolve(evidence, 'campus-search-' + viewport.width + '.png') });
    if (viewport.width > 1150) {
      const inputRect = await input.boundingBox(), mainRect = await page.locator('main.main').boundingBox();
      check(Math.abs(inputRect.x + inputRect.width / 2 - (mainRect.x + mainRect.width / 2)) < 2, 'search bar is geometrically centered within workspace');
    } else {
      const panelRect = await panel.boundingBox(); check(panelRect.x === 0 && Math.abs(panelRect.width - viewport.width) < 1, 'mobile palette uses full screen');
      const filterBoxes = await page.locator('.eb-search-filters select').evaluateAll(nodes => nodes.map(node => ({ y: node.getBoundingClientRect().y, width: node.getBoundingClientRect().width })));
      check(filterBoxes[0].width > 150 && filterBoxes[2].y > filterBoxes[0].y, 'phone filters use readable two-column rows');
    }
    await input.fill('> submit request'); await page.locator('.eb-search-option').filter({ hasText: 'Submit request' }).waitFor();
    await input.press('ArrowDown'); await input.press('Enter');
    await page.locator('dialog[open]').waitFor();
    check(await page.locator('#intake-form').count() > 0, 'command opens existing request form without submission');
    await page.locator('dialog[open] .close').click();
    await input.fill('pipe'); await page.locator('.eb-search-option').first().waitFor();
    await input.press('Escape');
    await page.locator('#persona').selectOption('contractor');
    check(await input.inputValue() === '', 'persona change retires old search query and results');
    violations.push(...await page.evaluate(() => globalThis.__violations || []));
    await context.close();
  }
  check(errors.length === 0, 'no JavaScript errors: ' + errors.join('; '));
  check(violations.length === 0, 'no CSP violations: ' + violations.join('; '));
  check(requests.every(request => request.method === 'GET' && request.url.startsWith(origin)), 'demo sends no provider or API requests');
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
