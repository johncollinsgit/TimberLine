import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile,mkdtemp,mkdir,copyFile,appendFile,rm} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {tmpdir} from 'node:os';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import vm from 'node:vm';
import {exportFreshSeed,exportFreshBuildingMapInputs,canonical,SOURCE_PINS} from '../../scripts/proposals/reviewer-workspace-seed.mjs';
import {exportFreshMapSeed} from '../../scripts/proposals/reviewer-workspace-map-seed.mjs';

const root=fileURLToPath(new URL('../../',import.meta.url));
const helper=await readFile(resolve(root,'resources/js/enterprise/reviewer/shared-workspace-map.js'),'utf8');
const context=vm.createContext({});vm.runInContext(helper,context);
const map=context.EverbranchSharedWorkspaceMap,plain=v=>JSON.parse(JSON.stringify(v));
const ref=(number='2')=>({schema_version:1,catalog:map.contract.catalog,catalog_sha256:map.contract.catalog_sha256,map_number:number,source_url:map.contract.source_url,source_page:2});
const sha=v=>createHash('sha256').update(v).digest('hex');

test('compiled PHP/JS contract uses exact current catalog and plan, with legend and drawn pages distinct',async()=>{
  const catalogBytes=await readFile(resolve(root,'resources/data/enterprise/lees-mcrae-locations.json'));
  const pointsBytes=await readFile(resolve(root,'resources/data/enterprise/campus-map-points.json'));
  assert.equal(sha(catalogBytes),map.contract.catalog_sha256);assert.equal(sha(pointsBytes),map.contract.points_sha256);
  const catalog=JSON.parse(catalogBytes),points=JSON.parse(pointsBytes),php=await readFile(resolve(root,'app/Proposals/ReviewerWorkspaceMapReference.php'),'utf8');
  for(const key of ['catalog','catalog_sha256','points_sha256','document_sha256','source_url','coordinate_basis'])assert.ok(php.includes(map.contract[key]));
  assert.equal(catalog.source_page,2);assert.equal(points.source_page,1);assert.equal(points.source_sha256,map.contract.document_sha256);
  assert.equal(points.coordinate_basis,map.contract.coordinate_basis);
  for(let n=1;n<=68;n++){assert.equal(catalog.entries.filter(e=>e.map_number===String(n)).length,1);assert.ok(points.points[String(n)]);assert.deepEqual(plain(map.validate(ref(String(n)))),ref(String(n)));}
});
test('closed JS reference rejects unknown fields, coercion, foreign source and getters without executing them',()=>{
  for(const invalid of [null,{},[],{...ref(),latitude:36},{...ref(),x:20},{...ref(),source_page:1},{...ref(),catalog_sha256:'a'.repeat(64)},{...ref(),source_url:map.contract.source_url+'?x=1'},...['02','69','0','2\n',' 2',2,null,'2e0'].map(map_number=>({...ref(),map_number}))])assert.throws(()=>map.validate(invalid));
  let called=false;const getter=ref();Object.defineProperty(getter,'map_number',{get(){called=true;return '2';}});assert.throws(()=>map.validate(getter));assert.equal(called,false);
});
test('missing map reference stays absent; valid mapping adds only trusted existing DTO provenance',()=>{
  const original={id:'saved',version:2,title:'Robb Center',latitude:1,map_number:'99'};
  assert.deepEqual(plain(map.buildingDto(original)),{});assert.equal(Object.hasOwn(original,'map_reference'),false);
  assert.deepEqual(plain(map.buildingDto({...original,map_reference:ref()})),{map_number:'2',source_url:map.contract.source_url});
  assert.throws(()=>map.buildingDto({...original,map_reference:null}));
});
test('separate map export is deterministic and preserves original seed graph hash, exact FKs and all exclusions',async()=>{
  const seed=await exportFreshSeed({root}),a=await exportFreshMapSeed({root}),b=await exportFreshMapSeed({root});
  assert.equal(seed.evidence.graph_sha256,'1dc4ecb5d67e1ed71e60eb2308efc1db95d4252fb4000afe5b48070a4dcd4a63');
  const frozen=JSON.parse(await readFile(resolve(root,'tests/Fixtures/proposals/reviewer-workspace-map-seed.json'),'utf8'));assert.equal(canonical(frozen.graph),canonical(a.graph));
  assert.equal(canonical(a),canonical(b));assert.equal(a.evidence.map_references,25);assert.equal(a.evidence.graph_bytes,48555);
  assert.equal(a.evidence.graph_sha256,'6710e8f9e6c0e5dfe2e1b45e1c28b64a3341a812bd0738282ec3e623e3083b79');
  const without=structuredClone(a.graph);without.buildings.forEach(b=>delete b.map_reference);assert.equal(canonical(without),canonical(seed.graph));
  assert.equal(a.graph.tasks.filter(t=>t.building_id===null).length,1);assert.equal(a.evidence.forbidden_calls,0);assert.equal(a.evidence.recovers_browser_edits,false);assert.equal(a.evidence.initializes_runtime,false);assert.equal(a.evidence.publishes,false);
});
test('narrow fresh metadata hook returns no notes/files/contact/auth/raw state',async()=>{
  const rows=await exportFreshBuildingMapInputs({root});assert.equal(rows.length,25);
  for(const row of rows)assert.deepEqual(Object.keys(row).sort(),['id','area_id','version','status','level','map_number','catalog_map_number','source_url','source_page'].sort());
  await assert.rejects(exportFreshMapSeed({root,sessionStorage:{}}));await assert.rejects(exportFreshBuildingMapInputs({root,state:{}}));
});
test('changed pinned source bytes refuse map export without external IO or modifying source assets',async()=>{
  for(const changed of ['resources/data/enterprise/campus-map-points.json','resources/data/enterprise/lees-mcrae-locations.json']){
    const temporary=await mkdtemp(resolve(tmpdir(),'workspace-map-'));
    try{for(const file of [...Object.keys(SOURCE_PINS),'resources/data/enterprise/campus-map-points.json']){await mkdir(dirname(resolve(temporary,file)),{recursive:true});await copyFile(resolve(root,file),resolve(temporary,file));}
      await appendFile(resolve(temporary,changed),'\n');await assert.rejects(exportFreshMapSeed({root:temporary}));
    }finally{await rm(temporary,{recursive:true,force:true});}
  }
});
test('controlled fake fresh metadata conflicts fail rather than invent links; absent reference stays unmapped',async()=>{
  const actualSeed=await exportFreshSeed({root}),inputs=await exportFreshBuildingMapInputs({root});
  let code=await readFile(resolve(root,'scripts/proposals/reviewer-workspace-map-seed.mjs'),'utf8');
  code=code.replace(/^import .*;\n/gm,'').replace(/^const ROOT=.*;\n/m,"const ROOT='/unused';\n").replace('export async function exportFreshMapSeed','globalThis.exportFreshMapSeed=async function');
  code=code.slice(0,code.indexOf('if(process.argv[1]'));
  const run=async alter=>{const rows=structuredClone(inputs);alter(rows);const fake=vm.createContext({readFile,createHash,resolve,canonical,Buffer,structuredClone,EverbranchSharedWorkspaceMap:map,exportFreshSeed:async()=>structuredClone(actualSeed),exportFreshBuildingMapInputs:async()=>rows});vm.runInContext(code,fake);return fake.exportFreshMapSeed({root});};
  for(const alter of [rows=>rows[0].map_number='03',rows=>rows[0].source_url='https://foreign.invalid/map.pdf',rows=>rows[0].source_page=1,rows=>rows[0].version++,rows=>rows[0].area_id=rows[1].area_id+'foreign',rows=>rows.push({...rows[0]})])await assert.rejects(run(alter));
  const absent=await run(rows=>{for(const key of ['map_number','catalog_map_number','source_url','source_page'])rows[0][key]=null;});
  assert.equal(absent.evidence.map_references,24);assert.equal(Object.hasOwn(absent.graph.buildings.find(b=>b.id===inputs[0].id),'map_reference'),false);
});
