import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const source=await readFile(new URL('../../resources/js/enterprise/reviewer/review-name.js',import.meta.url),'utf8');
const plain=value=>JSON.parse(JSON.stringify(value));
function fixture(){
 const prefix='https://workspace.invalid/enterprise-owner/service-proposals/FictionalToken/campus';
 const binding={version_hash:'a'.repeat(64),build_id:'fictional',source_hash:'b'.repeat(64)};
 const boot={...binding,pages:[{id:'requests'}],reviewer_names:['Andrew','Blaine'],urls:{data_pages:{requests:prefix+'/requests/review'}}};
 const descriptor={binding,endpoints:{projection:prefix+'/workspace/projection'}};
 let name='Andrew',alter=()=>{},calls=[],cancelled=false,oversized=false;
 const context=vm.createContext({URL,TextDecoder,Uint8Array,location:{href:prefix+'/requests',origin:'https://workspace.invalid'},fetch:async(url,init)=>{
  calls.push({url,init});const review={...binding,page:'requests',reviewer_name:name,comments:[{body:'Feedback must not escape this reader'}]};alter(review);
  let consumed=false;return{ok:true,headers:{get:()=> 'application/json; charset=UTF-8'},body:{getReader:()=>({read:async()=>consumed?{done:true}:(consumed=true,{done:false,value:oversized?new Uint8Array(10*1024*1024+1):new TextEncoder().encode(JSON.stringify({review}))}),cancel:async()=>{cancelled=true;},releaseLock(){}})}};
 }});
 vm.runInContext(source,context);
 return{boot,descriptor,binding,create:()=>context.EverbranchReviewerNameReader.create(boot,descriptor),calls,setName:value=>{name=value;},alter:value=>{alter=value;},oversize:()=>{oversized=true;},cancelled:()=>cancelled};
}
test('each current-name read uses the fixed private GET and returns only fresh name and exact binding',async()=>{
 const f=fixture(),reader=f.create();assert.deepEqual(plain(await reader.read('requests')),{...f.binding,reviewer_name:'Andrew'});
 f.setName('Blaine');assert.deepEqual(plain(await reader.read('requests')),{...f.binding,reviewer_name:'Blaine'});
 assert.equal(f.calls.length,2);assert.equal(f.calls[0].init.method,'GET');assert.equal(f.calls[0].init.credentials,'same-origin');assert.equal(f.calls[0].init.redirect,'error');assert.equal(f.calls[0].init.cache,'no-store');
 assert.equal(JSON.stringify(await reader.read('requests')).includes('Feedback'),false);
});
test('foreign/query destinations, unknown pages, changed binding and unsaved names fail without fallback',async()=>{
 for(const destination of ['https://foreign.invalid/private','https://workspace.invalid/enterprise-owner/service-proposals/FictionalToken/campus/requests/review?native=1']){
  const f=fixture();f.boot.urls.data_pages.requests=destination;assert.throws(()=>f.create(),/could not be verified/);assert.equal(f.calls.length,0);
 }
 const f=fixture(),reader=f.create();await assert.rejects(reader.read('native'),/could not be verified/);assert.equal(f.calls.length,0);
 f.alter(review=>{review.source_hash='f'.repeat(64);});await assert.rejects(reader.read('requests'),/could not be verified/);
 f.alter(()=>{});f.setName('Not a saved review choice');await assert.rejects(reader.read('requests'),/could not be verified/);
});
test('oversized private context stream is cancelled and never returned as a current name',async()=>{
 const f=fixture();f.oversize();await assert.rejects(f.create().read('requests'),/could not be verified/);assert.equal(f.cancelled(),true);
});
