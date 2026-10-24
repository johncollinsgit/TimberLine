import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import vm from 'node:vm';
const script=await readFile(new URL('../../resources/views/enterprise/private-memory-script.blade.php',import.meta.url),'utf8');
const tick=()=>new Promise(setImmediate);
function fixture(){
  const nodes=new Map(),calls=[];let context;
  class Node{
    constructor(tag=''){this.tagName=tag;this.children=[];this.dataset={};this.value='';this.hidden=false;this.checked=false;this.disabled=false;this.textContent='';}
    append(...values){this.children.push(...values);}
    replaceChildren(...values){this.children=values;this.value=values[0]?.value||'';}
    querySelectorAll(tag){return this.children.flatMap(value=>[...(value.tagName===tag?[value]:[]),...value.querySelectorAll(tag)]);}
    focus(){if(!this.disabled)context.document.activeElement=this;}
    reset(){$('memory-confirm').checked=false;$('memory-retention').value='7';}
  }
  const $=id=>{if(!nodes.has(id))nodes.set(id,new Node());return nodes.get(id);};
  const scope={tenant_id:'t1',user_id:'u1',area_id:'a1'},source={ref:'project:'+randomUUID(),title:'Fictional project',url:'/source'},entry={id:randomUUID(),version:1,area_id:'a1',type:'preference',key:'reply_length',value:'brief',state:'available',captured_by:'u1',captured_at:'2026-10-23T00:00:00Z',expires_at:'2026-10-30T00:00:00Z'};
  const listing={items:[],scope,provider_dispatch:false,can_manage:true,can_delete:true,preferences:{reply_length:['brief','standard'],date_style:['iso','long']}};
  context=vm.createContext({$,crypto:{randomUUID},area:'a1',section:'ask',nextBase:'/enterprise-campus/enterprise',nextQuery:()=>'?tenant=fictional',conversationScope:'s1',scopeValue:'s1',conversationScopeValue:()=>context.scopeValue,conversationMode:()=>true,conversationState:null,conversationBusy:false,conversationGeneration:0,conversationFields(){},conversationScoped:value=>JSON.stringify(value.scope)===JSON.stringify(scope),conversationSource:value=>value.ref===source.ref&&value.url===source.url,conversationText(host,tag,text,className=''){const node=new Node(tag);node.textContent=text;node.className=className;host.append(node);return node;},transport:{href:value=>value},document:{activeElement:null,createElement:tag=>new Node(tag),querySelectorAll:()=>[]},Option:class extends Node{constructor(text,value){super('option');this.textContent=text;this.value=value;}},data:{features:{private_memory:true},private_memory:listing,conversation:{ready:true}},resetCount:0,resetConversation(){context.resetCount++;vm.runInContext('resetPrivateMemory()',context);},renderConversation(){},api:(url,body)=>new Promise((resolve,reject)=>calls.push({url,body,resolve,reject}))});
  vm.runInContext(script+'\nrenderPrivateMemory()',context);
  const run=source=>vm.runInContext(source,context),submit=()=>$('memory-form').onsubmit({preventDefault(){}}),fill=()=>{$('memory-key').value='reply_length';$('memory-value').value='brief';$('memory-retention').value='7';$('memory-confirm').checked=true;};
  return{$,calls,context,scope,source,entry,listing,run,submit,fill};
}
test('memory is initially off and saving requires typed choices, retention and explicit confirmation without dispatching chat',async()=>{
  const f=fixture();assert.equal(f.$('memory-use').checked,false);assert.equal(f.calls.length,0);f.$('memory-new').onclick();assert.equal(f.run('memoryCanSend()'),false);await f.submit();assert.equal(f.calls.length,0);f.fill();const pending=f.submit();
  assert.equal(f.calls.length,1);assert.equal(f.calls[0].body.type,'preference');assert.equal(f.calls[0].body.retention_days,7);assert.equal(f.calls[0].body.confirmed,true);assert.ok(!('history'in f.calls[0].body));assert.ok(!('question'in f.calls[0].body));assert.ok(!('model'in f.calls[0].body));
  const entry={...f.entry,id:f.calls[0].body.id};f.calls[0].resolve(entry);await tick();f.calls[1].resolve({...f.listing,items:[entry]});await pending;assert.equal(f.$('memory-use').checked,false);assert.equal(f.$('memory-form').hidden,true);assert.match(f.$('memory-status').textContent,/only after you opt in/);
});
test('source observations are explicitly previewed and saved by canonical reference/hash without a browser-supplied fact',async()=>{
  const f=fixture();f.run('memorySourceAction')(f.$('source-card'),f.source);f.$('source-card').children[0].onclick();assert.equal(f.calls.length,1);assert.equal(f.calls[0].body,undefined);assert.match(f.calls[0].url,/memories\/preview/);assert.equal(f.$('memory-save').disabled,true);
  assert.equal(f.context.document.activeElement,f.$('memory-status'));f.calls[0].resolve({scope:f.scope,provider_dispatch:false,source_ref:f.source.ref,source_url:f.source.url,source_title:'Fictional project',source_version:3,field:'status',value:'active',source_sha256:'a'.repeat(64)});await tick();assert.equal(f.context.document.activeElement,f.$('memory-field'));f.$('memory-confirm').checked=true;const pending=f.submit(),body=f.calls[1].body;assert.equal(body.source_sha256,'a'.repeat(64));assert.equal(body.source_ref,f.source.ref);assert.equal(body.field,'status');assert.ok(!('value'in body));assert.ok(!('observed_value'in body));assert.ok(!('source_url'in body));f.calls[1].reject(Object.assign(new Error('Changed'),{status:409}));await pending;assert.match(f.$('memory-status').textContent,/source changed/);
});
test('a mismatched preview remains unavailable and cannot be confirmed as a saved fact',async()=>{
  const f=fixture();f.run('memorySourceAction')(f.$('source-card'),f.source);f.$('source-card').children[0].onclick();f.calls[0].resolve({scope:{...f.scope,user_id:'foreign'},source_ref:f.source.ref,field:'status',source_sha256:'a'.repeat(64),provider_dispatch:false});await tick();f.$('memory-confirm').checked=true;await f.submit();assert.equal(f.calls.length,1);assert.equal(f.$('memory-save').disabled,true);assert.match(f.$('memory-preview').textContent,/unavailable/);
});
test('uncertain memory writes retain the exact request and lock edits; no automatic retry occurs',async()=>{
  const f=fixture();f.$('memory-new').onclick();f.fill();const first=f.submit(),body=f.calls[0].body;f.calls[0].reject(new Error('Unknown'));await first;assert.equal(f.calls.length,1);assert.equal(f.$('memory-key').disabled,true);assert.equal(f.$('memory-cancel').disabled,true);const retry=f.submit();assert.deepEqual(f.calls[1].body,body);f.calls[1].resolve({...f.entry,id:body.id});await tick();f.calls[2].resolve({...f.listing,items:[{...f.entry,id:body.id}]});await retry;assert.equal(f.$('memory-form').hidden,true);
});
test('context reset clears private values and fences a late response without repeating or cancelling its server operation',async()=>{
  const f=fixture();f.$('memory-new').onclick();f.fill();const pending=f.submit();f.run('resetPrivateMemory()');f.context.conversationScope='s2';f.context.scopeValue='s2';f.calls[0].resolve({...f.entry,id:f.calls[0].body.id,value:'OLD PRIVATE VALUE'});await pending;assert.equal(f.calls.length,1);assert.equal(f.$('private-memory').hidden,true);assert.equal(f.$('memory-use').checked,false);assert.equal(f.$('memory-list').children.length,0);assert.doesNotMatch(f.$('memory-status').textContent,/OLD PRIVATE/);
});
test('own deletion needs a separate confirmation and its uncertain exact retry cannot turn into a save or refund',async()=>{
  const f=fixture();f.context.data.private_memory={...f.listing,can_manage:false,items:[{...f.entry,type:'source_observation',state:'unavailable',key:undefined,value:undefined}]};f.run('renderPrivateMemory()');const remove=f.$('memory-list').querySelectorAll('button')[0];assert.equal(remove.textContent,'Delete memory');remove.onclick();assert.equal(f.calls.length,0);f.$('memory-delete-no').onclick();assert.equal(f.context.document.activeElement,f.$('memory-refresh'));remove.onclick();const first=f.$('memory-delete-yes').onclick(),body=f.calls[0].body;assert.ok(!('refund'in body));f.calls[0].reject(new Error('Unknown'));await first;await f.submit();assert.equal(f.calls.length,1);const retry=f.$('memory-delete-yes').onclick();assert.deepEqual(f.calls[1].body,body);f.calls[1].resolve({id:f.entry.id,status:'deleted'});await tick();f.calls[2].resolve({...f.listing,items:[]});await retry;assert.match(f.$('memory-status').textContent,/cannot be recalled/);
});
test('the review checkbox uses its input event target and active conversations prevent memory edits',()=>{
  const f=fixture();f.$('memory-new').onclick();f.context.document.activeElement=f.$('memory-key');f.$('memory-confirm').checked=true;f.$('memory-form').oninput({target:f.$('memory-confirm')});assert.equal(f.$('memory-confirm').checked,true);f.$('memory-form').oninput({target:f.$('memory-key')});assert.equal(f.$('memory-confirm').checked,false);f.$('memory-cancel').onclick();f.context.conversationState={phase:'active'};f.$('memory-new').onclick();assert.equal(f.$('memory-form').hidden,true);assert.equal(f.calls.length,0);
});
