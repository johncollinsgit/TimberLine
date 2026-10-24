import test from 'node:test';import assert from 'node:assert/strict';import{readFileSync}from'node:fs';import vm from'node:vm';
const source=readFileSync(new URL('../../resources/views/enterprise/app.blade.php',import.meta.url),'utf8'),start=source.indexOf('  async function command(body) {'),end=source.indexOf('  const requestTypeLabels=',start);assert.ok(start>=0&&end>start);
function fixture(draft){
 let response,ready;const loaded=[],sent=[],nodes={retry:{hidden:true},'create-dialog':{close(){}}},store={...draft};
 const context={busy:false,pending:null,pendingUncertain:false,EverbranchTransport:{setRefreshBlocker(){}},$:id=>nodes[id],message(){},urls:{commands:'/commands'},api:(url,body)=>{sent.push(JSON.parse(JSON.stringify(body)));ready?.();return new Promise((resolve,reject)=>{response={resolve,reject};});},load:async()=>loaded.push({...store}),readDraft:()=>({...store}),writeDraft:(key,value)=>{for(const key of Object.keys(store))delete store[key];Object.assign(store,value);}};
 vm.createContext(context);vm.runInContext(source.slice(start,end),context);
 return{store,context,sent,loaded,run:body=>context.command(body),accept:()=>response.resolve({id:'r'}),uncertain:()=>response.reject(Object.assign(new Error('Uncertain synthetic outcome'),{status:503}))};
}
const input={action:'request.classify',id:'r',area_id:'a',request_id:'exact-retained-key',request_type:'plumbing',priority:'high'};
test('delayed success clears only acknowledged classification values and preserves newer edits through reload',async()=>{
 const f=fixture({request_type:'plumbing',priority:'high',body:'Independent note'}),run=f.run(input);
 f.store.request_type='lighting';f.store.priority='low';f.accept();await run;
 assert.deepEqual(f.store,{request_type:'lighting',priority:'low',body:'Independent note'});assert.deepEqual(f.loaded,[f.store]);
 assert.equal(f.context.pending,null);assert.equal(f.context.busy,false);
});
test('exact uncertain retry does not capture later edits or erase them when the original command succeeds',async()=>{
 const f=fixture({request_type:'plumbing',priority:'high',body:'Independent note'});let run=f.run(input);f.uncertain();await run;
 assert.equal(f.context.pendingUncertain,true);assert.equal(f.context.pending,input);
 f.store.request_type='maintenance';f.store.priority='low';run=f.run(f.context.pending);f.accept();await run;
 assert.deepEqual(f.sent,[input,input]);assert.deepEqual(f.store,{request_type:'maintenance',priority:'low',body:'Independent note'});assert.deepEqual(f.loaded,[f.store]);
});
test('only acknowledged values clear, including explicit Not recorded, without clearing a newer note',async()=>{
 const f=fixture({request_type:'',priority:'high',body:'Original note'});let run=f.run({...input,request_type:null});f.accept();await run;assert.deepEqual(f.store,{body:'Original note'});
 run=f.run({action:'request.triage',id:'r',area_id:'a',note:'Original note'});f.store.body='Newer note';f.store.priority='low';f.accept();await run;assert.deepEqual(f.store,{body:'Newer note',priority:'low'});
});
