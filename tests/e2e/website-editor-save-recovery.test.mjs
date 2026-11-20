import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium, expect} from '@playwright/test';
import './website-retail-controls.test.mjs';
import './website-domain-status-recovery.test.mjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const build = path.join(root, 'public/build');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4s8AAAAASUVORK5CYII=', 'base64');
const escape = value => String(value).replaceAll('&', '&amp;').replaceAll("'", '&#39;').replaceAll('<', '&lt;');

// The production-built React editor talks over real loopback HTTP. Only this
// fictional endpoint server owns persisted state and controls response timing.
async function fixture(options = {}) {
    const state = {savedPage: null, savedTheme: null, published: null, pages: [], themes: [], uploads: 0, failPage: false, htmlPage: false, holdPage: null, holdTheme: null, holdUpload: null, uploadStatus: 201, submitted: null, historyReads: [], restores: [], restoreStatus: 200, pageVersion: 3, siteVersion: 3, pageConflict: false, publishStatus: 200, publishMessage: null, publishAttempts: [], publishResponse: 'confirmed', overviewReads: 0};
    const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json'), 'utf8'));
    const entry = manifest['resources/js/managed-website/editor.tsx'];
    assert.ok(entry?.file, 'Build the actual editor before its browser test.');
    const catalogEntry = manifest['resources/js/managed-website/catalog-photo-fields.tsx'];
    const cssFor = (item, seen = new Set()) => { if (!item || seen.has(item.file)) return []; seen.add(item.file); return [...(item.css || []), ...(item.imports || []).flatMap(key => cssFor(manifest[key], seen))]; };
    let origin;
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, origin);
        const json = (value, status = 200) => { response.writeHead(status, {'Content-Type': 'application/json'}); response.end(JSON.stringify(value)); };
        if (url.pathname.startsWith('/build/')) {
            const file = path.resolve(build, url.pathname.slice('/build/'.length));
            if (!file.startsWith(build + path.sep) || !fs.existsSync(file)) { response.writeHead(404); response.end(); return; }
            response.writeHead(200, {'Content-Type': file.endsWith('.css') ? 'text/css' : 'text/javascript'});
            response.end(fs.readFileSync(file)); return;
        }
        if (url.pathname === '/catalog') {
            const values = options.connected ? [{url: '/original.png', alt: ''}] : options.multiple ? [{url: origin + '/website-catalog-media/1', alt: 'Existing product'}] : [{url: '', alt: ''}];
            const mediaOrigin = options.relativeOwned ? '' : origin;
            const photos = options.connected ? [{id: 'owned', name: 'Original website photo', url: '/original.png'}] : [{id: 9, name: 'Owned catalog photo', url: mediaOrigin + '/website-media/9', catalog_url: mediaOrigin + '/website-catalog-media/9', alt_text: 'Owned catalog detail', mime_type: 'image/png'}];
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(`<html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fictional-only">${cssFor(catalogEntry).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><form method="POST" action="/catalog-submit"><div data-website-photo-field data-value='${escape(JSON.stringify(values))}' data-multiple="${options.multiple ? 'true' : 'false'}" data-connected-origin="${options.connected ? origin : ''}" data-name="${options.connected ? 'content[home_001]' : options.multiple ? 'retained_media[]' : 'image_url'}" data-alt-name="image_alt" data-photos='${escape(JSON.stringify(photos))}' data-related-photos='${escape(JSON.stringify([{id:'starter',name:'Related business photo',url:options.connected ? '/starter.png' : origin + '/starter.png',alt_text:'Original business image'}]))}' data-upload-url="/upload"></div><button type="submit">Save catalog</button></form><script type="module" src="/build/${catalogEntry.file}"></script></body></html>`); return;
        }
        if (url.pathname === '/editor') {
            const page = {id: 1, version_id: state.pageVersion, title: 'Fictional studio', slug: '/', blocks: [{id: 'hero-fixture', type: 'hero', heading: 'Fictional studio', body: 'Initial draft'}, {id: 'gallery-fixture', type: 'gallery', items: [{heading: 'A'}, {heading: 'B'}, {heading: 'C'}]}], seo: {}, ...(state.savedPage || {})};
            const site = {name: 'Fictional studio', version_id: state.siteVersion, status: 'draft', theme: state.savedTheme?.settings || {theme_name: 'Initial theme', typography: 'sans'}, navigation: state.savedTheme?.navigation || [], preview_url: origin + '/preview', preview_site_url: origin + '/preview'};
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(`<html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fictional-only">${cssFor(entry).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="managed-website-editor-root" data-page='${escape(JSON.stringify(page))}' data-pages='${escape(JSON.stringify([{id: 1, title: 'Home', slug: '/', url: '/editor'}, {id: 2, title: 'About', slug: 'about', url: '/second'}]))}' data-site='${escape(JSON.stringify(site))}' data-related-photos='${escape(JSON.stringify(options.related ? [{id:"starter",name:"Related business photo",url:origin + "/starter.png",alt_text:"Original business image"}] : []))}' data-save-url="/save-page" data-theme-save-url="/save-theme" data-publish-url="/publish" data-media-url="/media" data-media-upload-url="/upload" data-index-url="/website" data-retail-shop-available="${options.retailShop ? 'true' : 'false'}" data-catalog-url="/catalog" data-history-url="${options.history ? '/website/editor/1/history' : ''}" data-publishing-enabled="true"></div><script type="module" src="/build/${entry.file}"></script></body></html>`); return;
        }
        if (url.pathname === '/website/editor/1/history' && request.method === 'GET') {
            state.historyReads.push(structuredClone(state.savedPage));
            const row = (id, title, status, site = false) => ({id, version_number:id, title, status, created_at:'2026-11-15T12:00:00Z', is_current:id === (site ? state.siteVersion : state.pageVersion), can_restore:!options.historyReadOnly && id !== (site ? state.siteVersion : state.pageVersion), restore_url:site ? origin + `/website/history/site/${id}/restore` : origin + `/website/editor/1/history/${id}/restore`});
            json({page:{current_version_id:state.pageVersion, versions:[row(state.pageVersion,'Current page','draft'),row(2,'Published page','published'),row(1,'Earlier saved page','draft')]}, site:{current_version_id:state.siteVersion, versions:[row(state.siteVersion,'Current settings','draft',true),row(2,'Published settings','published',true)]}, limits:{page_drafts:30,site_drafts:10,published_shown:30},retention_applies:true,can_restore:!options.historyReadOnly}); return;
        }
        if (url.pathname === '/preview') { response.writeHead(200, {'Content-Type': 'text/html'}); response.end(`<p>Fictional private preview</p>${['A', 'B', 'C'].map((name, index) => `<button onclick="parent.postMessage({type:'everbranch.website.editor.select',kind:'section',blockIndex:'1',itemIndex:'${index}',field:'item_heading'},location.origin)">Select ${name}</button>`).join('')}`); return; }
        if (url.pathname === '/media') { json({media: options.library ? Array.from({length: 13}, (_, index) => ({id: index + 20, name: 'Owned photo ' + (index + 1), url: origin + '/website-media/' + (index + 20), alt_text: 'Owned image description ' + (index + 1), mime_type: 'image/png'})) : []}); return; }
        if (/^\/website-(catalog-)?media\/\d+$/.test(url.pathname) || url.pathname === '/starter.png' || url.pathname === '/original.png') { response.writeHead(200, {'Content-Type': 'image/png'}); response.end(png); return; }
        if (url.pathname === '/second' || url.pathname === '/website') { if (url.pathname === '/website') state.overviewReads++; response.writeHead(200, {'Content-Type': 'text/html'}); response.end('<p>Navigation completed</p>'); return; }
        const chunks = []; for await (const chunk of request) chunks.push(chunk);
        const raw = Buffer.concat(chunks).toString();
        if (/^\/website\/(editor\/1\/history\/1|history\/site\/2)\/restore$/.test(url.pathname) && request.method === 'POST') {
            state.restores.push({path:url.pathname, body:JSON.parse(raw), csrf:request.headers['x-csrf-token']});
            if (state.restoreStatus !== 200) { json({message:'A newer draft exists.'},state.restoreStatus); return; }
            if (url.pathname.includes('/editor/')) state.savedPage = {title:'Earlier saved page',blocks:[{id:'restored-hero',type:'hero',heading:'Restored draft',body:'Earlier private content'}],seo:{}};
            const next = url.pathname.includes('/editor/') ? ++state.pageVersion : ++state.siteVersion;
            json({status:'draft_restored',id:next,version_number:next}); return;
        }
        if (url.pathname === '/catalog-submit' && request.method === 'POST') { state.submitted = [...new URLSearchParams(raw)]; response.writeHead(200, {'Content-Type': 'text/html'}); response.end('<p>Catalog saved</p>'); return; }
        if (url.pathname === '/save-page' && request.method === 'PUT') {
            const body = JSON.parse(raw); state.pages.push(body);
            const hold = state.holdPage; state.holdPage = null; if (hold) await hold;
            if (state.failPage) { response.destroy(); return; }
            if (state.htmlPage) { response.writeHead(200, {'Content-Type': 'text/html'}); response.end('<p>Sign in again</p>'); return; }
            if (state.pageConflict || body.current_version_id !== state.pageVersion) { json({message:'Stale page'},409); return; }
            state.savedPage = body; json({saved_at: new Date().toISOString(),id:++state.pageVersion,version_number:state.pageVersion}); return;
        }
        if (url.pathname === '/save-theme' && request.method === 'PUT') {
            const body = JSON.parse(raw); state.themes.push(body);
            const hold = state.holdTheme; state.holdTheme = null; if (hold) await hold;
            if (body.current_version_id !== state.siteVersion) { json({message:'Stale settings'},409); return; }
            state.savedTheme = body; json({saved_at: new Date().toISOString(),id:++state.siteVersion,version_number:state.siteVersion}); return;
        }
        if (url.pathname === '/upload' && request.method === 'POST') {
            state.uploads++; if (state.holdUpload) await state.holdUpload;
            assert.match(request.headers['content-type'], /^multipart\/form-data;/);
            assert.match(raw, /fictional.png/);
            if (state.uploadStatus !== 201) { json({errors: {image: ['Your private trial includes 10 photos. Activate a plan for more.']}}, state.uploadStatus); return; }
            const mediaOrigin = options.relativeOwned ? '' : origin;
            json({media: {id: 7, name: 'fictional.png', url: mediaOrigin + '/website-media/7', catalog_url: mediaOrigin + '/website-catalog-media/7', alt_text: 'Uploaded graphic', mime_type: 'image/png'}}, 201); return;
        }
        if (url.pathname === '/publish' && request.method === 'POST') { state.publishAttempts.push(structuredClone(state.savedPage)); if (state.publishStatus !== 200) { json({message: state.publishMessage}, state.publishStatus); return; } state.published = structuredClone(state.savedPage); if (state.publishResponse === 'interrupted') { response.writeHead(200, {'Content-Type': 'application/json', 'Content-Length': '1000'}); response.flushHeaders(); response.write('{"status":'); setTimeout(() => response.destroy(), 50); return; } json({status: state.publishResponse === 'unknown' ? 'unknown' : 'published'}); return; }
        response.writeHead(404); response.end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({headless: true, ...(process.platform === 'win32' ? {channel: 'msedge'} : {})});
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const failures = []; page.on('requestfailed', request => failures.push(`${request.url()}: ${request.failure()?.errorText}`));
    page.on('console', message => { if (message.type() === 'error') failures.push(message.text()); });
    if (options.monitorPublish) await page.addInitScript(() => {
        const original = window.fetch;
        window.__websitePublishFetches = 0;
        window.fetch = (input, init) => {
            const url = new URL(typeof input === 'string' ? input : input.url || String(input), location.href);
            if (url.pathname === '/publish' && init?.method === 'POST') window.__websitePublishFetches++;
            return original.call(window, input, init);
        };
    });
    await page.goto(origin + (options.catalog ? '/catalog' : '/editor' + (options.menu ? '?menu=1' : '')));
    const close = async () => { await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); };
    try { await (options.catalog ? page.getByRole('button', {name: 'Choose photo', exact: true}).or(page.getByRole('button', {name: 'Replace photo', exact: true})) : options.menu ? page.getByRole('button', {name: 'Add a page to menu', exact: true}) : page.getByLabel(/^Text/)).first().waitFor({timeout: 10000}); }
    catch (error) { const labels = await page.locator('label').allTextContents(); const inputs = await page.locator('textarea').count(); await close(); throw new Error(`Editor did not mount: ${errors.join('; ')} ${failures.join('; ')}. Labels ${JSON.stringify(labels)}; textareas ${inputs}. ${error.message}`); }
    return {state, page, origin, errors, close};
}

test('a retailer deliberately adds Shop to its draft menu and retains it after reload without publication', async () => {
    const f = await fixture({retailShop:true, menu:true});
    try {
        await f.page.getByRole('button', {name:'Add Shop to menu', exact:true}).click();
        await expect.poll(() => f.state.savedTheme?.navigation).toEqual([{label:'Shop',url:'/shop',type:'link'}]);
        await expect(f.page.getByLabel('Menu destination 1', {exact:true})).toHaveValue('/shop');
        assert.equal(f.state.published,null);
        await f.page.reload();
        await expect(f.page.getByLabel('Menu destination 1', {exact:true})).toHaveValue('/shop');
        assert.equal(f.state.publishAttempts.length,0);
        assert.deepEqual(f.errors,[]);
    } finally {await f.close();}
});

test('an empty retail catalog does not offer a new automatic Shop menu link', async () => {
    const f = await fixture({menu:true});
    try {
        await expect(f.page.getByRole('button',{name:'Add Shop to menu',exact:true})).toBeHidden();
        assert.equal(f.state.themes.length,0);
        assert.equal(f.state.publishAttempts.length,0);
        assert.deepEqual(f.errors,[]);
    } finally {await f.close();}
});

test('a delayed autosave cannot clear or publish newer page edits', async () => {
    const f = await fixture();
    try {
        let release; f.state.holdPage = new Promise(resolve => { release = resolve; });
        await f.page.getByLabel(/^Text/).fill('Page A');
        await expect.poll(() => f.state.pages.length).toBe(1);
        await f.page.getByLabel(/^Text/).fill('Page B typed during A');
        await expect(f.page.locator('.eb-save')).not.toHaveText('Saved');
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        assert.equal(f.state.published, null);
        release();
        await expect.poll(() => f.state.published?.blocks[0].body).toBe('Page B typed during A');
        assert.equal(f.state.savedPage.blocks[0].body, 'Page B typed during A');
        assert.deepEqual(f.state.pages.map(p => p.blocks[0].body), ['Page A', 'Page B typed during A']);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('theme autosave drains its newest version and navigation waits for the page endpoint', async () => {
    const f = await fixture();
    try {
        let releaseTheme; f.state.holdTheme = new Promise(resolve => { releaseTheme = resolve; });
        await f.page.getByRole('button', {name: 'Theme', exact: true}).click();
        await f.page.getByLabel('Theme name', {exact: true}).fill('Theme A');
        await expect.poll(() => f.state.themes.length).toBe(1);
        await f.page.getByLabel('Theme name', {exact: true}).fill('Theme B typed during A');
        releaseTheme();
        await expect.poll(() => f.state.savedTheme?.settings.theme_name).toBe('Theme B typed during A');
        await f.page.getByRole('button', {name: 'Sections', exact: true}).click();
        let releasePage; f.state.holdPage = new Promise(resolve => { releasePage = resolve; });
        await f.page.getByLabel(/^Text/).fill('Saved before navigation');
        await f.page.getByLabel(/^Page/).selectOption('2');
        await expect.poll(() => f.state.pages.length).toBe(1);
        assert.equal(new URL(f.page.url()).pathname, '/editor');
        releasePage();
        await f.page.waitForURL('**/second');
        assert.equal(f.state.savedPage.blocks[0].body, 'Saved before navigation');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('network save failure preserves dirty work blocks publish and navigation and can be retried', async () => {
    const f = await fixture();
    try {
        f.state.failPage = true;
        await f.page.getByLabel(/^Text/).fill('Recover this exact text');
        await expect(f.page.locator('.eb-save')).toContainText('Could not save page');
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toContainText('Could not save page');
        await f.page.getByLabel(/^Page/).selectOption('2');
        await expect.poll(() => f.state.pages.length).toBeGreaterThanOrEqual(3);
        assert.equal(new URL(f.page.url()).pathname, '/editor'); assert.equal(f.state.published, null);
        const dialog = f.page.waitForEvent('dialog');
        const reload = f.page.reload({timeout: 5000}).catch(() => null);
        const warning = await dialog; assert.equal(warning.type(), 'beforeunload'); await warning.dismiss(); await reload;
        await expect(f.page.getByLabel(/^Text/)).toHaveValue('Recover this exact text');
        f.state.failPage = false;
        await f.page.getByRole('button', {name: 'Save', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toHaveText('Saved');
        await f.page.getByRole('link', {name: 'Back to Website', exact: true}).click();
        await f.page.waitForURL('**/website');
        assert.equal(f.state.savedPage.blocks[0].body, 'Recover this exact text');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('a publish checklist failure leads to Website recovery and saves newer edits before leaving on a phone', async () => {
    const f = await fixture();
    try {
        await f.page.setViewportSize({width: 390, height: 844});
        f.state.publishStatus = 422;
        f.state.publishMessage = 'Finish the Website checklist before publishing.';
        await f.page.getByLabel(/^Text/).fill('Private photo story before checklist review');
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        await expect(f.page.getByRole('alert')).toContainText(f.state.publishMessage);
        const recovery = f.page.getByRole('link', {name: 'Review checklist', exact: true});
        await expect(recovery).toHaveAttribute('href', '/website');
        assert.equal(f.state.publishAttempts.length, 1);
        assert.equal(f.state.published, null);
        assert.equal(f.state.savedPage.blocks[0].body, 'Private photo story before checklist review');
        await expect(f.page.locator('.eb-editor-title')).toContainText('Draft');
        assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await f.page.getByLabel(/^Text/).fill('Newer private copy retained on the way to Edit details');
        await recovery.click();
        await f.page.waitForURL('**/website');
        assert.equal(f.state.savedPage.blocks[0].body, 'Newer private copy retained on the way to Edit details');
        assert.equal(f.state.publishAttempts.length, 1, 'Recovery never retries publication.');
        assert.equal(f.state.published, null);
        await f.page.goto(f.origin + '/editor');
        await expect(f.page.getByLabel(/^Text/)).toHaveValue('Newer private copy retained on the way to Edit details');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('publish recovery keeps permission freeze conflict and unknown errors safe without exposing server details', async () => {
    const f = await fixture();
    try {
        await f.page.getByLabel(/^Text/).fill('Saved draft remains private for every blocked publication');
        const cases = [
            [403, 'Only a website owner or admin can publish.'],
            [423, 'Publishing is paused or your plan needs attention.'],
            [409, 'Your website changed elsewhere. Keep your edits here and review the saved draft before publishing.'],
            [422, 'Complete the Website checklist before publishing.'],
            [500, 'Publishing was not confirmed. Review your website before trying again.'],
        ];
        for (const [status, message] of cases) {
            f.state.publishStatus = status;
            f.state.publishMessage = '<img src=x onerror=alert(1)> private-provider-detail';
            const count = f.state.publishAttempts.length;
            await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
            await expect(f.page.getByRole('alert')).toContainText(message);
            await expect(f.page.getByRole('alert')).not.toContainText('private-provider-detail');
            await expect(f.page.locator('.eb-editor-title')).toContainText('Draft');
            assert.equal(f.state.publishAttempts.length, count + 1);
            assert.equal(f.state.published, null);
            assert.equal(f.state.savedPage.blocks[0].body, 'Saved draft remains private for every blocked publication');
            await expect(f.page.getByLabel(/^Text/)).toHaveValue('Saved draft remains private for every blocked publication');
        }
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

for (const receipt of ['unknown', 'interrupted']) {
    test(`an ${receipt} publish receipt requires explicit Website review and never repeats an accepted attempt`, async () => {
        const f = await fixture({monitorPublish: true});
        try {
            f.state.publishResponse = receipt;
            await f.page.getByLabel(/^Text/).fill('Original private draft sent once for publication');
            await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
            await expect(f.page.getByRole('alert')).toContainText('Publishing was not confirmed. Review your website before trying again.');
            await expect(f.page.locator('.eb-save')).toHaveText('Your draft is saved.');
            await expect(f.page.locator('.eb-editor-title')).toContainText('Draft');
            assert.equal(f.state.publishAttempts.length, 1);
            assert.equal(f.state.overviewReads, 0, 'Ambiguity does not navigate or retry automatically.');
            assert.equal(f.state.published.blocks[0].body, 'Original private draft sent once for publication', 'The endpoint accepted the original request despite an unconfirmed receipt.');
            await f.page.getByLabel(/^Text/).fill('Newer private draft kept while reviewing publication');
            assert.equal(await f.page.evaluate(() => window.__websitePublishFetches), 1, 'The editor initiates only its original publication fetch.');
            await f.page.getByRole('link', {name: 'Review Website', exact: true}).click();
            await f.page.waitForURL('**/website');
            assert.equal(f.state.overviewReads, 1, 'Review uses the ordinary Website GET.');
            assert.equal(f.state.publishAttempts.length, 1, 'Review never submits another publication.');
            assert.equal(f.state.savedPage.blocks[0].body, 'Newer private draft kept while reviewing publication');
            assert.equal(f.state.published.blocks[0].body, 'Original private draft sent once for publication');
            await f.page.goto(f.origin + '/editor');
            await expect(f.page.getByLabel(/^Text/)).toHaveValue('Newer private draft kept while reviewing publication');
            assert.deepEqual(f.errors, []);
        } finally { await f.close(); }
    });
}

test('a normal section upload preserves URL alt text and edits made during the request', async () => {
    const f = await fixture();
    try {
        await f.page.setViewportSize({width: 390, height: 1000});
        let release; f.state.holdUpload = new Promise(resolve => { release = resolve; });
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect.poll(() => f.state.uploads).toBe(1);
        await f.page.getByLabel(/^Text/).fill('Keep this newer copy with the image');
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        assert.equal(f.state.published, null);
        release();
        await expect(f.page.getByLabel('Image URL', {exact: true})).toHaveValue(f.origin + '/website-media/7');
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('Uploaded graphic');
        await expect.poll(() => f.state.published?.blocks[0].image_url).toBe(f.origin + '/website-media/7');
        assert.equal(f.state.published.blocks[0].image_alt, 'Uploaded graphic');
        assert.equal(f.state.published.blocks[0].body, 'Keep this newer copy with the image');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('an unexpected successful HTML response never confirms a draft save', async () => {
    const f = await fixture();
    try {
        f.state.htmlPage = true;
        await f.page.getByLabel(/^Text/).fill('Keep this after an expired session response');
        await expect(f.page.locator('.eb-save')).toContainText('Could not save page');
        assert.equal(f.state.savedPage, null);
        await f.page.getByRole('link', {name: 'Back to Website', exact: true}).click();
        await expect.poll(() => f.state.pages.length).toBeGreaterThanOrEqual(2);
        assert.equal(new URL(f.page.url()).pathname, '/editor');
        f.state.htmlPage = false;
        await f.page.getByRole('button', {name: 'Save', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toHaveText('Saved');
        assert.equal(f.state.savedPage.blocks[0].body, 'Keep this after an expired session response');
    } finally { await f.close(); }
});

test('a delayed gallery upload stays on its item after a preceding item is removed', async () => {
    const f = await fixture();
    try {
        const canvas = f.page.frameLocator('iframe');
        await canvas.getByRole('button', {name: 'Select B', exact: true}).click();
        await expect(f.page.getByLabel('Image URL', {exact: true})).toHaveAttribute('data-eb-item-index', '1');
        let release; f.state.holdUpload = new Promise(resolve => { release = resolve; });
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect.poll(() => f.state.uploads).toBe(1);
        await canvas.getByRole('button', {name: 'Select A', exact: true}).click();
        await expect(f.page.getByLabel('Image URL', {exact: true})).toHaveAttribute('data-eb-item-index', '0');
        await f.page.getByRole('button', {name: 'Remove item', exact: true}).click();
        release();
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        await expect.poll(() => f.state.published?.blocks[1].items?.[0]?.image_url).toBe(f.origin + '/website-media/7');
        assert.deepEqual(f.state.published.blocks[1].items.map(item => [item.heading, item.image_url]), [['B', f.origin + '/website-media/7'], ['C', undefined]]);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('an empty gallery item is reachable and its related photo and description survive save and reload', async () => {
    const f = await fixture({related: true});
    try {
        await f.page.locator('.eb-tree__sections').getByRole('button', {name: /Gallery/}).click();
        await f.page.getByRole('button', {name: 'Add item', exact: true}).click();
        await f.page.getByRole('button', {name: 'All images', exact: true}).click();
        await f.page.getByRole('button', {name: 'Image 4 Add photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByRole('tab', {name: 'Related photos'}).click();
        await f.page.getByRole('button', {name: 'Use Related business photo', exact: true}).click();
        await f.page.getByLabel('Image description', {exact: true}).fill('A fictional workshop garden');
        await f.page.getByRole('button', {name: 'Save', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toHaveText('Saved');
        assert.equal(f.state.savedPage.blocks[1].items[3].image_url, f.origin + '/starter.png');
        assert.equal(f.state.savedPage.blocks[1].items[3].image_alt, 'A fictional workshop garden');
        await f.page.reload();
        await f.page.locator('.eb-tree__sections').getByRole('button', {name: /Gallery/}).click();
        await f.page.getByRole('button', {name: 'A fictional workshop garden Edit', exact: true}).click();
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('A fictional workshop garden');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('the full photo library is searchable and selection removal and undo change URL and alt together', async () => {
    const f = await fixture({library: true});
    try {
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByLabel('Find photos', {exact: true}).fill('Owned photo 13');
        const photo = f.page.getByRole('button', {name: 'Use Owned photo 13', exact: true});
        await photo.click();
        await expect(photo).toHaveAttribute('aria-pressed', 'true');
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('Owned image description 13');
        await f.page.getByRole('button', {name: 'Undo', exact: true}).click();
        await expect(f.page.getByLabel('Image URL', {exact: true})).toHaveValue('');
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('');
        await f.page.getByRole('button', {name: 'Redo', exact: true}).click();
        await expect(f.page.getByLabel('Image URL', {exact: true})).toHaveValue(f.origin + '/website-media/32');
        await f.page.getByRole('button', {name: 'Remove photo', exact: true}).click();
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('');
        await f.page.getByRole('button', {name: 'Undo', exact: true}).click();
        await expect(f.page.getByLabel('Image URL', {exact: true})).toHaveValue(f.origin + '/website-media/32');
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('Owned image description 13');
        await f.page.getByLabel('Find photos', {exact: true}).press('Escape');
        await expect(f.page.getByRole('button', {name: 'Replace photo', exact: true})).toBeFocused();
        await expect(f.page.getByRole('tab', {name: 'Your photos'})).toHaveCount(0);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('a delayed upload cannot replace a newer explicit image selection', async () => {
    const f = await fixture({library: true});
    try {
        let release; f.state.holdUpload = new Promise(resolve => { release = resolve; });
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect.poll(() => f.state.uploads).toBe(1);
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Use Owned photo 1', exact: true}).click();
        release();
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        await expect.poll(() => f.state.published?.blocks[0].image_url).toBe(f.origin + '/website-media/20');
        assert.equal(f.state.published.blocks[0].image_alt, 'Owned image description 1');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('photo errors explain unsupported files and trial limits then allow retry', async () => {
    const f = await fixture();
    try {
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>')});
        await expect(f.page.getByRole('alert')).toContainText('JPG, PNG, WebP or AVIF');
        assert.equal(f.state.uploads, 0);
        f.state.uploadStatus = 422;
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect(f.page.getByRole('alert')).toContainText('private trial includes 10 photos');
        f.state.uploadStatus = 201;
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('Uploaded graphic');
        await expect(f.page.getByRole('alert')).toHaveCount(0);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('logo and social-image pickers save their own versioned slots without changing section images', async () => {
    const f = await fixture({library: true, related: true});
    try {
        await f.page.getByRole('button', {name: 'Theme', exact: true}).click();
        const logo = f.page.getByRole('region', {name: 'Logo picker', exact: true});
        await logo.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await logo.getByRole('button', {name: 'Use Owned photo 1', exact: true}).click();
        const social = f.page.getByRole('region', {name: 'Social image picker', exact: true});
        await social.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await social.getByRole('tab', {name: 'Related photos'}).click();
        await social.getByRole('button', {name: 'Use Related business photo', exact: true}).click();
        await f.page.getByLabel('Theme name', {exact: true}).fill('Fictional workshop');
        await f.page.getByRole('button', {name: 'Save', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toHaveText('Saved');
        assert.equal(f.state.savedTheme.settings.logo_url, f.origin + '/website-media/20');
        assert.equal(f.state.savedTheme.settings.logo_alt, 'Owned image description 1');
        assert.equal(f.state.savedTheme.settings.theme_name, 'Fictional workshop');
        assert.equal(f.state.savedPage.seo.social_image, f.origin + '/starter.png');
        assert.equal(f.state.savedPage.seo.social_image_alt, 'Original business image');
        assert.equal(f.state.savedPage.blocks[0].image_url, undefined);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('new photo sections start with the related business image and the phone picker fits and returns focus', async () => {
    const f = await fixture({related: true});
    try {
        await f.page.setViewportSize({width: 390, height: 1000});
        for (const name of ['Image', 'Image with text', 'Call to action']) {
            await f.page.getByRole('button', {name: /Add section/}).click();
            await f.page.getByRole('dialog', {name: 'Add a section'}).getByRole('button').filter({has: f.page.getByText(name, {exact: true})}).click();
            await expect(f.page.getByLabel('Image description', {exact: true})).toHaveValue('Original business image');
        }
        await f.page.getByRole('button', {name: 'Replace photo', exact: true}).click();
        const tab = f.page.getByRole('tab', {name: 'Your photos'});
        await tab.focus(); await tab.press('ArrowRight');
        await expect(f.page.getByRole('tab', {name: 'Related photos'})).toBeFocused();
        await f.page.getByRole('button', {name: 'Use Related business photo', exact: true}).press('Escape');
        await expect(f.page.getByRole('button', {name: 'Replace photo', exact: true})).toBeFocused();
        assert.ok(await f.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Phone editor must not overflow horizontally.');
        await f.page.getByRole('button', {name: 'Save', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toHaveText('Saved');
        for (const block of f.state.savedPage.blocks.slice(2)) assert.equal(block.image_url, f.origin + '/starter.png');
        if (process.env.EVERBRANCH_PHOTO_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.EVERBRANCH_PHOTO_SCREENSHOT_DIR, {recursive: true});
            await f.page.screenshot({path: path.join(process.env.EVERBRANCH_PHOTO_SCREENSHOT_DIR, 'everbranch-photo-picker-phone.png'), fullPage: true});
        }
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('catalog photo upload waits for confirmation and keeps its item when another photo is removed', async () => {
    const f = await fixture({catalog: true, multiple: true});
    try {
        await f.page.getByRole('button', {name: 'Add photo', exact: true}).click();
        await expect(f.page.getByLabel('Cover image description', {exact: true})).toHaveCount(1);
        await expect(f.page.getByLabel('Image description', {exact: true})).toHaveCount(0);
        let release; f.state.holdUpload = new Promise(resolve => { release = resolve; });
        await f.page.getByRole('region', {name: 'Image 2 picker', exact: true}).locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect.poll(() => f.state.uploads).toBe(1);
        await f.page.getByRole('button', {name: 'Delete photo 1', exact: true}).click();
        await f.page.getByRole('button', {name: 'Save catalog', exact: true}).click();
        assert.equal(f.state.submitted, null);
        release();
        await expect.poll(() => f.state.submitted).not.toBeNull();
        assert.deepEqual(f.state.submitted, [['retained_media[]', f.origin + '/website-catalog-media/7'], ['image_alt', 'Uploaded graphic']]);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('collection photo selection submits the catalog alias and its description without a technical URL', async () => {
    const f = await fixture({catalog: true});
    try {
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Use Owned catalog photo', exact: true}).click();
        await f.page.getByLabel('Image description', {exact: true}).fill('Fictional collection detail');
        await f.page.getByRole('button', {name: 'Save catalog', exact: true}).click();
        await expect.poll(() => f.state.submitted).not.toBeNull();
        assert.deepEqual(f.state.submitted, [['image_url', f.origin + '/website-catalog-media/9'], ['image_alt', 'Fictional collection detail']]);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('native owned picker and upload preserve relative catalog references on a nondefault port', async () => {
    const f = await fixture({catalog: true, relativeOwned: true});
    try {
        assert.notEqual(new URL(f.origin).port, '');
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Use Owned catalog photo', exact: true}).click();
        await expect(f.page.locator('input[name=image_url]')).toHaveValue('/website-catalog-media/9');
        await expect.poll(() => f.page.locator('.eb-photo__preview img').first().evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect(f.page.locator('input[name=image_url]')).toHaveValue('/website-catalog-media/7');
        await expect(f.page.locator('input[name=image_alt]')).toHaveValue('Uploaded graphic');
        await expect.poll(() => f.page.locator('.eb-photo__preview img').first().evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
        await f.page.getByRole('button', {name: 'Save catalog', exact: true}).click();
        await expect.poll(() => f.state.submitted).not.toBeNull();
        assert.deepEqual(f.state.submitted, [['image_url', '/website-catalog-media/7'], ['image_alt', 'Uploaded graphic']]);
        assert.equal(f.state.uploads, 1);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('a new nested service card can choose replace and clear its photo without changing siblings', async () => {
    const f = await fixture({related: true, library: true});
    try {
        await f.page.getByRole('button', {name: /Add section/}).click();
        await f.page.getByRole('dialog', {name: 'Add a section'}).getByRole('button').filter({has: f.page.getByText('Service cards', {exact: true})}).click();
        await f.page.getByRole('button', {name: 'Add item', exact: true}).click();
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByRole('tab', {name: 'Related photos'}).click();
        await f.page.getByRole('button', {name: 'Use Related business photo', exact: true}).click();
        await f.page.getByRole('tab', {name: 'Your photos'}).click();
        await f.page.getByRole('button', {name: 'Use Owned photo 2', exact: true}).click();
        await f.page.getByRole('button', {name: 'All cards', exact: true}).click();
        await f.page.getByRole('button', {name: 'Add item', exact: true}).click();
        await f.page.getByRole('button', {name: 'Choose photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Use Owned photo 3', exact: true}).click();
        await f.page.getByRole('button', {name: 'Remove photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Save', exact: true}).click();
        await expect(f.page.locator('.eb-save')).toHaveText('Saved');
        assert.deepEqual(f.state.savedPage.blocks[2].items.map(({image_url, image_alt}) => [image_url, image_alt]), [[f.origin + '/website-media/21', 'Owned image description 2'], ['', '']]);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('a delayed section upload keeps its original block after a drag reorder', async () => {
    const f = await fixture();
    try {
        let release; f.state.holdUpload = new Promise(resolve => { release = resolve; });
        await f.page.locator('input[type=file]').setInputFiles({name: 'fictional.png', mimeType: 'image/png', buffer: png});
        await expect.poll(() => f.state.uploads).toBe(1);
        await f.page.locator('.eb-tree__sections').getByRole('button', {name: /Hero/}).dragTo(f.page.locator('.eb-tree__sections').getByRole('button', {name: /Gallery/}));
        release();
        await f.page.getByRole('button', {name: 'Publish', exact: true}).click();
        await expect.poll(() => f.state.published?.blocks[1]?.image_url).toBe(f.origin + '/website-media/7');
        assert.equal(f.state.published.blocks[0].type, 'gallery');
        assert.equal(f.state.published.blocks[1].type, 'hero');
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});


test('a connected-site picker selects only existing photos and preserves the original form path without promising uploads', async () => {
    const f = await fixture({catalog: true, connected: true});
    try {
        await expect(f.page.locator('input[type=file]')).toHaveCount(0);
        await expect(f.page.getByRole('button', {name: 'Remove photo', exact: true})).toHaveCount(0);
        await expect(f.page.getByText('New uploads for this connected design are not available yet.', {exact: false})).toBeVisible();
        await f.page.getByRole('button', {name: 'Replace photo', exact: true}).click();
        await f.page.getByRole('tab', {name: 'Related photos'}).click();
        await f.page.getByRole('button', {name: 'Use Related business photo', exact: true}).click();
        await f.page.getByRole('button', {name: 'Save catalog', exact: true}).click();
        await expect.poll(() => f.state.submitted).not.toBeNull();
        assert.deepEqual(f.state.submitted, [['content[home_001]', '/starter.png']]);
        assert.equal(f.state.uploads, 0);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('saved version history waits for the newest autosave and restores a private page draft across reload', async () => {
    const f = await fixture({history: true});
    try {
        let release; f.state.holdPage = new Promise(resolve => { release = resolve; });
        await f.page.getByLabel(/^Text/).fill('Latest work before history');
        await f.page.getByRole('button', {name: 'History', exact: true}).click();
        await expect(f.page.getByRole('status')).toHaveText('Loading saved versions…');
        assert.deepEqual(f.state.historyReads, []);
        release();
        const dialog = f.page.getByRole('dialog', {name: 'Saved versions'});
        await expect(dialog.getByText('Earlier saved page', {exact: true})).toBeVisible();
        assert.equal(f.state.historyReads[0].blocks[0].body, 'Latest work before history');
        await dialog.getByRole('button', {name: 'Restore page version 1'}).click();
        await expect(f.page.getByLabel(/^Text/)).toHaveValue('Earlier private content');
        assert.deepEqual(f.state.restores, [{path:'/website/editor/1/history/1/restore',body:{current_version_id:4},csrf:'fictional-only'}]);
        assert.equal(f.state.published, null);
        assert.equal(f.state.uploads, 0);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('saved settings history uses its own source and never publishes restored settings', async () => {
    const f = await fixture({history: true});
    try {
        await f.page.getByRole('button', {name: 'History', exact: true}).click();
        const dialog = f.page.getByRole('dialog', {name: 'Saved versions'});
        await dialog.getByRole('tab', {name:'Site settings',exact:true}).click();
        await expect(dialog.getByText('Published settings', {exact:true})).toBeVisible();
        await dialog.getByRole('button', {name:'Restore site settings version 2'}).click();
        await expect(f.page.getByLabel(/^Text/)).toHaveValue('Initial draft');
        await expect.poll(() => f.state.restores).toEqual([{path:'/website/history/site/2/restore',body:{current_version_id:3},csrf:'fictional-only'}]);
        assert.equal(f.state.published, null);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('expired saved history is read only and keyboard close returns focus without a mutation', async () => {
    const f = await fixture({history: true,historyReadOnly:true});
    try {
        await f.page.getByRole('button', {name: 'History', exact: true}).click();
        const dialog = f.page.getByRole('dialog', {name: 'Saved versions'});
        await expect(dialog.getByText('Earlier saved page', {exact:true})).toBeVisible();
        await expect(dialog.getByRole('button', {name:/Restore/})).toHaveCount(0);
        await expect(dialog.getByText('Activate your plan to restore and edit saved work.', {exact:true})).toBeVisible();
        await dialog.getByRole('tab', {name:'Page',exact:true}).focus();
        await f.page.keyboard.press('ArrowRight');
        await expect(dialog.getByRole('tab', {name:'Site settings',exact:true})).toBeFocused();
        await f.page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(f.page.getByRole('button', {name:'History',exact:true})).toBeFocused();
        assert.deepEqual(f.state.restores, []);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('saved history rejects a stale restore without claiming success or discarding the open draft', async () => {
    const f = await fixture({history:true});
    try {
        f.state.restoreStatus = 409;
        await f.page.setViewportSize({width:390,height:844});
        await f.page.getByRole('button', {name:'History',exact:true}).click();
        const dialog = f.page.getByRole('dialog', {name:'Saved versions'});
        await dialog.getByRole('button', {name:'Restore page version 1'}).click();
        await expect(dialog.getByRole('alert')).toHaveText('A newer draft was saved. Close and reopen history before restoring.');
        const dimensions = await dialog.evaluate(element => ({width:element.getBoundingClientRect().width,inner:window.innerWidth,scroll:element.scrollWidth,client:element.clientWidth}));
        assert.ok(dimensions.width <= dimensions.inner && dimensions.scroll <= dimensions.client + 1);
        await dialog.getByRole('button', {name:'Close saved versions'}).click();
        await expect(f.page.getByLabel(/^Text/)).toHaveValue('Initial draft');
        assert.equal(f.state.restores.length, 1);
        assert.equal(f.state.savedPage, null);
        assert.equal(f.state.published, null);
        assert.deepEqual(f.errors, []);
    } finally { await f.close(); }
});

test('a conflicting autosave retains local edits and never retries against a newer saved version', async () => {
    const f = await fixture({history:true});
    try {
        f.state.pageConflict = true;
        await f.page.getByLabel(/^Text/).fill('Local edits worth keeping');
        await f.page.getByRole('button', {name:'Save',exact:true}).click();
        await expect(f.page.locator('.eb-save')).toContainText('This page changed elsewhere.');
        await f.page.getByRole('button', {name:'Publish',exact:true}).click();
        await expect(f.page.locator('.eb-save')).toContainText('This page changed elsewhere.');
        await expect(f.page.getByLabel(/^Text/)).toHaveValue('Local edits worth keeping');
        assert.ok(f.state.pages.length >= 1);
        assert.ok(f.state.pages.every(body => body.current_version_id === 3));
        assert.equal(f.state.savedPage,null);
        assert.equal(f.state.published,null);
        assert.deepEqual(f.errors,[]);
    } finally { await f.close(); }
});
