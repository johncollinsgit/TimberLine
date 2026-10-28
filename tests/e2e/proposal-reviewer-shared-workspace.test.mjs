import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash, webcrypto } from 'node:crypto';
import vm from 'node:vm';
const source = await readFile(new URL('../../resources/js/enterprise/reviewer/shared-workspace.js', import.meta.url), 'utf8');
const plain = x => JSON.parse(JSON.stringify(x));
const id = n => `${String(n).padStart(8, '0')}-0000-4000-8000-000000000001`;
const A = id(1), B = id(2), P = id(3), T = id(4), Q = id(5), L = id(6), C = id(7), D = id(8), W = id(9);
const binding = { version_hash: 'a'.repeat(64), build_id: 'synthetic-review-v1', source_hash: 'b'.repeat(64) };
const selection = { persona: 'demo_manager', area_ids: [A], person_id: null };
const response = (value, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => plain(value) });
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const turn = () => new Promise(r => setImmediate(r));
function fixture(options = {}) {
    const values = options.values || new Map(), calls = [], receipts = new Map();
    let handler = null, revision = 1, writes = 0, storageMode = null;
    const storage = { getItem: k => {
            if (storageMode === 'read')
                throw Error('RAW PRIVATE STORAGE MESSAGE');
            return values.get(k) ?? null;
        }, setItem: (k, v) => {
            if (storageMode === 'throw')
                throw Error('RAW PRIVATE STORAGE MESSAGE');
            if (storageMode === 'false')
                return false;
            values.set(k, v);
        }, removeItem: k => {
            if (storageMode === 'remove')
                throw Error('RAW PRIVATE STORAGE MESSAGE');
            values.delete(k);
        } };
    const boot = { identity: { tenantSlug: 'fictional' }, routes: { back: 'https://workspace.invalid/private/review/campus', data: '/enterprise/data?tenant=fictional', enterprise: '/enterprise' }, reviewerWorkspace: { enabled: true, schema_version: 1, workspace_id: W, browser_context: '8'.repeat(64), seed_sha256: 'c'.repeat(64), policy_sha256: 'd'.repeat(64), expires_at: '2099-01-01T00:00:00Z', binding, selection, endpoints: { projection: '/private/review/workspace/projection', commands: '/private/review/workspace/commands', status: '/private/review/workspace/command-status', facts: '/private/review/workspace/facts' }, csrf: 'synthetic-noncredential-csrf' } };
    const globalActions = ['project.create', 'project.update', 'project.archive', 'job.create', 'task.create', 'task.update', 'task.assign', 'task.status', 'task.archive', 'ticket.create', 'ticket.update', 'ticket.classify', 'ticket.assign', 'ticket.plan', 'ticket.status', 'ticket.reorder', 'plan.create', 'plan.update', 'plan.cancel'];
    const base = { schema_version: 1, areas: [{ id: A, name: 'Fictional facilities', visibility: 'shared' }, { id: B, name: 'Fictional events', visibility: 'shared' }], buildings: [{ id: L, area_id: A, title: 'Fictional hall', version: 1, status: 'active', updated_at: null }], people: [{ id: '1', name: 'Fictional coordinator', version: 1, area_ids: [A, B], active: true, updated_at: null }, { id: '2', name: 'Fictional worker', version: 1, area_ids: [A], active: true, updated_at: null }], projects: [{ id: P, area_id: A, title: 'Fictional roof', description: 'Retained fictional description', version: 1, status: 'active', owner_id: '1', due_on: null, updated_at: null, allowed_actions: ['project.update', 'project.archive'] }], tasks: [{ id: T, project_id: P, area_id: A, title: 'Fictional inspection', description: 'Fictional task description', version: 1, status: 'active', priority: 'high', due_on: null, assignee_id: '2', building_id: L, updated_at: null, allowed_actions: ['task.update', 'task.assign', 'task.status', 'task.archive'] }], tickets: [{ id: Q, area_id: A, title: 'Fictional request', description: 'Fictional queue description', version: 1, status: 'assigned', priority: 'normal', due_on: null, building_id: L, project_id: P, task_id: T, position: 0, type: 'maintenance', updated_at: null, allowed_actions: ['ticket.update', 'ticket.classify', 'ticket.assign', 'ticket.plan', 'ticket.status', 'ticket.reorder'], owned_by_caller: false }], contractors: [{ id: C, area_id: A, title: 'Fictional roofing crew', version: 1, status: 'active', updated_at: null }], plans: [{ id: D, area_id: A, project_id: P, contractor_id: C, version: 1, status: 'planned', starts_on: '2026-11-01', ends_on: '2026-11-03', updated_at: null, allowed_actions: ['plan.update', 'plan.cancel'] }], costs: [] };
    function project(sel = selection) {
        const graph = plain(base);
        for (const k of Object.keys(graph)) {
            if (k === 'schema_version')
                continue;
            graph[k] = graph[k].filter(r => k === 'areas' ? sel.area_ids.includes(r.id) : k === 'people' ? r.area_ids.some(a => sel.area_ids.includes(a)) : sel.area_ids.includes(r.area_id));
        }
        return { workspace_id: W, revision, workspace_digest: 'e'.repeat(64), selection: plain(sel), allowed_areas: plain(base.areas), graph, permitted_actions: [...globalActions], capabilities: { can_view_costs: true, can_manage_plans: true, can_manage_costs: false }, origin: 'server_shared_fictional', expires_at: boot.reviewerWorkspace.expires_at };
    }
    const context = vm.createContext({ URL, URLSearchParams, TextEncoder, TextDecoder, AbortController, crypto: options.crypto || webcrypto, sessionStorage: storage, location: { href: boot.routes.back, origin: 'https://workspace.invalid', assign: value => { context.destination = value; } }, history: { replaceState: (a, b, url) => { context.destination = url; } }, fetch: async (url, init) => {
            const body = JSON.parse(init.body);
            calls.push({ url, init, body });
            if (handler)
                return handler(url, init, body);
            if (url.endsWith('/projection'))
                return response(project(body.selection));
            if (url.endsWith('/command-status')) {
                const prior = receipts.get(body.request_id);
                return prior ? response({ status: 'applied', settled: true, receipt: prior, projection: project(body.selection) }) : response({ status: 'not_observed', settled: false });
            }
            if (url.endsWith('/commands')) {
                let receipt = receipts.get(body.request_id);
                const replayed = !!receipt;
                if (!receipt) {
                    writes++;
                    revision++;
                    if (body.action === 'project.update') {
                        base.projects[0].title = body.payload.title;
                        base.projects[0].version++;
                    }
                    receipt = { request_id: body.request_id, record_id: body.record_id, revision, digest: createHash('sha256').update(init.body).digest('hex') };
                    receipts.set(body.request_id, receipt);
                }
                return response({ status: 'applied', replayed, receipt, projection: project(body.selection) });
            }
            throw Error('Unexpected outbound route');
        } });
    vm.runInContext(source, context);
    const factory = context.EverbranchSharedWorkspace, adapter = factory.create(boot);
    return { adapter, factory, boot, base, project, calls, values, receipts, context, setHandler: h => { handler = h; }, setStorage: v => { storageMode = v; }, setRevision: n => { revision = n; }, get writes() { return writes; } };
}
async function edit(f, number = 20) { const view = await f.adapter.readSelection(selection), pin = f.adapter.pin(view); return f.adapter.prepareCommand(pin, { request_id: id(number), action: 'project.update', record_id: P, expected_record_version: 1, payload: { title: 'Reviewed fictional change', description: 'Fictional description', owner_id: '1', due_on: null } }); }
const expectError = async (p, code, outcome) => assert.rejects(p, e => e.code === code && (!outcome || e.outcome === outcome));
test('trusted boot has one active adapter; missing or malformed shared descriptor never instantiates a local sample', () => { const f = fixture(); assert.equal(f.factory.create(f.boot), f.adapter); assert.throws(() => f.factory.create({ routes: f.boot.routes }), /unavailable/); const bad = plain(f.boot); bad.reviewerWorkspace.endpoints.commands = 'https://other.invalid/commands'; assert.throws(() => f.factory.create(bad)); assert.equal(f.calls.length, 0); assert.equal(Object.isFrozen(f.factory.actions['job.create']), true); });
test('reads send only selection and immutable binding to exact private POST endpoint with CSRF', async () => { const f = fixture(), v = await f.adapter.readSelection(selection); assert.equal(v.origin, 'server_shared_fictional'); const c = f.calls[0]; assert.deepEqual(Object.keys(c.body).sort(), ['binding', 'selection']); assert.equal(c.init.credentials, 'same-origin'); assert.equal(c.init.redirect, 'error'); assert.equal(c.init.cache, 'no-store'); assert.equal(c.init.headers['X-CSRF-TOKEN'], 'synthetic-noncredential-csrf'); assert.equal(c.url, 'https://workspace.invalid/private/review/workspace/projection'); assert.equal(f.writes, 0); });
test('closed current read mapping preserves joins, queue rank, plans, drafts and separate cost read/edit flags', async () => { const f = fixture(), v = await f.adapter.readSelection(selection), d = f.adapter.data(v, A), p = f.adapter.planning(v, A); assert.equal(d.projects[0].tasks[0].linked_request.id, Q); assert.equal(d.requests[0].data.location_id, L); assert.equal(d.requests[0].data.location_version, 1); assert.equal(d.requests[0].task.assignee_id, '2'); assert.equal(d.projects[0].updated_at, null); assert.equal(p.can_finance, true); assert.equal(p.can_manage_costs, false); assert.equal(p.can_coordinate, true); assert.equal(p.items.find(r => r.kind === 'schedule').data.contractor_id, C); assert.equal(d.features.collaboration, false); assert.equal(f.values.size, 0); });
test('unknown native/file/provider paths and binary writes fail before any HTTP or local fallback', async () => {
    const f = fixture();
    for (const path of ['/team/tasks/x/files', '/enterprise/commands', '/enterprise/workflows', '/enterprise/resources', '/enterprise/discussion/project/x', 'https://other.invalid/enterprise/data?area_id=' + A])
        await expectError(f.adapter.request(path, {}), 'workspace_unsupported');
    assert.equal(f.calls.length, 0);
    await expectError(f.adapter.request('/enterprise/data?tenant=foreign&area_id=' + A), 'workspace_unsupported');
    assert.equal(f.calls.length, 0);
});
test('compatibility data/planning reads use server directory and never mutate active scope for a background area', async () => { const f = fixture(), out = await f.adapter.request('/enterprise/data?tenant=fictional&area_id=' + B); assert.equal(out.selected_area_id, B); assert.equal(out.areas.length, 2); assert.equal(f.adapter.snapshot().area_ids[0], A); assert.equal(f.calls[0].url.endsWith('/projection'), true); });
test('malformed or different workspace/selection/origin/graph/financial response fails closed', async () => {
    for (const alter of [v => v.workspace_id = id(99), v => v.origin = 'browser_sample', v => v.graph.tasks[0].project_id = id(99), v => v.graph.tasks[0].secret = 'excluded', v => v.capabilities.can_manage_costs = true, v => v.selection.area_ids = [B]]) {
        const f = fixture();
        f.setHandler(() => { const v = f.project(); alter(v); return response(v); });
        await assert.rejects(f.adapter.readSelection(selection));
        assert.equal(f.adapter.snapshot().revision, null);
    }
});
test('captured form revision remains exact after a newer background read; no automatic upgrade', async () => { const f = fixture(), p = await edit(f); f.setRevision(2); await f.adapter.readSelection(selection); assert.equal(p.command.expected_revision, 1); assert.equal(f.adapter.snapshot().revision, 2); assert.equal(Object.isFrozen(p.command.payload), true); });
test('all composite IDs and current FK versions are explicit and retained in one command', async () => { const f = fixture(), v = await f.adapter.readSelection(selection), pin = f.adapter.pin(v); const p = await f.adapter.prepareCommand(pin, { request_id: id(21), action: 'job.create', record_id: id(22), expected_record_version: 0, payload: { area_id: A, title: 'Fictional project', description: 'Fictional work', task_title: 'Fictional task', owner_id: null, task_id: id(23), ticket_id: id(24), assignee_id: '2', building_id: L, building_version: 1, priority: 'normal', due_on: null, type: 'general' } }); await f.adapter.send(p); assert.equal(f.calls.filter(c => c.url.endsWith('/commands')).length, 1); assert.equal(f.calls.at(-1).body.payload.task_id, id(23)); assert.equal(f.calls.at(-1).body.payload.building_version, 1); });
test('no cost save, timestamp field, missing FK version, native ID coercion or new unreviewed field is accepted', async () => {
    const f = fixture(), v = await f.adapter.readSelection(selection), pin = f.adapter.pin(v);
    for (const input of [{ request_id: id(25), action: 'cost.create', record_id: id(26), expected_record_version: 0, payload: {} }, { request_id: id(25), action: 'task.assign', record_id: T, expected_record_version: 1, payload: { assignee_id: 2 } }, { request_id: id(25), action: 'project.archive', record_id: P, expected_record_version: 1, payload: { archived: true, updated_at: '2099-01-01T00:00:00Z' } }])
        await assert.rejects(f.adapter.prepareCommand(pin, input));
    assert.equal(f.calls.length, 1);
});
test('exact edit is durable before command IO and clears only after verified apply', async () => { const f = fixture(), p = await edit(f); let storedAtDispatch = false; f.setHandler((url, init, body) => { storedAtDispatch = [...f.values.values()].some(s => JSON.parse(s).pending?.digest === p.digest); return response({ status: 'applied', replayed: false, receipt: { request_id: body.request_id, record_id: P, revision: 2, digest: p.digest }, projection: { ...f.project(), revision: 2 } }); }); const out = await f.adapter.send(p); assert.equal(storedAtDispatch, true); assert.equal(out.status, 'applied'); assert.equal(f.adapter.pending(), null); assert.equal(f.adapter.draft(), null); });
test('storage false/throw before dispatch makes zero command requests and exposes only sanitized message', async () => {
    for (const failure of ['false', 'throw']) {
        const f = fixture(), p = await edit(f);
        f.setStorage(failure);
        await assert.rejects(f.adapter.send(p), e => e.code === 'workspace_storage' && e.outcome === 'not_submitted' && !e.message.includes('RAW'));
        assert.equal(f.calls.filter(c => c.url.endsWith('/commands')).length, 0);
        assert.equal(f.adapter.pending(), null);
    }
});
test('unknown and not_observed retain same pending edit; explicit exact Retry applies once, never automatically', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => { throw Error('Network failed'); }); await expectError(f.adapter.send(p), 'workspace_unknown', 'unknown'); assert.equal(f.adapter.pending().digest, p.digest); const before = f.calls.length; f.setHandler(null); const found = await f.adapter.recover(); assert.equal(found.settled, false); assert.equal(f.adapter.pending().command.request_id, p.command.request_id); assert.equal(f.calls.length, before + 1); const out = await f.adapter.retry(p); assert.equal(out.status, 'applied'); assert.equal(f.writes, 1); assert.equal(f.adapter.pending(), null); });
test('same ID with altered body/digest cannot replace an unknown exact edit', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => { throw Error('lost'); }); await assert.rejects(f.adapter.send(p)); const bad = plain(p); bad.command.payload.title = 'Unreviewed change'; await assert.rejects(f.adapter.retry(bad)); assert.equal(f.adapter.pending().command.payload.title, 'Reviewed fictional change'); assert.equal(f.calls.length, 2); });
test('first exact definitive rejection needs explicit acknowledgment, retains draft and content-free tombstone', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => response({ error: 'workspace_conflict', automatic_retry: false, definitive_not_applied: true, request_id: p.command.request_id, digest: p.digest, workspace_id: W }, 409)); await expectError(f.adapter.send(p), 'workspace_conflict', 'rejected'); assert.ok(f.adapter.pending()); const draft = f.adapter.acknowledgeRejection(p); assert.equal(draft.payload.title, 'Reviewed fictional change'); assert.equal(f.adapter.pending(), null); const saved = JSON.parse([...f.values.values()][0]); assert.deepEqual(Object.keys(saved.tombstones[0]).sort(), ['digest', 'kind', 'request_id']); await assert.rejects(f.adapter.send(p)); });
test('a prior unknown is never settled by later exact409 or opaque/forged rejection', async () => {
    for (const forged of [false, true]) {
        const f = fixture(), p = await edit(f);
        f.setHandler(() => { throw Error('lost'); });
        await assert.rejects(f.adapter.send(p));
        f.setHandler(() => response({ error: 'workspace_conflict', automatic_retry: false, definitive_not_applied: true, request_id: p.command.request_id, digest: forged ? 'f'.repeat(64) : p.digest, workspace_id: W }, 409));
        await expectError(f.adapter.retry(p), 'workspace_unknown', 'unknown');
        assert.ok(f.adapter.pending());
        assert.throws(() => f.adapter.acknowledgeRejection(p));
    }
});
test('forged applied receipt does not clear pending or expose returned graph', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => response({ status: 'applied', replayed: false, receipt: { request_id: p.command.request_id, record_id: P, revision: 2, digest: 'f'.repeat(64) }, projection: { ...f.project(), revision: 2 } })); await expectError(f.adapter.send(p), 'workspace_unknown', 'unknown'); assert.equal(f.adapter.pending().digest, p.digest); });
test('receipt-only recovery confirms an already applied exact write after lost response without redispatch', async () => { const f = fixture(), p = await edit(f); f.setHandler((url, init, body) => { f.receipts.set(body.request_id, { request_id: body.request_id, record_id: P, revision: 2, digest: p.digest }); f.setRevision(2); throw Error('lost response after commit'); }); await assert.rejects(f.adapter.send(p)); f.setHandler(null); const before = f.calls.filter(c => c.url.endsWith('/commands')).length; assert.equal((await f.adapter.recover()).settled, true); assert.equal(f.calls.filter(c => c.url.endsWith('/commands')).length, before); assert.equal(f.adapter.pending(), null); });
test('remount restores only pending edit as unknown, never graph or automatic dispatch', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => { throw Error('lost'); }); await assert.rejects(f.adapter.send(p)); f.adapter.destroy(); const remount = f.factory.create(f.boot); assert.notEqual(remount, f.adapter); assert.equal(remount.snapshot().revision, null); assert.equal(remount.pending().digest, p.digest); assert.equal(f.calls.length, 2); });
test('older delayed read cannot replace newer revision; navigation invalidation withholds response', async () => { const f = fixture(), hold = deferred(); let n = 0; f.setHandler(() => ++n === 1 ? hold.promise : response({ ...f.project(), revision: 2 })); const old = f.adapter.readSelection(selection); await turn(); await f.adapter.readSelection(selection); hold.resolve(response(f.project())); await expectError(old, 'workspace_context'); assert.equal(f.adapter.snapshot().revision, 2); const later = deferred(); f.setHandler(() => later.promise); const read = f.adapter.readSelection(selection); await turn(); f.adapter.invalidate(); later.resolve(response(f.project())); await expectError(read, 'workspace_context'); assert.equal(f.adapter.snapshot().revision, null); });
test('late apply after selection change is content-free but still confirms matching receipt', async () => {
    const f = fixture(), p = await edit(f), hold = deferred();
    f.setHandler(() => hold.promise);
    const action = f.adapter.send(p);
    while (!f.calls.some(c => c.url.endsWith('/commands')))
        await turn();
    f.adapter.setSelection({ ...selection, area_ids: [B] });
    hold.resolve(response({ status: 'applied', replayed: false, receipt: { request_id: p.command.request_id, record_id: P, revision: 2, digest: p.digest }, projection: { ...f.project(), revision: 2 } }));
    const out = await action;
    assert.equal(out.stale_context, true);
    assert.equal('projection' in out, false);
    assert.equal(f.adapter.pending(), null);
    assert.equal(f.adapter.snapshot().area_ids[0], B);
});
test('late old cleanup cannot erase new remounted ownership in storage', async () => {
    const f = fixture(), p = await edit(f), hold = deferred();
    f.setHandler(() => hold.promise);
    const old = f.adapter.send(p);
    while (!f.calls.some(c => c.url.endsWith('/commands')))
        await turn();
    f.adapter.destroy();
    const remount = f.factory.create(f.boot);
    const key = [...f.values.keys()][0], foreign = JSON.parse(f.values.get(key));
    foreign.pending.command.request_id = id(88);
    foreign.pending.digest = 'f'.repeat(64);
    f.values.set(key, JSON.stringify(foreign));
    hold.resolve(response({ status: 'applied', replayed: false, receipt: { request_id: p.command.request_id, record_id: P, revision: 2, digest: p.digest }, projection: { ...f.project(), revision: 2 } }));
    const out = await old;
    assert.equal(out.stale_context, true);
    assert.equal(JSON.parse(f.values.get(key)).pending.command.request_id, id(88));
    assert.ok(remount.pending());
});
test('optional storage cleanup failure preserves confirmed apply, never mislabels it unknown', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => { f.setStorage('throw'); return response({ status: 'applied', replayed: false, receipt: { request_id: p.command.request_id, record_id: P, revision: 2, digest: p.digest }, projection: { ...f.project(), revision: 2 } }); }); assert.equal((await f.adapter.send(p)).status, 'applied'); assert.equal(f.adapter.snapshot().phase, 'applied'); assert.equal(f.adapter.pending(), null); });
test('two independent fake clients observe common server data rather than browser-local state', async () => { const a = fixture(), b = fixture(); let serverTitle = 'Shared server title', revision = 1; const h = f => (url, init, body) => { const v = f.project(body.selection); v.revision = revision; v.graph.projects[0].title = serverTitle; return response(v); }; a.setHandler(h(a)); b.setHandler(h(b)); assert.equal((await a.adapter.readSelection(selection)).graph.projects[0].title, 'Shared server title'); serverTitle = 'Changed by another client'; revision++; assert.equal((await b.adapter.readSelection(selection)).graph.projects[0].title, 'Changed by another client'); assert.equal(a.values.size, 0); assert.equal(b.values.size, 0); });
test('same-origin navigation is restricted to current publication; no native/source URL traversal', () => {
    const f = fixture();
    assert.match(f.adapter.href(f.boot.routes.back + '#section=projects&area_id=' + A), /#section=projects/);
    for (const url of ['https://elsewhere.invalid', '/team', '/enterprise', f.boot.routes.back + '?tenant=native', f.boot.routes.back + '#section=workflows'])
        assert.throws(() => f.adapter.href(url));
});
test('command digest preserves object/list shape, Unicode, slash and line terminators with no rejected raw values', async () => { const f = fixture(), input = { payload: { text: 'Fictional café / hall\u2028line\u2029🙂' }, selection: { area_ids: [A] }, binding: { source_hash: 'a' } }; assert.equal(f.factory.canonical(input), JSON.stringify({ binding: { source_hash: 'a' }, payload: { text: 'Fictional café / hall\u2028line\u2029🙂' }, selection: { area_ids: [A] } })); assert.equal(f.factory.canonical({ payload: {} }), '{"payload":{}}'); assert.equal(f.factory.canonical({ payload: [] }), '{"payload":[]}'); assert.equal(await f.factory.digest(input), createHash('sha256').update(f.factory.canonical(input)).digest('hex')); assert.throws(() => f.factory.canonical({ secret: undefined })); assert.throws(() => f.factory.canonical({ value: NaN })); });
function facts(f, revision = 1) { return { snapshot: { schema_version: 2, persona: 'demo_manager', local_revision: revision, area_ids: [A], sources: [], coverage: 'Fictional proof only' }, pin: { workspace_id: W, revision, workspace_digest: 'e'.repeat(64), selection_digest: 'f'.repeat(64), snapshot_digest: 'a'.repeat(64), policy_hash: 'd'.repeat(64), capability_hash: 'b'.repeat(64), version_hash: binding.version_hash, build_id: binding.build_id, source_hash: binding.source_hash } }; }
test('facts use a fourth fixed read endpoint and only binding/selection, without modifying ordinary current projection', async () => { const f = fixture(); await f.adapter.readSelection(selection); f.setHandler((url, init, body) => { assert.equal(url, 'https://workspace.invalid/private/review/workspace/facts'); assert.deepEqual(Object.keys(body).sort(), ['binding', 'selection']); return response(facts(f, 2)); }); const result = await f.adapter.readFacts(selection); assert.equal(result.pin.revision, 2); assert.equal(f.adapter.snapshot().revision, 1); assert.equal(f.values.size, 0); });
test('facts wrong workspace/policy or late selection callback is withheld without local fallback', async () => { const f = fixture(); f.setHandler(() => { const v = facts(f); v.pin.workspace_id = id(99); return response(v); }); await assert.rejects(f.adapter.readFacts(selection)); const hold = deferred(); f.setHandler(() => hold.promise); const pending = f.adapter.readFacts(selection); await turn(); f.adapter.setSelection({ ...selection, area_ids: [B] }); hold.resolve(response(facts(f))); await expectError(pending, 'workspace_context'); assert.equal(f.adapter.snapshot().revision, null); });
test('oversized streamed response is cancelled before JSON parsing, never a successful cached read', async () => { const f = fixture(); let cancelled = false; f.setHandler(() => ({ ok: true, status: 200, body: { getReader: () => ({ read: async () => ({ done: false, value: new Uint8Array(524289) }), cancel: async () => { cancelled = true; }, releaseLock() { } }) } })); await expectError(f.adapter.readSelection(selection), 'workspace_unknown'); assert.equal(cancelled, true); assert.equal(f.adapter.snapshot().revision, null); });
test('async prepared-command verification captures immutable body before any await', async () => { const f = fixture(), p = await edit(f), mutable = plain(p); const action = f.adapter.send(mutable); mutable.command.payload.title = 'Late changed unreviewed content'; await action; assert.equal(f.calls.at(-1).body.payload.title, 'Reviewed fictional change'); });
test('cleanup/remount and context movement before dispatch never send a stale new edit', async () => { const f = fixture(), p = await edit(f); const attempt = f.adapter.send(p); f.adapter.invalidate(); await expectError(attempt, 'workspace_context', 'not_submitted'); assert.equal(f.calls.filter(c => c.url.endsWith('/commands')).length, 0); assert.equal(f.adapter.pending(), null); });
test('unknown storage cannot be silently overwritten by a concurrent owner', async () => { const f = fixture(), p = await edit(f); f.values.set('everbranch-shared-workspace:' + W + ':' + binding.version_hash + ':' + '8'.repeat(64), 'foreign retained receipt'); await expectError(f.adapter.send(p), 'workspace_storage', 'not_submitted'); assert.equal(f.calls.filter(c => c.url.endsWith('/commands')).length, 0); });
test('trusted boot logical aliases are captured once and cannot widen unsupported routing later', async () => { const f = fixture(); f.boot.routes.data = '/team/tasks/secret'; await expectError(f.adapter.request('/team/tasks/secret?tenant=fictional&area_id=' + A), 'workspace_unsupported'); assert.equal(f.calls.length, 0); });
test('frozen actual PHP commandCanonical vectors include empty object and real U2028 U2029 Unicode', async () => {
    const f = fixture();
    const base = { binding: { version_hash: 'a'.repeat(64), build_id: 'fictional', source_hash: 'b'.repeat(64) }, selection: { persona: 'manager', area_ids: ['81000000-0000-4000-8000-000000000001'], person_id: null }, request_id: '89000000-0000-4000-8000-000000000001', expected_revision: 1, action: 'plan.cancel', record_id: '87000000-0000-4000-8000-000000000001', expected_record_version: 1, payload: {} };
    const rows = [[base, '7fd10cf0b98e5a9458532871876c6302b77f02d233ccb1df54cbc43ae2ab0206'], [{ ...base, action: 'project.update', payload: { title: 'Fictional café ⚡ / north' + String.fromCodePoint(0x2028) + 'hall' + String.fromCodePoint(0x2029) + '🙂', description: 'A/B', owner_id: null, due_on: null } }, 'b9b42051766e59ee5012380322df001a28d7868724ef1e35097fca206f149eb2'], [{ ...base, selection: { ...base.selection, area_ids: ['82000000-0000-4000-8000-000000000001', '81000000-0000-4000-8000-000000000001'] } }, '576160e63ddf72728a8ab41120b104db5d6615c58e6bef0e9b61bc9a6576cc66']];
    for (const [value, expected] of rows)
        assert.equal(await f.factory.digest(value), expected);
});
test('first forged rejection and generic failure remain unknown without acknowledgment authority', async () => {
    for (const failure of ['forged', 'opaque', 'nonJSON']) {
        const f = fixture(), p = await edit(f);
        f.setHandler(() => failure === 'nonJSON' ? { ok: false, status: 500, json: async () => { throw Error('SECRET RESPONSE'); } } : response(failure === 'forged' ? { error: 'workspace_conflict', automatic_retry: false, definitive_not_applied: true, request_id: id(99), digest: p.digest, workspace_id: W } : { error: 'other' }, 409));
        await expectError(f.adapter.send(p), 'workspace_unknown', 'unknown');
        assert.throws(() => f.adapter.acknowledgeRejection(p));
        assert.equal(f.adapter.pending().digest, p.digest);
    }
});
test('read network failures are sanitized and leave explicit unavailable state rather than endless loading', async () => { const f = fixture(); f.setHandler(() => { throw Error('SECRET private endpoint detail'); }); await assert.rejects(f.adapter.readSelection(selection), e => e.code === 'workspace_unknown' && !e.message.includes('SECRET')); assert.equal(f.adapter.snapshot().phase, 'unavailable'); assert.equal(f.adapter.snapshot().revision, null); });
test('new browser capability namespace cannot inherit or dispatch another grant pending edit', async () => { const f = fixture(), p = await edit(f); f.setHandler(() => { throw Error('lost'); }); await assert.rejects(f.adapter.send(p)); const before = [...f.values.entries()], nextBoot = plain(f.boot); nextBoot.reviewerWorkspace.browser_context = '9'.repeat(64); const next = f.factory.create(nextBoot); assert.equal(next.pending(), null); assert.equal(next.draft(), null); await assert.rejects(next.send(p)); assert.deepEqual([...f.values.entries()], before); assert.equal(f.calls.filter(c => c.url.endsWith('/commands')).length, 1); });

test('older same-generation denied read cannot erase a newer authorized projection', async () => {
    const f = fixture(), hold = deferred(); let n = 0;
    f.setHandler(() => ++n === 1 ? hold.promise : response({...f.project(), revision: 2}));
    const old = f.adapter.readSelection(selection); await turn();
    await f.adapter.readSelection(selection); assert.equal(f.adapter.snapshot().revision, 2);
    hold.resolve(response({error: 'workspace_access'}, 403));
    await expectError(old, 'workspace_access');
    assert.equal(f.adapter.snapshot().revision, 2); assert.equal(f.adapter.snapshot().phase, 'ready');
});
test('old selection denied read cannot clear a newly selected authorized projection', async () => {
    const f = fixture(), hold = deferred(); let n = 0;
    f.setHandler((url, init, body) => ++n === 1 ? hold.promise : response({...f.project(body.selection), revision: 2}));
    const old = f.adapter.readSelection(selection); await turn();
    const next = {...selection, area_ids: [B]}; f.adapter.setSelection(next); await f.adapter.readSelection(next);
    hold.resolve(response({error: 'workspace_access'}, 403)); await assert.rejects(old);
    assert.equal(f.adapter.snapshot().area_ids[0], B); assert.equal(f.adapter.snapshot().revision, 2); assert.equal(f.adapter.snapshot().phase, 'ready');
});
test('current owned access denial still retires ordinary projection for every closed access status', async () => {
    for (const status of [403, 404, 410, 419]) {
        const f = fixture(); await f.adapter.readSelection(selection);
        f.setHandler(() => response({error: 'workspace_access'}, status));
        await expectError(f.adapter.readSelection(selection), 'workspace_access');
        assert.equal(f.adapter.snapshot().revision, null); assert.equal(f.adapter.snapshot().phase, 'blocked'); assert.equal(f.values.size, 0);
    }
});
test('facts denial withholds facts without mutating an independently refreshed ordinary projection', async () => {
    const f = fixture(), hold = deferred(); await f.adapter.readSelection(selection);
    f.setHandler((url, init, body) => url.endsWith('/facts') ? hold.promise : response({...f.project(body.selection), revision: 2}));
    const old = f.adapter.readFacts(selection); await turn(); await f.adapter.readSelection(selection);
    hold.resolve(response({error: 'workspace_access'}, 403)); await expectError(old, 'workspace_access');
    assert.equal(f.adapter.snapshot().revision, 2); assert.equal(f.adapter.snapshot().phase, 'ready');
});
test('late command denial retains exact unknown intent without erasing a newer readable projection', async () => {
    const f = fixture(), p = await edit(f), hold = deferred();
    f.setHandler((url, init, body) => url.endsWith('/commands') ? hold.promise : response({...f.project(body.selection), revision: 2}));
    const old = f.adapter.send(p); while (!f.calls.some(c => c.url.endsWith('/commands'))) await turn();
    await f.adapter.readSelection(selection); hold.resolve(response({error: 'workspace_access'}, 403)); await expectError(old, 'workspace_access', 'unknown');
    assert.equal(f.adapter.snapshot().revision, 2); assert.equal(f.adapter.snapshot().phase, 'unknown');
    assert.equal(f.adapter.pending().digest, p.digest); assert.deepEqual(plain(f.adapter.draft()), plain(p.command));
});
test('late status denial retains exact unknown intent without erasing a newer readable projection', async () => {
    const f = fixture(), p = await edit(f), hold = deferred(); f.setHandler(() => {throw Error('lost response');}); await assert.rejects(f.adapter.send(p));
    f.setHandler((url, init, body) => url.endsWith('/command-status') ? hold.promise : response({...f.project(body.selection), revision: 2}));
    const old = f.adapter.recover(); while (!f.calls.some(c => c.url.endsWith('/command-status'))) await turn();
    await f.adapter.readSelection(selection); hold.resolve(response({error: 'workspace_access'}, 403)); await expectError(old, 'workspace_access', 'unknown');
    assert.equal(f.adapter.snapshot().revision, 2); assert.equal(f.adapter.snapshot().phase, 'unknown');
    assert.equal(f.adapter.pending().digest, p.digest); assert.deepEqual(plain(f.adapter.draft()), plain(p.command));
});

test('project task presentation resolves assigned names only from current scoped fictional people', async () => {
    const f = fixture(), view = await f.adapter.readSelection(selection), data = f.adapter.data(view, A);
    assert.deepEqual(plain(data.projects[0].tasks[0].assignee), {id:'2', name:'Fictional worker'});
    assert.deepEqual(plain(data.requests[0].task.assignee), {id:'2', name:'Fictional worker'});
    assert.equal(data.projects[0].tasks[0].assignee_id, '2');
    assert.equal(Object.isFrozen(data.projects[0].tasks[0].assignee), true);
    assert.equal(f.writes, 0);
});


test('an assigned inactive person retains assignment without exposing a directory name or becoming Unassigned', async () => {
    const f = fixture(); f.base.people[1].active = false;
    const data = f.adapter.data(await f.adapter.readSelection(selection), A);
    assert.deepEqual(plain(data.projects[0].tasks[0].assignee), {id:'2', name:'Assigned person · name unavailable'});
    assert.equal(data.members.some(person => person.id === '2'), false);
    assert.equal(data.requests[0].task.assignee.name.includes('Fictional worker'), false);
    assert.equal(f.writes, 0);
});

test('comment bridge first admits an actual full active-selection projection after scoped catalog reads', async () => {
    const f = fixture(), active = {...selection, area_ids:[A,B]};
    f.adapter.setSelection(active);
    await f.adapter.request('/enterprise/data?tenant=fictional&area_id='+A);
    assert.equal(f.adapter.snapshot().revision, null);
    const before = f.calls.length;
    Object.assign(f.context,{sharedWorkspace:f.adapter,presentation:{reviewerCollaboration:{enabled:true}},area:A,section:'requests',selected:Q,
      data:{projects:[]},$:()=>null,message:()=>{},EverbranchSharedCommentsUI:{create:()=>{
        assert.equal(f.adapter.snapshot().revision,1);
        assert.deepEqual(plain(f.adapter.snapshot().area_ids),[A,B]);
        return{mount:async()=> 'ACTUAL ACTIVE PROJECTION ACCEPTED'};
      }}});
    const bridge = await readFile(new URL('../../resources/views/enterprise/shared-comments-bridge.blade.php',import.meta.url),'utf8');
    vm.runInContext(bridge+'\nglobalThis.mountBridge=mountSharedDiscussion;',f.context);
    assert.equal(await f.context.mountBridge('ticket',Q),'ACTUAL ACTIVE PROJECTION ACCEPTED');
    assert.equal(f.calls.length,before+1);
    assert.deepEqual(f.calls.at(-1).body.selection,active);
    assert.equal(f.writes,0);
});

test('shared ticket DTO distinguishes admin editing from ordinary manager linked-task restrictions without changing source records', async () => {
    for (const persona of ['demo_manager', 'manager']) {
        const f = fixture(), selected = {...selection, persona};
        f.setHandler(() => { const value = f.project(selected); if (persona !== 'demo_manager') { value.permitted_actions = value.permitted_actions.filter(a => !a.startsWith('ticket.') || a === 'ticket.create'); value.graph.tickets.forEach(t => t.allowed_actions = []); } return response(value); });
        const view = await f.adapter.readSelection(selected), before = JSON.stringify(view), d = f.adapter.data(view, A);
        assert.equal(d.requests[0].can_edit, persona === 'demo_manager');
        assert.equal(d.requests[0].can_coordinate, persona === 'demo_manager');
        assert.equal(d.tasks[0].shared_ticket_fields_readonly, persona !== 'demo_manager');
        assert.equal(d.tasks[0].can_edit, true, 'Manager retains unrelated task edits');
        assert.equal(d.can_submit, true); assert.equal(d.can_coordinate, true, 'Unrelated project authority remains separate');
        assert.equal(JSON.stringify(view), before); assert.equal(f.writes, 0); assert.equal(f.values.size, 0);
        const unlinked = plain(view); unlinked.graph.tickets = [];
        assert.equal(f.adapter.data(unlinked, A).tasks[0].shared_ticket_fields_readonly, false);
    }
});
