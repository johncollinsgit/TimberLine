import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), root = resolve(), php = process.env.PHP_BINARY || 'php', probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done)); const port = probe.address().port; await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-team-calendar-commerce-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.TEAM_EVENT_COMMERCE_EVIDENCE || 'test-results/team-event-commerce'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'),
  DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '',
  MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true',
  TEAM_WORK_ASANA_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false', POS_RUNTIME_ID: 'event_browser_fixture' };
let server, browser;
try {
  await exec(php, ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: root, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec(php, [resolve('tests/e2e/fixtures/team-event-commerce-fixture.php')], { cwd: root, env })).stdout);
  env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
  server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: root, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(ready);
  const checked = [];
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch(); const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    const page = await context.newPage(); page.setDefaultTimeout(15000); await page.clock.setFixedTime(new Date('2026-11-07T12:00:00Z'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/teams?tenant=${fixture.tenant}`); await page.waitForFunction(() => window.TeamPremium?.context?.ready());
    await page.getByRole('tab', { name: 'Board', exact: true }).click();
    await page.locator('.teams-event-board .teams-task-card').filter({ hasText: 'Fictional seasonal makers and home goods weekend market' }).first().click();
    const dialog = page.locator('#tops-detail'), workspace = dialog.locator('.event-commerce');
    await workspace.getByRole('button', { name: 'Sales', exact: true }).waitFor();
    await workspace.getByText('Fictional Returning customer · $21.60', { exact: true }).waitFor();
    assert.equal(await workspace.getByText('$20.00', { exact: true }).count(), 2, 'Net sales and average order use net merchandise totals');
    await workspace.getByRole('link', { name: 'Marketing audience', exact: true }).waitFor();
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
      assert.ok(await dialog.evaluate(node => node.scrollWidth <= node.clientWidth + 1), `${name}@${width} no horizontal overflow`);
      await workspace.getByText('Fictional Returning customer · $21.60', { exact: true }).click();
      await workspace.getByText('2 × Fictional Garden candle 8oz cotton wick', { exact: true }).waitFor();
      await workspace.evaluate(node => { const body = node.closest('#tops-detail-body'); body.scrollTop += node.getBoundingClientRect().top - body.getBoundingClientRect().top - 16; });
      await page.screenshot({ path: join(evidence, `event-sales-${name}-${width}.png`) });
      await workspace.getByRole('button', { name: 'View customer', exact: true }).click();
      await workspace.getByRole('heading', { name: 'Fictional Returning customer', exact: true }).waitFor();
      const openCustomer = workspace.getByRole('link', { name: 'Open customer', exact: true }); await openCustomer.waitFor();
      assert.ok((await openCustomer.boundingBox()).height >= 44, 'Customer navigation keeps a full touch target');
      assert.equal(new URL(await openCustomer.getAttribute('href'), origin).searchParams.get('event_id'), '730', 'Customer navigation retains event context');
      await workspace.getByRole('button', { name: 'Back to sales', exact: true }).click();
      await workspace.getByRole('button', { name: 'Customers', exact: true }).click();
      const search = workspace.getByRole('searchbox', { name: 'Search event customers', exact: true }); await search.fill('fictional');
      const customer = workspace.getByRole('button', { name: /Fictional Returning customer/ }); await customer.waitFor();
      await workspace.evaluate(node => { const body = node.closest('#tops-detail-body'); body.scrollTop += node.getBoundingClientRect().top - body.getBoundingClientRect().top - 16; });
      await page.screenshot({ path: join(evidence, `event-customers-${name}-${width}.png`) });
      await customer.click(); await workspace.getByRole('heading', { name: 'Fictional Returning customer', exact: true }).waitFor();
      await workspace.getByRole('button', { name: 'Back to customers', exact: true }).click();
      await workspace.getByRole('button', { name: 'Sales', exact: true }).click();
      await workspace.getByText('Fictional Returning customer · $21.60', { exact: true }).waitFor();
      checked.push(`${name}@${width}`);
    }
    await dialog.locator('#tops-detail-close').click(); assert.deepEqual(errors, []); await browser.close(); browser = null;
  }
  console.log(JSON.stringify({ checked, errors: [], evidence }));
} finally { await browser?.close(); server?.kill(); await rm(dir, { recursive: true, force: true }); }
