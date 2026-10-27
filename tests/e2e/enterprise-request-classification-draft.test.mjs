import test from 'node:test';import assert from 'node:assert/strict';import{readFileSync}from'node:fs';import vm from'node:vm';
const source=readFileSync(new URL('../../resources/views/enterprise/app.blade.php',import.meta.url),'utf8'),start=source.indexOf('  async function command('),end=source.indexOf('  const requestTypeLabels=',start);assert.ok(start>=0&&end>start);
function fixture(draft,mode="private"){
 let response,ready;const loaded=[],sent=[],delegated=[],events=[],nodes={retry:{hidden:true},'create-dialog':{close(){}}},store={...draft};
 const context={transport:{mode},sharedCommand:(body,sharedContext)=>{delegated.push({body,sharedContext});return Promise.resolve({delegated:true});},document:{dispatchEvent:event=>events.push(event)},CustomEvent:class{constructor(type,{detail}){this.type=type;this.detail=detail;}},busy:false,pending:null,pendingUncertain:false,EverbranchTransport:{setRefreshBlocker(){}},$:id=>nodes[id],message(){},urls:{commands:'/commands'},api:(url,body)=>{sent.push(JSON.parse(JSON.stringify(body)));ready?.();return new Promise((resolve,reject)=>{response={resolve,reject};});},load:async()=>loaded.push({...store}),readDraft:()=>({...store}),writeDraft:(key,value)=>{for(const key of Object.keys(store))delete store[key];Object.assign(store,value);}};
 vm.createContext(context);vm.runInContext(source.slice(start,end),context);
 return{store,context,sent,loaded,delegated,events,run:(body,sharedContext)=>context.command(body,sharedContext),accept:()=>response.resolve({id:'r'}),uncertain:()=>response.reject(Object.assign(new Error('Uncertain synthetic outcome'),{status:503}))};
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

test('private acknowledged classification reaches the actual saved-event dispatch without becoming uncertain',async()=>{
 const f=fixture({request_type:'plumbing',priority:'high'}),run=f.run(input);f.accept();await run;
 assert.equal(f.context.pendingUncertain,false);assert.equal(f.context.pending,null);assert.equal(f.events.length,1);
 assert.equal(f.events[0].type,'enterprise:work-saved');assert.equal(f.events[0].detail.area_id,input.area_id);assert.equal(f.events[0].detail.ticket_id,undefined);
});
test('actual shared-mode gate delegates the original body and captured context without native dispatch or draft clearing',async()=>{
 const draft={request_type:'plumbing',priority:'high',body:'Retained shared draft'},f=fixture(draft,'shared'),captured={pin:{revision:7},target:{id:'r'}};
 const result=await f.run(input,captured);assert.equal(result.delegated,true);assert.equal(f.delegated.length,1);assert.equal(f.delegated[0].body,input);assert.equal(f.delegated[0].sharedContext,captured);
 assert.deepEqual(f.store,draft);assert.deepEqual(f.sent,[]);assert.deepEqual(f.loaded,[]);assert.deepEqual(f.events,[]);assert.equal(f.context.busy,false);
});
