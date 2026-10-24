import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
import vm from 'node:vm';
const resource=p=>new URL('../../'+p,import.meta.url);
const [mapSource,transportSource,viewsSource,fixture]=await Promise.all([
  readFile(resource('resources/js/enterprise/reviewer/shared-workspace-map.js'),'utf8'),
  readFile(resource('resources/js/enterprise/reviewer/shared-workspace.js'),'utf8'),
  readFile(resource('resources/js/enterprise/work-views.js'),'utf8'),
  readFile(resource('tests/Fixtures/proposals/reviewer-workspace-map-seed.json'),'utf8'),
]);
const plain=v=>JSON.parse(JSON.stringify(v)),graph=JSON.parse(fixture).graph,area=graph.areas[0].id;
const selection={persona:'demo_manager',area_ids:[area],person_id:null};
function mount({helper=true,alter=()=>{}}={}){
  const visible=plain(graph);
  for(const [key,rows] of Object.entries(visible))if(Array.isArray(rows))visible[key]=rows.filter(r=>key==='areas'?r.id===area:key==='people'?r.area_ids.includes(area):r.area_id===area);
  for(const kind of ['projects','tasks','tickets','plans'])for(const row of visible[kind]){row.allowed_actions=[];if(kind==='tickets'){delete row.owner_capability_hash;row.owned_by_caller=false;}}
  alter(visible);
  let calls=0;
  const boot={identity:{tenantSlug:'fictional'},routes:{back:'https://workspace.invalid/private/campus',data:'/enterprise/data?tenant=fictional',enterprise:'/enterprise'},reviewerWorkspace:{enabled:true,schema_version:1,workspace_id:'90000000-0000-4000-8000-000000000001',browser_context:'8'.repeat(64),seed_sha256:'c'.repeat(64),policy_sha256:'d'.repeat(64),expires_at:'2099-01-01T00:00:00Z',binding:{version_hash:'a'.repeat(64),build_id:'synthetic-map',source_hash:'b'.repeat(64)},selection,endpoints:{projection:'/private/workspace/projection',commands:'/private/workspace/commands',status:'/private/workspace/command-status',facts:'/private/workspace/facts'},csrf:'synthetic-not-credential'}};
  const context=vm.createContext({URL,URLSearchParams,TextEncoder,TextDecoder,crypto:webcrypto,AbortController,sessionStorage:{getItem:()=>null,setItem:()=>{throw Error('No writes authorized');}},location:{href:boot.routes.back,origin:'https://workspace.invalid'},fetch:async(url,init)=>{calls++;assert.equal(url,'https://workspace.invalid/private/workspace/projection');assert.deepEqual(JSON.parse(init.body),{binding:boot.reviewerWorkspace.binding,selection});return{ok:true,status:200,json:async()=>({workspace_id:boot.reviewerWorkspace.workspace_id,revision:1,workspace_digest:'e'.repeat(64),selection,allowed_areas:graph.areas,graph:visible,permitted_actions:[],capabilities:{can_view_costs:false,can_manage_costs:false,can_manage_plans:false},origin:'server_shared_fictional',expires_at:boot.reviewerWorkspace.expires_at})};}});
  if(helper)vm.runInContext(mapSource,context);vm.runInContext(transportSource,context);vm.runInContext(viewsSource,context);
  return{context,adapter:context.EverbranchSharedWorkspace.create(boot),get calls(){return calls;}};
}
test('actual separate transport hook maps explicit current source provenance to existing map markers',async()=>{
  const f=mount(),view=await f.adapter.readSelection(selection),data=f.adapter.data(view,area);
  assert.ok(data.buildings.items.length>0);assert.ok(data.buildings.items.every(b=>b.map_number&&b.source_url));
  const events=f.context.EverbranchWorkViews.workEvents(data,'requests',data.requests);
  assert.ok(events.length>0);assert.ok(events.every(e=>e.mapNumber));assert.equal(f.calls,1);
  const current=data.requests[0],buildings=data.buildings.items;
  for(const changed of [{...current,data:{...current.data,location_id:'foreign'}},{...current,data:{...current.data,location_version:99}},{id:'text-only',data:{location:buildings[0].title,latitude:36,longitude:-81}}])assert.equal(f.context.EverbranchWorkViews.mapNumber(changed,buildings),null);
});
test('present invalid or unavailable map helper rejects; old no-reference graphs retain compatibility',async()=>{
  const invalid=mount({alter:g=>g.buildings[0].map_reference.source_page=1});await assert.rejects(invalid.adapter.readSelection(selection));assert.equal(invalid.adapter.snapshot().revision,null);
  const missing=mount({helper:false});await assert.rejects(missing.adapter.readSelection(selection));
  const old=mount({helper:false,alter:g=>g.buildings.forEach(b=>delete b.map_reference)}),view=await old.adapter.readSelection(selection),data=old.adapter.data(view,area);
  assert.ok(data.buildings.items.every(b=>!Object.hasOwn(b,'map_number')));assert.equal(old.calls,1);
});
