import {test} from 'node:test';
import assert from 'node:assert/strict';
import {decisionSupportHtml} from '../../resources/js/trajectory/decision-support.js';

const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const precise=value=>value===null?'Unavailable':`USD ${(value/100).toFixed(2)}`;
const render=(support,selectedMonth)=>decisionSupportHtml({support,selectedMonth,esc,precise,date:value=>value||'Unknown date',btn:(label,action,attrs='')=>`<button data-action="${action}" ${attrs}>${esc(label)}</button>`});
const month={month:'2026-11',label:'November 2026',start:'2026-11-01',end:'2026-11-30',is_current:true,needed_cents:100000,received_cents:60000,spent_cents:45000,gap_cents:40000,metals_proceeds_cents:15000,after_metals_gap_cents:25000,coverage_complete:false};
const fixture={month,months:[month],ongoing:{monthly_needed_cents:95000,basis:'Current cost plan',coverage_complete:false,breakdown:[{label:'Confirmed bills',amount_cents:70000,confidence:'Confirmed'}]},businesses:[{name:'Example company',expected_distribution_cents:null,status:'Pending confirmation'}]};

test('monthly decisions preserve backend gaps and separate one-time metals from received income',()=>{
  const html=render(fixture);
  assert.match(html,/Average income needed each month/);
  assert.match(html,/USD 950\.00/);
  assert.match(html,/Income received<\/h3><strong>USD 600\.00/);
  assert.match(html,/Still to cover with income<\/h3><strong>USD 400\.00/);
  assert.match(html,/Gap after recorded metals cash<\/span><strong>USD 250\.00/);
  assert.match(html,/never becomes recurring income/);
  assert.match(html,/Partial evidence/);
  assert.doesNotMatch(html,/USD 750\.00/); // Never combines income with metals.
});

test('missing evidence stays unavailable while a verified zero remains zero',()=>{
  const current={...month,received_cents:null,spent_cents:0,gap_cents:null,metals_proceeds_cents:null,after_metals_gap_cents:null};
  const html=render({...fixture,month:current,months:[current]});
  assert.match(html,/Income received<\/h3><strong>Unavailable/);
  assert.match(html,/Spent so far<\/h3><strong>USD 0\.00/);
  assert.match(html,/Still to cover with income<\/h3><strong>Unavailable/);
  assert.match(html,/Pending confirmation/);
});

test('month selection uses server rows without changing the ongoing monthly rate',()=>{
  const previous={...month,month:'2026-10',label:'October 2026',is_current:false,received_cents:20000,coverage_complete:true};
  const html=render({...fixture,months:[previous,month]},'2026-10');
  assert.match(html,/<h3>October 2026<\/h3>/);
  assert.match(html,/Income received<\/h3><strong>USD 200\.00/);
  assert.match(html,/USD 950\.00/);
  assert.match(html,/value="2026-10" selected/);
  assert.doesNotMatch(html,/13 months|next 12 months/);
});

test('future estimates have a planned label and never become income received',()=>{
  const future={...month,month:'2026-12',label:'December 2026',is_current:false,is_future:true,received_cents:null,spent_cents:null,expected_income_cents:75000};
  const html=render({...fixture,month:future,months:[future]});
  assert.match(html,/Planned income<\/h3><strong>USD 750\.00/);
  assert.match(html,/Forward estimate, not money received/);
  assert.match(html,/Future plan/);
  assert.match(html,/Spent so far<\/h3><strong>Unavailable/);
});

test('all source labels are escaped and malformed money is not coerced into evidence',()=>{
  const html=render({...fixture,month:{...month,label:'<script>bad</script>',needed_cents:'100000'},upcoming_bills:[{name:'<b>Example</b>',due_on:'2026-11-09',amount_cents:12300,confidence:'Needs confirmation'}],notes:['<img src=x>']});
  assert.doesNotMatch(html,/<script>|<b>Example|<img/);
  assert.match(html,/&lt;script&gt;/);
  assert.match(html,/Needed for this month<\/h3><strong>Unavailable/);
  assert.equal(render(null),'');
});
