import {test} from 'node:test';
import assert from 'node:assert/strict';
import {planningUI} from '../../resources/js/trajectory/planning.js';
import {accountingComponentsHtml} from '../../resources/js/trajectory/accounting-report.js';
import {profitLossHtml,profitLossSeries,profitMoney} from '../../resources/js/trajectory/profit-loss.js';
const esc=value=>String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
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
test('P&L renders reported profit and operating expense without adding bank support or deriving missing profit',()=>{
 const report={authoritative:true,complete:true,source:'QuickBooks',note:'Reported source',basis:'accrual',currency:'USD',income_cents:1000000,cogs_cents:400000,gross_profit_cents:600000,expenses_cents:300000,other_expenses_cents:10000,other_income_cents:20000,total_costs_cents:710000,net_cents:310000,revenue_ids:[],transaction_expense_lines:[],unreviewed_count:0};
 const data={space:{id:7,name:'Fictional report business',kind:'business'},business:{profit_loss:report},range:{start:'2028-01-01',end:'2028-01-31'},workspace_settings:{}};
 const ui=planningUI({getData:()=>data,esc,money:precise,precise,panel:(title,body)=>body,btn:()=>''});
 const html=ui.profit();
 assert.match(html,/Net profit/);
 assert.match(html,/\$3,100\.00/);
 assert.match(html,/Operating expenses/);
 assert.match(html,/\$3,000\.00/);
 assert.match(html,/\$6,000\.00/);
 assert.match(html,/Accounting profit is not available bank cash/);
 assert.doesNotMatch(html,/\$7,000\.00|Revenue less expenses/);
 report.net_cents=null;report.total_costs_cents=null;report.complete=false;
 const partial=ui.profit();
 assert.match(partial,/Unavailable/);
 assert.doesNotMatch(partial,/\$7,000\.00|\$3,100\.00|\$7,100\.00/);
});
test('historical series retain missing periods as gaps and preserve source negative profit',()=>{
 const missing={label:'Missing month',income_cents:null,cogs_cents:null,expenses_cents:null,net_cents:null};
 const report={history:{monthly:[{label:'Reported',income_cents:10000,cogs_cents:4000,expenses_cents:7000,net_cents:-1000},missing],quarterly:[missing]}};
 assert.deepEqual(profitLossSeries(report).datasets.find(s=>s.key==='net_cents').data,[-1000,null]);
 assert.deepEqual(profitLossSeries(report,'quarterly').datasets.find(s=>s.key==='income_cents').data,[null]);
 assert.equal(profitMoney(null,'USD'),'Unavailable');
 assert.equal(profitMoney(0,'USD'),'$0.00');
 assert.equal(profitMoney(-1234,'USD'),'-$12.34');
 assert.match(profitMoney(1234,null),/currency unreported/);
});
test('comparison charts use only available source figures and retain zero credits and missing points',()=>{
 const report={history:{monthly:[
  {label:'Source zero',net_cents:0,comparison:{previous_year:{available:true,metrics:{net_cents:0,cogs_cents:-125}}}},
  {label:'Absent report',net_cents:null,comparison:{previous_year:{available:false,metrics:{net_cents:99999,cogs_cents:99999}}}},
  {label:'Missing figure',net_cents:-500,comparison:{previous_year:{available:true,metrics:{net_cents:null,cogs_cents:null}}}},
 ]}};
 const series=profitLossSeries(report,'monthly','previous_year');
 assert.deepEqual(series.datasets.find(s=>s.comparison&&s.key==='net_cents').data,[0,null,null]);
 assert.deepEqual(series.datasets.find(s=>s.comparison&&s.key==='cogs_cents').data,[-125,null,null]);
 assert.deepEqual(series.datasets.find(s=>!s.comparison&&s.key==='net_cents').data,[0,null,-500]);
 assert.equal(profitLossSeries(report,'monthly','previous_period').datasets.some(s=>s.comparison),false);
});
test('imported account and insight text stays escaped and source periods render as dates',()=>{
 const injected='<img src=x onerror="alert(1)">';
 const report={authoritative:true,complete:false,currency:'USD',basis:'accrual',period:{start:'2026-11-01',end:'2026-11-16'},income_cents:0,net_cents:-100,
  statement:{sections:[{key:'income',label:'Income',total_cents:0,lines:[{label:injected,amount_cents:0,key:'parent',children:[{key:'"><svg onload="alert(2)">',label:injected,amount_cents:-100}]}]}]},
  insights:[{kind:'fact',title:injected,detail:injected,figures:{period:{start:'2026-11-01',end:'2026-11-16'},net_cents:-100}}],coverage:{notes:[injected]},
 };
 const html=profitLossHtml({report,space:{name:injected},esc,btn:(label,action,attributes='')=>`<button data-action="${esc(action)}" ${attributes}>${esc(label)}</button>`});
 assert.doesNotMatch(html,/<img|<svg onload|\[object Object\]/);
 assert.match(html,/&lt;img src=x onerror=&quot;alert\(1\)&quot;&gt;/);
 assert.match(html,/data-key="&quot;&gt;&lt;svg onload=&quot;alert\(2\)&quot;&gt;"/);
 assert.match(html,/2026-11-01 – 2026-11-16/);
 assert.match(html,/\$0\.00/);assert.match(html,/-\$1\.00/);
});
