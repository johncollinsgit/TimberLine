import {test} from 'node:test';
import assert from 'node:assert/strict';
import {importLabelFiles} from './batch-import.js';
test('batch imports each file separately, retaining successes through recoverable failures',async()=>{
 const files=[{name:'fictional-a.png',size:20_000_000,lastModified:1},{name:'fictional-b.png',size:20_000_000,lastModified:2}],completed=new Set(),sent=[];
 let fail=true;
 const send=async file=>{sent.push(file.name);if(fail&&file===files[1])throw new Error('Recoverable upload failure');return {results:[{file:file.name,ok:true}]};};
 let results=await importLabelFiles(files,send,()=>{},completed);assert.equal(results[0].ok,true);assert.equal(results[1].ok,false);
 fail=false;results=await importLabelFiles(files,send,()=>{},completed);assert.equal(results.length,1);assert.deepEqual(sent,['fictional-a.png','fictional-b.png','fictional-b.png']);
});
test('batch refuses more than fifty files before sending anything',async()=>{
 let calls=0;await assert.rejects(importLabelFiles(Array(51).fill({}),()=>calls++,()=>{}),/50/);assert.equal(calls,0);
});
