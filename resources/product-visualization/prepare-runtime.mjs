import {mkdir,cp,writeFile,readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
// Pinned model-viewer 4.4.0 calls this AR hook for ordinary 3D scale changes.
// Guard only its XR-menu update; keep the dependency/version/license intact.
// Fail closed on any upstream source change rather than applying a fuzzy patch.
const arFile=new URL('./node_modules/@google/model-viewer/lib/three-components/ARRenderer.js',import.meta.url);
const originalArHash='6d5f3fcb8d4728f579217fd5066263f8ea8c10c1701a4fc73c6ac4fcb1f8b5d1';
const patchedArHash='47d420e4d0d9bae43210c3bdcfdf90ebb9465266241292038e1978c72e36ada0';
let arSource=await readFile(arFile,'utf8');
const arHash=createHash('sha256').update(arSource).digest('hex');
if(arHash===originalArHash){
  const start=arSource.indexOf('        this.onUpdateScene = () => {');
  const end=arSource.indexOf('        this.onSelectStart = ',start);
  const body=arSource.slice(start,end),guard='if (this.xrMode !== XRMode.SCREEN_SPACE) {';
  if(start<0||end<start||body.split(guard).length!==2)throw new Error('Pinned model-viewer AR guard target changed');
  arSource=arSource.slice(0,start)+body.replace(guard,'if (this.isPresenting && this.placementBox != null && this.xrMode !== XRMode.SCREEN_SPACE) {')+arSource.slice(end);
  if(createHash('sha256').update(arSource).digest('hex')!==patchedArHash)throw new Error('Pinned model-viewer AR guard checksum mismatch');
  await writeFile(arFile,arSource);
}else if(arHash!==patchedArHash)throw new Error('Unrecognized model-viewer source; review the AR compatibility guard before building');
const runtime=new URL('./public/runtime/',import.meta.url);
const expected='fbc2a30080c3c557093b5ddfc334698132eb341044ccee322ccf8bcf3607cde1';
await mkdir(runtime,{recursive:true});
await cp(new URL('./node_modules/@mediapipe/tasks-vision/wasm/',import.meta.url),new URL('wasm/',runtime),{recursive:true});
const target=new URL('hand_landmarker.task',runtime);
let bytes=await readFile(target).catch(()=>null);
if(!bytes || createHash('sha256').update(bytes).digest('hex')!==expected){
  const response=await fetch('https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task');
  if(!response.ok)throw new Error(`Hand model download failed: ${response.status}`);
  bytes=Buffer.from(await response.arrayBuffer());
  if(createHash('sha256').update(bytes).digest('hex')!==expected)throw new Error('Hand model checksum mismatch; no unverified replacement installed');
  await writeFile(target,bytes);
}
console.log('Self-hosted hand runtime verified. No camera frames leave the device.');
