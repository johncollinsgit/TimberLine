import {test} from 'node:test';
import assert from 'node:assert/strict';
import {planningUI} from '../../resources/js/trajectory/planning.js';
import {accountingComponentsHtml} from '../../resources/js/trajectory/accounting-report.js';
const esc=value=>String(value??'').replaceAll('<','&lt;').replaceAll('>','&gt;');
const precise=value=>value===null?'Unavailable':`$${(value/100).toFixed(2)}`;
test('accounting sections are distinct and never derive costs or profit from one expense component',()=>{
 const html=accountingComponentsHtml({report:{cogs_cents:400000,expenses_cents:300000,other_expenses_cents:10000,other_income_cents:20000},esc,precise});
 for(const text of ['Product costs (COGS)','Expenses component','Other expenses','Other income','$4000.00','$3000.00','$100.00','$200.00'])assert.ok(html.includes(text));
 assert.doesNotMatch(html,/\$7000\.00|Revenue less expenses/);
});
test('missing accounting sections stay unavailable and explicit source zero stays zero',()=>{
 const html=accountingComponentsHtml({report:{cogs_cents:null,expenses_cents:0,other_expenses_cents:'100',other_income_cents:NaN},esc,precise});
 assert.equal((html.match(/Unavailable/g)||[]).length,3);
 assert.equal((html.match(/\$0\.00/g)||[]).length,1);
});
test('P&L renders reported profit and total costs separately from the expense component',()=>{
 const report={authoritative:true,complete:true,source:'QuickBooks',note:'Reported source',basis:'Accrual',income_cents:1000000,cogs_cents:400000,expenses_cents:300000,other_expenses_cents:10000,other_income_cents:20000,total_costs_cents:710000,net_cents:310000,revenue_ids:[],transaction_expense_lines:[],unreviewed_count:0};
 const data={business:{profit_loss:report},range:{start:'2028-01-01',end:'2028-01-31'},workspace_settings:{}};
 const ui=planningUI({getData:()=>data,esc,money:precise,precise,panel:(title,body)=>body,btn:()=>''});
 const html=ui.profit();
 assert.match(html,/Reported QuickBooks net income/);
 assert.match(html,/\$3100\.00/);
 assert.match(html,/Total accounting costs/);
 assert.match(html,/\$7100\.00/);
 assert.match(html,/Expenses component/);
 assert.match(html,/\$3000\.00/);
 assert.doesNotMatch(html,/\$7000\.00|Revenue less expenses/);
 report.net_cents=null;report.total_costs_cents=null;report.complete=false;
 const partial=ui.profit();
 assert.match(partial,/Unavailable/);
 assert.doesNotMatch(partial,/\$7000\.00|\$3100\.00|\$7100\.00/);
});
