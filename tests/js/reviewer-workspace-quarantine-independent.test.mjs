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
test('independent: malformed committed begin reply remains unknown and pending',async()=>{
 const f=fixture(),p=await prepare(f);
 f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body),v=await r.json();v.file.reservation.digest='f'.repeat(64);return response(v);});
 let error;try {await f.client.begin(p);} catch(e){error=e;}
 assert.equal(f.writes,1);assert.equal(f.client.pending().digest,p.digest);
 assert.equal(error?.outcome,'unknown');
});

test('independent: malformed complete reply remains unknown until exact recovery confirms quarantine',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected);await f.client.begin(p);
 f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);if(!url.endsWith('/complete'))return r;const v=await r.json();v.file.reservation.digest='f'.repeat(64);return response(v);});
 let e;try{await f.client.resume(p,selected);}catch(err){e=err;}
 assert.equal(f.completions,1);assert.equal(f.record.status,'quarantined');assert.equal(f.client.pending().digest,p.digest);assert.equal(e?.outcome,'unknown');
 f.setHandler(null);assert.equal((await f.client.recover()).status,'quarantined');assert.equal(f.client.pending(),null);
});
test('independent: malformed cancellation reply remains unknown and never discards matching pending',async()=>{
 const f=fixture(),p=await prepare(f);await f.client.begin(p);
 f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);const v=await r.json();if(url.endsWith('/cancel'))v.file.target.id=id(88);return response(v);});
 let e;try{await f.client.cancel();}catch(err){e=err;}
 assert.equal(f.record.status,'cancelled');assert.equal(f.client.pending().digest,p.digest);assert.equal(e?.outcome,'unknown');
 f.setHandler(null);assert.equal((await f.client.recover()).status,'cancelled');assert.equal(f.client.pending(),null);
});
test('independent: expired held recovery yields receipt only without resurrecting file metadata',async()=>{
 const f=fixture(),p=await prepare(f);await f.client.begin(p);const hold=deferred();
 f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);await hold.promise;return r;});
 const reading=f.client.recover();while(!f.calls.some(c=>c.url.endsWith('/status')))await turn();
 f.context.Date=class extends Date{static now(){return Date.parse('2100-01-01T00:00:00Z');}};hold.resolve();
 const r=await reading;assert.equal(r.stale_context,true);assert.equal(r.file,undefined);assert.equal(r.receipt.digest,p.digest);assert.ok(f.client.pending());
});
test('independent: late destroyed completion cannot clear a remounted newer exact pending receipt',async()=>{
 const f=fixture(),selected=file(),p=await prepare(f,selected);await f.client.begin(p);const hold=deferred();
 f.setHandler(async(url,init,body)=>{const r=await f.server(url,init,body);if(url.endsWith('/complete'))await hold.promise;return r;});
 const old=f.client.resume(p,selected);while(!f.calls.some(c=>c.url.endsWith('/complete')))await turn();f.client.destroy();
 const next=fixture(f.values);next.copyRecord(f);await next.client.show(p.command.target);assert.equal((await next.client.recover()).status,'quarantined');
 const v=await next.client.show(p.command.target),p2=await next.client.prepare(v,file(71),{request_id:id(10),expected_reviewer_name:'Blaine'});
 next.setHandler(async(url,init,body)=>{if(url.endsWith('/begin'))throw Error('lost new receipt');return next.server(url,init,body);});await assert.rejects(next.client.begin(p2));
 const durable=[...f.values.values()][0];hold.resolve();const out=await old;assert.equal(out.stale_context,true);assert.equal(out.file,undefined);
 assert.equal([...f.values.values()][0],durable);assert.equal(next.client.pending().digest,p2.digest);
});
