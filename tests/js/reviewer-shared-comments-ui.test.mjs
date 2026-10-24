import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto,createHash} from 'node:crypto';
import vm from 'node:vm';
const [shared,clientSource,uiSource]=await Promise.all([
  '../../resources/js/enterprise/reviewer/shared-workspace.js',
  '../../resources/js/enterprise/reviewer/collaboration-client.js',
  '../../resources/views/enterprise/shared-comments-script.blade.php'
].map(path=>readFile(new URL(path,import.meta.url),'utf8')));
const id=n=>String(n).padStart(8,'0')+'-0000-4000-8000-000000000001',W=id(1),A=id(2),B=id(3),T=id(4),P=id(5),K=id(6);
const plain=v=>JSON.parse(JSON.stringify(v)),tick=()=>new Promise(resolve=>setImmediate(resolve));
async function until(check){for(let i=0;i<200;i++){if(check())return;await tick();}throw Error('Bounded expected UI transition did not occur.');}
const held=()=>{let resolve;const promise=new Promise(r=>resolve=r);return{resolve,promise};};
class Node {
  constructor(tag,doc){this.tagName=tag.toUpperCase();this.doc=doc;this.parentNode=null;this.children=[];this.listeners=new Map();this.style={};this.dataset={};this.value='';this.hidden=false;this.disabled=false;this.readOnly=false;this.scrollTop=0;this._text='';}
  get isConnected(){return this===this.doc.body||!!this.parentNode?.isConnected;}
  set textContent(v){this.replaceChildren();this._text=String(v);}
  get textContent(){return this._text+this.children.map(n=>n.textContent).join('');}
  set innerHTML(v){throw Error('Untrusted HTML construction is forbidden in this UI.');}
  append(...nodes){for(const n of nodes){n.remove();n.parentNode=this;this.children.push(n);}}
  prepend(...nodes){for(const n of [...nodes].reverse()){n.remove();n.parentNode=this;this.children.unshift(n);}}
  remove(){if(this.parentNode){const siblings=this.parentNode.children;siblings.splice(siblings.indexOf(this),1);this.parentNode=null;}}
  replaceChildren(...nodes){for(const n of this.children)n.parentNode=null;this.children=[];this._text='';this.append(...nodes);}
  addEventListener(type,fn){if(!this.listeners.has(type))this.listeners.set(type,new Set());this.listeners.get(type).add(fn);}
  removeEventListener(type,fn){this.listeners.get(type)?.delete(fn);}
  dispatchEvent(event){event.preventDefault??=()=>{};event.target??=this;for(const fn of [...(this.listeners.get(event.type)||[])])fn(event);return true;}
  setAttribute(name,value){this[name]=String(value);}
  focus(){this.doc.activeElement=this;}
  click(){if(!this.disabled)this.dispatchEvent({type:'click'});}
}
class Document {
  constructor(){this.body=new Node('body',this);this.activeElement=this.body;}
  createElement(tag){return new Node(tag,this);}
  getElementById(id){const walk=n=>n.id===id?n:n.children.map(walk).find(Boolean);return walk(this.body)||null;}
}
const response=(value,status=200)=>({ok:status>=200&&status<300,status,json:async()=>plain(value)});
function fixture({values=new Map(),feature=true,name='Blaine'}={}){
  const doc=new Document(),drawer=doc.createElement('dialog'),detail=doc.createElement('div'),underlying=doc.createElement('form'),file=doc.createElement('input');
  drawer.id='work-drawer';drawer.dataset.placement='center';detail.id='detail';underlying.id='existing-edit-form';file.id='existing-file-input';file.files={fictionalSelectedFile:true};underlying.append(file);detail.append(underlying);drawer.append(detail);doc.body.append(drawer);detail.scrollTop=137;
  const binding={version_hash:'a'.repeat(64),source_hash:'b'.repeat(64),build_id:'synthetic-comments-ui'};
  const boot={reviewerWorkspace:{binding,endpoints:{projection:'/private/workspace/projection'}}};
  if(feature)boot.reviewerCollaboration={enabled:true,schema_version:1,workspace_id:W,browser_context:'c'.repeat(64),binding,expires_at:'2099-01-01T00:00:00Z',csrf:'synthetic-csrf',endpoints:{show:'/private/workspace/collaboration/show',comment:'/private/workspace/collaboration/comment',status:'/private/workspace/collaboration/status'}};
  let selected={workspace_id:W,browser_context:'c'.repeat(64),persona:'demo_manager',area_ids:[A],person_id:null,revision:1,phase:'ready'},navigation='requests:'+T,serverName=name,handler=null,readNameHandler=null,chooseCount=0,writes=0;
  const subscriptions=new Set(),events=new Map(),calls=[],records=new Map(),receipts=new Map();
  const record=target=>{const key=target.kind+':'+target.id;if(!records.has(key))records.set(key,{target:plain(target),version:1,revision:0,comments:[],can:true});return records.get(key);};
  const view=target=>{const r=record(target);return{workspace_id:W,target:plain(target),target_version:r.version,thread_revision:r.revision,can_comment:r.can,comments:plain(r.comments),before:null,files_available:false,expires_at:boot.reviewerCollaboration.expires_at};};
  let context;
  const normal=(url,body)=>{
    if(url.endsWith('/show'))return response(view(body.target));
    if(url.endsWith('/comment')){
      const digest=createHash('sha256').update(context.EverbranchSharedWorkspace.canonical(body)).digest('hex'),prior=receipts.get(body.request_id),r=record(body.target);
      if(prior){if(prior.digest!==digest)return response({error:'opaque'},409);return response({status:'applied',replayed:true,receipt:prior});}
      if(body.expected_reviewer_name!==serverName||body.expected_target_version!==r.version||body.expected_thread_revision!==r.revision)return response({error:'workspace_conflict',automatic_retry:false,definitive_not_applied:true,request_id:body.request_id,digest,workspace_id:W},409);
      writes++;r.revision++;const receipt={request_id:body.request_id,digest,comment_id:id(100+writes),thread_revision:r.revision};receipts.set(body.request_id,receipt);
      r.comments.push({id:receipt.comment_id,body:body.body,name:serverName,identity_status:'self_selected_display_name',created_at:'2026-10-24T00:00:00Z',mine:true});
      return response({status:'applied',replayed:false,receipt});
    }
    if(url.endsWith('/status')){const receipt=receipts.get(body.request_id);return response(receipt?{status:'applied',settled:true,receipt}:{status:'not_observed',settled:false});}
    throw Error('Unexpected route');
  };
  const workspace={mode:'shared',snapshot:()=>plain(selected),subscribe:fn=>{subscriptions.add(fn);return()=>subscriptions.delete(fn);}};
  context=vm.createContext({document:doc,URL,TextEncoder,TextDecoder,AbortController,crypto:webcrypto,location:{href:'https://workspace.invalid/private/campus',origin:'https://workspace.invalid'},sessionStorage:{getItem:k=>values.get(k)??null,setItem:(k,v)=>values.set(k,v)},addEventListener:(n,fn)=>{if(!events.has(n))events.set(n,new Set());events.get(n).add(fn);},removeEventListener:(n,fn)=>events.get(n)?.delete(fn),fetch:async(url,init)=>{const body=JSON.parse(init.body);calls.push({url,init,body});return handler?handler(url,body,init,normal):normal(url,body);}});
  vm.runInContext(shared,context);vm.runInContext(clientSource,context);vm.runInContext(uiSource,context);
  const options={boot,workspace,host:()=>detail,context:()=>navigation,readReviewerName:async()=>readNameHandler?readNameHandler():{...binding,reviewer_name:serverName},chooseReviewerName:()=>chooseCount++,tasksForProject:()=>[{id:K,title:'Fictional task <literal>'}]};
  let ui=context.EverbranchSharedCommentsUI.create(options);
  const $=id=>doc.getElementById(id),type=text=>{const input=$('shared-discussion-body');assert.ok(input&&!input.readOnly);input.value=text;input.dispatchEvent({type:'input'});};
  return {get ui(){return ui;},context,options,remount:()=>ui=context.EverbranchSharedCommentsUI.create(options),boot,workspace,doc,drawer,detail,underlying,file,values,calls,records,receipts,normal,view,$,type,record,
    setHandler:fn=>handler=fn,setName:v=>serverName=v,setReadName:fn=>readNameHandler=fn,
    select:area=>{selected.area_ids=[area];subscriptions.forEach(fn=>fn());},navigate:v=>{navigation=v;ui.invalidate();},loseGrant:()=>{selected.phase='blocked';selected.revision=null;subscriptions.forEach(fn=>fn());},get writes(){return writes;},get chooses(){return chooseCount;},emit:n=>events.get(n)?.forEach(fn=>fn())};
}

test('missing feature adds no controls; present malformed descriptor fails without native fallback',()=>{
 const no=fixture({feature:false});assert.equal(no.ui,null);assert.equal(no.calls.length,0);assert.equal(no.detail.children.length,1);
 const f=fixture();const bad=plain(f.boot);bad.reviewerCollaboration.endpoints.comment='https://foreign.invalid/comment';assert.throws(()=>f.context.EverbranchSharedCommentsUI.create({...f.options,boot:bad}),e=>e.code==='discussion_access');
 assert.equal(f.calls.length,0);
});
test('literal server comments, saved name and explicit post use exact CSRF client; no upload/native controls',async()=>{
 const f=fixture();f.record({kind:'ticket',id:T}).comments.push({id:id(8),body:'<img src=x onerror=bad()>',name:'<Blaine>',identity_status:'self_selected_display_name',created_at:'2026-10-24T00:00:00Z',mine:false});await f.ui.mount('ticket',T);
 assert.match(f.$('shared-discussion-feed').textContent,/<img src=x/);assert.equal(f.$('shared-discussion-feed').children[0].children[1].children.length,0);
 assert.match(f.$('shared-discussion-name').textContent,/Blaine.*self-selected/);assert.match(f.$('shared-discussion').textContent,/uploads are not available/);
 f.type('Current saved text');f.$('shared-discussion-post').click();await until(()=>f.writes===1&&f.$('shared-discussion-status').textContent.startsWith('Comment saved'));
 assert.equal(f.$('shared-discussion-body').value,'');assert.match(f.$('shared-discussion-feed').textContent,/Current saved text/);assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,1);const call=f.calls.find(c=>c.url.endsWith('/comment'));assert.equal(call.init.headers['X-CSRF-TOKEN'],'synthetic-csrf');assert.equal(call.body.expected_reviewer_name,'Blaine');assert.equal(call.body.expected_target_version,1);assert.equal(call.body.expected_thread_revision,0);
});
test('Center/Side changes and same-target remount preserve DOM/composer/files/focus/scroll with no new fetch',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Unsent context');const section=f.$('shared-discussion'),input=f.$('shared-discussion-body'),files=f.file.files;input.focus();const count=f.calls.length;
 f.drawer.dataset.placement='side';await f.ui.mount('ticket',T);assert.equal(f.$('shared-discussion'),section);assert.equal(f.$('shared-discussion-body'),input);assert.equal(input.value,'Unsent context');assert.equal(f.file.files,files);assert.equal(f.doc.activeElement,input);assert.equal(f.detail.scrollTop,137);assert.equal(f.underlying.parentNode,f.detail);assert.equal(f.calls.length,count);
});
test('per-target drafts remain distinct, incoming ticket is independent of task and project selector labels exact threads',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Incoming-only draft');await f.ui.mount('project',P);f.type('Project-only draft');const select=f.$('shared-discussion-thread');select.value='task:'+K;select.dispatchEvent({type:'change'});await until(()=>f.$('shared-discussion').children[0].textContent==='Task comments'&&!f.$('shared-discussion-body').readOnly);f.type('Task-only draft');await f.ui.mount('ticket',T);assert.equal(f.$('shared-discussion-body').value,'Incoming-only draft');assert.equal(f.calls.filter(c=>c.url.endsWith('/show')).at(-1).body.target.kind,'ticket');
 await f.ui.mount('project',P);assert.equal(f.$('shared-discussion-body').value,'Project-only draft');assert.equal(f.$('shared-discussion-thread').value,'project:'+P);
});
test('refresh never silently upgrades a nonempty draft pin; rejection needs Ack then explicit fresh review/new intent',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Pinned draft');f.record({kind:'ticket',id:T}).revision=1;f.$('shared-discussion-refresh').click();await until(()=>!f.$('shared-discussion-post').disabled);f.$('shared-discussion-post').click();await until(()=>!f.$('shared-discussion-ack').hidden&&!f.$('shared-discussion-ack').disabled);assert.equal(f.calls.find(c=>c.url.endsWith('/comment')).body.expected_thread_revision,0);assert.equal(f.writes,0);
 f.$('shared-discussion-ack').click();assert.equal(f.$('shared-discussion-post').disabled,true);assert.equal(f.$('shared-discussion-body').value,'Pinned draft');f.$('shared-discussion-review-pin').click();await until(()=>!f.$('shared-discussion-post').disabled);f.$('shared-discussion-post').click();await until(()=>f.writes===1);const attempts=f.calls.filter(c=>c.url.endsWith('/comment'));assert.equal(attempts[1].body.expected_thread_revision,1);assert.notEqual(attempts[0].body.request_id,attempts[1].body.request_id);
});
test('unknown blocks replacement even on another target; Check is read-only and explicit Retry retains complete original body/id',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Exact unknown note');let lost=true;f.setHandler((url,body,init,normal)=>{if(url.endsWith('/comment')&&lost){lost=false;throw Error('synthetic lost response');}return normal(url,body);});f.$('shared-discussion-post').click();await until(()=>f.$('shared-discussion-status').textContent.includes('unconfirmed'));
 const original=plain(f.calls.find(c=>c.url.endsWith('/comment')).body);await f.ui.mount('project',P);f.type('Other thread draft');assert.equal(f.$('shared-discussion-post').disabled,true);assert.match(f.$('shared-discussion').textContent,/Another thread/);
 await f.ui.mount('ticket',T);assert.equal(f.$('shared-discussion-body').value,'Exact unknown note');f.$('shared-discussion-check').click();await until(()=>f.$('shared-discussion-status').textContent.includes('not been observed'));assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,1);f.$('shared-discussion-retry').click();await until(()=>f.writes===1&&f.$('shared-discussion-status').textContent.startsWith('Comment saved'));const attempts=f.calls.filter(c=>c.url.endsWith('/comment'));assert.deepEqual(plain(attempts[1].body),original);await f.ui.mount('project',P);assert.equal(f.$('shared-discussion-body').value,'Other thread draft');
});
test('server-backed name refresh before Post captures the actual new name; no unconfirmed DOM identity is used',async()=>{
 const f=fixture({name:null});await f.ui.mount('ticket',T);f.type('Name-bound draft');assert.equal(f.$('shared-discussion-post').disabled,true);f.$('shared-discussion-choose-name').click();assert.equal(f.chooses,1);f.setName('Andrew');f.$('shared-discussion-name-refresh').click();await until(()=>!f.$('shared-discussion-post').disabled);f.setName('Blaine');f.$('shared-discussion-post').click();await until(()=>f.writes===1);assert.equal(f.calls.find(c=>c.url.endsWith('/comment')).body.expected_reviewer_name,'Blaine');
});
test('old load failure cannot clear a newer target and old success after selection away/back cannot render stale bytes',async()=>{
 const f=fixture(),hold=held();f.setHandler((url,body,init,normal)=>url.endsWith('/show')&&body.target.id===T?hold.promise:normal(url,body));const old=f.ui.mount('ticket',T);await tick();await f.ui.mount('project',P);const section=f.$('shared-discussion');hold.resolve(response({error:'denied'},403));await old;assert.equal(f.$('shared-discussion'),section);assert.equal(section.children[0].textContent,'Project comments');
 const h=held();f.setHandler(()=>h.promise);const older=f.ui.mount('ticket',T);await tick();f.select(B);f.select(A);h.resolve(response(f.view({kind:'ticket',id:T})));await older;assert.equal(f.$('shared-discussion'),null);
});
test('grant loss and navigation clear old visible bytes while retaining unsent drafts until fresh authorized read',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Retained private draft');const input=f.$('shared-discussion-body');f.navigate('projects:'+P);assert.equal(input.value,'');assert.equal(f.$('shared-discussion'),null);await f.ui.mount('ticket',T);assert.equal(f.$('shared-discussion-body').value,'Retained private draft');f.loseGrant();assert.equal(f.$('shared-discussion'),null);assert.equal(f.file.parentNode,f.underlying);
});

test('late confirmed old-target save settles only its exact draft and never replaces the new target body or controls',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Old exact note');const hold=held();f.setHandler((url,body,init,normal)=>url.endsWith('/comment')?hold.promise:normal(url,body));f.$('shared-discussion-post').click();await until(()=>f.calls.some(c=>c.url.endsWith('/comment')));await f.ui.mount('project',P);const current=f.$('shared-discussion');
 const request=f.calls.find(c=>c.url.endsWith('/comment'));hold.resolve(f.normal(request.url,request.body));await until(()=>!f.$('shared-discussion-name-refresh').disabled);assert.equal(f.$('shared-discussion'),current);assert.equal(current.children[0].textContent,'Project comments');assert.doesNotMatch(f.$('shared-discussion-feed').textContent,/Old exact note/);assert.equal(f.$('shared-discussion-body').value,'');f.type('New project draft');assert.equal(f.$('shared-discussion-post').disabled,false);
});
test('lost applied response is recovered without a second Post and remount retains exact pending/draft without automatic IO',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Once only');let first=true;f.setHandler((url,body,init,normal)=>{if(url.endsWith('/comment')&&first){first=false;normal(url,body);throw Error('lost after commit');}return normal(url,body);});f.$('shared-discussion-post').click();await until(()=>f.$('shared-discussion-status').textContent.includes('unconfirmed'));assert.equal(f.writes,1);
 f.ui.destroy();const calls=f.calls.length;f.remount();assert.equal(f.calls.length,calls);await f.ui.mount('ticket',T);assert.equal(f.$('shared-discussion-body').value,'Once only');f.$('shared-discussion-check').click();await until(()=>f.$('shared-discussion-status').textContent.startsWith('Comment saved'));assert.equal(f.$('shared-discussion-body').value,'');assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,1);assert.equal(f.writes,1);
});
test('unknown intent never offers acknowledgment after a later definitive-looking conflict',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Unknown forever until receipt');let first=true;f.setHandler((url,body,init,normal)=>{if(!url.endsWith('/comment'))return normal(url,body);if(first){first=false;throw Error('lost');}const digest=createHash('sha256').update(f.context.EverbranchSharedWorkspace.canonical(body)).digest('hex');return response({error:'workspace_conflict',automatic_retry:false,definitive_not_applied:true,request_id:body.request_id,digest,workspace_id:W},409);});f.$('shared-discussion-post').click();await until(()=>f.$('shared-discussion-status').textContent.includes('unconfirmed'));f.$('shared-discussion-refresh').click();await until(()=>!f.$('shared-discussion-retry').disabled);f.$('shared-discussion-retry').click();await until(()=>f.calls.filter(c=>c.url.endsWith('/comment')).length===2&&!f.$('shared-discussion-check').disabled);assert.equal(f.$('shared-discussion-ack').hidden,true);assert.equal(f.$('shared-discussion-post').disabled,true);assert.equal(f.writes,0);
});
test('name authority failure withholds old bytes and makes zero posts; fresh server name and thread can restore retained text',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Private retained text');f.setReadName(()=>{throw Error('PRIVATE RAW NAME ERROR');});f.$('shared-discussion-post').click();await until(()=>f.$('shared-discussion-status').textContent.includes('Saved name access'));assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,0);assert.equal(f.$('shared-discussion-body').value,'');assert.equal(f.$('shared-discussion-feed').textContent,'');assert.doesNotMatch(f.$('shared-discussion-status').textContent,/PRIVATE RAW/);
 f.setReadName(()=>({...f.boot.reviewerCollaboration.binding,reviewer_name:'Blaine'}));f.$('shared-discussion-refresh').click();f.$('shared-discussion-name-refresh').click();await until(()=>!f.$('shared-discussion-post').disabled);assert.equal(f.$('shared-discussion-body').value,'Private retained text');
});
test('pagehide and same-target reload preserve original unsent pin and do not write or auto-upgrade it',async()=>{
 const f=fixture();await f.ui.mount('ticket',T);f.type('Reloaded old pin');f.emit('pagehide');assert.equal(f.$('shared-discussion'),null);f.ui.destroy();f.record({kind:'ticket',id:T}).version=2;f.remount();await f.ui.mount('ticket',T);assert.equal(f.$('shared-discussion-body').value,'Reloaded old pin');assert.equal(f.calls.filter(c=>c.url.endsWith('/comment')).length,0);f.$('shared-discussion-post').click();await until(()=>!f.$('shared-discussion-ack').hidden);assert.equal(f.calls.find(c=>c.url.endsWith('/comment')).body.expected_target_version,1);assert.equal(f.writes,0);
});
