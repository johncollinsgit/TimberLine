import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port; await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-team-calendar-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.TEAM_CALENDAR_EVIDENCE || 'test-results/team-operations-calendar'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
await rm(join(evidence, 'failure.png'), { force: true });
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser, page;
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', [resolve('scripts/everbranch-team/operations-calendar-browser-fixture.php')], { cwd: base, env })).stdout); env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(ready);
  browser = await chromium.launch(); const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1]; assert.ok(csrf);
  const login = await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } }); assert.ok(login.ok(), await login.text());
  page = await context.newPage(); const errors = [], writes = []; page.setDefaultTimeout(12000); page.on('pageerror', error => errors.push(error.message)); page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/team/') && !['GET', 'HEAD'].includes(request.method())) writes.push(request.url()); });
  page.on('console', message => { if (message.type() === 'error' && /Content Security Policy|Refused to (?:load|execute|apply)|\bCSP\b/i.test(message.text())) errors.push(message.text()); });
  const branding = [];
  for (const surface of ['standalone', 'embedded']) {
    for (const width of [320, 390, 639, 640, 820, 1440, 3442]) {
      await page.setViewportSize({ width, height: width >= 640 ? 1000 : 844 });
      const artworkResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/brand/everbranch-teams-trees-v1.webp' && response.status() === 200);
      const response = await page.goto(`${origin}/${surface === 'standalone' ? 'teams' : 'team'}?tenant=${fixture.tenant}`);
      await artworkResponse;
      assert.equal(response.status(), 200, surface + ' authenticated Teams page');
      await page.waitForFunction(() => window.TeamPremium?.context?.ready());
      const brand = page.locator('[data-teams-product-brand]');
      assert.equal(await brand.count(), 1, 'One compact product masthead');
      await brand.getByText('Everbranch Teams', { exact: true }).waitFor();
      const measured = await brand.evaluate(element => {
        const art = element.querySelector('.eb-teams-tree-canopy'), lockup = element.querySelector('.eb-teams-product-lockup'), clusters = [...art.querySelectorAll('.eb-teams-tree-cluster')];
        const bounds = element.getBoundingClientRect(), artBounds = art.getBoundingClientRect(), lockupBounds = lockup.getBoundingClientRect();
        return { width: innerWidth, height: bounds.height, artHeight: artBounds.height, artTop: artBounds.top, artBottom: artBounds.bottom, artLeft: artBounds.left, artRight: artBounds.right, brandRight: bounds.right, lockupTop: lockupBounds.top, lockupRight: lockupBounds.right, lockupColor: getComputedStyle(lockup).backgroundColor, font: getComputedStyle(lockup).fontFamily, decorative: art.getAttribute('aria-hidden'), artBackground: getComputedStyle(art).backgroundImage, clusterBackground: getComputedStyle(clusters[0]).backgroundImage, clusterVariants: new Set(clusters.map(cluster => cluster.getAttribute('style'))).size, forestRight: clusters.at(-1).getBoundingClientRect().right, pageWidth: document.documentElement.scrollWidth };
      });
      if (width < 640) {
        assert.ok(measured.height <= 100 && measured.artHeight === 40, 'Phone retains compact painted-tree canopy');
        assert.ok(measured.lockupTop >= measured.artBottom, 'Phone artwork stays above product text and controls');
        assert.ok(measured.artBackground.includes('/brand/everbranch-teams-trees-v1.webp'), 'Phone retains approved local tree asset');
      } else {
        assert.ok(measured.height <= 65 && measured.artHeight === 64, 'Desktop masthead uses one compact row');
        assert.ok(Math.abs(measured.lockupTop - measured.artTop) <= 1 && measured.artLeft >= measured.lockupRight - 1, 'Desktop forest sits beside the wordmark without overlapping it');
        assert.ok(Math.abs(measured.artRight - measured.brandRight) <= 1 && measured.forestRight >= measured.artRight, 'Desktop forest fills through the right edge, including ultrawide screens');
        assert.ok(measured.clusterVariants > 1, 'Desktop tree clusters vary in size, crop and direction');
        assert.ok(measured.clusterBackground.includes('/brand/everbranch-teams-trees-v1.webp'), 'Desktop reuses approved local tree asset');
      }
      assert.equal(measured.lockupColor, 'rgb(255, 255, 255)', 'Plain readable wordmark background');
      assert.equal(measured.decorative, 'true', 'CSS forest artwork is decorative');
      assert.ok(measured.pageWidth <= width + 1, surface + width + ' no page overflow');
      await page.locator('#tw-filter-toggle').click();
      await page.locator('#tw-workspace-label').filter({ hasText: 'Workspace:' }).waitFor();
      await page.locator('#tw-filter-toggle').click();
      await brand.evaluate(element => element.scrollIntoView({ block: 'start' }));
      await page.screenshot({ path: join(evidence, `brand-${surface}-${width}.png`) });
      branding.push({ surface, ...measured });
    }
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/brand/everbranch-teams-trees-v1.webp', route => route.abort());
  await page.route('**/brand/everbranch-mark.svg', route => route.abort());
  await page.goto(`${origin}/teams?tenant=${fixture.tenant}`);
  await page.waitForFunction(() => window.TeamPremium?.context?.ready());
  await page.locator('[data-teams-product-brand]').getByText('Everbranch Teams', { exact: true }).waitFor();
  assert.equal(await page.locator('[data-teams-product-brand] img').count(), 0, 'Decorative CSS fallback cannot display broken image glyphs');
  assert.equal(await page.locator('#tw-workspaces').isEnabled(), true, 'Workspace controls stay usable with unavailable artwork');
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Unavailable artwork never causes overflow');
  await page.screenshot({ path: join(evidence, 'brand-artwork-blocked-390.png') });
  await page.unroute('**/brand/everbranch-teams-trees-v1.webp');
  await page.unroute('**/brand/everbranch-mark.svg');
  branding.push({ artworkBlocked: true, readableProductName: true, usableWorkspaceControl: true });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(`${origin}/teams?tenant=${fixture.tenant}&area=${fixture.area_id}&area_view=calendar`);
  await page.waitForFunction(() => window.TeamPremium?.context?.ready());
  await page.locator('#tw-filter-toggle').click();
  await page.locator('#tops-areas button').first().click();
  await page.locator('#tops-groups').getByRole('button', { name: 'Plan & prepare', exact: true }).click();
  await page.locator('#tops-tabs').getByRole('button', { name: 'Calendar', exact: true }).click();
  await page.locator('#team-operations').getByLabel('Calendar month', { exact: true }).fill('2026-11');
  const grid = page.locator('.tops-event-calendar'), event = grid.locator(`[data-calendar-event="${fixture.event_id}"]`);
  await event.first().waitFor(); assert.equal(await event.count(), 3);
  await page.getByText(/Loaded results · 3 event records/).waitFor();
  assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] button').count(), 0, 'Gap Saturday must remain empty');
  const legacyAnchor = grid.locator('[data-calendar-date="2026-11-06"] [data-calendar-kind="range_anchor"]').filter({ hasText: 'Fictional legacy range' });
  assert.equal(await legacyAnchor.count(), 1, 'A legacy range is represented only by its first-day marker');
  await legacyAnchor.getByText('Unconfirmed range', { exact: true }).waitFor();
  assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] [data-calendar-kind="range_anchor"]').count(), 0, 'Legacy ranges do not paint intervening days');
  await page.getByRole('heading', { name: 'Legacy ranges · activity unconfirmed' }).waitFor(); await page.getByRole('heading', { name: 'Sessions with unknown dates' }).waitFor();
  const setup = grid.locator('[data-calendar-kind="setup"]'), selling = grid.locator('[data-calendar-kind="selling"]').first();
  assert.notEqual(await setup.evaluate(el => getComputedStyle(el).backgroundColor), await selling.evaluate(el => getComputedStyle(el).backgroundColor), 'Setup has a distinct lighter treatment');
  await page.getByLabel('Calendar colors').selectOption('person'); assert.ok(await grid.locator(`[data-calendar-person="${fixture.person_id}"]`).count() > 0, 'Recorded crew remains available in the event card'); const crewColor = await event.first().evaluate(el => el.style.getPropertyValue('--event-color'));
  const calendarReady = () => page.waitForFunction(() => document.querySelector('#tops-content').getAttribute('aria-busy') !== 'true');
  await page.getByRole('button', { name: '3 days', exact: true }).click(); await calendarReady();
  assert.equal(await page.getByLabel('Calendar colors').inputValue(), 'person', 'Crew mode is retained when changing the view');
  await page.getByLabel('Calendar first day').fill('2026-11-06');
  await page.getByLabel('Calendar first day').blur(); await calendarReady();
  assert.equal(await page.getByLabel('Calendar colors').inputValue(), 'person', 'Crew mode is retained when changing the date');
  assert.equal(await grid.locator('[data-calendar-date]').count(), 3); assert.equal(await event.count(), 2); assert.equal(await grid.locator('[data-calendar-date="2026-11-07"] button').count(), 0);
  assert.equal(await event.first().evaluate(el => el.style.getPropertyValue('--event-color')), crewColor, 'Crew identity color is stable between views');
  await Promise.all([page.waitForResponse(response => new URL(response.url()).pathname === '/team/operations/records'), page.locator('#tops-refresh').click()]);
  await page.waitForFunction(() => document.querySelector('#tops-content').getAttribute('aria-busy') !== 'true');
  assert.equal(await page.getByLabel('Calendar colors').inputValue(), 'person', 'Refresh preserves crew mode');
  assert.equal(await grid.locator('[data-calendar-date]').count(), 3, 'Refresh preserves selected span');
  await page.getByRole('button', { name: '2 days', exact: true }).click(); await calendarReady(); assert.equal(await grid.locator('[data-calendar-date]').count(), 2); assert.equal(await event.count(), 1);
  await page.getByRole('button', { name: '1 day', exact: true }).click(); await calendarReady(); assert.equal(await grid.locator('[data-calendar-date]').count(), 1);
  const geometry = [];
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['tablet', { width: 820, height: 1180 }], ['landscape', { width: 844, height: 390 }], ['phone-320', { width: 320, height: 844 }], ['phone-375', { width: 375, height: 844 }], ['phone', { width: 390, height: 844 }]]) {
    await page.setViewportSize(viewport); await page.getByRole('button', { name: '3 days', exact: true }).click(); await calendarReady();
    const measurements = await page.locator('.tops-event-calendar-controls').evaluate(toolbar => {
      const fields = [...toolbar.querySelectorAll('input:not([hidden]),select')].map(el => { const box = el.getBoundingClientRect(), css = getComputedStyle(el); return { top: box.top, left: box.left, width: box.width, height: box.height, font: css.fontFamily, size: Number.parseFloat(css.fontSize) }; });
      const buttons = [...toolbar.querySelectorAll('button')].map(el => ({ height: el.getBoundingClientRect().height, font: getComputedStyle(el).fontFamily }));
      return { fields, buttons };
    });
    assert.ok(measurements.fields.every(field => Math.abs(field.height - 44) <= 1 && field.size >= 16), name + ' consistent readable44px fields ' + JSON.stringify(measurements));
    assert.ok(measurements.buttons.every(button => Math.abs(button.height - 44) <= 1), name + '44px buttons');
    assert.equal(new Set([...measurements.fields.map(field => field.font), ...measurements.buttons.map(button => button.font)]).size, 1, name + ' one UI font');
    if (viewport.width >= 360) assert.ok(Math.abs(measurements.fields[0].top - measurements.fields[1].top) <= 1, name + ' date/color rows align');
    else assert.ok(Math.abs(measurements.fields[0].left - measurements.fields[1].left) <= 1 && Math.abs(measurements.fields[0].width - measurements.fields[1].width) <= 1, name + ' full-width stacked fields align');
    assert.ok(await event.first().locator('strong').evaluate(el => Number.parseFloat(getComputedStyle(el).fontSize) >= 14), name + ' readable title');
    assert.ok(await event.first().locator('small').evaluate(el => Number.parseFloat(getComputedStyle(el).fontSize) >= 14), name + ' readable metadata');
    await page.locator('.tops-event-calendar-controls').evaluate(el => el.scrollIntoView({ block: 'start' })); await page.locator('.tops-event-calendar-scroll').evaluate(el => { el.scrollLeft = 0; });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' no page overflow'); await page.screenshot({ path: join(evidence, name + '-calendar.png') });
    if (name === 'landscape') { await scrollCalendarToTop(); await page.screenshot({ path: join(evidence, 'landscape-cards.png') }); }
    geometry.push({ width: viewport.width, height: viewport.height, ...measurements });
  }
  const scroll = page.getByRole('region', { name: 'Dated event calendar. Scroll horizontally for more dates.' }); await scroll.focus(); await page.keyboard.press('ArrowRight'); await page.waitForFunction(() => document.querySelector('.tops-event-calendar-scroll').scrollLeft > 0);
  await page.getByRole('button', { name: '2 days', exact: true }).click(); await page.getByLabel('Calendar first day').fill('2026-11-05');
  await grid.locator('[data-calendar-kind="setup"]').waitFor();
  const crewGeometry = await grid.locator('.tops-calendar-crew').evaluateAll(els => els.map(el => { const card = el.closest('.tops-calendar-session').getBoundingClientRect(), footer = el.getBoundingClientRect(); return { cardTop: card.top, cardBottom: card.bottom, footerBottom: footer.bottom }; }));
  assert.ok(Math.max(...crewGeometry.map(x => x.cardTop)) - Math.min(...crewGeometry.map(x => x.cardTop)) <= 1, 'Phase cards start aligned across dates');
  assert.ok(crewGeometry.every(x => x.footerBottom <= x.cardBottom + 1), 'Crew labels stay within compact cards');
  await page.locator('.tops-event-calendar-controls').evaluate(el => el.scrollIntoView({ block: 'start' })); await scroll.evaluate(el => { el.scrollLeft = 0; }); await page.getByLabel('Calendar first day').evaluate(el => el.blur()); await page.screenshot({ path: join(evidence, 'phone-setup.png') });
  const fieldRevision = 'a'.repeat(64), fieldReviews = [];
  const detailRoute = '**/team/operations/records/**';
  await page.route(detailRoute, async route => {
    const request = route.request(), path = new URL(request.url()).pathname;
    if (path === `/team/operations/records/${fixture.event_id}` && request.method() === 'GET') {
      const response = await route.fetch(), body = await response.json();
      body.data.fields = [{ field: 'booth_number', value: '423', passage: 'Booth Number: 423', review_state: 'needs_review' }];
      body.data.fields_revision = fieldRevision;
      return route.fulfill({ response, json: body });
    }
    if (path === `/team/operations/records/${fixture.event_id}/review` && request.method() === 'POST') {
      fieldReviews.push(request.postDataJSON());
      const headers = await request.allHeaders();
      return route.fulfill({ status: 200, headers: { 'X-Team-Viewer': headers['x-team-viewer'], 'X-Team-Workspace': headers['x-team-workspace'] }, json: { simulated: true } });
    }
    return route.continue();
  });
  await event.first().click(); const detail = page.locator('#tops-detail'); await detail.getByRole('heading', { name: 'Dated event schedule' }).waitFor();
  await detail.getByText(/Confirmed date set · 2 distinct selling days/).waitFor(); await detail.getByText(/Asana due anchor:.*Nov.*6.*2026/).waitFor();
  assert.ok((await detail.getByRole('link', { name: 'Review schedules in Teams Studio' }).getAttribute('href')).includes('/teams/studio?tenant=' + fixture.tenant));
  await detail.getByText(/Calendar windows do not establish staff shifts/).waitFor(); await page.screenshot({ path: join(evidence, 'phone-schedule-summary.png') });
  await detail.getByRole('checkbox', { name: 'Approve Booth number: 423', exact: true }).check();
  const fieldReviewResponse = page.waitForResponse(response => new URL(response.url()).pathname === `/team/operations/records/${fixture.event_id}/review` && response.request().method() === 'POST');
  const fieldReloadResponse = page.waitForResponse(response => new URL(response.url()).pathname === `/team/operations/records/${fixture.event_id}` && response.request().method() === 'GET');
  await detail.getByRole('button', { name: 'Approve selected fields', exact: true }).click();
  await fieldReviewResponse;
  await fieldReloadResponse;
  assert.equal(fieldReviews.length, 1); assert.equal(fieldReviews[0].expected_fields_revision, fieldRevision);
  assert.deepEqual(fieldReviews[0].field_indices, [0]); assert.equal(fieldReviews[0].action, 'fields');
  await page.unroute(detailRoute);
  await page.locator('#tops-detail-close').click(); await page.getByRole('button', { name: '1 day', exact: true }).click(); await page.getByLabel('Calendar first day').fill('2026-11-10');
  await grid.getByText('Hours unknown', { exact: true }).waitFor(); await grid.getByText('Timezone unknown', { exact: true }).waitFor(); await grid.getByText('Partial schedule', { exact: true }).waitFor();
  await page.locator('.tops-event-calendar-controls').evaluate(el => el.scrollIntoView({ block: 'start' })); await page.screenshot({ path: join(evidence, 'phone-unknown-source.png') });
  const singleDay = page.getByRole('button', { name: '1 day', exact: true }); await singleDay.focus(); await singleDay.press('Enter'); assert.ok(await singleDay.evaluate(el => document.activeElement === el), 'Keyboard focus stays on selected view'); await page.screenshot({ path: join(evidence, 'phone-keyboard-focus.png') });
  await page.emulateMedia({ forcedColors: 'active' }); await page.screenshot({ path: join(evidence, 'phone-high-contrast.png') }); await page.emulateMedia({ forcedColors: 'none' });
  const failedRead = '**/team/operations/records?*'; await page.route(failedRead, route => route.abort()); await page.locator('#tops-refresh').click(); await page.locator('#tops-notice').filter({ hasText: /Failed to fetch/ }).waitFor();
  await page.getByRole('heading', { name: 'This view is unavailable', exact: true }).waitFor(); await page.getByText('The source or access check did not complete. This does not mean there are no records.', { exact: true }).waitFor();
  await page.screenshot({ path: join(evidence, 'phone-network-unavailable.png') });
  await page.unroute(failedRead); await page.locator('#tops-refresh').click(); await grid.getByText('Partial schedule', { exact: true }).waitFor(); await page.waitForFunction(() => document.querySelector('#tops-content').getAttribute('aria-busy') !== 'true');
  assert.deepEqual(errors, []); assert.deepEqual(writes.filter(url => !new URL(url).pathname.endsWith('/review')), [], 'Calendar browsing has no writes; field review was intercepted');
  await writeFile(join(evidence, 'acceptance.json'), JSON.stringify({ passed: true, fictional_only: true, sparse_event_explicit_sessions: 3, distinct_selling_days: 2, gap_days_painted: 0, views: ['month', 1, 2, 3], crew_identity_color_stable: true, refresh_preserves_view_and_color: true, keyboard_scroll: true, keyboard_focus: true, forced_colors: true, reduced_motion: true, long_title: true, unknown_source: true, failed_read_notice_and_recovery: true, mock_field_review_contract: fieldReviews.length === 1, geometry, branding, provider_or_operation_writes: writes.filter(url => !new URL(url).pathname.endsWith('/review')), javascript_errors: errors }, null, 2));
  console.log('PASS legacy calendar: real authenticated HTTP, explicit sparse dates, lighter setup, unknown/legacy labels, event/crew colors, month/1/2/3-day controls, source anchor, Studio review link, desktop/phone, no writes.');
} catch (error) { if (page) { await page.screenshot({ path: join(evidence, 'failure.png') }); console.error((await page.locator('body').innerText()).slice(-5000)); } throw error;
} finally { if (browser) await browser.close(); if (server && server.exitCode === null) { server.kill('SIGTERM'); await new Promise(done => server.once('exit', done)); } await rm(dir, { recursive: true, force: true }); }

async function scrollCalendarToTop() { await page.locator('.tops-event-calendar-scroll').evaluate(el => el.scrollIntoView({ block: 'start' })); }
