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
const evidence = resolve(process.env.TEAM_DETAIL_EVIDENCE || 'test-results/team-event-detail-lifecycle'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser, page;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', [resolve('tests/e2e/fixtures/team-main-event-fixture.php')], { cwd: base, env })).stdout);
  env.TEAM_WORK_TENANT_ALLOWLIST = `${fixture.tenant_id},${fixture.other_tenant_id}`;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(ready);
  const summary = [];
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    page = await context.newPage(); page.setDefaultTimeout(12000); await page.clock.setFixedTime(new Date('2026-11-07T12:00:00Z'));
    const errors = [], calls = []; page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { const url = new URL(request.url()); if (url.pathname.startsWith('/team/')) calls.push({ path: url.pathname, method: request.method() }); });
    await page.goto(`${origin}/teams?tenant=${fixture.tenant}`);
    const cards = page.locator('.teams-event-board .teams-task-card'), dialog = page.locator('#tops-detail');
    await cards.first().waitFor(); await page.waitForLoadState('networkidle');
    const initialReads = calls.length, perTab = {};
    for (const tab of ['List', 'Calendar', 'Board']) {
      const before = calls.length; await page.getByRole('tab', { name: tab, exact: true }).click();
      await page.locator(tab === 'List' ? '.teams-event-list' : tab === 'Calendar' ? '.tops-event-calendar' : '.teams-event-board').waitFor();
      await page.waitForLoadState('networkidle'); perTab[tab] = calls.slice(before).map(call => call.path.replace(/[0-9a-f]{8}-[0-9a-f-]{27,}/g, ':id'));
    }
    const titleA = await cards.nth(0).locator('.teams-task-card-title').innerText(), titleB = await cards.nth(1).locator('.teams-task-card-title').innerText();
    let holdRead = null, holdApproval = null, holdWindow = null;
    await page.route('**/team/operations/records/*', async route => {
      if (route.request().method() !== 'GET') return route.continue();
      const response = await route.fetch(), payload = await response.json();
      if (payload.id === fixture.event_id) payload.data = { ...payload.data, fields_revision: 'fictional-revision', fields: [{ field: 'venue', value: 'Fictional review candidate', passage: 'Venue: Fictional review candidate', review_state: 'pending' }] };
      const pending = holdRead; holdRead = null;
      if (pending) { pending.started(); await pending.promise; }
      await route.fulfill(pending?.denied ? { status: 403, json: { message: 'Fictional obsolete source denial.' } } : { response, json: payload });
    });
    const deferred = (denied = false) => { let started, release; const ready = new Promise(done => { started = done; }), promise = new Promise(done => { release = done; }); return { ready, started, promise, release, denied }; };
    const snapshot = async () => ({ title: await page.locator('#tops-detail-title').innerText(), body: await page.locator('#tops-detail-body').innerText(), status: await page.locator('#tw-status').innerText() });
    const openB = async () => { await cards.nth(1).click(); await page.locator('#tops-detail-body table').first().waitFor(); assert.equal(await page.locator('#tops-detail-title').innerText(), titleB); };
    for (const denied of [false, true]) {
      const pending = deferred(denied); holdRead = pending;
      await cards.nth(0).click(); await pending.ready; await page.locator('#tops-detail-close').click(); await openB();
      const before = await snapshot(); pending.release(); await page.waitForLoadState('networkidle');
      assert.deepEqual(await snapshot(), before, `${name}: late ${denied ? 'denied' : 'successful'} A read cannot change B title, body or status`);
      await page.locator('#tops-detail-close').click();
    }
    await page.route('**/team/operations/records/*/review', async route => {
      assert.equal(route.request().method(), 'POST');
      const body = route.request().postDataJSON(); assert.equal(body.action, 'fields'); assert.equal(body.expected_fields_revision, 'fictional-revision');
      const pending = holdApproval; holdApproval = null; assert.ok(pending); pending.started(); await pending.promise;
      await route.fulfill({ json: { id: fixture.event_id, version: 2 } }); // Fake acceptance; no DB/provider mutation.
    });
    await cards.nth(0).click(); await dialog.getByLabel('Approve Venue: Fictional review candidate').check();
    const pendingApproval = deferred(); holdApproval = pendingApproval;
    await dialog.getByRole('button', { name: 'Approve selected fields', exact: true }).click(); await pendingApproval.ready;
    await page.locator('#tops-detail-close').click(); await openB(); const beforeApproval = await snapshot(), countBefore = calls.filter(call => call.path === '/team/operations/records/' + fixture.event_id).length;
    pendingApproval.release(); await page.waitForLoadState('networkidle');
    assert.deepEqual(await snapshot(), beforeApproval, `${name}: a settled approval for A never reopens A over B`);
    assert.equal(calls.filter(call => call.path === '/team/operations/records/' + fixture.event_id).length, countBefore, 'No obsolete follow-up detail GET');
    await page.locator('#tops-detail-close').click();
    await page.route('**/team/operations/records?*', async route => {
      const pending = holdWindow; if (!pending) return route.continue(); holdWindow = null;
      const response = await route.fetch(); pending.started(); await pending.promise; await route.fulfill({ response });
    });
    const oldWindow = deferred(); holdWindow = oldWindow;
    await page.getByRole('tab', { name: 'Calendar', exact: true }).click(); await oldWindow.ready;
    const latestMembers = page.waitForResponse(response => /\/team\/areas\/[^/]+\/members$/.test(new URL(response.url()).pathname));
    await page.getByRole('tab', { name: 'List', exact: true }).click(); await page.locator('.teams-event-list').waitFor(); await (await latestMembers).finished();
    const membersBefore = calls.filter(call => /\/members$/.test(call.path)).length;
    oldWindow.release(); await page.waitForLoadState('networkidle');
    assert.equal(calls.filter(call => /\/members$/.test(call.path)).length, membersBefore, 'Retired area load does not issue another members request');
    assert.equal(await dialog.isVisible(), false); assert.deepEqual(errors, []);
    summary.push({ browser: name, initial_reads: initialReads, per_tab_reads: perTab, delayed_success_fenced: true, delayed_denial_fenced: true, obsolete_approval_reread_suppressed: true, obsolete_members_read_suppressed: true });
    await context.close(); await browser.close(); browser = null;
  }
  await writeFile(join(evidence, 'results.json'), JSON.stringify({ summary, scope: 'Private authenticated SQLite; local GETs, fictional intercepted approval only, no provider traffic' }, null, 2));
  console.log(JSON.stringify(summary));
} finally { await browser?.close(); server?.kill(); await rm(dir, { recursive: true, force: true }); }
