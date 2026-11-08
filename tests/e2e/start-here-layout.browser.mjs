import { mkdtemp, writeFile, readFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import assert from 'node:assert/strict';

let playwright;
try { playwright = await import('playwright'); }
catch (error) {
  if (!process.env.PLAYWRIGHT_MODULE_PATH) throw error;
  playwright = await import(pathToFileURL(resolve(process.env.PLAYWRIGHT_MODULE_PATH)).href);
}
const exec = promisify(execFile), base = resolve();
const evidence = resolve(process.env.START_HERE_EVIDENCE || 'test-results/start-here-layout');
await mkdir(evidence, { recursive: true });
const probe = createServer(); await new Promise(done => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port; await new Promise(done => probe.close(done));
const origin = `http://127.0.0.1:${port}`, dir = await mkdtemp(join(tmpdir(), 'codex-start-here-'));
await writeFile(join(dir, 'db.sqlite'), ''); await writeFile(join(dir, 'http.log'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin,
  APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'),
  DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '',
  TEAM_WORK_DISPOSABLE_MYSQL: '0', POS_LIVE_DISPOSABLE_MYSQL: '0',
  CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '',
  MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4', LOG_CHANNEL: 'null',
  NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false',
  TEAM_WORK_MOBILE_ENABLED: 'false',
  START_HERE_BROWSER_HTTP_LOG: join(dir, 'http.log') };
let server, browser, page;
const measurements = [], screenshots = [], errors = [], writes = [], mobileFallback = [], longLabelStress = [];
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', [resolve('scripts/everbranch-team/start-here-browser-fixture.php')], { cwd: base, env })).stdout);
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', resolve('public'), resolve('tests/e2e/start-here-browser-router.php')], { cwd: base, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); }
  assert.ok(ready, 'Private Laravel server starts');
  for (const engine of ['chromium', 'webkit']) {
    browser = await playwright[engine].launch();
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const login = await context.request.get(origin + '/login');
    const csrf = (await login.text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1]; assert.ok(csrf);
    const signed = await context.request.post(origin + '/login', { form: { email: 'owner@start-here-fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } });
    assert.ok(signed.ok(), `Real Fortify login ${signed.status()}`);
    page = await context.newPage(); page.setDefaultTimeout(12000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (new URL(request.url()).origin === origin && !['GET', 'HEAD'].includes(request.method())) writes.push(request.url()); });
    for (const width of [403, 320, 390, 430, 1440]) {
      await page.setViewportSize({ width, height: width === 1440 ? 1000 : 900 });
      const response = await page.goto(`${origin}/start?tenant=${fixture.demo.slug}`); assert.equal(response.status(), 200);
      const guide = page.getByRole('link', { name: 'Open your tailored setup guide →', exact: true }); await guide.waitFor();
      await page.getByRole('heading', { name: fixture.demo.name, exact: true }).waitFor();
      await page.getByText('Viewing Demo Tenant', { exact: true }).waitFor();
      const measured = await guide.evaluate(link => {
        const header = document.querySelector('.fb-workflow-header'), main = link.closest('[data-flux-main]');
        const box = link.getBoundingClientRect(), card = header.getBoundingClientRect();
        const parent = document.querySelector('.mf-shell-content > div'), css = getComputedStyle(parent);
        return { viewport: innerWidth, pageWidth: document.documentElement.scrollWidth, guide: { x: box.x, y: box.y, width: box.width, height: box.height, font: getComputedStyle(link).fontFamily }, header: { x: card.x, y: card.y, width: card.width }, guideInsideMain: Boolean(main), headerInsideSameMain: Boolean(main?.contains(header)), shellColumns: css.gridTemplateColumns };
      });
      const file = join(evidence, `${engine}-${width}.png`); await page.screenshot({ path: file }); screenshots.push(file); measurements.push({ engine, ...measured });
      await writeFile(join(evidence, 'measurements.json'), JSON.stringify(measurements, null, 2));
      assert.ok(measured.guideInsideMain && measured.headerInsideSameMain, 'Guide and Start Here card share the same Flux main column');
      assert.ok(measured.pageWidth <= width + 1, `${engine}${width}: no horizontal overflow`);
      assert.ok(measured.guide.height >= 44 && measured.guide.height <= 90, `${engine}${width}: readable touch target with natural wrapping`);
      assert.ok(Math.abs(measured.guide.x - measured.header.x) <= 1, `${engine}${width}: guide and header align`);
      assert.ok(measured.header.x <= (width < 1024 ? 48 : 400), `${engine}${width}: no phantom left column`);
      assert.ok(measured.header.x + measured.header.width <= width + 1, `${engine}${width}: main card is not clipped off-screen`);
      if (width < 640) assert.ok(Math.abs(measured.guide.width - measured.header.width) <= 1, `${engine}${width}: full-width phone guide`);
      assert.ok(measured.header.y >= measured.guide.y + measured.guide.height && measured.header.y - measured.guide.y - measured.guide.height <= 32, `${engine}${width}: no excessive vertical gap`);
      assert.ok((await guide.getAttribute('href')).includes(`tenant=${fixture.demo.slug}`), 'Guide retains original tenant context');
    }
    await page.setViewportSize({ width: 403, height: 900 });
    await page.goto(`${origin}/start?tenant=${fixture.production.slug}`);
    await page.getByRole('heading', { name: fixture.production.name, exact: true }).waitFor();
    assert.equal(await page.getByText('Viewing Demo Tenant', { exact: true }).count(), 0);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Long workspace title remains contained');
    const guide = page.getByRole('link', { name: 'Open your tailored setup guide →', exact: true }); await guide.focus();
    assert.ok(await guide.evaluate(link => document.activeElement === link), 'Guide is keyboard accessible');
    const file = join(evidence, `${engine}-403-long-name-focus.png`); await page.screenshot({ path: file }); screenshots.push(file);
    const guideResponse = await context.request.get(await guide.getAttribute('href')); assert.ok(guideResponse.ok(), 'Existing setup guide remains reachable');
    // Browser-only copy stress; the server-rendered CTA and its destination stay unchanged.
    const longerLabel = await guide.evaluate(link => {
      link.textContent = 'Open your tailored setup guide and review the next steps for this workspace →';
      const box = link.getBoundingClientRect();
      return { width: box.width, height: box.height, right: box.right, viewport: innerWidth };
    });
    assert.ok(longerLabel.height >= 44 && longerLabel.height <= 110 && longerLabel.right <= 403, 'Long guide copy wraps naturally within the phone');
    longLabelStress.push({ engine, ...longerLabel });
    const longLabelFile = join(evidence, `${engine}-403-long-label-stress.png`); await page.screenshot({ path: longLabelFile }); screenshots.push(longLabelFile);
    for (const width of [320, 390]) {
      await page.setViewportSize({ width, height: 900 });
      const params = new URLSearchParams({ client_id: 'everbranch-teams', redirect_uri: 'everbranchteams://auth/callback', code_challenge: 'a'.repeat(43), code_challenge_method: 'S256', state: 'fixture-state-0123456789', auth_method: 'email' });
      const blocked = await page.goto(`${origin}/mobile/authorize?${params}`);
      assert.equal(blocked.status(), 503, 'Disabled Teams sign-in fails closed with readable HTML');
      await page.getByRole('heading', { name: 'Teams isn’t ready to sign in yet', exact: true }).waitFor();
      await page.getByText('Close this window to return to Everbranch Teams.', { exact: true }).waitFor();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Disabled sign-in page is contained');
      const file = join(evidence, `${engine}-mobile-unavailable-${width}.png`); await page.screenshot({ path: file }); screenshots.push(file);
      mobileFallback.push({ engine, width, status: blocked.status(), readable: true, overflow: false });
    }
    await browser.close(); browser = null;
  }
  assert.deepEqual(errors, []); assert.deepEqual(writes, []);
  assert.equal(await readFile(join(dir, 'http.log'), 'utf8'), '', 'No provider HTTP or outgoing mail');
  await writeFile(join(evidence, 'acceptance.json'), JSON.stringify({ passed: true, fictional_only: true, transport: 'Real Fortify/Laravel HTTP and private SQLite', engines: ['chromium', 'webkit'], widths: [320, 390, 403, 430, 1440], measurements, screenshots, longLabelStress, mobileFallback, operation_writes: writes, javascript_errors: errors, provider_or_mail_calls: 0 }, null, 2));
  console.log('PASS Start Here: authenticated demo and long-name production fixtures, Chromium/WebKit at320/390/403/430/1440, aligned full-width phone guide,44px+ controls, no overflow or provider writes.');
} catch (error) {
  if (page) await page.screenshot({ path: join(evidence, 'failure.png') });
  throw error;
} finally {
  await browser?.close();
  if (server && server.exitCode === null) { server.kill('SIGTERM'); await new Promise(done => server.once('exit', done)); }
  await rm(dir, { recursive: true, force: true });
}
