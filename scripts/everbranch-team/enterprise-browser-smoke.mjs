import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdir, writeFile, unlink } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const php = process.env.ENTERPRISE_TEST_PHP;
assert.ok(php, 'ENTERPRISE_TEST_PHP is required');
const {
  chromium
} = await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE)).href);
const db = resolve(`apps/enterprise/storage/framework/testing/enterprise-browser-${Date.now()}.sqlite`);
await mkdir(resolve('apps/enterprise/storage/framework/testing'), {
  recursive: true
});
await writeFile(db, '');
const evidence = resolve(process.env.ENTERPRISE_EVIDENCE || '../evidence/enterprise');
await mkdir(evidence, {
  recursive: true
});
const origin = 'http://127.0.0.1:8879';
const env = {
  ...process.env,
  ENTERPRISE_APP_ENV: 'testing',
  ENTERPRISE_APP_URL: origin,
  ENTERPRISE_APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
  ENTERPRISE_RUNTIME_ISOLATED: 'true',
  ENTERPRISE_DB_DRIVER: 'sqlite',
  ENTERPRISE_DB_DATABASE: db,
  ENTERPRISE_ENABLED: 'true',
  ENTERPRISE_WRITES_ENABLED: 'true',
  ENTERPRISE_TENANT_ALLOWLIST: '1'
};
let server, browser;
try {
  const seeded = spawnSync(php, ['scripts/everbranch-team/enterprise-browser-fixture.php'], {
    env,
    encoding: 'utf8',
    windowsHide: true
  });
  assert.equal(seeded.status, 0, seeded.stdout + seeded.stderr);
  server = spawn(php, ['-S', '127.0.0.1:8879', '-t', 'apps/enterprise/public', 'apps/enterprise/public/index.php'], {
    env,
    stdio: 'ignore',
    windowsHide: true
  });
  let ready = false;
  for (let i = 0; i < 40; i++) {
    try {
      const r = await fetch(origin + '/login');
      if (r.status === 200) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise(r => setTimeout(r, 250));
  }
  assert.ok(ready, 'Isolated local server did not start');
  browser = await chromium.launch({
    headless: true,
    ...(process.env.ENTERPRISE_CHROME ? {
      executablePath: process.env.ENTERPRISE_CHROME
    } : process.platform === 'win32' ? {
      executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe'
    } : {})
  });
  const context = await browser.newContext({
    viewport: {
      width: 1440,
      height: 1000
    }
  });
  const external = [];
  await context.route('**/*', route => {
    if (new URL(route.request().url()).hostname === '127.0.0.1') return route.continue();
    external.push(route.request().url());
    return route.abort();
  });
  const page = await context.newPage(),
    errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(origin + '/login');
  await page.getByLabel('Email', {
    exact: true
  }).fill('fixture-owner@example.invalid');
  await page.getByLabel('Password', {
    exact: true
  }).fill('Synthetic-browser-only-2026!');
  await page.getByRole('button', {
    name: 'Sign in',
    exact: true
  }).click();
  await page.getByRole('link', {
    name: 'Synthetic Enterprise'
  }).click();
  await page.getByRole('button', {
    name: 'New workspace',
    exact: true
  }).click();
  await page.getByLabel('Title', {
    exact: true
  }).fill('Synthetic Facilities');
  await page.getByRole('button', {
    name: 'Save',
    exact: true
  }).click();
  await page.locator('#visibility').filter({
    hasText: 'Department only'
  }).waitFor();
  await page.getByRole('button', {
    name: 'Operations & projects',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'New project',
    exact: true
  }).click();
  await page.getByLabel('Title', {
    exact: true
  }).fill('Synthetic repair project');
  await page.getByRole('button', {
    name: 'Save',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Synthetic repair project',
    exact: true
  }).waitFor();
  await page.getByRole('button', {
    name: 'Requests',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'New request',
    exact: true
  }).click();
  await page.getByLabel('Title', {
    exact: true
  }).fill('Synthetic inspection');
  await page.getByLabel('Work description').fill('Disposable UI fixture; no college incident.');
  await page.getByLabel('Due date').fill('2026-11-01');
  await page.getByLabel('Location label').fill('Fixture coordinate');
  await page.getByLabel('Latitude (optional)', {
    exact: true
  }).fill('35');
  await page.getByLabel('Longitude (optional)', {
    exact: true
  }).fill('-82');
  await page.getByRole('button', {
    name: 'Save',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Synthetic inspection',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Triage request',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Assign task',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Save progress',
    exact: true
  }).waitFor();
  await page.getByRole('button', {
    name: 'Map',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Open Synthetic inspection',
    exact: true
  }).click();
  await page.screenshot({
    path: resolve(evidence, 'enterprise-map-desktop.png'),
    fullPage: true
  });
  await page.getByRole('button', {
    name: 'Hide Map',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'View Map',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Calendar',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Synthetic inspection',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Complete work',
    exact: true
  }).click();
  await page.locator('#detail .badge').filter({
    hasText: 'completed'
  }).waitFor();
  await page.getByRole('button', {
    name: 'Audit history',
    exact: true
  }).click();
  await page.getByText('Version 4 · request.complete', {
    exact: false
  }).waitFor();
  await page.reload();
  await page.getByRole('button', {
    name: 'Synthetic inspection',
    exact: true
  }).click();
  await page.locator('#detail .badge').filter({
    hasText: 'completed'
  }).waitFor();
  await page.setViewportSize({
    width: 390,
    height: 844
  });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile horizontal overflow');
  await page.screenshot({
    path: resolve(evidence, 'enterprise-mobile.png'),
    fullPage: true
  });
  await page.getByRole('button', {
    name: 'Contractors',
    exact: true
  }).click();
  await page.getByText('Contractor assignments, documents and costs are planned for a later phase.', {
    exact: false
  }).waitFor();
  assert.deepEqual(errors, []);
  assert.deepEqual(external, []);
  console.log('PASS: independent-runtime login, workspace/project/request creation, triage, task assignment, map detail, Hide/View Map, calendar, completion, audit, reload persistence, mobile fit, contractor navigation; zero external requests and JavaScript errors.');
} finally {
  if (browser) await browser.close();
  if (server) server.kill();
  await unlink(db).catch(() => {});
}
