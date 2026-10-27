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

const navigationSource=await readFile(new URL('../../resources/js/enterprise/reviewer/shared-tour-navigation.js',import.meta.url),'utf8');
function navigation(options={}){
 const f=fixture(),doc=new EventTarget(),win=new EventTarget(),destinations=[],notices=[];let where='requests:A',preferred=options.area||A;
 f.context.document=doc;f.context.addEventListener=win.addEventListener.bind(win);f.context.removeEventListener=win.removeEventListener.bind(win);
 f.context.EverbranchSampleTransport=new Proxy({}, {get(){throw Error('Sample fallback must never be consulted');}});
 vm.runInContext(navigationSource,f.context);
 const router=f.context.EverbranchSharedTourNavigation.create({adapter:f.adapter,dispatch:value=>destinations.push(plain(value)),notify:value=>notices.push(value),currentArea:()=>preferred,context:()=>where});
 return{...f,doc,win,router,destinations,notices,setContext:v=>{where=v;},setArea:v=>{preferred=v;}};
}
test('shared tour reads fresh closed server projection and opens actual current permitted ticket without writes',async()=>{
 const f=navigation();f.base.tickets[0].id=id(101);const out=await f.router.open();assert.equal(out.available,true);
 assert.deepEqual(f.destinations,[{section:'requests',area:A,selected:id(101)}]);assert.equal(f.calls.length,1);assert.equal(f.calls[0].url.endsWith('/workspace/projection'),true);
 assert.deepEqual(Object.keys(f.calls[0].body).sort(),['binding','selection']);assert.equal(f.calls[0].init.credentials,'same-origin');assert.equal(f.calls[0].init.headers['X-CSRF-TOKEN'],'synthetic-noncredential-csrf');assert.equal(f.writes,0);assert.equal(f.values.size,0);
 f.base.tickets=[];assert.equal((await f.router.open()).available,false);assert.equal(f.destinations.length,1);assert.match(f.notices[0],/No currently permitted ticket/);assert.equal(f.calls.length,2);
});
test('prefers current permitted area and deterministic IDs, never another selected or foreign scope',async()=>{
 const f=navigation({area:B});f.adapter.setSelection({...selection,area_ids:[A,B]});
 f.base.tickets.push({...plain(f.base.tickets[0]),id:id(102),area_id:B,building_id:null,project_id:null,task_id:null});
 f.base.tickets.push({...plain(f.base.tickets[0]),id:id(101),area_id:B,building_id:null,project_id:null,task_id:null});
 await f.router.open();assert.deepEqual(f.destinations,[{section:'requests',area:B,selected:id(101)}]);
 const g=navigation({area:B});await g.router.open();assert.equal(g.destinations[0].area,A);assert.deepEqual(g.calls[0].body.selection.area_ids,[A]);
});
test('requester selection with no server-returned owned ticket stays empty rather than inventing a sample record',async()=>{
 const f=navigation();f.adapter.setSelection({persona:'requester',area_ids:[A],person_id:null});f.base.tickets=[];
 assert.equal((await f.router.open()).available,false);assert.deepEqual(f.destinations,[]);assert.equal(f.calls[0].body.selection.persona,'requester');assert.equal(f.writes,0);
});
test('revoked or invalid current projection announces only sanitized refusal and no navigation',async()=>{
 for(const status of [403,410]){const f=navigation();f.setHandler(()=>response({error:'PRIVATE_RAW_SENTINEL'},status));assert.equal((await f.router.open()).available,false);assert.deepEqual(f.destinations,[]);assert.equal(f.notices.length,1);assert.equal(f.notices[0].includes('PRIVATE_RAW'),false);}
 const f=navigation();f.setHandler(()=>response({...f.project(),workspace_id:id(999)}));await f.router.open();assert.deepEqual(f.destinations,[]);
});
test('repeated button press retains one current read and dispatch',async()=>{
 const f=navigation(),pending=deferred();f.setHandler(()=>pending.promise);const first=f.router.open();await turn();
 assert.equal((await f.router.open()).available,false);assert.equal(f.calls.length,1);pending.resolve(response(f.project()));assert.equal((await first).available,true);assert.equal(f.destinations.length,1);
});
test('navigation away and back fences late replies even if transport ignores AbortSignal',async()=>{
 const f=navigation(),pending=deferred();f.setHandler(()=>pending.promise);const first=f.router.open();await turn();
 f.setContext('projects:A');f.doc.dispatchEvent(new Event('enterprise:navigate'));f.setContext('requests:A');f.doc.dispatchEvent(new Event('enterprise:navigate'));
 pending.resolve(response(f.project()));assert.equal((await first).available,false);assert.deepEqual(f.destinations,[]);assert.deepEqual(f.notices,[]);
});
test('selection, detail, workspace refresh and permanent unmount fence old ticket opens',async()=>{
 for(const event of ['enterprise:tour-context','enterprise:workspace-state']){const f=navigation(),pending=deferred();f.setHandler(()=>pending.promise);const first=f.router.open();await turn();f.doc.dispatchEvent(new Event(event));pending.resolve(response(f.project()));await first;assert.deepEqual(f.destinations,[]);}
 const f=navigation(),pending=deferred();f.setHandler(()=>pending.promise);const first=f.router.open();await turn();f.adapter.setSelection({...selection,area_ids:[B]});pending.resolve(response(f.project()));await first;assert.deepEqual(f.destinations,[]);
 const g=navigation();g.router.destroy();assert.equal((await g.router.open()).available,false);assert.equal(g.calls.length,0);
});
test('reviewer adapter shared callback bypasses old sample ID while original sample callback is unchanged',async()=>{
 const partial=await readFile(new URL('../../resources/views/enterprise/reviewer-adapter.blade.php',import.meta.url),'utf8');
 assert.ok(partial.includes("resource_path('js/enterprise/reviewer/shared-tour-navigation.js')"));assert.ok(partial.includes('Open a current permitted ticket'));
 const method=partial.match(/onSampleRequest\(\) \{[^\n]+\},/)[0].slice(0,-1),opened=[],sent=[];
 const shared=vm.createContext({shared:true,sharedTour:{open:()=>{opened.push(true);return {available:true};}},dispatch:x=>sent.push(x),defaultArea:A,EverbranchSampleTransport:new Proxy({},{get(){throw Error('No sample read for shared');}})});
 assert.equal(vm.runInContext('({'+method+'}).onSampleRequest()',shared).available,true);assert.equal(opened.length,1);assert.equal(sent.length,0);
 const legacy=vm.createContext({shared:false,sharedTour:{open(){throw Error('No shared read for legacy');}},dispatch:x=>sent.push(plain(x)),defaultArea:A,EverbranchSampleTransport:{ids:{request:Q}}});
 vm.runInContext('({'+method+'}).onSampleRequest()',legacy);assert.deepEqual(sent,[{section:'requests',area:A,selected:Q}]);
});
