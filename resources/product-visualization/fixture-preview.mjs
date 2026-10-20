import * as THREE from 'three';
import {GLTFExporter} from 'three/addons/exporters/GLTFExporter.js';
import {mkdir,writeFile,readFile,stat,readdir} from 'node:fs/promises';
import {createServer} from 'node:http';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

// Local synthetic geometry only. These are deliberately not merchant assets.
globalThis.FileReader=class{readAsArrayBuffer(blob){blob.arrayBuffer().then(value=>{this.result=value;this.onloadend?.();});}};
const base=fileURLToPath(new URL('./',import.meta.url));
const fixtures=path.resolve(base,'../../storage/app/private/product-visualization-fixtures');
const built=path.resolve(base,'../../public/product-visualization');
await mkdir(fixtures,{recursive:true});
const material=(color,metalness=0,roughness=.45)=>new THREE.MeshStandardMaterial({color,metalness,roughness});
const mesh=(geometry,mat,x=0,y=0,z=0)=>{const m=new THREE.Mesh(geometry,mat);m.position.set(x,y,z);return m;};
const knife=new THREE.Group();
knife.add(mesh(new THREE.BoxGeometry(.042,.18,.003),material(0xc3c9ce,.8,.25),0,.08));
knife.add(mesh(new THREE.BoxGeometry(.025,.11,.016),material(0x175d88),0,-.065));
knife.add(mesh(new THREE.BoxGeometry(.025,.048,.017),material(0x664e33),0,-.101));
for(const y of[-.036,-.094])knife.add(mesh(new THREE.CylinderGeometry(.002,.002,.018,12),material(0xc7c9c6,.7),0,y));
const barrel=new THREE.Group();const profile=[];
for(let i=0;i<=24;i++){const y=i/24*.9;profile.push(new THREE.Vector2(.285+.055*Math.sin(i/24*Math.PI),y));}
barrel.add(mesh(new THREE.LatheGeometry(profile,48),material(0x89592e)));
barrel.add(mesh(new THREE.CylinderGeometry(.285,.285,.025,48),material(0x9b6d3e),0,.9));
for(const y of[.06,.22,.68,.84])barrel.add(mesh(new THREE.CylinderGeometry(.29+.055*Math.sin(y/.9*Math.PI),.29+.055*Math.sin(y/.9*Math.PI),.035,48,1,true),material(0x383a37,.75),0,y));
for(const [name,scene]of[['knife',knife],['barrel',barrel]]){
  const data=await new GLTFExporter().parseAsync(scene,{binary:true});await writeFile(path.join(fixtures,name+'.glb'),Buffer.from(data));
}
const html=`<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Local product visualization QA</title><link rel="stylesheet" href="/product-visualization/viewer.css"><style>body{font-family:system-ui;background:#f7f5ee;color:#203b30;margin:32px auto;max-width:900px;padding:0 20px}.cards{display:grid;grid-template-columns:1fr 1fr;gap:24px}article{padding:24px;background:white;border:1px solid #cbd4c6;border-radius:16px}h1{font-family:Georgia;font-size:36px}.note{padding:12px;background:#e7ede0}@media(max-width:600px){.cards{grid-template-columns:1fr}}</style><h1>Product visualization</h1><p class="note">LOCAL QA · Synthetic models only · No tenant or production data</p><div class="cards"><article><h2>Knife · hand mode</h2><div id="knife"></div></article><article><h2>Barrel · room mode</h2><div id="barrel"></div></article></div><p>Camera remains off until requested. Room AR requires a supported phone on HTTPS.</p><script type="module">
import '/product-visualization/viewer.js';
for(const [name,placement,lengthMm]of[['knife','hand',300],['barrel','room',900]]){const el=document.createElement('everbranch-product-viewer');el.setAttribute('data-config',JSON.stringify({version:1,productId:name==='knife'?1:2,title:'Synthetic '+name,placement,modelUrl:'/fixtures/'+name+'.glb',lengthMm,grip:[.5,.15,.5],rotationDeg:[0,0,0],variants:[{id:1,label:'Synthetic QA model',modelUrl:'/fixtures/'+name+'.glb'}]}));document.getElementById(name).append(el);}
</script></html>`;
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.wasm':'application/wasm','.task':'application/octet-stream','.glb':'model/gltf-binary'};
const workerFile=(await readdir(path.join(built,'assets'))).find(name=>name.startsWith('hand-worker-'));
const checkRuntime=`<button id="check-runtime" style="padding:12px">Check hand runtime (no camera)</button><p id="runtime-status" role="status"></p><script>document.getElementById('check-runtime').onclick=()=>{const status=document.getElementById('runtime-status');status.textContent='Loading self-hosted worker and hand model…';const worker=new Worker('/product-visualization/assets/${workerFile}');worker.onerror=e=>{status.textContent='Runtime failed: '+e.message;worker.terminate();};worker.onmessage=({data})=>{status.textContent=data.type==='ready'?'Hand runtime ready · no camera accessed':data.message;worker.terminate();};worker.postMessage({type:'init',wasm:new URL('/product-visualization/runtime/wasm/',location.href).href,model:new URL('/product-visualization/runtime/hand_landmarker.task',location.href).href});};</script>`;
createServer(async(req,res)=>{try{
  const url=new URL(req.url,'http://localhost');let bytes;
  if(url.pathname==='/'){bytes=Buffer.from(html.replace('</html>',checkRuntime+'</html>'));res.setHeader('Content-Type','text/html');}
  else{
    let file;
    if(url.pathname.startsWith('/product-visualization/'))file=path.resolve(built,'.'+decodeURIComponent(url.pathname.slice('/product-visualization'.length)));
    else if(['/fixtures/knife.glb','/fixtures/barrel.glb'].includes(url.pathname))file=path.join(fixtures,path.basename(url.pathname));
    else throw new Error('not found');
    if(!file.startsWith(built+path.sep)&&!file.startsWith(fixtures+path.sep))throw new Error('outside preview');
    await stat(file);bytes=await readFile(file);res.setHeader('Content-Type',mime[path.extname(file)]||'application/octet-stream');
  }
  res.setHeader('Cache-Control','no-store');res.end(bytes);
}catch{res.writeHead(404);res.end('Not found');}}).listen(8766,'127.0.0.1',()=>console.log('Synthetic local QA: http://127.0.0.1:8766'));
