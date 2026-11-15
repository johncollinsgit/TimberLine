import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium, expect} from '@playwright/test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const build = path.join(root, 'public/build');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4s8AAAAASUVORK5CYII=', 'base64');
const escape = value => String(value).replaceAll('&', '&amp;').replaceAll("'", '&#39;').replaceAll('<', '&lt;');

// The production-built React editor talks over real loopback HTTP. Only this
// fictional endpoint server owns persisted state and controls response timing.
async function fixture() {
    const state = {savedPage: null, savedTheme: null, published: null, pages: [], themes: [], uploads: 0, failPage: false, htmlPage: false, holdPage: null, holdTheme: null, holdUpload: null};
    const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json'), 'utf8'));
    const entry = manifest['resources/js/managed-website/editor.tsx'];
    assert.ok(entry?.file, 'Build the actual editor before its browser test.');
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
        if (url.pathname === '/editor') {
            const page = {id: 1, title: 'Fictional studio', slug: '/', blocks: [{id: 'hero-fixture', type: 'hero', heading: 'Fictional studio', body: 'Initial draft'}, {id: 'gallery-fixture', type: 'gallery', items: [{heading: 'A'}, {heading: 'B'}, {heading: 'C'}]}], seo: {}};
            const site = {name: 'Fictional studio', status: 'draft', theme: {theme_name: 'Initial theme', typography: 'sans'}, navigation: [], preview_url: origin + '/preview', preview_site_url: origin + '/preview'};
            response.writeHead(200, {'Content-Type': 'text/html'});
            response.end(`<html><head><meta name="csrf-token" content="fictional-only">${(entry.css || []).map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="managed-website-editor-root" data-page='${escape(JSON.stringify(page))}' data-pages='${escape(JSON.stringify([{id: 1, title: 'Home', slug: '/', url: '/editor'}, {id: 2, title: 'About', slug: 'about', url: '/second'}]))}' data-site='${escape(JSON.stringify(site))}' data-save-url="/save-page" data-theme-save-url="/save-theme" data-publish-url="/publish" data-media-url="/media" data-media-upload-url="/upload" data-index-url="/website" data-publishing-enabled="true"></div><script type="module" src="/build/${entry.file}"></script></body></html>`); return;
        }
        if (url.pathname === '/preview') { response.writeHead(200, {'Content-Type': 'text/html'}); response.end(`<p>Fictional private preview</p>${['A', 'B', 'C'].map((name, index) => `<button onclick="parent.postMessage({type:'everbranch.website.editor.select',kind:'section',blockIndex:'1',itemIndex:'${index}',field:'item_heading'},location.origin)">Select ${name}</button>`).join('')}`); return; }
        if (url.pathname === '/media') { json({media: []}); return; }
        if (url.pathname === '/website-media/7') { response.writeHead(200, {'Content-Type': 'image/png'}); response.end(png); return; }
        if (url.pathname === '/second' || url.pathname === '/website') { response.writeHead(200, {'Content-Type': 'text/html'}); response.end('<p>Navigation completed</p>'); return; }
        const chunks = []; for await (const chunk of request) chunks.push(chunk);
        const raw = Buffer.concat(chunks).toString();
        if (url.pathname === '/save-page' && request.method === 'PUT') {
            const body = JSON.parse(raw); state.pages.push(body);
            const hold = state.holdPage; state.holdPage = null; if (hold) await hold;
            if (state.failPage) { response.destroy(); return; }
            if (state.htmlPage) { response.writeHead(200, {'Content-Type': 'text/html'}); response.end('<p>Sign in again</p>'); return; }
            state.savedPage = body; json({saved_at: new Date().toISOString()}); return;
        }
        if (url.pathname === '/save-theme' && request.method === 'PUT') {
            const body = JSON.parse(raw); state.themes.push(body);
            const hold = state.holdTheme; state.holdTheme = null; if (hold) await hold;
            state.savedTheme = body; json({saved_at: new Date().toISOString()}); return;
        }
        if (url.pathname === '/upload' && request.method === 'POST') {
            state.uploads++; if (state.holdUpload) await state.holdUpload;
            assert.match(request.headers['content-type'], /^multipart\/form-data;/);
            assert.match(raw, /fictional.png/);
            json({media: {id: 7, name: 'fictional.png', url: origin + '/website-media/7', alt_text: 'Uploaded graphic', mime_type: 'image/png'}}); return;
        }
        if (url.pathname === '/publish' && request.method === 'POST') { state.published = structuredClone(state.savedPage); json({status: 'published'}); return; }
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
    await page.goto(origin + '/editor');
    const close = async () => { await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); };
    try { await page.getByLabel(/^Text/).waitFor({timeout: 10000}); }
    catch (error) { const labels = await page.locator('label').allTextContents(); const inputs = await page.locator('textarea').count(); await close(); throw new Error(`Editor did not mount: ${errors.join('; ')} ${failures.join('; ')}. Labels ${JSON.stringify(labels)}; textareas ${inputs}. ${error.message}`); }
    return {state, page, origin, errors, close};
}

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
