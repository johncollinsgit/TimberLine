/** Intercepted synthetic HTTP and scan DTOs only. No listening server, real scanner, camera, provider or customer data. */
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFile, mkdir, writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {chromium, webkit} from 'playwright';

const read = path => readFile(new URL('../../' + path, import.meta.url), 'utf8');
const scripts = await Promise.all([
    'resources/js/enterprise/reviewer/shared-workspace.js',
    'resources/js/enterprise/reviewer/workspace-upload-client.js',
    'resources/js/enterprise/attachment-gallery.js',
    'resources/js/enterprise/reviewer/workspace-image-client.js',
    'resources/js/enterprise/reviewer/workspace-file-ui.js',
].map(read));
const partial = await read('resources/views/enterprise/partials/reviewer-workspace-files.blade.php');
const gallery = await read('resources/views/enterprise/attachment-gallery.blade.php');
const oldHarness = await read('tests/browser/enterprise-attachment-gallery.smoke.mjs');
const png = Buffer.from(oldHarness.match(/const png = Buffer\.from\('([^']+)'/)[1], 'base64');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const id = n => String(n).padStart(8, '0') + '-0000-4000-8000-000000000001';
const W = id(1), A = id(2), T = id(3), OTHER = id(4);
const origin = 'https://reviewer-files.test';
const binding = {version_hash: 'a'.repeat(64), source_hash: 'b'.repeat(64), build_id: 'SYNTHETIC-FILES-ONLY'};
const expiry = '2099-01-01T00:00:00Z';
const boot = {
    reviewerWorkspace: {binding, endpoints: {projection: '/private/workspace/projection'}},
    reviewerAttachments: {enabled: true, schema_version: 1, workspace_id: W, browser_context: 'c'.repeat(64), binding, expires_at: expiry, csrf: 'SYNTHETIC-NOT-A-CREDENTIAL', endpoints: Object.fromEntries(['show', 'begin', 'status', 'chunk', 'complete', 'cancel'].map(a => [a, '/private/workspace/files/' + a]))},
    reviewerImages: {enabled: true, schema_version: 1, workspace_id: W, browser_context: 'c'.repeat(64), binding, expires_at: expiry, csrf: 'SYNTHETIC-NOT-A-CREDENTIAL', endpoints: Object.fromEntries(['metadata', 'thumbnail', 'preview'].map(a => [a, '/private/workspace/images/' + a]))},
};
const target = {kind: 'ticket', id: T};
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px system-ui;margin:0}#ticket{width:min(90vw,1100px);height:85vh}#detail{height:70vh;overflow:auto}.spacer{height:350px}.button{padding:8px}[hidden]{display:none!important}#ticket[data-placement=center]{width:min(94vw,1250px)}</style>
<button id="open">Open ticket</button><dialog id="ticket" data-placement="side"><h2>Synthetic ticket</h2><button id="placement">Center</button><div id="detail"><textarea id="draft">Unsent fictional comment</textarea><div class="spacer"></div>${partial}<div class="spacer"></div></div></dialog>${gallery}
<script>${scripts.join('\n')}
const boot=${JSON.stringify(boot)}, target=${JSON.stringify(target)}, listeners=new Set();let currentTarget=target;
let state={workspace_id:'${W}',browser_context:'${'c'.repeat(64)}',persona:'demo_manager',area_ids:['${A}'],person_id:null,revision:1,workspace_digest:'${'d'.repeat(64)}',phase:'ready'};
const workspace={mode:'shared',snapshot:()=>JSON.parse(JSON.stringify(state)),subscribe:f=>{listeners.add(f);return()=>listeners.delete(f)}};
const created=[],revoked=[],make=URL.createObjectURL.bind(URL),revoke=URL.revokeObjectURL.bind(URL);URL.createObjectURL=b=>{const u=make(b);created.push(u);return u};URL.revokeObjectURL=u=>{revoked.push(u);revoke(u)};
const uploads=EverbranchWorkspaceUploads.create(boot,workspace),images=EverbranchWorkspaceImages.create(boot,workspace);
const host=document.querySelector('[data-workspace-files]'),dialog=document.getElementById('ticket');let name='Blaine';
const ui=EverbranchWorkspaceFileUi.mount({host,uploads,images,workspace,dialog:document.getElementById('attachment-gallery'),getReviewerName:()=>name,getCurrentTarget:()=>currentTarget,onOpenTarget:t=>{currentTarget=t;ui.setTarget(t,1)},onAccessLoss:()=>document.body.dataset.denied='true'});
document.getElementById('open').onclick=()=>dialog.showModal();document.getElementById('placement').onclick=()=>{dialog.dataset.placement=dialog.dataset.placement==='side'?'center':'side';ui.setTarget(currentTarget,1)};
window.fixture={ui,uploads,images,workspace,created,revoked,async ready(){await ui.setTarget(target,1)},changeTarget:async()=>{currentTarget={kind:'ticket',id:'${OTHER}'};await ui.setTarget(currentTarget,1)},scope(){state={...state,area_ids:['${id(9)}']};listeners.forEach(f=>f())},phase(){state={...state,phase:'unknown'};listeners.forEach(f=>f())},rename(n){name=n;ui.refreshName()}};fixture.ready();
</script>`;
const evidence = resolve(process.env.ENTERPRISE_EVIDENCE || 'test-results/enterprise/reviewer-workspace-files');
await mkdir(evidence, {recursive: true});
const results = [];
for (const [engine, type] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await type.launch({headless: true});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}, hasTouch: true});
    const page = await context.newPage();
    const records = new Map(), calls = [], errors = [], held = [];
    let next = 20, loseBegin = false, holdBinary = false, denyBinary = false;
    const same = (a, b) => a.kind === b.kind && a.id === b.id;
    const canonical = value => JSON.stringify((function sort(v) {return Array.isArray(v) ? v.map(sort) : v && typeof v === 'object' ? Object.fromEntries(Object.keys(v).sort().map(k => [k, sort(v[k])])) : v;})(value));
    for (const n of [10, 11]) {
        const command = {target, name: `SYNTHETIC scan fixture ${n}.png`, size: png.length, mime: 'image/png', sha256: sha(png), request_id: id(n + 100)};
        records.set(id(n), {id: id(n), command, digest: 'e'.repeat(64), status: 'quarantined', revision: 3, chunks: [], syntheticReady: true});
    }
    const view = r => ({id: r.id, name: r.command.name, mime: r.command.mime, size: r.command.size, sha256: r.command.sha256, status: r.status, revision: r.revision, target: r.command.target, mine: !r.syntheticReady, reservation: r.syntheticReady ? null : {request_id: r.command.request_id, digest: r.digest}, received: r.chunks, chunk_bytes: 262144, busy: false, preview_available: false, download_available: false, scan_status: 'not_admitted'});
    const file = r => ({id: r.id, name: r.command.name, mime: r.command.mime, size: r.command.size, original_sha256: r.command.sha256, state: r.syntheticReady ? 'ready' : 'held', preview_available: !!r.syntheticReady, thumbnail: r.syntheticReady ? {sha256: sha(png), size: png.length, mime: 'image/png', width: 320, height: 200, renderer_sha256: 'e'.repeat(64)} : null, scan_expires_at: r.syntheticReady ? expiry : null, attestation_id: r.syntheticReady ? id(Number(r.id.slice(0, 8)) + 200) : null, attestation_revision: r.syntheticReady ? 2 : null});
    const release = () => held.splice(0).forEach(f => f());
    const json = (route, body, status = 200) => route.fulfill({status, contentType: 'application/json', headers: {'Cache-Control': 'private, no-store'}, body: JSON.stringify(body)}).catch(() => {});
    const binCount = () => calls.filter(c => /\/(thumbnail|preview)$/.test(c.path)).length;
    const picker = page.locator('[data-work-files-input]');
    async function open() {await page.locator('#open').click();await page.getByRole('button', {name: 'Add image file', exact: true}).waitFor();await page.getByRole('button', {name: 'Add image file', exact: true}).scrollIntoViewIfNeeded();}
    async function select(name = 'SELECTED FICTIONAL.png') {const chooser = page.waitForEvent('filechooser');await page.getByRole('button', {name: 'Add image file', exact: true}).click();await (await chooser).setFiles({name, mimeType: 'image/png', buffer: png});}
    try {
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', async route => {
            const u = new URL(route.request().url());
            if (u.protocol === 'blob:') return route.continue();
            assert.equal(u.origin, origin, 'No external network permitted');
            if (u.pathname === '/') return route.fulfill({contentType: 'text/html', body: html});
            assert.match(u.pathname, /^\/private\/workspace\/(files|images)\/(show|begin|status|chunk|complete|cancel|metadata|thumbnail|preview)$/);
            assert.equal(route.request().method(), 'POST');
            const body = route.request().postDataJSON(), action = u.pathname.split('/').at(-1);
            calls.push({path: u.pathname, body, headers: route.request().headers()});
            assert.equal(route.request().headers()['x-csrf-token'], 'SYNTHETIC-NOT-A-CREDENTIAL');
            assert.equal(route.request().headers()['x-team-viewer'], undefined);
            const current = [...records.values()].filter(r => same(r.command.target, body.target) && !['cancelled', 'expired'].includes(r.status));
            if (action === 'show') return json(route, {workspace_id: W, target: body.target, target_version: 1, can_upload: true, files: current.map(view), expires_at: expiry, preview_available: false, scanner_available: false});
            if (action === 'metadata') return json(route, {workspace_id: W, workspace_revision: 1, workspace_digest: 'd'.repeat(64), target: body.target, target_version: 1, files: current.map(file), expires_at: expiry});
            if (['thumbnail', 'preview'].includes(action)) {
                const r = records.get(body.upload_id);assert.equal(r?.syntheticReady, true, 'Held upload must never request bytes');
                if (holdBinary) await new Promise(resolve => held.push(resolve));
                if (denyBinary) return json(route, {error: 'unavailable'}, 403);
                return route.fulfill({headers: {'Content-Type': 'image/png', 'Content-Length': String(png.length), 'Cache-Control': 'private, no-store', 'X-Everbranch-Image-Workspace': W, 'X-Everbranch-Image-Version': binding.version_hash, 'X-Everbranch-Image-Upload': r.id, 'X-Everbranch-Image-Original-SHA256': sha(png), 'X-Everbranch-Image-SHA256': sha(png), 'X-Everbranch-Image-Attestation': file(r).attestation_id, 'X-Everbranch-Image-Revision': '2', 'X-Everbranch-Image-Kind': action, 'X-Everbranch-Image-Expires': expiry}, body: png}).catch(() => {});
            }
            if (action === 'begin') {
                let r = [...records.values()].find(r => r.command.request_id === body.request_id), replayed = !!r;
                if (!r) {r = {id: id(next++), command: body, digest: sha(canonical(body)), status: 'uploading', revision: 1, chunks: []};records.set(r.id, r);}
                if (loseBegin) {loseBegin = false;return route.abort('failed');}
                return json(route, {status: 'reserved', replayed, file: view(r)});
            }
            if (action === 'status') {const r = [...records.values()].find(r => r.command.request_id === body.request_id);return json(route, r ? {status: 'reserved', settled: true, file: view(r)} : {status: 'not_observed', settled: false});}
            const r = records.get(body.upload_id);assert.ok(r);
            if (action === 'chunk') {const part = {index: body.index, size: Buffer.from(body.bytes, 'base64').length, sha256: body.sha256};r.chunks = r.chunks.filter(c => c.index !== part.index).concat(part);r.revision++;}
            if (action === 'complete') {r.status = 'quarantined';r.revision++;}
            if (action === 'cancel') {r.status = 'cancelled';r.revision++;}
            return json(route, {status: r.status, file: view(r)});
        });
        await page.goto(origin);await open();
        await picker.setInputFiles({name: 'UNSUPPORTED.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.7 fictional')});
        await page.waitForFunction(() => document.querySelector('[data-work-files-status]').textContent.includes('Choose a JPEG or PNG'));
        assert.equal(calls.filter(c => c.path.endsWith('/begin')).length, 0, 'Image-only UI must not offer an unreadable PDF upload');
        assert.equal(await page.locator('[data-work-files-upload]').isDisabled(), true);
        await select();
        await page.waitForFunction(() => !document.querySelector('[data-work-files-upload]').disabled);
        await page.evaluate(() => {window.savedHost = document.querySelector('[data-workspace-files]');window.savedInput = document.querySelector('[data-work-files-input]');window.savedFile = savedInput.files[0];});
        await page.locator('#placement').click();
        assert.equal(await page.evaluate(() => savedHost === document.querySelector('[data-workspace-files]') && savedInput === document.querySelector('[data-work-files-input]') && savedInput.files[0] === savedFile), true, 'Center/Side must preserve selected File and DOM');
        await page.getByRole('button', {name: 'Upload', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[data-work-files-list]').textContent.includes('SELECTED FICTIONAL.png'));
        assert.equal([...records.values()].filter(r => r.command.name === 'SELECTED FICTIONAL.png').length, 1);
        assert.equal([...records.values()].find(r => r.command.name === 'SELECTED FICTIONAL.png').syntheticReady, undefined, 'Upload stays quarantined without a real scanner');
        assert.equal(await picker.evaluate(n => n.files.length), 0);
        assert.equal(await page.evaluate(() => fixture.uploads.pending()), null);
        assert.equal(await page.locator('#draft').inputValue(), 'Unsent fictional comment');
        const photoChooser = page.waitForEvent('filechooser');
        await page.getByRole('button', {name: 'Add photo', exact: true}).click();
        await (await photoChooser).setFiles({name: 'UNSENT PHOTO.png', mimeType: 'image/png', buffer: png});
        const ready = page.getByRole('button', {name: 'View photo SYNTHETIC scan fixture 10.png', exact: true});
        await ready.scrollIntoViewIfNeeded();await page.waitForFunction(() => document.querySelectorAll('.discussion-image img[src]').length === 2);
        const top = await page.locator('#detail').evaluate(n => n.scrollTop);
        await ready.click();await page.waitForFunction(() => document.querySelector('[data-gallery-image]')?.naturalWidth > 0);
        await page.getByRole('button', {name: 'Zoom in photo'}).click();assert.equal(await page.locator('[data-gallery-zoom-value]').textContent(), '150%');
        await page.getByRole('button', {name: 'Fit', exact: true}).click();await page.keyboard.press('ArrowRight');await page.getByRole('heading', {name: 'SYNTHETIC scan fixture 11.png'}).waitFor();
        await page.keyboard.press('Escape');assert.equal(await page.locator('#ticket').evaluate(n => n.open), true);assert.equal(await page.locator('#attachment-gallery').evaluate(n => n.open), false);
        assert.equal(await ready.evaluate(n => document.activeElement === n), true);assert.equal(await page.locator('#detail').evaluate(n => n.scrollTop), top);
        assert.equal(await page.locator('#ticket').getAttribute('data-placement'), 'center');
        assert.equal(await page.locator('[data-work-photo-input]').evaluate(n => n.files[0].name), 'UNSENT PHOTO.png');
        await page.evaluate(() => fixture.ui.refresh());
        await page.waitForFunction(() => document.querySelectorAll('.discussion-image img[src]').length === 2);
        assert.equal(await page.locator('body').getAttribute('data-denied'), null, 'Ordinary refresh retirement must not deny access');
        await page.setViewportSize({width: 390, height: 844});await ready.click();await page.waitForFunction(() => document.querySelector('[data-gallery-image]')?.naturalWidth > 0);
        await page.locator('[data-gallery-stage]').dispatchEvent('touchstart', {touches: [{identifier: 1, clientX: 300, clientY: 300}]});await page.locator('[data-gallery-stage]').dispatchEvent('touchend', {changedTouches: [{identifier: 1, clientX: 100, clientY: 300}]});await page.getByRole('heading', {name: 'SYNTHETIC scan fixture 11.png'}).waitFor();
        const box = await page.locator('#attachment-gallery').boundingBox();assert.ok(box.width <= 390 && box.height <= 844);
        await page.screenshot({path: resolve(evidence, engine + '-phone.png')});await page.keyboard.press('Escape');
        await page.setViewportSize({width: 1440, height: 1000});
        await page.evaluate(() => {window.syntheticHidden = true;Object.defineProperty(document, 'hidden', {configurable: true, get: () => syntheticHidden});document.dispatchEvent(new Event('visibilitychange'));});
        const hiddenCount = binCount();await page.evaluate(() => fixture.ui.refresh());await page.waitForTimeout(100);
        assert.equal(binCount(), hiddenCount, 'A gallery mounted while already hidden must not request bytes');
        assert.equal(await page.evaluate(() => fixture.created.length === fixture.revoked.length), true);
        await page.evaluate(() => {syntheticHidden = false;document.dispatchEvent(new Event('visibilitychange'));});
        await page.waitForFunction(() => document.querySelectorAll('.discussion-image img[src]').length === 2);

        loseBegin = true;await select('RECOVERY FICTIONAL.png');await page.getByRole('button', {name: 'Upload', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[data-work-files-status]').textContent.includes('unknown'));
        const pending = await page.evaluate(() => fixture.uploads.pending());assert.ok(pending);
        const mutationCount = calls.filter(c => /\/(begin|chunk|complete|cancel)$/.test(c.path)).length;
        await page.reload();await open();await page.waitForFunction(() => !document.querySelector('[data-work-files-recover]').disabled);
        assert.equal(await picker.evaluate(n => n.files.length), 0);assert.equal(calls.filter(c => /\/(begin|chunk|complete|cancel)$/.test(c.path)).length, mutationCount, 'Reload must not redispatch or retain bytes');
        await page.getByRole('button', {name: 'Recover upload', exact: true}).click();await page.waitForFunction(() => document.querySelector('[data-work-files-status]').textContent.includes('reselect') || !document.querySelector('[data-work-files-recover]').disabled);
        await select('RECOVERY FICTIONAL.png');await page.getByRole('button', {name: 'Resume selected file', exact: true}).click();
        await page.waitForFunction(() => fixture.uploads.pending() === null);
        assert.equal(calls.filter(c => c.path.endsWith('/begin') && c.body.request_id === pending.command.request_id).length, 1, 'Recovery must not create a second reservation');
        assert.equal([...records.values()].filter(r => r.command.name === 'RECOVERY FICTIONAL.png').length, 1);
        loseBegin = true;await select('CANCEL FICTIONAL.png');await page.getByRole('button', {name: 'Upload', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[data-work-files-status]').textContent.includes('unknown'));
        await page.getByRole('button', {name: 'Cancel upload', exact: true}).click();await page.waitForFunction(() => fixture.uploads.pending() === null);
        assert.equal([...records.values()].find(r => r.command.name === 'CANCEL FICTIONAL.png').status, 'cancelled');
        await ready.scrollIntoViewIfNeeded();await page.waitForFunction(() => document.querySelectorAll('.discussion-image img[src]').length === 2);
        holdBinary = true;const count = binCount();await ready.click();await page.waitForFunction(() => document.querySelector('[data-gallery-status]').textContent.includes('Checking'));
        for (let n = 0; held.length === 0 && n < 50; n++) await page.waitForTimeout(10);assert.ok(held.length);
        await page.evaluate(() => fixture.scope());release();holdBinary = false;
        await page.waitForFunction(() => fixture.created.length === fixture.revoked.length);assert.equal(await page.locator('#attachment-gallery').evaluate(n => n.open), false);assert.equal(await page.locator('[data-gallery-image]').getAttribute('src'), null);assert.ok(binCount() > count);
        await page.reload();await open();await ready.scrollIntoViewIfNeeded();await page.waitForFunction(() => document.querySelectorAll('.discussion-image img[src]').length === 2);
        denyBinary = true;await ready.click();await page.waitForFunction(() => document.body.dataset.denied === 'true');assert.equal(await page.locator('.discussion-image').count(), 0);assert.equal(await page.evaluate(() => fixture.created.length === fixture.revoked.length), true);
        assert.equal(await page.locator('#draft').inputValue(), 'Unsent fictional comment');
        assert.equal(await page.evaluate(() => Object.values(sessionStorage).some(v => v.includes('blob:') || v.includes('data:image'))), false, 'Private image bytes/URLs never enter browser persistence');
        assert.deepEqual(errors, []);
        results.push({engine, syntheticHttp: true, simulatedScanDtos: true, actualScanner: false, nativeServer: false, physicalTouchOrCamera: false, checks: ['actual file/photo picker and explicit upload', 'held uploads never preview', 'Center/Side preserves File and DOM', 'reloaded pending requires reselect and explicit resume', 'same reservation once and explicit cancellation', 'nested Escape, zoom, next and keyboard', 'focus, scroll and draft retained', 'phone viewport and simulated swipe', 'pre-hidden mount zero-byte fetch; scope abort and revoke', 'actual intercepted403 clears images'], requests: calls.length});
    } finally {release();await context.close();await browser.close();}
}
await writeFile(resolve(evidence, 'result.json'), JSON.stringify(results, null, 2));
console.log(JSON.stringify(results));
