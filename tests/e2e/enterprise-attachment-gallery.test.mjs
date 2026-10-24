import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/enterprise/attachment-gallery.js', import.meta.url), 'utf8');
const context = {mode: 'private', origin: 'https://campus.test', base: 'https://campus.test/enterprise-campus/enterprise',
    kind: 'request', id: 'request-a', tenant: 'campus-a', viewer: 'actor-a', workspace: 'workspace-a'};
const file = {id: 'file-a', status: 'ready', downloadable: true, mime_type: 'image/png', size: 4,
    preview_url: 'https://campus.test/enterprise-campus/enterprise/discussion/request/request-a/files/file-a/preview?tenant=campus-a'};
function fixture(fetch) {
    const sandbox = vm.createContext({URL, fetch}); vm.runInContext(source, sandbox);
    return sandbox.EverbranchAttachmentGallery;
}
function response({status = 200, type = 'image/png', size = 4, viewer = 'actor-a', workspace = 'workspace-a'} = {}) {
    const values = {'Content-Type': type, 'X-Team-Viewer': viewer, 'X-Team-Workspace': workspace};
    return {ok: status === 200, status, headers: {get: key => values[key]}, blob: async () => ({size, type})};
}
test('gallery accepts only current ready JPEG/PNG server links for this exact canonical thread mount and tenant', () => {
    const gallery = fixture();
    assert.equal(gallery.previewUrl(file, context), file.preview_url);
    assert.equal(gallery.previewUrl(file, {...context, base: '/enterprise-campus/enterprise'}), file.preview_url);
    for (const patch of [{status:'quarantined'}, {status:'uploading'}, {status:'rejected'}, {downloadable:false}, {mime_type:'image/svg+xml'},
        {mime_type:'application/pdf'}, {size:0}, {size:10485761}, {preview_url:null}, {id:'other-file'},
        {preview_url:file.preview_url.replace('campus.test','remote.test')}, {preview_url:file.preview_url.replace('request-a','request-b')},
        {preview_url:file.preview_url.replace('campus-a','campus-b')}, {preview_url:file.preview_url+'&tenant=campus-a'},
        {preview_url:file.preview_url+'#fragment'}, {preview_url:file.preview_url.replace('https://','https://user:pass@')}]) {
        assert.equal(gallery.previewUrl({...file,...patch}, context), null, JSON.stringify(patch));
    }
    assert.equal(gallery.previewUrl(file, {...context, mode:'sample'}), null);
    assert.equal(gallery.previewUrl(file, {...context, base:'/enterprise-owner/enterprise'}), null);
});
test('private image bytes use current identity fences, no browser cache, no redirects and caller cancellation', async () => {
    const calls=[], signal={aborted:false};
    const gallery=fixture(async (...args) => {calls.push(args); return response();});
    assert.equal((await gallery.fetchImage(file,context,signal)).size,4);
    assert.equal(calls[0][0],file.preview_url);
    const options=calls[0][1];
    assert.equal(options.credentials,'same-origin'); assert.equal(options.cache,'no-store'); assert.equal(options.redirect,'error'); assert.equal(options.signal,signal);
    assert.equal(options.headers['X-Team-Viewer'],'actor-a'); assert.equal(options.headers['X-Team-Workspace'],'workspace-a');
    assert.equal(options.headers.Accept,'image/png');
});
test('quarantine sample and changed thread are refused before any network or blob access', async () => {
    let calls=0; const gallery=fixture(async()=>{calls++;return response();});
    for(const [entry, scope] of [[{...file,status:'quarantined'},context],[file,{...context,mode:'sample'}],[file,{...context,id:'other'}],[file,{...context,viewer:null}]]) {
        await assert.rejects(gallery.fetchImage(entry,scope), /unavailable/);
    }
    assert.equal(calls,0);
});
test('revocation identity changes unexpected MIME and truncated bytes fail closed without returning bytes', async () => {
    for(const patch of [{status:403},{status:404},{viewer:'other'},{workspace:'other'},{type:'text/html'},{type:'image/jpeg'},{size:3},{size:10485761}]) {
        const gallery=fixture(async()=>response(patch));
        await assert.rejects(gallery.fetchImage(file,context), /available|changed|verified/, JSON.stringify(patch));
    }
});
