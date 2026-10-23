import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
import vm from 'node:vm';
const base = new URL('../../', import.meta.url);
const sources = await Promise.all(['job-sample', 'sample-adapter', 'live-ai', 'live-ai-client'].map(name => readFile(new URL('resources/js/enterprise/reviewer/' + name + '.js', base), 'utf8')));
const locations = JSON.parse(await readFile(new URL('resources/data/enterprise/lees-mcrae-locations.json', base), 'utf8')).entries;
const plain = value => JSON.parse(JSON.stringify(value));
const defer = () => { let resolve, reject; const promise = new Promise((yes, no) => {resolve = yes; reject = no;}); return {promise, resolve, reject}; };
function fixture(handler, persistence, instrument) {
    const context = vm.createContext({URL, URLSearchParams, Blob, TextEncoder, crypto:webcrypto, console,
        location:{href:'https://example.invalid/demo'}, history:{replaceState(){}}, sessionStorage:{getItem(){return null;},setItem(){}}, fetch(){throw Error('No provider/network in client fixture');}});
    for (const source of sources) vm.runInContext(source, context);
    const api = context.EverbranchSampleTransport;
    const adapter = api.create({runtime:'sample',defaultPersona:'demo_manager',identity:{workspace:'ai-client-fixture'},locations,routes:{back:'/demo',workflowWorkspace:'/demo/workflows'}});
    let selected = {area_id:api.ids.area,all_departments:true,binding:{build_id:'synthetic-ai-build',version_hash:'a'.repeat(64),source_hash:'b'.repeat(64)}};
    const calls = [], receipts = [];
    let preview;
    const makePreview = body => (preview = {id:webcrypto.randomUUID(),request_id:body.request_id,digest:'c'.repeat(64),snapshot_digest:'d'.repeat(64),
        snapshot:body.snapshot,question:body.question,maximum_microusd:350000,expires_at:Math.floor(Date.now()/1000)+600});
    const accepted = () => ({status:'accepted',preview_id:preview.id,request_id:preview.request_id,digest:preview.digest,snapshot_digest:preview.snapshot_digest,
        provider_mode:'openai_generated',provider_dispatch:true,provider_simulated:true,answer:'Fictional work is recorded in the selected departments.',refs:[preview.snapshot.sources[0].ref]});
    const request = async (action,body) => {calls.push({action,body:plain(body)});return handler ? handler({action,body,makePreview,accepted}) : action==='preview'?makePreview(body):accepted();};
    if(instrument)instrument(context);
    const client = context.EverbranchProposalAiClient.create({adapter,context:()=>selected,request,persistReceipt:value=>{receipts.push(plain(value));return persistence ? persistence(value) : true;},changed(){}});
    return {api,adapter,client,calls,receipts,select(value){selected={...selected,...value};}};
}
async function edit(f) {
    const data = await f.adapter.request('/enterprise/data?area_id='+f.api.ids.area), project=data.projects[0];
    await f.adapter.request('/enterprise/commands',{action:'project.update',id:project.id,area_id:f.api.ids.area,expected_version:project.version,
        request_id:webcrypto.randomUUID(),title:'Revised fictional source',description:'Not shared'});
}
test('review fields is unpaid preview only; explicit double confirmation dispatches once with receipt-only persistence', async()=>{
    const hold=defer(),f=fixture(({action,body,makePreview,accepted})=>action==='preview'?makePreview(body):hold.promise.then(accepted));
    await f.client.prepare('  What work is visible?  ');
    assert.equal(f.client.read().phase,'preview');assert.equal(f.client.read().snapshot.sources.length,25);
    assert.deepEqual(f.calls.map(row=>row.action),['preview']);assert.equal(f.client.read().question,'What work is visible?');
    const first=f.client.confirm();await new Promise(r=>setImmediate(r));await f.client.confirm();
    assert.deepEqual(f.calls.map(row=>row.action),['preview','confirm']);assert.equal(f.client.read().phase,'sending');
    assert.doesNotMatch(JSON.stringify(f.receipts[0]),/question|sources|Fictional|api_key/);
    hold.resolve();await first;assert.equal(f.client.read().phase,'accepted');assert.equal(f.client.read().pending,null);assert.equal(f.receipts.at(-1),null);
});
test('editing records, changing perspective or scope before confirmation cannot send the stale preview',async()=>{
    for(const change of [edit,async f=>f.adapter.setPersona('worker'),async f=>f.select({area_id:f.api.ids.it})]){
        const f=fixture();await f.client.prepare('Who is working?');await change(f);await f.client.confirm();
        assert.equal(f.client.read().phase,'idle');assert.deepEqual(f.calls.map(row=>row.action),['preview']);assert.equal(f.client.read().question,'Who is working?');
    }
});
test('changes while preparing discard late previews rather than granting authority from a saved revision',async()=>{
    const hold=defer(),f=fixture(({action,body,makePreview})=>hold.promise.then(()=>makePreview(body)));
    const preparing=f.client.prepare('What is next?');await new Promise(r=>setImmediate(r));await edit(f);hold.resolve();await preparing;
    assert.equal(f.client.read().phase,'idle');assert.equal(f.client.read().preview,null);assert.equal(f.calls.length,1);
});
test('late paid answers after an edit are withheld and reconciled only by read-only status',async()=>{
    const hold=defer(),f=fixture(({action,body,makePreview,accepted})=>action==='preview'?makePreview(body):action==='confirm'?hold.promise.then(accepted):accepted());
    await f.client.prepare('What is active?');const sending=f.client.confirm();await new Promise(r=>setImmediate(r));await edit(f);f.client.invalidate();hold.resolve();await sending;
    assert.equal(f.client.read().phase,'stale');assert.equal(f.client.read().result,null);assert.ok(f.client.read().pending);
    await f.client.confirm();await f.client.recover();assert.deepEqual(f.calls.map(row=>row.action),['preview','confirm','status']);
    assert.equal(f.client.read().result,null);f.client.acknowledgeSettled();assert.equal(f.client.read().phase,'idle');assert.equal(f.client.read().pending,null);
});
test('lost responses never auto-redispatch; status uses the same immutable receipt and exact binding',async()=>{
    const f=fixture(({action,body,makePreview,accepted})=>action==='preview'?makePreview(body):action==='confirm'?Promise.reject(Error('Simulated lost response')):accepted());
    await f.client.prepare('What is planned?');await f.client.confirm();assert.equal(f.client.read().phase,'uncertain');
    const pending=plain(f.client.read().pending);await f.client.confirm();await f.client.prepare('Different question');assert.equal(f.calls.length,2);
    await f.client.recover();assert.equal(f.client.read().phase,'accepted');assert.deepEqual(f.calls[2].body,pending);assert.equal(f.calls[2].action,'status');
});
test('unsupported answer links, refs and changed receipt bindings cannot become displayed answers',async()=>{
    for(const mutate of [value=>({...value,answer:'Visit https://example.invalid'}),value=>({...value,refs:['project:foreign']}),value=>({...value,snapshot_digest:'e'.repeat(64)})]){
        const f=fixture(({action,body,makePreview,accepted})=>action==='preview'?makePreview(body):mutate(accepted()));
        await f.client.prepare('Summarize these records');await f.client.confirm();assert.equal(f.client.read().phase,'uncertain');assert.equal(f.client.read().result,null);assert.ok(f.client.read().pending);
    }
});
test('reload retains only opaque receipt fields and recovers through status without another confirm',async()=>{
    let savedPreview;
    const f=fixture(({action,body,makePreview})=>{if(action==='preview')return savedPreview=plain(makePreview(body));throw Error('Simulated disconnected browser');});
    await f.client.prepare('What work is visible?');await f.client.confirm();const receipt=plain(f.receipts[0]);
    const reloaded=fixture(({action})=>{assert.equal(action,'status');return {status:'accepted',preview_id:savedPreview.id,request_id:savedPreview.request_id,
        digest:savedPreview.digest,snapshot_digest:savedPreview.snapshot_digest,provider_mode:'openai_generated',provider_dispatch:true,provider_simulated:true,answer:'Fictional work is visible.',refs:[savedPreview.snapshot.sources[0].ref],preview:savedPreview};});
    reloaded.client.resume(receipt);assert.equal(reloaded.client.read().phase,'uncertain');await reloaded.client.confirm();assert.equal(reloaded.calls.length,0);
    await reloaded.client.recover();assert.equal(reloaded.client.read().phase,'accepted');assert.equal(reloaded.client.read().question,'What work is visible?');assert.deepEqual(reloaded.calls.map(row=>row.action),['status']);
    const foreign=fixture();foreign.client.resume({...receipt,binding:{...receipt.binding,source_hash:'f'.repeat(64)}});assert.equal(foreign.client.read().pending,null);assert.equal(foreign.calls.length,0);
});
test('source inspection rechecks exact current fictional bytes instead of following a stale model reference',async()=>{
    const f=fixture();await f.client.prepare('What is active?');await f.client.confirm();const ref=f.client.read().result.refs[0];
    assert.equal((await f.client.source(ref)).ref,ref);await edit(f);assert.equal(await f.client.source(ref),null);assert.equal(f.client.read().result,null);assert.equal(f.calls.length,2);
});

test('receipt storage failure before dispatch is known unsubmitted and keeps the question editable',async()=>{
    for(const persistence of [()=>false,()=>{throw Error('Storage blocked');}]){
        const f=fixture(null,persistence);await f.client.prepare('Who is working?');await f.client.confirm();
        assert.equal(f.client.read().phase,'idle');assert.equal(f.client.read().pending,null);assert.equal(f.client.read().preview,null);
        assert.equal(f.client.read().question,'Who is working?');assert.match(f.client.read().error,/Nothing was sent to OpenAI/);
        assert.deepEqual(f.calls.map(row=>row.action),['preview']);await f.client.recover();assert.equal(f.calls.length,1);
    }
});

test('optional receipt cleanup false or throw preserves accepted and rejected settled outcomes without redispatch',async()=>{
    for(const terminal of ['accepted','rejected'])for(const mode of ['false','throw']){
        const f=fixture(({action,body,makePreview,accepted})=>action==='preview'?makePreview(body):({...accepted(),status:terminal}),value=>{if(value!==null)return true;if(mode==='false')return false;throw Error('Raw private storage error');});
        await f.client.prepare('What is active?');await f.client.confirm();const state=f.client.read();
        assert.equal(state.phase,terminal);assert.equal(state.pending,null);assert.ok(state.preview);assert.match(state.error,/settled request receipt could not be removed/);assert.doesNotMatch(state.error,/Raw private/);
        if(terminal==='accepted'){assert.ok(state.result);assert.equal((await f.client.source(state.result.refs[0])).ref,state.result.refs[0]);}else assert.equal(state.result,null);
        await f.client.confirm();await f.client.recover();assert.deepEqual(f.calls.map(row=>row.action),['preview','confirm']);
    }
});
test('stale settled acknowledgment handles optional cleanup failure and always emits the editable state',async()=>{
    for(const mode of ['false','throw']){
        const hold=defer(),f=fixture(({action,body,makePreview,accepted})=>action==='preview'?makePreview(body):hold.promise.then(accepted),value=>{if(value!==null)return true;if(mode==='false')return false;throw Error('Private cleanup failure');});
        await f.client.prepare('Who is assigned?');const sending=f.client.confirm();await new Promise(r=>setImmediate(r));await edit(f);f.client.invalidate();hold.resolve();await sending;assert.equal(f.client.read().phase,'stale');
        assert.doesNotThrow(()=>f.client.acknowledgeSettled());const state=f.client.read();assert.equal(state.phase,'idle');assert.equal(state.pending,null);assert.equal(state.result,null);assert.match(state.error,/settled request receipt could not be removed/);assert.doesNotMatch(state.error,/Private cleanup/);assert.deepEqual(f.calls.map(row=>row.action),['preview','confirm']);
    }
});

const closedReceipt = (preview,status) => ({preview_id:preview.id,request_id:preview.request_id,digest:preview.digest,
    snapshot_digest:preview.snapshot_digest,status,content_expired:true});

test('unclaimed recovery requires atomic cancellation and explicit acknowledgment before another question',async()=>{
    let preview;
    const f=fixture(({action,body,makePreview})=>{
        if(action==='preview')return preview=plain(makePreview(body));
        if(action==='confirm')throw Error('Lost request before admission');
        if(action==='status')return {...closedReceipt(preview,'prepared'),content_expired:false,preview};
        assert.equal(action,'cancel');return closedReceipt(preview,'cancelled');
    });
    await f.client.prepare('What is active?');await f.client.confirm();await f.client.recover();
    assert.equal(f.client.read().phase,'prepared');assert.ok(f.client.read().pending);
    await f.client.confirm();f.client.acknowledgeSettled();await f.client.prepare('Another question');
    assert.deepEqual(f.calls.map(row=>row.action),['preview','confirm','status']);
    const receipt=plain(f.client.read().pending);await f.client.cancelPrepared();
    assert.deepEqual(f.calls.at(-1).body,receipt);assert.equal(f.client.read().phase,'settled_expired');
    assert.ok(f.client.read().pending);assert.equal(f.client.read().result,null);
    f.client.acknowledgeSettled();assert.equal(f.client.read().pending,null);assert.equal(f.client.read().phase,'idle');
    assert.equal(f.receipts.at(-1),null);
});

test('expired settled content recovers after reload without replaying a question or showing an old answer',async()=>{
    for(const status of ['accepted','rejected','cancelled']){
        let preview;
        const first=fixture(({action,body,makePreview})=>{if(action==='preview')return preview=plain(makePreview(body));throw Error('Lost response');});
        await first.client.prepare('Summarize the work');await first.client.confirm();
        const receipt=plain(first.client.read().pending),reloaded=fixture(({action})=>{
            assert.equal(action,'status');return closedReceipt(preview,status);
        });
        reloaded.client.resume(receipt);await reloaded.client.recover();
        assert.equal(reloaded.client.read().phase,'settled_expired');assert.equal(reloaded.client.read().result,null);
        assert.equal(reloaded.client.read().snapshot,null);assert.ok(reloaded.client.read().pending);
        reloaded.client.acknowledgeSettled();assert.equal(reloaded.client.read().pending,null);
        assert.deepEqual(reloaded.calls.map(row=>row.action),['status']);
    }
});

test('expired active exposure stays blocked and cannot be acknowledged or cancelled as settled',async()=>{
    for(const status of ['claimed','reserved','dispatching','uncertain']){
        let preview;
        const f=fixture(({action,body,makePreview})=>{
            if(action==='preview')return preview=plain(makePreview(body));
            if(action==='confirm')throw Error('Unknown outcome');
            assert.equal(action,'status');return closedReceipt(preview,status);
        });
        await f.client.prepare('Who is working?');await f.client.confirm();await f.client.recover();
        assert.equal(f.client.read().phase,'uncertain');assert.ok(f.client.read().pending);
        f.client.acknowledgeSettled();await f.client.cancelPrepared();await f.client.confirm();await f.client.prepare('Try again');
        assert.ok(f.client.read().pending);assert.deepEqual(f.calls.map(row=>row.action),['preview','confirm','status']);
    }
});

test('claim wins a cancel race or a lost cancel response never releases the retained receipt',async()=>{
    for(const cancellation of ['claimed','lost']){
        let preview,cancelled=false;
        const f=fixture(({action,body,makePreview})=>{
            if(action==='preview')return preview=plain(makePreview(body));
            if(action==='confirm')throw Error('Lost admission');
            if(action==='status')return closedReceipt(preview,cancelled?'cancelled':'prepared');
            assert.equal(action,'cancel');
            if(cancellation==='lost'){cancelled=true;throw Error('Lost cancellation response');}
            return closedReceipt(preview,'claimed');
        });
        await f.client.prepare('What is next?');await f.client.confirm();await f.client.recover();
        await f.client.cancelPrepared();assert.equal(f.client.read().phase,'uncertain');
        f.client.acknowledgeSettled();assert.ok(f.client.read().pending);await f.client.confirm();
        assert.equal(f.calls.filter(row=>row.action==='confirm').length,1);
        if(cancelled){await f.client.recover();assert.equal(f.client.read().phase,'settled_expired');f.client.acknowledgeSettled();assert.equal(f.client.read().pending,null);}
    }
});

test('changed digest or leaked expired content cannot become a settled acknowledgment',async()=>{
    for(const mutate of [row=>({...row,digest:'e'.repeat(64)}),row=>({...row,answer:'Private expired answer'}),row=>({...row,preview:{question:'Private expired question'}})]){
        let preview;
        const f=fixture(({action,body,makePreview})=>{
            if(action==='preview')return preview=plain(makePreview(body));
            if(action==='confirm')throw Error('Unknown outcome');
            return mutate(closedReceipt(preview,'accepted'));
        });
        await f.client.prepare('Summarize');await f.client.confirm();await f.client.recover();
        assert.equal(f.client.read().phase,'uncertain');assert.equal(f.client.read().result,null);
        f.client.acknowledgeSettled();assert.ok(f.client.read().pending);
    }
});

test('late prior outcome cannot replace a newer in-flight paid receipt after expired acknowledgment',async()=>{
 const oldCapture=defer(),newResponse=defer();let reads=0,confirms=0,oldPreview;
 const f=fixture(({action,body,makePreview,accepted})=>{
  if(action==='preview'){const value=makePreview(body);if(!oldPreview)oldPreview=plain(value);return value;}
  if(action==='confirm'){confirms++;return confirms===1?accepted():newResponse.promise.then(accepted);}
  assert.equal(action,'status');return {preview_id:oldPreview.id,request_id:oldPreview.request_id,digest:oldPreview.digest,snapshot_digest:oldPreview.snapshot_digest,status:'accepted',content_expired:true};
 },null,context=>{const original=context.EverbranchProposalAi;context.EverbranchProposalAi={...original,capture:async(...args)=>{if(++reads===5)await oldCapture.promise;return original.capture(...args);}};});
 await f.client.prepare('First question');const old=f.client.confirm();await new Promise(r=>setImmediate(r));assert.equal(reads,5);assert.equal(f.client.read().phase,'sending');
 f.client.invalidate();await f.client.recover();assert.equal(f.client.read().phase,'settled_expired');f.client.acknowledgeSettled();
 await f.client.prepare('Second paid question');const second=f.client.confirm();await new Promise(r=>setImmediate(r));const pending=plain(f.client.read().pending);assert.equal(f.client.read().phase,'sending');assert.equal(confirms,2);
 oldCapture.resolve();await old;const afterOld=plain(f.client.read());f.client.acknowledgeSettled();const afterAck=plain(f.client.read());
 newResponse.reject(Error('Synthetic test cleanup'));await second;
 assert.equal(afterOld.phase,'sending','A late prior callback must not replace the newer sending phase');assert.deepEqual(afterAck.pending,pending,'Old settled acknowledgment must not erase the newer in-flight request');
});
