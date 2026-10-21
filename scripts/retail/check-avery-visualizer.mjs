import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
const root=new URL('../../',import.meta.url);
const manifest=JSON.parse(await readFile(new URL('public/avery-visualizer/build-manifest.json',root),'utf8'));
const hash=bytes=>createHash('sha256').update(bytes).digest('hex');
assert.equal(manifest.upstreamCommit,'a5cf35775e2594ffa5a39e0a9972da2e6184accf');
for(const [file,expected] of Object.entries(manifest.files))assert.equal(hash(await readFile(new URL('public/avery-visualizer/'+file,root))),expected,file);
for(const patch of manifest.patches){
  const bytes=await readFile(new URL('public/avery-visualizer/'+patch.file,root));
  assert.equal(hash(bytes),patch.patchedSha256);
  const text=bytes.toString();assert.equal(text.split(patch.replace).length-1,1);
  assert.equal(hash(Buffer.from(text.replace(patch.replace,patch.find))),patch.upstreamSha256);
  assert.equal(patch.upstreamSha256,manifest.upstreamFiles[patch.file]);
}
const model=await readFile(new URL('resources/retail-visualizer/illustrative-knife.glb',root));
assert.equal(model.toString('ascii',0,4),'glTF');assert.equal(model.readUInt32LE(4),2);assert.equal(model.readUInt32LE(8),model.length);
const document=JSON.parse(model.toString('utf8',20,20+model.readUInt32LE(12)).trim());
assert(document.asset.generator.includes('not an Avery product model'));
assert(document.meshes.length>=6);assert.equal(document.materials.length,3);
assert(document.buffers.every(buffer=>!buffer.uri));assert(!document.images?.length);
assert(Math.abs(document.accessors[0].max[1]-document.accessors[0].min[1]-.3)<1e-6);
console.log(`Verified ${Object.keys(manifest.files).length} runtime hashes, exact single-substitution provenance, and complete embedded fictional GLB ${hash(model)}.`);
