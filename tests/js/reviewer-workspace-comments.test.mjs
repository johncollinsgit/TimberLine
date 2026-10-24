import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash,webcrypto} from 'node:crypto';
import vm from 'node:vm';
const [shared,source]=await Promise.all(['shared-workspace.js','collaboration-client.js'].map(name=>readFile(new URL('../../resources/js/enterprise/reviewer/'+name,import.meta.url),'utf8')));
const id=n=>String(n).padStart(8,'0')+'-0000-4000-8000-000000000001',W=id(1),A=id(2),B=id(3),T=id(4),C=id(5);
const plain=v=>JSON.parse(JSON.stringify(v)),turn=()=>new Promise(r=>setImmediate(r));
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};};
const response=(value,status=200)=>({ok:status>=200&&status<300,status,json:async()=>plain(value)});
function fixture(values=new Map()){
  const binding={version_hash:'a'.repeat(64),source_hash:'b'.repeat(64),build_id:'synthetic-comments'};
  const boot={reviewerWorkspace:{binding,endpoints:{projection:'/private/workspace/projection'}},reviewerCollaboration:{enabled:true,schema_version:1,workspace_id:W,browser_context:'c'.repeat(64),binding,expires_at:'2099-01-01T00:00:00Z',csrf:'synthetic-csrf-not-credential',endpoints:{show:'/private/workspace/collaboration/show',comment:'/private/workspace/collaboration/comment',status:'/private/workspace/collaboration/status'}}};
  let snapshot={workspace_id:W,browser_context:'c'.repeat(64),persona:'demo_manager',area_ids:[A],person_id:null},handler=null,storageFailure=false,writes=0,revision=0;
  const listeners=new Set(),calls=[],receipts=new Map(),comments=[];
  const workspace={mode:'shared',snapshot:()=>plain(snapshot),subscribe:callback=>{listeners.add(callback);return()=>listeners.delete(callback);}};
  const view=target=>({workspace_id:W,target,target_version:1,thread_revision:revision,can_comment:true,comments:plain(comments),before:null,files_available:false,expires_at:boot.reviewerCollaboration.expires_at});
  const context=vm.createContext({URL,URLSearchParams,TextEncoder,TextDecoder,AbortController,crypto:webcrypto,location:{href:'https://workspace.invalid/private/campus',origin:'https://workspace.invalid'},sessionStorage:{getItem:k=>values.get(k)??null,setItem:(k,v)=>{if(storageFailure)throw Error('PRIVATE RAW STORAGE');values.set(k,v);}},fetch:async(url,init)=>{const body=JSON.parse(init.body);calls.push({url,init,body});if(handler)return handler(url,init,body);if(url.endsWith('/show'))return response(view(body.target));if(url.endsWith('/comment')){let receipt=receipts.get(body.request_id);const replayed=!!receipt;if(!receipt){writes++;revision++;receipt={request_id:body.request_id,digest:createHash('sha256').update(context.EverbranchSharedWorkspace.canonical(body)).digest('hex'),comment_id:C,thread_revision:revision};receipts.set(body.request_id,receipt);comments.push({id:C,body:body.body,name:body.expected_reviewer_name,identity_status:'self_selected_display_name',created_at:'2026-10-24T12:00:00Z',mine:true});}return response({status:'applied',replayed,receipt});}if(url.endsWith('/status')){const receipt=receipts.get(body.request_id);return response(receipt?{status:'applied',settled:true,receipt}:{status:'not_observed',settled:false});}throw Error('Forbidden route');}});
  vm.runInContext(shared,context);vm.runInContext(source,context);
  const client=context.EverbranchWorkspaceComments.create(boot,workspace);
  return{boot,workspace,context,client,values,calls,receipts,view,setHandler:v=>handler=v,failStorage:()=>storageFailure=true,setArea:area=>{snapshot.area_ids=[area];listeners.forEach(fn=>fn(snapshot));},get writes(){return writes;}};
}
async function prepare(f){const view=await f.client.show({kind:'ticket',id:T});return f.client.prepare(view,{request_id:id(9),expected_reviewer_name:'Blaine',body:'Literal fictional work review'});}
test('fixed CSRF endpoints and validated shared comments have no upload/native/local history fallback',async()=>{
  const f=fixture(),v=await f.client.show({kind:'ticket',id:T});assert.equal(v.files_available,false);assert.equal(f.values.size,0);assert.equal(f.calls[0].init.credentials,'same-origin');assert.equal(f.calls[0].init.redirect,'error');assert.equal(f.calls[0].init.cache,'no-store');assert.equal(f.calls[0].init.headers['X-CSRF-TOKEN'],'synthetic-csrf-not-credential');
  const bad=plain(f.boot);bad.reviewerCollaboration.endpoints.comment='https://foreign.invalid/comment';assert.throws(()=>f.context.EverbranchWorkspaceComments.create(bad,f.workspace));assert.throws(()=>f.context.EverbranchWorkspaceComments.create(f.boot,{mode:'sample'}));
});
test('comment intent is verified and durable before IO; actual receipt alone confirms save and clears matching draft',async()=>{
  const f=fixture(),p=await prepare(f);let persisted=false;f.setHandler((url,init,body)=>{persisted=[...f.values.values()].some(v=>JSON.parse(v).pending?.digest===p.digest);return response({status:'applied',replayed:false,receipt:{request_id:body.request_id,digest:p.digest,comment_id:C,thread_revision:1}});});
  const out=await f.client.send(p);assert.equal(persisted,true);assert.equal(out.status,'applied');assert.equal(f.client.pending(),null);assert.equal(f.client.draft(),null);
});
test('storage failure is known unsubmitted and makes zero comment requests',async()=>{
  const f=fixture(),p=await prepare(f);f.failStorage();await assert.rejects(f.client.send(p),e=>e.code==='discussion_storage'&&e.outcome==='not_submitted'&&!e.message.includes('PRIVATE'));assert.equal(f.calls.length,1);assert.equal(f.client.pending(),null);
});
test('lost response keeps exact pending; status never redispatches and not_observed never settles',async()=>{
  const f=fixture(),p=await prepare(f);f.setHandler(()=>{throw Error('SECRET network');});await assert.rejects(f.client.send(p),e=>e.outcome==='unknown');assert.equal(f.client.pending().digest,p.digest);f.setHandler(null);assert.equal((await f.client.recover()).settled,false);assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,1);assert.ok(f.client.pending());assert.equal((await f.client.retry(p)).status,'applied');assert.equal(f.writes,1);
});
test('status resolves already-applied lost response without another dispatch; another wrapper cannot replace its exact body',async()=>{
  const f=fixture(),p=await prepare(f);f.setHandler(()=>{f.receipts.set(p.command.request_id,{request_id:p.command.request_id,digest:p.digest,comment_id:C,thread_revision:1});throw Error('lost after commit');});await assert.rejects(f.client.send(p));const altered=plain(p);altered.command.body='Altered';await assert.rejects(f.client.retry(altered));f.setHandler(null);assert.equal((await f.client.recover()).settled,true);assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,1);
});
test('first exact definitive rejection needs explicit acknowledgment; prior unknown cannot be released by later409',async()=>{
  for(const unknown of [false,true]){const f=fixture(),p=await prepare(f);if(unknown){f.setHandler(()=>{throw Error('lost');});await assert.rejects(f.client.send(p));}f.setHandler(()=>response({error:'workspace_conflict',automatic_retry:false,definitive_not_applied:true,request_id:p.command.request_id,digest:p.digest,workspace_id:W},409));await assert.rejects(unknown?f.client.retry(p):f.client.send(p),e=>e.outcome===(unknown?'unknown':'rejected'));if(unknown)assert.throws(()=>f.client.acknowledgeRejection(p));else{assert.equal(f.client.acknowledgeRejection(p).body,p.command.body);assert.equal(f.client.pending(),null);await assert.rejects(f.client.send(p));}}
});
test('forged apply never clears pending or creates saved history',async()=>{
  const f=fixture(),p=await prepare(f);f.setHandler(()=>response({status:'applied',replayed:false,receipt:{request_id:p.command.request_id,digest:'f'.repeat(64),comment_id:C,thread_revision:1}}));await assert.rejects(f.client.send(p));assert.equal(f.client.pending().digest,p.digest);assert.equal(f.client.draft().body,p.command.body);
});
test('late scope response is withheld even if selection returns; scope change aborts actual pending read',async()=>{
  const f=fixture(),hold=deferred();f.setHandler(()=>hold.promise);const pending=f.client.show({kind:'ticket',id:T});await turn();f.setArea(B);assert.equal(f.calls[0].init.signal.aborted,true);f.setArea(A);hold.resolve(response(f.view({kind:'ticket',id:T})));await assert.rejects(pending,e=>e.code==='discussion_context');
});
test('late confirmed save after destroy remains content-free applied receipt and retains no new screen authority',async()=>{
  const f=fixture(),p=await prepare(f),hold=deferred();f.setHandler(()=>hold.promise);const pending=f.client.send(p);while(!f.calls.some(c=>c.url.endsWith('/comment')))await turn();f.client.destroy();hold.resolve(response({status:'applied',replayed:false,receipt:{request_id:p.command.request_id,digest:p.digest,comment_id:C,thread_revision:1}}));const out=await pending;assert.equal(out.status,'applied');assert.equal(out.stale_context,true);assert.equal(f.client.pending(),null);
});
test('remount restores only unknown intended edit with no automatic IO; new browser namespace cannot inherit it',async()=>{
  const f=fixture(),p=await prepare(f);f.setHandler(()=>{throw Error('lost');});await assert.rejects(f.client.send(p));f.client.destroy();const fresh=fixture(f.values);assert.equal(fresh.calls.length,0);assert.equal(fresh.client.pending().digest,p.digest);const other=plain(fresh.boot);other.reviewerCollaboration.browser_context='d'.repeat(64);const workspace={...fresh.workspace,snapshot:()=>({...fresh.workspace.snapshot(),browser_context:'d'.repeat(64)})};const isolated=fresh.context.EverbranchWorkspaceComments.create(other,workspace);assert.equal(isolated.pending(),null);await assert.rejects(isolated.send(p));
});
test('oversized real response stream cancels before parse; no raw server/storage error escapes',async()=>{
  const f=fixture();let cancelled=false;f.setHandler(()=>({ok:true,status:200,body:{getReader:()=>({read:async()=>({done:false,value:new Uint8Array(262145)}),cancel:async()=>cancelled=true,releaseLock(){}})}}));await assert.rejects(f.client.show({kind:'ticket',id:T}));assert.equal(cancelled,true);assert.equal(f.values.size,0);
});
test('late recovery preserves matching settlement but flags destroyed or changed context; not_observed remains pending',async()=>{
  for(const move of ['destroy','scope','not_observed']){
    const f=fixture(),p=await prepare(f);f.setHandler(()=>{throw Error('lost');});await assert.rejects(f.client.send(p));
    const hold=deferred();f.setHandler(()=>hold.promise);const pending=f.client.recover();while(!f.calls.some(c=>c.url.endsWith('/status')))await turn();
    if(move==='scope')f.setArea(B);else f.client.destroy();
    hold.resolve(response(move==='not_observed'?{status:'not_observed',settled:false}:{status:'applied',settled:true,receipt:{request_id:p.command.request_id,digest:p.digest,comment_id:C,thread_revision:1}}));
    const out=await pending;assert.equal(out.stale_context,true);assert.equal(out.settled,move!=='not_observed');
    if(move==='not_observed')assert.equal(f.client.pending().digest,p.digest);else{assert.equal(f.client.pending(),null);assert.equal(f.client.draft(),null);}
  }
});
test('valid large Unicode thread remains readable through PHP-style escaped JSON without truncation',async()=>{
  const f=fixture(),target={kind:'ticket',id:T},view=f.view(target);view.thread_revision=14;
  view.comments=Array.from({length:14},(_,i)=>({id:id(100+i),body:'🌲'.repeat(4000),name:'Blaine',identity_status:'self_selected_display_name',created_at:'2026-10-24T12:00:00Z',mine:true}));
  const wire=JSON.stringify(view).replace(/[^\x00-\x7f]/g,c=>'\\u'+c.charCodeAt(0).toString(16).padStart(4,'0'));
  assert.ok(new TextEncoder().encode(wire).length>262144);let sent=false,released=false,cancelled=false;
  f.setHandler(()=>({ok:true,status:200,body:{getReader:()=>({read:async()=>sent?{done:true}:(sent=true,{done:false,value:new TextEncoder().encode(wire)}),cancel:async()=>{cancelled=true;},releaseLock:()=>{released=true;}})}}));
  const result=await f.client.show(target);assert.equal(result.comments.length,14);assert.equal(result.comments[13].body,'🌲'.repeat(4000));assert.equal(released,true);assert.equal(cancelled,false);assert.equal(f.writes,0);assert.equal(f.values.size,0);
});
