import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), root = resolve(), php = process.env.PHP_BINARY || 'php', probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port;
await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-customer-profile-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.CUSTOMER_PROFILE_EVIDENCE || 'test-results/customer-profile-theme');
await mkdir(evidence, { recursive: true });
await writeFile(join(dir, 'customer-profile-theme.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
  APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'customer-profile-theme.sqlite'), DB_URL: '', DATABASE_URL: '',
  CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync',
  LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser;
const checked = [], contrast = [];
try {
  await exec(php, ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: root, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec(php, [resolve('tests/e2e/fixtures/customer-profile-theme-fixture.php')], { cwd: root, env })).stdout);
  env.MARKETING_DESK_ENABLED = 'true'; env.MARKETING_DESK_TENANT_IDS = String(fixture.tenant_id);
  server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: root, env, stdio: 'ignore' });
  for (let attempt = 0; attempt < 80; attempt++) {
    try { if ((await fetch(`${origin}/up`)).ok) break; } catch {}
    await new Promise(done => setTimeout(done, 200));
  }
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await page.goto(`${origin}/login?tenant=${fixture.tenant}`);
    await page.getByRole('textbox', { name: /email/i }).fill('customer-profile@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Fictional-profile-2026!');
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForLoadState('networkidle');
    for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
      await page.emulateMedia({ colorScheme: theme });
      await page.setViewportSize({ width, height: 1000 });
      for (const [path, label] of [
        [`/workspace/customers/${fixture.profile_id}?tenant=${fixture.tenant}`, 'profile'],
        [`/workspace/customers/square?tenant=${fixture.tenant}`, 'square-connection'],
        [`/workspace/customers/reconciliation?tenant=${fixture.tenant}`, 'reconciliation'],
        [`/workspace/customers?tenant=${fixture.tenant}`, 'directory'],
        [`/marketing/desk?tenant=${fixture.tenant}`, 'marketing-desk'],
      ]) {
        const response = await page.goto(origin + path);
        assert.equal(response.status(), 200, path);
        await page.evaluate(dark => document.documentElement.classList.toggle('dark', dark), theme === 'dark');
        if (label === 'profile') {
          await page.getByRole('heading', { name: 'Purchases & Candle Cash', exact: true }).waitFor();
          await page.locator('.customer-commerce-balance').getByText('7.25 reward credits', { exact: true }).waitFor();
          await page.getByRole('heading', { name: 'Birthday', exact: true }).waitFor();
          await page.getByText('02/29', { exact: true }).waitFor();
        }
        const colors = await page.locator('#app-main').evaluate(main => {
          const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
          const ctx = canvas.getContext('2d', { willReadFrequently: true });
          const rgba = color => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = color; ctx.fillRect(0, 0, 1, 1); return [...ctx.getImageData(0, 0, 1, 1).data]; };
          const lum = color => color.slice(0, 3).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; }).reduce((n, v, i) => n + v * [.2126, .7152, .0722][i], 0);
          return [...main.querySelectorAll('h1, h2, h3, a[href], button:not(:disabled), label, .eb-square-details summary, .eb-square-record-title strong, .customer-commerce th, .customer-birthday p')]
            .filter(node => node.getBoundingClientRect().height > 0 && getComputedStyle(node).visibility !== 'hidden' && node.textContent.trim() !== '')
            .map(node => {
              const fg = rgba(getComputedStyle(node).color);
              let bg = [255, 255, 255, 255];
              for (let parent = node; parent; parent = parent.parentElement) {
                const color = rgba(getComputedStyle(parent).backgroundColor);
                if (color[3] === 255) { bg = color; break; }
              }
              const a = lum(fg), b = lum(bg);
              return { text: node.textContent.trim().slice(0, 90), ratio: Math.round(((Math.max(a, b) + .05) / (Math.min(a, b) + .05)) * 100) / 100, foreground: fg, background: bg };
            });
        });
        assert.ok(colors.length > 0 || label === 'reconciliation', `Expected rendered contrast subjects for ${label}`);
        contrast.push({ engine: name, theme, width, page: label, colors });
        const failures = colors.filter(color => color.ratio < 4.5);
        if (failures.length) {
          await page.screenshot({ path: join(evidence, `contrast-failure-${label}-${name}-${theme}-${width}.png`), fullPage: true });
          await writeFile(join(evidence, 'contrast-failure.json'), JSON.stringify({ engine: name, theme, width, page: label, colors }, null, 2));
        }
        assert.deepEqual(failures, [], `${name} ${theme} ${width} ${label}: text must contrast with its rendered panel`);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2), false, `${label} ${name} ${theme}@${width}: horizontal overflow`);
        await page.screenshot({ path: join(evidence, `${label}-${name}-${theme}-${width}.png`), fullPage: true });
        if (label === 'profile' && width === 1440) await page.locator('.eb-square-history').screenshot({ path: join(evidence, `square-panel-${name}-${theme}.png`) });
        checked.push(`${label} ${name} ${theme}@${width}`);
      }
    }
    assert.deepEqual(errors, []);
    await browser.close(); browser = null;
  }
  await writeFile(join(evidence, 'contrast.json'), JSON.stringify({ checked, contrast }, null, 2));
  console.log(JSON.stringify({ checked, minimum_contrast: Math.min(...contrast.flatMap(row => row.colors.map(color => color.ratio))), evidence }));
} finally {
  await browser?.close(); server?.kill('SIGTERM');
  await rm(dir, { recursive: true, force: true });
}
