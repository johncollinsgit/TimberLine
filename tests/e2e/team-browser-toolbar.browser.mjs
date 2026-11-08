import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port;
await new Promise(done => probe.close(done));
const directory = await mkdtemp(join(tmpdir(), 'codex-team-calendar-toolbar-'));
const evidence = resolve(process.env.TEAM_TOOLBAR_EVIDENCE || 'test-results/team-browser-toolbar');
const origin = `http://127.0.0.1:${port}`;
await mkdir(evidence, { recursive: true });
await writeFile(join(directory, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(directory, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(directory, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'true', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
const results = [], errors = [], mutations = [];
let server, browser, page;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', ['scripts/everbranch-team/browser-toolbar-fixture.php'], { cwd: base, env, maxBuffer: 2e6 })).stdout);
  env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  let ready = false;
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {}
    await new Promise(done => setTimeout(done, 100));
  }
  assert.ok(ready, 'Private Laravel HTTP server started');
  for (const [engine, launcher, widths] of [['chromium', chromium, [1440, 1280, 820, 430, 390, 320]], ['webkit', webkit, [320, 390, 1440]]]) {
    browser = await launcher.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok(csrf);
    const login = await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } });
    assert.ok(login.ok(), 'Real Fortify login succeeded');
    page = await context.newPage();
    page.setDefaultTimeout(12000);
    page.on('pageerror', error => errors.push({ engine, message: error.message }));
    page.on('console', message => { if (message.type() === 'error' && /Content Security Policy|Refused to (?:load|execute|apply)/i.test(message.text())) errors.push({ engine, message: message.text() }); });
    page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/team/') && !['GET', 'HEAD'].includes(request.method())) mutations.push(request.url()); });
    for (const width of widths) {
      await page.setViewportSize({ width, height: width < 600 ? 1000 : 1050 });
      const response = await page.goto(`${origin}/teams?tenant=${fixture.tenant}`);
      assert.equal(response.status(), 200);
      await page.waitForFunction(() => window.TeamPremium?.context?.ready());
      assert.equal(await page.locator('#tw-layout').inputValue(), 'board', 'Workspace starts with Board');
      assert.equal(await page.locator('#tw-controls-panel').isVisible(), false, 'Controls start closed on every viewport');
      assert.equal(await page.locator('#tw-refresh').isVisible(), false, 'Refresh is secondary');
      await page.locator('[data-team-universal-search] input').waitFor({state:'visible'});
      if (width <= 800) await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
      const allWorkResponse = page.waitForResponse(response => {
        const url = new URL(response.url());
        return url.pathname === '/team/data' && url.searchParams.get('mine') === '0' && response.status() === 200;
      });
      await page.locator('.teams-nav [data-team-mode="all"]').click();
      await allWorkResponse;
      await page.waitForFunction(() => document.querySelector('#tw-mode').value === 'all' && document.querySelector('#tw-home').hidden && document.querySelector('#tw-list-heading').textContent === 'Tasks');
      await page.locator('#tw-page-title').filter({ hasText: /^All work$/ }).waitFor();
      await page.locator('#tw-count').filter({ hasText: '3 of 3' }).waitFor();
      await page.locator('#tw-filter-toggle').click();
      await page.locator('#tw-source-filter').selectOption('native');
      await page.locator('#tw-filter-toggle').click();
      await page.getByRole('tab', {name:'List', exact:true}).click();
      await page.locator('#tw-list .task-open').first().waitFor();
      const geometry = await page.evaluate(() => {
        const rendered = element => {
          if (!element.getClientRects().length || getComputedStyle(element).visibility === 'hidden') return false;
          // Closed native disclosures can still return hypothetical child rectangles.
          for (let parent = element.parentElement; parent; parent = parent.parentElement) {
            if (parent.tagName === 'DETAILS' && !parent.open && !parent.querySelector(':scope > summary')?.contains(element)) return false;
          }
          return true;
        };
        const controls = [...document.querySelectorAll('.teams-work-header button, .teams-work-toolbar button, .teams-work-toolbar input, .teams-work-toolbar select, .teams-work-secondary > summary, .tops-launcher button, .tops-launcher summary')]
          .filter(rendered);
        return { width: innerWidth, pageWidth: document.documentElement.scrollWidth, headerHeight: document.querySelector('.teams-work-header').getBoundingClientRect().height, toolbarHeight: document.querySelector('.teams-work-toolbar').getBoundingClientRect().height, firstTaskTop: document.querySelector('#tw-list .task-open').getBoundingClientRect().top, controls: controls.map(element => ({ id: element.id || element.textContent.trim(), height: element.getBoundingClientRect().height, top: element.getBoundingClientRect().top, bottom: element.getBoundingClientRect().bottom, font: parseFloat(getComputedStyle(element).fontSize), left: element.getBoundingClientRect().left, right: element.getBoundingClientRect().right })), scope: ['tw-search', 'tw-project-filter', 'tw-source-filter'].map(id => { const box = document.getElementById(id).getBoundingClientRect(); return { id, bottom: box.bottom, width: box.width }; }) };
      });
      results.push({ engine, ...geometry });
      assert.ok(geometry.pageWidth <= width + 1, `${engine} ${width}: no page overflow`);
      if (width <= 600) assert.ok(geometry.toolbarHeight < 420, 'Phone primary toolbar remains compact');
      if (width <= 600) assert.ok(geometry.firstTaskTop < 900, `${engine} ${width}: phone first task at ${geometry.firstTaskTop}px must remain below 900px, including platform font variation`);
      if (width <= 600) {

        assert.ok(geometry.headerHeight <= 90, `Compact phone header is ${geometry.headerHeight}px`);
        assert.equal(await page.locator('.teams-work-header').evaluate(header => [...header.querySelectorAll('button')].filter(button => button.getClientRects().length).length), 2, 'Phone header contains only navigation and Create');
        const aligned = await page.evaluate(() => { const title = document.querySelector('#tw-page-title').getBoundingClientRect(), create = document.querySelector('#tw-new-task').getBoundingClientRect(); return Math.abs(title.y + title.height / 2 - create.y - create.height / 2) < 1; });
        assert.ok(aligned, 'Phone title and Create action align on one row');
      }
      for (const control of geometry.controls) {
        assert.ok(control.height >= 43.5, `${engine} ${width}: ${control.id} has44px target (${control.height})`);
        assert.ok(control.font >= 14, `${engine} ${width}: ${control.id} uses legible type`);
        assert.ok(control.left >= -1 && control.right <= width + 1, `${engine} ${width}: ${control.id} stays inside viewport`);
      }
      for (let i = 0; i < geometry.controls.length; i++) {
        for (const other of geometry.controls.slice(i + 1)) {
          const control = geometry.controls[i];
          const horizontal = Math.min(control.right, other.right) - Math.max(control.left, other.left);
          const vertical = Math.min(control.bottom, other.bottom) - Math.max(control.top, other.top);
          assert.ok(horizontal <= 1 || vertical <= 1, `${engine} ${width}: ${control.id} must not overlap ${other.id}`);
        }
      }

      await page.screenshot({ path: join(evidence, `${engine}-all-work-${width}.png`), fullPage: true });
      await page.locator('#tw-filter-toggle').click();
      await page.locator('#tw-controls-panel').waitFor({ state: 'visible' });
      for (const id of ['tw-search','tw-project-filter','tw-source-filter','tw-saved-views','tw-refresh','tw-workspaces']) await page.locator('#'+id).waitFor({state:'visible'});
      assert.equal(await page.locator('#tw-filter-toggle').getAttribute('aria-expanded'), 'true');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Expanded Controls stay inside viewport');
      await page.locator('#tw-search').fill('organizer');
      await page.locator('#tw-search').press('Enter');
      await page.locator('#tw-count').filter({ hasText: '1 of 1' }).waitFor();
      await page.locator('#tw-search').fill('');
      await page.locator('#tw-search').press('Enter');
      await page.locator('#tw-count').filter({ hasText: '3 of 3' }).waitFor();
      await page.locator('#tw-source-filter').selectOption('all');
      await page.locator('#tw-filter-toggle').click();
      await page.getByRole('tab', { name: 'Calendar', exact: true }).click();
      await page.locator('.tops-event-calendar').waitFor();
      assert.equal(await page.locator('.teams-work-toolbar').isVisible(), true, 'Event calendar keeps the same primary work tabs');
      assert.equal(await page.locator('#tw-controls-panel').isVisible(), false, 'Calendar does not reveal secondary controls');
      await page.getByRole('tab', { name: 'List', exact: true }).click();
      await page.locator('#tw-filter-toggle').click();
      await page.locator('#tw-save-view').click();
      await page.getByRole('dialog', { name: 'Save view' }).waitFor();
      await page.locator('#tw-view-close').click();
    }
    if (!await page.locator('#tw-controls-panel').isVisible()) await page.locator('#tw-filter-toggle').click();
    await page.locator('#tw-project-filter').selectOption(fixture.project_id);
    await page.locator('#tw-page-title').filter({ hasText: 'Fictional seasonal market preparation' }).waitFor();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Long selected project stays contained');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.setViewportSize({ width: 1440, height: 1050 });
    assert.equal(await page.locator('#tw-controls-panel #tw-workspaces').count(), 1, 'Workspace picker remains within Controls after resizing');
    assert.equal(await page.locator('#tw-controls-panel #tw-new-project').count(), 1, 'Secondary actions remain within Controls');
    await browser.close(); browser = null;
  }
  browser = await chromium.launch();
  const memberContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await memberContext.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const memberCsrf = (await (await memberContext.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
  assert.ok(memberCsrf);
  const memberLogin = await memberContext.request.post(origin + '/login', { form: { email: 'member@fixture.invalid', password: 'Fictional-local-only-2026!', _token: memberCsrf }, headers: { Accept: 'application/json' } });
  assert.ok(memberLogin.ok());
  page = await memberContext.newPage();
  page.on('pageerror', error => errors.push({ engine: 'member-chromium', message: error.message }));
  page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/team/') && !['GET', 'HEAD'].includes(request.method())) mutations.push(request.url()); });
  await page.goto(`${origin}/teams?tenant=${fixture.tenant}`);
  await page.waitForFunction(() => window.TeamPremium?.context?.ready());
  const areaResponse = await memberContext.request.get(`${origin}/team/areas?tenant=${fixture.tenant}`, { headers: { Accept: 'application/json' } });
  assert.equal(areaResponse.status(), 200);
  assert.deepEqual((await areaResponse.json()).items, [], 'A member without area access receives no Event area choices');
  await page.getByRole('tab', { name: 'Calendar', exact: true }).click();
  await page.locator('.teams-calendar').waitFor();
  assert.equal(await page.locator('#tw-event-calendar-entry').isVisible(), false, 'No Event calendar shortcut is offered without an authorized area');
  await browser.close(); browser = null;
  assert.deepEqual(errors, [], 'No JavaScript or CSP errors');
  assert.deepEqual(mutations, [], 'Toolbar navigation does not mutate tasks, preferences, sources or providers');
  await writeFile(join(evidence, 'acceptance.json'), JSON.stringify({ results, errors, mutations }, null, 2));
  console.log(JSON.stringify({ toolbar_geometry: results.map(({ engine, width, firstTaskTop, headerHeight, toolbarHeight }) => ({ engine, width, firstTaskTop, headerHeight, toolbarHeight })) }, null, 2));
  console.log(`PASS authenticated browser toolbar: ${results.length} viewport/engine cases; hidden secondary controls,44px targets, view filter/calendar/dialog interactions, in-place Events calendar, no page overflow or writes.`);
} catch (error) {
  const diagnostic = { error: error.message, lastGeometry: results.at(-1), errors, mutations };
  console.error('Toolbar failure geometry: ' + JSON.stringify(diagnostic));
  await writeFile(join(evidence, 'failure-geometry.json'), JSON.stringify(diagnostic, null, 2)).catch(() => {});
  if (page && !page.isClosed()) await page.screenshot({ path: join(evidence, 'failure.png'), fullPage: true }).catch(() => {});
  throw error;
} finally {
  await browser?.close(); server?.kill(); await rm(directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
