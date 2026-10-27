import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync,spawnSync} from 'node:child_process';
import {readFile,mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {exportFreshSeed,canonical,SOURCE_PINS} from '../../scripts/proposals/reviewer-workspace-seed.mjs';

const root=fileURLToPath(new URL('../../',import.meta.url));
const fixture=resolve(root,'tests/Fixtures/proposals/reviewer-workspace-seed-validate.php');
const plain=value=>JSON.parse(JSON.stringify(value));
const php=graph=>spawnSync(process.env.PHP_BINARY||'php',[fixture],{input:JSON.stringify(graph),encoding:'utf8',env:{PATH:process.env.PATH}});
const expected={areas:4,buildings:25,people:11,projects:20,tasks:21,tickets:25,contractors:5,plans:18,costs:0};

test('fresh pinned fixture export is deterministic with all20jobs10workers5contractors and canonical plans',async()=>{
  const one=await exportFreshSeed(),two=await exportFreshSeed();
  assert.equal(canonical(one),canonical(two));
  assert.deepEqual(one.evidence.counts,expected);
  assert.equal(one.evidence.field_workers,10);
  assert.equal(new Set(one.evidence.field_worker_ids).size,10);
  assert.equal(one.evidence.graph_bytes,41011);
  assert.equal(one.evidence.graph_sha256,'1dc4ecb5d67e1ed71e60eb2308efc1db95d4252fb4000afe5b48070a4dcd4a63');
  assert.equal(createHash('sha256').update(canonical(one.graph)).digest('hex'),one.evidence.graph_sha256);
  assert.equal(one.evidence.forbidden_calls,0);
  assert.equal(one.evidence.canonical_plans_revision,'campus-job-plans-v2');
});

test('exact source joins retain21tasks and5incoming tickets without fabricating a link for emergency work',async()=>{
  const {graph}=await exportFreshSeed();
  const projects=new Map(graph.projects.map(row=>[row.id,row])),tasks=new Map(graph.tasks.map(row=>[row.id,row]));
  const buildings=new Map(graph.buildings.map(row=>[row.id,row])),contractors=new Map(graph.contractors.map(row=>[row.id,row]));
  for(const task of graph.tasks) assert.equal(projects.get(task.project_id).area_id,task.area_id);
  for(const ticket of graph.tickets){
    if(ticket.task_id){const task=tasks.get(ticket.task_id);assert.equal(task.project_id,ticket.project_id);assert.equal(task.area_id,ticket.area_id);assert.equal(task.building_id,ticket.building_id);}
    if(ticket.building_id)assert.equal(buildings.get(ticket.building_id).area_id,ticket.area_id);
  }
  const emergency=tasks.get('40000000-0000-4000-8000-000000000002');
  assert.ok(emergency);assert.equal(emergency.project_id,'20000000-0000-4000-8000-000000000001');assert.equal(emergency.building_id,null);
  assert.equal(graph.tickets.some(row=>row.task_id===emergency.id),false);
  assert.equal(graph.tickets.filter(row=>row.task_id===null&&row.project_id===null).length,5);
  for(const plan of graph.plans){assert.equal(projects.get(plan.project_id).area_id,plan.area_id);assert.equal(contractors.get(plan.contractor_id).area_id,plan.area_id);assert.ok(plan.starts_on<=plan.ends_on);}
});

test('only closed fictional graph fields survive; ownership timestamps and costs do not imply native data',async()=>{
  const bundle=await exportFreshSeed(),{graph}=bundle;
  assert.equal(bundle.evidence.recovers_browser_edits,false);assert.equal(bundle.evidence.initializes_runtime,false);assert.equal(bundle.evidence.publishes,false);
  const forbidden=new Set(['notes','note','progress_note','checklist','uploads','files','photos','contact_name','email','created_by','password','token','receipts','sessionStorage','finance','reviewer_name','owner_user_id','accountable_owner_id']);
  const walk=value=>{if(value&&typeof value==='object')for(const[key,v]of Object.entries(value)){assert.equal(forbidden.has(key),false,key);walk(v);}};
  walk(graph);
  assert.ok(graph.tickets.every(row=>row.owner_capability_hash===null));
  for(const rows of Object.values(graph).filter(Array.isArray)) for(const row of rows) if('updated_at'in row)assert.equal(row.updated_at,null);
  assert.deepEqual(graph.costs,[]);
  assert.ok(graph.people.every(row=>row.area_ids.length===4));
});

test('actual PHP closed Schema and actual Projector validate individual and combined scopes without HTTP DB or truncation',async()=>{
  const {graph,evidence}=await exportFreshSeed(),result=php(graph);
  assert.equal(result.status,0,result.stderr+result.stdout);
  const actual=JSON.parse(result.stdout);
  assert.equal(actual.valid,true);assert.equal(actual.graph_sha256,evidence.graph_sha256);assert.deepEqual(actual.counts,expected);
  assert.equal(actual.projector_scopes.length,5);
  const combined=actual.projector_scopes.at(-1);assert.equal(combined.projects,20);assert.equal(combined.tasks,21);assert.equal(combined.tickets,25);assert.equal(combined.sources,25);assert.ok(combined.bytes<24000);
  assert.ok(actual.projector_scopes.every(row=>row.coverage.startsWith('Server-held shared fictional workspace.')));
  // PHP8.5's pre-existing null-offset deprecation remains visible in explicit diagnostics; it is not an invented building.
  assert.ok(Array.isArray(actual.diagnostics));
  assert.ok(actual.diagnostics.every(row=>row.type==='php_deprecation'&&row.file==='ReviewerWorkspaceProjector.php'&&row.line===104));
});

test('actual PHP validation rejects foreign and missing relationships, recipient ownership and leaked private keys',async()=>{
  const {graph}=await exportFreshSeed();
  const variants=[];
  let changed=plain(graph);changed.tasks[0].project_id='ffffffff-ffff-4fff-8fff-ffffffffffff';variants.push(changed);
  changed=plain(graph);const task=changed.tasks[0];task.building_id=changed.buildings.find(row=>row.area_id!==task.area_id).id;variants.push(changed);
  changed=plain(graph);changed.tickets[0].owner_capability_hash='a'.repeat(64);variants.push(changed);
  changed=plain(graph);changed.people[0].email='must-not-enter@example.invalid';variants.push(changed);
  for(const bad of variants){const result=php(bad);assert.equal(result.status,1);assert.deepEqual(JSON.parse(result.stdout),{valid:false,error:'seed_validation_refused'});}
});

test('browser state and arbitrary CLI options are refused rather than treated as a seed source',async()=>{
  await assert.rejects(exportFreshSeed({state:{schema:1,projects:[]}}),/no browser state/);
  const result=spawnSync(process.execPath,[resolve(root,'scripts/proposals/reviewer-workspace-seed.mjs'),'--state=anything'],{encoding:'utf8',env:{PATH:process.env.PATH}});
  assert.equal(result.status,1);assert.equal(result.stdout,'');assert.match(result.stderr,/only optional --graph/);
  const wire=execFileSync(process.execPath,[resolve(root,'scripts/proposals/reviewer-workspace-seed.mjs'),'--graph'],{encoding:'utf8',env:{PATH:process.env.PATH}});
  assert.deepEqual(JSON.parse(wire),(await exportFreshSeed()).graph);
});

test('changed official source fingerprint refuses before running any changed fixture',async()=>{
  const temporary=await mkdtemp(resolve(tmpdir(),'reviewer-seed-source-'));
  try{
    for(const path of Object.keys(SOURCE_PINS)){await mkdir(dirname(resolve(temporary,path)),{recursive:true});await writeFile(resolve(temporary,path),await readFile(resolve(root,path)));}
    await writeFile(resolve(temporary,'resources/js/enterprise/reviewer/job-sample.js'),'throw new Error("MUST NOT EXECUTE")');
    await assert.rejects(exportFreshSeed({root:temporary}),/source fingerprint changed/);
  }finally{await rm(temporary,{recursive:true,force:true});}
});
