import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../resources/js/enterprise/transport.js', import.meta.url), 'utf8');
function environment(extra = {}) {
    const calls = [];
    const context = vm.createContext({URL, location: {href: 'https://enterprise.test/?runtime=sample', origin: 'https://enterprise.test', assign: url => calls.push(['navigate', url])}, history: {replaceState: (...args) => calls.push(['replace', ...args])}, fetch: async (...args) => {calls.push(args); return {ok: true, json: async () => ({saved: true})};}, ...extra});
    vm.runInContext(source, context);
    return {context, calls, create: boot => context.EverbranchTransport.create(boot)};
}
const boot = {identity: {viewer: 'user-a', workspace: 'tenant-a'}, csrf: 'csrf-a'};

test('private transport ignores URL mode and preserves identity and exact command receipt/version', async () => {
    const {create, calls} = environment();
    const transport = create(boot), body = {request_id: 'stable-key', expected_version: 7, action: 'request.progress'};
    assert.equal(transport.mode, 'private');
    await transport.request('/enterprise/commands?tenant=workspace-a', body);
    const [url, request] = calls[0];
    assert.equal(url, 'https://enterprise.test/enterprise/commands?tenant=workspace-a');
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf-a');
    assert.equal(request.headers['X-Team-Viewer'], 'user-a');
    assert.equal(request.headers['X-Team-Workspace'], 'tenant-a');
    assert.deepEqual(JSON.parse(request.body), body);
});
test('private transport rejects remote URLs and missing identity before HTTP', async () => {
    const {create, calls} = environment();
    await assert.rejects(create(boot).request('https://remote.test/enterprise/data'), /current application/);
    await assert.rejects(create({}).request('/enterprise/data'), /identity/);
    assert.equal(calls.length, 0);
});

test('update lifecycle observes concurrent writes until network and response parsing settle including uncertain errors', async () => {
    const pending = [];
    const {context, create} = environment({fetch: () => new Promise((resolve, reject) => pending.push({resolve, reject}))});
    const transport = create(boot);
    const first = transport.request('/enterprise/commands', {request_id: 'one'});
    const second = transport.request('/team/file-uploads/a/chunks/0', new Uint8Array([1]), {method: 'PUT', binary: true});
    assert.equal(context.EverbranchTransport.pendingWrites, 2);
    pending[0].resolve({ok: true, json: async () => ({saved: true})});
    await first;
    assert.equal(context.EverbranchTransport.pendingWrites, 1);
    const uncertain = assert.rejects(second, /Connection interrupted/);
    pending[1].reject(new Error('Connection interrupted'));
    await uncertain;
    assert.equal(context.EverbranchTransport.pendingWrites, 0);
    const read = transport.request('/enterprise/data');
    assert.equal(context.EverbranchTransport.pendingWrites, 0);
    pending[2].resolve({ok: true, json: async () => ({items: []})});
    await read;
});
test('private transport passes exact binary bytes and exposes denial status', async () => {
    const {create, calls} = environment();
    const bytes = new Uint8Array([0, 8, 255]);
    await create(boot).request('/team/file-uploads/a/chunks/0', bytes, {method: 'PUT', binary: true});
    assert.equal(calls[0][1].body, bytes);
    assert.equal(calls[0][1].headers['Content-Type'], 'application/octet-stream');
    assert.equal(calls[0][1].method, 'PUT');
    const denied = environment({fetch: async () => ({ok: false, status: 403, json: async () => ({message: 'Permission changed.'})})});
    await assert.rejects(denied.create(boot).request('/enterprise/data'), error => error.status === 403 && error.message === 'Permission changed.');
});

test('refresh blockers outlive settled uncertain HTTP and remain independent across operations', async () => {
    const {context, create} = environment({fetch: async () => { throw new Error('Uncertain outcome'); }});
    const lifecycle = context.EverbranchTransport;
    lifecycle.setRefreshBlocker('workspace-command', true);
    lifecycle.setRefreshBlocker('planning:area-a', true);
    lifecycle.setRefreshBlocker('workspace-command', true);
    assert.equal(lifecycle.refreshBlockers, 2);
    await assert.rejects(create(boot).request('/enterprise/commands', {request_id: 'retain-exact'}), /Uncertain/);
    assert.equal(lifecycle.pendingWrites, 0);
    assert.equal(lifecycle.refreshBlockers, 2);
    lifecycle.setRefreshBlocker('workspace-command', false);
    assert.equal(lifecycle.refreshBlockers, 1);
    lifecycle.setRefreshBlocker('planning:area-a', false);
    lifecycle.setRefreshBlocker('planning:area-a', false);
    assert.equal(lifecycle.refreshBlockers, 0);
});
test('sample mode fails closed without its explicit adapter and rejects unknown runtimes', () => {
    const {create, calls} = environment();
    assert.throws(() => create({...boot, runtime: 'sample'}), /sample adapter/);
    assert.throws(() => create({...boot, runtime: 'other'}), /Unsupported/);
    const partial = environment({EverbranchSampleTransport: {create: () => ({mode: 'sample', request: async () => ({})})}});
    assert.throws(() => partial.create({...boot, runtime: 'sample'}), /sample adapter/);
    assert.equal(calls.length, 0);
});
test('sample requests and navigation use only the supplied sample adapter', async () => {
    const requests = [], navigation = [];
    const {create, calls} = environment({EverbranchSampleTransport: {create: input => ({mode: 'sample', request: (...args) => {requests.push([input.runtime, ...args]); return Promise.resolve({sample: true});}, href: url => '#sample:' + url, navigate: url => navigation.push(url), replace: url => navigation.push(url)})}});
    const transport = create({...boot, runtime: 'sample'});
    assert.equal((await transport.request('/enterprise/commands', {request_id: 'sample-key'})).sample, true);
    assert.equal(requests[0][0], 'sample');
    assert.equal(transport.href('/projects/workspace'), '#sample:/projects/workspace');
    transport.navigate('/projects/workspace'); transport.replace('/?section=home');
    assert.deepEqual(navigation, ['/projects/workspace', '/?section=home']);
    assert.equal(calls.length, 0);
});
