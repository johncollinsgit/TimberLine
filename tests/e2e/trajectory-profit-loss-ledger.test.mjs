import test from 'node:test';
import assert from 'node:assert/strict';
import {planningUI} from '../../resources/js/trajectory/planning.js';
import {profitAccountLedgerHtml} from '../../resources/js/trajectory/profit-loss.js';
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fixture=()=>({status:'complete',source:'QuickBooks General Ledger',currency:'USD',basis:'accrual',period:{start:'2028-01-01',end:'2028-01-31'},account:{id:'31',label:'Fictional sales'},statement_amount_cents:50000,activity_cents:50000,difference_cents:0,reconciles:true,rows:[{date:'2028-01-07',transaction_type:'Invoice',document_number:'R-1',name:'Fictional customer',memo:'Sale <account>',amount_cents:60000},{date:'2028-01-08',transaction_type:'Credit memo',memo:'Returned product',amount_cents:-10000}],notes:[]});
test('accounting detail displays source signed entries and reconciliation without bank substitution',()=>{
  const html=profitAccountLedgerHtml(fixture(),{esc});
  for(const value of ['Matches saved statement','$500.00','$0.00','$600.00','-$100.00','Credit memo','Sale &lt;account&gt;'])assert.ok(html.includes(value));
  assert.doesNotMatch(html,/<account>/);
  const unknown=profitAccountLedgerHtml({...fixture(),status:'partial',reconciles:null,activity_cents:null,difference_cents:null,rows:[]},{esc});
  assert.match(unknown,/Reconciliation unavailable/);assert.match(unknown,/No usable accounting transactions/);assert.equal((unknown.match(/Unavailable/g)||[]).length,2);
  const mismatch=profitAccountLedgerHtml({...fixture(),reconciles:false,status:'partial',difference_cents:123},{esc});
  assert.match(mismatch,/Does not match saved statement/);assert.match(mismatch,/\$1\.23/);
});
function harness(api,refreshProfitLoss){
  const original=globalThis.document,container={isConnected:true,innerHTML:'loading',setAttribute(){}};
  const status={textContent:''},modal={open:true},data={space:{id:7},range:{start:'2028-01-01',end:'2028-01-31'},planning:{},business:{profit_loss:{snapshot_id:19,basis:'accrual',requested_basis:'accrual',currency:'USD',period:{start:'2028-01-01',end:'2028-01-31'},statement:{sections:[{lines:[{id:'31',key:'income.sales',label:'Fictional sales',amount_cents:50000,transaction_detail_available:true}]}]}}}};
  const dialogs=[];
  globalThis.document={querySelector:selector=>selector==='#tr-pnl-refresh-status'?status:selector==='#tr-dialog'?modal:selector.startsWith('.tr-pnl-account-request')?container:null};
  const ui=planningUI({getData:()=>data,esc,api,refreshProfitLoss,endpoint:path=>'/trajectory/spaces/7'+path,dialog:(...args)=>dialogs.push(args),btn:(label,action,attrs='')=>`<button data-action="${action}" ${attrs}>${esc(label)}</button>`});
  return{ui,data,container,modal,dialogs,status,restore:()=>{globalThis.document=original;}};
}
test('leaf account loads only its source snapshot/key after click and ignores late responses after context or dialog change',async()=>{
  let resolve;const requests=[],f=harness(url=>{requests.push(url);return new Promise(done=>{resolve=done;});});
  try{
    const pending=f.ui.handle('plan-profit-account',{dataset:{key:'income.sales'}});
    const query=new URL(requests[0],'https://fixture.invalid').searchParams;
    assert.equal(query.get('snapshot_id'),'19');assert.equal(query.get('account_key'),'income.sales');assert.equal(query.has('amount_cents'),false);
    assert.match(f.dialogs[0][1],/Loading accounting transactions/);
    f.data.business.profit_loss.snapshot_id=20;resolve(fixture());await pending;assert.equal(f.container.innerHTML,'loading');
    const closed=f.ui.handle('plan-profit-account',{dataset:{key:'income.sales'}});f.modal.open=false;resolve(fixture());await closed;assert.equal(f.container.innerHTML,'loading');
  }finally{f.restore();}
});
test('supported accounting detail renders and unsupported account never triggers provider request',async()=>{
  let calls=0;const f=harness(async()=>{calls++;return fixture();});
  try{
    await f.ui.handle('plan-profit-account',{dataset:{key:'income.sales'}});assert.equal(calls,1);assert.match(f.container.innerHTML,/Matches saved statement/);
    f.data.business.profit_loss.statement.sections[0].lines[0].transaction_detail_available=false;
    await f.ui.handle('plan-profit-account',{dataset:{key:'income.sales'}});assert.equal(calls,1);assert.match(f.dialogs.at(-1)[1],/Individual accounting transactions are unavailable/);
  }finally{f.restore();}
});

test('explicit report load uses selected server dates and basis with existing snapshot pin, never runs on render',async()=>{
  const requests=[],f=harness(async()=>{throw Error('No implicit request');},async payload=>{requests.push(payload);f.data.business.profit_loss.observed_at='2028-02-01T12:00:00Z';});
  try{
    const report=f.data.business.profit_loss;report.capabilities={report_refresh:true};report.requested_basis='cash';
    report.comparison={previous_year:{period:{start:'2027-01-01',end:'2027-01-31'},available:false}};
    f.ui.profit();assert.equal(requests.length,0);
    const target={dataset:{target:'selected'},textContent:'Refresh report',isConnected:true};
    await f.ui.handle('plan-profit-refresh',target);assert.deepEqual(requests[0],{from:'2028-01-01',through:'2028-01-31',accounting_basis:'cash',snapshot_id:19});assert.equal(target.disabled,false);
    await f.ui.handle('plan-profit-refresh',{...target,dataset:{target:'comparison'}});assert.deepEqual(requests[1],{from:'2027-01-01',through:'2027-01-31',accounting_basis:'cash'});
    report.snapshot_id=null;await f.ui.handle('plan-profit-refresh',target);assert.equal(Object.hasOwn(requests[2],'snapshot_id'),false);
    assert.match(f.status.textContent,/accounting report has been loaded/);
  }finally{f.restore();}
});
