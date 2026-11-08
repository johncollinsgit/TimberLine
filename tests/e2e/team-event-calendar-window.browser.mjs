import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done)); const port = probe.address().port; await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-team-calendar-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.TEAM_CALENDAR_WINDOW_EVIDENCE || 'test-results/team-calendar-window'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
await rm(join(evidence, 'failure.png'), { force: true });
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'false', TEAM_CALENDAR_WINDOW_FIXTURE: '1', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser, page;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', [resolve('scripts/everbranch-team/operations-calendar-browser-fixture.php')], { cwd: base, env })).stdout); env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(ready);
  const checks = [];
  for (const [engine, engineName] of [[chromium, 'Chromium'], [webkit, 'WebKit']]) {
    browser = await engine.launch(); const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1]; assert.ok(csrf);
    assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    page = await context.newPage(); page.setDefaultTimeout(12000); const errors = [], writes = [], reads = [];
    page.on('pageerror', error => errors.push(error.message)); page.on('request', request => { const url = new URL(request.url()); if (url.pathname.startsWith('/team/') && !['GET', 'HEAD'].includes(request.method())) writes.push(request.url()); if (url.pathname === '/team/operations/records') reads.push(url); });
    await page.goto(`${origin}/teams?tenant=${fixture.tenant}&area=${fixture.area_id}&area_view=calendar`);
    const area = page.locator('#team-operations'), grid = page.locator('.tops-event-calendar');
    const month = () => area.getByLabel('Calendar month', { exact: true });
    const settled = () => page.waitForFunction(() => !document.querySelector('#tops-content').hasAttribute('aria-busy'));
    async function search(value) {
      const response = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/operations/records' && url.searchParams.get('q') === value; });
      await area.getByLabel('Search this area').fill(value); await response; await settled();
    }
    async function selectMonth(value) {
      if (await month().inputValue() === value) { await settled(); return; }
      const response = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/operations/records' && url.searchParams.get('calendar_from') === value + '-01'; });
      await month().fill(value); await month().blur(); await response; await settled();
    }
    await month().fill('2026-11'); await grid.locator(`[data-calendar-event="${fixture.event_id}"]`).first().waitFor();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] button').count(), 0, 'Sparse gap is not filled');
    assert.ok(reads.some(url => url.searchParams.get('calendar_from') === '2026-11-01' && url.searchParams.get('calendar_to') === '2026-11-30'), 'Month requests server-side window');
    await search('Current page event');
    await grid.getByText('Current page event 000', { exact: true }).waitFor();
    await area.getByRole('button', { name: 'Load more events', exact: true }).click(); await grid.getByText('Current page event 030', { exact: true }).waitFor();
    assert.ok(reads.some(url => url.searchParams.get('calendar_recheck_sources') === '1'), 'Continuation asks for current source-project access');
    assert.equal(await grid.getByText('Current page event 000', { exact: true }).count(), 1, 'First page remains visible once');
    await area.getByRole('button', { name: 'Load more events', exact: true }).click(); await area.getByRole('button', { name: 'Continue with next events', exact: true }).waitFor();
    await area.getByRole('button', { name: 'Continue with next events', exact: true }).click(); await grid.getByText('Current page event 091', { exact: true }).waitFor();
    assert.equal(await grid.getByText('Current page event 000', { exact: true }).count(), 0, 'Explicit next batch releases prior 90');
    await search('Fictional legacy range');
    const legacyAnchor = grid.locator('[data-calendar-date="2026-11-06"] [data-calendar-kind="range_anchor"]');
    await legacyAnchor.waitFor();
    await legacyAnchor.getByText('Unconfirmed range', { exact: true }).waitFor();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] [data-calendar-kind="range_anchor"]').count(), 0, 'A range marker does not invent activity on intervening days');
    const compact = await grid.evaluate(element => {
      const day = element.querySelector('.tops-day[data-calendar-date]'), card = element.querySelector('[data-calendar-kind="range_anchor"]');
      return { minimumDayHeight: parseFloat(getComputedStyle(day).minHeight), cardHeight: card.getBoundingClientRect().height, cardRadius: parseFloat(getComputedStyle(card).borderRadius), gridWidth: parseFloat(getComputedStyle(element).minWidth) };
    });
    assert.ok(compact.minimumDayHeight <= 150 && compact.gridWidth <= 840, 'Month calendar is compact');
    assert.ok(compact.cardHeight >= 44 && compact.cardRadius >= 8, 'Event markers retain touch targets and rounded edges');
    await search('Fictional due anchor'); await grid.locator('[data-calendar-kind="source_due"]').waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="source_due"]').count(), 1); await grid.getByText('Task date · not a confirmed event day').waitFor();
    await search('Fictional range and due separated');
    await grid.locator('[data-calendar-date="2026-11-06"] [data-calendar-kind="range_anchor"]').waitFor();
    await grid.locator('[data-calendar-date="2026-11-20"] [data-calendar-kind="source_due"]').waitFor();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] [data-calendar-kind="range_anchor"]').count(), 0, 'Range and due markers never invent intervening event days');
    await search('Fictional range and due same day');
    await grid.locator('[data-calendar-date="2026-11-06"] [data-calendar-kind="source_due"]').waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="range_anchor"]').count(), 0, 'A due anchor already marking the range start is not duplicated');
    await search('Fictional reviewed outlier'); await grid.locator('[data-calendar-kind="selling"]').waitFor();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-10"] [data-calendar-kind="source_due"]').count(), 1, 'Due outside a reviewed schedule remains distinct');
    await selectMonth('2026-10'); await grid.locator('[data-calendar-date="2026-10-31"] [data-calendar-kind="setup"]').waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="source_due"]').count(), 0, 'Other-month due does not leak into selected dates');
    assert.ok(reads.some(url => url.searchParams.get('calendar_from') === '2026-10-01' && !url.searchParams.has('cursor')), 'Month change resets continuation');
    await selectMonth('2026-11'); await grid.locator('[data-calendar-kind="selling"]').waitFor();
    await search('seasonal makers'); await grid.locator(`[data-calendar-event="${fixture.event_id}"]`).first().waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="source_due"]').count(), 0, 'Due anchor is deduplicated against same-day session');
    await area.getByRole('button', { name: '3 days', exact: true }).click(); await area.getByLabel('Calendar first day').fill('2026-11-06'); await area.getByLabel('Calendar first day').blur();
    await grid.locator('[data-calendar-date="2026-11-08"] [data-calendar-kind="selling"]').waitFor(); assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] button').count(), 0);
    assert.ok(reads.some(url => url.searchParams.get('calendar_from') === '2026-11-06' && url.searchParams.get('calendar_to') === '2026-11-08'), 'Day selection requests exact new server window');
    await area.getByRole('button', { name: 'Month', exact: true }).click(); await selectMonth('2026-11');
    await search('Current page event'); await grid.getByText('Current page event 000', { exact: true }).waitFor();
    await page.route('**/team/operations/records?**', async route => { if (!new URL(route.request().url()).searchParams.has('cursor')) return route.continue(); const response = await route.fetch(); const body = await response.json(); await route.fulfill({ response, json: { ...body, items: [], count: 0, has_more: false, next_cursor: null, source_access_incomplete: true, complete: false } }); });
    await area.getByRole('button', { name: 'Load more events', exact: true }).click(); await area.getByText('Earlier loaded pages were cleared because a current source access check was unavailable.').waitFor();
    assert.equal(await grid.getByText('Current page event 000', { exact: true }).count(), 0, 'Source denial clears earlier accumulated pages');
    await page.unroute('**/team/operations/records?**');
    await search('Partial batch'); await grid.getByText('Partial batch current 000', { exact: true }).waitFor();
    await area.getByRole('button', { name: 'Load more events', exact: true }).click(); await grid.getByText('Partial batch current 059', { exact: true }).waitFor();
    await area.getByRole('button', { name: 'Load more events', exact: true }).click(); await grid.getByText('Partial batch current 079', { exact: true }).waitFor();
    await area.getByText(/Load more events to continue checking retained dates/).waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="selling"]').count(), 80, 'Real scan budget yielded a partial 20-row third page');
    await area.getByRole('button', { name: 'Load more events', exact: true }).click(); await grid.getByText('Partial batch outlier 029', { exact: true }).waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="selling"]').count(), 110, 'Complete full page survives crossing the 90-record threshold');
    await area.getByText('110 events loaded. Continue replaces this batch; dates outside the loaded batch remain unchecked.').waitFor();
    await area.getByRole('button', { name: 'Continue with next events', exact: true }).click(); await grid.getByText('Partial batch outlier 030', { exact: true }).waitFor();
    assert.equal(await grid.locator('[data-calendar-kind="selling"]').count(), 1, 'Next cursor continues after every fully retained prior row');
    assert.equal(errors.length, 0, errors.join('\n')); assert.equal(writes.length, 0, 'Calendar browsing is read-only');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await search('Fictional reviewed outlier'); await grid.locator('[data-calendar-kind="source_due"]').waitFor();
    const dueMarker = grid.locator('[data-calendar-kind="source_due"]');
    assert.equal(await dueMarker.evaluate(element => getComputedStyle(element).borderTopStyle), 'dashed', 'Task anchor has a distinct non-activity treatment');
    await dueMarker.screenshot({ path: join(evidence, `${engineName.toLowerCase()}-due-marker.png`) });
    await area.getByLabel('Search this area').blur();
    await page.screenshot({ path: join(evidence, `${engineName.toLowerCase()}-390.png`), fullPage: true });
    checks.push({ browser: engineName, monthScoped: true, currentAfterHistoricalPage: true, explicitPaginationAndCap: true, partialPageCrossingThresholdPreservesRows: true, sparseAndCrossMonth: true, dueAnchorDistinctAndDeduplicated: true, accessFailureClears: true, noWrites: true });
    await browser.close(); browser = null;
  }
  console.log(JSON.stringify({ checks }, null, 2));
} catch (error) { if (page) await page.screenshot({ path: join(evidence, 'failure.png'), fullPage: true }).catch(() => {}); throw error; }
finally { await browser?.close(); server?.kill('SIGTERM'); await rm(dir, { recursive: true, force: true }); }
