import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const source=await readFile(new URL('../../resources/js/enterprise/roll-call-projects.js',import.meta.url),'utf8');
const context=vm.createContext({console});vm.runInContext(source,context);const api=context.EverbranchRollProjects;
const plain=value=>JSON.parse(JSON.stringify(value));
const scopes=[{areaId:'a',areaName:'Facilities',data:{selected_area_id:'a',members:[{id:'1',name:'Fictional Alex'},{id:'2',name:'Fictional Morgan'}],projects:[{id:'p',name:'Roof <repair>',owner:{id:'2',name:'Fictional Morgan'},tasks:[{id:'t',title:'Inspect roof',assignee_id:'1',completed_at:null}],locations:[{name:'Sample hall'}],updated_at:'2026-10-23T11:00:00Z'},{id:'empty',name:'No assignments',created_by:'1',tasks:['non-readable-id']}],requests:[{id:'r',task:{project_id:'p',assignee_id:'1'},data:{location:'Sample hall'}}]},operations:{items:[{project_id:'p',owner_user_id:'2',ball_user_id:'1'}]},planning:{items:[{id:'c',kind:'contractor',title:'Fictional Roofer'},{id:'unlinked',kind:'contractor',title:'Other company'},{id:'s',kind:'schedule',status:'planned',data:{project_id:'p',contractor_id:'c'}},{id:'cancelled',kind:'schedule',status:'cancelled',data:{project_id:'empty',contractor_id:'unlinked'}}]}},{areaId:'b',areaName:'IT',data:{selected_area_id:'b',members:[{id:'3',name:'Fictional Terry'}],projects:[{id:'it',name:'Wi-Fi',archived:true,tasks:[],locations:[]}]}}];
test('rows only join returned project, current visible people, explicit contractor schedules and saved locations',()=>{
 const rows=plain(api.projectRows(scopes));assert.equal(rows.length,3);const p=rows[0];assert.equal(p.name,'Roof <repair>');assert.deepEqual(p.people.map(row=>row.name),['Fictional Alex','Fictional Morgan']);assert.deepEqual(p.contractors,[{id:'c',name:'Fictional Roofer'}]);assert.deepEqual(p.locations,['Sample hall']);assert.equal(p.completed,0);assert.equal(rows[1].owner,null,'Creator is not automatically project owner');assert.deepEqual(rows[1].contractors,[],'Cancelled or unrelated planning never invents an assignment');assert.deepEqual(rows[1].tasks,[],'Task IDs cannot disclose unreadable task detail');assert.equal(rows[2].status,'Archived');
});
test('search and every control compose over the same permission-checked rows',()=>{
 const rows=api.projectRows(scopes);for(const filters of [{query:'roof'},{area:'a',person:'1'},{contractor:'c'},{location:'Sample hall'},{status:'Active',query:'morgan'}])assert.deepEqual(plain(api.filter(rows,filters).map(row=>row.id)),['p']);assert.equal(api.filter(rows,{area:'b',person:'1'}).length,0);assert.equal(api.filter(rows,{query:'not returned'}).length,0);assert.equal(api.filter(rows,{status:'Archived'})[0].id,'it');assert.equal(api.filter(rows).length,3);
});
test('input snapshots are not mutated or expanded into invented project rosters',()=>{
 const before=JSON.stringify(scopes);api.filter(api.projectRows(scopes),{person:'missing'});assert.equal(JSON.stringify(scopes),before);assert.equal(api.projectRows([{data:{projects:[]}}]).length,0);
});
test('shared source keeps meetings secondary and routes all detail/actions through native guards',async()=>{
 const panels=await readFile(new URL('../../resources/views/enterprise/roll-call-panels.blade.php',import.meta.url),'utf8'),script=await readFile(new URL('../../resources/views/enterprise/roll-call-script.blade.php',import.meta.url),'utf8'),detail=await readFile(new URL('../../resources/views/enterprise/project-detail-script.blade.php',import.meta.url),'utf8');
 assert.match(panels,/<details id="roll-meeting-tools"[^>]*><summary>Priorities &amp; meeting notes/);assert.doesNotMatch(panels,/Keep the operational Roll Call tickets/);assert.match(script,/snapshot.can_lead\?await api/);assert.match(script,/epoch===rollProjectEpoch&&section==='roll_call'&&data===source/);assert.match(script,/selected:button.dataset.rollProjectId/);assert.match(detail,/mountDiscussion\('project',project.id\)/);assert.match(detail,/renderProjectWorkControls\(project\)/);assert.match(detail,/!current\(\)\|\|!summary.isConnected/);assert.doesNotMatch(detail,/innerHTML\s*=\s*project\./);
});
function overviewFixture(data) {
 const nodes=new Map(),pending=[],listeners=new Map();
 const get=id=>{if(!nodes.has(id))nodes.set(id,{value:'',textContent:'',innerHTML:'',options:[{textContent:'Any'}],querySelectorAll(){return[];},addEventListener(){}});return nodes.get(id);};
 const sandbox=vm.createContext({console,URL,URLSearchParams,Promise,Date,Set,Map,data,section:'roll_call',area:'a',transport:{mode:'private'},urls:{data:'/data?tenant=fixture'},$:get,esc:value=>String(value??'').replaceAll('<','&lt;'),rollEndpoint:(path,q)=>path+'?area_id='+q.area_id,document:{addEventListener:(type,fn)=>listeners.set(type,fn),dispatchEvent(){}},CustomEvent:class{},api:path=>new Promise((resolve,reject)=>pending.push({path,resolve,reject}))});
 vm.runInContext(source,sandbox);
 return {sandbox,get,pending,listeners,run:code=>vm.runInContext(code,sandbox)};
}
const overviewScript=(await readFile(new URL('../../resources/views/enterprise/roll-call-script.blade.php',import.meta.url),'utf8')).split('  const rollNavigation')[0].replace(/\{!! file_get_contents[^\n]*\n/,'');
test('refresh discards a denied workspace and all prior project/filter labels instead of redisplaying retained access',async()=>{
 const f=overviewFixture({areas:[{id:'a',name:'Allowed'},{id:'b',name:'Revoked'}]});vm.runInContext(overviewScript,f.sandbox);
 const first=f.run('rollLoadProjects()');assert.equal(f.pending.length,2);f.pending[0].resolve({selected_area_id:'a',projects:[{id:'ok',name:'Readable project',owner:{id:'1',name:'Readable person'}}]});f.pending[1].resolve({selected_area_id:'b',projects:[{id:'removed',name:'Former private project',owner:{id:'2',name:'Former private person'}}]});await first;
 assert.match(f.get('roll-projects-body').innerHTML,/Former private project/);assert.match(f.get('roll-projects-person').innerHTML,/Former private person/);
 const second=f.run('rollLoadProjects()');assert.doesNotMatch(f.get('roll-projects-body').innerHTML,/Former private project/);assert.doesNotMatch(f.get('roll-projects-person').innerHTML,/Former private person/);f.pending[2].resolve({selected_area_id:'a',projects:[{id:'ok',name:'Readable project'}]});f.pending[3].reject(Object.assign(new Error('Denied'),{status:403}));await second;
 assert.match(f.get('roll-projects-body').innerHTML,/Readable project/);assert.doesNotMatch(f.get('roll-projects-body').innerHTML,/Former private project/);assert.doesNotMatch(f.get('roll-projects-person').innerHTML,/Former private person/);assert.match(f.get('roll-projects-coverage').textContent,/1 workspace checks were unavailable/);
});
test('a delayed authorized projection cannot paint after navigation or replacement of the current workspace identity',async()=>{
 const f=overviewFixture({areas:[{id:'a',name:'Allowed'}]});vm.runInContext(overviewScript,f.sandbox);const result=f.run('rollLoadProjects()');f.sandbox.section='requests';f.pending[0].resolve({selected_area_id:'a',projects:[{id:'late',name:'Late private project'}]});await result;assert.doesNotMatch(f.get('roll-projects-body').innerHTML,/Late private project/);
 f.sandbox.section='roll_call';const next=f.run('rollLoadProjects()');f.sandbox.data={areas:[]};f.pending[1].resolve({selected_area_id:'a',projects:[{id:'late',name:'Other actor project'}]});await next;assert.doesNotMatch(f.get('roll-projects-body').innerHTML,/Other actor project/);
});
