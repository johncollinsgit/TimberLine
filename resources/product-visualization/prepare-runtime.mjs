import {mkdir,cp,writeFile,readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
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
