import { createServer } from 'node:http';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

// Actual UI module/CSS, isolated synthetic transport. No business/provider
// requests, OS clipboard access, or production uploads are used by this fixture.
const base = resolve(), evidence = resolve('test-results/team-event-updates'); await mkdir(evidence, { recursive: true });
const fixture = `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/resources/css/team-event-updates.css"><style>body{margin:0;font:14px system-ui;color:#243d2d;background:#f4f7ef}#dialog{display:grid;grid-template-rows:auto minmax(0,1fr) auto;height:100dvh;max-width:1080px;margin:auto;background:white}header{padding:18px;border-bottom:1px solid #dde5d7}#body{overflow:auto;padding:20px;min-width:0}#footer{min-width:0}button{font:inherit}*{box-sizing:border-box}</style></head><body><main id="dialog"><header>Fictional event · Updates</header><section id="body"><div style="height:80px">Event essentials</div><div id="feed"></div></section><footer id="footer"></footer></main><script type="module">
import { installEventUpdates,hasEventUpdateDrafts } from '/resources/js/team/event-updates.js';
const history=[];let active=0,upload=0;
window.fixture={calls:[],failPostOnce:false,blocked:false,holdPost:false,holdLoad:false,posts:[],pending:[],capability:{},enabled:0,history};
const caps={can_post:true,can_upload:true,max_file_bytes:10485760,allowed_mimes:['image/png','image/jpeg','application/pdf','text/plain'],upload:{begin_url:'/team/events/event-a/updates/files',chunk_url_template:'/team/files/{upload}/chunks/{index}',complete_url_template:'/team/files/{upload}/complete',cancel_url_template:'/team/files/{upload}'}};
async function api(path,options={}){
 const method=options.method||'GET';fixture.calls.push({path,method,query:structuredClone(options.query),raw:options.raw||false,body:options.body instanceof ArrayBuffer?{bytes:options.body.byteLength}:structuredClone(options.body)});
 if(fixture.blocked)throw Object.assign(new Error('Original account must be verified'),{status:401});
 if(path.endsWith('/updates')&&method==='GET'){if(fixture.holdLoad)await new Promise(r=>fixture.releaseLoad=r);if(options.query?.cursor)fixture.hasMore=false;return {...caps,...fixture.capability,pending:fixture.pending,items:[{id:'welcome',body:'<img src=x onerror=alert(1)>',author_name:'Fictional Crew',created_at:'2026-11-08T12:00:00Z'},...history],has_more:!!fixture.hasMore,next_cursor:fixture.hasMore?'fixture-more':null};}
 if(path.endsWith('/updates/files'))return {id:'upload-'+(++upload),status:'uploading',chunk_bytes:262144,received_bytes:0,received_chunks:[]};
 if(path.includes('/chunks/'))return {id:path.split('/')[2],status:'uploading',chunk_bytes:262144,received_bytes:options.body.byteLength,received_chunks:[0]};
 if(path.endsWith('/complete'))return {id:path.split('/')[2],status:'ready',downloadable:true};
 if(method==='DELETE')return {status:'cancelled'};
 if(path.endsWith('/reconcile')){const id=path.split('/')[4],prior=fixture.pending.find(item=>item.id===id);const override=fixture.reconcileReplies?.shift();if(override){if(['reviewed','succeeded'].includes(override.status))fixture.pending=[];else fixture.pending=fixture.pending.map(item=>item.id===id?{...item,...override}:item);return {id,...override};}fixture.pending=[];return {id,status:options.body?.acknowledge?'reviewed':'succeeded',file_ids:prior?.file_ids||[]};}
 if(path.endsWith('/updates')&&method==='POST'){
  fixture.posts.push(structuredClone(options.body));if(fixture.holdPost)await new Promise(r=>fixture.releasePost=r);
  if(fixture.failPostOnce){fixture.failPostOnce=false;throw Object.assign(new Error('Response interrupted; retry the same update'),{status:503});}
  if(fixture.unknownNext){fixture.unknownNext=false;return {id:'attempt-files',status:'unknown',can_acknowledge:true,review_kind:'source',source_review_url:'https://app.asana.com/0/0/12345'};}
  if(!history.some(x=>x.id===options.body.request_id))history.push({id:options.body.request_id,body:options.body.body,author_name:'Fictional Owner',created_at:'2026-11-08T13:00:00Z'});
  return {id:options.body.request_id,status:'succeeded'};
 }
 throw new Error('Unexpected fixture request '+path);
}
window.mount=async(scope='tenant1|actor1|workspace1')=>{window.ui?.dispose();const ticket=++active;window.ui=installEventUpdates(document.querySelector('#feed'),{api,record:{id:'event-a'},scope,current:()=>active===ticket,composerHost:document.querySelector('#footer'),dropTarget:document.querySelector('#dialog'),onEnableUpdates:()=>{fixture.enabled++;}});await ui.ready;};
window.dirty=hasEventUpdateDrafts;window.ready=mount();
</script></body></html>`;
const server = createServer(async (request, response) => {
  if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(fixture); return; }
  if (!['/resources/js/team/event-updates.js', '/resources/css/team-event-updates.css'].includes(request.url)) { response.writeHead(404); response.end(); return; }
  response.setHeader('Content-Type', request.url.endsWith('.js') ? 'text/javascript' : 'text/css'); response.end(await readFile(base + request.url));
});
await new Promise(done => server.listen(0, '127.0.0.1', done)); const origin = 'http://127.0.0.1:' + server.address().port;
const results = []; let browser;
try {
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) {
    browser = await engine.launch(); const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await page.goto(origin); await page.evaluate(() => ready);
    const input = page.getByRole('textbox', { name: 'Write an update', exact: true });
    const openSourceReview = async () => {
      const link = page.getByRole('link', { name: 'Review the original in Asana', exact: true });
      await link.evaluate(element => element.addEventListener('click', event => event.preventDefault(), { once: true })); await link.click();
    };
    assert.equal(await page.getByRole('button', { name: 'Enable updates', exact: true }).isVisible(), false, 'Native Teams never requires an Asana connection');
    assert.equal(await page.locator('.event-updates-feed img').count(), 0, 'Comment markup is rendered as text');
    await input.fill('Ready for setup');
    await page.locator('input[type=file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aB3kAAAAASUVORK5CYII=', 'base64') });
    assert.equal(await page.locator('.event-update-files img').count(), 1, 'Photo selection previews automatically');
    await page.evaluate(() => { const data = new DataTransfer(); data.items.add(new File(['%PDF-1.4\nFictional map'], 'venue.pdf', { type: 'application/pdf' })); document.querySelector('#dialog').dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: data })); });
    assert.equal(await page.locator('.event-update-file').count(), 2, 'File drop attaches without a type selector');
    await page.evaluate(() => { const data = new DataTransfer(); data.items.add(new File(['synthetic-png-file'], 'screenshot.png', { type: 'image/png' })); document.querySelector('textarea').dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data })); });
    assert.equal(await page.locator('.event-update-files img').count(), 2, 'Pasted screenshot previews automatically');
    await page.getByRole('button', { name: 'Remove screenshot.png', exact: true }).click();
    assert.equal(await page.locator('.event-update-file').count(), 2, 'Selected screenshot can be removed');
    await page.evaluate(() => fixture.failPostOnce = true); await page.getByRole('button', { name: 'Post update', exact: true }).click();
    await page.getByRole('button', { name: 'Retry same update', exact: true }).waitFor();
    assert.equal(await input.getAttribute('readonly'), '', 'Uncertain intent cannot be edited into a duplicate');
    await page.getByRole('button', { name: 'Retry same update', exact: true }).click();
    await page.locator('.event-update-body').filter({ hasText: 'Ready for setup' }).waitFor();
    const requests = await page.evaluate(() => fixture.posts);
    assert.equal(requests.length, 2); assert.deepEqual(requests[0], requests[1], 'Lost response retries the exact request and attachment IDs');
    assert.equal(await input.inputValue(), '', 'Confirmed post clears the composer');
    assert.equal(await page.evaluate(() => dirty()), false, 'Confirmed post releases its draft guard');
    assert.ok((await page.evaluate(() => fixture.calls)).filter(call => call.method === 'PUT').every(call => call.raw && call.body.bytes > 0), 'File chunks use raw bytes');

    await page.evaluate(async () => { fixture.pending=[{id:'attempt-recovery',request_id:'retained-request',body:'A saved unconfirmed update',file_ids:[],status:'unknown',notice:'An earlier update needs review.'}]; await ui.refresh(); });
    const beforeRecovery = await page.evaluate(() => fixture.posts.length);
    await page.getByRole('button', { name: 'Resume update', exact: true }).click();
    assert.equal(await input.inputValue(), 'A saved unconfirmed update', 'Server-retained intent can be explicitly resumed');
    assert.equal(await page.evaluate(() => fixture.posts.length), beforeRecovery, 'Resuming never automatically posts');
    await page.locator('.event-update-tools').getByRole('button', { name: 'Check update', exact: true }).click();
    await page.getByRole('button', { name: 'Post update', exact: true }).waitFor();
    assert.equal(await input.inputValue(), '', 'Checking an accepted retained intent releases its frozen draft');

    await page.evaluate(async () => { fixture.pending=[{id:'attempt-review',request_id:'review-request',body:'Original update to review',file_ids:[],status:'unknown',can_acknowledge:true,review_kind:'source',source_review_url:'https://app.asana.com/0/0/12345'}]; await ui.refresh(); });
    await page.getByRole('button', { name: 'Resume update', exact: true }).click();
    const confirmReview = page.getByRole('button', { name: 'Confirm original reviewed', exact: true });
    assert.equal(await confirmReview.isDisabled(), true, 'Acknowledgement waits for an explicit source-review step');
    await page.getByRole('link', { name: 'Review the original in Asana', exact: true }).evaluate(link => link.addEventListener('click', event => event.preventDefault(), { once: true }));
    await page.getByRole('link', { name: 'Review the original in Asana', exact: true }).click();
    const beforeAcknowledgement = await page.evaluate(() => fixture.posts.length); await confirmReview.click();
    await page.waitForFunction(() => !document.querySelector('textarea').readOnly);
    assert.equal(await input.inputValue(), 'Original update to review', 'Explicit review preserves the human text for editing');
    assert.equal(await page.evaluate(() => fixture.posts.length), beforeAcknowledgement, 'Acknowledgement never posts another copy');
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.path.endsWith('/reconcile') && call.body?.acknowledge).at(-1).body.review_kind), 'source', 'Source review explicitly identifies its kind');

    await input.fill('');
    await page.evaluate(async () => { fixture.pending=[{id:'attempt-unsent',request_id:'unsent-request',body:'',file_ids:['saved-photo-a','saved-photo-b'],status:'prepared',can_acknowledge:true,review_kind:'unsent'}]; await ui.refresh(); });
    await page.getByRole('button', { name: 'Resume update', exact: true }).first().click();
    const keepDraft = page.getByRole('button', { name: 'Keep as editable draft', exact: true });
    assert.equal(await keepDraft.isEnabled(), true, 'A native unsent draft requires no Asana link');
    assert.equal(await page.getByRole('link', { name: 'Review the original in Asana', exact: true }).count(), 0, 'Native recovery does not request an Asana account');
    const beforeUnsent = await page.evaluate(() => ({ posts:fixture.posts.length, deletes:fixture.calls.filter(call=>call.method==='DELETE').length, chunks:fixture.calls.filter(call=>call.method==='PUT').length }));
    await keepDraft.click(); await page.waitForFunction(() => !document.querySelector('textarea').readOnly);
    assert.equal(await page.locator('.event-update-file').count(), 2, 'Server-retained file IDs survive review without browser File objects');
    assert.equal(await page.evaluate(() => fixture.posts.length), beforeUnsent.posts, 'Reviewing an unsent draft never posts it');
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.path.endsWith('/reconcile') && call.body?.acknowledge).at(-1).body.review_kind), 'unsent', 'Unsent review explicitly identifies its proof');
    await page.getByRole('button', { name: 'Remove saved attachment 1', exact: true }).click();
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.method === 'DELETE').length), beforeUnsent.deletes, 'Removing a saved file from the new draft preserves original evidence');
    assert.equal(await page.getByRole('button', { name: 'Post update', exact: true }).isEnabled(), true, 'A retained file-only draft can be posted explicitly');
    await page.getByRole('button', { name: 'Post update', exact: true }).click();
    await page.waitForFunction(() => !dirty());
    const retainedPost = await page.evaluate(() => fixture.posts.at(-1));
    assert.deepEqual(retainedPost.file_ids, ['saved-photo-b']); assert.equal(retainedPost.body, '');
    assert.notEqual(retainedPost.request_id, 'unsent-request', 'A new intent is created only after deliberate editing and Post');
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.method === 'PUT').length), beforeUnsent.chunks, 'An existing scanned attachment is not uploaded again');

    await page.evaluate(async () => { fixture.hasMore=true;await ui.refresh(); });
    await page.getByRole('button', { name: 'More updates', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('.event-updates-older').offsetParent);
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.method==='GET' && call.query?.cursor).at(-1).query.cursor), 'fixture-more', 'More updates follows the server continuation');

    await page.evaluate(async () => { fixture.pending=[{id:'attempt-race',request_id:'race-request',body:'Retain during classification changes',file_ids:[],status:'prepared',can_acknowledge:true,review_kind:'unsent'}];await ui.refresh(); });
    await page.getByRole('button', { name: 'Resume update', exact: true }).first().click();
    const racePosts = await page.evaluate(() => fixture.posts.length);
    await page.evaluate(() => fixture.reconcileReplies=[{status:'unknown',can_acknowledge:true,review_kind:'source',source_review_url:'https://app.asana.com/0/0/12345',notice:'The original may have posted.'}]);
    await page.getByRole('button', { name: 'Keep as editable draft', exact: true }).click();
    await page.getByRole('link', { name: 'Review the original in Asana', exact: true }).waitFor();
    assert.equal(await input.getAttribute('readonly'), '', 'A claimed/unknown response does not release the original intent');
    assert.equal(await input.inputValue(), 'Retain during classification changes');
    assert.equal(await confirmReview.isDisabled(), true, 'Unsent-to-source reclassification requires opening the source first');
    await openSourceReview(); assert.equal(await confirmReview.isEnabled(), true);
    await page.evaluate(() => fixture.reconcileReplies=[{status:'prepared',can_acknowledge:true,review_kind:'unsent'}]);
    await page.locator('.event-update-send').click(); await page.getByRole('button', { name: 'Keep as editable draft', exact: true }).waitFor();
    await page.evaluate(() => fixture.reconcileReplies=[{status:'unknown',can_acknowledge:true,review_kind:'source',source_review_url:'https://app.asana.com/0/0/12345'}]);
    await page.locator('.event-update-check').click(); await confirmReview.waitFor();
    assert.equal(await confirmReview.isDisabled(), true, 'A prior source-open cannot survive an intervening review-kind change');
    await openSourceReview();
    await page.evaluate(() => fixture.reconcileReplies=[{id:'attempt-race-other',status:'unknown',can_acknowledge:true,review_kind:'source',source_review_url:'https://app.asana.com/0/0/12346'}]);
    await page.locator('.event-update-send').click(); await confirmReview.waitFor();
    assert.equal(await confirmReview.isDisabled(), true, 'Source-open proof is bound to the exact attempt ID as well as its review kind');
    await openSourceReview();
    await page.evaluate(() => fixture.reconcileReplies=[{status:'unknown',can_acknowledge:true,review_kind:'source',source_review_url:'https://app.asana.com/0/0/12346'}]);
    await confirmReview.click(); await page.waitForFunction(() => !document.querySelector('.event-update-composer').textContent.includes('Saving…'));
    assert.equal(await input.getAttribute('readonly'), '', 'Acknowledgement that did not return reviewed cannot clear the intent');
    assert.equal(await input.inputValue(), 'Retain during classification changes');
    await confirmReview.click(); await page.waitForFunction(() => !document.querySelector('textarea').readOnly);
    assert.equal(await input.inputValue(), 'Retain during classification changes', 'Only an actual reviewed reply returns the retained body to editing');
    assert.equal(await page.evaluate(() => fixture.posts.length), racePosts, 'Every recovery/classification action remains free of provider-post retries');

    await input.fill(''); await page.locator('input[type=file]').setInputFiles({ name: 'proof.txt', mimeType: 'text/plain', buffer: Buffer.from('Original event update evidence') });
    await page.evaluate(() => fixture.unknownNext = true); await page.getByRole('button', { name: 'Post update', exact: true }).click();
    await page.getByRole('link', { name: 'Review the original in Asana', exact: true }).evaluate(link => link.addEventListener('click', event => event.preventDefault(), { once: true }));
    await page.getByRole('link', { name: 'Review the original in Asana', exact: true }).click(); await page.getByRole('button', { name: 'Confirm original reviewed', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('textarea').readOnly);
    const cancelsBeforeRemoval = await page.evaluate(() => fixture.calls.filter(call => call.method === 'DELETE').length);
    await page.getByRole('button', { name: 'Remove proof.txt', exact: true }).click();
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.method === 'DELETE').length), cancelsBeforeRemoval, 'Removing a reviewed attachment from a new draft preserves original evidence');

    await input.fill('Retain this private draft'); await page.evaluate(() => mount('tenant2|actor2|workspace2'));
    assert.equal(await input.inputValue(), '', 'Another actor and tenant never see the retained draft');
    await page.evaluate(() => mount()); assert.equal(await input.inputValue(), 'Retain this private draft', 'Original actor can resume after a fresh admission read');
    await page.evaluate(() => { fixture.blocked = true; document.dispatchEvent(new Event('team:access-lost')); });
    assert.equal(await input.inputValue(), '', 'Access loss hides retained text');
    assert.equal(await page.locator('.event-updates-feed .event-update-entry').count(), 0, 'Access loss removes readable update history');
    await page.evaluate(() => { fixture.blocked = false; return mount(); });
    assert.equal(await input.inputValue(), 'Retain this private draft', 'Verified original scope recovers sealed text');

    await input.fill('Closing during a save'); await page.evaluate(() => fixture.holdPost = true);
    await page.getByRole('button', { name: 'Post update', exact: true }).click();
    await page.waitForFunction(() => typeof fixture.releasePost === 'function');
    await page.evaluate(() => mount());
    await page.evaluate(() => { fixture.holdPost = false; fixture.releasePost(); });
    await page.locator('.event-update-body').filter({ hasText: 'Closing during a save' }).waitFor();
    assert.equal(await input.inputValue(), '', 'An accepted in-flight post updates the reopened composer');
    assert.equal(await page.getByRole('button', { name: 'Post update', exact: true }).isDisabled(), true, 'Reopened empty composer is not stuck saving');

    await page.setViewportSize({ width: 390, height: 844 }); await input.fill('A phone update');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Phone has no horizontal page overflow');
    const sticky = await page.locator('.event-update-composer').boundingBox(); assert.ok(sticky.y + sticky.height <= 844 && sticky.width <= 390, 'Composer remains at the visible dialog bottom');
    await page.screenshot({ path: evidence + '/composer-phone-' + name + '.png' });
    await page.evaluate(async () => { fixture.capability={can_post:false,can_upload:false,requires_update_connection:false};await ui.refresh(); });
    assert.equal(await page.getByRole('button', { name: 'Enable updates', exact: true }).isVisible(), false, 'A read-only viewer is not offered scope elevation');
    await page.evaluate(async () => { fixture.capability={can_post:false,can_upload:true,requires_update_connection:true};await ui.refresh(); });
    const beforeEnable = await page.evaluate(() => fixture.calls.filter(call => call.method !== 'GET').length);
    await page.getByRole('button', { name: 'Enable updates', exact: true }).click();
    assert.equal(await page.evaluate(() => fixture.enabled), 1, 'Enable updates invokes only the explicit connection-navigation callback');
    assert.equal(await page.evaluate(() => fixture.calls.filter(call => call.method !== 'GET').length), beforeEnable, 'Enable updates never starts OAuth or writes a provider grant');
    await page.evaluate(() => { fixture.capability={};return mount(); });
    assert.equal(await input.inputValue(), 'A phone update', 'Enabling navigation retains the original unsent draft');
    assert.deepEqual(errors, []); results.push({ browser: name, automatic_previews: true, paste_drop: true, exact_retry: true, actor_scope: true, sealed_recovery: true, unsent_recovery: true, retained_saved_files: true, review_identity_and_kind: true, unknown_race_retained: true, reopened_save: true, mobile: true });
    await browser.close(); browser = null;
  }
  await writeFile(evidence + '/results.json', JSON.stringify(results, null, 2)); console.log(JSON.stringify(results));
} finally { if (browser) await browser.close(); await new Promise(done => server.close(done)); }
