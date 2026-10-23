import{test}from'node:test';import assert from'node:assert/strict';import{readFile}from'node:fs/promises';import{webcrypto}from'node:crypto';import vm from'node:vm';
const [helper,source,catalog,rollSource]=await Promise.all(['../../resources/js/enterprise/reviewer/job-sample.js','../../resources/js/enterprise/reviewer/sample-adapter.js','../../resources/data/enterprise/lees-mcrae-locations.json','../../resources/js/enterprise/roll-call-projects.js'].map(path=>readFile(new URL(path,import.meta.url),'utf8'))),plain=value=>JSON.parse(JSON.stringify(value));
function fixture(options={}){const values=new Map(),context=vm.createContext({crypto:webcrypto,URL,URLSearchParams,Blob,TextEncoder,console,location:{href:'https://sample.example/platform/demos/lees-mcrae'},history:{replaceState(){}},fetch(){throw new Error('No network allowed');},sessionStorage:{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key),get length(){return values.size;},key:i=>[...values.keys()][i]||null}});vm.runInContext(helper,context);vm.runInContext(source,context);vm.runInContext(rollSource,context);const boot={runtime:'sample',defaultPersona:'demo_manager',identity:{workspace:'synthetic-jobs'},locations:JSON.parse(catalog).entries,routes:{back:'/platform/demos/lees-mcrae',workflowWorkspace:'/platform/demos/lees-mcrae/workflows'}},api=context.EverbranchSampleTransport;if(options.state)values.set(api.storageKey+":"+boot.identity.workspace,JSON.stringify(options.state));if(options.defaultPersona)boot.defaultPersona=options.defaultPersona;const adapter=api.create(boot);return{adapter,api,boot,values,jobApi:context.EverbranchJobSample,rollApi:context.EverbranchRollProjects};}
const send=(a,body)=>a.request('/enterprise/commands',{id:webcrypto.randomUUID(),request_id:webcrypto.randomUUID(),...body});
test('fictional manager job uses one task, exact retry and no grant authority',async()=>{const{adapter:a,api}=fixture(),body={action:'job.create',id:webcrypto.randomUUID(),request_id:webcrypto.randomUUID(),area_id:api.ids.area,title:'Synthetic job',assignee_id:2,priority:'high'},before=a.snapshot(),result=await send(a,body),again=await send(a,body);assert.equal(result.task_id,again.task_id);assert.equal(a.snapshot().projects.length,before.projects.length+1);assert.equal(a.snapshot().tasks.length,before.tasks.length+1);await assert.rejects(send(a,{...body,title:'Changed intent'}),/reus|different|match/i);await assert.rejects(send(a,{action:'workspace.create',title:'No grant'}),/outside the demo manager/);});
test('incoming routing joins exact saved building, project and task without a duplicate source ticket',async()=>{const{adapter:a,api}=fixture(),data=await a.request('/enterprise/data?area_id='+api.ids.area),ticket=data.requests.find(row=>!row.task&&['submitted','triaged'].includes(row.status)),target=await a.request('/enterprise/data?area_id='+api.ids.it),building=target.buildings.items[0],project=target.projects[0],result=await send(a,{action:'request.plan',id:ticket.id,area_id:ticket.area_id,expected_version:ticket.version,target_area_id:api.ids.it,project_id:project.id,assignee_id:2,location_id:building.id,location_version:building.version,acknowledge_routing:true}),refreshed=await a.request('/enterprise/data?area_id='+api.ids.it),saved=refreshed.requests.find(row=>row.id===ticket.id);assert.equal(saved.status,'assigned');assert.equal(saved.data.location_id,building.id);assert.equal(saved.data.location_version,building.version);assert.ok(refreshed.projects.find(row=>row.id===project.id).tasks.some(row=>row.id===result.task_id));assert.equal((await a.request('/enterprise/data?area_id='+api.ids.area)).requests.some(row=>row.id===ticket.id),false);});
test('stale and unavailable assignment roll back all sample bytes; worker cannot coordinate or read private scope',async()=>{const{adapter:a,api}=fixture(),data=await a.request('/enterprise/data?area_id='+api.ids.area),ticket=data.requests.find(row=>!row.task),before=plain(a.snapshot());await assert.rejects(send(a,{action:'request.plan',id:ticket.id,area_id:ticket.area_id,expected_version:999,target_area_id:ticket.area_id,project_id:data.projects[0].id,assignee_id:2,acknowledge_routing:true}),/changed/);assert.deepEqual(plain(a.snapshot()),before);await assert.rejects(send(a,{action:'job.create',title:'Refused',area_id:api.ids.area,assignee_id:99999}),/worker/);assert.deepEqual(plain(a.snapshot()),before);a.setPersona('worker');await assert.rejects(send(a,{action:'job.create',title:'Denied',area_id:api.ids.area,assignee_id:2}),/manager/);await assert.rejects(a.request('/enterprise/data?area_id='+api.ids.projects),/unavailable/);});
test('linked task edits preserve intake and versions; removal retains history and unrelated notes/uploads',async()=>{const{adapter:a,api}=fixture(),data=await a.request('/enterprise/data?area_id='+api.ids.area),project=data.projects.find(row=>row.tasks.some(task=>task.can_edit&&task.linked_request)),task=project.tasks.find(task=>task.can_edit&&task.linked_request),before=a.snapshot(),body={action:'task.update',id:task.id,area_id:api.ids.area,expected_version:task.version,title:'Revised synthetic task',priority:'urgent',assignee_id:4,related_request_id:task.linked_request.id,request_version:task.linked_request.version};await send(a,body);await assert.rejects(send(a,body),/changed/);const updated=(await a.request('/enterprise/data?area_id='+api.ids.area)).projects.find(row=>row.id===project.id).tasks.find(row=>row.id===task.id);assert.equal(updated.assignee_id,'4');assert.equal(updated.priority,'urgent');await send(a,{action:'task.delete',id:task.id,area_id:api.ids.area,expected_version:updated.version,related_request_id:updated.linked_request.id,request_version:updated.linked_request.version,acknowledge_removal:true});assert.ok(a.snapshot().tasks.find(row=>row.id===task.id).deleted);assert.equal((await a.request('/enterprise/data?area_id='+api.ids.area)).projects.find(row=>row.id===project.id).tasks.some(row=>row.id===task.id),false);assert.deepEqual(plain(a.snapshot().discussions),plain(before.discussions));assert.deepEqual(plain(a.snapshot().uploads||{}),plain(before.uploads||{}));});
test('Executive is read-only while explicit Blaine manager has only work commands and read-only voice lookup',async()=>{
 const{adapter:a,api}=fixture();let data=await a.request('/enterprise/data?area_id='+api.ids.area);assert.equal(data.demo_persona,'demo_manager');assert.equal(data.viewer_id,'1');assert.equal(data.can_coordinate,true);assert.equal(data.can_submit,true);assert.equal(data.can_manage,false);
 a.setPersona('executive');data=await a.request('/enterprise/data?area_id='+api.ids.area);assert.equal(data.viewer_id,'demo-executive');assert.equal(data.can_coordinate,false);assert.equal(data.can_submit,false);assert.ok(data.projects.every(row=>!row.can_edit&&!row.can_add_task&&row.tasks.every(task=>!task.can_edit)));assert.ok(data.requests.every(row=>!row.can_coordinate&&!row.can_progress));
 const before=plain(a.snapshot());for(const action of ['project.create','job.create','request.create','task.update'])await assert.rejects(send(a,{action,title:'Must remain blocked',area_id:api.ids.area}),{status:403});await assert.rejects(a.request('/enterprise/preferences',{area_id:api.ids.area}),{status:403});assert.deepEqual(plain(a.snapshot()),before);
 a.setPersona('demo_manager');const current=plain(a.snapshot());const voice=await a.request('/enterprise/voice/jobs',{question:'assign the event audio task',area_id:api.ids.area});assert.equal(voice.outcome,'review_required');assert.equal(voice.provider_dispatch,false);assert.deepEqual(plain(a.snapshot()),current);for(const[path,body]of [['/enterprise/commands',{action:'workspace.create',title:'No grant'}],['/enterprise/finance/commands',{action:'invoice.create'}],['/enterprise/operations/commands',{action:'meeting.start'}]])await assert.rejects(a.request(path,body),{status:403});assert.deepEqual(plain(a.snapshot()),current);
 const restricted=fixture({defaultPersona:'executive'});assert.throws(()=>restricted.adapter.setPersona('demo_manager'),/perspective/);const spoofed=fixture({defaultPersona:'executive',state:{...a.snapshot(),persona:'demo_manager'}});assert.equal(spoofed.adapter.snapshot().persona,'executive');await assert.rejects(send(spoofed.adapter,{action:'project.create',title:'No stored role escalation'}),{status:403});
});
test('trusted Blaine migration is once-only and retains records, uploads, notes and later Executive choice',async()=>{
 const original=fixture({defaultPersona:'executive'}).adapter.snapshot();delete original.sample_migrations?.['blaine-demo-manager-v1'];original.projects[0].name='Retained user project';original.discussions={synthetic:{text:'Retained note'}};original.uploads={synthetic:{name:'retained.txt'}};
 const migrated=fixture({state:original});assert.equal(migrated.adapter.snapshot().persona,'demo_manager');assert.ok(migrated.adapter.snapshot().sample_migrations['blaine-demo-manager-v1']);for(const key of ['projects','tasks','requests','resources','plans','discussions','uploads'])assert.deepEqual(plain(migrated.adapter.snapshot()[key]),plain(original[key]),key+' retained');
 migrated.adapter.setPersona('executive');const reloaded=fixture({state:migrated.adapter.snapshot()});assert.equal(reloaded.adapter.snapshot().persona,'executive');await assert.rejects(send(reloaded.adapter,{action:'project.create',title:'Still read only'}),{status:403});
});

async function planningConsumers(f) {
 const scopes=[];
 for(const area of f.adapter.snapshot().areas){
  const data=await f.adapter.request('/enterprise/data?area_id='+area.id);
  const planning=await f.adapter.request('/enterprise/planning?area_id='+area.id);
  scopes.push({areaId:area.id,areaName:area.name,data,planning});
 }
 return {
  contractors:scopes.flatMap(scope=>scope.planning.items.filter(row=>row.kind==='contractor')),
  schedules:scopes.flatMap(scope=>scope.planning.items.filter(row=>row.kind==='schedule')),
  projects:f.rollApi.projectRows(scopes),
 };
}
function legacyPlanningState() {
 const f=fixture(),state=plain(f.adapter.snapshot()),original=state.plans.find(row=>row.id===f.api.ids.contractor);
 // Persisted v1 split: original contractor is canonical; five definitions/eighteen schedules are orphaned.
 state.planning=plain(state.plans);
 const orphan=state.planning.find(row=>row.id===f.api.ids.contractor);
 orphan.data={contact_name:'Fictional contact1',fictional:true};
 state.plans=[{...original,title:'Sample campus contractor',data:{}}];
 delete state.sample_migrations[f.jobApi.plansRevision];
 return {state,ids:f.api.ids,repairRevision:f.jobApi.plansRevision};
}
test('fresh adapter exposes all five contractors and eighteen actual Roll Call schedule links',async()=>{
 const f=fixture(),visible=await planningConsumers(f);
 assert.equal(visible.contractors.length,5);
 assert.equal(new Set(visible.contractors.map(row=>row.id)).size,5);
 assert.equal(visible.schedules.length,18);
 assert.equal(visible.projects.length,20);
 assert.equal(visible.projects.filter(row=>row.contractors.length).length,18);
 assert.ok(visible.contractors.some(row=>row.title==='Appalachian Facility Services'));
 assert.equal(f.adapter.snapshot().planning,undefined);
 const again=await planningConsumers(fixture({state:plain(f.adapter.snapshot())}));
 assert.equal(again.contractors.length,5);
 assert.equal(again.projects.filter(row=>row.contractors.length).length,18);
});
test('already-v1 adapter repairs one canonical contractor to five visible contractors without reseeding jobs',async()=>{
 const {state,repairRevision}=legacyPlanningState();
 assert.equal(state.plans.filter(row=>row.kind==='contractor').length,1);
 assert.equal(state.planning.filter(row=>row.kind==='contractor').length,5);
 const before=plain(state),f=fixture({state}),after=f.adapter.snapshot(),visible=await planningConsumers(f);
 assert.ok(after.sample_migrations[repairRevision]);
 assert.equal(visible.contractors.length,5);
 assert.equal(visible.schedules.length,18);
 assert.equal(visible.projects.filter(row=>row.contractors.length).length,18);
 for(const key of Object.keys(before).filter(key=>!['plans','sample_migrations'].includes(key)))assert.deepEqual(plain(after[key]),before[key],key+' retained');
 assert.deepEqual(plain(after.sample_migrations['campus-jobs-20-v1']),before.sample_migrations['campus-jobs-20-v1']);
});
test('repair preserves canonical edits, orphan edits, unrelated state and missing projects',()=>{
 const {state,ids}=legacyPlanningState();
 const extra=plain(state.planning.find(row=>row.kind==='contractor'&&row.id!==ids.contractor));
 const schedule=plain(state.planning.find(row=>row.kind==='schedule'));
 Object.assign(state.plans[0],{title:'My retained contractor',version:7,data:{contact_name:'Retained contact',note:'User edit'}});
 Object.assign(extra,{title:'My archived contractor',status:'archived',version:9});
 Object.assign(schedule,{title:'My revised schedule',version:6,starts_on:'2027-01-12',ends_on:'2027-01-13'});
 state.plans.push(extra,schedule,{id:'user-cost',kind:'cost',version:4,title:'Retained cost',data:{amount:'123.45'}});
 const orphanSchedule=state.planning.filter(row=>row.kind==='schedule').at(-2);
 orphanSchedule.title='Retained orphan title';orphanSchedule.version=3;
 const removedProject=state.projects.at(-1).id;
 state.projects=state.projects.filter(row=>row.id!==removedProject);
 state.projects[0].name='Retained project';state.projects[0].version=17;
 state.discussions.synthetic={text:'Retained comment'};
 state.uploads.push({id:'user-upload',received_chunks:[0,2],status:'uploading'});
 const before=plain(state),after=fixture({state}).adapter.snapshot();
 assert.equal(new Set(after.plans.map(row=>row.id)).size,after.plans.length);
 for(const row of before.plans)assert.deepEqual(plain(after.plans.find(item=>item.id===row.id)),row);
 assert.deepEqual(plain(after.plans.find(row=>row.id===orphanSchedule.id)),orphanSchedule);
 assert.equal(after.projects.some(row=>row.id===removedProject),false);
 assert.equal(after.plans.some(row=>row.kind==='schedule'&&row.data?.project_id===removedProject),false);
 for(const key of Object.keys(before).filter(key=>!['plans','sample_migrations'].includes(key)))assert.deepEqual(plain(after[key]),before[key],key+' retained');
});
test('repair preserves pre-repair removals instead of reconstructing missing orphan records',()=>{
 const {state,ids}=legacyPlanningState();
 const removedContractor=state.planning.find(row=>row.kind==='contractor'&&row.id!==ids.contractor).id;
 const removedSchedule=state.planning.find(row=>row.kind==='schedule').id;
 state.plans=[];
 state.planning=state.planning.filter(row=>![removedContractor,removedSchedule].includes(row.id));
 const after=fixture({state}).adapter.snapshot();
 assert.equal(after.plans.some(row=>[ids.contractor,removedContractor,removedSchedule].includes(row.id)),false);
 assert.ok(after.plans.filter(row=>row.kind==='schedule').every(row=>after.plans.some(contractor=>contractor.kind==='contractor'&&contractor.id===row.data.contractor_id)));
});
test('serialized repair is once-only and retains later contractor/schedule/job deletion and new user plans',async()=>{
 const {state}=legacyPlanningState(),fixed=fixture({state}),edited=plain(fixed.adapter.snapshot());
 const removedContractor=edited.plans.find(row=>row.kind==='contractor'&&row.id!==fixed.api.ids.contractor).id;
 const removedSchedule=edited.plans.find(row=>row.kind==='schedule'&&row.data.contractor_id!==fixed.api.ids.contractor).id;
 edited.plans=edited.plans.filter(row=>![removedContractor,removedSchedule].includes(row.id));
 edited.projects=edited.projects.slice(0,-1);
 edited.plans.push({id:'my-plan',kind:'contractor',area_id:fixed.api.ids.area,title:'My later contractor',version:5,status:'active',data:{retained:true}});
 for(let pass=0;pass<3;pass++){
  const reloaded=fixture({state:plain(edited)});
  assert.deepEqual(plain(reloaded.adapter.snapshot()),edited);
  const visible=await planningConsumers(reloaded);
  assert.equal(visible.contractors.some(row=>row.id===removedContractor),false);
  assert.equal(visible.schedules.some(row=>row.id===removedSchedule),false);
 }
});
