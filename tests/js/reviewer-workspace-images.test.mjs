import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
const [shared, source] = await Promise.all(['shared-workspace.js', 'workspace-image-client.js'].map(n => readFile(new URL('../../resources/js/enterprise/reviewer/' + n, import.meta.url), 'utf8')));
const id = n => String(n).padStart(8, '0') + '-0000-4000-8000-000000000001', W = id(1), A = id(2), B = id(3), T = id(4), F = id(5), S = id(6), sha = b => createHash('sha256').update(b).digest('hex'), plain = v => JSON.parse(JSON.stringify(v)), turn = () => new Promise(r => setImmediate(r));
const deferred = () => { let resolve; const promise = new Promise(r => resolve = r); return { promise, resolve }; };
function fixture() {
    const data = new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]), hash = sha(data), binding = { version_hash: 'a'.repeat(64), source_hash: 'b'.repeat(64), build_id: 'FAKE-IMAGE-REVIEW' };
    const boot = { reviewerWorkspace: { binding, endpoints: { projection: '/private/workspace/projection' } }, reviewerImages: { enabled: true, schema_version: 1, workspace_id: W, browser_context: 'c'.repeat(64), binding, expires_at: '2099-01-01T00:00:00Z', csrf: 'FAKE-NOT-CREDENTIAL', endpoints: Object.fromEntries(['metadata', 'thumbnail', 'preview'].map(a => [a, '/private/workspace/images/' + a])) } };
    let state = { workspace_id: W, browser_context: 'c'.repeat(64), persona: 'demo_manager', area_ids: [A], person_id: null, revision: 1, workspace_digest: 'd'.repeat(64), phase: 'ready' }, handler = null;
    const file = { id: F, name: 'FAKE clean fixture.png', mime: 'image/png', size: data.length, original_sha256: hash, state: 'ready', preview_available: true, thumbnail: { sha256: hash, size: data.length, mime: 'image/png', width: 1, height: 1, renderer_sha256: 'e'.repeat(64) }, scan_expires_at: boot.reviewerImages.expires_at, attestation_id: S, attestation_revision: 2 };
    const listeners = new Set(), calls = [], mounts = [];
    const workspace = { mode: 'shared', snapshot: () => plain(state), subscribe: f => { listeners.add(f); return () => listeners.delete(f); } };
    const json = body => { const raw = JSON.stringify(body); return new Response(raw, { headers: { 'Content-Type': 'application/json', 'Content-Length': String(Buffer.byteLength(raw)) } }); };
    const metadata = t => ({ workspace_id: W, workspace_revision: state.revision, workspace_digest: state.workspace_digest, target: t, target_version: 1, files: [plain(file)], expires_at: boot.reviewerImages.expires_at });
    const binary = (kind = 'preview', values = {}) => new Response(data, { headers: { 'Content-Type': 'image/png', 'Content-Length': String(data.length), 'X-Everbranch-Image-Workspace': W, 'X-Everbranch-Image-Version': binding.version_hash, 'X-Everbranch-Image-Upload': F, 'X-Everbranch-Image-Original-SHA256': hash, 'X-Everbranch-Image-SHA256': hash, 'X-Everbranch-Image-Attestation': S, 'X-Everbranch-Image-Revision': '2', 'X-Everbranch-Image-Kind': kind, 'X-Everbranch-Image-Expires': file.scan_expires_at, ...values } });
    const server = async (u, i, b) => u.endsWith('/metadata') ? json(metadata(b.target)) : binary(u.endsWith('/thumbnail') ? 'thumbnail' : 'preview');
    const context = vm.createContext({ URL, TextEncoder, TextDecoder, AbortController, crypto: webcrypto, Blob, Uint8Array, Response, ReadableStream, setTimeout, clearTimeout, location: { origin: 'https://private.invalid', href: 'https://private.invalid/private' }, EverbranchAttachmentGallery: { mountWithLoader: (options, loader) => { const handle = { destroyed: false, closed: false, destroy() { this.destroyed = true; }, close() { this.closed = true; } }; mounts.push({ options, loader, handle }); return handle; } }, fetch: async (u, i) => { const b = JSON.parse(i.body); calls.push({ url: u, init: i, body: b }); return handler ? handler(u, i, b) : server(u, i, b); } });
    vm.runInContext(shared, context);
    vm.runInContext(source, context);
    const client = context.EverbranchWorkspaceImages.create(boot, workspace);
    return { client, boot, workspace, context, calls, mounts, file, data, json, binary, metadata, server, setHandler: h => handler = h, change: delta => { state = { ...state, ...delta }; listeners.forEach(f => f(state)); } };
}
const target = { kind: 'ticket', id: T };
async function mount(f) { const result = await f.client.mount({ host: {}, dialog: {}, target, current: () => true }); return { result, ...f.mounts.at(-1) }; }
test('closed own endpoints and current metadata cannot inherit native/sample authority', async () => {
    const f = fixture();
    assert.equal(f.calls.length, 0);
    await f.client.metadata(target);
    assert.equal(f.calls[0].init.method, 'POST');
    assert.equal(f.calls[0].init.cache, 'no-store');
    assert.equal(f.calls[0].init.headers['X-Team-Viewer'], undefined);
    for (const p of ['https://foreign.invalid/x', '/enterprise/native/files', '/private/workspace/images/preview?x=1']) {
        const b = plain(f.boot);
        b.reviewerImages.endpoints.preview = p;
        assert.throws(() => f.context.EverbranchWorkspaceImages.create(b, f.workspace));
    }
    f.client.destroy();
});
test('only actual validated ready object may invoke typed separate thumbnail/full loader', async () => {
    const f = fixture(), g = await mount(f), file = g.options.files[0];
    assert.equal(g.loader.eligible(file), true);
    assert.equal(g.loader.eligible({ ...file }), false);
    await assert.rejects(g.loader.fetch({ ...file }, {}, new AbortController().signal, 'preview'));
    assert.equal(f.calls.filter(c => !c.url.endsWith('/metadata')).length, 0);
    for (const kind of ['thumbnail', 'preview']) {
        const blob = await g.loader.fetch(file, {}, new AbortController().signal, kind);
        assert.equal(blob.type, 'image/png');
        assert.equal(blob.size, f.data.length);
    }
    assert.equal(f.calls.at(-1).body.attestation_revision, 2);
    f.client.destroy();
});
test('held uploads have no eligible file, thumbnail or full fetch', async () => { const f = fixture(); Object.assign(f.file, { state: 'held', preview_available: false, thumbnail: null, scan_expires_at: null, attestation_id: null, attestation_revision: null }); const g = await mount(f); assert.equal(g.options.files.length, 0); assert.equal(f.calls.length, 1); f.client.destroy(); });
test('wrong identity, digest, kind, MIME or length refuses binary rather than generating a blob', async () => {
    for (const change of [{ 'X-Everbranch-Image-Upload': id(9) }, { 'X-Everbranch-Image-SHA256': 'f'.repeat(64) }, { 'X-Everbranch-Image-Kind': 'thumbnail' }, { 'Content-Type': 'text/html' }, { 'Content-Length': '9' }]) {
        const f = fixture(), g = await mount(f);
        f.setHandler(() => f.binary('preview', change));
        await assert.rejects(g.loader.fetch(g.options.files[0], {}, new AbortController().signal, 'preview'));
        f.client.destroy();
    }
});
test('wrong actual body SHA refuses even when headers claim correct identity', async () => { const f = fixture(), g = await mount(f); f.setHandler(() => { const r = f.binary(); return new Response(new Uint8Array(f.data.length).fill(1), { headers: r.headers }); }); await assert.rejects(g.loader.fetch(g.options.files[0], {}, new AbortController().signal, 'preview')); f.client.destroy(); });
test('current grant403 remains denial and never falls back to a public/native URL', async () => { const f = fixture(), g = await mount(f); f.setHandler(() => new Response('{}', { status: 403 })); await assert.rejects(g.loader.fetch(g.options.files[0], {}, new AbortController().signal, 'preview'), e => e.status === 403); assert.ok(f.calls.every(c => c.url.startsWith('https://private.invalid/private/workspace/images/'))); f.client.destroy(); });
test('scope loss aborts response-body phase and withholds late bytes', async () => {
    const f = fixture(), g = await mount(f), hold = deferred();
    f.setHandler(() => { const response = f.binary(); return new Response(new ReadableStream({ async start(c) { await hold.promise; c.enqueue(f.data); c.close(); } }), { headers: response.headers }); });
    const loading = g.loader.fetch(g.options.files[0], {}, new AbortController().signal, 'preview');
    while (f.calls.length < 2)
        await turn();
    await turn();
    f.change({ area_ids: [B] });
    assert.equal(f.calls.at(-1).init.signal.aborted, true);
    hold.resolve();
    await assert.rejects(loading, e => e.code === 'image_context');
    assert.equal(g.handle.destroyed, true);
    f.client.destroy();
});
test('graph revision/digest or pending phase retires gallery without waiting for a timer', async () => {
    for (const delta of [{ revision: 2, workspace_digest: 'e'.repeat(64) }, { phase: 'unknown' }]) {
        const f = fixture(), g = await mount(f);
        f.change(delta);
        assert.equal(g.handle.destroyed, true);
        assert.equal(g.loader.eligible(g.options.files[0]), false);
        f.client.destroy();
    }
});
test('late metadata after target change or latest same-target refresh never replaces current view', async () => { const f = fixture(), hold = deferred(); let n = 0; f.setHandler((u, i, b) => ++n === 1 ? hold.promise : f.server(u, i, b)); const old = f.client.metadata(target); await turn(); await f.client.metadata(target); hold.resolve(f.json(f.metadata(target))); await assert.rejects(old, e => e.code === 'image_context'); f.client.destroy(); });
test('stale shared projection metadata pin is rejected with zero image fetches', async () => { const f = fixture(); f.setHandler((u, i, b) => f.json({ ...f.metadata(b.target), workspace_revision: 2 })); await assert.rejects(f.client.metadata(target)); assert.equal(f.calls.length, 1); f.client.destroy(); });
test('oversized streamed bytes are cancelled and never become a Blob', async () => { const f = fixture(), g = await mount(f); let cancelled = false; f.setHandler(() => new Response(new ReadableStream({ start(c) { c.enqueue(new Uint8Array(5242881)); }, cancel() { cancelled = true; } }), { headers: f.binary().headers })); await assert.rejects(g.loader.fetch(g.options.files[0], {}, new AbortController().signal, 'preview')); assert.equal(cancelled, true); f.client.destroy(); });
test('old scoped gallery handle cannot destroy or close a newer mounted gallery', async () => { const f = fixture(), one = await mount(f), two = await mount(f); one.result.destroy(); one.result.close(); assert.equal(two.handle.destroyed, false); assert.equal(two.handle.closed, false); two.result.destroy(); assert.equal(two.handle.destroyed, true); f.client.destroy(); });
test('scan freshness cannot outlive immutable image descriptor expiry', async () => { const f = fixture(); f.file.scan_expires_at = '2099-01-02T00:00:00Z'; await assert.rejects(f.client.metadata(target)); assert.equal(f.calls.filter(c => !c.url.endsWith('/metadata')).length, 0); f.client.destroy(); });
test('known attestation expiry retires cached media without treating it as recipient access denial', async () => {
    const f = fixture();
    f.file.scan_expires_at = new Date(Date.now() + 200).toISOString();
    let expired = 0, denied = 0;
    await f.client.mount({ host: {}, dialog: {}, target, current: () => true, onExpired: () => expired++, onDenied: () => denied++ });
    const g = f.mounts.at(-1);
    await new Promise(r => setTimeout(r, 260));
    assert.equal(expired, 1);
    assert.equal(denied, 0);
    assert.equal(g.handle.destroyed, true);
    assert.equal(g.loader.eligible(g.options.files[0]), false);
    assert.equal(f.calls.length, 1);
    f.client.destroy();
});
