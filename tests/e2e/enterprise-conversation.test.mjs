import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import vm from 'node:vm';
const script=await readFile(new URL('../../resources/views/enterprise/conversation-script.blade.php',import.meta.url),'utf8');
class Node{
  constructor(tag='div',text=''){this.tagName=tag;this.textContent=text;this.children=[];this.value='';this.hidden=false;this.disabled=false;this.readOnly=false;this.dataset={};this.classList={add(){}};}
  append(...nodes){this.children.push(...nodes);}replaceChildren(...nodes){this.children=nodes;}get options(){return this.children;}get text(){return this.textContent+this.children.map(node=>node.text).join(' ');}focus(){this.focused=true;}scrollIntoView(){}
}
function fixture(){
  const nodes=new Map(),calls=[],storage=new Map();const $=id=>{if(!nodes.has(id))nodes.set(id,new Node());return nodes.get(id);};
  const availability={ready:true,state:'ready',scope:{tenant_id:'t1',user_id:'u1',area_id:'a1'},intents:{overview:true,building:true,costs:true},remaining_microusd:5000000,maximum_turn_microusd:20300,message:'Ready'};
  const context=vm.createContext({$,URL,crypto:{randomUUID},workspace:'t1',viewer:'u1',tenantSlug:'synthetic',area:'a1',section:'ask',nextBase:'/enterprise-campus/enterprise',nextQuery:()=>'?tenant=synthetic',location:{origin:'https://workspace.invalid',href:'https://workspace.invalid/enterprise-campus/teams'},data:{viewer_id:'u1',workspace:{name:'Fictional workshop'},areas:[{id:'a1',name:'Repairs'}],features:{conversation_ui:true,read_only_costs:true,read_only_assistant:true,resources:true},buildings:{items:[]},conversation:availability},transport:{mode:'private',href:href=>href},sessionStorage:{setItem:(key,value)=>storage.set(key,value),getItem:key=>storage.get(key)||null,removeItem:key=>storage.delete(key)},Option:class extends Node{constructor(text,value){super('option',text);this.value=value;}},document:{createElement:tag=>new Node(tag)},api:(url,body)=>new Promise((resolve,reject)=>calls.push({url,body,resolve,reject})),chatMessage:(text,kind)=>{const node=new Node('article',text);node.kind=kind;$('chat-log').append(node);return node;}});
  $('conversation-intent').append(...['overview','building','costs'].map(value=>{const node=new Node('option');node.value=value;return node;}));$('conversation-intent').value='overview';
  vm.runInContext(script+'\nrenderConversation();',context);
  const run=source=>vm.runInContext(source,context),send=question=>{$('ask-question').value=question;return run('conversationSend({preventDefault(){}})');};
  const reply=(call,text='Fictional generated reply')=>({mode:'openai_generated',provider_dispatch:true,provider_simulated:true,sample_data:true,reply:text,scope:{tenant_id:'t1',user_id:'u1',area_id:'a1'},session_id:call.body.session_id,request_id:call.body.request_id,sources:[],cited_refs:[],calculations:[],as_of:'2026-10-23T00:00:00Z'});
  return{$,calls,storage,context,availability,run,send,reply};
}
test('only explicit ready Send dispatches and browser persists opaque receipt IDs without question text',async()=>{
  const f=fixture();assert.equal(f.calls.length,0);const pending=f.send('Fictional private question');assert.equal(f.calls.length,1);const saved=[...f.storage.values()][0];assert.deepEqual(Object.keys(JSON.parse(saved)).sort(),['request_id','session_id']);assert.ok(!saved.includes('question'));assert.equal(f.$('ask-submit').disabled,true);
  f.calls[0].resolve(f.reply(f.calls[0]));await pending;assert.match(f.$('chat-log').text,/Fictional generated reply/);assert.match(f.$('chat-log').text,/Simulated OpenAI reply/);assert.equal(f.$('ask-submit').disabled,false);
  f.context.data.conversation.ready=false;f.run('renderConversation()');assert.equal(f.$('chat-log').text,'');await f.send('No send');assert.equal(f.calls.length,1);
});
test('receipt storage failure fails closed before any HTTP and never silently drops recoverability',async()=>{
  const f=fixture();f.context.sessionStorage.setItem=()=>{throw Error('Storage unavailable');};await f.send('No send');assert.equal(f.calls.length,0);assert.match(f.$('conversation-status').textContent,/No question was sent/);
});
test('Stop waiting fences late reply, retains exact receipt and only nonbillable checks can resume',async()=>{
  const f=fixture(),pending=f.send('Fictional question');const receipt=[...f.storage.values()][0];f.$('conversation-stop').onclick();f.calls[0].resolve(f.reply(f.calls[0],'LATE PRIVATE TEXT'));await pending;
  assert.doesNotMatch(f.$('chat-log').text,/LATE PRIVATE TEXT/);assert.equal([...f.storage.values()][0],receipt);assert.equal(f.$('ask-submit').disabled,true);assert.equal(f.$('conversation-check').focused,true);await f.send('No retry');assert.equal(f.calls.length,1);
  const check=f.$('conversation-check').onclick();assert.equal(f.calls[1].body,undefined);f.calls[1].resolve(f.availability);await new Promise(setImmediate);assert.equal(f.calls[2].body,undefined);
  const result=f.reply(f.calls[0]);f.calls[2].resolve({scope:result.scope,session_id:result.session_id,state:'active',intent:'overview',building_id:null,history:[{role:'user',text:'Fictional question'},{role:'assistant',text:result.reply}],last_result:result,provider_dispatch:false,message:'Restored'});await check;assert.equal(f.$('ask-submit').disabled,false);assert.equal(f.calls.filter(call=>call.body).length,1);
});
test('navigation or fresh role/resource/context change clears old evidence and fences in-flight replies',async()=>{
  for(const change of ["section='requests'","area='other'","viewer='u2'","workspace='t2'","data.features.read_only_costs=false","data.features.resources=false","data.viewer_id='u2'"]){const f=fixture(),pending=f.send('Old private question');f.run(change+';renderConversation()');f.calls[0].resolve(f.reply(f.calls[0],'OLD PRIVATE REPLY'));await pending;assert.equal(f.$('chat-log').text,'');assert.equal(f.$('ask-question').value,'');assert.equal(f.calls.length,1);}
});
test('foreign/malformed evidence and denial do not leave generated text or permit new-key retry',async()=>{
  for(const change of [result=>result.scope.user_id='foreign',result=>result.sources=[{ref:'project:p1',url:'https://foreign.invalid',title:'foreign'}],result=>result.calculations=[{project:'p',currency:'USD',estimate_minor:1,recorded_cost_minor:2,difference_minor:1,input_refs:['cost:missing']}]] ){
    const f=fixture(),pending=f.send('Question'),result=f.reply(f.calls[0],'DO NOT DISPLAY');change(result);f.calls[0].resolve(result);await pending;assert.doesNotMatch(f.$('chat-log').text,/DO NOT DISPLAY/);assert.equal(f.$('ask-submit').disabled,true);await f.send('No retry');assert.equal(f.calls.length,1);
  }
  const f=fixture(),pending=f.send('Private question');f.calls[0].reject(Object.assign(new Error('Denied'),{status:403}));await pending;assert.equal(f.$('chat-log').text,'');assert.equal(f.$('ask-question').value,'');assert.equal(f.$('ask-submit').disabled,true);assert.equal(f.storage.size,1);
});
test('policy refusal retains an existing session; clear requires explicit confirmation and does not refund',async()=>{
  const f=fixture(),first=f.send('Question');f.calls[0].resolve(f.reply(f.calls[0]));await first;const receipt=[...f.storage.values()][0],policy=f.send('Please send an email');f.calls[1].resolve({mode:'deterministic_policy',provider_dispatch:false,reply:'Nothing sent',session_id:f.calls[1].body.session_id,request_id:f.calls[1].body.request_id});await policy;assert.equal([...f.storage.values()][0],receipt);
  f.$('conversation-clear').onclick();assert.equal(f.calls.length,2);assert.equal(f.$('conversation-clear-confirm').hidden,false);f.$('conversation-clear-no').onclick();assert.equal(f.calls.length,2);
  f.$('conversation-clear').onclick();const clear=f.$('conversation-clear-yes').onclick();assert.equal(f.calls[2].body.confirm_end,true);assert.ok(!('refund' in f.calls[2].body));f.calls[2].resolve({status:'ended',session_id:f.calls[0].body.session_id});await clear;assert.equal(f.storage.size,0);assert.equal(f.$('chat-log').text,'');assert.match(f.$('conversation-status').textContent,/reservations and metering evidence remain/);
});
test('unavailable projection and sample mode cannot dispatch; pending restore never auto-POSTs',async()=>{
  const f=fixture();f.context.transport.mode='sample';f.run('renderConversation()');await f.send('No send');assert.equal(f.calls.length,0);
  f.context.transport.mode='private';f.context.data.conversation.ready=false;f.run('renderConversation()');await f.send('No send');assert.equal(f.calls.length,0);
  f.context.data.conversation.ready=true;f.run('renderConversation()');const turn=f.send('Question');f.calls[0].reject(new Error('Outcome unknown'));await turn;f.run('resetConversation();renderConversation()');assert.equal(f.calls.length,1);assert.equal(f.$('ask-submit').disabled,true);assert.match(f.$('conversation-status').textContent,/Check it before sending/);
});
test('an unresolved server receipt needs explicit local forget and fresh readiness; metering is untouched',async()=>{
  const f=fixture(),pending=f.send('Question');f.calls[0].reject(new Error('Outcome unknown'));await pending;
  f.$('conversation-clear').onclick();const clear=f.$('conversation-clear-yes').onclick();f.calls[1].reject(Object.assign(new Error('Not found'),{status:404}));await clear;
  assert.equal(f.storage.size,1);assert.equal(f.$('conversation-forget').hidden,false);assert.match(f.$('conversation-status').textContent,/Server content could not be cleared/);
  f.$('conversation-forget').onclick();assert.equal(f.storage.size,0);assert.equal(f.$('ask-submit').disabled,true);assert.equal(f.calls.length,2);assert.match(f.$('conversation-status').textContent,/existing cost exposure remain/);
});
