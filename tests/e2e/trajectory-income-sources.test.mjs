import {test} from 'node:test';
import assert from 'node:assert/strict';
import {incomeSourceSlices,incomeReceiptsCaption,incomeSourcesHtml} from '../../resources/js/trajectory/income-sources.js';
import {esc,precise,btn} from './trajectory-money-flow-fixture.mjs';
const income={earned_income_cents:93500,gross_earned_income_cents:100000,customer_refunds_cents:6500,sources:[
  {key:'sales',name:'Fictional sales <script>',detail:'Gross processor receipts',amount_cents:100000,counts_as_income:true,count:1,ids:[1]},
  {key:'customer-refunds-sales-reversals',name:'Customer refunds / sales reversals',detail:'Not overhead',amount_cents:-6500,counts_as_income:true,count:1,ids:[2]},
  {key:'loan-draws',name:'Borrowing',detail:'Not earned',amount_cents:200000,counts_as_income:false,count:1,ids:[3]},
  {key:'transfers',name:'Transfers',detail:'Internal',amount_cents:30000,counts_as_income:false,count:1,ids:[4]},
]};
test('gross receipt charts exclude negative customer returns and show their net income separately',()=>{
  assert.deepEqual(incomeSourceSlices(income,'cash').map(row=>row.ids),[[1],[3]]);
  assert.deepEqual(incomeSourceSlices(income,'income').map(row=>row.ids),[[1]]);
  const caption=incomeReceiptsCaption(income,'income',precise);
  assert.match(caption,/Gross income receipts before \$65.00 in customer refunds; net recorded income \$935.00/);
  assert.doesNotMatch(incomeReceiptsCaption(income,'cash',precise),/net recorded income/);
});
test('source table keeps signed refunds evidence and explicit gross minus refunds net instead of overhead',()=>{
  const html=incomeSourcesHtml({income,panel:(name,body)=>`<section><h2>${name}</h2>${body}</section>`,money:precise,precise,esc,btn,empty:esc});
  assert.match(html,/\$1,000.00 income receipts − \$65.00 customer refunds = \$935.00 net recorded income/);
  assert.match(html,/-\$65.00/);
  assert.match(html,/data-key="customer-refunds-sales-reversals"/);
  assert.match(html,/Fictional sales &lt;script&gt;/);
  assert.doesNotMatch(html,/<script>/);
  const onlyRefund=incomeSourcesHtml({income:{...income,earned_income_cents:-6500,gross_earned_income_cents:0,sources:[income.sources[1]]},panel:(_,body)=>body,money:precise,precise,esc,btn,empty:esc});
  assert.match(onlyRefund,/\$0.00 income receipts − \$65.00 customer refunds = -\$65.00 net recorded income/);
});
