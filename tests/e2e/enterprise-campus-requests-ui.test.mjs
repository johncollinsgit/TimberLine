import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {File} from 'node:buffer';
import {randomUUID} from 'node:crypto';

const html = fs.readFileSync(new URL('../../resources/demos/lees-mcrae-campus.html', import.meta.url), 'utf8');
const marker = html.indexOf('/* Public Requests UI:');
assert(marker >= 0);
const ui = html.slice(marker, html.indexOf('\n  // Campus operations prototype.', marker));
const between = (a, b) => {const start = ui.indexOf(a), end = ui.indexOf(b, start); assert(start >= 0 && end > start); return ui.slice(start, end);};
const pure = between('  /* PUBLIC_REQUEST_PURE_BEGIN */', '  /* PUBLIC_REQUEST_PURE_END */');
function context(extra = {}) {const c = {TextEncoder, Uint8Array, crypto:{randomUUID}, ...extra}; vm.createContext(c); vm.runInContext(pure + '\n' + ui.split('\n').find(line => line.startsWith('  const rawFile =')), c); return c;}
function memory() {const data = new Map(); return {data, getItem:key => data.get(key) ?? null, setItem:(key,value) => data.set(key,value)};}
const intent = (overrides = {}) => ({action:'request-comment', body:{id:'REQ-1',expected_version:3,request_key:randomUUID(),body:'Retain this fictional draft'}, ...overrides});
const file = (name = 'sample.png', mime = 'image/png') => new File([Uint8Array.from([137,80,78,71,13,10,26,10,0])], name, {type:mime});
const plain = value => JSON.parse(JSON.stringify(value));

test('durable exact pending body is isolated from later fields and survives reload without a new key', () => {
  const c = context(), storage = memory(), store = c.createRequestDraftStore(storage), draft = store.get('staff:REQ-1');
  draft.fields.comment = 'Retain this fictional draft'; const body = intent();
  const pending = store.reserve('staff:REQ-1', draft, body);
  draft.fields.comment = 'Newer unsent text'; body.body.body = 'Caller mutation'; store.save('staff:REQ-1', draft);
  assert.equal(pending.body.body, 'Retain this fictional draft');
  const restored = c.createRequestDraftStore(storage).get('staff:REQ-1');
  assert.equal(restored.pending.body.request_key, pending.body.request_key);
  assert.equal(restored.pending.outcome, 'unknown');
  assert.equal(restored.fields.comment, 'Newer unsent text');
});
test('storage refusal prevents reservation and unsupported retained routes remain blocked', () => {
  const c = context(), blocked = c.createRequestDraftStore({getItem:()=>null,setItem:()=>{throw Error('quota');}}), draft = blocked.get('staff:REQ-1');
  assert.throws(() => blocked.reserve('staff:REQ-1', draft, intent()), /Nothing was submitted/); assert.equal(draft.pending, null);
  const storage = memory(); storage.setItem('lees-mcrae-public-request-draft:v1:staff:REQ-1', JSON.stringify({fields:{comment:'Keep'},pending:intent({action:'../../enterprise/email-drafts'})}));
  const held = c.createRequestDraftStore(storage).get('staff:REQ-1'); assert.equal(held.pending.invalid, true); assert.equal(held.pending.retained.action, '../../enterprise/email-drafts');
  assert.throws(() => c.createRequestDraftStore(memory()).reserve('staff:REQ-1', {pending:null}, intent({action:'https://example.invalid'})), /fixed request interface/);
});
test('saved Low is preserved by omission while an explicit supported change is sent', () => {
  const c = context();
  assert.deepEqual(plain(c.requestSettingsChanges({priority:'low'}, 'low', 'J02')), {location_id:'J02'});
  assert.deepEqual(plain(c.requestSettingsChanges({priority:'low'}, 'normal', 'J02')), {location_id:'J02',priority:'normal'});
  assert.deepEqual(plain(c.requestSettingsChanges({priority:'urgent'}, 'urgent', 'J01')), {location_id:'J01'});
  vm.runInContext(ui.split('\n').find(line => line.startsWith('  function priorities(')), c);
  assert.match(c.priorities('low', true), /value="low" selected>Low \(saved\)/);
  assert.doesNotMatch(c.priorities('low'), /value="low"/);
});
test('detail and assignment drafts keep independent values and dirty captured versions', () => {
  const c = context(); vm.runInContext(between('  function fields(', '  function priorities('), c);
  const session = {request:{version:5},draft:{fields:{priority:'urgent',location_id:'J02',comment:'Unsent comment'},versions:{settings:1,comment:1},dirty:{settings:true,comment:true,assign:false},pending:null}};
  c.fields(session,'assign',{assign_priority:'low',assign_location:'J01',project_id:'visit',assignee_id:'manager'});
  c.fields(session,'settings',{priority:'normal',location_id:'J04'});
  assert.equal(session.draft.fields.priority,'urgent'); assert.equal(session.draft.fields.location_id,'J02'); assert.equal(session.draft.fields.comment,'Unsent comment');
  assert.equal(session.draft.fields.assign_priority,'low'); assert.equal(session.draft.fields.assign_location,'J01');
  assert.equal(session.draft.versions.settings,1); assert.equal(session.draft.versions.comment,1); assert.equal(session.draft.versions.assign,5);
});
test('placement changes keep the form node and restore the prior editor caret and scroll', () => {
  const frames=[], focus=[], selections=[], modes=[], node={isConnected:true,querySelectorAll:()=>[]}, editor={isConnected:true,selectionStart:7,selectionEnd:19,selectionDirection:'forward',focus:value=>focus.push(value),setSelectionRange:(...value)=>selections.push(value)};
  const dialog={isConnected:true,open:true,contains:value=>value===node,scrollTop:40,dataset:{},classList:{toggle:(name,value)=>modes.push([name,value])}}, session={node,interactionEpoch:0,placementEpoch:0,lastFocused:editor,preference:{save:value=>modes.push(value)}};
  const c=context({current:()=>true,document:{querySelector:()=>dialog,activeElement:{isConnected:true}},EverbranchDetailPlacement:{valid:value=>['center','side'].includes(value)},requestAnimationFrame:fn=>frames.push(fn)});
  vm.runInContext(between('  function applyPlacement(', '  function begin('),c);c.applyPlacement(session,'side');frames[0]();
  assert.equal(session.node,node);assert.equal(session.lastFocused,editor);assert.equal(dialog.scrollTop,40);assert.equal(dialog.dataset.requestPlacement,'side');assert.equal(focus.length,1);assert.deepEqual(plain(selections),[[7,19,'forward']]);
});
test('restricted linked job and genuine no-job states stay distinct', () => {
  const c = context();
  const restricted = c.requestLinkSummary({has_linked_task:true,can_open_task:false,job_access:'restricted',job_id:null});
  assert.match(restricted.task, /hidden/); assert.match(restricted.job, /hidden/); assert.doesNotMatch(restricted.job, /No operations/);
  assert.equal(c.requestLinkSummary({has_linked_task:false,job_access:'not_linked',job_id:null}).job, 'No operations job linked');
});
test('actual local file inspection accepts bounded JPEG PNG PDF metadata without bytes or URLs', async () => {
  const c = context();
  const values = [file(), new File([Uint8Array.from([255,216,255,0])], 'photo.jpg', {type:'image/jpeg'}), new File(['%PDF-1.4\n'], 'notes.pdf', {type:'application/pdf'})];
  for (const selected of values) {const metadata = plain(await c.inspectRequestFile(selected)); assert.deepEqual(Object.keys(metadata), ['name','mime','size']); assert.equal(metadata.name, selected.name); assert.equal(metadata.size, selected.size);}
});
test('local file inspection rejects disguised bytes oversize and path-like names', async () => {
  const c = context();
  for (const selected of [new File(['<svg/>'], 'fake.png', {type:'image/png'}), file('fake.pdf','application/pdf'), file('../sample.png'), new File([new Uint8Array(5242881)], 'large.pdf', {type:'application/pdf'})]) await assert.rejects(c.inspectRequestFile(selected));
});
test('late file byte inspection cannot insert into a retired persona or request draft', async () => {
  let resolve, isCurrent = true; const held = new Promise(r => {resolve = r;}), chosen = {name:'sample.png',type:'image/png',size:9,slice:()=>({arrayBuffer:()=>held})};
  const map = new Map(), draft = {files:[],dirty:{},pending:null}, session = {id:'REQ-1',persona:'staff',draft,request:{attachments:[]}};
  const c = context({localFiles:map,current:()=>isCurrent,drafts:{save:()=>assert.fail('Retired selection must not persist')},environment:{toast:()=>assert.fail('Retired selection must not toast')},find:()=>null});
  vm.runInContext(between('  async function chooseFiles(', '  async function preview('), c);
  const pending = c.chooseFiles(session, {files:[chosen]}); isCurrent = false; resolve(Uint8Array.from([137,80,78,71,13,10,26,10,0]).buffer); await pending;
  assert.equal(map.size, 0); assert.deepEqual(draft.files, []);
});
test('validated File objects stay in RAM while only metadata enters the retained draft', async () => {
  const chosen = file(), map = new Map(), draft = {files:[],dirty:{},pending:null}, session = {id:'REQ-1',persona:'staff',key:'staff:REQ-1',draft,request:{attachments:[]}}; let saved;
  const c = context({localFiles:map,current:()=>true,drafts:{save:(_key,value)=>{saved=JSON.stringify(value);}},environment:{toast:()=>assert.fail()},find:()=>null});
  vm.runInContext(between('  async function chooseFiles(', '  async function preview('), c); await c.chooseFiles(session, {files:[chosen]});
  assert.equal(map.get(draft.files[0].id).file, chosen); assert.equal(map.get(draft.files[0].id).requestId, 'REQ-1');
  assert.deepEqual(Object.keys(JSON.parse(saved).files[0]), ['id','name','mime','size']); assert.equal(draft.dirty.attachment, true);
});
const request = version => ({id:'REQ-1',version,priority:'normal',location_id:'J01',comments:[],attachments:[],assignment_history:[],can_view_change_history:true,change_history_recorded:false,change_history:[],can_edit:true,can_assign:false,can_reassign:true,can_comment:true,can_attach:true,has_linked_task:true,can_open_task:true,can_open_job:false});
function actionContext(persona = 'staff') {
  const storage = memory(), c = context({environment:{load:async()=>{},toast:()=>{}},currentData:{persona},current:()=>true,find:()=>null,updateReadOnlyParts:()=>{},save:()=>{},pendingUi:()=>{},localFiles:new Map()});
  c.drafts = c.createRequestDraftStore(storage);
  vm.runInContext(ui.split('\n').find(line => line.startsWith('  const admin =')), c);
  vm.runInContext(between('  function assertRead(', '  async function read(') + '\n' + between('  function effectivePriority(', '  function fields(') + '\n' + between('  function definitive(', '  function requestHeader('), c);
  const draft = c.drafts.get(persona + ':REQ-1'); Object.assign(draft, {fields:{comment:'Unsent comment'},files:[{id:'first'},{id:'second'}],versions:{comment:1,settings:1,attachment:1},dirty:{comment:true,settings:false,attachment:true}});
  return {c, session:{kind:'detail',id:'REQ-1',persona,key:persona + ':REQ-1',draft,payload:{options:{locations:[],projects:[]}},request:request(1),busy:false}};
}
test('sequential file saves advance only appropriate pins while dirty comment versions remain captured', async () => {
  const {c,session} = actionContext();
  for (const [id,version] of [['first',2],['second',3]]) {const pending={action:'request-attachment',body:{file:{id}}};session.draft.pending=pending;await c.settle(session,{id:'REQ-1',request:request(version)},pending);assert.equal(session.draft.versions.attachment,version);assert.equal(session.draft.versions.settings,version);assert.equal(session.draft.versions.comment,1);assert.equal(session.draft.fields.comment,'Unsent comment');}
  assert.deepEqual(plain(session.draft.files), []);
});
test('malformed post-dispatch projection retains exact unknown intent instead of clearing it', async () => {
  const {c,session} = actionContext(); c.environment.api=async()=>({id:'REQ-1',ok:true,replayed:false,request:{id:'REQ-1',version:2}});
  const body={id:'REQ-1',expected_version:1,request_key:randomUUID(),body:'Keep exactly'};await c.perform(session,'request-comment',body);
  assert.equal(session.draft.pending.outcome,'unknown');assert.equal(session.draft.pending.body.request_key,body.request_key);assert.equal(session.draft.pending.body.body,'Keep exactly');assert.equal(session.draft.fields.comment,'Unsent comment');
});
test('late action result does not clear a newer editor or replace the original action', async () => {
  const {c,session} = actionContext(); let resolve, active=true; c.current=()=>active;c.environment.api=()=>new Promise(r=>{resolve=r;});
  const body={id:'REQ-1',expected_version:1,request_key:randomUUID(),body:'Original'};const pending=c.perform(session,'request-comment',body);active=false;session.draft.fields.comment='Newer retained draft';resolve({id:'REQ-1',ok:true,replayed:false,request:request(2)});await pending;
  assert.equal(session.draft.pending.outcome,'result_received');assert.equal(session.draft.pending.body.request_key,body.request_key);assert.equal(session.draft.fields.comment,'Newer retained draft');
});
test('definitive rejection requires exact first-attempt identity and cannot settle an already unknown intent', () => {
  const {c} = actionContext(), pending={hadUnknown:false,body:{id:'REQ-1',request_key:randomUUID()}}, error={status:409,code:'request_conflict',automatic_retry:false,definitive_not_applied:true,...pending.body};
  assert.equal(c.definitive(error,pending),true);assert.equal(c.definitive({...error,request_key:'different-key'},pending),false);assert.equal(c.definitive(error,{...pending,hadUnknown:true}),false);
});


test('request field and assignment UI handlers refuse requester Manager and stale Admin role before dispatch', async () => {
  for (const persona of ['staff','manager','leader','it']) {
    const {c,session}=actionContext(persona); let calls=0;c.environment.api=async()=>{calls++;};
    await c.perform(session,'request-update',{id:'REQ-1',expected_version:1,request_key:randomUUID(),title:'Denied'});
    await c.perform(session,'request-assign',{id:'REQ-1',expected_version:1,request_key:randomUUID(),project:'visit',assignee_id:'manager'});
    assert.equal(calls,0);assert.equal(session.draft.pending,null);
  }
  const {c,session}=actionContext('admin');let calls=0;c.environment.api=async()=>{calls++;};c.currentData.persona='staff';
  await c.perform(session,'request-update',{id:'REQ-1',expected_version:1,request_key:randomUUID(),title:'Old Admin form'});
  assert.equal(calls,0);assert.equal(session.draft.pending,null);
});
test('current Admin details use the exact captured version and only settle a confirmed matching projection', async () => {
  const {c,session}=actionContext('admin');const bodies=[];c.environment.api=async(action,body)=>{bodies.push([action,plain(body)]);return {ok:true,replayed:false,id:'REQ-1',request:{...request(2),title:'Corrected request',details:'Admin detail correction'}};};
  const body={id:'REQ-1',expected_version:1,request_key:randomUUID(),title:'Corrected request',details:'Admin detail correction',location_id:'J02'};
  await c.perform(session,'request-update',body);
  assert.deepEqual(bodies,[['request-update',body]]);assert.equal(session.draft.pending,null);assert.equal(session.request.version,2);assert.equal(session.draft.fields.request_title,'Corrected request');assert.equal(session.draft.fields.request_details,'Admin detail correction');
});
test('Admin audit rendering escapes actual before and after fields without inventing historic changes', () => {
  const c=context({currentData:{persona:'admin',people:{}},nameFor:()=> 'Demo participant'});vm.runInContext(ui.split('\n').find(line=>line.startsWith('  const admin ='))+'\n'+ui.split('\n').find(line=>line.startsWith('  const esc ='))+'\n'+between('  function changeHistory(', '  function localFileRows('),c);
  const session={persona:'admin',request:{can_view_change_history:true,change_history:[]}};assert.match(c.changeHistory(session),/Not recorded\./);
  session.request.change_history=[{actor_label:'Demo admin',recorded_at:'fictional time',changes:[{field:'title',before:'Old <title>',after:'New & title'}]}];
  const result=c.changeHistory(session);assert.match(result,/Old &lt;title&gt;/);assert.match(result,/New &amp; title/);assert.match(result,/Before/);assert.match(result,/After/);
  session.persona='staff';assert.equal(c.changeHistory(session),'');
});


function placementFixture() {
  const frames = [], listeners = {}, restored = [], selections = [], stored = [], node = {isConnected:true,querySelectorAll:()=>[],addEventListener:(type,fn)=>{assert.equal(listeners[type],undefined);listeners[type]=fn;}};
  const editor = {isConnected:true,selectionStart:6,selectionEnd:17,selectionDirection:'backward',matches:()=>true,focus:()=>restored.push('editor'),setSelectionRange:(...v)=>selections.push(v)};
  const close = {isConnected:true,focus:()=>restored.push('close')};
  const dialog = {isConnected:true,open:true,contains:value=>value===node,scrollTop:35,dataset:{},classList:{toggle:()=>{}}};
  const session = {node,interactionEpoch:0,placementEpoch:0,preference:{save:mode=>stored.push(mode)}};
  const c = context({current:()=>true,document:{querySelector:()=>dialog,activeElement:close},EverbranchDetailPlacement:{valid:mode=>['center','side'].includes(mode)},requestAnimationFrame:fn=>frames.push(fn)});
  vm.runInContext(between('  function bindInteractions(', '  function begin('),c);
  c.bindInteractions(session);
  return {c,session,node,editor,close,dialog,frames,listeners,restored,selections,stored};
}
for (const type of ['pointerdown','keydown','focusin','input']) test('new owned '+type+' cancels queued focus and scroll restoration', () => {
  const f=placementFixture(); f.c.applyPlacement(f.session,'side');
  f.listeners[type]({target:f.editor}); f.dialog.scrollTop=91; f.frames[0]();
  assert.equal(f.session.interactionEpoch,1); assert.equal(f.dialog.scrollTop,91); assert.deepEqual(f.restored,[]); assert.deepEqual(f.selections,[]);
  if(type==='focusin') assert.equal(f.session.lastFocused,f.editor);
});
test('latest placement alone restores its exact editor selection and scroll when callbacks arrive out of order', () => {
  const f=placementFixture(); f.c.applyPlacement(f.session,'side');
  f.listeners.focusin({target:f.editor}); f.dialog.scrollTop=73; f.c.applyPlacement(f.session,'center');
  f.dialog.scrollTop=120; f.frames[1](); f.frames[0]();
  assert.deepEqual(f.restored,['editor']); assert.deepEqual(f.selections,[[6,17,'backward']]); assert.equal(f.dialog.scrollTop,73);
  assert.equal(f.dialog.dataset.requestPlacement,'center'); assert.equal(f.session.placementEpoch,2); assert.deepEqual(f.stored,['side','center']);
});
for (const kind of ['retired','closed','detached dialog','detached body','other modal','replaced body']) test('queued placement is inert for '+kind, () => {
  const f=placementFixture(); f.session.lastFocused=f.editor; f.c.applyPlacement(f.session,'side'); f.dialog.scrollTop=91;
  if(kind==='retired') f.c.current=()=>false;
  if(kind==='closed') f.dialog.open=false;
  if(kind==='detached dialog') f.dialog.isConnected=false;
  if(kind==='detached body') f.node.isConnected=false;
  if(kind==='other modal') f.c.document.querySelector=()=>({...f.dialog});
  if(kind==='replaced body') f.dialog.contains=()=>false;
  f.frames[0](); assert.equal(f.dialog.scrollTop,91); assert.deepEqual(f.restored,[]); assert.deepEqual(f.selections,[]);
});
test('denied replacement invalidates queued Close restoration without changing draft save or URL retirement', () => {
  const f=placementFixture(), steps=[];
  f.node.replaceChildren=()=>steps.push('replace'); f.node.append=()=>steps.push('append');
  f.c.save=()=>steps.push('save'); f.c.revoke=()=>steps.push('revoke'); f.c.document.createElement=()=>({});
  vm.runInContext(between('  function denied(', '  function bindInteractions('),f.c);
  f.c.applyPlacement(f.session,'side'); f.c.denied(f.session); f.dialog.scrollTop=91; f.frames[0]();
  assert.deepEqual(steps,['save','revoke','replace','append']); assert.equal(f.session.placementEpoch,2); assert.deepEqual(f.restored,[]); assert.equal(f.dialog.scrollTop,91);
});
test('retired owned events cannot change the current focus record or interaction epoch', () => {
  const f=placementFixture(); f.c.current=()=>false;
  for(const fn of Object.values(f.listeners)) fn({target:f.editor});
  assert.equal(f.session.interactionEpoch,0); assert.equal(f.session.lastFocused,undefined);
});
