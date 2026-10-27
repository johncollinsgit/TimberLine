import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/demos/lees-mcrae-campus.html',import.meta.url),'utf8');
const start=s.indexOf('function enterTourSubview('),end=s.indexOf('function renderTour()',start);
assert(start>=0&&end>start,'Production tour functions must be present');
const source=s.slice(start,end);
const goMatch=s.match(/function go\(next\)\{[^\n]+/);assert(goMatch,'Production navigation hook must be present');const go=goMatch[0];
const stepStart=s.indexOf('const steps=['),stepEnd=s.indexOf('\n];',stepStart);assert(stepStart>=0&&stepEnd>stepStart);
const steps=JSON.parse(vm.runInNewContext(s.slice(stepStart,stepEnd+3)+';JSON.stringify(steps)'));
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};}
function fixture(){
 const calls=[],pending=[],renders=[],frames=[],scrolls=[],toasts=[];
 const context={data:{persona:'leader',sms_simulated:false},steps,
  tourGeneration:0,tourStep:-1,modal:{open:false},view:'home',project:'original',query:'unsaved-filter',layout:'map',smsDone:false,
  api:(name,body)=>{assert(['persona','state'].includes(name));const d=deferred();calls.push({name,body});pending.push(d);return d.promise;},
  document:{querySelector:()=>null,getElementById:()=>null},window:{scrollY:0,scrollTo:value=>scrolls.push(value)},render:()=>renders.push(context.view),renderTour:()=>{},
  toast:(...v)=>toasts.push(v),requestAnimationFrame:fn=>frames.push(fn)};
 vm.createContext(context);vm.runInContext(source+'\n'+go,context);return {context,calls,pending,renders,frames,scrolls,toasts};
}
test('latest step owns the eventual persona/state projection; old entry cannot render',async()=>{
 const f=fixture(),old=f.context.setTour(1),latest=f.context.setTour(2);
 f.pending[0].resolve({});await old;assert.equal(f.calls.length,2);assert.deepEqual(f.renders,[]);
 f.pending[1].resolve({});await new Promise(setImmediate);assert.equal(f.calls[2].name,'state');
 f.pending[2].resolve({persona:'admin',sms_simulated:false});await latest;assert.equal(f.context.tourStep,2);assert.equal(f.context.data.persona,'admin');assert.deepEqual(f.renders,['intake']);
 assert.deepEqual(f.calls.map(x=>x.name),['persona','persona','state']);
});
test('exit and ordinary navigation invalidate pending entry and stale reveal frame',async()=>{
 for(const exit of [f=>f.context.setTour(-1),f=>f.context.go('files')]){
  const f=fixture(),pending=f.context.setTour(0);assert.equal(f.calls[0].name,'state');await exit(f);const rendered=[...f.renders];
  f.pending[0].resolve({persona:'leader',sms_simulated:false});await pending;assert.equal(f.context.tourStep,-1);assert.deepEqual(f.renders,rendered);assert.equal(f.frames.length,0);
 }
 const f=fixture(),p=f.context.setTour(0);f.pending[0].resolve({persona:'leader',sms_simulated:false});await p;await f.context.setTour(-1);f.frames[0]();assert.deepEqual(f.scrolls,[]);
});
test('open dialog keeps unsent fields and makes no transition or API request',async()=>{
 const f=fixture();f.context.modal.open=true;f.context.modal.unsent='preserve exactly';await f.context.setTour(1);
 assert.equal(f.context.modal.unsent,'preserve exactly');assert.equal(f.context.modal.open,true);assert.deepEqual(f.calls,[]);assert.deepEqual(f.renders,[]);assert.equal(f.context.tourGeneration,0);assert.equal(f.toasts[0][1],true);
});
test('missing target is never replaced with another target or created record',()=>{
 const f=fixture();f.context.tourStep=0;f.context.tourGeneration=4;f.context.revealTourTarget(4,0);assert.deepEqual(f.calls,[]);assert.deepEqual(f.scrolls,[]);
});

test('real tour metadata enters List and New Request, then Jobs with the explicit saved J05 example',()=>{
 assert.equal(steps.length,11);
 const staff=steps.find(step=>step.view==='intake'&&step.persona==='staff');
 const admin=steps.find(step=>step.view==='intake'&&step.persona==='admin');
 assert.equal(staff.requestLayout,'list');assert.equal(staff.target,'#new-request');
 assert.equal(admin.requestLayout,'list');assert.equal(admin.target,'#requests-content');
 const operations=steps.find(step=>step.view==='operations');assert.equal(operations.persona,'admin');
 assert.equal(operations.operationsTab,'jobs');assert.equal(operations.target,'[data-ops-job="J05"]');
});
