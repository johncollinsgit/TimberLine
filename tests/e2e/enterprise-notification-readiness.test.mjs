import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../../resources/views/enterprise/next-script.blade.php', import.meta.url), 'utf8');
const begin = source.indexOf('  function notificationReadinessText('), end = source.indexOf('  async function loadMailboxes()', begin);
assert(begin >= 0 && end > begin);
const functions = source.slice(begin, end);
const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
function fixture(mode='native') {
  const node={textContent:''},calls=[],pending=[];
  const context={transport:{mode},area:'current-area',section:'ask',notificationGeneration:0,nextBase:'/enterprise',nextQuery:()=>'?tenant=current',
    $:id=>{ assert.equal(id,'notification-readiness');return node; },api:url=>{calls.push(url);const d=deferred();pending.push(d);return d.promise;}};
  vm.createContext(context);vm.runInContext(functions,context);return {context,node,calls,pending};
}
function metadata(reason='exact_confirmation_required') {
  return {in_app:{mode:'native_workspace',available:true},email:{mode:'own_mailbox',configured:true,dispatch_enabled:true,automatic_notifications:false,requires_confirmation:true,live_delivery_verified:false,reason_code:reason},
    sms:{mode:'unavailable',configured:false,dispatch_enabled:false,automatic_notifications:false,requires_confirmation:true,live_delivery_verified:false}};
}
test('readiness uses exact metadata GET and text, never a send or mailbox endpoint',async()=>{
  const f=fixture();const p=f.context.loadNotificationReadiness();assert.deepEqual(f.calls,['/enterprise/integrations?tenant=current&area_id=current-area']);
  f.pending[0].resolve({notifications:metadata()});await p;
  assert.match(f.node.textContent,/exact email preview and separate confirmation/);assert.match(f.node.textContent,/delivery is unverified/);
  assert.match(f.node.textContent,/Automatic work email and SMS notifications are not connected/);assert.match(f.node.textContent,/uncertain sends/);
});
test('separate fictional modes never query native integration authority',async()=>{
  for(const mode of ['sample','shared']){const f=fixture(mode);await f.context.loadNotificationReadiness();assert.deepEqual(f.calls,[]);assert.match(f.node.textContent,/Fictional work updates only/);}
});
test('old success and old failure cannot overwrite newer scope readiness',async()=>{
  for(const failOld of [false,true]){const f=fixture();const old=f.context.loadNotificationReadiness();f.context.area='other-area';const current=f.context.loadNotificationReadiness();
    f.pending[1].resolve({notifications:metadata()});await current;const text=f.node.textContent;
    if(failOld)f.pending[0].reject(Error('must not expose old error'));else f.pending[0].resolve({notifications:metadata('own_mailbox_required')});
    await old;assert.equal(f.node.textContent,text);}
});
test('leaving Ask or invalidating generation fences late results',async()=>{
  for(const invalidate of [f=>f.context.section='requests',f=>f.context.notificationGeneration++]){const f=fixture();const p=f.context.loadNotificationReadiness();invalidate(f);f.node.textContent='new current view';f.pending[0].resolve({notifications:metadata()});await p;assert.equal(f.node.textContent,'new current view');}
});
test('malformed or widened readiness fails closed and uses no raw error text',async()=>{
  const f=fixture();for(const change of [v=>v.email.mode='ses',v=>v.email.automatic_notifications=true,v=>v.email.live_delivery_verified=true,v=>v.email.configured=false,v=>v.sms.dispatch_enabled=true,v=>v.email.reason_code='<b>provider secret</b>']){
    const value=metadata();change(value);assert.equal(f.context.notificationReadinessText(value),'Delivery readiness is unavailable. No message was sent.');}
  const p=f.context.loadNotificationReadiness();f.pending[0].reject(Error('secret provider error'));await p;assert.equal(f.node.textContent,'Delivery readiness is unavailable. No message was sent.');
});
test('disabled and read-only metadata never claims a sender is ready',()=>{
  const f=fixture();for(const reason of ['drafts_disabled','read_only_scope','own_mailbox_required','sender_dispatch_disabled']){const value=metadata(reason);value.email.dispatch_enabled=false;value.email.configured=reason==='sender_dispatch_disabled';
    const text=f.context.notificationReadinessText(value);assert.doesNotMatch(text,/is ready for/);assert.match(text,/notifications are not connected/);}
});
test('reset and Ask hooks retain generation invalidation',()=>{
  assert.match(source,/notificationGeneration\+\+; \$\('notification-readiness'\)/);assert.match(source,/if \(section === 'ask'\) \{ loadNotificationReadiness\(\);/);
  const panel=fs.readFileSync(new URL('../../resources/views/enterprise/next-panels.blade.php', import.meta.url),'utf8');
  assert.match(panel,/id="notification-readiness" role="status"/);assert.match(panel,/separate approved session/);
});

function gmailLifecycleFixture() {
  const f=fixture(), nodes=new Map(), messages=[], refreshes=[];
  nodes.set('notification-readiness',f.node);
  f.context.$=id=>{if(!nodes.has(id))nodes.set(id,{disabled:false,hidden:false,replaceChildren(){this.cleared=true;}});return nodes.get(id);};
  Object.assign(f.context,{gmailBusy:false,gmailConnection:{connection_id:'own-connection'},emailGeneration:3,emailPreview:{id:'original-preview'},emailPending:{id:'original-intent'},
    URL,message:(...args)=>messages.push(args),mailboxLoads:0,loadMailboxes:()=>{f.context.mailboxLoads++;}});
  const load=f.context.loadNotificationReadiness;
  f.context.loadNotificationReadiness=()=>{const p=load();refreshes.push(p);return p;};
  const start=source.indexOf("  $('gmail-connect').onclick="),stop=source.indexOf("  $('email-form').oninput",start);
  assert(start>=0&&stop>start);vm.runInContext(source.slice(start,stop),f.context);
  return {...f,nodes,messages,refreshes};
}
test('actual disconnect handler refreshes readiness after success uncertain or failed revoke and fences old ready replies',async()=>{
  for(const outcome of ['accepted','uncertain','failed']){
    const f=gmailLifecycleFixture();const initial=f.context.loadNotificationReadiness();f.pending[0].resolve({notifications:metadata()});await initial;
    assert.match(f.node.textContent,/is ready for/);
    const old=f.context.loadNotificationReadiness();const disconnect=f.nodes.get('gmail-disconnect').onclick();
    assert.equal(f.calls[2],'/enterprise/gmail/own-connection/disconnect?tenant=current');
    if(outcome==='failed')f.pending[2].reject(Error('fixed fictional failed revoke'));else f.pending[2].resolve({provider_revocation:outcome});
    await disconnect;
    assert.equal(f.context.mailboxLoads,1);assert.equal(f.calls[3],'/enterprise/integrations?tenant=current&area_id=current-area');
    const unavailable=metadata('own_mailbox_required');unavailable.email.configured=false;unavailable.email.dispatch_enabled=false;
    f.pending[3].resolve({notifications:unavailable});await f.refreshes[2];
    const settled=f.node.textContent;assert.doesNotMatch(settled,/is ready for/);assert.match(settled,/Connect your own authorized mailbox/);
    f.pending[1].resolve({notifications:metadata()});await old;assert.equal(f.node.textContent,settled);
    if(outcome!=='failed'){assert.equal(f.context.emailPreview,null);assert.equal(f.context.emailPending,null);assert.equal(f.nodes.get('email-confirm').hidden,true);}
  }
});
test('actual connection handler refreshes readiness on settle but navigation away suppresses either lifecycle refresh',async()=>{
  for(const kind of ['gmail-connect','gmail-disconnect']){
    const f=gmailLifecycleFixture();const p=f.nodes.get(kind).onclick();f.pending[0].reject(Error('fixed fictional failure'));await p;
    assert.equal(f.context.mailboxLoads,1);assert.equal(f.calls[1],'/enterprise/integrations?tenant=current&area_id=current-area');
    f.pending[1].reject(Error('fixed status failure'));await f.refreshes[0];assert.equal(f.node.textContent,'Delivery readiness is unavailable. No message was sent.');
    const away=gmailLifecycleFixture();const operation=away.nodes.get(kind).onclick();away.context.area='another-area';away.pending[0].reject(Error('late failure'));await operation;
    assert.equal(away.calls.length,1);assert.equal(away.context.mailboxLoads,0);assert.equal(away.refreshes.length,0);
  }
});
