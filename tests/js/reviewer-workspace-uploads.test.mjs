import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash,webcrypto} from 'node:crypto';
import vm from 'node:vm';
const [shared,source]=await Promise.all(['shared-workspace.js','workspace-upload-client.js'].map(name=>readFile(new URL('../../resources/js/enterprise/reviewer/'+name,import.meta.url),'utf8')));
const id=n=>String(n).padStart(8,'0')+'-0000-4000-8000-000000000001',W=id(1),A=id(2),B=id(3),T=id(4),F=id(5);
const plain=v=>JSON.parse(JSON.stringify(v)),turn=()=>new Promise(r=>setImmediate(r));
const hash=v=>createHash('sha256').update(v).digest('hex');
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};};
const response=(value,status=200)=>({ok:status>=200&&status<300,status,json:async()=>plain(value)});
function fixture(values=new Map()){
 const binding={version_hash:'a'.repeat(64),source_hash:'b'.repeat(64),build_id:'synthetic-quarantine'};
 const boot={reviewerWorkspace:{binding,endpoints:{projection:'/private/workspace/projection'}},reviewerAttachments:{enabled:true,schema_version:1,workspace_id:W,browser_context:'c'.repeat(64),binding,expires_at:'2099-01-01T00:00:00Z',csrf:'synthetic-not-credential',endpoints:Object.fromEntries(['show','begin','status','chunk','complete','cancel'].map(a=>[a,'/private/workspace/files/'+a]))}};
 let snapshot={workspace_id:W,browser_context:'c'.repeat(64),persona:'demo_manager',area_ids:[A],person_id:null},handler=null,storageFailure=false,record=null,writes=0,completions=0;
 const listeners=new Set(),calls=[];
 const workspace={mode:'shared',snapshot:()=>plain(snapshot),subscribe:f=>{listeners.add(f);return()=>listeners.delete(f);}};
 const view=()=>record?{id:F,name:record.command.name,mime:record.command.mime,size:record.command.size,sha256:record.command.sha256,status:record.status,revision:record.revision,target:record.command.target,mine:true,reservation:{request_id:record.command.request_id,digest:record.digest},received:plain(record.chunks),chunk_bytes:262144,busy:false,preview_available:false,download_available:false,scan_status:'not_admitted'}:null;
 let context;
 const server=async(url,init,body)=>{
  if(url.endsWith('/show'))return response({workspace_id:W,target:body.target,target_version:1,can_upload:true,files:record&&JSON.stringify(body.target)===JSON.stringify(record.command.target)?[view()]:[],expires_at:boot.reviewerAttachments.expires_at,preview_available:false,scanner_available:false});
  if(url.endsWith('/begin')){const replayed=!!record;if(!record){writes++;record={command:body,digest:hash(context.EverbranchSharedWorkspace.canonical(body)),status:'uploading',revision:1,chunks:[]};}return response({status:'reserved',replayed,file:view()});}
  if(url.endsWith('/status'))return response(record?{status:'reserved',settled:true,file:view()}:{status:'not_observed',settled:false});
  if(url.endsWith('/chunk')){const part={index:body.index,size:Buffer.from(body.bytes,'base64').length,sha256:body.sha256};record.chunks=record.chunks.filter(c=>c.index!==part.index).concat(part);record.revision++;return response({status:'uploading',file:view()});}
  if(url.endsWith('/complete')){completions++;record.status='quarantined';record.revision++;return response({status:'quarantined',file:view()});}
  if(url.endsWith('/cancel')){record.status='cancelled';record.revision++;return response({status:'cancelled',file:{...view(),name:'Removed attachment'}});}
  throw Error('Forbidden route');
 };
 context=vm.createContext({URL,TextEncoder,TextDecoder,Uint8Array,AbortController,crypto:webcrypto,btoa:s=>Buffer.from(s,'binary').toString('base64'),location:{href:'https://workspace.invalid/private/campus',origin:'https://workspace.invalid'},sessionStorage:{getItem:k=>values.get(k)??null,setItem:(k,v)=>{if(storageFailure)throw Error('PRIVATE STORAGE');values.set(k,v);}},fetch:async(url,init)=>{const body=JSON.parse(init.body);calls.push({url,init,body});return handler?handler(url,init,body):server(url,init,body);}});
 vm.runInContext(shared,context);vm.runInContext(source,context);
 const client=context.EverbranchWorkspaceUploads.create(boot,workspace);
 return{boot,workspace,context,client,values,calls,view,server,setHandler:h=>handler=h,failStorage:()=>storageFailure=true,setArea:a=>{snapshot.area_ids=[a];listeners.forEach(f=>f(snapshot));},copyRecord:from=>{record=plain(from.record);},get record(){return record;},get writes(){return writes;},get completions(){return completions;}};
}
function file(size=70,fill=90){const data=new Uint8Array(size).fill(fill);return{name:'FICTIONAL.png',type:'image/png',size,arrayBuffer:async()=>data.slice().buffer};}
async function prepare(f,input=file()){const v=await f.client.show({kind:'ticket',id:T});return f.client.prepare(v,input,{request_id:id(9),expected_reviewer_name:'Blaine'});}
test('closed same-origin endpoints, one shared adapter and no automatic IO/cache on mount',async()=>{
 const f=fixture();assert.equal(f.calls.length,0);assert.equal(f.client,f.context.EverbranchWorkspaceUploads.create(f.boot,f.workspace));
 const v=await f.client.show({kind:'ticket',id:T});assert.equal(v.scanner_available,false);assert.equal(v.preview_available,false);assert.equal(f.values.size,0);
 const call=f.calls[0];assert.equal(call.init.credentials,'same-origin');assert.equal(call.init.redirect,'error');assert.equal(call.init.cache,'no-store');assert.equal(call.init.headers['X-CSRF-TOKEN'],'synthetic-not-credential');
 for(const path of ['https://foreign.invalid/begin','/enterprise/tasks/upload','/private/workspace/files/begin?url=x']){const b=plain(f.boot);b.reviewerAttachments.endpoints.begin=path;assert.throws(()=>f.context.EverbranchWorkspaceUploads.create(b,f.workspace));}
 assert.throws(()=>f.context.EverbranchWorkspaceUploads.create(f.boot,{mode:'sample'}));
});
test('intent is durable before begin; explicit chunks and complete persist quarantine without byte storage or ready claims',async()=>{
 const f=fixture(),selected=file(262144+17),p=await prepare(f,selected);let persisted=false;
 f.setHandler((url,init,body)=>{if(url.endsWith('/begin'))persisted=[...f.values.values()].some(v=>JSON.parse(v).pending?.digest===p.digest);return f.server(url,init,body);});
 const r=await f.client.begin(p);assert.equal(persisted,true);assert.equal(r.status,'reserved');assert.ok(f.client.pending());const done=await f.client.resume(p,selected);
 assert.equal(done.status,'quarantined');assert.equal(done.file.preview_available,false);assert.equal(done.file.scan_status,'not_admitted');assert.equal(f.client.pending(),null);assert.equal(f.writes,1);assert.equal(f.completions,1);assert.equal(f.calls.filter(c=>c.url.endsWith('/chunk')).length,2);
 assert.equal([...f.values.values()].some(v=>v.includes('bytes')||v.includes('WlpaWlpa')),false);
});
test('storage failure prevents initial reservation with zero begin HTTP and no raw error',async()=>{
 const f=fixture(),p=await prepare(f);f.failStorage();await assert.rejects(f.client.begin(p),e=>e.code==='upload_storage'&&!e.message.includes('PRIVATE'));assert.equal(f.calls.filter(c=>c.url.endsWith('/begin')).length,0);assert.equal(f.client.pending(),null);
});
test('lost unobserved begin retains exact intent; recover never redispatches and explicit retry uses same request',async()=>{
 const f=fixture(),p=await prepare(f);f.setHandler(()=>{throw Error('lost');});await assert.rejects(f.client.begin(p));f.setHandler(null);const status=await f.client.recover();assert.equal(status.settled,false);assert.equal(f.calls.filter(c=>c.url.endsWith('/begin')).length,1);assert.ok(f.client.pending());await f.client.begin(p);assert.equal(f.writes,1);assert.equal(f.calls.filter(c=>c.url.endsWith('/begin')).length,2);
 const changed=plain(p);changed.command.name='changed.png';await assert.rejects(f.client.begin(changed));assert.equal(f.writes,1);
});
test('lost accepted begin and reload recover same reservation; wrong reselected file makes zero chunk writes',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected);f.setHandler(async(url,init,body)=>{await f.server(url,init,body);throw Error('lost after reserve');});await assert.rejects(f.client.begin(p));f.client.destroy();
 const next=fixture(f.values);next.copyRecord(f);assert.equal(next.calls.length,0);assert.ok(next.client.pending());await next.client.show(p.command.target);const recovered=await next.client.recover();assert.equal(recovered.receipt.upload_id,F);await assert.rejects(next.client.resume(p,file(70,88)));assert.equal(next.calls.filter(c=>c.url.endsWith('/chunk')).length,0);const done=await next.client.resume(p,selected);assert.equal(done.status,'quarantined');assert.equal(next.writes,0);
});
test('lost committed chunk is recovered and skipped rather than replaced on explicit resume',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected);await f.client.begin(p);f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);if(url.endsWith('/chunk'))throw Error('lost saved chunk');return r;});await assert.rejects(f.client.resume(p,selected));assert.equal(f.record.chunks.length,1);f.setHandler(null);assert.equal((await f.client.recover()).settled,false);assert.equal((await f.client.resume(p,selected)).status,'quarantined');assert.equal(f.calls.filter(c=>c.url.endsWith('/chunk')).length,1);
});
test('late begin after destroy returns receipt only with stale marker and preserves recovery intent',async()=>{
 const f=fixture(),p=await prepare(f),hold=deferred();f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);await hold.promise;return r;});const saving=f.client.begin(p);while(!f.calls.some(c=>c.url.endsWith('/begin')))await turn();f.client.destroy();hold.resolve();const out=await saving;assert.equal(out.stale_context,true);assert.equal(out.file,undefined);assert.equal(out.receipt.upload_id,F);assert.ok(f.client.pending());
});
test('late recover after destroy or away/back withholds old file metadata while retaining matching receipt',async()=>{
 for(const move of ['destroy','awayback']){const f=fixture(),p=await prepare(f);await f.client.begin(p);const hold=deferred();f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);await hold.promise;return r;});const status=f.client.recover();while(!f.calls.some(c=>c.url.endsWith('/status')))await turn();if(move==='destroy')f.client.destroy();else{f.setArea(B);f.setArea(A);}hold.resolve();const out=await status;assert.equal(out.stale_context,true);assert.equal(out.file,undefined);assert.ok(f.client.pending());}
});
test('scope loss aborts held show and newer target supersedes old response',async()=>{
 const f=fixture(),hold=deferred();f.setHandler(()=>hold.promise);const pending=f.client.show({kind:'ticket',id:T});await turn();f.setArea(B);assert.equal(f.calls[0].init.signal.aborted,true);f.setArea(A);hold.resolve(response({}));await assert.rejects(pending,e=>e.code==='upload_context');
 const other=fixture(),first=deferred();let n=0;other.setHandler((u,i,b)=>++n===1?first.promise:other.server(u,i,b));const old=other.client.show({kind:'ticket',id:T});await turn();await other.client.show({kind:'task',id:id(8)});first.resolve(response({}));await assert.rejects(old,e=>e.code==='upload_context');
});
test('forged reservation cannot settle or replace pending ownership',async()=>{
 const f=fixture(),p=await prepare(f);f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body),v=await r.json();v.file.reservation.digest='f'.repeat(64);return response(v);});await assert.rejects(f.client.begin(p));assert.equal(f.client.pending().digest,p.digest);assert.ok([...f.values.values()].every(v=>JSON.parse(v).pending.upload_id===null));
});
test('explicit cancellation settles original reservation and keeps no stored bytes',async()=>{
 const f=fixture(),p=await prepare(f);await f.client.begin(p);const out=await f.client.cancel();assert.equal(out.status,'cancelled');assert.equal(f.client.pending(),null);assert.equal(f.calls.filter(c=>c.url.endsWith('/chunk')).length,0);
});
test('oversized streamed response is cancelled before parse without persisted fake completion',async()=>{
 const f=fixture();let cancelled=false;f.setHandler(()=>({ok:true,status:200,body:{getReader:()=>({read:async()=>({done:false,value:new Uint8Array(131073)}),cancel:async()=>cancelled=true,releaseLock(){}})}}));await assert.rejects(f.client.show({kind:'ticket',id:T}));assert.equal(cancelled,true);assert.equal(f.client.pending(),null);
});
test('another legitimate browser cannot inherit the pending reservation or selected bytes',async()=>{
 const f=fixture(),p=await prepare(f);await f.client.begin(p);const b=plain(f.boot);b.reviewerAttachments.browser_context='d'.repeat(64);const workspace={...f.workspace,snapshot:()=>({...f.workspace.snapshot(),browser_context:'d'.repeat(64)})};const other=f.context.EverbranchWorkspaceUploads.create(b,workspace);assert.equal(other.pending(),null);await assert.rejects(other.begin(p));
});
test('late complete after scope loss confirms content-free quarantine receipt and settles only original intent',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected),hold=deferred();await f.client.begin(p);f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);if(url.endsWith('/complete'))await hold.promise;return r;});const saving=f.client.resume(p,selected);while(!f.calls.some(c=>c.url.endsWith('/complete')))await turn();f.setArea(B);hold.resolve();const out=await saving;assert.equal(out.status,'quarantined');assert.equal(out.stale_context,true);assert.equal(out.file,undefined);assert.equal(f.client.pending(),null);
});
test('awaited begin digest cannot cross an away/back context generation before dispatch',async()=>{
 const f=fixture(),p=await prepare(f),hold=deferred();const original=f.context.crypto;f.context.crypto={subtle:{digest:async(...args)=>{await hold.promise;return original.subtle.digest(...args);}}};const saving=f.client.begin(p);await turn();f.setArea(B);f.setArea(A);hold.resolve();await assert.rejects(saving,e=>e.code==='upload_context');assert.equal(f.calls.filter(c=>c.url.endsWith('/begin')).length,0);assert.equal(f.client.pending(),null);
});
test('no pending intent can be silently replaced by another prepared upload',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected);const other=plain(p);other.command.request_id=id(10);other.digest=hash(f.context.EverbranchSharedWorkspace.canonical(other.command));await f.client.begin(p);await assert.rejects(f.client.begin(other));assert.equal(f.writes,1);assert.equal(f.client.pending().command.request_id,p.command.request_id);
});

test('opening a different ticket aborts old upload and fences held final metadata without losing pending intent',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected),hold=deferred();await f.client.begin(p);f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);if(url.endsWith('/chunk'))await hold.promise;return r;});const uploading=f.client.resume(p,selected);while(!f.calls.some(c=>c.url.endsWith('/chunk')))await turn();await f.client.show({kind:'ticket',id:id(8)});assert.equal(f.calls.find(c=>c.url.endsWith('/chunk')).init.signal.aborted,true);hold.resolve();await assert.rejects(uploading,e=>e.code==='upload_context');assert.ok(f.client.pending());assert.equal(f.calls.filter(c=>c.url.endsWith('/complete')).length,0);await assert.rejects(f.client.recover());
});

test('wrong-target file metadata in a server reply refuses instead of displaying a cross-thread file',async()=>{
 const f=fixture(),p=await prepare(f);await f.client.begin(p);f.setHandler(async(u,i,b)=>{const r=await f.server(u,i,b),v=await r.json();if(u.endsWith('/show'))v.files=[{...f.view(),target:{kind:'ticket',id:id(8)}}];return response(v);});await assert.rejects(f.client.show(p.command.target));
});

test('post-dispatch malformed reservation, status, chunk, complete and cancel replies remain unknown and keep original intent',async()=>{
 for(const stage of ['begin','status','chunk','complete','cancel']){
  const f=fixture(),selected=file(),p=await prepare(f,selected);if(stage!=='begin')await f.client.begin(p);
  f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body),v=await r.json();if(url.endsWith('/'+stage))v.file.reservation.digest='f'.repeat(64);return response(v);});
  const operation=stage==='begin'?()=>f.client.begin(p):stage==='status'?()=>f.client.recover():stage==='cancel'?()=>f.client.cancel():()=>f.client.resume(p,selected);
  await assert.rejects(operation,e=>e.outcome==='unknown');assert.ok(f.client.pending());assert.equal(f.client.pending().digest,p.digest);assert.equal(f.writes,1);
 }
});
test('after a committed chunk, a target change is unknown and original pending bytes are never replaced',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected),hold=deferred();await f.client.begin(p);f.setHandler(async(u,i,b)=>{const r=await f.server(u,i,b);if(u.endsWith('/chunk'))await hold.promise;return r;});const pending=f.client.resume(p,selected);while(!f.calls.some(c=>c.url.endsWith('/chunk')))await turn();f.setArea(B);hold.resolve();await assert.rejects(pending,e=>e.outcome==='unknown');assert.equal(f.client.pending().digest,p.digest);assert.equal(f.record.chunks.length,1);
});
