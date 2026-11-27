import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createTransactionReview,normalizedReviewRow,reviewRowKey,saveTransactionCategory,transactionIdentityHtml} from '../../resources/js/trajectory/transaction-review.js';
const esc=value=>String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const source={authorized_source:true,source_space_id:2,source_space_name:'Fictional Studio',account_id:9,account_name:'Operating account (…1527)',account_kind:'cash',card_last4:null,card_type:null,card_evidence_status:'not_recorded',paid_by_label:'Household purchase paid by Fictional Studio'};
const row={id:41,source_transaction_id:41,source_space_id:7,version:3,merchant:'Fictional Book Store',date:'2028-02-12',amount_cents:-12000,category:'uncategorized',flow:'expense',reviewed:false,pending:false,editable:true,face_punched:true,bullshit_spending:false};
function fixture({preview,save,canRefine}={}){
  const context={space_id:7,account_id:null,view:'transactions'},calls=[],changes=[],notices=[];let renders=0;
  const api=async(path,method='GET',body)=>{calls.push({path,method,body});if(path.endsWith('classification-suggestions'))return preview?preview(path,method,body):{space_id:7,readonly:true,rows:[{key:'41:3:0',version:3,category:'education',category_label:'School',confidence:'high',reason:'Three reviewed book purchases on this account used School.',has_conflicts:false,editable:true}]};if(save)return save(path,method,body);return{version:4};};
  const ui=createTransactionReview({getContext:()=>context,canRefine,api,endpoint:(path,id=7)=>`/spaces/${id}${path}`,esc,precise:String,onRender:()=>renders++,onChanged:async()=>changes.push('reload'),onChangeCategory:r=>changes.push({change:r.id}),onSaved:(r,category,isCurrent)=>saveTransactionCategory({row:r,category,targetSpace:context.space_id,api,endpoint:(path,id)=>`/spaces/${id}${path}`,isCurrent}),notice:text=>notices.push(text)});
  const click=async(action,key='41:3:0')=>ui.handleClick(action,{dataset:{reviewKey:key},disabled:false,isConnected:true});
  return{ui,context,calls,changes,notices,click,get renders(){return renders;}};
}
test('high confidence is a proposal only, and decline leaves the source unchanged',async()=>{
  const f=fixture();f.ui.synchronize([row]);await flush();
  assert.equal(f.calls.length,1);assert.match(f.ui.render(row),/Suggested: School/);assert.match(f.ui.render(row),/Confirm School/);
  assert.equal(row.category,'uncategorized');assert.equal(row.reviewed,false);
  await f.click('category-review-decline');f.ui.synchronize([row]);await flush();
  assert.equal(f.calls.length,1);assert.match(f.ui.render(row),/category is unchanged/);
});
test('Change opens a choice without a mutation and explicit save preserves source flags and flow',async()=>{
  const f=fixture();f.ui.synchronize([row]);await flush();await f.click('category-review-change');
  assert.deepEqual(f.changes,[{change:41}]);assert.equal(f.calls.length,1);
  await f.ui.saveChoice(row,'education');
  assert.deepEqual(f.calls.at(-1),{path:'/spaces/7/transactions/41',method:'PATCH',body:{version:3,category:'education',flow:'expense',face_punched:true,bullshit_spending:false,learn:false}});
  assert.match(f.ui.renderUndo(),/Undo/);
});
test('Confirm invokes only one native save and Undo uses the resulting source version',async()=>{
  const f=fixture();f.ui.synchronize([row]);await flush();await f.click('category-review-confirm');
  assert.equal(f.calls.filter(call=>call.method==='PATCH').length,1);assert.deepEqual(f.changes,['reload']);
  await f.click('category-review-undo');
  assert.deepEqual(f.calls.at(-1),{path:'/spaces/7/transactions/41/undo',method:'POST',body:{version:4}});assert.equal(f.ui.renderUndo(),'');
});
test('late suggestions and old controls cannot cross financial contexts',async()=>{
  let resolve;const waiting=new Promise(done=>resolve=done),f=fixture({preview:()=>waiting});
  f.ui.synchronize([row]);f.context.space_id=8;f.ui.synchronize([]);
  resolve({space_id:7,readonly:true,rows:[{key:'41:3:0',version:3,category:'education',editable:true}]});await flush();
  assert.equal(f.ui.render(row),'');await f.click('category-review-confirm');assert.equal(f.calls.length,1);
});
test('wrong-space and omitted proposal responses never trigger a repeated or automatic write',async()=>{
  const wrong=fixture({preview:()=>({space_id:9,readonly:true,rows:[]})});wrong.ui.synchronize([row]);await flush();assert.match(wrong.ui.render(row),/another financial view/);
  const missing=fixture({preview:()=>({space_id:7,readonly:true,rows:[]})});missing.ui.synchronize([row]);await flush();missing.ui.synchronize([row]);await flush();
  assert.equal(missing.calls.length,1);assert.match(missing.ui.render(row),/Review this financial purpose/);assert.doesNotMatch(missing.ui.render(row),/data-action="category-review-confirm"/);
});
test('pending promotions require a fresh version and confidence never changes pending evidence',async()=>{
  const f=fixture();const pending={...row,pending:true};f.ui.synchronize([pending]);await flush();assert.match(f.ui.render(pending),/Pending purchase/);
  const posted={...row,version:4,pending:false};f.ui.synchronize([posted]);await flush();await f.click('category-review-confirm','41:3:0');
  assert.equal(f.calls.filter(call=>call.method==='PATCH').length,0);assert.equal(posted.version,4);
});
test('bank account masks are shown only as account details and physical cards need verified evidence',()=>{
  const html=transactionIdentityHtml({...row,source_identity:source},{esc});
  assert.match(html,/Account: Operating account/);assert.match(html,/1527/);assert.doesNotMatch(html,/Physical card not recorded|evidence needs review/);assert.doesNotMatch(html,/card ••••1527/);
  const verified=transactionIdentityHtml({...row,source_identity:{...source,card_last4:'7851',card_type:'debit',card_label:'Fictional staff card',card_evidence_status:'verified'}},{esc});
  assert.match(verified,/Debit card ••••7851/);
  const hidden=transactionIdentityHtml({...row,source_identity:{...source,authorized_source:false,paid_by_label:'Household purchase paid from another space',card_last4:'7851',card_evidence_status:'verified'}},{esc});
  assert.doesNotMatch(hidden,/1527|7851|Fictional Studio|Operating account/);
});
test('one native child allocation normalizes into its actual economic category',()=>{
  const split={...row,source_identity:source,editable:false,allocation_details:[{id:19,category:'food',subcategory_record_id:22,program_record_id:null}]};
  const normalized=normalizedReviewRow(split,[{id:22,key:'groceries'}]);
  assert.equal(normalized.category,'groceries');assert.equal(normalized.attribution_detail_id,19);assert.equal(normalized.attribution_editable,true);assert.equal(normalized.source_space_id,7);
});
test('confirming one attributed child preserves every other economic line and its signed amount',async()=>{
  const calls=[],lines=[{detail_id:11,space_id:7,amount_cents:-7000,category:'shopping',subcategory_record_id:null,program_record_id:31,settlement_status:'none',settlement_note:null},{detail_id:12,space_id:2,amount_cents:-5000,category:'materials',subcategory_record_id:null,program_record_id:null,settlement_status:'reimbursed',settlement_note:'Owner confirmed'}];
  const api=async(path,method='GET',body)=>{calls.push({path,method,body});if(method==='PUT')return{version:4,event_id:99};if(path.endsWith('definitions'))return{category_definitions:[{id:20,key:'food',active:true},{id:21,key:'groceries',parent_record_id:20,active:true}]};return{version:3,lines};};
  const result=await saveTransactionCategory({row:{...row,attribution_detail_id:11},category:'groceries',targetSpace:7,api,endpoint:(path,id)=>`/spaces/${id}${path}`});
  const put=calls.find(call=>call.method==='PUT');assert.equal(put.body.version,3);assert.equal(put.body.lines[0].category,'food');assert.equal(put.body.lines[0].subcategory_record_id,21);assert.equal(put.body.lines[0].program_record_id,31);
  assert.deepEqual(put.body.lines[1],Object.fromEntries(Object.entries(lines[1]).filter(([key])=>key!=='detail_id')));assert.equal(put.body.lines.reduce((sum,line)=>sum+line.amount_cents,0),-12000);
  assert.deepEqual(result,{kind:'attribution',id:41,space_id:7,version:4,event_id:99});
});
test('a changed source version or intervening context change prevents the attribution write',async()=>{
  for(const mode of ['version','context']){
    let current=true;const calls=[],api=async(path,method='GET',body)=>{calls.push({path,method,body});if(path.endsWith('definitions')){current=false;return{category_definitions:[]};}return{version:mode==='version'?4:3,lines:[{detail_id:11,space_id:7,amount_cents:-12000,category:'shopping'}]};};
    await assert.rejects(()=>saveTransactionCategory({row:{...row,attribution_detail_id:11},category:'education',targetSpace:7,api,endpoint:(path,id)=>`/spaces/${id}${path}`,isCurrent:()=>current}),/changed/);
    assert.equal(calls.filter(call=>call.method==='PUT').length,0);
  }
});


test('a reviewed native parent can request a refinement while a reviewed leaf stays unchanged',async()=>{
 const parent={...row,category:'shopping',reviewed:true};const f=fixture({canRefine:row=>row.category==='shopping',preview:()=>({space_id:7,readonly:true,rows:[{key:'41:3:0',version:3,category:'household_items',category_label:'Household items',confidence:'low',reason:'Review this subcategory.',editable:true}]})});
 f.ui.synchronize([parent]);await flush();assert.match(f.ui.render(parent),/Suggested: Household items/);assert.equal(f.calls.filter(call=>call.method==='PATCH').length,0);assert.equal(parent.category,'shopping');assert.equal(parent.reviewed,true);
 const leaf=fixture({canRefine:()=>false});leaf.ui.synchronize([{...parent,category:'household_items'}]);await flush();assert.equal(leaf.calls.length,0);
});

test('legacy bank rows distinguish a saved Christmas purpose from raw Shopping and identify genuine splits', () => {
  const shopping = {...row, category:'shopping', allocation_details:[{id:21,category:'christmas',subcategory_record_id:null}]};
  assert.equal(normalizedReviewRow(shopping,[{id:null,key:'other'}]).category,'christmas');
  shopping.allocation_details.push({id:22,category:'food',subcategory_record_id:31});
  const split = normalizedReviewRow(shopping,[{id:31,key:'groceries'}]);
  assert.deepEqual(split.split_categories,['christmas','groceries']);
  assert.equal(split.attribution_detail_id,undefined);
});

test('the requested detail ID is authoritative and cannot select another line or space', async () => {
  const description={version:3,lines:[{detail_id:11,space_id:7,category:'shopping'}, {detail_id:12,space_id:2,category:'groceries'}]};
  for (const detail_id of [12, 99]) {
    const calls = [];
    const api=async(path,method='GET')=>{calls.push(method);return description;};
    await assert.rejects(()=>saveTransactionCategory({row:{...row,detail_id},category:'christmas',targetSpace:7,api,endpoint:path=>path}),/current allocation detail/);
    assert.deepEqual(calls,['GET']);
  }
});
