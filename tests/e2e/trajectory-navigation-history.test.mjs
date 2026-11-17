import {test} from 'node:test';
import assert from 'node:assert/strict';
import {reportNavigation} from '../../resources/js/trajectory/navigation-history.js';
function harness(){
 const listeners=new Map(),entries=[{state:null,url:'https://fixture.invalid/prior-404'},{state:{oauthOther:'preserved'},url:'https://fixture.invalid/trajectory'}];let index=1,number=0,value={tab:'money-flow',mode:'inflows',filter:{},focus:'Fictional company'},scope='household:all:year',enabled=true;
 const win={crypto:{randomUUID:()=>`opaque-${++number}`},location:{href:entries[index].url},addEventListener:(name,fn)=>listeners.set(name,fn),removeEventListener:name=>listeners.delete(name)};
 async function go(delta){index+=delta;assert.ok(index>=0&&index<entries.length);win.location.href=entries[index].url;await listeners.get('popstate')?.({state:entries[index].state});}
 win.history={get state(){return entries[index].state;},replaceState(state,_,url){entries[index]={state,url};},pushState(state,_,url){entries.splice(index+1);entries.push({state,url});index++;},back(){void go(-1);}};
 const restored=[],invalid=[];
 const nav=reportNavigation({window:win,capture:()=>structuredClone(value),context:()=>scope,enabled:()=>enabled,invalidate:reason=>invalid.push(reason),restore:async(snapshot,current)=>{if(current()){value=structuredClone(snapshot);restored.push(snapshot);}}});
 return {nav,entries,win,go,restored,invalid,get value(){return value;},set value(next){value=next;},set scope(next){scope=next;},set enabled(next){enabled=next;},get index(){return index;}};
}
test('dialog and detail back steps stay in the app and forward restores in-memory focus and receipt mode',async()=>{
 const h=harness();h.nav.sync();h.nav.push();h.value={...h.value,dialog:'Fictional company · Net payroll'};h.nav.sync();h.nav.push();h.value={...h.value,tab:'transactions',filter:{ids:[123],cash_movement:true}};h.nav.sync();
 assert.equal(h.nav.back(),true);await new Promise(resolve=>setImmediate(resolve));assert.equal(h.value.tab,'money-flow');assert.equal(h.value.dialog,'Fictional company · Net payroll');assert.equal(h.value.mode,'inflows');
 assert.equal(h.nav.back(),true);await new Promise(resolve=>setImmediate(resolve));assert.equal(h.value.dialog,undefined);assert.equal(h.win.location.href,'https://fixture.invalid/trajectory');assert.equal(h.nav.back(),false);
 await h.go(1);assert.equal(h.value.dialog,'Fictional company · Net payroll');await h.go(1);assert.deepEqual(h.value.filter,{ids:[123],cash_movement:true});
 for(const {state} of h.entries.slice(1)){assert.equal(state.oauthOther,'preserved');assert.deepEqual(Object.keys(state.trajectoryNavigation).sort(),['entry','session','version']);assert.ok(!JSON.stringify(state).includes('Fictional'));assert.ok(!JSON.stringify(state).includes('123'));}
});
test('changed financial scope invalidates historical snapshots instead of restoring private labels and evidence',async()=>{
 const h=harness();h.nav.sync();h.nav.push();h.value={tab:'transactions',filter:{ids:[123]}};h.nav.sync();h.scope='business:other:month';h.value={tab:'overview',filter:{}};h.nav.sync();assert.deepEqual(h.invalid,['context']);assert.equal(h.nav.back(),false);
 await h.go(-1);assert.deepEqual(h.invalid,['context','history']);assert.equal(h.value.tab,'overview');assert.equal(h.restored.length,0);
});
test('OAuth-disabled navigation leaves callback state untouched',()=>{
 const h=harness();h.enabled=false;h.nav.sync();assert.equal(h.nav.push(),false);assert.equal(h.nav.back(),false);assert.deepEqual(h.win.history.state,{oauthOther:'preserved'});assert.equal(h.entries.length,2);h.enabled=true;h.nav.sync();assert.equal(h.win.history.state.oauthOther,'preserved');h.nav.dispose();
});
