import {test} from 'node:test';
import assert from 'node:assert/strict';
import {budgetMappingPayload,budgetMappingHtml,budgetPreviewHtml} from '../../resources/js/trajectory/budget-import.js';

const columns=['Item','Group','Planned','Purpose','Month','Stable ID'];
const values=extra=>new Map(Object.entries({map_name:'Item',map_category:'Group',map_amount:'Planned',amount_units:'dollars',planned_amounts:'on',default_purpose:'spending',default_effective_on:'2026-11-01',...extra}));
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const precise=value=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(value/100);

test('mapped budgets require explicit columns units and planned-amount confirmation',()=>{
  assert.throws(()=>budgetMappingPayload(new Map(),columns),/Choose the name/);
  assert.throws(()=>budgetMappingPayload(values({planned_amounts:''}),columns),/Confirm/);
  assert.throws(()=>budgetMappingPayload(values({amount_units:''}),columns),/Choose dollars/);
  assert.throws(()=>budgetMappingPayload(values({map_amount:'Not a source column'}),columns),/Choose columns/);
  const payload=budgetMappingPayload(values({map_source_id:'Stable ID'}),columns);
  assert.deepEqual(payload.column_mapping,{name:'Item',category:'Group',amount:'Planned',source_id:'Stable ID'});
  assert.equal(payload.budget_defaults.amount_basis,'planned');
  assert.equal(payload.budget_defaults.reviewed,false);
  assert.equal(payload.budget_defaults.amount_units,'dollars');
  assert.equal(Object.hasOwn(payload,'rows'),false);
});

test('purpose and effective dates are chosen rather than inferred from the current month',()=>{
  assert.throws(()=>budgetMappingPayload(values({default_purpose:''}),columns),/purpose/);
  assert.throws(()=>budgetMappingPayload(values({default_effective_on:''}),columns),/effective date/);
  const payload=budgetMappingPayload(values({default_purpose:'',default_effective_on:'',map_purpose:'Purpose',map_effective_on:'Month',amount_units:'cents'}),columns);
  assert.equal(payload.column_mapping.purpose,'Purpose');
  assert.equal(payload.column_mapping.effective_on,'Month');
  assert.equal(Object.hasOwn(payload.budget_defaults,'effective_on'),false);
});

test('mapping view starts without guessed columns or a reviewed attestation',()=>{
  const fields=[];
  const html=budgetMappingHtml({preview:{columns,sample:[{Item:'<script>Example</script>',Planned:'125.50'}]},esc,field:(name,label,type,value,choices)=>{fields.push({name,type,value,choices});return `<label>${esc(label)}</label>`;}});
  assert.equal(fields.filter(field=>field.name.startsWith('map_')).every(field=>field.value===''),true);
  assert.equal(fields.find(field=>field.name==='planned_amounts').value,false);
  assert.equal(fields.find(field=>field.name==='default_reviewed').value,false);
  assert.match(html,/&lt;script&gt;/);
  assert.doesNotMatch(html,/<script>/);
});

test('final preview preserves exact cents source IDs purposes dates and explicit category choices',()=>{
  const preview={preview:[{name:'Example target',monthly_cents:105,purpose:'debt_payment',effective_on:'2026-10-01',category:'Source group',_source_category:'Source group',reviewed:false,source_id:'example-stable-id',source:'Example budget'}],warnings:['Generated IDs only replay this same file.']};
  const html=budgetPreviewHtml({preview,categoryMap:{'Source group':'transport'},esc,precise});
  assert.match(html,/\$1\.05/);
  assert.match(html,/debt_payment/);
  assert.match(html,/2026-10-01/);
  assert.match(html,/transport/);
  assert.match(html,/From Source group/);
  assert.match(html,/example-stable-id/);
  assert.match(html,/Needs review/);
  assert.match(html,/creates no transactions/);
});

test('source labels and prototype-like category names never become markup or implicit mappings',()=>{
  const preview={preview:[{name:'<b>Example</b>',monthly_cents:0,purpose:'spending',effective_on:'2026-11-01',category:'__proto__',_source_category:'__proto__',reviewed:true,source_id:'<img src=x>',source:'Example'}]};
  const html=budgetPreviewHtml({preview,esc,precise});
  assert.match(html,/__proto__/);
  assert.match(html,/&lt;b&gt;/);
  assert.match(html,/&lt;img/);
  assert.doesNotMatch(html,/<b>|<img|\[object Object\]/);
});
