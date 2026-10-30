import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { createServer } from 'node:http';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const html = readFileSync(new URL('../../resources/demos/lees-mcrae-campus.html', import.meta.url), 'utf8').replace(/<(script|style)(?=[\s>])/gi, '<$1 nonce="map-fixture"');
const csp = "default-src 'none'; script-src 'nonce-map-fixture'; style-src 'nonce-map-fixture'; style-src-attr 'unsafe-inline'; img-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; worker-src 'none'; font-src 'none'";
const server = createServer((request, response) => { response.writeHead(200, { 'Content-Type': 'text/html', 'Content-Security-Policy': csp }); response.end(html); });
const liveUrl = process.env.CAMPUS_MAP_URL;
if (!liveUrl) await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = liveUrl || 'http://127.0.0.1:' + server.address().port;
const origin = new URL(url).origin;
const evidence = resolve(process.env.SEARCH_EVIDENCE || '../map-evidence'); mkdirSync(evidence, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: process.env.DEMO_BROWSER_EXECUTABLE || (process.platform === 'win32' ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined) });
const errors = [], requests = [], violations = [];
let checks = 0; const check = (ok, name) => { assert.ok(ok, name); console.log('ok ' + ++checks + ' - ' + name); };
try {
  for (const viewport of [{ width: 1600, height: 1050 }, { width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport, hasTouch: viewport.width < 500 });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => requests.push(request.url()));
    await page.addInitScript(() => document.addEventListener('securitypolicyviolation', event => { globalThis.__violations ||= []; globalThis.__violations.push(event.violatedDirective); }));
    const response = await page.goto(url);
    check(response.status() === 200, 'campus document responds successfully at ' + viewport.width);
    await page.locator('#persona').selectOption('admin');
    const building = page.locator('#campus-map .campus-job-marker[aria-label^="Shelton Learning Commons"]');
    const single = page.locator('#campus-map .campus-job-marker[aria-label^="Cannon Welcome Center"]');
    const tooltip = page.locator('.campus-building-tooltip');
    if (viewport.width > 500) {
      await building.hover(); await tooltip.waitFor();
      const tip = await tooltip.boundingBox(), map = await page.locator('#campus-map').boundingBox();
      check(tip.y >= map.y && tip.y + tip.height <= map.y + map.height, 'hover details fit in the default North campus view');
      await page.screenshot({ path: resolve(evidence, 'campus-map-north-hover.png') });
    }
    await page.locator('#map-fit').click();
    await tooltip.waitFor({ state: 'detached' });
    check(await building.count() === 1, 'shared building has one marker at ' + viewport.width);
    check(await building.locator('svg[data-work-icon=technology]').count() === 1 && (await building.innerText()).trim() === '', 'marker shows only the work icon');
    check(!await tooltip.count(), 'building and job titles are hidden until hover or focus');
    const markerSize = await building.boundingBox(); check(markerSize.width === 38 && markerSize.height === 38, 'map markers stay compact');
    if (viewport.width > 500) {
      await building.hover(); await tooltip.waitFor();
      const text = await tooltip.textContent();
      check(text.includes('Shelton Learning Commons') && ['J02', 'J06', 'J07', 'J08'].every(id => text.includes(id)), 'hover reveals building and all its visible jobs');
      check(text.includes('Technology Services') && text.includes('active') && text.includes('Due 2026-10-21'), 'hover includes job department, status and due date');
      check(await tooltip.evaluate(node => parseFloat(getComputedStyle(node).fontSize)) >= 13, 'hover text has readable full-size type');
      const tooltipRect = await tooltip.boundingBox(), mapRect = await page.locator('#campus-map').boundingBox();
      check(tooltipRect.y >= mapRect.y && tooltipRect.y + tooltipRect.height <= mapRect.y + mapRect.height, 'hover building and job titles stay inside the map');
      await page.screenshot({ path: resolve(evidence, 'campus-map-hover.png') });
      await page.locator('#map-fit').hover();
      await tooltip.waitFor({ state: 'detached' });
      check(await building.locator('span').evaluate(node => node.getBoundingClientRect().width) === 38, 'moving away restores compact view');
    }
    await building.click();
    const choices = page.locator('#campus-job-info [data-building-job]');
    check(await choices.count() === 4, 'click opens this building job chooser');
    check(await page.locator('#building-jobs-heading').textContent() === 'Shelton Learning Commons', 'chooser names the building');
    await page.screenshot({ path: resolve(evidence, 'campus-map-chooser-' + viewport.width + '.png') });
    await page.locator('[data-building-job="J07"]').click();
    check((await page.locator('#campus-job-building').textContent()).startsWith('J07'), 'choosing a job opens its existing detail');
    await single.click();
    check((await page.locator('#campus-job-building').textContent()).startsWith('J01') && await choices.count() === 0, 'single-job building opens job directly');
    await building.focus(); await tooltip.filter({ hasText: 'Shelton Learning Commons' }).waitFor();
    await tooltip.filter({ hasText: 'Cannon Welcome Center' }).waitFor({ state: 'detached' });
    check((await tooltip.textContent()).includes('Shelton Learning Commons'), 'keyboard focus exposes the same building and jobs');
    await building.press('Enter');
    check(await choices.count() === 4, 'Enter opens building job chooser');
    await choices.first().focus(); await page.keyboard.press('Enter');
    check(await page.locator('#job-select').count() === 1, 'keyboard can choose a job');
    violations.push(...await page.evaluate(() => globalThis.__violations || []));
    await context.close();
  }
  check(!errors.length, 'no browser errors: ' + errors.join('; '));
  check(!violations.length, 'no CSP violations: ' + violations.join('; '));
  check(requests.every(url => url.startsWith(origin)), 'demo remains browser-only with no provider requests');
} finally { await browser.close(); if (!liveUrl) await new Promise(resolve => server.close(resolve)); }
