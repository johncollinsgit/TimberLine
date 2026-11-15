import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), root = resolve(), probe = createServer(); await new Promise(done => probe.listen(0, '127.0.0.1', done)); const port = probe.address().port; await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-team-calendar-navigation-')), origin = 'http://127.0.0.1:' + port, evidence = resolve('test-results/event-performance-navigation'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: root, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', ['tests/e2e/fixtures/team-main-event-fixture.php'], { cwd: root, env })).stdout); env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=' + port, '--no-reload'], { cwd: root, env, stdio: 'ignore' });
  let up = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { up = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(up);
  const results = [];
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch(); const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }); await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1]; assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    const page = await context.newPage(); page.setDefaultTimeout(15000); const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('**/team/event-performance?*', route => { const headers = route.request().headers(); return route.fulfill({ json: { status: 'ready', as_of: '2026-11-15T12:00:00Z', permissions: { can_compare_all: true, can_view_customers: false }, summary: { event_count: 1, sale_count: 10, net_sales_minor: 100000, selling_days: 1, average_day_net_sales_minor: 100000, complete: true }, rows: [{ id: fixture.event_id, label: 'Fictional linked event', event_ids: [fixture.event_id], net_sales_minor: 100000, selling_days: 1, average_day_net_sales_minor: 100000, sale_count: 10, event_count: 1, complete: true }], page: 1, total_rows: 1, has_more: false, review_items: [] }, headers: { 'X-Team-Viewer': headers['x-team-viewer'], 'X-Team-Workspace': headers['x-team-workspace'] } }); });
    await page.goto(origin + '/teams/app?tenant=' + fixture.tenant + '&app=events&area=' + fixture.area_id + '&area_view=performance'); await page.getByRole('button', { name: 'Fictional linked event', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Fictional linked event', exact: true }).click(); const dialog = page.locator('#tops-detail'); await dialog.locator('.event-glance').waitFor(); assert.equal(new URL(page.url()).searchParams.get('event_id'), fixture.event_id);
    await page.goBack(); await dialog.waitFor({ state: 'hidden' }); assert.equal(new URL(page.url()).searchParams.has('event_id'), false);
    await page.goForward(); await dialog.locator('.event-glance').waitFor(); assert.equal(await dialog.isVisible(), true, 'Forward restores the selected event');
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden' }); await page.waitForFunction(() => !new URL(location.href).searchParams.has('event_id')); assert.equal(new URL(page.url()).searchParams.has('event_id'), false, 'Escape retires selected-event URL context');
    assert.deepEqual(errors, []); await page.screenshot({ path: evidence + '/' + name + '-navigation.png', fullPage: true }); results.push({ engine: name, checks: ['actual Teams shell', 'event source read', 'back closes', 'forward reopens', 'escape clears URL'] }); await browser.close(); browser = null;
  }
  await writeFile(evidence + '/results.json', JSON.stringify(results, null, 2)); console.log(JSON.stringify(results));
} finally { await browser?.close(); server?.kill('SIGTERM'); await rm(dir, { recursive: true, force: true }); }
