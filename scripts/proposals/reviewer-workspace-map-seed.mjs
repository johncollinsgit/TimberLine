import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
import '../../resources/js/enterprise/reviewer/shared-workspace-map.js';
import {exportFreshSeed,exportFreshBuildingMapInputs,canonical} from './reviewer-workspace-seed.mjs';

const ROOT=fileURLToPath(new URL('../../',import.meta.url));
const sha=value=>createHash('sha256').update(value).digest('hex');
const refuse=()=>{throw new Error('Official fictional map seed refused.');};
const requireValue=value=>{if(!value)refuse();};
// This separate source mode does not change the original default seed output.
export async function exportFreshMapSeed(options={}){
  requireValue(options&&Object.keys(options).every(k=>k==='root')&&(options.root===undefined||typeof options.root==='string'));
  const root=options.root??ROOT;
  const map=globalThis.EverbranchSharedWorkspaceMap;
  const catalogBytes=await readFile(resolve(root,'resources/data/enterprise/lees-mcrae-locations.json'));
  const pointsBytes=await readFile(resolve(root,'resources/data/enterprise/campus-map-points.json'));
  requireValue(sha(catalogBytes)===map.contract.catalog_sha256&&sha(pointsBytes)===map.contract.points_sha256);
  const catalog=JSON.parse(catalogBytes),points=JSON.parse(pointsBytes);
  requireValue(catalog.catalog===map.contract.catalog&&catalog.source_url===map.contract.source_url&&catalog.source_page===2
    &&points.source_url===map.contract.source_url&&points.source_page===1&&points.source_sha256===map.contract.document_sha256
    &&points.coordinate_basis===map.contract.coordinate_basis);
  const seed=await exportFreshSeed({root}),input=await exportFreshBuildingMapInputs({root});
  const rows=new Map();
  for(const row of input){requireValue(!rows.has(row.id));rows.set(row.id,row);}
  requireValue(rows.size===seed.graph.buildings.length);
  const graph=structuredClone(seed.graph);
  for(const building of graph.buildings){
    const row=rows.get(building.id);
    requireValue(row&&row.area_id===building.area_id&&row.version===building.version&&row.status===building.status);
    const present=[row.map_number,row.catalog_map_number,row.source_url,row.source_page].some(v=>v!==null);
    if(!present)continue;
    requireValue(row.level==='facility'&&row.map_number!==null&&row.catalog_map_number!==null&&row.map_number===row.catalog_map_number);
    const reference={schema_version:1,catalog:catalog.catalog,catalog_sha256:map.contract.catalog_sha256,map_number:row.map_number,source_url:row.source_url,source_page:row.source_page};
    map.validate(reference);
    const matches=catalog.entries.filter(e=>e.map_number===reference.map_number),point=points.points[reference.map_number];
    requireValue(matches.length===1&&point&&Object.keys(point).length===2&&['x','y'].every(k=>Number.isFinite(point[k])&&point[k]>=0&&point[k]<=100));
    building.map_reference=reference;
  }
  const graphBytes=Buffer.byteLength(canonical(graph));
  requireValue(graphBytes<=262144);
  return {schema_version:1,purpose:'fresh_official_fictional_map_seed_only',graph,evidence:{...seed.evidence,
    parent_seed_sha256:seed.evidence.graph_sha256,graph_sha256:sha(canonical(graph)),graph_bytes:graphBytes,
    map_references:graph.buildings.filter(b=>b.map_reference).length,map_catalog_sha256:map.contract.catalog_sha256,
    map_points_sha256:map.contract.points_sha256,map_document_sha256:map.contract.document_sha256,
    map_basis:'Explicit saved facility reference to printed official document labels; not GPS, ownership or native college authority.',
    initializes_runtime:false,publishes:false}};
}

if(process.argv[1]&&pathToFileURL(resolve(process.argv[1])).href===import.meta.url){
  try{
    requireValue(process.argv.slice(2).every(a=>a==='--graph')&&process.argv.slice(2).length<=1);
    const seed=await exportFreshMapSeed();
    process.stdout.write(canonical(process.argv.includes('--graph')?seed.graph:seed)+'\n');
  }catch{process.stderr.write('Official fictional map seed refused.\n');process.exitCode=1;}
}
