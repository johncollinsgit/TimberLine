import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source=readFileSync('resources/views/enterprise/campus-shell-script.blade.php','utf8');
class Element{
  constructor(id=''){this.id=id;this.children=[];this.hidden=false;this.value='';this.attrs={};this.parentNode=null;this.disabled=false;}
  append(...nodes){for(const node of nodes){if(node.parentNode)node.parentNode.children=node.parentNode.children.filter(v=>v!==node);this.children.push(node);node.parentNode=this;}}
  insertBefore(node,before){this.append(node);this.children=this.children.filter(v=>v!==node);this.children.splice(this.children.indexOf(before),0,node);}
  after(node){this.parentNode.append(node);}
  replaceChildren(...nodes){for(const node of this.children)node.parentNode=null;this.children=[];this.append(...nodes);}
  setAttribute(k,v){this.attrs[k]=String(v);}
  getAttribute(k){return this.attrs[k]??null;}
  focus(){this.owner.activeElement=this;}
  contains(node){return node===this||this.children.some(child=>child.contains(node));}
  querySelector(){return this.children[0]||null;}
  addEventListener(){}
}
function fixture(mode='private'){
 const nodes=new Map(),listeners={},calls=[],events=[],document={activeElement:null,modal:false,
  createElement:()=>new Element(),createComment:()=>new Element(),querySelectorAll:()=>document.modal?[{}]:[],querySelector:selector=>selector==='dialog[open]'&&document.modal?{}:null,
  addEventListener:(name,listener)=>{(listeners[name]??=[]).push(listener);},dispatchEvent:event=>{events.push(event);for(const listener of listeners[event.type]||[])listener(event);}};
 const $=id=>{if(!nodes.has(id)){const n=new Element(id);n.owner=document;nodes.set(id,n);}return nodes.get(id);};
 const home=new Element('home');home.append($('campus-shell-tools'),$('campus-assistant-panel'));$('campus-assistant-panel').append($('campus-assistant-body'));home.append($('ask'));$('ask').append($('ask-question'));$('campus-search-form').append(new Element('submit'));
 const actor={id:'area-a',name:'Selected department'};
 const context={console,URL,URLSearchParams,Map,document,location:{origin:'https://example.invalid',href:'https://example.invalid/campus'},$,transport:{mode,href:value=>String(value)},
  workspace:'workspace-a',viewer:'viewer-a',area:actor.id,section:'projects',workspaceLoadGeneration:1,reviewerMode:mode!=='private',tenantSlug:'fictional',urls:{back:'https://example.invalid/campus'},allowedSections:['home','projects','requests','ask'],
  data:{selected_area_id:actor.id,areas:[actor],projects:[],requests:[],tasks:[],features:{operations:true,ask:true}},
  askBusy:false,conversationState:null,readDraft:()=>({}),renderReadOnlyAssistant:()=>calls.push('read-only'),renderConversation:()=>calls.push('conversation'),renderVoice:()=>calls.push('voice'),voiceSession:{stop:()=>calls.push('stop')},
  opUrl:(path,query)=>'/enterprise'+path+'?tenant=fictional&'+new URLSearchParams(query),api:async path=>{calls.push(path);return{items:[],coverage:'Current authorized records'};},
  CustomEvent:class{constructor(type,init){this.type=type;this.detail=init.detail;}},EverbranchTransport:{pendingWrites:0,refreshBlockers:0}};
 vm.createContext(context);vm.runInContext(source,context);return{context,$,document,calls,events,fire:(name,event={})=>(listeners[name]||[]).forEach(listener=>listener(event))};
}
const submit={preventDefault(){}};
test('native global search uses current CampusSearch adapter and rejects external links',async()=>{
 const f=fixture();f.$('campus-search-query').value='Roof';f.context.api=async path=>{f.calls.push(path);return{coverage:'Current authorized records',items:[{kind:'project',title:'Safe <record>',source_url:'https://example.invalid/campus?tenant=fictional&area_id=area-a&section=projects&selected=project-a'},{kind:'project',title:'Wrong origin',source_url:'https://other.invalid/campus?tenant=fictional&section=projects'}]};};
 await f.context.campusSearchSubmit(submit);assert.deepEqual(f.calls,['/enterprise/search?tenant=fictional&q=Roof']);assert.equal(f.$('campus-search-results').children.length,1);assert.equal(f.$('campus-search-results').children[0].children[1].children[0].textContent,'Safe <record>');assert.equal(f.context.section,'projects');
});
test('shared search stays within current returned projection and never calls native APIs',async()=>{
 const f=fixture('shared');f.$('campus-search-query').value='roof';f.context.data.projects=[{id:'project-a',name:'Roof repair',description:'Current shared project'}];
 await f.context.campusSearchSubmit(submit);assert.deepEqual(f.calls,[]);assert.equal(f.$('campus-search-results').children.length,1);assert.match(f.$('campus-search-coverage').textContent,/currently loaded, authorized shared department/);
});
test('reset and changed projection cannot revive a late search result',async()=>{
 const f=fixture();let finish;f.$('campus-search-query').value='roof';f.context.api=()=>new Promise(resolve=>finish=resolve);const pending=f.context.campusSearchSubmit(submit);f.context.resetCampusShell();f.context.data={...f.context.data,selected_area_id:'area-b'};f.context.area='area-b';finish({items:[{title:'stale',source_url:'https://example.invalid/campus?tenant=fictional&section=projects'}],coverage:'stale'});await pending;
 assert.equal(f.$('campus-search-results-panel').hidden,true);assert.equal(f.$('campus-search-results').children.length,0);assert.notEqual(f.$('campus-search-coverage').textContent,'stale');
});
test('panel parks the same Ask draft without navigation or any request',()=>{
 const f=fixture();f.$('ask-question').value='Unsent current-work question';const original=f.$('ask-question'),localFile={name:'local-only.png'};original.localFile=localFile;
 f.$('campus-assistant-launcher').onclick();assert.equal(f.context.campusAssistantActive(),true);assert.equal(f.$('ask').parentNode,f.$('campus-assistant-body'));
 f.$('campus-assistant-close').onclick();assert.equal(f.$('campus-assistant-panel').hidden,true);assert.equal(f.document.activeElement,f.$('campus-assistant-launcher'));
 f.context.section='requests';f.$('campus-assistant-launcher').onclick();assert.equal(f.$('ask-question'),original);assert.equal(original.value,'Unsent current-work question');assert.equal(original.localFile,localFile);assert.deepEqual(f.events,[]);assert.ok(!f.calls.some(value=>String(value).startsWith('/')));
});
test('reviewer and disabled native gates cannot mount native Ask or voice',()=>{
 for(const mode of ['sample','shared']){const f=fixture(mode);f.$('campus-assistant-launcher').onclick();assert.equal(f.context.campusAssistantActive(),false);assert.deepEqual(f.calls,[]);assert.equal(f.$('ask').parentNode.id,'home');}
 const f=fixture();f.context.data.features={};f.$('campus-assistant-launcher').onclick();assert.equal(f.context.campusAssistantActive(),false);assert.deepEqual(f.calls,[]);assert.equal(f.$('campus-assistant-existing').hidden,true);
});
test('active native modal prevents launcher focus changes; Escape restores only its own opener',()=>{
 const f=fixture();f.document.modal=true;f.$('campus-assistant-launcher').onclick();assert.equal(f.context.campusAssistantActive(),false);assert.deepEqual(f.calls,[]);
 f.document.modal=false;f.$('campus-assistant-launcher').onclick();let prevented=false;f.fire('keydown',{key:'Escape',target:f.$('ask-question'),preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(f.$('campus-assistant-panel').hidden,true);assert.equal(f.document.activeElement,f.$('campus-assistant-launcher'));
});
test('pending or uncertain writes retain the current work instead of following a search link',async()=>{
 const f=fixture();f.$('campus-search-query').value='roof';f.context.api=async()=>({items:[{kind:'project',title:'Roof',source_url:'https://example.invalid/campus?tenant=fictional&area_id=area-a&section=projects&selected=project-a'}],coverage:'Current'});await f.context.campusSearchSubmit(submit);const link=f.$('campus-search-results').children[0].children[1].children[0];
 for(const field of ['pendingWrites','refreshBlockers']){f.context.EverbranchTransport[field]=1;let prevented=false;link.onclick({button:0,preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.deepEqual(f.events,[]);f.context.EverbranchTransport[field]=0;}
});

test('explicit empty draft stays empty, but exact unknown pending question is retained',()=>{
 const f=fixture();f.$('campus-assistant-launcher').onclick();f.$('ask-question').value='Discard this unsent draft';f.$('campus-assistant-close').onclick();f.$('campus-assistant-launcher').onclick();assert.equal(f.$('ask-question').value,'Discard this unsent draft');f.$('ask-question').value='';f.$('campus-assistant-close').onclick();f.$('campus-assistant-launcher').onclick();assert.equal(f.$('ask-question').value,'');
 const pending={area_id:'area-a',question:'Exact unknown question',request_id:'same-original-request'};f.context.askPending=pending;f.$('ask-question').value='';f.$('campus-assistant-close').onclick();f.$('campus-assistant-launcher').onclick();assert.equal(f.$('ask-question').value,pending.question);assert.equal(f.context.askPending,pending);
});
const readOnlyCode=readFileSync('resources/views/enterprise/read-only-assistant-script.blade.php','utf8');
const readOnlyHandler=readOnlyCode.slice(readOnlyCode.indexOf("  $('read-only-form').onsubmit"));
for(const kind of ['captured-after-close','late-after-close','close-reopen-ABA'])test('actual read-only handler fences '+kind,async()=>{
 const f=fixture();Object.assign(f.context,{readOnlyGeneration:0,nextBase:'/enterprise',nextQuery:()=>'?tenant=fictional',renderedAnswers:0,showReadOnlyAnswer:()=>f.context.renderedAnswers++});f.context.data.features.read_only_assistant=true;f.$('read-only-intent').value='overview';vm.runInContext(readOnlyHandler,f.context);
 f.$('campus-assistant-launcher').onclick();const captured=f.$('read-only-form').onsubmit;let finish,requests=0;f.context.api=()=>{requests++;return new Promise(resolve=>finish=resolve);};let pending;
 if(kind!=='captured-after-close')pending=captured(submit);f.$('campus-assistant-close').onclick();assert.equal(f.context.campusAssistantActive(),false);assert.equal(f.context.campusAssistantContextValid(),true);
 if(kind==='close-reopen-ABA')f.$('campus-assistant-launcher').onclick();if(kind==='captured-after-close')pending=captured(submit);
 finish?.({provider_dispatch:false,mode:'deterministic_records',scope:{user_id:'viewer-a',tenant_id:'workspace-a',area_id:'area-a'}});await pending;assert.equal(requests,kind==='captured-after-close'?0:1);assert.equal(f.context.renderedAnswers,0);
});
test('the existing full Ask page still admits its actual read-only handler',async()=>{
 const f=fixture();f.context.section='ask';Object.assign(f.context,{readOnlyGeneration:0,nextBase:'/enterprise',nextQuery:()=>'?tenant=fictional',renderedAnswers:0,showReadOnlyAnswer:()=>f.context.renderedAnswers++});f.context.data.features.read_only_assistant=true;f.$('read-only-intent').value='overview';f.context.api=async()=>({provider_dispatch:false,mode:'deterministic_records',scope:{user_id:'viewer-a',tenant_id:'workspace-a',area_id:'area-a'}});vm.runInContext(readOnlyHandler,f.context);await f.$('read-only-form').onsubmit(submit);assert.equal(f.context.renderedAnswers,1);
});
const conversationCode=readFileSync('resources/views/enterprise/conversation-script.blade.php','utf8');
const conversationSendCode=conversationCode.slice(conversationCode.indexOf('  async function conversationSend'),conversationCode.indexOf("  $('conversation-stop')",conversationCode.indexOf('  async function conversationSend')));
const conversationCurrentCode=conversationCode.split('\n').find(line=>line.startsWith('  function conversationCurrent'));
const conversationCheckCode=conversationCode.slice(conversationCode.indexOf("  $('conversation-check').onclick"),conversationCode.indexOf("  $('conversation-clear').onclick",conversationCode.indexOf("  $('conversation-check').onclick")));
test('closed advanced handlers cannot dispatch and saved/memory pending IDs survive Close',async()=>{
 const f=fixture();f.context.conversationMode=()=>true;f.context.conversationBusy=false;f.context.conversationState=null;f.context.api=async()=>{throw new Error('Closed control dispatched');};
 vm.runInContext(readFileSync('resources/views/enterprise/saved-assistant-script.blade.php','utf8'),f.context);vm.runInContext(readFileSync('resources/views/enterprise/private-memory-script.blade.php','utf8'),f.context);vm.runInContext(conversationSendCode,f.context);
 f.$('campus-assistant-launcher').onclick();vm.runInContext("savedAssistantPending={action:'save',id:'assistant-original',body:{request_id:'saved-original'}};savedAssistantBusy=true;memoryPending={action:'save',id:'memory-original',body:{request_id:'memory-original'}};memoryBusy=true;",f.context);const before=vm.runInContext('JSON.stringify([savedAssistantPending,memoryPending])',f.context);
 f.$('campus-assistant-close').onclick();assert.equal(vm.runInContext('JSON.stringify([savedAssistantPending,memoryPending])',f.context),before);assert.equal(vm.runInContext('savedAssistantBusy||memoryBusy',f.context),false);
 for(const [id,handler]of[['saved-assistant-form','onsubmit'],['memory-form','onsubmit'],['saved-assistant-refresh','onclick'],['memory-refresh','onclick'],['saved-assistant-delete-yes','onclick'],['memory-delete-yes','onclick']])await f.$(id)[handler](submit);
 await f.context.conversationSend(submit);assert.equal(vm.runInContext('JSON.stringify([savedAssistantPending,memoryPending])',f.context),before);
});
test('pending conversation Close preserves original receipt and enables only explicit status recovery',async()=>{
 const f=fixture();let receipt=null,finish,posted=null,answers=0;const calls=[];let id=0;
 Object.assign(f.context,{crypto:{randomUUID:()=>`original-${++id}`},conversationMode:()=>true,conversationAvailability:{ready:true,intents:{overview:true}},conversationScoped:()=>true,conversationState:null,conversationBusy:false,conversationOperation:null,conversationGeneration:0,conversationScope:'same-scope',conversationScopeValue:()=> 'same-scope',conversationReceipt:value=>{receipt=value;return true;},conversationFields:()=>{},conversationAnswer:()=>{answers++;return true;},chatMessage:()=>new Element(),nextBase:'/enterprise',nextQuery:()=>'?tenant=fictional'});
 vm.runInContext(conversationCurrentCode+'\n'+conversationSendCode+'\n'+conversationCheckCode,f.context);f.$('conversation-intent').value='overview';f.$('ask-question').value='Original pending question';f.context.api=(path,body)=>{calls.push({path,body});posted=body;return new Promise(resolve=>finish=resolve);};
 f.$('campus-assistant-launcher').onclick();const pending=f.context.conversationSend(submit);const savedReceipt=receipt,receiptBytes=JSON.stringify(receipt);assert.equal(f.context.conversationBusy,true);f.$('campus-assistant-close').onclick();assert.equal(JSON.stringify(receipt),receiptBytes);assert.deepEqual(receipt,savedReceipt);assert.equal(f.context.conversationBusy,false);assert.equal(f.context.conversationState.phase,'unconfirmed');assert.equal(f.context.conversationState.session_id,savedReceipt.session_id);assert.equal(f.context.conversationState.request_id,savedReceipt.request_id);
 f.$('campus-assistant-launcher').onclick();finish({});await pending;assert.equal(answers,0);assert.deepEqual(receipt,savedReceipt);assert.equal(f.context.conversationState.phase,'unconfirmed');
 f.context.api=async(path,body)=>{calls.push({path,body});return path.includes('/sessions/')?{session_id:savedReceipt.session_id,provider_dispatch:false,intent:'overview',history:[],state:'unconfirmed',pending_request_id:savedReceipt.request_id,message:'Original outcome remains held'}:{state:'ready',ready:true,message:'Current original campaign'};};await f.$('conversation-check').onclick();assert.equal(calls.length,3);assert.ok(calls[1].path.includes('/conversation/status'));assert.ok(calls[2].path.includes('/sessions/'+savedReceipt.session_id));assert.equal(calls[1].body,undefined);assert.equal(calls[2].body,undefined);assert.equal(posted.request_id,savedReceipt.request_id);assert.deepEqual(receipt,savedReceipt);assert.equal(f.context.conversationBusy,false);
});
test('same real voice instance rejects closed/ABA operations and admits a fresh explicit reopened turn',async()=>{
 const {createVoiceJobs}=await import('../../resources/js/enterprise/voice-jobs.js');const f=fixture(),mounts=[],effects=[],calls=[];let held;
 const voiceCode=readFileSync('resources/views/enterprise/voice-script.blade.php','utf8');const a=voiceCode.indexOf('  function renderVoice(){'),b=voiceCode.indexOf("  window.addEventListener('everbranch:voice-jobs-ready'",a);
 f.context.data.features={ask:true,voice_jobs:true,voice_generation:true,voice_drafts:true};
 Object.assign(f.context,{voiceScope:null,voiceSession:null,nextBase:'/enterprise',nextQuery:()=>'?tenant=fictional',conversationMode:()=>false,openVoiceJob:async()=>false,voiceReply:()=>effects.push('reply'),chatMessage:()=>{},clearVoiceEvidence:()=>effects.push('clear'),message:()=>effects.push('error'),resetVoice(){f.context.voiceSession?.dispose();f.context.voiceSession=null;f.context.voiceScope=null;}});
 const module={mount:(host,options)=>{const session=createVoiceJobs({...options,onState:()=>{},uuid:()=> 'same-fake-session'});mounts.push({session,options});return session;}};f.context.window={EverbranchVoiceJobs:module};f.context.EverbranchVoiceJobs=module;
 f.context.transport.request=(path,body)=>{calls.push({path,body});if(path.includes('/cancel'))return Promise.resolve({});return new Promise(resolve=>held=resolve);};vm.runInContext(voiceCode.slice(a,b),f.context);
 f.$('ask-form').onsubmit=()=>{};const submitStart=voiceCode.indexOf("  const originalAskSubmit=");vm.runInContext(voiceCode.slice(submitStart,voiceCode.indexOf("  for(const button",submitStart)),f.context);
 f.$('campus-assistant-launcher').onclick();const original=f.context.voiceSession,captured=f.$('ask-form').onsubmit;f.$('ask-question').value='Original held question';const pending=captured(submit),finishOld=held;
 f.$('campus-assistant-close').onclick();await Promise.resolve();assert.equal(calls.filter(row=>row.path.includes('/cancel')).length,1);assert.equal(calls.at(-1).body.area_id,'area-a');const closedCount=calls.length;await captured(submit);for(const name of ['lookup','generate','preparePreview'])await assert.rejects(mounts[0].options[name]({area_id:'area-a',question:'closed'}),{name:'AbortError'});assert.equal(calls.length,closedCount);
 f.$('campus-assistant-launcher').onclick();assert.equal(f.context.voiceSession,original);assert.equal(mounts.length,1);
 const reply={mode:'record_backed',provider_dispatch:false,outcome:'no_match',reply:'Checked current records',scope:{tenant_id:'workspace-a',user_id:'viewer-a',area_id:'area-a'}};finishOld(reply);await pending;assert.deepEqual(effects,[]);
 f.$('ask-question').value='Fresh explicit question';const fresh=captured(submit);held(reply);await fresh;assert.deepEqual(effects,['reply']);assert.equal(f.context.voiceSession,original);assert.equal(mounts.length,1);original.dispose();
});
