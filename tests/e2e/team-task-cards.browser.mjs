import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done)); const port = probe.address().port;
await new Promise(done => probe.close(done));
const directory = await mkdtemp(join(tmpdir(), 'codex-team-calendar-cards-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.TEAM_CARD_EVIDENCE || 'test-results/team-task-cards');
await mkdir(evidence, { recursive: true }); await writeFile(join(directory, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(directory, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(directory, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'true', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
const errors = [], mutations = [], results = []; let server, browser;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', ['scripts/everbranch-team/browser-task-card-fixture.php'], { cwd: base, env, maxBuffer: 2e6 })).stdout);
  env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) break; } catch {} await new Promise(done => setTimeout(done, 100)); }
  for (const [engine, launcher] of [['chromium', chromium], ['webkit', webkit]]) {
    browser = await launcher.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'America/New_York' });
    const connection = '11111111-1111-4111-8111-111111111111';
    const sourceTasks = [['103', 'Fictional source undated', null], ['102', 'Fictional source later', '2026-11-20'], ['101', 'Fictional source early', '2026-11-02']].map(([gid, name, due_on]) => ({ gid, key: 'asana:' + gid, name, due_on, due_at: null, completed: false, assignee: { gid: '42', name: 'Fictional source owner' }, memberships: [{ project: { gid: '200', name: 'Fictional source project' } }], notes: 'Fictional original source description.', custom_fields: [], permalink_url: 'https://app.asana.com/0/200/' + gid }));
    await context.route('**/*', async route => {
      const url = new URL(route.request().url()); if (url.origin !== origin) return route.abort();
      if (!url.pathname.startsWith('/team/asana/')) return route.continue();
      assert.equal(route.request().method(), 'GET', 'Source cards are read only');
      const path = url.pathname.slice('/team/asana'.length), headers = route.request().headers();
      let payload = { connection_id: connection, items: [], has_more: false };
      if (path === '/status') payload = { ...payload, connected: true, configured: true, account_name: 'Fictional source account', selected_projects: ['200'], external_user_id: '42' };
      else if (path === '/tasks') payload = { ...payload, project: { gid: '200', name: 'Fictional source project' }, items: sourceTasks, external_user_id: '42' };
      else if (/^\/tasks\/\d+$/.test(path)) payload = { ...payload, ...sourceTasks.find(task => task.gid === path.split('/').at(-1)) };
      await route.fulfill({ json: payload, headers: { 'X-Team-Viewer': headers['x-team-viewer'], 'X-Team-Workspace': headers['x-team-workspace'] } });
    });
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    const page = await context.newPage(); page.setDefaultTimeout(12000); page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/team/') && !['GET', 'HEAD'].includes(request.method())) mutations.push(request.url()); });
    await page.goto(`${origin}/teams?tenant=${fixture.tenant}`); await page.waitForFunction(() => window.TeamPremium?.context?.ready());
    await page.locator('#tw-filter-toggle').click(); await page.locator('#tw-source-filter').selectOption('native');
    await page.locator('#tw-list .teams-task-card').filter({ hasText: 'Fictional early task' }).waitFor();
    await page.locator('#tw-filter-toggle').focus(); await page.keyboard.press('Escape');
    assert.equal(await page.locator('#tw-controls-panel').isVisible(), false);
    assert.equal(await page.locator('#tw-filter-toggle').evaluate(node => node === document.activeElement), true);
    const native = page.locator('#tw-list .teams-task-card'); assert.equal(await native.count(), 6);
    const dates = await native.locator('time').evaluateAll(nodes => nodes.map(node => node.dateTime));
    assert.deepEqual(dates, [...dates].sort(), 'Native board uses recorded date chronology');
    for (const width of [320, 390, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const geometry = await native.evaluateAll(nodes => nodes.map(node => { const b = node.getBoundingClientRect(); return { width: b.width, height: b.height, nested: node.querySelectorAll('button,input,select,a').length }; }));
      assert.ok(geometry.every(b => Math.abs(b.width - b.height) < 2 && b.width >= 220 && b.width <= 281 && b.height <= 281 && b.nested === 0));
      assert.ok(await native.evaluateAll(nodes => nodes.every(card => {
        const title = card.querySelector('.teams-task-card-title'), date = card.querySelector('.teams-task-card-date');
        return title.getBoundingClientRect().top < date.getBoundingClientRect().top
          && parseFloat(getComputedStyle(title).fontSize) > parseFloat(getComputedStyle(date.querySelector('strong') || date).fontSize);
      })), 'The task name is above and more prominent than its date');
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
      if (width === 1440) assert.ok(await native.evaluateAll(nodes => new Set(nodes.map(node => Math.round(node.getBoundingClientRect().left))).size) >= 3, 'Desktop board has multiple compact columns');
      await page.screenshot({ path: join(evidence, `${engine}-native-board-${width}.png`), fullPage: true });
    }
    await native.filter({ hasText: 'Fictional early task' }).click();
    await page.waitForFunction(() => document.querySelector('#tw-title')?.value === 'Fictional early task');
    assert.equal(await page.locator('#tw-description').inputValue(), 'Fictional original full task description.');
    await page.locator('#tw-draft-close').click();
    await page.locator('#tw-filter-toggle').click(); await page.locator('#tw-source-filter').selectOption('asana');
    const asana = page.locator('#tw-asana-work .teams-task-card'); await asana.filter({ hasText: 'Fictional source early' }).waitFor();
    assert.deepEqual(await asana.locator('.teams-task-card-title').allTextContents(), ['Fictional source early', 'Fictional source later', 'Fictional source undated']);
    await page.locator('#tw-filter-toggle').click(); await asana.first().click();
    await page.locator('#tw-asana-detail').getByRole('heading', { name: 'Fictional source early', exact: true }).waitFor();
    await page.locator('#tw-asana-detail').getByText('Fictional original source description.', { exact: true }).waitFor();
    await page.locator('#tw-asana-detail-close').click();
    await page.getByRole('tab', { name: 'List', exact: true }).click();
    const sourceRows = page.locator('#tw-asana-work .task-open'); await sourceRows.first().waitFor();
    assert.deepEqual(await sourceRows.allTextContents(), ['Fictional source early', 'Fictional source later', 'Fictional source undated']);
    results.push({ engine, native_cards: 6, source_cards: 3, source_list_chronology: true, full_details: true });
    await browser.close(); browser = null;
  }
  assert.deepEqual(errors, []); assert.deepEqual(mutations, []);
  await writeFile(join(evidence, 'results.json'), JSON.stringify({ results, errors, mutations, scope: 'Private real Laravel authentication/SQLite; fictional intercepted Asana, no provider actions' }, null, 2));
  console.log(JSON.stringify({ cases: results.length, results, mutations: mutations.length }));
} finally { await browser?.close(); server?.kill(); await rm(directory, { recursive: true, force: true }); }
