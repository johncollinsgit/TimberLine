import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const { chromium } = await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE)).href);
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
const origin = 'http://127.0.0.1:8766';
try {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await context.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
  const page = await context.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto(origin + '/team?tenant=fictional-evergreen');
  await page.locator('input[name=email]').fill('owner@fictional.invalid');
  await page.locator('input[name=password]').fill('Fictional-local-only-2026!'); await page.locator('[data-test=login-button]').click();
  await page.goto(origin + '/team?tenant=fictional-evergreen'); await page.locator('#tw-count').filter({ hasText: 'tasks on page' }).waitFor();
  const response = await context.request.get(origin + '/team/data?tenant=fictional-evergreen', { headers: { Accept: 'application/json' } });
  const project = (await response.json()).projects.findLast(p => Object.values(p.sections).includes('Ready for visit') && !p.archived); assert.ok(project);
  await page.locator('#tw-project-filter').selectOption(project.id); await page.locator('#tw-layout').selectOption('board');
  await page.locator('#tw-list .board-column h2').filter({ hasText: /^Ready for visit$/ }).waitFor();
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  await page.screenshot({ path: resolve('../evidence/team-laravel/mobile-board.png'), fullPage: true }); console.log('ok 1 - 390px board scroll stays inside its card');
  await page.locator('#tw-list .task-open').first().click(); await page.waitForFunction(() => document.querySelector('#tw-detail-meta').textContent.startsWith('Version'));
  await page.locator('#tw-back').click(); assert.equal(await page.locator('#tw-title').inputValue(), ''); assert.equal(await page.locator('#tw-assignee').inputValue(), ''); console.log('ok 2 - leaving detail clears prior task and assignee display');
  await page.locator('#tw-layout').selectOption('calendar'); await page.locator('#tw-month').fill('2026-11');
  await page.locator('#tw-list h2').filter({ hasText: /^2026-11-03$/ }).waitFor();
  await page.getByRole('button', { name: 'Create for 2026-11-03', exact: true }).click();
  assert.equal(await page.locator('#tw-create-due').inputValue(), '2026-11-03'); assert.equal(await page.locator('#tw-create-project').inputValue(), project.id);
  await page.locator('#tw-task-dialog [data-close]').click(); console.log('ok 3 - calendar create carries selected literal date and project into review');
  assert.deepEqual(errors, []); console.log('ok 4 - mobile view transitions have no JavaScript runtime errors\n1..4');
} finally { await browser.close(); }
