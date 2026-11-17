import {test} from 'node:test';
import assert from 'node:assert/strict';
import {moneyFlowGraph,moneyFlowCompactGraph,moneyFlowLayout,moneyFlowHtml,moneyFlowTrendSeries} from '../../resources/js/trajectory/money-flow.js';
import {report,esc,precise,btn} from './trajectory-money-flow-fixture.mjs';

function conserved(graph,lastColumn=3){
 for(const node of graph.nodes){
  const incoming=graph.links.filter(link=>link.target===node.id).reduce((n,link)=>n+link.amount_cents,0);
  const outgoing=graph.links.filter(link=>link.source===node.id).reduce((n,link)=>n+link.amount_cents,0);
  if(node.column>0)assert.equal(incoming,node.amount_cents,node.id+' incoming');
  if(node.column<lastColumn)assert.equal(outgoing,node.amount_cents,node.id+' outgoing');
 }
 for(const link of graph.links)assert.ok(Number.isSafeInteger(link.amount_cents)&&link.amount_cents>0);
}
test('every source pool category and merchant layer conserves exact cents including derived differences',()=>{
 const graph=moneyFlowGraph(report.views.spending);conserved(graph);
 assert.equal(graph.total_cents,181600);assert.equal(graph.nodes.find(node=>node.kind==='difference').amount_cents,50000);
 assert.deepEqual(graph.nodes.filter(node=>node.kind==='difference').flatMap(node=>node.ids),[]);
 const gap=moneyFlowGraph({...report.views.spending,inflows:[]});conserved(gap);
 assert.equal(gap.nodes.find(node=>node.id==='funding-gap').amount_cents,131600);
 const layout=moneyFlowLayout(graph);for(const node of layout.nodes)assert.ok(node.height>=44&&node.y>=0);
 for(const link of layout.links)assert.ok(!/NaN|Infinity/.test(link.path));
});
test('phone source-to-category overview conserves every cent and keeps purpose/review bands distinct',()=>{
 const graph=moneyFlowCompactGraph(report.views.cash);conserved(graph,2);
 assert.equal(graph.total_cents,181600);assert.ok(!graph.nodes.some(node=>node.column===3));
 for(const kind of ['income','borrowing','medical','review'])assert.ok(graph.nodes.some(node=>node.column===0&&node.kind===kind));
 const layout=moneyFlowLayout(graph,true);assert.equal(layout.width,360);
 for(const node of layout.nodes)assert.ok(node.x>=0&&node.x+node.width+6<=360&&node.height>=44);
});
test('large diagrams group small merchants without losing cents or hiding Needs review',()=>{
 const outflows=Array.from({length:20},(_,i)=>({key:'cat'+i,label:'Category '+i,kind:'outflow',amount_cents:i+10,ids:[i+100],merchants:[{key:'merchant'+i,label:'Merchant '+i,amount_cents:i+10,ids:[i+100]}]}));
 outflows.push({key:'needs_review',label:'Needs review',kind:'review',amount_cents:1,ids:[999],merchants:[{key:'unknown',label:'Unknown',amount_cents:1,ids:[999]}]});
 const graph=moneyFlowGraph({inflows:[],outflows});conserved(graph);
 assert.equal(graph.outflow_cents,outflows.reduce((n,row)=>n+row.amount_cents,0));
 assert.ok(graph.nodes.some(node=>node.column===2&&node.kind==='review'&&node.ids.includes(999)));
 assert.equal(new Set(graph.nodes.filter(node=>node.column===3).flatMap(node=>node.ids)).size,21);
});
test('empty or unsafe values never produce invented bands or a savings claim',()=>{
 assert.equal(moneyFlowGraph({inflows:[],outflows:[]}).nodes.length,0);
 assert.equal(moneyFlowGraph({inflows:[{key:'a',kind:'income',amount_cents:Number.MAX_SAFE_INTEGER,ids:[]},{key:'b',kind:'income',amount_cents:10,ids:[]}],outflows:[]}).error,'amount_range');
 const html=moneyFlowHtml({report,esc,precise,btn});
 for(const text of ['Recorded inflows above outflows','may be partial','Share of its direction','Known category refunds','Zero-amount records','Needs review','data-ids="[6]"','role="button"','tabindex="0"','&lt;script&gt;'])assert.ok(html.includes(text),text);
 assert.ok(!html.includes('<script>'));assert.ok(!html.includes('Verified savings'));
});
test('monthly multi-year trend preserves true zeros and unknown months rather than filling missing history',()=>{
 const report={history:{years:[{year:2027,months:[{views:{spending:{outflow_cents:0}}},{views:{spending:{outflow_cents:null}}}]},{year:2028,months:[{views:{spending:{outflow_cents:12345}}},{views:{spending:{outflow_cents:undefined}}}]}]}};
 const series=moneyFlowTrendSeries(report);
 assert.deepEqual(series.map(year=>year.values),[[0,null],[12345,null]]);
 assert.deepEqual(series.map(year=>year.year),[2027,2028]);
});
test('custom net-collection labels stay escaped and separate from other income in phone aggregation',()=>{
 const view={...report.views.spending,inflows:[{key:'net',kind:'income',purpose_key:'income:net_collections',purpose_label:'Net <bank> collections',label:'Net <bank> collections · Fictional processor',amount_cents:10000,ids:[90]},{key:'interest',kind:'income',purpose_key:'income:interest',purpose_label:'Interest income',label:'Interest income · Fictional bank',amount_cents:200,ids:[91]},{key:'other',kind:'income',label:'Classified income · Fictional other',amount_cents:300,ids:[92]}]};
 const graph=moneyFlowCompactGraph(view);conserved(graph,2);
 const sources=graph.nodes.filter(node=>node.column===0&&node.kind!=='difference');
 assert.deepEqual(sources.map(node=>node.label),['Net <bank> collections','Interest income','Classified income']);
 assert.deepEqual(sources.map(node=>node.ids),[[90],[91],[92]]);
 const html=moneyFlowHtml({report:{...report,views:{spending:view}},esc,precise,btn});
 assert.ok(html.includes('Net &lt;bank&gt; collections'));assert.ok(!html.includes('<bank>'));
});
test('payroll branches retain separate cents and IDs with a conditional net-distribution explanation',()=>{
 const groups=[['owner_payroll','Owner payroll',12001,101],['studio_payroll','Studio Payroll',23002,102],['event_payroll','Event Payroll',34003,103],['payroll','Payroll',4555,104]].map(([key,label,amount_cents,id])=>({key,label,kind:'outflow',amount_cents,ids:[id],merchants:[{key:'provider',label:'Fictional payroll provider',amount_cents,ids:[id]}]}));
 const view={...report.views.spending,outflows:groups,outflow_cents:73561};
 for(const compact of [false,true]){
  const graph=compact?moneyFlowCompactGraph(view):moneyFlowGraph(view);conserved(graph,compact?2:3);
  const outgoing=graph.nodes.filter(node=>node.column===2&&node.kind!=='difference');
  assert.deepEqual(outgoing.map(row=>row.ids).flat().toSorted(),[101,102,103,104]);
  assert.equal(outgoing.reduce((n,row)=>n+row.amount_cents,0),73561);
 }
 const html=moneyFlowHtml({report:{...report,views:{spending:view}},esc,precise,btn});
 for(const label of ['Owner payroll','Studio Payroll','Event Payroll','Payroll retains taxes and unassigned withdrawals','not total employer cost'])assert.ok(html.includes(label));
 assert.ok(!moneyFlowHtml({report,esc,precise,btn}).includes('tr-flow-payroll-note'));
 const busy={...view,outflows:[...Array.from({length:20},(_,i)=>({key:'cost'+i,label:'Other cost '+i,kind:'outflow',amount_cents:100000+i,ids:[200+i],merchants:[{key:'supplier'+i,label:'Fictional supplier',amount_cents:100000+i,ids:[200+i]}]})),...groups]};
 for(const graph of [moneyFlowGraph(busy),moneyFlowCompactGraph(busy)]){
  for(const row of groups)assert.deepEqual(graph.nodes.find(node=>node.column===2&&node.key===row.key)?.ids,row.ids,'Small payroll categories must remain separate');
 }
});

test('unknown tax splits leave totals unavailable and known component evidence keeps its exact basis on phone and desktop',()=>{
 const view={inflows:[],outflows:[{key:'tax_interest',label:'Tax interest',kind:'outflow',amount_cents:200,ids:[7],economic_category:'interest',merchants:[{key:'agency',label:'Fictional agency',kind:'outflow',amount_cents:200,ids:[7],economic_category:'interest'}]}],excluded:[],refund_categories:[],inflow_cents:null,outflow_cents:null,difference_cents:null,review_inflow_cents:0,review_outflow_cents:0,tax_split_unknown_ids:[8],tax_split_unknown_gross_cents:22222};
 const rendered=moneyFlowHtml({report:{views:{spending:view},coverage:{notes:[]}},esc,precise,btn});
 assert.ok(rendered.includes('Economic totals unavailable'));assert.ok(rendered.includes('not total operating spending'));
 assert.ok(rendered.includes('data-economic-category="interest"'));assert.ok(rendered.includes('data-economic="true"'));
 const credits={...view,inflows:[{key:'interest-credit',label:'Tax interest credits',purpose_key:'tax_cost_credit:interest',purpose_label:'Tax interest credits',kind:'tax_credit',amount_cents:200,ids:[9],economic_category:'interest'},{key:'penalty-credit',label:'Tax penalty credits',purpose_key:'tax_cost_credit:tax_penalties',purpose_label:'Tax penalty credits',kind:'tax_credit',amount_cents:700,ids:[10],economic_category:'tax_penalties'}],outflows:[]};
 const phone=moneyFlowCompactGraph(credits).nodes.filter(node=>node.column===0&&node.kind!=='difference');
 assert.equal(phone.length,2);assert.deepEqual(phone.map(node=>node.economic_category),['interest','tax_penalties']);
});

test('condensed branches retain only the tax components they actually include',()=>{
 const component=(key,amount)=>({key,label:key,kind:'outflow',amount_cents:amount,ids:[77],economic_category:key==='tax_interest'?'interest':key,merchants:[]});
 const preserved=['payroll','owner_payroll','studio_payroll','event_payroll'].map((key,index)=>({key,label:key,kind:'outflow',amount_cents:100-index,ids:[index+1],merchants:[]}));
 const graph=moneyFlowCompactGraph({inflows:[],outflows:[{key:'housing',label:'Housing',kind:'outflow',amount_cents:100000,ids:[20],merchants:[]},component('tax_penalties',70000),component('tax_interest',200),component('tax_fees',100),...preserved]});
 const other=graph.nodes.find(node=>node.key==='other'&&node.column===2);
 assert.equal(other.amount_cents,300);assert.deepEqual(other.economic_categories,['interest','tax_fees']);assert.deepEqual(other.ids,[77]);
});

test('family receipt sources and small company subtypes conserve cents without changing other views or income labels',()=>{
 const groups=[['company','Fictional <company>','support','payroll',12001],['company','Fictional <company>','support','draw',2002],['company','Fictional <company>','support','other',103],['loans','Loans','borrowing','loan',30004],['metals','Metals','asset','metal',40005],['odd_jobs','Odd jobs','income','jobs',5006],['medical','Medical bill shares','medical','medical',6007],['review','Needs review','review','unknown',708],['other-income','Interest income','income','interest',9]];
 const parents=new Map(),types=groups.map(([key,label,kind,type,amount_cents],index)=>{const id=500+index,purpose_key='household:'+key;if(!parents.has(key))parents.set(key,{key,label,purpose_key,purpose_label:label,kind,amount_cents:0,ids:[]});const p=parents.get(key);p.amount_cents+=amount_cents;p.ids.push(id);return {key:type,label:label+' · '+type,kind,amount_cents,ids:[id],preserve:true,merchants:[{key:'sender',label:'Fictional sender',amount_cents,ids:[id]}]};});
 const total=groups.reduce((n,row)=>n+row[4],0),view={receipt_view:true,inflows:[...parents.values()],outflows:types,inflow_cents:total,outflow_cents:total,receipt_count:9,review_inflow_cents:708,source_families:[],notes:['Fictional receipt coverage']};
 for(const [compact,graph] of [[false,moneyFlowGraph(view)],[true,moneyFlowCompactGraph(view)]]){conserved(graph,compact?2:3);assert.equal(graph.total_cents,total);assert.ok(!graph.nodes.some(row=>row.kind==='difference'));for(const type of types)assert.deepEqual(graph.nodes.find(row=>row.column===2&&row.key===type.key).ids,type.ids);}
 const withFamily={...report,family_inflows:view},html=moneyFlowHtml({report:withFamily,mode:'inflows',esc,precise,btn});
 for(const text of ['Recorded receipts','Receipt transactions','Receipts needing review','Receipt types','Received from','Fictional &lt;company&gt;','data-cash="true"','data-inflows="true"','not how it was spent'])assert.ok(html.includes(text),text);
 for(const text of ['Recorded outflows','Outgoing categories','Monthly recorded outflows','Verified savings'])assert.ok(!html.includes(text),text);
 assert.equal(moneyFlowHtml({report:withFamily,mode:'spending',esc,precise,btn}).includes('Fictional &lt;company&gt;'),false);
 assert.ok(!moneyFlowHtml({report,esc,precise,btn}).includes('data-mode="inflows"'));
});
test('receipt trends and evidence use incoming owned-bank cash including zero and unknown months',()=>{
 const point=(amount,ids)=>({views:{cash:{inflow_cents:amount,outflow_cents:999,inflow_ids:ids,outflow_ids:[999],ids:[...ids,999]}}});
 const history={note:'Fictional limited history',current:{from:'2028-01-01',through:'2028-07-20',...point(12345,[1])},prior:{from:'2027-01-01',through:'2027-07-20',...point(null,[])},years:[{year:2028,months:Array.from({length:12},(_,index)=>point(index===0?0:index===1?12345:null,index===1?[1]:[]))}]};
 const view={receipt_view:true,inflows:[],outflows:[],inflow_cents:null,known_inflow_cents:0,receipt_count:0,review_inflow_cents:0,source_families:[{label:'Loans',ids:[]}],notes:[]};
 const report={family_inflows:view,history};assert.deepEqual(moneyFlowTrendSeries(report,'inflows')[0].values,[0,12345,...Array(10).fill(null)]);
 const html=moneyFlowHtml({report,mode:'inflows',esc,precise,btn});assert.ok(html.includes('Monthly recorded receipts'));assert.ok(html.includes('data-ids="[1]"'));assert.ok(!html.includes('data-ids="[999]"'));assert.ok(html.includes('No reviewed receipts for Loans'));
});

test('customer-refund outflows conserve gross movement and stay visible outside purchase refund tables',()=>{
 const customerReturn={key:'customer_refunds',label:'Customer refunds / sales reversals',kind:'customer_refund',preserve:true,amount_cents:6500,ids:[71],merchants:[{key:'processor',label:'Fictional processor',amount_cents:6500,ids:[71]}]};
 const view={...report.views.cash,inflows:[{key:'collections',kind:'income',label:'Net bank sales collections',amount_cents:100000,ids:[70]}],outflows:[customerReturn],inflow_cents:100000,outflow_cents:6500,difference_cents:93500,refund_categories:[]};
 const graph=moneyFlowGraph(view);conserved(graph);
 assert.equal(graph.nodes.find(node=>node.key==='customer_refunds').amount_cents,6500);
 const html=moneyFlowHtml({report:{...report,views:{cash:view}},mode:'cash',esc,precise,btn});
 assert.match(html,/Customer refunds \/ sales reversals/);
 assert.match(html,/data-ids="\[71\]"/);
 assert.match(html,/\$65.00/);
});
