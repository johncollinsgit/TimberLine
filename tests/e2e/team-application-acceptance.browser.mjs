import { createServer } from 'node:http';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

// Real Operations renderer and Blade DOM; fictional fetch responses, no backend/database.
const base = resolve(), evidence = resolve(process.env.TEAM_APPLICATION_ACCEPTANCE_EVIDENCE || 'test-results/team-application-acceptance');
await mkdir(evidence, { recursive: true });
const markup = (await readFile(resolve('resources/views/team/operations.blade.php'), 'utf8'))
  .replace(/@if[\s\S]*?@endif/g, '').replace(/{{[\s\S]*?}}/g, '');
const source = { connection_id: 'fictional-source' };
const record = (id, data, extra = {}) => ({ id, title: id, status: 'accepted', kind: 'event', data, ...extra });
const projection = (status, label, basis = 'asana_tags') => ({ status, label, basis });
const items = [
  record('Source Accepted tag', { calendar_acceptance_status: 'accepted', event_acceptance: projection('accepted', 'Accepted'), reviewed_fields: { acceptance_status: 'no', application_status: 'Submitted', payment_status: 'Paid' } }, { source }),
  record('Source tag removed', { calendar_acceptance_status: null, event_acceptance: projection(null, 'Needs confirmation'), reviewed_fields: { acceptance_status: 'yes' } }, { source }),
  record('Source conflicting tags', { calendar_acceptance_status: null, event_acceptance: projection(null, 'Needs confirmation'), reviewed_fields: { acceptance_status: 'yes' } }, { source }),
  record('Source admission unavailable', { calendar_acceptance_status: null, event_acceptance: projection(null, 'Needs confirmation', 'source_unverified'), reviewed_fields: { acceptance_status: 'yes' } }, { source }),
  record('Native confirmed', { acceptance_status: 'Confirmed', event_acceptance: projection('Confirmed', 'Confirmed', 'native') }, { status: 'pending' }),
  record('Task stage only', {}, { status: 'accepted' }),
  record('Legacy reviewed wording', { reviewed_fields: { acceptance_status: 'Yes (via email on 1/17/25)' } }, { source }),
  record('Unreviewed source candidate', { acceptance_status: 'yes', fields: [{ field: 'acceptance_status', value: 'yes', review_state: 'needs_review' }] }, { source }),
  record('Compact cleared acceptance', { calendar_acceptance_status: null, reviewed_fields: { acceptance_status: 'yes' } }, { source }),
  record('Tag acceptance independent of stage', { event_acceptance: projection('accepted', 'Accepted') }, { source, status: 'pending' }),
];
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/resources/css/team-operations.css"><style>body{font:14px system-ui;margin:0;background:#fff}main{padding:16px;max-width:1280px;margin:auto}*{box-sizing:border-box}[hidden]{display:none!important}</style></head><body><main id="native-root" data-workspace-id="fictional-workspace">${markup}</main><script type="module">
import { installOperations } from '/resources/js/team/operations.js';
window.fixture={items:${JSON.stringify(items)},calls:[]};
const root=document.querySelector('#team-operations');Object.assign(root.dataset,{url:'/team',tenant:'fictional',user:'1',admin:'0'});
window.fetch=async(input,options={})=>{const url=new URL(input,location.origin);fixture.calls.push({path:url.pathname,method:options.method||'GET'});if(!['GET','HEAD'].includes(options.method||'GET'))throw new Error('Fixture rejects writes');let data;
if(url.pathname==='/team/areas')data={items:[{id:'fixture-area',name:'Fictional events',profile:'events',views:['overview','applications'],capabilities:{write:false},settings:{}}]};
else if(url.pathname==='/team/areas/fixture-area/members')data={items:[]};
else if(url.pathname==='/team/operations/records')data={items:structuredClone(fixture.items),count:fixture.items.length,has_more:false};
else throw new Error('Unexpected fixture request '+url.pathname);
return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json','X-Team-Viewer':'1','X-Team-Workspace':'fictional-workspace'}});};
installOperations(document.querySelector('#native-root'));
</script></body></html>`;
const server = createServer(async (request, response) => {
  try {
    let path = new URL(request.url, 'http://fixture').pathname;
    if (path === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
    if (/^\/resources\/js\/team\/[a-z0-9.-]+$/.test(path)) {
      if (!/\.(?:js|mjs)$/.test(path)) path += '.js';
      response.setHeader('Content-Type', 'text/javascript');
      response.end((await readFile(base + path, 'utf8')).replace(/^import ['"][^'"]+\.css['"];?$/gm, '')); return;
    }
    if (/^\/resources\/css\/team-[a-z-]+\.css$/.test(path)) { response.setHeader('Content-Type', 'text/css'); response.end(await readFile(base + path)); return; }
    response.writeHead(404); response.end();
  } catch { response.writeHead(404); response.end(); }
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const origin = 'http://127.0.0.1:' + server.address().port;
let browser;
const results = [];
try {
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch();
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], external = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
      if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
      return route.continue();
    });
    await page.goto(origin + '/?area=fixture-area&area_view=applications');
    const row = title => page.locator('#tops-content tbody tr').filter({ has: page.getByRole('button', { name: title, exact: true }) });
    await row('Source Accepted tag').waitFor();
    assert.equal(await row('Source Accepted tag').locator('td').nth(2).innerText(), 'Accepted');
    assert.equal(await row('Source Accepted tag').locator('td').nth(1).innerText(), 'Submitted');
    assert.equal(await row('Source Accepted tag').locator('td').nth(3).innerText(), 'Paid');
    for (const title of ['Source tag removed', 'Source conflicting tags', 'Source admission unavailable', 'Task stage only', 'Unreviewed source candidate', 'Compact cleared acceptance']) {
      assert.equal(await row(title).locator('td').nth(2).innerText(), 'Needs confirmation', title);
    }
    assert.equal(await row('Native confirmed').locator('td').nth(2).innerText(), 'Confirmed');
    assert.equal(await row('Legacy reviewed wording').locator('td').nth(2).innerText(), 'Yes (via email on 1/17/25)');
    assert.equal(await row('Tag acceptance independent of stage').locator('td').nth(2).innerText(), 'Accepted');
    await page.evaluate(() => { fixture.items[0].data.event_acceptance = { status: null, label: 'Needs confirmation', basis: 'asana_tags' }; fixture.items[0].data.calendar_acceptance_status = null; fixture.items[0].data.reviewed_fields.acceptance_status = 'yes'; });
    await page.locator('#tops-refresh').click();
    await page.waitForFunction(() => document.querySelector('#tops-content').getAttribute('aria-busy') !== 'true');
    assert.equal(await row('Source Accepted tag').locator('td').nth(2).innerText(), 'Needs confirmation', 'Fresh tag removal overrides an old affirmative note');
    assert.equal(await row('Source Accepted tag').locator('td').nth(1).innerText(), 'Submitted');
    assert.equal(await row('Source Accepted tag').locator('td').nth(3).innerText(), 'Paid');
    await page.screenshot({ path: evidence + '/' + name + '-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await row('Source Accepted tag').scrollIntoViewIfNeeded();
    await page.screenshot({ path: evidence + '/' + name + '-mobile.png', fullPage: true });
    assert.deepEqual(errors, []); assert.deepEqual(external, []);
    assert.equal(await page.evaluate(() => fixture.calls.some(call => !['GET', 'HEAD'].includes(call.method))), false);
    results.push({ engine: name, source_projection: true, removed_conflicting_unavailable_unconfirmed: true, native_legacy_preserved: true, task_stage_independent: true, refresh_removal: true, writes: 0, errors: 0 });
    await browser.close(); browser = null;
  }
  await writeFile(evidence + '/results.json', JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results));
} finally { await browser?.close(); server.close(); }
