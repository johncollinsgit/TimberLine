import assert from 'node:assert/strict';
import { chromium, webkit } from 'playwright';
import { createServer } from 'node:http';
import { mkdtemp, readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join, extname } from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
const run = promisify(execFile), base = process.cwd();
const dir = await mkdtemp(join(tmpdir(), 'codex-teams-ask-'));
const output = resolve(process.env.TEAMS_ASK_OUTPUT || 'test-results/team-assistant');
await mkdir(output, { recursive: true });
let server;
try {
  await run('php', ['vendor/bin/pest', '-c', 'phpunit.team.xml', 'tests/Feature/Team/TeamAskEntryTest.php', '--filter=approved Teams owner receives', '--compact'], { cwd: base, env: { ...process.env, TEAMS_ASK_BROWSER_HTML: join(dir, 'approved.html') }, maxBuffer: 2e6 });
  let html = await readFile(join(dir, 'approved.html'), 'utf8');
  const renderedOrigin = new URL(html.match(/data-base-url="([^"]+)"/)[1]).origin;
  const manifest = JSON.parse(await readFile(join(base, 'public/build/manifest.json'), 'utf8'));
  const entry = manifest['resources/js/team/app.js'];
  // The guarded Blade fixture disables Vite and Livewire transport. Exercise the real compiled Teams entry.
  html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  html = html.replace('</head>', (entry.css || []).map(path => `<link rel="stylesheet" href="/build/${path}">`).join('') + '</head>');
  html = html.replace('</body>', `<script type="module" src="/build/${entry.file}"></script></body>`);
  server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://fixture.invalid');
    if (url.pathname === '/teams') { response.setHeader('Content-Type', 'text/html'); response.end(html.replaceAll(renderedOrigin, origin)); return; }
    const file = resolve(base, 'public', url.pathname.slice(1));
    if (!file.startsWith(resolve(base, 'public') + '/')) { response.writeHead(404).end(); return; }
    try { const content = await readFile(file); response.setHeader('Content-Type', { '.js': 'text/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' }[extname(file)] || 'application/octet-stream'); response.end(content); }
    catch { response.writeHead(404).end(); }
  });
  await new Promise(done => server.listen(0, '127.0.0.1', done));
  const origin = `http://127.0.0.1:${server.address().port}`, reports = [];
  for (const [name, launcher] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await launcher.launch({ headless: true });
    try {
      for (const width of [320, 390, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        const errors = [], unexpected = [], requests = []; let messages = [], conversation = null;
        page.on('pageerror', error => errors.push(error.message));
        try {
        await page.route('**/*', async route => {
          const request = route.request(), url = new URL(request.url()), path = url.pathname;
          if (url.origin !== origin) { unexpected.push(path); return route.abort(); }
          let payload;
          if (path.startsWith('/ai-branch/')) {
            requests.push({ path, method: request.method(), tenant: url.searchParams.get('tenant') });
            assert.ok(url.searchParams.get('tenant')?.startsWith('fixture-'));
            if (request.method() === 'POST') assert.ok(request.headers()['x-csrf-token']);
            if (path === '/ai-branch/assistant') payload = { version: 1, workspace: { id: 1, name: 'Fictional Team' }, commercial: null, conversation: conversation ? { id: conversation, messages, pending: false } : null, conversations: [], memories: [], actions: [], usage: {}, context: { page: { title: 'Workspace' } }, actions_available: false };
            else if (path === '/ai-branch/conversations') { conversation = 44; payload = { conversation_id: conversation }; }
            else if (path === '/ai-branch/conversations/44/messages') {
              const body = request.postDataJSON(); assert.equal(body.origin.kind, 'workspace'); assert.match(body.request_id, /^[a-f\d-]{36}$/i);
              messages = [{ role: 'user', text: body.question, uuid: body.request_id }, { role: 'assistant', text: 'A permitted fictional workspace answer.', uuid: body.request_id, sources: [] }]; payload = messages[1];
            } else throw new Error(`Unexpected assistant API ${path}`);
          } else if (path.startsWith('/team/')) {
            assert.equal(request.method(), 'GET', 'Teams boot must not mutate work or send AI');
            if (path === '/team/data') {
              const user = await page.locator('#team-work-app').getAttribute('data-user');
              payload = { viewer: { id: user, name: 'Fictional Owner' }, tasks: [], projects: [], members: [], has_more: false, total: 0, can_create_task: false, can_create_project: false };
            } else if (path === '/team/preferences') payload = { preferences: {}, views: [] };
            else if (path === '/team/inbox') payload = { items: [], unread: 0 };
            else if (path === '/team/areas') payload = { items: [], can_manage: false };
            else { unexpected.push(path); payload = {}; }
          } else return route.continue();
          return route.fulfill({ json: payload, headers: { 'X-Team-Viewer': request.headers()['x-team-viewer'] || '1', 'X-Team-Workspace': request.headers()['x-team-workspace'] || '1' } });
        });
        await page.goto(origin + '/teams');
        const ask = page.locator('[data-team-assistant-entry] .everbranch-assistant-launcher');
        await ask.waitFor({ state: 'attached' });
        assert.equal(requests.length, 0, 'Mounting only loads JavaScript, not AI context/provider');
        if (width < 801) await page.locator('[data-teams-menu]').click();
        assert.ok((await ask.boundingBox()).height >= 44);
        await ask.click(); const dialog = page.getByRole('dialog', { name: 'Ask Everbranch', exact: true });
        await dialog.getByText('Teams event details and Asana notes are not included', { exact: false }).waitFor();
        await dialog.getByRole('textbox', { name: 'Question', exact: true }).fill('Help me review my permitted workspace.');
        await page.waitForFunction(() => !document.querySelector('.everbranch-assistant-panel form button.assistant-primary')?.disabled, null, { timeout: 5000 }).catch(async error => {
          console.error(JSON.stringify({ requests, unexpected, errors, text: await dialog.innerText() })); throw error;
        });
        await dialog.getByRole('button', { name: 'Ask', exact: true }).click();
        await dialog.getByText('A permitted fictional workspace answer.', { exact: true }).waitFor();
        assert.equal(requests.filter(item => item.method === 'POST').length, 2);
        assert.equal(new URL(page.url()).pathname, '/teams'); assert.equal(await page.locator('iframe').count(), 0);
        const dimensions = await dialog.evaluate(node => ({ width: node.getBoundingClientRect().width, overflow: node.scrollWidth > node.clientWidth + 1 }));
        assert.ok(dimensions.width <= width && !dimensions.overflow);
        await page.screenshot({ path: join(output, `${name}-${width}-answer.png`) });
        await dialog.evaluate(node => { node.scrollTop = 0; });
        await page.screenshot({ path: join(output, `${name}-${width}.png`) });
        await dialog.getByRole('button', { name: 'Close assistant', exact: true }).click();
        assert.equal(await page.getByRole('dialog', { name: 'Ask Everbranch', exact: true }).isVisible(), false);
        assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
        reports.push({ engine: name, width, passed: true, question_posts: 1, provider_calls: 0, dimensions });
        await page.close();
        } catch (error) {
          await page.screenshot({ path: join(output, `${name}-${width}-failure.png`), fullPage: true }).catch(() => {});
          await writeFile(join(output, `${name}-${width}-failure.json`), JSON.stringify({ error: error.message, errors, unexpected, requests }, null, 2));
          throw error;
        }
      }
    } finally { await browser.close(); }
  }
  await writeFile(join(output, 'report.json'), JSON.stringify({ cases: reports, real_provider_calls: 0, production_mutations: 0 }, null, 2));
  console.log(`Legacy in-place Ask: ${reports.length} browser cases passed, zero real provider calls.`);
} finally {
  if (server) await new Promise(done => server.close(done));
  await rm(dir, { recursive: true, force: true });
}
