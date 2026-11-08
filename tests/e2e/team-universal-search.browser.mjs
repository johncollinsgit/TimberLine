import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done)); const port = probe.address().port; await new Promise(done => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), 'codex-team-search-')), origin = `http://127.0.0.1:${port}`;
const evidence = resolve(process.env.TEAM_SEARCH_EVIDENCE || 'test-results/team-universal-search'); await mkdir(evidence, { recursive: true }); await writeFile(join(dir, 'db.sqlite'), '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', APP_CONFIG_CACHE: join(dir, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'db.sqlite'), DB_URL: '', DATABASE_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'null', BCRYPT_ROUNDS: '4', TEAM_WORK_ENABLED: 'true', TEAM_WORK_PREMIUM_ENABLED: 'true', TEAM_OPERATIONS_ENABLED: 'true', TEAM_WORK_ASANA_ENABLED: 'false', TEAM_WORK_SEARCH_ENABLED: 'false', TEAM_WORK_SEARCH_INDEX_ENABLED: 'false', EVERBRANCH_AI_STANDARD_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', TELESCOPE_ENABLED: 'false', PULSE_ENABLED: 'false' };
let server, browser, page; const checks = [];
try {
  await exec('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec('php', ['scripts/everbranch-team/universal-search-browser-fixture.php'], { cwd: base, env })).stdout); env.TEAM_WORK_TENANT_ALLOWLIST = fixture.allowlist;
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], { cwd: base, env, stdio: 'ignore' });
  let ready = false; for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) { ready = true; break; } } catch {} await new Promise(done => setTimeout(done, 100)); } assert.ok(ready);
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch(); const context = await browser.newContext({ reducedMotion: 'reduce' });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const csrf = (await (await context.request.get(origin + '/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1]; assert.ok(csrf);
    assert.ok((await context.request.post(origin + '/login', { form: { email: 'owner@fixture.invalid', password: 'Fictional-local-only-2026!', _token: csrf }, headers: { Accept: 'application/json' } })).ok());
    page = await context.newPage(); page.setDefaultTimeout(12000); await page.clock.setFixedTime(new Date('2026-11-07T12:00:00Z')); const errors = [], writes = [], navigationReads = [];
    page.on('pageerror', error => errors.push(error.message)); page.on('request', request => { const url = new URL(request.url()); if (url.pathname.startsWith('/team/') && !['GET', 'HEAD'].includes(request.method())) writes.push(request.url()); if (url.pathname === '/team/operations/records' || /^\/team\/areas\/[^/]+\/members$/.test(url.pathname)) navigationReads.push(request.url()); });
    for (const width of [320, 390, 1440]) {
      await page.setViewportSize({ width, height: 900 }); await page.goto(`${origin}/teams?tenant=${fixture.tenant}`);
      await page.waitForFunction(() => window.TeamSearchBridge?.available());
      const input = page.getByRole('combobox', { name: 'Search everything you can access in this workspace' });
      assert.equal(await input.count(), 1, 'One main search'); assert.equal(await page.locator('#eb-teams-search').count(), 0, 'No duplicate legacy search header');
      assert.ok((await input.boundingBox()).height >= 44); await page.keyboard.press('Control+k'); assert.ok(await input.evaluate(node => node === document.activeElement));
      await input.fill('seasonal'); const result = page.locator('[data-team-universal-search] .eb-search-option').filter({ hasText: 'Fictional seasonal makers' }); await result.waitFor();
      const filters = page.locator('[data-team-universal-search] .eb-search-filters');
      const filterLink = page.locator('[data-team-universal-search] .eb-search-filter-toggle');
      assert.ok(await filters.isHidden(), 'Search filters stay hidden until requested');
      assert.equal(await filterLink.getAttribute('aria-expanded'), 'false');
      await filterLink.click(); assert.ok(await filters.isVisible());
      await page.getByRole('combobox', { name: 'All work', exact: true }).selectOption('active');
      await result.waitFor(); assert.equal(await filterLink.innerText(), 'Filters (1)', 'Hidden active filters remain discoverable');
      await page.getByRole('combobox', { name: 'All work', exact: true }).selectOption(''); await result.waitFor();
      await filterLink.click(); assert.ok(await filters.isHidden());
      assert.ok(['rgb(214, 229, 215)', 'rgb(225, 237, 223)'].includes(await input.evaluate(node => getComputedStyle(node).backgroundColor)), 'Search uses a solid green background in focused and resting states');
      if (width === 1440) {
        // At 1440 the sidebar, tabs and controls can leave less than 560px with
        // Linux fonts. Verify expansion where there is actually room to grow.
        await page.setViewportSize({ width: 1920, height: 900 });
        assert.ok((await input.boundingBox()).width > 800, 'Wide desktop search expands beyond its old 560px maximum');
        await page.setViewportSize({ width, height: 900 });
      }
      assert.ok(await page.locator('.teams-search-coverage').isVisible(), 'Saved-source coverage remains visible');
      const panel = await page.locator('[data-team-universal-search] .eb-search-panel').boundingBox(); assert.ok(panel.x >= -1 && panel.x + panel.width <= width + 1, 'Search stays in viewport');
      await page.screenshot({ path: join(evidence, `${name}-${width}-search.png`) });
      await input.press('Escape'); assert.ok(await page.locator('[data-team-universal-search] .eb-search-panel').isHidden());
      if (!await page.locator('#tw-workspaces').isVisible()) await page.getByRole('button', { name: 'Controls', exact: true }).click();
      await page.locator('#tw-workspaces').click(); const dialog = page.locator('#tw-workspace-dialog'); await dialog.waitFor({ state: 'visible' });
      await dialog.getByRole('button', { name: /Fictional Long Workspace Name/ }).waitFor();
      const metrics = await dialog.evaluate(node => ({ width: node.clientWidth, scroll: node.scrollWidth, controls: [...node.querySelectorAll('input,button')].filter(el => el.getBoundingClientRect().height).map(el => ({ height: el.getBoundingClientRect().height, width: el.getBoundingClientRect().width })) }));
      assert.ok(metrics.scroll <= metrics.width + 1, 'Long workspace names do not overflow'); assert.ok(metrics.controls.every(control => control.height >= 44), 'Chooser controls stay touch-sized');
      await dialog.getByRole('button', { name: 'Stay here' }).focus(); await page.screenshot({ path: join(evidence, `${name}-${width}-chooser.png`) });
      await dialog.getByRole('button', { name: 'Stay here' }).click();
      if (width === 390) {
        for (const layout of ['board', 'list', 'calendar']) {
          if (await page.locator('#tw-filter-toggle').getAttribute('aria-expanded') === 'true') await page.locator('#tw-filter-toggle').click();
          await page.locator(`[data-team-layout="${layout}"]`).click();
          await page.locator('#tw-list ' + ({ board: '.teams-event-board', list: '.teams-event-list', calendar: '.tops-event-calendar' }[layout])).waitFor();
          await page.locator('#tops-content:not([aria-busy])').waitFor();
          const month = page.locator('#tw-list input[aria-label="Calendar month"]');
          if (layout === 'calendar') {
            const changed = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/operations/records' && url.searchParams.get('calendar_from') === '2031-03-01' && url.searchParams.get('event_scope') === 'window' && response.ok(); });
            await month.fill('2031-03'); await month.blur(); await changed; await page.locator('#tops-content:not([aria-busy])').waitFor();
            await page.locator('[data-calendar-date="2031-03-01"]').waitFor();
          }
          const before = { url: page.url(), month: layout === 'calendar' ? await month.inputValue() : null, reads: navigationReads.length };
          await input.fill('seasonal'); await result.waitFor();
          const revalidated = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/search/all' && url.searchParams.get('ref') === 'operation:' + fixture.event_id && response.ok(); });
          const opened = page.waitForResponse(response => new URL(response.url()).pathname === '/team/operations/records/' + fixture.event_id && response.ok());
          await result.click(); await revalidated; const admitted = await (await opened).json();
          assert.equal(admitted.record?.id || admitted.id, fixture.event_id, 'Selection opens its actual authorized event after revalidation');
          await page.locator('#tops-detail').getByRole('heading', { name: 'Fictional seasonal makers and home goods weekend market', exact: true }).waitFor();
          await page.locator('#tops-detail[open] .event-detail-notes').getByText('Event notes', { exact: true }).click();
          await page.locator('#tops-detail[open]').getByText('Copper lantern setup', { exact: true }).waitFor();
          await page.locator('#tops-detail-close').click();
          assert.equal(page.url(), before.url, `${layout}: search details does not navigate to an area overview`);
          assert.equal(navigationReads.length, before.reads, `${layout}: same-area selection only reads the authorized record`);
          assert.ok(await page.locator('#tw-list #team-operations.tops-inline-calendar').isVisible(), `${layout}: inline event surface remains mounted`);
          assert.equal(await page.locator(`[data-team-layout="${layout}"]`).getAttribute('aria-selected'), 'true', `${layout}: selected tab remains unchanged`);
          if (layout === 'calendar') assert.equal(await month.inputValue(), before.month, `${layout}: selected date window remains unchanged`);
          assert.ok(await page.locator('.teams-work-toolbar').isVisible(), `${layout}: main toolbar remains visible`);
          assert.ok(await page.locator('#tw-controls-panel').isHidden(), `${layout}: Controls remain collapsed`);
          await page.locator('[data-team-universal-search] .eb-search-panel').waitFor({ state: 'hidden' });
          assert.ok(await page.locator(`[data-team-layout="${layout}"]`).evaluate(node => node === document.activeElement), `${layout}: focus returns to the selected main view`);
          if (layout === 'calendar') await page.screenshot({ path: join(evidence, `${name}-390-calendar-after-search.png`) });
          checks.push(`${name} ${layout}: search→details→close retains inline URL, toolbar, collapsed Controls, selected tab/month and focus`);
          await input.press('Escape');
        }
        // Real Teams results stay interactive while only the slower app phase is held.
        const deferred = label => { let started, release, finish; const ready = new Promise((done, reject) => { const timer = setTimeout(() => reject(new Error('Expected deferred app search did not start: ' + label)), 12000); started = () => { clearTimeout(timer); done(); }; }), gate = new Promise(done => { release = done; }), finished = new Promise(done => { finish = done; }); ready.catch(() => {}); return { ready, started, gate, release, finished, finish, mode: 'merge' }; };
        const fakeApp = (id, title) => ({ id: 'app:fictional-' + id, type: 'request', title, context: 'Fictional app search', source: 'Everbranch', target: { kind: 'app', url: `/settings?tenant=${fixture.tenant_id}` } });
        const workspaceTitle = 'Fictional Evergreen Events and Seasonal Makers Collective Teams';
        let heldApps = null, paginateApps = false, initialTeamTitles = [];
        const stagedApps = async route => {
          const url = new URL(route.request().url());
          if (url.searchParams.get('q') !== 'seasonal' || url.searchParams.has('ref')) return route.continue();
          const pending = url.searchParams.get('stage') === 'apps' ? heldApps : null;
          if (!pending && !(paginateApps && url.searchParams.has('cursor') && !url.searchParams.has('stage'))) return route.continue();
          if (pending) { heldApps = null; pending.started(); await pending.gate; }
          try {
            const response = await route.fetch(), body = await response.json();
            if (['fail', 'expired'].includes(pending?.mode)) return await route.fulfill({ response, status: pending.mode === 'expired' ? 422 : 503, json: { message: pending.mode === 'expired' ? 'Fictional expired search cursor.' : 'Fictional app search outage.' } });
            const app = pending?.mode === 'stale' ? fakeApp('stale', 'Fictional stale app result') : fakeApp(pending ? 'first' : 'next', pending ? 'Fictional first app result' : 'Fictional next app result');
            if (pending) {
              assert.equal(url.searchParams.get('limit'), '4', 'App phase fills the remaining first-page slots');
              return await route.fulfill({ response, json: { ...body, items: [app], next_cursor: url.searchParams.get('cursor'), facets: { ...body.facets, type: [{ id: 'request', title: 'Request' }] } } });
            }
            paginateApps = false;
            assert.equal(body.items.length, 1, 'The retained cursor admits only the known workspace navigation result');
            assert.equal(body.items[0].title, workspaceTitle); assert.equal(body.items[0].type, 'navigation');
            assert.equal(body.items[0].target.kind, 'app');
            const destination = new URL(body.items[0].target.url, origin);
            assert.equal(destination.pathname, '/teams'); assert.equal(destination.searchParams.get('tenant'), fixture.tenant_id);
            assert.ok(body.items.every(item => !initialTeamTitles.includes(item.title)), 'The retained server cursor does not repeat an initial Team result');
            await route.fulfill({ response, json: { ...body, items: [...body.items, app], next_cursor: null } });
          } catch (error) { if (!/closed|disposed|canceled|cancelled|intercepted|Invalid InterceptionId/i.test(error.message)) throw error; } finally { pending?.finish(); }
        };
        await page.route('**/team/search/all?**', stagedApps);
        const options = page.locator('[data-team-universal-search] .eb-search-option');
        const stagedStatus = page.locator('[data-team-universal-search] .eb-search-status');
        console.log(name + ': progressive early open'); let pending = deferred('early open'); heldApps = pending;
        await input.fill('seasonal'); await pending.ready; await result.waitFor();
        assert.equal(await options.count(), 4, 'Four real Team rows appear before the app response');
        assert.match(await stagedStatus.innerText(), /Searching other apps/);
        const earlyOpen = page.waitForResponse(response => new URL(response.url()).pathname === '/team/operations/records/' + fixture.event_id && response.ok());
        await result.click(); await earlyOpen;
        await page.locator('#tops-detail[open] .event-detail-notes').getByText('Event notes', { exact: true }).click();
        await page.locator('#tops-detail[open]').getByText('Copper lantern setup', { exact: true }).waitFor();
        pending.release(); await pending.finished; await page.locator('#tops-detail-close').click();
        assert.ok(await page.locator('[data-team-universal-search] .eb-search-panel').isHidden(), 'Opening a Team result retires the unfinished app search');
        checks.push(`${name}: Team result opens before delayed app response`);

        console.log(name + ': progressive merge'); pending = deferred('merge'); heldApps = pending;
        await input.fill('seasonal'); await pending.ready; await result.waitFor(); initialTeamTitles = await options.locator('strong').allTextContents(); await input.press('ArrowDown');
        const selectedTitle = await page.locator('[data-team-universal-search] .eb-search-option[aria-selected="true"] strong').innerText();
        const initialCoverage = await page.locator('.teams-search-coverage').innerText();
        pending.release(); await options.filter({ hasText: 'Fictional first app result' }).waitFor();
        assert.equal(await options.count(), 5, 'App phase merges once with the four initial Team rows');
        assert.equal(await page.locator('[data-team-universal-search] .eb-search-option[aria-selected="true"] strong').innerText(), selectedTitle, 'Final merge preserves the selected row identity');
        assert.ok(await input.evaluate(node => node === document.activeElement), 'Final merge preserves keyboard focus');
        assert.equal(await page.locator('.teams-search-coverage').innerText(), initialCoverage, 'Initial source coverage survives the app phase');
        paginateApps = true; await page.getByRole('button', { name: 'View all results', exact: true }).click();
        await options.filter({ hasText: 'Fictional next app result' }).waitFor();
        const titles = await options.locator('strong').allTextContents(); assert.equal(titles.length, new Set(titles).size, 'Pagination never duplicates initial Team or App rows');
        assert.equal(titles.length, 7, 'The four initial Teams, known workspace and two intercepted app rows appear exactly once');
        assert.deepEqual(titles.slice().sort(), [...initialTeamTitles, workspaceTitle, 'Fictional first app result', 'Fictional next app result'].sort());
        assert.ok(await input.evaluate(node => node === document.activeElement), 'Completing pagination returns focus from the disappearing continuation button to search');
        checks.push(`${name}: staged merge preserves selection/focus/coverage and paginates without duplicates`);

        console.log(name + ': progressive stale query'); pending = deferred('stale query'); pending.mode = 'stale'; heldApps = pending;
        await input.fill('seasonal'); await pending.ready; await result.waitFor();
        await input.fill('aspen'); await options.filter({ hasText: 'Fictional Aspen regional market' }).waitFor();
        pending.release(); await pending.finished;
        await page.evaluate(() => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done))));
        assert.equal(await options.filter({ hasText: 'Fictional stale app result' }).count(), 0, 'Late app response from the retired query is ignored');
        assert.equal(await result.count(), 0, 'Retired Team rows are not restored by a late app response');
        checks.push(`${name}: stale app phase cannot overwrite a newer query`);

        console.log(name + ': progressive outage'); pending = deferred('outage'); pending.mode = 'fail'; heldApps = pending;
        await input.fill('seasonal'); await pending.ready; await result.waitFor(); pending.release();
        await stagedStatus.filter({ hasText: 'Other apps could not load.' }).waitFor();
        assert.equal(await options.count(), 4, 'App failure keeps the already authorized Team rows');
        const retry = page.waitForResponse(response => { const url = new URL(response.url()); return url.pathname === '/team/search/all' && url.searchParams.get('q') === 'seasonal' && url.searchParams.has('cursor') && !url.searchParams.has('stage'); });
        await page.getByRole('button', { name: 'View all results', exact: true }).click(); await retry;
        await options.filter({ hasText: workspaceTitle }).waitFor(); assert.equal(await options.count(), 5, 'Retry adds only the known workspace result to the four retained Teams');
        assert.deepEqual((await options.locator('strong').allTextContents()).sort(), [...initialTeamTitles, workspaceTitle].sort());
        assert.doesNotMatch(await stagedStatus.innerText(), /Other apps could not load/);
        checks.push(`${name}: failed app phase keeps Team rows and retries its original cursor`);
        console.log(name + ': progressive expired cursor'); pending = deferred('expired cursor'); pending.mode = 'expired'; heldApps = pending;
        await input.fill('seasonal'); await pending.ready; await result.waitFor(); pending.release();
        await stagedStatus.filter({ hasText: 'Search again to refresh these results.' }).waitFor();
        assert.equal(await options.count(), 4, 'Expired app cursor keeps the already admitted Team rows');
        assert.ok(await page.locator('[data-team-universal-search] .eb-search-more').isHidden(), 'An expired cursor cannot enter a continuation retry loop');
        checks.push(`${name}: expired app cursor preserves Teams and asks for a fresh search`);
        await page.unroute('**/team/search/all?**', stagedApps);

        await input.fill('aspen');
        const otherResult = page.locator('[data-team-universal-search] .eb-search-option').filter({ hasText: 'Fictional Aspen regional market' }); await otherResult.waitFor();
        const otherOpened = page.waitForResponse(response => new URL(response.url()).pathname === '/team/operations/records/' + fixture.other_event_id && response.ok());
        await otherResult.click(); await otherOpened;
        await page.locator('#tops-detail').getByRole('heading', { name: 'Fictional Aspen regional market', exact: true }).waitFor();
        assert.equal(new URL(page.url()).searchParams.get('area'), fixture.other_area_id, 'Cross-area selection keeps the existing authorized area navigation');
        assert.ok(await page.locator('#team-operations').evaluate(node => !node.classList.contains('tops-inline-calendar')), 'Cross-area selection is not falsely kept in the original inline area');
        await page.locator('#tops-detail-close').click();
        checks.push(`${name}: cross-area search opens its authorized area and record`);
      }
      checks.push(`${name} ${width}: real saved event search, single entry, keyboard/focus, responsive search, long-name chooser`);
    }
    assert.deepEqual(errors, []); assert.deepEqual(writes, []); await context.close(); await browser.close(); browser = null;
  }
  await writeFile(join(evidence, 'results.json'), JSON.stringify({ checks, provider_calls: 0, business_writes: 0 }, null, 2)); console.log(JSON.stringify({ passed: checks.length, checks, evidence }));
} catch (error) { if (page) console.log(JSON.stringify(await page.evaluate(() => ({query:document.querySelector('#teams-universal-query')?.value,focus:document.activeElement?.id,status:document.querySelector('.eb-search-status')?.textContent,panelHidden:document.querySelector('.eb-search-panel')?.hidden,dialogOpen:document.querySelector('#tops-detail')?.open})),null,2)); if (page) await page.screenshot({ path: join(evidence, 'failure.png') }).catch(() => {}); throw error; }
finally { await browser?.close(); server?.kill('SIGTERM'); await rm(dir, { recursive: true, force: true }); }
