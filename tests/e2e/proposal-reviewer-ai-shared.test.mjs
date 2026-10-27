import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
import test from 'node:test';
import vm from 'node:vm';
const scripts=await Promise.all(['live-ai','live-ai-client'].map(name=>readFile(new URL('../../resources/js/enterprise/reviewer/'+name+'.js',import.meta.url),'utf8')));
const copy=value=>JSON.parse(JSON.stringify(value));
const area='10000000-0000-4000-8000-000000000001', workspace='60000000-0000-4000-8000-000000000001';
const defer=()=>{let resolve;const promise=new Promise(yes=>resolve=yes);return{resolve,promise};};
function fixture(handler){
 const scope={area_id:area,all_departments:false,binding:{version_hash:'a'.repeat(64),source_hash:'b'.repeat(64),build_id:'synthetic-shared-build'}};
 const selection={persona:'demo_manager',area_ids:[area],person_id:null};
 let revision=1,graphDigest='c'.repeat(64),readError=false,latest,known=new Map();const calls=[],persisted=[];
 const pin=()=>({workspace_id:workspace,revision,workspace_digest:graphDigest,selection_digest:'d'.repeat(64),snapshot_digest:'e'.repeat(64),policy_hash:'f'.repeat(64),capability_hash:'1'.repeat(64),...scope.binding});
 const snapshot=()=>({schema_version:2,persona:selection.persona,local_revision:revision,area_ids:[area],sources:[{ref:'project:20000000-0000-4000-8000-000000000001',kind:'project',area_id:area,title:'Server-held fictional job',version:revision,status:'active',tasks:[],buildings:[],contractor_plans:[],costs:[],comparisons:[]}],coverage:'Server-held shared fictional workspace. Synthetic test only.'});
 const adapter={mode:'shared',async aiFacts(){if(readError)throw Error('Current hosted facts unavailable');return{snapshot:snapshot(),pin:pin(),selection:copy(selection)};},request(){throw Error('Browser facts fallback forbidden');}};
 const context=vm.createContext({crypto:webcrypto,TextEncoder,console});for(const script of scripts)vm.runInContext(script,context);
 const preview=body=>latest={id:webcrypto.randomUUID(),request_id:body.request_id,digest:'2'.repeat(64),snapshot_digest:'3'.repeat(64),question:body.question,snapshot:snapshot(),workspace:{selection:copy(selection),pin:pin()},...(body.history?{history:body.history.map(receipt=>known.get(receipt.preview_id))}:{}),expires_at:Math.floor(Date.now()/1000)+600,content_expires_at:Math.floor(Date.now()/1000)+600,maximum_microusd:350000};
 const accepted=()=>({status:'accepted',preview_id:latest.id,request_id:latest.request_id,digest:latest.digest,snapshot_digest:latest.snapshot_digest,provider_mode:'openai_generated',provider_dispatch:true,provider_simulated:true,answer:'The current fictional project is active.',refs:[latest.snapshot.sources[0].ref]});
 const request=async(action,body)=>{calls.push({action,body:copy(body)});const value=handler?await handler({action,body,preview,accepted}):action==='preview'?preview(body):accepted();if(value.status==='accepted')known.set(latest.id,{question:latest.question,answer:value.answer,refs:value.refs});return value;};
 const client=context.EverbranchProposalAiClient.create({adapter,context:()=>scope,request,persistReceipt:value=>{persisted.push(copy(value));return true;}});
 return{client,calls,persisted,change({sameRevision=false}={}){if(!sameRevision)revision++;graphDigest=graphDigest==='c'.repeat(64)?'4'.repeat(64):'5'.repeat(64);},failRead(){readError=true;},snapshot};
}
test('shared consent uses server facts and sends only selection/revision, then requires explicit once-only confirmation',async()=>{
 const f=fixture();await f.client.prepare('Who owns this job?');assert.equal(f.client.read().phase,'preview');
 assert.deepEqual(f.calls[0].body.workspace,{expected_revision:1,selection:{persona:'demo_manager',area_ids:[area],person_id:null}});
 assert.equal(Object.hasOwn(f.calls[0].body,'snapshot'),false);assert.equal(f.calls.length,1);
 await Promise.all([f.client.confirm(),f.client.confirm()]);assert.equal(f.client.read().phase,'accepted');assert.deepEqual(f.calls.map(x=>x.action),['preview','confirm']);
 assert.doesNotMatch(JSON.stringify(f.persisted),/sources|question|Server-held|capability_hash/);
 await f.client.prepare('And what is its status?');assert.equal(f.client.read().phase,'preview');assert.equal(f.calls.at(-1).body.history.length,1);
});
test('shared graph revisions and full digest drift both retire consent before paid confirmation',async()=>{
 for(const sameRevision of [false,true]){const f=fixture();await f.client.prepare('What is current?');f.change({sameRevision});await f.client.confirm();assert.equal(f.client.read().phase,'idle');assert.deepEqual(f.calls.map(x=>x.action),['preview']);}
});
test('failed server facts never fall back to browser records or create a sharing preview',async()=>{
 const f=fixture();f.failRead();await f.client.prepare('What is current?');assert.equal(f.client.read().phase,'idle');assert.equal(f.calls.length,0);assert.match(f.client.read().error,/hosted facts unavailable/);
});
test('a changed sharing pin in the preview is refused even when the record snapshot looks identical',async()=>{
 const f=fixture(({body,preview})=>{const value=preview(body);value.workspace.pin.policy_hash='0'.repeat(64);return value;});
 await f.client.prepare('What is current?');assert.equal(f.client.read().phase,'idle');assert.equal(f.client.read().preview,null);assert.equal(f.calls.length,1);
});
test('a shared edit during paid wait withholds the old answer and retains only its original recovery receipt',async()=>{
 const hold=defer(),f=fixture(({action,body,preview,accepted})=>action==='preview'?preview(body):action==='confirm'?hold.promise.then(accepted):accepted());
 await f.client.prepare('What is current?');const sending=f.client.confirm();while(f.calls.length<2)await new Promise(resolve=>setImmediate(resolve));f.change();hold.resolve();await sending;
 assert.equal(f.client.read().phase,'stale');assert.equal(f.client.read().result,null);assert.ok(f.client.read().pending);await f.client.recover();assert.equal(f.client.read().phase,'stale');assert.deepEqual(f.calls.map(x=>x.action),['preview','confirm','status']);
});
test('an invalid shared evidence pin cannot be downgraded to the old browser snapshot mode',async()=>{
 const f=fixture(({body,preview})=>{const value=preview(body);delete value.workspace;return value;});await f.client.prepare('What is current?');assert.equal(f.client.read().phase,'idle');assert.equal(f.client.read().preview,null);assert.equal(f.calls.length,1);
});
test('failed source reauthorization withholds an accepted answer and returns no stale record',async()=>{
 const f=fixture();await f.client.prepare('What is current?');await f.client.confirm();const ref=f.client.read().result.refs[0];f.failRead();
 assert.equal(await f.client.source(ref),null);assert.equal(f.client.read().result,null);assert.equal(f.client.read().phase,'idle');assert.match(f.client.read().error,/old answer is withheld/);assert.equal(f.calls.length,2);
});
