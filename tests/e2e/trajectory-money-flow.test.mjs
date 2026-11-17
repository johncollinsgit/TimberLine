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
