import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../../resources/views/enterprise/shared-comments-bridge.blade.php',import.meta.url),'utf8');
function fixture({enabled=true,shared=true}={}) {
 const calls={create:0,reader:0,mount:[],read:[],stash:0,invalidate:0,choose:0,messages:[]};let options;
 const workspace={mode:'shared',snapshot:()=>({revision:1})},presentation={reviewerWorkspace:{trusted:'descriptor'},...(enabled?{reviewerCollaboration:{enabled:true}}:{})};
 const state={area:'A',section:'requests',selected:'T',sharedWorkspace:shared?workspace:null,presentation,data:{projects:[{id:'P',tasks:[{id:'K',title:'Current task',private:'discard'}]}]},
  $:id=>id==='detail'?{id}:id==='review-open'?{click:()=>calls.choose++}:null,message:(...args)=>calls.messages.push(args),
  EverbranchSharedCommentsUI:{create:value=>{calls.create++;options=value;return{mount:async(...args)=>{calls.mount.push(args);return'discussion';},stash:()=>calls.stash++,invalidate:value=>{assert.equal(value.stash,true);calls.invalidate++;}};}},
  EverbranchReviewerNameReader:{create:(boot,descriptor)=>{assert.ok(boot);assert.equal(descriptor,presentation.reviewerWorkspace);calls.reader++;return{read:async page=>{calls.read.push(page);return{reviewer_name:'Saved server name'};}};}}};
 vm.createContext(state);vm.runInContext(source+'\nglobalThis.bridge={mountSharedDiscussion,stashSharedDiscussion,invalidateSharedDiscussion};',state);
 return{state,calls,options:()=>options,boot:()=>{state.EverbranchCampusReviewBoot={page:'projects',pages:[{id:'requests'},{id:'projects'}]};},workspace};
}
test('missing shared feature has no client, controls, name read or native fallback',async()=>{
 for(const settings of [{enabled:false},{shared:false}]){const f=fixture(settings);await f.state.bridge.mountSharedDiscussion('ticket','T');f.state.bridge.stashSharedDiscussion();f.state.bridge.invalidateSharedDiscussion();assert.equal(f.calls.create,0);assert.equal(f.calls.reader,0);assert.equal(f.calls.messages.length,0);}
});
test('bridge reuses the one workspace, lazily reads saved name and projects only current task identities',async()=>{
 const f=fixture();assert.equal(f.calls.reader,0);await f.state.bridge.mountSharedDiscussion('ticket','T');assert.equal(f.options().workspace,f.workspace);assert.equal(f.calls.reader,0);
 f.boot();assert.equal((await f.options().readReviewerName()).reviewer_name,'Saved server name');f.state.section='roll_call';await f.options().readReviewerName();assert.deepEqual(f.calls.read,['requests','projects']);assert.equal(f.calls.reader,1);
 assert.deepEqual(JSON.parse(JSON.stringify(f.options().tasksForProject('P'))),[{id:'K',title:'Current task'}]);f.state.data.projects=[];assert.deepEqual(JSON.parse(JSON.stringify(f.options().tasksForProject('P'))),[]);
 await f.state.bridge.mountSharedDiscussion('project','P');assert.equal(f.calls.create,1);f.options().chooseReviewerName();assert.equal(f.calls.choose,1);
 f.state.bridge.stashSharedDiscussion();f.state.bridge.invalidateSharedDiscussion();assert.equal(f.calls.stash,1);assert.equal(f.calls.invalidate,1);
});
test('late mount failure does not overwrite a newer detail status',async()=>{
 const f=fixture();let reject;f.state.EverbranchSharedCommentsUI.create=()=>({mount:()=>new Promise((_,r)=>{reject=r;})});const pending=f.state.bridge.mountSharedDiscussion('ticket','T');f.state.selected='OTHER';reject(Error('private failure'));await pending;assert.equal(f.calls.messages.length,0);
});
test('navigation invalidation fences an initial projection even after returning to the identical detail',async()=>{
 const f=fixture();let release;f.workspace.snapshot=()=>({revision:null,persona:'demo_manager',area_ids:['A'],person_id:null});f.workspace.readSelection=()=>new Promise(resolve=>{release=resolve;});
 const pending=f.state.bridge.mountSharedDiscussion('ticket','T');f.state.bridge.invalidateSharedDiscussion();release();assert.equal(await pending,null);assert.equal(f.calls.create,0);assert.equal(f.calls.messages.length,0);
});
