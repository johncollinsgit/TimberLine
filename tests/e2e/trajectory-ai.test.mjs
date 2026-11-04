import test from 'node:test';
import assert from 'node:assert/strict';
import {planningUI} from '../../resources/js/trajectory/planning.js';

function fixture(api,reviewBudget=()=>{}) {
  const data={space:{id:7,name:'Example household'},range:{start:'2026-02-01',end:'2026-02-12'},planning:{},records:[]};
  const nodes={'#tr-bud-question':{value:'Explain cash'},'#tr-range':{value:'quarter'},'#tr-bud-thread':{innerHTML:''}};
  const original=globalThis.document;
  globalThis.document={querySelector:key=>nodes[key]};
  const esc=value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
  const ui=planningUI({getData:()=>data,spaces:[],esc,money:String,precise:String,panel:(heading,body)=>heading+body,btn:(label,action,attrs='')=>`<button data-action="${action}" ${attrs}>${esc(label)}</button>`,api,endpoint:path=>'/example'+path,reviewBudget});
  return {data,nodes,ui,restore:()=>{globalThis.document=original;}};
}

test('Ask Everbranch preserves its UUID through network and uncertain-outcome retries',async()=>{
  const requests=[];
  const f=fixture(async(url,method,payload)=>{requests.push(payload);if(requests.length===1)throw Error('network interruption');return{reply:'Calculated fallback',mode:'Bud Core',links:[],proposals:[],ai:{status:requests.length===2?'outcome_unconfirmed':'request_not_replayed'}};});
  try{
    const target={dataset:{},disabled:false};
    await assert.rejects(f.ui.handle('plan-bud',target),/network interruption/);
    await f.ui.handle('plan-bud',target);
    f.nodes['#tr-bud-question'].value='Explain cash';
    await f.ui.handle('plan-bud',target);
    assert.equal(requests.length,3);
    assert.match(requests[0].request_id,/^[0-9a-f-]{36}$/);
    assert.equal(requests[0].request_id,requests[1].request_id);
    assert.equal(requests[0].request_id,requests[2].request_id);
    assert.equal(requests[0].use_ai,true);
    assert.equal(requests[0].range,'quarter');
    assert.equal(target.disabled,false);
    assert.match(f.nodes['#tr-bud-thread'].innerHTML,/Bud Core/);
  }finally{f.restore();}
});

test('budget proposals open explicit review with their original record version and never save from chat',async()=>{
  let request,reviewed;
  const proposal={type:'budget',mode:'update',space_id:7,record_id:11,expected_version:3,name:'Example plan',requires_review:true,data:{category:'groceries',monthly_cents:32500}};
  const f=fixture(async(url,method,payload)=>{request=payload;return{reply:'Draft only',mode:'Bud Core',links:[],proposals:[proposal]};},draft=>{reviewed=draft;});
  try{
    f.data.records=[{id:11,kind:'budget',version:3}];
    await f.ui.handle('plan-bud',{dataset:{},disabled:false});
    assert.equal(reviewed,undefined);
    const action={dataset:{draft:request.request_id+':0'}};
    await f.ui.handle('plan-budget-review',action);
    assert.equal(reviewed.version,3);
    assert.equal(reviewed.data.monthly_cents,32500);
    f.data.records[0].version=4;
    await assert.rejects(f.ui.handle('plan-budget-review',action),/budget changed/);
    f.data.space.id=8;
    await assert.rejects(f.ui.handle('plan-budget-review',action),/different view/);
  }finally{f.restore();}
});

test('AI notices and partial source coverage are visible and escaped',async()=>{
  const f=fixture(async()=>({reply:'<script>unsafe</script>',mode:'Ask Everbranch AI',notice:'<private note>',links:[],ai:{status:'completed',source_coverage:{complete:false}}}));
  try{
    await f.ui.handle('plan-bud',{dataset:{},disabled:false});
    const html=f.nodes['#tr-bud-thread'].innerHTML;
    assert.match(html,/Ask Everbranch AI/);
    assert.match(html,/AI context is partial/);
    assert.match(html,/&lt;script&gt;/);
    assert.doesNotMatch(html,/<script>/);
    assert.match(f.ui.bud(),/no money moves from this chat/);
  }finally{f.restore();}
});
