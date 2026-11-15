import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium, expect} from '@playwright/test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const build = path.join(root, 'public/build');

// Feature tests render the actual Laravel views/guards. This complementary
// fixture executes their production-built grids with intentionally absent
// action URLs, so mounting/searching cannot resurrect unavailable controls.
async function fixture(state, width) {
    const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json'), 'utf8'));
    const customers = manifest['resources/js/marketing/customers-grid.tsx'];
    const work = manifest['resources/js/field-service/jobs-grid.tsx'];
    assert.ok(customers?.file && work?.file, 'Build the actual grids first.');
    const trial = state !== 'legacy';
    const reads = [];
    const writes = [];
    let origin;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, origin);
        if (request.method !== 'GET') { writes.push(url.pathname); response.writeHead(423); response.end(); return; }
        const json = value => { response.writeHead(200, {'Content-Type': 'application/json'}); response.end(JSON.stringify(value)); };
        if (url.pathname.startsWith('/build/')) {
            const file = path.resolve(build, url.pathname.slice(7));
            if (!file.startsWith(build + path.sep) || !fs.existsSync(file)) { response.writeHead(404); response.end(); return; }
            response.writeHead(200, {'Content-Type': file.endsWith('.css') ? 'text/css' : 'application/javascript'});
            response.end(fs.readFileSync(file)); return;
        }
        if (url.pathname === '/customers-data') {
            reads.push(url.href);
            json({rows: [], meta: {columns: [{key: 'first_name', label: 'Name', type: 'text'}], pagination: {page: 1, per_page: 25, total: 0, last_page: 1}, filters: {}, sort_options: []}}); return;
        }
        if (url.pathname === '/jobs-data') {
            reads.push(url.href);
            json({rows: [{id: 1, kind: 'job', title: 'Fictional retained job', status: 'scheduled', customer: 'Fictional customer', url: '/job/1'}], meta: {total: 1, page: 1, last_page: 1}, options: {team: [], vehicles: [], statuses: ['scheduled']}}); return;
        }
        if (url.pathname === '/job/1/updates') { json({updates: []}); return; }
        const importedStyles = entry => [...(entry.css || []), ...(entry.imports || []).flatMap(key => importedStyles(manifest[key]))];
        const styles = [...new Set([manifest['resources/css/app.css']?.file, ...importedStyles(customers), ...importedStyles(work)].filter(Boolean))];
        response.writeHead(200, {'Content-Type': 'text/html'});
        response.end(`<html><head><meta name="csrf-token" content="fictional-only">${styles.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body data-eb-workspace><main style="padding:16px"><div id="marketing-customers-grid" data-endpoint="/customers-data" data-add-customer-url="${state === 'expired' ? '' : '/customers/create'}" data-message-customer-url="${trial ? '' : '/messages'}" data-bulk-action-url="${trial ? '' : '/bulk'}" data-operational-directory="true"></div><div id="field-service-jobs-grid" data-endpoint="/jobs-data" data-update-template="/job/0" data-transition-template="/job/0/transition" data-updates-template="/job/0/updates" data-note-template="/job/0/notes" data-can-manage="${trial ? 0 : 1}" data-can-manage-drafts="${trial ? 0 : 1}" ${trial ? 'data-can-post-updates="0"' : ''}></div></main><script type="module" src="/build/${customers.file}"></script><script type="module" src="/build/${work.file}"></script></body></html>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch(process.platform === 'win32' ? {channel: 'msedge'} : {});
    const page = await browser.newPage({viewport: {width, height: 1000}});
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    page.setDefaultTimeout(5000);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto(origin);
    return {page, reads, writes, errors, async close() { await browser.close(); await new Promise(resolve => server.close(resolve)); }};
}

for (const state of ['active', 'expired', 'legacy']) {
    for (const width of [1440, 390]) {
        test(`${state} controls stay truthful after the normal grids mount at ${width}px`, async () => {
            const f = await fixture(state, width);
            try {
                const {page} = f;
                const customers = page.locator('#marketing-customers-grid');
                const work = page.locator('#field-service-jobs-grid');
                await expect(customers.getByRole('heading', {name: 'Manage Customers'})).toBeVisible();
                await expect(work.getByText('1 current job', {exact: true})).toBeVisible();
                await expect(customers.getByRole('link', {name: 'Add Customer', exact: true})).toHaveCount(state === 'expired' ? 0 : 1);
                await expect(customers.getByRole('link', {name: 'Text a customer'})).toHaveCount(state === 'legacy' ? 1 : 0);
                await expect(work.getByRole('button', {name: 'Edit all', exact: true})).toHaveCount(state === 'legacy' ? 1 : 0);
                await expect(work.getByRole('button', {name: 'Job Drafts', exact: true})).toHaveCount(state === 'legacy' ? 1 : 0);
                await customers.getByPlaceholder('Search name, email, phone, or address').fill('Fictional');
                await work.getByPlaceholder('Search jobs, customers, addresses').fill('Retained');
                await expect.poll(() => f.reads.some(url => new URL(url).searchParams.get('search') === 'Fictional')).toBe(true);
                await expect.poll(() => f.reads.some(url => new URL(url).searchParams.get('q') === 'Retained')).toBe(true);
                if (width === 1440) {
                    const canvas = work.locator('canvas').first();
                    await work.locator('.eb-workspace-grid-canvas').scrollIntoViewIfNeeded();
                    await canvas.waitFor({state: 'visible'});
                    const bounds = await canvas.boundingBox();
                    assert.ok(bounds);
                    // Glide receives native input on its transparent scroller.
                    await page.mouse.click(bounds.x + (state === 'legacy' ? 60 : 24), bounds.y + 72);
                    await expect(page.getByRole('heading', {name: 'Fictional retained job', exact: true})).toBeVisible();
                    await expect(page.getByPlaceholder('Write a job update or field note')).toHaveCount(state === 'legacy' ? 1 : 0);
                }
                assert.deepEqual(f.errors, []);
                assert.deepEqual(f.writes, []);
            } finally { await f.close(); }
        });
    }
}
