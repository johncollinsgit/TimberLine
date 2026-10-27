import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
const source=new URL('../../',import.meta.url);
const app=await readFile(new URL('resources/views/enterprise/app.blade.php',source),'utf8');
const directory=await readFile(new URL('resources/views/enterprise/shared-directory-script.blade.php',source),'utf8');
class Element{
 constructor(tag='div'){this.tagName=tag;this.children=[];this.hidden=false;this.open=false;this.options=[];this._value='';this.textContent='';this.classList={add(){}};this.dataset={};}
 append(...nodes){this.children.push(...nodes);}
 replaceChildren(...nodes){this.children=[...nodes];}
 setAttribute(){}
 closest(){return new Element('label');}
 set innerHTML(value){this._html=value;if(this.tagName==='select'){this.options=[...value.matchAll(/<option value="([^"]*)">([^<]*)<\/option>/g)].map(m=>({value:m[1],textContent:m[2]}));this._value=this.options[0]?.value||'';}}
 get innerHTML(){return this._html||'';}
 set value(value){this._value=this.tagName==='select'&&!this.options.some(o=>o.value===String(value))?'':String(value);}
 get value(){return this._value;}
 showModal(){this.open=true;}
 reset(){for(const input of Object.values(this.elements||{}))input.value='';}
}
const allButtons=root=>root.children.flatMap(child=>child.tagName==='button'?[child]:child.children?allButtons(child):[]);
function fixture({filter='B',pending=null}={}){
 const elements=new Map();const $=id=>{if(!elements.has(id))elements.set(id,new Element(['request-building','request-department'].includes(id)?'select':'div'));return elements.get(id);};
 $('create-form').elements=Object.fromEntries(['location','latitude','longitude'].map(id=>[id,new Element('input')]));
 const areaData=(id)=>({shared:{revision:1},selected_area_id:id,areas:[{id:'A',name:'Fictional A'},{id:'B',name:'Fictional B'}],buildings:{items:[{id:'building-'+id,title:'Building '+id,version:1}]},requests:[],projects:[],members:[],can_submit:true});
 let resolve;const target=new Promise(done=>{resolve=done;});const calls=[],messages=[];
 $('request-building');$('request-department');
 const context=vm.createContext({urls:{data:'/enterprise/data?tenant=fictional'},document:{createElement:tag=>new Element(tag),createTextNode:text=>({textContent:text}),addEventListener(){},dispatchEvent(){}},$,crypto:webcrypto,CustomEvent:class{constructor(type,options){Object.assign(this,{type,...options});}},calls,messages,areaData,resolve,api:url=>{calls.push(url);return target;},nextBase:'/enterprise',nextQuery:()=>'?tenant=fictional',esc:value=>String(value),canClassifyRequest:()=>true,message:value=>messages.push(value),captureSharedPin:()=>({revision:1})});
 const start=app.slice(app.indexOf('  let requestBuildingChoices='),app.indexOf("  $('request-department').onchange"));
 const create=app.slice(app.indexOf('  function create('),app.indexOf("  $('request-building').onchange"));
 vm.runInContext(`const transport={mode:'shared'};let data=areaData('A'),section='buildings',area='A',pending=${JSON.stringify(pending)},draft=null,createAction=null,createSharedPin=null;const catalogFilters={department:${JSON.stringify(filter)}},reviewerMode=true;${start}\n${create}\n${directory}\nglobalThis.hooks={createStale(version){return create('request.create',{source:data,building:{...data.buildings.items[0],version}});},replaceData(){data={...data};},render:renderSharedDirectory,buttons:()=>sharedDirectory.children,root:sharedDirectory,finish:()=>resolve(areaData('B')),state:()=>({department:$('request-department').value,building:$('request-building').value,intake:requestCreateArea,ready:requestCreateReady,open:$('create-dialog').open,pending})};`,context);
 context.hooks.render();allButtons(context.hooks.root).find(b=>b.textContent==='Building A').onclick();
 return {context,elements,calls,messages,clickNew(){allButtons(context.hooks.root).find(b=>b.textContent==='New ticket in this building').onclick();}};
}
test('building New ticket preserves the clicked building and its department despite a prior different catalog filter',async()=>{
 const f=fixture();f.clickNew();f.context.hooks.finish();await new Promise(done=>setImmediate(done));
 assert.deepEqual(JSON.parse(JSON.stringify(f.context.hooks.state())),{department:'A',building:'building-A',intake:'A',ready:true,open:true,pending:null});
});
test('blocked new creation never modifies the retained request building fields',()=>{
 const f=fixture({filter:'all',pending:{request_id:'original-unknown'}});const field=f.elements.get('request-building');field.innerHTML='<option value="original-building">Original retained building</option>';field.value='original-building';f.clickNew();
 assert.equal(field.value,'original-building');assert.equal(f.context.hooks.state().pending.request_id,'original-unknown');
});

test('stale source closure refuses before opening or resetting a form',()=>{const f=fixture({filter:'all'});f.context.hooks.replaceData();f.clickNew();assert.equal(f.context.hooks.state().open,false);assert.equal(f.calls.length,0);});
test('stale building version refuses before opening or resetting a form',()=>{const f=fixture({filter:'all'});assert.equal(f.context.hooks.createStale(0),false);assert.equal(f.context.hooks.state().open,false);assert.equal(f.calls.length,0);});
