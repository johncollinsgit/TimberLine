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
let server, browser, currentPage;
const checked = [], contrast = [];
const loginOutcomes = [], navigation = [], currentErrors = [], consoleErrors = [];
let phase = { step: 'prepare' }, tenantSlug = '';
const redact = (value, limit = 8000) => String(value ?? '').replaceAll('Fictional-profile-2026!', '[redacted]')
  .replace(/\b(?:sk_(?:test|live)_|whsec_)[\w-]+/g, '[redacted]')
  .replace(/\bBearer\s+[^\s"'<>]+/gi, 'Bearer [redacted]').slice(0, limit);
const safeUrl = value => {
  try {
    const url = new URL(value, origin);
    if (url.origin !== origin) return '[external URL]';
    const tenant = url.searchParams.get('tenant');
    return url.pathname + (tenant && tenant === tenantSlug ? `?tenant=${encodeURIComponent(tenant)}` : '');
  } catch { return '[invalid URL]'; }
};
const pageState = page => page.evaluate(() => ({
  title: document.title,
  headings: [...document.querySelectorAll('h1,h2,h3')].slice(0, 30).map(node => node.textContent.trim().slice(0, 160)),
  app_main: !!document.querySelector('#app-main'),
  password_form: !!document.querySelector('form input[type="password"]'),
  commerce_panels: document.querySelectorAll('.customer-commerce').length,
  visible_commerce_panels: [...document.querySelectorAll('.customer-commerce')].filter(node => node.getBoundingClientRect().height > 0 && getComputedStyle(node).visibility !== 'hidden').length,
}));
try {
  await exec(php, ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: root, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec(php, [resolve('tests/e2e/fixtures/customer-profile-theme-fixture.php')], { cwd: root, env })).stdout);
  tenantSlug = fixture.tenant;
  env.MARKETING_DESK_ENABLED = 'true'; env.MARKETING_DESK_TENANT_IDS = String(fixture.tenant_id);
  server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: root, env, stdio: 'ignore' });
  for (let attempt = 0; attempt < 80; attempt++) {
    try { if ((await fetch(`${origin}/up`)).ok) break; } catch {}
    await new Promise(done => setTimeout(done, 200));
  }
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    currentPage = page;
    phase = { engine: name, step: 'login-page' };
    navigation.length = currentErrors.length = consoleErrors.length = 0;
    const errors = [];
    page.on('pageerror', error => { errors.push(error.message); if (currentErrors.length < 20) currentErrors.push(redact(error.message)); });
    page.on('console', message => { if (['error', 'warning'].includes(message.type()) && consoleErrors.length < 20) consoleErrors.push({ type: message.type(), text: redact(message.text()) }); });
    page.on('response', response => {
      const request = response.request();
      if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
        navigation.push({ method: request.method(), url: safeUrl(response.url()), status: response.status(), redirected_from: request.redirectedFrom() ? safeUrl(request.redirectedFrom().url()) : null });
        if (navigation.length > 24) navigation.shift();
      }
    });
    page.on('requestfailed', request => {
      if (request.isNavigationRequest() && navigation.length < 24) navigation.push({ method: request.method(), url: safeUrl(request.url()), failure: redact(request.failure()?.errorText) });
    });
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await page.goto(`${origin}/login?tenant=${fixture.tenant}`);
    await page.getByRole('textbox', { name: /email/i }).fill('customer-profile@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Fictional-profile-2026!');
    phase.step = 'login-submit';
    await Promise.all([
      page.waitForURL(url => url.origin === origin && url.pathname === '/dashboard', { waitUntil: 'domcontentloaded' }),
      page.getByRole('button', { name: /log in|sign in/i }).click(),
    ]);
    await page.waitForLoadState('networkidle');
    await page.locator('#app-main').waitFor();
    assert.equal(await page.locator('form input[type="password"]').count(), 0, `${name}: fixture login must complete before customer navigation`);
    loginOutcomes.push({ engine: name, settled_url: safeUrl(page.url()), ...await pageState(page), navigation: [...navigation] });
    await writeFile(join(evidence, 'login-outcomes.json'), JSON.stringify(loginOutcomes, null, 2));
    for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
      await page.emulateMedia({ colorScheme: theme });
      await page.setViewportSize({ width, height: 1000 });
      for (const [path, label] of [
        [`/workspace/customers/${fixture.profile_id}?tenant=${fixture.tenant}`, 'profile'],
        [`/workspace/customers/square?tenant=${fixture.tenant}`, 'square-connection'],
        [`/workspace/customers/reconciliation?tenant=${fixture.tenant}`, 'reconciliation'],
        [`/workspace/customers?tenant=${fixture.tenant}`, 'directory'],
        [`/marketing/desk?tenant=${fixture.tenant}`, 'marketing-desk'],
        [`/subscriptions/recharge?tenant=${fixture.tenant}`, 'recharge-setup'],
      ]) {
        phase = { engine: name, theme, width, page: label, requested_url: safeUrl(origin + path), step: 'navigate' };
        const response = await page.goto(origin + path);
        phase = { ...phase, status: response.status(), final_url: safeUrl(page.url()), step: 'page-assertions' };
        assert.equal(response.status(), 200, path);
        await page.evaluate(dark => document.documentElement.classList.toggle('dark', dark), theme === 'dark');
        if (label === 'profile') {
          await page.getByRole('heading', { name: 'Purchases & Candle Cash', exact: true }).waitFor();
          await page.locator('.customer-commerce-balance').getByText('7.25 reward credits', { exact: true }).waitFor();
          await page.getByRole('heading', { name: 'Birthday', exact: true }).waitFor();
          await page.getByText('02/29', { exact: true }).waitFor();
        }
        if (label === 'recharge-setup') {
          const fields = page.locator('.eb-recharge-setup input:not([type="hidden"]):not([type="checkbox"])');
          assert.equal(await fields.count(), 3);
          for (const field of await fields.all()) {
            const box = await field.boundingBox();
            assert.ok(box.width > 100 && box.height >= 44, 'Private setup fields must have usable visible dimensions');
            assert.equal(await field.evaluate(el => getComputedStyle(el).borderTopStyle), 'solid');
          }
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
    currentPage = null;
  }
  await writeFile(join(evidence, 'contrast.json'), JSON.stringify({ checked, contrast }, null, 2));
  console.log(JSON.stringify({ checked, minimum_contrast: Math.min(...contrast.flatMap(row => row.colors.map(color => color.ratio))), evidence }));
} catch (error) {
  try {
    const failure = { phase, error: { name: error.name, message: redact(error.message) }, completed: checked, login_outcomes: loginOutcomes,
      navigation, page_errors: currentErrors, console_errors: consoleErrors };
    await writeFile(join(evidence, 'failure.json'), JSON.stringify(failure, null, 2));
    if (currentPage && !currentPage.isClosed()) {
      failure.final_url = safeUrl(currentPage.url());
      failure.page = await pageState(currentPage);
      await writeFile(join(evidence, 'failure.json'), JSON.stringify(failure, null, 2));
      const html = await currentPage.evaluate(() => {
        const copy = document.body.cloneNode(true);
        copy.querySelectorAll('script,style,noscript,input[type="hidden"],input[type="password"],textarea').forEach(node => node.remove());
        for (const node of [copy, ...copy.querySelectorAll('*')]) for (const attribute of [...node.attributes]) {
          if (!['id', 'class', 'role', 'type', 'name', 'aria-label', 'aria-labelledby', 'aria-hidden', 'aria-expanded', 'aria-selected'].includes(attribute.name)) node.removeAttribute(attribute.name);
        }
        return copy.outerHTML.slice(0, 64000);
      });
      await writeFile(join(evidence, 'failure-dom.html'), redact(html, 64000));
      await currentPage.screenshot({ path: join(evidence, 'failure.png'), fullPage: false, mask: [currentPage.locator('input[type="password"]')] });
    }
    await writeFile(join(evidence, 'failure.json'), JSON.stringify(failure, null, 2));
  } catch (captureError) { console.error(`Failure capture: ${redact(captureError.message)}`); }
  throw error;
} finally {
  await browser?.close(); server?.kill('SIGTERM');
  await rm(dir, { recursive: true, force: true });
}
