import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const view=readFileSync(new URL('../../resources/views/enterprise/app.blade.php',import.meta.url),'utf8');
const resources=readFileSync(new URL('../../resources/views/enterprise/resources-script.blade.php',import.meta.url),'utf8');
const building={id:'synthetic-building',title:'Synthetic North <Hall>',version:4};
function formFixture(mode='private',items=[building]) {
  const nodes={};
  const element=id=>nodes[id]??=( {value:'',disabled:false,hidden:false,textContent:'',innerHTML:''} );
  const elements=Object.fromEntries(['title','description','due_on','location_id','location','latitude','longitude','visibility'].map(name=>[name,element(name==='location_id'?'request-building':name)]));
  const form=element('create-form');form.elements=elements;form.reset=()=>Object.values(elements).forEach(input=>input.value='');
  element('create-dialog').showModal=()=>{nodes.modalOpen=true;};
  const result={bodies:[],messages:[]};
  const context=vm.createContext({$:element,data:{buildings:{items,has_more:false,coverage:'Current authorized saved buildings.'}},transport:{mode},area:'synthetic-area',pending:null,createAction:null,draft:null,
    crypto:{randomUUID:()=>String(Math.random())},esc:value=>String(value).replaceAll('<','&lt;').replaceAll('>','&gt;'),
    FormData:class{constructor(form){this.elements=form.elements;}get(name){const input=this.elements[name];return input?.disabled?null:input?.value??null;}},
    command:body=>result.bodies.push(body),message:(...args)=>result.messages.push(args)});
  const start=view.indexOf('  let requestBuildingChoices=[];'),end=view.indexOf("  $('create-workspace').onclick",start);
  assert.ok(start>=0&&end>start,'Actual create handler exists');
  vm.runInContext(view.slice(start,end),context);
  return {context,nodes,elements,result,create:()=>vm.runInContext("create('request.create')",context),submit:()=>nodes['create-form'].onsubmit({preventDefault(){},target:form})};
}

test('native form sends explicit captured ID/version and clears coordinates',()=>{
  const f=formFixture();f.create();
  assert.equal(f.nodes['request-building'].disabled,false);
  assert.match(f.nodes['request-building'].innerHTML,/Synthetic North &lt;Hall&gt;/);
  f.elements.location_id.value=building.id;f.elements.latitude.value='36';f.elements.longitude.value='-81';
  f.nodes['request-building'].onchange();
  assert.equal(f.elements.location.value,building.title);assert.equal(f.elements.location.readOnly,true);
  assert.equal(f.elements.latitude.value,'');assert.equal(f.elements.longitude.value,'');
  f.elements.title.value='Synthetic work';f.submit();
  const body=f.result.bodies[0];assert.equal(body.location_id,building.id);assert.equal(body.location_version,4);
  assert.equal(body.location,building.title);assert.equal('latitude' in body,false);assert.equal('longitude' in body,false);
});

test('background refresh cannot silently adopt an unreviewed building version',()=>{
  const f=formFixture();f.create();f.elements.location_id.value=building.id;f.nodes['request-building'].onchange();
  f.context.data={buildings:{items:[{...building,version:5,title:'Changed after form opened'}]}};
  f.submit();assert.equal(f.result.bodies[0].location_version,4);assert.equal(f.result.bodies[0].location,building.title);
});

test('typed text and cleared selections remain unlinked legacy intake',()=>{
  const f=formFixture();f.create();f.elements.location_id.value=building.id;f.nodes['request-building'].onchange();
  f.elements.location_id.value='';f.nodes['request-building'].onchange();
  assert.equal(f.elements.location.readOnly,false);assert.equal(f.elements.latitude.readOnly,false);
  f.elements.location.value=building.title;f.elements.latitude.value='36.16';f.elements.longitude.value='-81.87';f.submit();
  const body=f.result.bodies[0];assert.equal('location_id' in body,false);assert.equal('location_version' in body,false);
  assert.equal(body.location,building.title);assert.equal(body.latitude,'36.16');
});

test('unavailable or tampered choice does not produce a command; sample stays explicitly text-only',()=>{
  const f=formFixture();f.create();f.elements.location_id.value='foreign-building';f.submit();
  assert.equal(f.result.bodies.length,0);assert.match(f.result.messages[0][0],/unavailable/);
  const sample=formFixture('sample');sample.create();assert.equal(sample.nodes['request-building'].disabled,true);
  assert.match(sample.nodes['request-building-coverage'].textContent,/Fictional sample/);
  sample.elements.location.value=building.title;sample.submit();assert.equal('location_id' in sample.result.bodies[0],false);
  const empty=formFixture('private',[]);empty.create();assert.equal(empty.nodes['request-building'].disabled,true);
});

test('only authorized explicit IDs contribute counts, with real priority ordering and separate text matches',()=>{
  const context=vm.createContext({resourceSelection:building.id,resourceResult:true,resourceItems:[{...building,kind:'location',data:{level:'facility'}}],resourceCatalog:{entries:[]},resourceMapNumber:()=>null,resourceDatum:row=>row.data,
    area:'synthetic-area',transport:{mode:'private'},esc:String,data:{requests:[
      {id:'text',area_id:'synthetic-area',title:'Synthetic legacy',status:'submitted',data:{location:building.title},task:{priority:'urgent'}},
      {id:'low',area_id:'synthetic-area',title:'Synthetic linked low',status:'submitted',data:{location_id:building.id},task:{priority:'low'}},
      {id:'urgent',area_id:'synthetic-area',title:'Synthetic linked urgent',status:'assigned',data:{location_id:building.id},task:{priority:'urgent'}},
      {id:'closed',area_id:'synthetic-area',title:'Synthetic linked closed',status:'completed',data:{location_id:building.id},task:{priority:'urgent'}},
      {id:'foreign',area_id:'foreign-area',title:'Must not appear',status:'submitted',data:{location_id:building.id},task:{priority:'urgent'}}
    ]}});
  const start=resources.indexOf('  const buildingPriority ='),end=resources.indexOf('  function buildingAssignee',start);
  vm.runInContext(resources.slice(start,end),context);
  const tickets=vm.runInContext('buildingTickets()',context);
  assert.deepEqual(Array.from(tickets.filter(item=>item.linked),item=>item.row.id),['urgent','low','closed']);
  assert.deepEqual(Array.from(tickets.filter(item=>!item.linked),item=>item.row.id),['text']);
  assert.match(vm.runInContext('buildingCounts(resourceSelection)',context),/2 open linked · 1 urgent linked/);
  context.data.requests=[];
  assert.match(vm.runInContext('buildingCounts(resourceSelection)',context),/No linked tickets in this result/);
  context.transport.mode='sample';
  assert.match(vm.runInContext('buildingCounts(resourceSelection)',context),/Ticket links not established/);
});
