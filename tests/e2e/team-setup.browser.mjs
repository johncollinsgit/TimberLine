import { createServer } from 'node:http';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { createServer as createVite } from 'vite';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

const evidence = resolve('test-results/team-setup'); await mkdir(evidence, { recursive: true });
const vite = await createVite({ root: resolve(), configFile: false, appType: 'custom', server: { middlewareMode: true, hmr: false } });
const html = `<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font:14px system-ui;color:#243d2e;background:#f4f7ef;margin:0}dialog{width:min(760px,calc(100vw - 32px));max-height:calc(100dvh - 24px);box-sizing:border-box;border:1px solid #d4dfcd;border-radius:18px;padding:24px}dialog::backdrop{background:#213c2655}header{display:flex;align-items:center;justify-content:space-between;gap:20px}header h2{font-size:21px}button{cursor:pointer}#trigger{margin:24px}*{box-sizing:border-box}</style></head><body><button id="trigger">Teams setup</button><dialog><header><h2>Set up Everbranch Teams</h2><button id="close">Close setup</button></header><div id="body"></div></dialog><script type="module">
import {createTeamsSetup,hasTeamsSetupDrafts} from '/resources/js/team/setup.js';
window.fixture={calls:[],receipts:new Map(),failDraftOnce:false,failFinishOnce:false,created:0,completed:0,opens:0,status:{can_manage:true,version:0,required:true,suggested:true,completed_at:null,draft:null,defaults:{home_base:{address:null,timezone:null},event_defaults:{teardown_minutes:60,arrival_buffer_minutes:0}},choices:{areas:[],members:[{id:'1',name:'Fictional Owner'},{id:'2',name:'Fictional Crew'}],profiles:[{key:'events',name:'Events'},{key:'custom',name:'Custom'}],cost_templates:['skip','blank']},connections_optional:true}};
const clone=x=>structuredClone(x);const dialog=document.querySelector('dialog');
async function api(path,options={}){fixture.calls.push({path,method:options.method||'GET',body:clone(options.body)});if(path==='/setup')return clone(fixture.status);const input=options.body;
 const existing=fixture.receipts.get(input.request_id);if(existing){if(JSON.stringify(existing.input)!==JSON.stringify(input))throw Object.assign(new Error('Request changed'),{status:409});return {...clone(existing.result),replayed:true};}
 if(input.expected_version!==fixture.status.version)throw Object.assign(new Error('Setup changed on another device'),{status:409});
 if(path==='/setup/draft'){fixture.status.version++;fixture.status.draft={step:input.step,values:clone(input.values)};const result=clone(fixture.status);fixture.receipts.set(input.request_id,{input:clone(input),result});if(fixture.failDraftOnce){fixture.failDraftOnce=false;throw Object.assign(new Error('Save response interrupted'),{status:503});}return result;}
 if(path==='/setup/finish'){const values=fixture.status.draft.values;if(!values.home_base.address||!values.home_base.timezone||!values.area.name)throw Object.assign(new Error('Complete the required fields'),{status:422});fixture.status.version++;fixture.status.required=false;fixture.status.suggested=false;fixture.status.completed_at='2026-11-08T15:00:00Z';fixture.status.defaults={...fixture.status.defaults,home_base:clone(values.home_base),area_id:'area-created',member_ids:clone(values.member_ids)};fixture.status.choices.areas=[{id:'area-created',name:values.area.name,profile:values.area.profile,version:1}];fixture.status.draft=null;fixture.created++;const result={...clone(fixture.status),area:clone(fixture.status.choices.areas[0]),cost_policy:{status:'draft'},connection_next:values.connection};fixture.receipts.set(input.request_id,{input:clone(input),result});if(fixture.failFinishOnce){fixture.failFinishOnce=false;throw Object.assign(new Error('Finish response interrupted'),{status:503});}return result;}
 throw new Error('Unexpected fixture request '+path);
}
window.owner=scope=>{window.setup?.dispose();window.setup=createTeamsSetup({api,scope,trigger:document.querySelector('#trigger'),openDialog:()=>{fixture.opens++;const body=document.querySelector('#body');body.replaceChildren();if(!dialog.open)dialog.showModal();return body;},closeDialog:()=>dialog.close(),onComplete:result=>{fixture.completed++;fixture.lastResult=result;}});};
document.querySelector('#close').onclick=()=>dialog.close();owner('tenant1|owner1');window.dirty=hasTeamsSetupDrafts;window.ready=setup.inspect();
</script></body></html>`;
const server = createServer((request, response) => { if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); } else vite.middlewares(request, response, () => { response.writeHead(404); response.end(); }); });
await new Promise(done => server.listen(0, '127.0.0.1', done)); const origin = 'http://127.0.0.1:' + server.address().port;
const results = []; let browser;
try {
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch(); const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } }); page.setDefaultTimeout(10000);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await page.goto(origin); await page.evaluate(() => ready); await page.getByRole('heading', { name: 'Home base', exact: true }).waitFor();
    await page.getByLabel('Home base address', { exact: true }).fill('123 Fictional Studio Road'); await page.getByLabel('Time zone', { exact: true }).fill('America/New_York');
    await page.getByRole('button', { name: 'Next', exact: true }).click(); await page.getByRole('heading', { name: 'Work area & people', exact: true }).waitFor();
    await page.getByLabel('Work area name', { exact: true }).fill('Fictional Markets'); await page.getByLabel('Fictional Crew', { exact: true }).check();
    await page.evaluate(() => fixture.failDraftOnce = true); await page.getByRole('button', { name: 'Save & close', exact: true }).click();
    await page.getByRole('button', { name: 'Retry same save', exact: true }).waitFor();
    assert.equal(await page.evaluate(() => dirty()), true, 'Interrupted draft stays protected');
    await page.getByRole('button', { name: 'Close setup', exact: true }).click(); await page.evaluate(() => setup.open());
    await page.getByRole('button', { name: 'Retry same save', exact: true }).click();
    await page.getByRole('button', { name: 'Next', exact: true }).waitFor();
    const draftCalls = await page.evaluate(() => fixture.calls.filter(call => call.path === '/setup/draft'));
    assert.deepEqual(draftCalls[1].body, draftCalls[2].body, 'Close and resume retries the exact interrupted draft');
    assert.equal(await page.getByLabel('Work area name', { exact: true }).inputValue(), 'Fictional Markets');
    await page.getByRole('button', { name: 'Next', exact: true }).click(); await page.getByRole('heading', { name: 'Defaults & costs', exact: true }).waitFor();
    assert.equal(await page.getByLabel('Booth teardown · minutes', { exact: true }).inputValue(), '60');
    await page.getByLabel('Cost policy', { exact: true }).selectOption('blank');
    await page.getByLabel('Policy name · optional', { exact: true }).fill('Fictional event costs'); await page.getByLabel('Currency', { exact: true }).fill('USD'); await page.getByLabel('Effective date', { exact: true }).fill('2026-11-08');
    await page.getByLabel('Hourly pay', { exact: true }).fill('19.25'); await page.getByLabel('Mileage per mile', { exact: true }).fill('0.725');
    const beforeInvalid = await page.evaluate(() => fixture.calls.length); await page.getByRole('button', { name: 'Next', exact: true }).click(); assert.equal(await page.evaluate(() => fixture.calls.length), beforeInvalid, 'Fractional cents are not silently rounded');
    await page.getByLabel('Mileage per mile', { exact: true }).fill('0.72'); await page.getByLabel('Per diem per day', { exact: true }).fill('24.00'); await page.getByLabel('Commission %', { exact: true }).fill('4.25');
    await page.getByRole('button', { name: 'Next', exact: true }).click(); await page.getByRole('heading', { name: 'Review', exact: true }).waitFor();
    const savedPolicy = await page.evaluate(() => fixture.status.draft.values.cost_policy); assert.equal(savedPolicy.hourly_rate_minor, 1925); assert.equal(savedPolicy.mileage_rate_minor, 72); assert.equal(savedPolicy.per_diem_minor, 2400); assert.equal(savedPolicy.commission_rate_bps, 425);
    await page.screenshot({ path: evidence + '/setup-review-' + name + '.png' });
    await page.evaluate(() => fixture.failFinishOnce = true); await page.getByRole('button', { name: 'Finish setup', exact: true }).click();
    await page.getByRole('button', { name: 'Retry same setup', exact: true }).click(); await page.waitForFunction(() => fixture.completed === 1);
    const finished = await page.evaluate(() => ({ created: fixture.created, calls: fixture.calls.filter(call => call.path === '/setup/finish'), result: fixture.lastResult }));
    assert.equal(finished.created, 1, 'Interrupted finish creates only one work area'); assert.deepEqual(finished.calls[0].body, finished.calls[1].body, 'Finish retries preserve exact command identity'); assert.equal(finished.result.cost_policy.status, 'draft'); assert.equal(finished.result.connection_next, 'later');
    await page.evaluate(() => setup.inspect()); assert.equal(await page.locator('dialog').isVisible(), false, 'Configured workspace setup never auto-opens');

    await page.evaluate(() => setup.open()); await page.getByLabel('Home base address', { exact: true }).fill('456 Local Draft Lane');
    await page.evaluate(() => fixture.status.version++); await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Review latest saved setup', exact: true }).click();
    assert.equal(await page.getByLabel('Home base address', { exact: true }).inputValue(), '456 Local Draft Lane', 'Version conflict retains the owner’s entries');
    await page.getByRole('button', { name: 'Next', exact: true }).click(); await page.getByRole('heading', { name: 'Work area & people', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Back', exact: true }).click(); await page.getByRole('heading', { name: 'Home base', exact: true }).waitFor();
    await page.getByLabel('Home base address', { exact: true }).fill('Private unsaved address'); await page.evaluate(() => document.dispatchEvent(new Event('team:access-lost')));
    assert.equal(await page.getByLabel('Home base address', { exact: true }).count(), 0, 'Access loss hides retained owner fields');
    await page.evaluate(() => { document.querySelector('dialog').close(); owner('tenant2|owner2'); return setup.inspect(); });
    assert.equal(await page.locator('dialog').isVisible(), false, 'Second configured scope stays nonblocking'); await page.evaluate(() => setup.open());
    assert.notEqual(await page.getByLabel('Home base address', { exact: true }).inputValue(), 'Private unsaved address', 'Another actor scope cannot see an unsaved owner draft');
    await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Phone setup fits without horizontal overflow');
    await page.screenshot({ path: evidence + '/setup-phone-' + name + '.png' });
    await page.evaluate(() => { fixture.status.can_manage=false; return setup.inspect(); });
    assert.equal(await page.getByLabel('Home base address', { exact: true }).count(), 0, 'Revoked setup permission retires an already open form');
    assert.equal(await page.locator('#trigger').isVisible(), false, 'Owner-only setup link disappears when permission is revoked');
    const existingEditor = await page.evaluate(async () => { fixture.status.can_manage=true;fixture.status.required=true;document.querySelector('#body').textContent='Fictional unsaved editor';owner('tenant3|owner3');const before=fixture.opens;await setup.inspect();return {before,after:fixture.opens,text:document.querySelector('#body').textContent}; });
    assert.equal(existingEditor.after, existingEditor.before, 'First-time inspection never interrupts an already-open editor');
    assert.equal(existingEditor.text, 'Fictional unsaved editor', 'Automatic setup preserves existing editor contents');
    assert.deepEqual(errors, []); results.push({ browser: name, first_run: true, nonblocking_existing: true, exact_draft_retry: true, exact_finish_retry: true, rates: true, conflict: true, actor_scope: true, mobile: true }); await browser.close(); browser = null;
  }
  await writeFile(evidence + '/results.json', JSON.stringify(results, null, 2)); console.log(JSON.stringify(results));
} finally { if (browser) await browser.close(); await new Promise(done => server.close(done)); await vite.close(); }
