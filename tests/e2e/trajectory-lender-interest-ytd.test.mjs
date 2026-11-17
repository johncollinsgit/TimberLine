import {test} from 'node:test';
import assert from 'node:assert/strict';
import {lenderInterestYtdHtml} from '../../resources/js/trajectory/lender-interest-ytd.js';
import {esc} from './trajectory-money-flow-fixture.mjs';
const source={name:'Fictional <lender>',source:'Calendar-year table <reviewed>',source_sha256:'a'.repeat(64),observed_on:'2028-07-25'};
const report={available:true,cutoff:'2028-07-25',default_year:2028,note:'Calendar-year cumulative lender reports, separate from selected-period charges, cash interest paid and future estimates.',years:[{year:2028,reported_accounts:2,owned_accounts:3,currency:'USD',reported_subset_cents:6912,different_cutoffs:true,rows:[{account:'Fictional A',currency:'USD',interest_charged_cents:1234,reported_through:'2028-06-20',sources:[source]},{account:'Fictional B',currency:'USD',interest_charged_cents:5678,reported_through:'2028-06-30',sources:[]},{account:'Fictional C',currency:'USD',interest_charged_cents:null,reported_through:null,issue:'No usable reviewed lender total at this cutoff.',sources:[]}]},{year:2027,reported_accounts:1,owned_accounts:3,currency:'USD',reported_subset_cents:0,rows:[{account:'Fictional explicit zero',currency:'USD',interest_charged_cents:0,reported_through:'2027-12-20',sources:[]}]}]};
const render=(r=report,selectedYear)=>lenderInterestYtdHtml(r,{esc,selectedYear});
test('annual source view shows cutoff currency coverage and escaped evidence without claiming a full period or cash cost',()=>{
 const html=render();for(const text of ['$12.34','$56.78','$69.12','2 of 3','different statement cutoffs','not a complete business or household interest total','2028-06-20','Fictional &lt;lender&gt;','Calendar-year table &lt;reviewed&gt;'])assert.ok(html.includes(text),text);
 assert.ok(!html.includes('<reviewed>'));assert.ok(!html.includes(source.source_sha256));assert.ok(html.includes('Unavailable'));assert.ok(html.includes('future estimates'));assert.ok(html.includes('tr-lender-ytd-year'));
});
test('calendar-year selection keeps true zero separate from missing or mixed-currency subtotals',()=>{
 const prior=render(report,2027);assert.ok(prior.includes('$0.00'));assert.ok(!prior.includes('$69.12'));assert.ok(prior.includes('2027 cumulative interest'));
 const mixed=structuredClone(report);mixed.years[0].different_currencies=true;mixed.years[0].reported_subset_cents=null;mixed.years[0].rows[1].currency='EUR';
 const html=render(mixed);assert.ok(html.includes('€56.78'));assert.ok(html.includes('Different currencies; no subtotal'));assert.ok(!html.includes('$69.12'));
});
test('missing sources future-only and stale year selections never fabricate a financial total',()=>{
 const missing=structuredClone(report);missing.years[0].reported_accounts=0;missing.years[0].reported_subset_cents=null;missing.years[0].rows=missing.years[0].rows.map(row=>({...row,interest_charged_cents:null,sources:[]}));
 assert.ok(!render(missing).includes('$0.00'));assert.ok(render(report,1900).includes('2028 cumulative interest'));
 const future=render({...report,available:false});assert.ok(future.includes('period is in the future'));assert.ok(!future.includes('$69.12'));assert.equal(render(null),'');
});
