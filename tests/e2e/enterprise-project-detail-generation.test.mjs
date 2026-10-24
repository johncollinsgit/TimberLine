import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const script=await readFile(new URL('../../resources/views/enterprise/project-detail-script.blade.php',import.meta.url),'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
test('an old Planning result cannot replace the roster after re-rendering the same project',async()=>{
 const pending=[],summary={isConnected:true,textContent:'',innerHTML:''},host={isConnected:true,innerHTML:''},dialog={open:true};
 const context=vm.createContext({transport:{mode:'private'},area:'same-area',section:'projects',selected:'same-project',project:{id:'same-project',version:1},data:{areas:[],projects:[],members:[],features:{planning:true}},$:id=>id==='detail'?host:id==='work-drawer'?dialog:summary,
 esc:value=>String(value??''),EverbranchRollProjects:{projectRows:items=>[{name:'Same project',status:'Active',tasks:[],people:[],contractors:items[0].planning?.contractors||[],locations:[],coverage:'Current allowed data'}]},mountDiscussion(){},rollEndpoint:()=>'/planning',api:()=>new Promise((resolve,reject)=>pending.push({resolve,reject}))});
 vm.runInContext(script,context);vm.runInContext('renderProjectDetail(project)',context);dialog.open=false;dialog.open=true;vm.runInContext('renderProjectDetail(project)',context);
 pending[1].resolve({contractors:[{name:'Fresh current contractor'}]});await flush();assert.match(summary.innerHTML,/Fresh current contractor/);
 // Keep the old fixture node connected deliberately: the generation itself must refuse it.
 pending[0].resolve({contractors:[{name:'Stale contractor'}]});await flush();assert.match(summary.innerHTML,/Fresh current contractor/);assert.doesNotMatch(summary.innerHTML,/Stale contractor/);
 vm.runInContext('renderProjectDetail(project)',context);vm.runInContext('renderProjectDetail(project)',context);pending[3].resolve({contractors:[{name:'Newest contractor'}]});await flush();pending[2].reject(new Error('Old failure'));await flush();assert.match(summary.innerHTML,/Newest contractor/);assert.notEqual(summary.textContent,'Contractor records are unavailable for this check.');
});
