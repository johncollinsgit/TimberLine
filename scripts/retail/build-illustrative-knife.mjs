// Original, deliberately fictional geometry. No merchant/customer model or texture.
// Run from any directory: node scripts/retail/build-illustrative-knife.mjs
import {writeFile} from 'node:fs/promises';
const positions=[], normals=[], indices=[], primitives=[];
const triangle=(a,b,c)=>{
  const u=b.map((n,i)=>n-a[i]),v=c.map((n,i)=>n-a[i]);
  const n=[u[1]*v[2]-u[2]*v[1],u[2]*v[0]-u[0]*v[2],u[0]*v[1]-u[1]*v[0]];
  const length=Math.hypot(...n)||1,offset=positions.length/3;
  for(const p of [a,b,c]){positions.push(...p);normals.push(...n.map(x=>x/length));}
  indices.push(offset,offset+1,offset+2);
};
const prism=(outline,depth,material,z=0)=>{
  const start=indices.length;
  for(let i=1;i<outline.length-1;i++){
    triangle([...outline[0],z+depth/2],[...outline[i],z+depth/2],[...outline[i+1],z+depth/2]);
    triangle([...outline[0],z-depth/2],[...outline[i+1],z-depth/2],[...outline[i],z-depth/2]);
  }
  for(let i=0;i<outline.length;i++){
    const a=outline[i],b=outline[(i+1)%outline.length];
    triangle([...a,z-depth/2],[...b,z-depth/2],[...b,z+depth/2]);
    triangle([...a,z-depth/2],[...b,z+depth/2],[...a,z+depth/2]);
  }
  primitives.push({start,count:indices.length-start,material});
};
// A tapered blade, complete rounded handle, bolster, and three contrasting pins.
prism([[-.018,.006],[-.018,.142],[-.010,.170],[.004,.180],[.019,.147],[.026,.105],[.026,.035],[.017,.006]],.003,0);
prism([[-.012,.004],[-.015,-.018],[-.016,-.078],[-.012,-.112],[-.006,-.120],[.007,-.120],[.014,-.110],[.017,-.082],[.017,-.030],[.013,.004]],.017,1);
prism([[-.014,-.070],[-.016,-.078],[-.012,-.112],[-.006,-.120],[.007,-.120],[.014,-.110],[.017,-.082],[.015,-.070]],.0175,2);
prism([[-.020,.007],[-.020,-.002],[.021,-.002],[.021,.007]],.019,0);
for(const y of [-.021,-.052,-.094]){
  const outline=Array.from({length:20},(_,i)=>[Math.cos(i/20*Math.PI*2)*.0018,y+Math.sin(i/20*Math.PI*2)*.0018]);
  prism(outline,.019,0);
}
const floatPositions=new Float32Array(positions),floatNormals=new Float32Array(normals),uintIndices=new Uint16Array(indices);
const buffers=[Buffer.from(floatPositions.buffer),Buffer.from(floatNormals.buffer),Buffer.from(uintIndices.buffer)];
const bin=Buffer.concat(buffers),views=[];let offset=0;
for(let i=0;i<buffers.length;i++){views.push({buffer:0,byteOffset:offset,byteLength:buffers[i].length,target:i===2?34963:34962});offset+=buffers[i].length;}
const min=[0,1,2].map(axis=>Math.min(...positions.filter((_,i)=>i%3===axis)));
const max=[0,1,2].map(axis=>Math.max(...positions.filter((_,i)=>i%3===axis)));
const accessors=[{bufferView:0,componentType:5126,count:positions.length/3,type:'VEC3',min,max},{bufferView:1,componentType:5126,count:normals.length/3,type:'VEC3'}];
const meshes=primitives.map(p=>{
  const accessor=accessors.length;accessors.push({bufferView:2,byteOffset:p.start*2,componentType:5123,count:p.count,type:'SCALAR'});
  return {primitives:[{attributes:{POSITION:0,NORMAL:1},indices:accessor,material:p.material}]};
});
const material=(name,color,metallicFactor,roughnessFactor)=>({name,pbrMetallicRoughness:{baseColorFactor:[...color,1],metallicFactor,roughnessFactor},doubleSided:true});
const doc={asset:{version:'2.0',generator:'Everbranch original illustrative fixture; not an Avery product model'},scene:0,scenes:[{nodes:meshes.map((_,i)=>i)}],nodes:meshes.map((_,i)=>({mesh:i})),meshes,materials:[material('Illustrative silver',[.68,.72,.75],.72,.32),material('Illustrative blue handle',[.025,.19,.31],.05,.4),material('Illustrative warm handle accent',[.24,.11,.045],0,.55)],buffers:[{byteLength:bin.length}],bufferViews:views,accessors};
const json=Buffer.from(JSON.stringify(doc).padEnd(Math.ceil(JSON.stringify(doc).length/4)*4,' '));
const padBin=Buffer.concat([bin,Buffer.alloc((4-bin.length%4)%4)]);
const header=Buffer.alloc(12);header.writeUInt32LE(0x46546c67);header.writeUInt32LE(2,4);header.writeUInt32LE(12+8+json.length+8+padBin.length,8);
const jc=Buffer.alloc(8);jc.writeUInt32LE(json.length);jc.writeUInt32LE(0x4e4f534a,4);
const bc=Buffer.alloc(8);bc.writeUInt32LE(padBin.length);bc.writeUInt32LE(0x004e4942,4);
await writeFile(new URL('../../resources/retail-visualizer/illustrative-knife.glb',import.meta.url),Buffer.concat([header,jc,json,bc,padBin]));
console.log('Wrote complete fictional knife fixture; nominal geometry height 0.300 m, not merchant measurements.');
