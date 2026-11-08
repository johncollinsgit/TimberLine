import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port; await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-team-calendar-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.TEAM_MAIN_EVENT_EVIDENCE || 'test-results/team-main-event-surface'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser, page;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', [resolve('tests/e2e/fixtures/team-main-event-fixture.php')], { cwd: base, env })).stdout);
  env.TEAM_WORK_TENANT_ALLOWLIST = `${fixture.tenant_id},${fixture.other_tenant_id}`;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(ready);
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });

    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    page = await context.newPage(); page.setDefaultTimeout(12000); await page.clock.setFixedTime(new Date('2026-11-08T01:00:00Z'));
    await page.addInitScript(() => {
      const timers = new Map(), set = window.setInterval.bind(window), clear = window.clearInterval.bind(window);
      window.setInterval = (fn, delay, ...args) => { const id = set(fn, delay, ...args); if (delay === 60000) timers.set(id, fn); return id; };
      window.clearInterval = id => { timers.delete(id); return clear(id); };
      window.__refreshTeams = () => {
        const refresh = [...timers.values()].filter(fn => typeof fn === 'function' && ['panel', 'detail', 'editor'].every(key => String(fn).includes(key)));
        if (refresh.length !== 1) throw new Error(`Expected one Teams refresh timer: ${JSON.stringify([...timers.values()].map(String))}`); refresh[0]();
      };
    });
    const errors = [], reads = []; page.on('pageerror', error => errors.push(error.message)); page.on('request', request => { const url = new URL(request.url()); if (url.pathname === '/team/operations/records') reads.push(url); });
    await page.goto(`${origin}/teams?tenant=${fixture.tenant}`); await page.waitForFunction(() => window.TeamPremium?.context?.ready());
    const tabs = page.locator('[data-team-layout]'), target = 'Fictional seasonal makers and home goods weekend market';
    const openEvent = async () => { await page.locator('#tw-list .teams-event-board button.teams-task-card:visible, #tw-list .teams-event-list button.teams-task-card:visible, #tw-list .tops-event-calendar button.tops-calendar-session:visible').filter({ hasText: target }).first().click(); const detail = page.locator('#tops-detail'); await detail.getByRole('heading', { name: target }).waitFor(); await detail.getByRole('heading', { name: 'Dated event schedule' }).waitFor(); await detail.locator('#tops-detail-close').click(); };
    await tabs.filter({ hasText: 'Board' }).click(); await page.locator('#tw-list .teams-event-board').waitFor();
    assert.equal(await page.getByText('Loading events for the selected dates…', { exact: true }).count(), 0, 'Loading placeholder clears after the surface mounts');
    await openEvent();
    const board = page.locator('#tw-list .teams-event-board');
    const desktopBoxes = await board.locator('button.teams-task-card').evaluateAll(cards => cards.map(card => { const b = card.getBoundingClientRect(); return { x: b.x, y: b.y, width: b.width, height: b.height }; }));
    assert.ok(desktopBoxes.length >= 3 && new Set(desktopBoxes.slice(0, 3).map(box => Math.round(box.x))).size >= 3, 'Desktop board shows at least three card columns');
    assert.ok(desktopBoxes.every(box => box.width >= 220 && box.width <= 280 && box.height >= 220 && box.height <= 280 && Math.abs(box.width - box.height) <= 2), 'Desktop event cards are bounded squares');
    const longTitle = board.locator('button.teams-task-card').filter({ hasText: target });
    const titleFits = await longTitle.evaluate(card => { const c = card.getBoundingClientRect(), t = card.querySelector('.teams-task-card-title').getBoundingClientRect(); return t.left >= c.left && t.right <= c.right + 1 && t.bottom <= c.bottom + 1; });
    assert.ok(titleFits, 'Long event title remains inside its card');
    await page.screenshot({ path: join(evidence, `main-board-${name}.png`) });
    await page.setViewportSize({ width: 390, height: 844 });
    const phoneBoxes = await board.locator('button.teams-task-card').evaluateAll(cards => cards.map(card => { const b = card.getBoundingClientRect(); return { width: b.width, height: b.height }; }));
    assert.ok(phoneBoxes.length && phoneBoxes.every(box => box.width >= 220 && box.width <= 280 && Math.abs(box.width - box.height) <= 2), 'Phone cards stay bounded and square');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Phone board has no page overflow');
    await page.screenshot({ path: join(evidence, `main-board-phone-${name}.png`) });
    await page.setViewportSize({ width: 1440, height: 1000 });
    assert.ok(reads.some(url => url.searchParams.get('event_scope') === 'window' && url.searchParams.get('calendar_from') === '2026-11-07' && url.searchParams.get('calendar_to') === '2026-12-06'), 'Board uses the area local date for the upcoming 30 days across UTC midnight');
    assert.ok(reads.some(url => url.searchParams.get('event_scope') === 'window' && url.searchParams.has('cursor')), 'Transport continuation loads automatically');
    assert.equal(await board.locator('button.teams-task-card').count(), fixture.upcoming_event_count, 'Every accessible event loads without user paging');
    assert.equal(await board.getByText('Upcoming event 204', { exact: true }).count(), 1, 'Every upcoming event loads beyond the first transport batch');
    assert.equal(await board.getByText('Historical archive market', { exact: true }).count(), 0, '2022 history is absent by default');
    assert.equal(await board.getByText('Future January market', { exact: true }).count(), 0, 'Distant future is outside the upcoming window');
    assert.equal(await page.getByRole('button', { name: 'Load more events', exact: true }).count(), 0, 'Board has no continuation button');
    assert.equal(await page.locator('#tw-list .tops-inline-calendar-nav').count(), 0, 'Board has no month filter');
    await page.locator('#tw-filter-toggle').click();
    await page.getByLabel('Events from', { exact: true }).fill('2022-02-01'); await page.getByLabel('Events through', { exact: true }).fill('2022-02-28');
    await page.getByRole('button', { name: 'Apply dates', exact: true }).click();
    await board.getByText('Historical archive market', { exact: true }).waitFor();
    assert.equal(await board.locator('button.teams-task-card').count(), 1, 'Controls explicitly load a historical date range');
    assert.ok(reads.some(url => url.searchParams.get('calendar_from') === '2022-02-01' && url.searchParams.get('calendar_to') === '2022-02-28'));
    await page.getByRole('button', { name: 'Upcoming 30 days', exact: true }).click();
    await board.getByText('Upcoming event 204', { exact: true }).waitFor(); await page.locator('#tw-filter-toggle').click();

    await tabs.filter({ hasText: 'List' }).click(); const list = page.locator('#tw-list .teams-event-list'); await list.waitFor(); await openEvent();
    await list.getByText('Window event 00', { exact: true }).waitFor(); assert.equal(await list.locator('button.teams-task-card').count(), fixture.upcoming_event_count);
    const positions = await list.locator(':scope > *').evaluateAll(rows => rows.map(row => row.textContent));
    assert.ok(positions.findIndex(text => text.includes(target)) < positions.findIndex(text => text.includes('Window event 00')), 'Ongoing Nov 5 event sorts before later November events');
    const due = list.locator('button.teams-task-card').filter({ hasText: 'Fictional Asana due marker' });
    await due.waitFor(); assert.match(await due.innerText(), /Asana due date/); assert.match(await due.innerText(), /not a confirmed event day/);
    assert.equal(await due.locator('.teams-task-card-title').innerText(), 'Fictional Asana due marker', 'Event name leads without the repeated source date prefix');
    assert.equal(await due.getAttribute('title'), '11.09.26 - 11.10.26 Fictional Asana due marker', 'Original source title remains available');
    await page.screenshot({ path: join(evidence, `main-list-${name}.png`) });
    await tabs.filter({ hasText: 'Calendar' }).click(); const grid = page.locator('#tw-list .tops-event-calendar'); await grid.waitFor();
    await grid.locator(`[data-calendar-event="${fixture.event_id}"]`).first().waitFor(); await openEvent();
    // A real pending refresh used to clear the entire calendar for the request's
    // duration. Exercise the timer, overlapping ticks and failure recovery.
    await page.waitForLoadState('networkidle');
    await tabs.filter({ hasText: 'Calendar' }).focus();
    let releaseRefresh, refreshStarted;
    const refreshGate = new Promise(resolve => { releaseRefresh = resolve; });
    const refreshPending = new Promise(resolve => { refreshStarted = resolve; });
    let gated = false;
    const slowRefresh = async route => { if (!gated) { gated = true; refreshStarted(); await refreshGate; } await route.continue(); };
    await page.route('**/team/operations/records?**', slowRefresh);
    await page.evaluate(() => { window.__existingTeamsGrid = document.querySelector('.tops-event-calendar'); window.__refreshTeams(); });
    await Promise.race([refreshPending, new Promise((_, reject) => setTimeout(() => reject(new Error('Idle refresh did not start')), 5000))]);
    try {
      assert.ok(await grid.isVisible(), 'Calendar remains visible during an idle refresh');
      assert.equal(await grid.evaluate(node => node === window.__existingTeamsGrid), true, 'Pending refresh retains the displayed calendar');
      assert.equal(await page.locator('#tops-content').evaluate(node => getComputedStyle(node).opacity), '1', 'Idle refresh does not fade the content');
      assert.equal(await page.locator('#tops-content').evaluate(node => node.inert), true, 'Interactions wait for current access verification');
      const beforeTick = reads.length; await page.evaluate(() => window.__refreshTeams()); await page.waitForTimeout(100);
      assert.equal(reads.length, beforeTick, 'A second idle tick does not supersede the pending refresh');
    } finally { releaseRefresh(); }
    await page.locator('#tops-content:not([aria-busy])').waitFor(); await page.unroute('**/team/operations/records?**', slowRefresh);
    await grid.locator(`[data-calendar-event="${fixture.event_id}"]`).first().waitFor();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] [data-calendar-event="' + fixture.event_id + '"]').count(), 0, 'A gap day stays empty');
    assert.equal(await page.locator('#tw-controls-panel').isVisible(), false, 'Secondary controls start collapsed');
    assert.equal(await page.locator('#tw-controls-panel .tops-event-calendar-controls').isVisible(), false, 'Calendar view options stay inside Controls');
    assert.equal(await page.locator('#tw-list .tops-inline-calendar-nav').count(), 1); await page.screenshot({ path: join(evidence, `main-calendar-${name}.png`) });
    await page.locator('#tw-filter-toggle').click(); assert.equal(await page.locator('#tw-controls-panel .tops-event-calendar-controls').isVisible(), true); await page.locator('#tw-filter-toggle').click();
    assert.ok(reads.some(url => url.searchParams.get('event_scope') === 'window' && url.searchParams.get('calendar_from') === '2026-11-01' && url.searchParams.get('calendar_to') === '2026-12-31'), 'Calendar requests its selected month plus one bounded forward month');
    assert.equal(await grid.locator('[data-calendar-date="2026-12-10"]').count(), 0, 'Forward buffer does not render December cells in November');
    assert.equal(await page.locator('#tops-content').getByText('Future December market', { exact: true }).count(), 0, 'Forward-buffer records stay off the selected month surface');
    const beforeBufferedNavigation = reads.length;
    await page.locator('.tops-inline-calendar-nav').getByRole('button', { name: 'Next period', exact: true }).click();
    await grid.locator('[data-calendar-date="2026-12-10"]').getByText('Future December market', { exact: true }).waitFor();
    assert.equal(reads.length, beforeBufferedNavigation, 'The buffered next month opens instantly without records requests');
    const changed = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/operations/records' && url.searchParams.get('calendar_from') === '2027-01-01' && url.searchParams.get('calendar_to') === '2027-02-28'; });
    await page.locator('.tops-inline-calendar-nav').getByRole('button', { name: 'Next period', exact: true }).click(); await changed; await page.locator('#tops-content:not([aria-busy])').waitFor();
    await grid.locator('[data-calendar-date="2027-01-10"]').getByText('Future January market', { exact: true }).waitFor();
    for (const month of ['2026-12', '2026-11']) {
      const changed = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/operations/records' && url.searchParams.get('calendar_from') === month + '-01'; });
      await page.locator('.tops-inline-calendar-nav').getByRole('button', { name: 'Previous period', exact: true }).click(); await changed; await page.locator('#tops-content:not([aria-busy])').waitFor();
    }
    await grid.locator(`[data-calendar-event="${fixture.event_id}"]`).first().waitFor();
    await page.locator('#tw-filter-toggle').click(); const beforeLocalNavigation = reads.length;
    await page.locator('#tops-inline-options').getByRole('button', { name: '3 days', exact: true }).click();
    await page.getByLabel('Calendar first day', { exact: true }).fill('2026-11-06'); await page.getByLabel('Calendar first day', { exact: true }).blur();
    await grid.locator('[data-calendar-date="2026-11-08"] [data-calendar-kind="selling"]').waitFor();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] [data-calendar-event="' + fixture.event_id + '"]').count(), 0, 'An ongoing event never fills a sparse gap day');
    await page.getByLabel('Calendar colors').selectOption('person');
    assert.equal(reads.length, beforeLocalNavigation, 'Day, view and color changes inside the loaded month need no records request');
    await page.locator('#tops-inline-options').getByRole('button', { name: 'Month', exact: true }).click(); await page.locator('#tw-filter-toggle').click();

    await page.evaluate(({ areaId, recordId }) => window.TeamOperations.open(areaId, recordId), { areaId: fixture.area_id, recordId: fixture.event_id });
    await page.locator('#tops-detail').getByRole('heading', { name: target }).waitFor();
    await page.locator('#tops-detail-close').click();
    const partialFirstBatch = async route => {
      const url = new URL(route.request().url());
      if (url.searchParams.get('event_scope') !== 'window' || url.searchParams.has('cursor')) return route.continue();
      const response = await route.fetch(); const body = await response.json();
      await route.fulfill({ response, json: { ...body, items: body.items.slice(1), source_access_incomplete: true, collection_access_incomplete: false, partial: true, complete: false } });
    };
    await page.route('**/team/operations/records?**', partialFirstBatch);
    await tabs.filter({ hasText: 'List' }).click(); await list.locator('button.teams-task-card').first().waitFor();
    assert.equal(await list.locator('button.teams-task-card').count(), fixture.upcoming_event_count - 1, 'One explicitly unavailable source record does not hide other authorized events');
    await page.locator('#tops-content').getByText('Some source records are unavailable. Only currently accessible events are shown.', { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Load more events', exact: true }).count(), 0, 'Partial source coverage is distinct from transport pagination');
    await page.unroute('**/team/operations/records?**', partialFirstBatch);
    const failContinuation = async route => {
      const url = new URL(route.request().url());
      if (url.searchParams.get('event_scope') !== 'window' || !url.searchParams.has('cursor')) return route.continue();
      const response = await route.fetch(); const body = await response.json();
      await route.fulfill({ response, json: { ...body, collection_access_incomplete: true } });
    };
    await page.route('**/team/operations/records?**', failContinuation);
    await tabs.filter({ hasText: 'Board' }).click();
    await page.locator('#tops-content').getByText('This view is unavailable', { exact: true }).waitFor();
    assert.equal(await page.locator('#tw-list .teams-task-card').count(), 0, 'An incomplete source access check clears every earlier transport batch');
    await page.unroute('**/team/operations/records?**', failContinuation);
    await page.locator('#tw-filter-toggle').click(); await page.locator('#tw-refresh').click();
    await board.locator('button.teams-task-card').first().waitFor();
    assert.equal(await board.locator('button.teams-task-card').count(), fixture.upcoming_event_count, 'Refresh retries the full collection after access check recovery');
    await page.route('**/team/operations/records?**', route => route.fulfill({ status: 503, json: { message: 'Temporary connection failure' } }));
    await page.evaluate(() => window.__refreshTeams());
    await page.locator('#tops-content').getByText('This view is unavailable', { exact: true }).waitFor();
    assert.equal(await page.locator('#tops-content .teams-task-card').count(), 0, 'Failed refresh clears records that could not be reverified');
    assert.equal(await page.locator('#tops-content').getAttribute('aria-busy'), null, 'Failed refresh releases its loading state');
    await page.unroute('**/team/operations/records?**');
    await page.locator('#tw-refresh').click(); await board.locator('button.teams-task-card').first().waitFor();
    await page.route('**/team/operations/records?**', route => route.fulfill({ status: 401, json: { message: 'Session expired' } }));
    await page.evaluate(() => window.__refreshTeams());
    await page.locator('#tops-content').getByRole('button', { name: 'Verify original account', exact: true }).waitFor();
    assert.equal(await page.locator('#tops-content .teams-task-card').count(), 0, 'Expired session retires event records');
    assert.equal(await page.locator('#tops-content').getAttribute('data-background-refresh'), null, 'Expired session retires its refresh state');
    const expiredReads = reads.length; await page.evaluate(() => window.__refreshTeams()); await page.waitForTimeout(100);
    assert.equal(reads.length, expiredReads, 'Expired session does not keep polling');
    await page.unroute('**/team/operations/records?**');
    assert.deepEqual(errors, []);
    await context.close();
    const otherContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await otherContext.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const otherCsrf = (await (await otherContext.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok((await otherContext.request.post(origin + '/login', { form: { email: 'no-events@fixture.invalid', password: 'Fictional-local-only-2026!', _token: otherCsrf }, headers: { Accept: 'application/json' } })).ok());
    const otherPage = await otherContext.newPage(); await otherPage.goto(`${origin}/teams?tenant=${fixture.other_tenant}`); await otherPage.waitForFunction(() => window.TeamPremium?.context?.ready());
    await otherPage.locator('[data-team-layout="calendar"]').click(); await otherPage.locator('.teams-calendar').first().waitFor();
    assert.equal(await otherPage.locator('.tops-inline-calendar').count(), 0, 'Tenant without Events falls back to its task calendar');
    await otherContext.close(); await browser.close(); browser = null;
  }
  process.stdout.write('Main event surface: Chromium and WebKit passed.\n');
} catch (error) {
  if (page) await page.screenshot({ path: join(evidence, 'failure.png') }).catch(() => {});
  throw error;
} finally {
  if (browser) await browser.close();
  if (server && server.exitCode === null) { const stopped = new Promise(done => server.once('exit', done)); server.kill('SIGTERM'); await stopped; }
  await rm(dir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
