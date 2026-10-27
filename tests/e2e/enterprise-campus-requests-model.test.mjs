import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const html=fs.readFileSync(new URL('../../resources/demos/lees-mcrae-campus.html',import.meta.url),'utf8');
const begin=html.indexOf('const OFFLINE_FIXTURE='),end=html.indexOf('const icons=',begin);assert(begin>=0&&end>begin);
const source=html.slice(begin,end),storageKey='lees-mcrae-fictional-hosted-v3';
function fixture(saved){const storage=new Map(saved?[[storageKey,JSON.stringify(saved)]]:[]);let refuseStorage=false;
 const context={structuredClone,URL,Response,location:{href:'https://campus-model.example.test/platform/demos/lees-mcrae'},sessionStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>{if(refuseStorage)throw Error('fictional quota refusal');storage.set(k,v);}}};vm.createContext(context);vm.runInContext(source,context);
 const raw=()=>JSON.parse(vm.runInContext('JSON.stringify(offline)',context));
 const call=async(path,body)=>{const response=await context.fetch('/api/'+path,body===undefined?{}:{method:'POST',body:JSON.stringify(body)});return {status:response.status,body:await response.json()};};
 const persona=async p=>{assert.equal((await call('persona',{persona:p})).status,200);};
 return {context,storage,raw,call,persona,refuseStorage:()=>{refuseStorage=true;}};}
const input=(key='fictional-intake-00001',extra={})=>({title:'Fictional request',department:'Facilities',details:'Fictional details',urgency:'normal',location_id:'J03',request_key:key,...extra});
async function create(f,key='fictional-intake-00001',extra={}){await f.persona('admin');await f.call('state');const r=await f.call('intake',input(key,extra));assert.equal(r.status,200);return r.body.request;}
const action=(r,key,extra={})=>({id:r.id,expected_version:r.version,request_key:key,...extra});
const file=(id='10000000-0000-4000-8000-000000000001',extra={})=>({id,name:'Fictional résumé.pdf',mime:'application/pdf',size:123,...extra});
function unchanged(f,before,stored){assert.deepEqual(f.raw(),before);assert.equal(f.storage.get(storageKey),stored);}
test('Normal intake and omitted assignment priority stay Normal; one committed assignment replays without duplicate history',async()=>{
 const f=fixture(),r=await create(f),body=action(r,'fictional-assign-00001',{project:'residence',assignee_id:'manager'}),before=f.raw();
 const assigned=await f.call('request-assign',body);assert.equal(assigned.status,200);const now=assigned.body.request;
 assert.equal(now.priority,'normal');assert.equal(now.requested_priority,'normal');assert.equal(now.priority_source,'task');assert.equal(now.assignment_history.length,1);assert.equal(now.assignment_history[0].action,'assigned');
 assert.equal(f.raw().demo.graph.tasks[now.task_id].priority,'normal');assert.equal(f.raw().demo.jobs[now.job_id].priority,3);
 assert.deepEqual(f.raw().demo.graph.projects,before.demo.graph.projects);for(const [id,t]of Object.entries(before.demo.graph.tasks))assert.deepEqual(f.raw().demo.graph.tasks[id],t);
 const applied=f.raw();assert.equal((await f.call('request-assign',body)).body.replayed,true);assert.deepEqual(f.raw(),applied);
});
test('invalid priority building and unknown fields refuse without partial request/task/job mutations',async()=>{
 const f=fixture(),r=await create(f);for(const fields of [{priority:'invalid'},{priority:'high',location_id:'not-a-building'},{location_id:null},{location_id:'J03',url:'https://private.invalid'}]){
 const before=f.raw(),stored=f.storage.get(storageKey),res=await f.call('request-update',action(r,'fictional-invalid-0001',fields));assert.equal(res.status,422);unchanged(f,before,stored);}
 const assigned=await f.call('request-assign',action(r,'fictional-assign-00002',{project:'residence',assignee_id:'manager'}));const current=assigned.body.request,before=f.raw(),stored=f.storage.get(storageKey);
 assert.equal((await f.call('request-update',action(current,'fictional-invalid-0002',{priority:'urgent',location_id:'unapproved'}))).status,422);unchanged(f,before,stored);
});
test('building correction is versioned and stable; stale new intent and changed replay fail without writes',async()=>{
 const f=fixture(),r=await create(f),body=action(r,'fictional-building-0001',{location_id:'J02'});const corrected=await f.call('request-update',body);assert.equal(corrected.status,200);assert.equal(corrected.body.request.location_id,'J02');
 const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-update',body)).body.replayed,true);unchanged(f,before,stored);
 const conflict=await f.call('request-update',{...body,location_id:'J01'});assert.equal(conflict.status,409);assert.equal(conflict.body.definitive_not_applied,false);unchanged(f,before,stored);
 const stale=await f.call('request-update',{...body,request_key:'fictional-building-0002'});assert.equal(stale.status,409);assert.equal(stale.body.definitive_not_applied,true);unchanged(f,before,stored);
});
test('visible requester intake does not grant hidden r2 or invent an operations job or historical assignment',async()=>{
 const f=fixture();await f.persona('staff');const row=(await f.call('request?id=REQ-STAGE-07')).body.request;
 assert.equal(row.has_linked_task,true);assert.equal(row.task_id,null);assert.equal(row.task_access,'restricted');assert.equal(row.can_open_task,false);assert.equal(row.can_assign,false);assert.equal(row.can_reassign,false);assert.equal(row.job_id,null);assert.equal(row.job_access,'not_linked');assert.equal(row.history_recorded,false);assert.deepEqual(row.assignment_history,[]);
 const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-assign',action(row,'fictional-denied-00001',{project:'residence',assignee_id:'staff'}))).status,403);unchanged(f,before,stored);
 assert.equal((await f.call('command',{command:{kind:'task.update',id:'r2',expected_version:1,idempotency_key:'forged-task-attempt',changes:{assignee_id:'staff'}}})).status,403);assert.deepEqual(f.raw(),before);
});
test('authorized reassignment updates the explicit task only when no job exists and never widens membership',async()=>{
 const f=fixture();await f.persona('admin');const r=(await f.call('request?id=REQ-STAGE-07')).body.request,before=f.raw(),body=action(r,'fictional-reassign-0001',{project:'residence',assignee_id:'specialist'});
 const changed=await f.call('request-assign',body);assert.equal(changed.status,200);assert.equal(changed.body.request.job_id,null);assert.equal(changed.body.request.status,r.status);assert.deepEqual(f.raw().demo.graph.tasks.r2.memberships,before.demo.graph.tasks.r2.memberships);assert.deepEqual(f.raw().demo.jobs,before.demo.jobs);
 assert.equal(changed.body.request.assignment_history.length,1);assert.equal(changed.body.request.assignment_history[0].action,'reassigned');assert.equal(changed.body.request.assignment_history[0].from_assignee_id,'manager');assert.equal(changed.body.request.assignee_id,'specialist');
 const current=f.raw();assert.equal((await f.call('request-assign',body)).body.replayed,true);assert.deepEqual(f.raw(),current);
 await f.persona('staff');const hidden=(await f.call('request?id=REQ-STAGE-07')).body.request;assert.equal(hidden.task_id,null);assert.equal(hidden.assignment_history[0].details_available,false);assert.equal('from_assignee_id'in hidden.assignment_history[0],false);
});
test('invalid recipient or implicit project move refuses before assignment and history',async()=>{
 const f=fixture();await f.persona('admin');const r=(await f.call('request?id=REQ-STAGE-07')).body.request;
 for(const fields of [{project:'residence',assignee_id:'it'},{project:'commons',assignee_id:'manager'}]){const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-assign',action(r,'fictional-bad-assign01',fields))).status,422);unchanged(f,before,stored);}
});
test('request comments are literal bounded and once-only; a requester never writes to the linked hidden task',async()=>{
 const f=fixture();await f.persona('staff');const r=(await f.call('request?id=REQ-STAGE-07')).body.request,taskBefore=f.raw().demo.graph.tasks.r2,body=action(r,'fictional-comment-0001',{body:'<img src=x onerror=alert(1)> Fictional comment'});
 const added=await f.call('request-comment',body);assert.equal(added.status,200);assert.equal(added.body.request.comments[0].body,body.body);assert.deepEqual(f.raw().demo.graph.tasks.r2,taskBefore);
 const before=f.raw();assert.equal((await f.call('request-comment',body)).body.replayed,true);assert.deepEqual(f.raw(),before);
 const bad=await f.call('request-comment',action(added.body.request,'fictional-comment-0002',{body:'x'.repeat(4001)}));assert.equal(bad.status,422);assert.deepEqual(f.raw(),before);
});
test('file metadata is local unscanned unshared and exact replay never appends it twice',async()=>{
 const f=fixture(),r=await create(f),body=action(r,'fictional-file-000001',{file:file()});const result=await f.call('request-attachment',body);assert.equal(result.status,200);
 const saved=result.body.request.attachments[0];assert.equal(saved.storage,'browser_metadata_only');assert.equal(saved.scan_status,'not_scanned');assert.equal(saved.shared,false);assert.equal(saved.requires_reselection,true);assert.equal('url'in saved,false);assert.equal('bytes'in saved,false);
 const before=f.raw();assert.equal((await f.call('request-attachment',body)).body.replayed,true);assert.deepEqual(f.raw(),before);
});
test('invalid or partially valid intake attachment lists are atomic and reject bytes URL path type and size widening',async()=>{
 const invalid=[file(undefined,{name:'../secret.pdf'}),file(undefined,{mime:'text/html'}),file(undefined,{size:5242881}),{...file(),url:'blob:caller'},{...file(),bytes:'caller-base64'}];
 for(let i=0;i<invalid.length;i++){const f=fixture();await f.persona('staff');const before=f.raw(),stored=f.storage.get(storageKey),res=await f.call('intake',input('fictional-files-'+String(i).padStart(5,'0'),{files:[file('20000000-0000-4000-8000-000000000002'),invalid[i]]}));assert.equal(res.status,422);unchanged(f,before,stored);}
 const f=fixture(),r=await create(f,'fictional-files-valid',{files:[file()]});assert.equal(r.attachments.length,1);assert.equal((await f.call('intake',input('fictional-files-valid',{files:[file()]}))).body.replayed,true);
});
test('legacy v3 state and absent history survive read without reset or backfilled events',async()=>{
 const f=fixture();await f.persona('staff');const original=f.raw();delete original.demo.requests[0].version;original.private_fixture_marker={preserve:'unrelated saved work'};const restored=fixture(original),before=restored.raw(),stored=restored.storage.get(storageKey);
 const r=await restored.call('request?id=REQ-104');assert.equal(r.status,200);assert.equal(r.body.request.version,1);assert.equal(r.body.request.history_recorded,false);unchanged(restored,before,stored);assert.equal(restored.raw().private_fixture_marker.preserve,'unrelated saved work');
});
test('legacy review route preserves saved Normal priority and exactly reuses its original assignment',async()=>{
 const f=fixture(),r=await create(f),body={id:r.id,project:'residence',assignee_id:'manager'};const assigned=await f.call('review',body);assert.equal(assigned.status,200);assert.equal(assigned.body.request.priority,'normal');assert.equal(assigned.body.request.assignment_history.length,1);const before=f.raw();assert.equal((await f.call('review',body)).body.replayed,true);assert.deepEqual(f.raw(),before);
});
test('browser storage failure holds the original intent with memory and stored records unchanged',async()=>{
 const f=fixture(),r=await create(f),before=f.raw(),stored=f.storage.get(storageKey);f.refuseStorage();const result=await f.call('request-update',action(r,'fictional-storage-0001',{priority:'high'}));assert.equal(result.status,503);assert.equal(result.body.definitive_not_applied,false);unchanged(f,before,stored);
});

test('unmodified legacy intake key recovers the existing row with omitted optional fields and no new receipt or history',async()=>{
 const f=fixture();await f.persona('staff');const saved=f.raw();saved.demo.requests.push({id:'REQ-LEGACY',title:'Fictional request',department:'Facilities',details:'Fictional details',priority:'normal',request_key:'fictional-legacy-0001',requester:'staff',status:'New',task_id:null});
 const old=fixture(saved),before=old.raw(),stored=old.storage.get(storageKey);const result=await old.call('intake',{title:'Fictional request',department:'Facilities',details:'Fictional details',request_key:'fictional-legacy-0001'});
 assert.equal(result.status,200);assert.equal(result.body.id,'REQ-LEGACY');assert.equal(result.body.replayed,true);assert.equal(result.body.legacy_recovery,true);unchanged(old,before,stored);
});
test('bounded comments attachments and history HOLD at capacity without erasure or partial change',async()=>{
 const f=fixture(),r=await create(f),saved=f.raw(),row=saved.demo.requests.find(x=>x.id===r.id);row.request_comments=Array.from({length:100},(_,i)=>({id:'old-comment-'+i,body:'retained',author_id:'staff',created_at:'2026-10-20T00:00:00Z'}));row.request_attachments=Array.from({length:20},(_,i)=>({...file(),id:'old-file-'+i}));row.assignment_history=Array.from({length:100},(_,i)=>({id:'old-history-'+i,action:'assigned',at:'2026-10-20T00:00:00Z'}));
 const full=fixture(saved),before=full.raw(),stored=full.storage.get(storageKey);
 for(const [path,fields]of [['request-comment',{body:'extra'}],['request-attachment',{file:file()}],['request-assign',{project:'residence',assignee_id:'manager'}]]){assert.equal((await full.call(path,action(r,'fictional-capacity-'+path,fields))).status,409);unchanged(full,before,stored);}
});
test('unknown modern missing receipt or malformed response state never causes a repair or partial write',async()=>{
 const f=fixture(),r=await create(f),saved=f.raw();delete saved.request_receipts;const broken=fixture(saved),before=broken.raw(),stored=broken.storage.get(storageKey);
 const result=await broken.call('intake',input());assert.equal(result.status,409);assert.equal(result.body.definitive_not_applied,false);unchanged(broken,before,stored);
 const malformed=f.raw();malformed.demo.requests.find(x=>x.id===r.id).request_comments={unexpected:'must hold'};const bad=fixture(malformed),rawBefore=bad.raw(),storeBefore=bad.storage.get(storageKey);
 assert.equal((await bad.call('request-update',action(r,'fictional-malformed-01',{priority:'high'}))).status,409);unchanged(bad,rawBefore,storeBefore);
});

test('same owner does not fabricate reassignment history and omitted legacy Low remains Low',async()=>{
 const f=fixture();await f.persona('admin');const base=f.raw();base.demo.graph.tasks.r2.priority='low';const old=fixture(base),r=(await old.call('request?id=REQ-STAGE-07')).body.request;
 assert.equal(r.priority,'low');const result=await old.call('request-assign',action(r,'fictional-low-sameowner',{project:'residence',assignee_id:'manager'}));assert.equal(result.status,200);assert.equal(result.body.request.priority,'low');assert.equal(result.body.request.history_recorded,false);assert.deepEqual(result.body.request.assignment_history,[]);assert.equal(old.raw().demo.graph.tasks.r2.priority,'low');
 const update=await old.call('request-assign',action(result.body.request,'fictional-low-newowner',{project:'residence',assignee_id:'specialist'}));assert.equal(update.status,200);assert.equal(update.body.request.priority,'low');assert.equal(update.body.request.assignment_history.length,1);
});
test('old task owner control synchronizes only explicit linked task/job, and replay or unchanged owner adds no event',async()=>{
 const f=fixture(),r=await create(f),assigned=(await f.call('request-assign',action(r,'fictional-linked-create1',{project:'residence',assignee_id:'manager'}))).body.request,t=f.raw().demo.graph.tasks[assigned.task_id];
 const command={kind:'task.update',id:t.id,expected_version:t.version,idempotency_key:'fictional-old-task-001',changes:{assignee_id:'specialist'}};
 assert.equal((await f.call('command',{command})).status,200);let row=(await f.call('request?id='+r.id)).body.request;assert.equal(row.assignee_id,'specialist');assert.equal(f.raw().demo.jobs[row.job_id].assignee_id,'specialist');assert.equal(row.assignment_history.length,2);
 const before=f.raw();assert.equal((await f.call('command',{command})).body.replayed,true);assert.deepEqual(f.raw(),before);
 const current=f.raw().demo.graph.tasks[t.id];assert.equal((await f.call('command',{command:{...command,expected_version:current.version,idempotency_key:'fictional-old-task-002'}})).status,200);row=(await f.call('request?id='+r.id)).body.request;assert.equal(row.assignment_history.length,2);
 const denied={...command,expected_version:f.raw().demo.graph.tasks[t.id].version,idempotency_key:'fictional-old-task-003',changes:{title:'must roll back',assignee_id:'it'}},snapshot=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('command',{command:denied})).status,403);unchanged(f,snapshot,stored);
});
test('old operations assignment synchronizes explicit task owner once, preserving denial and unchanged owner behavior',async()=>{
 const f=fixture(),r=await create(f),assigned=(await f.call('request-assign',action(r,'fictional-linked-create2',{project:'residence',assignee_id:'specialist'}))).body.request,job=f.raw().demo.jobs[assigned.job_id];
 const body={action:'assign',id:job.id,expected_version:job.version,operation_key:'fictional-old-ops-0001',worker_id:'manager',phase:'active'};
 assert.equal((await f.call('operations',body)).status,200);let row=(await f.call('request?id='+r.id)).body.request;assert.equal(row.assignee_id,'manager');assert.equal(f.raw().demo.graph.tasks[row.task_id].assignee_id,'manager');assert.equal(row.assignment_history.length,2);
 const before=f.raw();assert.equal((await f.call('operations',body)).body.replayed,true);assert.deepEqual(f.raw(),before);
 const same={...body,expected_version:f.raw().demo.jobs[job.id].version,operation_key:'fictional-old-ops-0002'};assert.equal((await f.call('operations',same)).status,200);row=(await f.call('request?id='+r.id)).body.request;assert.equal(row.assignment_history.length,2);
 await f.persona('staff');const snapshot=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('operations',{...same,expected_version:f.raw().demo.jobs[job.id].version,operation_key:'fictional-old-ops-0003',worker_id:'specialist'})).status,403);unchanged(f,snapshot,stored);
});

test('closed request input shape rejects null and arrays without state mutation',async()=>{
 const f=fixture(),r=await create(f),before=f.raw(),stored=f.storage.get(storageKey);
 for(const body of [null,[],{...action(r,'fictional-closed-0001',{priority:'normal'}),file_bytes:'not-allowed'}]){assert.equal((await f.call('request-update',body)).status,422);unchanged(f,before,stored);}
});

test('controlled J05 or an orphan request cannot rebind a job already linked to another task',async()=>{
 const f=fixture(),r=await create(f),saved=f.raw();saved.demo.jobs.J05.task_id='r2';saved.demo.jobs.J05.assignee_id=saved.demo.graph.tasks.r2.assignee_id;
 const old=fixture(saved),before=old.raw(),stored=old.storage.get(storageKey);assert.equal((await old.call('demo-incoming',{})).status,409);unchanged(old,before,stored);
 saved.demo.requests.find(x=>x.id===r.id).job_id='J05';const orphan=fixture(saved),rawBefore=orphan.raw(),storedBefore=orphan.storage.get(storageKey);
 assert.equal((await orphan.call('request-assign',action(r,'fictional-orphan-0001',{project:'residence',assignee_id:'manager'}))).status,409);unchanged(orphan,rawBefore,storedBefore);
});

test('missing controlled example is not lazily created to satisfy intake association',async()=>{
 const f=fixture();await f.persona('admin');const before=f.raw(),stored=f.storage.get(storageKey);assert.equal(before.demo.jobs.J05,undefined);
 assert.equal((await f.call('demo-incoming',{})).status,409);unchanged(f,before,stored);
});

test('old linked task priority invalidates an open request form; fresh omitted reassignment keeps current priority',async()=>{
 const f=fixture(),r=await create(f),opened=(await f.call('request-assign',action(r,'fictional-stamp-create1',{project:'residence',assignee_id:'manager'}))).body.request,t=f.raw().demo.graph.tasks[opened.task_id];
 const command={kind:'task.update',id:t.id,expected_version:t.version,idempotency_key:'fictional-old-priority1',changes:{priority:'high'}};
 assert.equal((await f.call('command',{command})).status,200);let current=(await f.call('request?id='+r.id)).body.request;assert.equal(current.version,opened.version+1);assert.equal(current.priority,'high');assert.equal(current.assignment_history.length,1);
 const before=f.raw(),stored=f.storage.get(storageKey);for(const [path,fields]of [['request-update',{priority:'normal'}],['request-assign',{project:'residence',assignee_id:'specialist',priority:'normal'}]]){assert.equal((await f.call(path,action(opened,'fictional-stale-'+path,fields))).status,409);unchanged(f,before,stored);}
 assert.equal((await f.call('command',{command})).body.replayed,true);assert.deepEqual(f.raw(),before);
 const fresh=await f.call('request-assign',action(current,'fictional-fresh-priority',{project:'residence',assignee_id:'specialist'}));assert.equal(fresh.status,200);assert.equal(fresh.body.request.priority,'high');
 const afterFresh=fresh.body.request,task=f.raw().demo.graph.tasks[t.id];assert.equal((await f.call('command',{command:{...command,expected_version:task.version,idempotency_key:'fictional-same-priority'}})).status,200);current=(await f.call('request?id='+r.id)).body.request;assert.equal(current.version,afterFresh.version);
 const titleVersion=f.raw().demo.graph.tasks[t.id].version;assert.equal((await f.call('command',{command:{kind:'task.update',id:t.id,expected_version:titleVersion,idempotency_key:'fictional-title-only',changes:{title:'Unrelated title change'}}})).status,200);assert.equal((await f.call('request?id='+r.id)).body.request.version,current.version);
});
test('old linked operations rank and location invalidate forms without rewriting task priority or unrelated cost edits',async()=>{
 const f=fixture(),r=await create(f),opened=(await f.call('request-assign',action(r,'fictional-stamp-create2',{project:'residence',assignee_id:'manager'}))).body.request,j=f.raw().demo.jobs[opened.job_id];
 const rank={action:'priority',id:j.id,expected_version:j.version,operation_key:'fictional-old-rank-001',priority:7};assert.equal((await f.call('operations',rank)).status,200);
 let current=(await f.call('request?id='+r.id)).body.request;assert.equal(current.version,opened.version+1);assert.equal(current.priority,'normal');assert.equal(current.assignment_history.length,1);
 const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-assign',action(opened,'fictional-stale-rank01',{project:'residence',assignee_id:'specialist',priority:'normal'}))).status,409);unchanged(f,before,stored);
 const fresh=await f.call('request-assign',action(current,'fictional-fresh-rank01',{project:'residence',assignee_id:'specialist'}));assert.equal(fresh.status,200);assert.equal(f.raw().demo.jobs[j.id].priority,7);current=fresh.body.request;
 let job=f.raw().demo.jobs[j.id];assert.equal((await f.call('job',{id:j.id,expected_version:job.version,x:42,y:44})).status,200);let moved=(await f.call('request?id='+r.id)).body.request;assert.equal(moved.version,current.version+1);
 const moveBefore=f.raw(),moveStored=f.storage.get(storageKey);assert.equal((await f.call('request-update',action(current,'fictional-stale-location',{location_id:'J02'}))).status,409);unchanged(f,moveBefore,moveStored);
 job=f.raw().demo.jobs[j.id];assert.equal((await f.call('job',{id:j.id,expected_version:job.version,x:42,y:44})).status,200);assert.equal((await f.call('request?id='+r.id)).body.request.version,moved.version);
 job=f.raw().demo.jobs[j.id];assert.equal((await f.call('job',{id:j.id,expected_version:job.version,labor_minutes:20})).status,200);assert.equal((await f.call('request?id='+r.id)).body.request.version,moved.version);
});

test('requester submits once but requester and manager cannot edit submitted fields or use legacy assignment after role switch',async()=>{
 const f=fixture();await f.persona('staff');const r=(await f.call('intake',input('fictional-owner-rule-01'))).body.request;
 for(const role of ['staff','manager']){await f.persona(role);const view=(await f.call('request?id='+r.id)).body.request;assert.equal(view.can_edit,false);assert.equal(view.can_assign,false);assert.equal(view.can_reassign,false);
  for(const [path,body]of [['request-update',action(r,'fictional-denied-edit01',{title:'forbidden',details:'forbidden',priority:'urgent',location_id:'J02'})],['request-assign',action(r,'fictional-denied-assign',{project:'residence',assignee_id:'manager'})],['review',{id:r.id,project:'residence',assignee_id:'manager'}]]){const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call(path,body)).status,403);unchanged(f,before,stored);}
 }
 await f.persona('admin');const changed=await f.call('request-update',action(r,'fictional-admin-edit01',{title:'Admin corrected title',details:'Admin corrected details'}));assert.equal(changed.status,200);
 await f.persona('staff');const fresh=(await f.call('request?id='+r.id)).body.request;assert.equal(fresh.title,'Admin corrected title');assert.equal(fresh.can_edit,false);assert.equal(fresh.can_comment,true);assert.equal(fresh.can_attach,true);assert.deepEqual(fresh.change_history,[]);
});
test('Admin details edits audit actual before and after without changing task or job labels; no-op and exact replay append no audit',async()=>{
 const f=fixture(),r=await create(f),assigned=(await f.call('request-assign',action(r,'fictional-admin-audit01',{project:'residence',assignee_id:'manager'}))).body.request,before=f.raw();
 const body=action(assigned,'fictional-admin-audit02',{title:'Corrected request',details:'Corrected intake details',priority:'high',location_id:'J02'}),changed=await f.call('request-update',body);assert.equal(changed.status,200);const view=changed.body.request;
 assert.equal(view.change_history.length,2);const audit=view.change_history[1];assert.equal(audit.actor_id,'admin');assert.equal(typeof audit.actor_label,'string');assert.equal(audit.request_key,body.request_key);assert.match(audit.recorded_at,/^\d{4}-\d{2}-\d{2}T/);
 assert.deepEqual(audit.changes.map(x=>x.field),['title','details','priority','location_id']);assert.deepEqual(audit.changes[0],{field:'title',before:r.title,after:'Corrected request'});
 assert.equal(f.raw().demo.graph.tasks[view.task_id].title,before.demo.graph.tasks[view.task_id].title);assert.equal(f.raw().demo.graph.tasks[view.task_id].description,before.demo.graph.tasks[view.task_id].description);assert.equal(f.raw().demo.jobs[view.job_id].title,before.demo.jobs[view.job_id].title);
 const snapshot=f.raw();assert.equal((await f.call('request-update',body)).body.replayed,true);assert.deepEqual(f.raw(),snapshot);
 const same=await f.call('request-update',action(view,'fictional-admin-audit03',{title:view.title,details:view.details}));assert.equal(same.status,200);assert.equal(same.body.request.change_history.length,2);
 const changedBody=await f.call('request-update',{...body,details:'different intent'});assert.equal(changedBody.status,409);assert.equal(f.raw().demo.requests.find(x=>x.id===r.id).change_history.length,2);
});
test('Admin title details closed limits and stale form refuse atomically with no audit or work label changes',async()=>{
 const f=fixture(),r=await create(f);for(const fields of [{title:''},{title:'x'.repeat(201)},{details:'x'.repeat(3001)},{details:null},{details:'bad\u0000control'},{department:'Housing'},{type:'it'},{due:'2026-11-01'}]){const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-update',action(r,'fictional-details-bad01',fields))).status,422);unchanged(f,before,stored);}
 const updated=(await f.call('request-update',action(r,'fictional-details-good1',{title:'x'.repeat(200),details:''}))).body.request;assert.equal(updated.details,'');
 const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-update',action(r,'fictional-details-stale',{title:'Stale old form'}))).status,409);unchanged(f,before,stored);
});
test('linked old task and job controls deny nonadmin protected changes but preserve unrelated original task edits',async()=>{
 const f=fixture();await f.persona('admin');const row=(await f.call('request?id=REQ-STAGE-07')).body.request;await f.persona('manager');
 const task=f.raw().demo.graph.tasks.r2;for(const changes of [{priority:'urgent'},{assignee_id:'specialist'},{title:'must roll back',priority:'urgent'}]){const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('command',{command:{kind:'task.update',id:'r2',expected_version:task.version,idempotency_key:'fictional-linked-deny1',changes}})).status,403);unchanged(f,before,stored);}
 const unrelated=await f.call('command',{command:{kind:'task.update',id:'r2',expected_version:task.version,idempotency_key:'fictional-task-title01',changes:{title:'Separate task label'}}});assert.equal(unrelated.status,200);assert.equal(f.raw().demo.requests.find(r=>r.id===row.id).title,row.title);
 await f.persona('admin');const r=await create(f,'fictional-linked-job001'),assigned=(await f.call('request-assign',action(r,'fictional-linked-job002',{project:'residence',assignee_id:'manager'}))).body.request;
 await f.persona('manager');const job=f.raw().demo.jobs[assigned.job_id];for(const [path,body]of [['operations',{action:'assign',id:job.id,expected_version:job.version,operation_key:'fictional-linked-job003',worker_id:'specialist'}],['operations',{action:'priority',id:job.id,expected_version:job.version,operation_key:'fictional-linked-job004',priority:2}],['operations',{action:'contractor_assign',id:job.id,expected_version:job.version,operation_key:'fictional-linked-job005',contractor_id:'contractor',agreed_cost_cents:100}],['job',{id:job.id,expected_version:job.version,x:0.2,y:0.3}]]){const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call(path,body)).status,403);unchanged(f,before,stored);}
 const state=(await f.call('state')).body;const projected=state.operations.jobs.find(j=>j.id===job.id);assert.equal(projected.request_fields_admin_only,true);assert.equal(projected.can_edit_linked_request_fields,false);
});
test('Admin opened form loses authority on role switch even for exact applied replay; returns to Admin without new intent',async()=>{
 const f=fixture();await f.persona('staff');const r=(await f.call('intake',input('fictional-role-intake01'))).body.request;await f.persona('admin');const body=action(r,'fictional-role-switch01',{title:'Admin saved'}),result=await f.call('request-update',body);assert.equal(result.status,200);
 for(const role of ['staff','manager']){await f.persona(role);const before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('request-update',body)).status,403);unchanged(f,before,stored);}
 await f.persona('admin');const before=f.raw();assert.equal((await f.call('request-update',body)).body.replayed,true);assert.deepEqual(f.raw(),before);assert.equal(before.demo.requests.find(x=>x.id===r.id).change_history.length,1);
});
test('legacy rows have no invented admin audit and full audit capacity refuses entire change',async()=>{
 const f=fixture();await f.persona('admin');const r=(await f.call('request?id=REQ-STAGE-07')).body.request;assert.equal(r.change_history_recorded,false);assert.deepEqual(r.change_history,[]);
 const saved=f.raw(),row=saved.demo.requests.find(x=>x.id===r.id);row.change_history=Array.from({length:100},(_,i)=>({id:'retained-'+i,changes:[{field:'title',before:'before',after:'after'}]}));const full=fixture(saved),before=full.raw(),stored=full.storage.get(storageKey);
 assert.equal((await full.call('request-update',action(r,'fictional-audit-full01',{title:'must not partially save'}))).status,409);unchanged(full,before,stored);
});

test('Admin linked original controls record actual protected changes; task capability flags distinguish linked from unrelated',async()=>{
 const f=fixture();await f.persona('admin');const r=(await f.call('request?id=REQ-STAGE-07')).body.request,t=f.raw().demo.graph.tasks.r2;
 const command={kind:'task.update',id:t.id,expected_version:t.version,idempotency_key:'fictional-admin-old001',changes:{priority:'urgent',assignee_id:'specialist'}};assert.equal((await f.call('command',{command})).status,200);
 const row=(await f.call('request?id='+r.id)).body.request;assert.deepEqual(row.change_history[0].changes.map(x=>x.field),['priority','assignee_id']);assert.equal(row.change_history[0].actor_id,'admin');
 const current=f.raw();assert.equal((await f.call('command',{command})).body.replayed,true);assert.deepEqual(f.raw(),current);
 await f.persona('manager');const projected=vm.runInContext('offlineTask(offline.demo.graph.tasks.r2)',f.context);assert.equal(projected.request_fields_admin_only,true);assert.equal(projected.can_edit_linked_request_fields,false);
 const other=vm.runInContext('offlineTask(offline.demo.graph.tasks.v1)',f.context);assert.equal(other.request_fields_admin_only,false);assert.equal(other.can_edit_linked_request_fields,true);
});

test('Manager task form may retain unchanged owner and priority while editing separate task title and date',async()=>{
 const f=fixture();await f.persona('manager');const t=f.raw().demo.graph.tasks.r2,row=f.raw().demo.requests.find(r=>r.id==='REQ-STAGE-07'),beforeRequest=structuredClone(row);
 const command={kind:'task.update',id:t.id,expected_version:t.version,idempotency_key:'fictional-manager-form1',changes:{title:'Separate permitted task title',due_on:'2026-11-12',priority:t.priority,assignee_id:t.assignee_id}};
 assert.equal((await f.call('command',{command})).status,200);assert.equal(f.raw().demo.graph.tasks.r2.title,command.changes.title);assert.equal(f.raw().demo.graph.tasks.r2.due_on,command.changes.due_on);assert.deepEqual(f.raw().demo.requests.find(r=>r.id===row.id),beforeRequest);
 const current=f.raw().demo.graph.tasks.r2,before=f.raw(),stored=f.storage.get(storageKey);assert.equal((await f.call('command',{command:{...command,expected_version:current.version,idempotency_key:'fictional-manager-form2',changes:{...command.changes,title:'must not partially save',priority:'urgent'}}})).status,403);unchanged(f,before,stored);
});
