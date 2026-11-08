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
    page = await context.newPage(); page.setDefaultTimeout(12000); await page.clock.setFixedTime(new Date('2026-11-07T12:00:00Z'));
    const errors = [], reads = []; page.on('pageerror', error => errors.push(error.message)); page.on('request', request => { const url = new URL(request.url()); if (url.pathname === '/team/operations/records') reads.push(url); });
    await page.goto(`${origin}/teams?tenant=${fixture.tenant}`); await page.waitForFunction(() => window.TeamPremium?.context?.ready());
    const tabs = page.locator('[data-team-layout]'), target = 'Fictional seasonal makers and home goods weekend market';
    const openEvent = async () => { await page.locator('#tw-list').getByText(target, { exact: true }).first().click(); const detail = page.locator('#tops-detail'); await detail.getByRole('heading', { name: target }).waitFor(); await detail.getByRole('heading', { name: 'Dated event schedule' }).waitFor(); await detail.locator('#tops-detail-close').click(); };
    await tabs.filter({ hasText: 'Board' }).click(); await page.locator('#tw-list .teams-event-board').waitFor();
    assert.equal(await page.getByText('Loading events for the selected dates…', { exact: true }).count(), 0, 'Loading placeholder clears after the surface mounts');
    await openEvent();
    assert.ok(reads.some(url => url.searchParams.get('calendar_from') === '2026-11-01' && url.searchParams.get('calendar_to') === '2026-11-30'), 'Board reads the bounded current month');
    assert.equal(await page.getByText('Historical padding 000', { exact: true }).count(), 0, 'Old event pages do not precede this month');
    await tabs.filter({ hasText: 'List' }).click(); const list = page.locator('#tw-list .teams-event-list'); await list.waitFor(); await openEvent();
    await page.getByRole('button', { name: 'Load more events', exact: true }).click(); await list.getByText('Window event 30', { exact: true }).waitFor();
    const positions = await list.locator(':scope > *').evaluateAll(rows => rows.map(row => row.textContent));
    assert.ok(positions.findIndex(text => text.includes('Window event 30')) < positions.findIndex(text => text.includes(target)), 'Later page reviewed Nov 4 is re-sorted before Nov 5');
    await tabs.filter({ hasText: 'Calendar' }).click(); const grid = page.locator('#tw-list .tops-event-calendar'); await grid.waitFor();
    await grid.locator(`[data-calendar-event="${fixture.event_id}"]`).first().waitFor(); await openEvent();
    assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] [data-calendar-event="' + fixture.event_id + '"]').count(), 0, 'A gap day stays empty');
    assert.equal(await page.locator('#tw-controls-panel').isVisible(), false, 'Secondary controls start collapsed');
    assert.equal(await page.locator('#tw-controls-panel .tops-event-calendar-controls').isVisible(), false, 'Calendar view options stay inside Controls');
    assert.equal(await page.locator('#tw-list .tops-inline-calendar-nav').count(), 1); await page.screenshot({ path: join(evidence, `main-calendar-${name}.png`) });
    await page.locator('#tw-filter-toggle').click(); assert.equal(await page.locator('#tw-controls-panel .tops-event-calendar-controls').isVisible(), true); await page.locator('#tw-filter-toggle').click();
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
} finally { if (browser) await browser.close(); if (server) server.kill('SIGTERM'); await rm(dir, { recursive: true, force: true }); }
