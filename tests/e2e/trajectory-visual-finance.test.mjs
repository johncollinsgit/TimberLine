import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createVisualFinance, financeBudgetRows, financeBudgetPresentation, financeColor, financeSelectionRows, sortFinanceEntries} from '../../resources/js/trajectory/visual-finance.js';

const esc = x => String(x ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
const precise = x => Number.isSafeInteger(x) ? `$${(x / 100).toFixed(2)}` : 'Unavailable';
const entries = [
  {id: 1, entry_key: 'line:1', date: '2028-02-01', merchant: 'Fictional Market', category: 'groceries', parent_category: 'food', amount_cents: -12500, version: 2, flow: 'expense', direction: 'out', editable: true, face_punched: false, bullshit_spending: false},
  {id: 2, entry_key: 'line:2', date: '2028-02-02', merchant: 'Fictional Cafe', category: 'dining', parent_category: 'food', amount_cents: -3000, version: 1, flow: 'expense', direction: 'out', editable: true},
  {id: 1, entry_key: 'line:3', date: '2028-02-01', merchant: 'Fictional Market', category: 'office', amount_cents: -1000, version: 2, flow: 'expense', direction: 'out', editable: true},
  {id: 3, entry_key: 'refund:3', date: '2028-02-03', merchant: 'Fictional Cafe refund', category: 'dining', parent_category: 'food', amount_cents: 500, version: 1, flow: 'refund', direction: 'out', editable: true},
];
const report = {
  range: {from: '2028-02-01', through: '2028-02-15'}, summary: {inflow_cents: 40000, spending_cents: 15000},
  inflows: [{key: 'company:1', label: 'Fictional company', amount_cents: 40000, ids: [10]}],
  categories: [{key: 'food', label: 'Food', amount_cents: 15000, ids: [1, 2, 3], children: [{key: 'groceries', label: 'Groceries', amount_cents: 12500, ids: [1]}, {key: 'dining', label: 'Dining', amount_cents: 2500, ids: [2, 3]}]}],
  entries, periods: [{label: 'Feb', from: '2028-02-01', through: '2028-02-15', inflow_cents: 40000, spending_cents: 15000, comparison: {from: '2027-02-01', through: '2027-02-15', spending_cents: null}}],
  category_options: ['food', 'groceries', 'dining', 'office'], category_definitions: [{key: 'food', label: 'Food'}, {key: 'groceries', label: 'Groceries', parent_key: 'food'}, {key: 'dining', label: 'Dining', parent_key: 'food'}], coverage: {complete: false},
};
function fixture(apiOverride = null) {
  const calls = [], changes = [], notices = [];
  const ctx = {space_id: 7, scope: 'personal', account_id: null, from: '2028-02-01', through: '2028-02-15'};
  const budgets = {rows: [{key: 'food', category: 'food', label: 'Food', target_cents: 20000, spent_cents: 15000, pending_cents: 2000, remaining_cents: 3000}], summary: {remaining_cents: 3000}};
  const api = async (path, method = 'GET', body) => {calls.push({path, method, body}); if (apiOverride) return apiOverride(path, method, body); return path.includes('budget-envelopes') ? budgets : structuredClone(report);};
  const ui = createVisualFinance({root: {querySelector: () => null}, getContext: () => ctx, api, endpoint: (path, id = ctx.space_id) => `/spaces/${id}${path}`, esc, precise, chart: () => {}, onBeforeChange: () => changes.push('remember'), onChange: () => changes.push('render'), onChanged: async () => changes.push('reload'), onNavigate: () => {}, onReviewTransaction: () => {}, onEditBudget: () => {}, onOpenBudget: row => changes.push({budget: row.key ?? row.category}), onAddDefinition: () => {}, notice: x => notices.push(x)});
  return {ui, calls, ctx, changes, notices};
}

test('biggest-first sorting keeps cents, refunds, stable source keys and does not mutate evidence', () => {
  const before = structuredClone(entries);
  assert.deepEqual(sortFinanceEntries(entries).map(e => e.entry_key), ['line:1', 'line:2', 'line:3', 'refund:3']);
  assert.deepEqual(sortFinanceEntries(entries, 'newest', 'cafe').map(e => e.id), [3, 2]);
  assert.deepEqual(entries, before);
});
test('category and child drilldown cannot leak another attributed line from the same bank transaction', () => {
  assert.deepEqual(financeSelectionRows(report, {direction: 'out', category: 'food'}).entries.map(r => r.entry_key), ['line:1', 'line:2', 'refund:3']);
  assert.deepEqual(financeSelectionRows(report, {direction: 'out', category: 'food', subcategory: 'groceries'}).entries.map(r => r.entry_key), ['line:1']);
  const scoped = {...report, categories: [{...report.categories[0], entry_keys: ['line:1']} ]};
  assert.deepEqual(financeSelectionRows(scoped, {direction: 'out', category: 'food'}).entries.map(r => r.entry_key), ['line:1']);
});
test('incoming refund receipts and economic refunds remain separate drilldown populations', () => {
  const refund = {...entries[3], direction: 'in', entry_key: 'cash:3'};
  const r = {...report, entries: [...entries, refund], inflows: [{key: 'refunds', ids: [3]}]};
  assert.deepEqual(financeSelectionRows(r, {direction: 'in', category: 'refunds'}).entries.map(e => e.entry_key), ['cash:3']);
  assert.deepEqual(financeSelectionRows(r, {direction: 'out', category: 'food', subcategory: 'dining'}).entries.map(e => e.entry_key), ['line:2', 'refund:3']);
  const debt = {id: 12, entry_key: 'cash:12', direction: 'out', category: 'uncategorized', analysis_category: 'debt_cash', amount_cents: -5000};
  assert.deepEqual(financeSelectionRows({entries: [debt], categories: [{key: 'debt_cash', ids: [12]}]}, {direction: 'out', category: 'debt_cash'}).entries, [debt]);
});
test('unknown budget allowance is not zero; real zero and overspending are retained', () => {
  const rows = financeBudgetRows({rows: [{category: 'a', target_cents: null, spent_cents: 100, remaining_cents: null}, {category: 'b', target_cents: 0, remaining_cents: -100}, {category: 'c', target_cents: 100, remaining_cents: 0}]});
  assert.equal(rows[0].remaining_cents, null); assert.equal(rows[1].remaining_cents, -100); assert.equal(rows[2].remaining_cents, 0);
  assert.equal(financeColor('groceries'), financeColor('groceries'));
});
test('default month requests exact scope and home puts incoming/spending charts before budget cards', async () => {
  const {ui, calls, ctx} = fixture(); await ui.refresh();
  assert.match(calls[0].path, /analysis\?year=2028&grain=month&from=2028-02-01&through=2028-02-15&basis=spending/);
  assert.match(calls[1].path, /budget-envelopes\?month=2028-02/);
  for(const scope of ['personal','business']){
    ctx.scope=scope;const home=ui.render('overview'),budgets=home.indexOf('class="vf-budget-preview"');
    assert.ok(budgets>=0);
    for(const marker of ['class="vf-stat-row"','id="vf-income-chart"','id="vf-spending-chart"'])assert.ok(home.indexOf(marker)>=0&&home.indexOf(marker)<budgets,`${scope}: ${marker} precedes budget cards`);
    assert.equal((home.match(/class="vf-budget-preview"/g)||[]).length,1);
  }
  const html = ui.render('overview');
  assert.ok(html.includes('Purchases when made')); assert.ok(!html.includes('funding-gap'));
});
test('period → category → child → inline ledger remembers navigation and largest sort', async () => {
  const {ui, changes} = fixture(); await ui.refresh();
  await ui.handleClick({dataset: {action: 'vf-period', periodIndex: '0'}});
  await ui.handleClick({dataset: {action: 'vf-category', direction: 'out', key: 'food'}});
  assert.match(ui.render('analysis'), /Subcategory amounts/);
  await ui.handleClick({dataset: {action: 'vf-subcategory', key: 'groceries'}});
  const html = ui.render('analysis'); assert.match(html, /data-vf-category="line:1"/); assert.ok(!html.includes('data-vf-category="line:3"'));
  assert.equal(ui.capture().ledgerSort, 'largest'); assert.equal(changes.filter(c => c === 'remember').length, 3);
  const saved = ui.capture(); await ui.handleClick({dataset: {action: 'vf-level', level: 'period'}}); ui.restore(saved);
  assert.equal(ui.capture().subcategory, 'groceries');
});
test('purpose → merchant → ledger keeps exact split keys, native Ask category and both Back levels', async () => {
  const nested = structuredClone(report), food = nested.categories[0], groceries = food.children[0];
  groceries.group_kind = 'subcategory';groceries.category_key = 'groceries';
  groceries.children = [{key:'merchant:groceries:fictional',category_key:'groceries',group_kind:'merchant',label:'Fictional Market',amount_cents:12500,ids:[1],entry_keys:['line:1'],children:[]}];
  food.children.push({key:'merchant:food:direct',category_key:'food',group_kind:'merchant',label:'Direct Food Shop',amount_cents:400,ids:[8],entry_keys:['direct:8'],children:[]});
  nested.entries.push({id:8,entry_key:'direct:8',category:'food',merchant:'Direct Food Shop',date:'2028-02-04',amount_cents:-400,direction:'out',version:1});
  const {ui,calls}=fixture(async path=>path.includes('budget-envelopes')?{rows:[]}:structuredClone(nested));await ui.refresh();
  await ui.handleClick({dataset:{action:'vf-category',direction:'out',key:'food'}});
  const parentState=ui.capture();await ui.handleClick({dataset:{action:'vf-subcategory',key:'groceries'}});
  assert.equal(ui.capture().ledger,false);assert.match(ui.render('analysis'),/Merchant amounts/);assert.doesNotMatch(ui.render('analysis'),/data-vf-category=/);
  const purposeState=ui.capture();await ui.handleClick({dataset:{action:'vf-subcategory',key:'merchant:groceries:fictional'}});
  assert.equal(ui.capture().subcategory,'groceries');assert.equal(ui.capture().merchantGroup,'merchant:groceries:fictional');
  assert.deepEqual(financeSelectionRows(ui.getReport(),ui.capture()).entries.map(row=>row.entry_key),['line:1']);
  assert.match(ui.render('analysis'),/data-vf-category="line:1"/);assert.doesNotMatch(ui.render('analysis'),/data-vf-category="line:3"/);
  assert.equal(ui.getAssistantContext().subcategory,'groceries');assert.deepEqual(ui.getAssistantContext().transaction_ids,[1]);
  assert.doesNotMatch(JSON.stringify(ui.getAssistantContext()),/merchant:/);assert.ok(calls.every(call=>call.method==='GET'));
  ui.restore(purposeState);assert.match(ui.render('analysis'),/Merchant amounts/);assert.equal(ui.capture().merchantGroup,null);
  ui.restore(parentState);await ui.handleClick({dataset:{action:'vf-subcategory',key:'merchant:food:direct'}});
  assert.equal(ui.capture().subcategory,null);assert.deepEqual(financeSelectionRows(ui.getReport(),ui.capture()).entries.map(row=>row.entry_key),['direct:8']);
  assert.equal(ui.getAssistantContext().subcategory,null);assert.equal(ui.getAssistantContext().category,'food');
  await ui.handleClick({dataset:{action:'vf-level',level:'category'}});assert.equal(ui.capture().merchantGroup,null);assert.match(ui.render('analysis'),/Subcategory and merchant amounts/);
  ui.restore({...purposeState,merchantGroup:'merchant:groceries:removed',ledger:true});assert.deepEqual(financeSelectionRows(ui.getReport(),ui.capture()).entries,[]);
  assert.match(ui.render('analysis'),/This category changed/);
  await ui.handleInput({dataset:{vfInput:'basis'},value:'cash'});assert.equal(ui.capture().merchantGroup,null);
});
test('analysis failures show retry and never substitute old or zero financial data', async () => {
  const {ui} = fixture(async path => {if (path.includes('analysis')) throw Error('No access to this program'); return {rows: []};});
  await ui.refresh();const html = ui.render('analysis'); assert.match(html, /No access to this program/); assert.match(html, /Try again/); assert.ok(!html.includes('$0.00'));
});
test('an unsplit tax payment shows recorded costs separately while missing history stays unavailable', async () => {
  const partial=structuredClone(report);
  partial.summary.spending_cents=null;partial.summary.recorded_spending_cents=15000;
  partial.coverage.spending={complete:false,unresolved_gross_cents:62411,review_rows:[{id:42,date:'2028-02-05',merchant:'Fictional <tax> agency',bank_amount_cents:-62411}]};
  const {ui}=fixture(async path=>path.includes('budget-envelopes')?{rows:[]}:structuredClone(partial));await ui.refresh();
  const html=ui.render('overview');
  assert.match(html,/Recorded costs<\/span><strong>\$150\.00/);
  assert.match(html,/\$624\.11 in tax payments is separate/);
  assert.match(html,/Fictional &lt;tax> agency/);
  assert.match(html,/1 tax payment to review/);
  assert.equal(ui.getReport().summary.spending_cents,null);
  partial.summary.recorded_spending_cents=null;await ui.refresh();
  assert.match(ui.render('overview'),/Purchases &amp; costs<\/span><strong>Unavailable/);
  assert.doesNotMatch(ui.render('overview'),/Recorded costs · tax split pending/);
});
test('inline whole-source category change preserves purpose, flags and exact version; no merchant learning', async () => {
  const {ui, calls} = fixture(); await ui.refresh();
  const target = {dataset: {vfCategory: 'line:1'}, value: 'office', disabled: false, isConnected: true};
  await ui.handleInput(target);
  const write = calls.find(c => c.method === 'PATCH'); assert.deepEqual(write.body, {version: 2, category: 'office', flow: 'expense', face_punched: false, bullshit_spending: false, learn: false}); assert.equal(target.disabled, false);
});
test('editing one attributed category preserves other lines and uses the source version atomically', async () => {
  const attributed = {...report, entries: [{...entries[0], attribution_detail_id: 41, attribution_editable: true, source_space_id: 7, source_transaction_id: 1}]};
  const originalLines = [{detail_id: 41, space_id: 7, amount_cents: -12500, category: 'groceries', program_record_id: 15, subcategory_record_id: null, settlement_status: 'none', settlement_note: null}, {detail_id: 42, space_id: 8, amount_cents: -1000, category: 'office', program_record_id: null, subcategory_record_id: null, settlement_status: 'reimbursed', settlement_note: 'Confirmed by owner'}];
  const {ui, calls} = fixture(async (url, method) => {
    if (url.includes('analysis?')) return structuredClone(attributed);
    if (url.includes('budget-envelopes')) return {rows: []};
    if (url.endsWith('/attribution/definitions')) return {category_definitions: []};
    if (method === 'GET') return {version: 2, lines: originalLines};
    return {version: 3};
  });
  await ui.refresh(); await ui.handleInput({dataset: {vfCategory: 'line:1'}, value: 'office', disabled: false, isConnected: true});
  const put = calls.find(c => c.method === 'PUT');
  assert.equal(put.body.version, 2);assert.equal(put.body.lines[0].category, 'office');assert.equal(put.body.lines[0].program_record_id, 15);
  assert.deepEqual(put.body.lines[1], Object.fromEntries(Object.entries(originalLines[1]).filter(([k]) => k !== 'detail_id')));
  assert.equal(put.body.lines.reduce((n, row) => n + row.amount_cents, 0), -13500);assert.ok(!calls.some(c => c.method === 'PATCH'));
});
test('sort and page choices do not insert extra Back steps between drilldown levels', async () => {
  const {ui, changes} = fixture(); await ui.refresh();
  await ui.handleInput({dataset: {vfInput: 'sort'}, value: 'newest'});await ui.handleClick({dataset: {action: 'vf-page', page: '2'}});
  assert.equal(changes.filter(c => c === 'remember').length, 0);
});
test('selecting a purchase gives Ask the exact source version, while clearing returns category scope', async () => {
  const {ui} = fixture();await ui.refresh();await ui.handleClick({dataset: {action: 'vf-category', direction: 'out', key: 'food'}});
  await ui.handleClick({dataset: {action: 'vf-select-row', entry: 'line:1'}});
  let context=ui.getAssistantContext();assert.equal(context.focused_transaction,true);assert.deepEqual(context.transaction_ids,[1]);assert.deepEqual(context.transaction_versions,{'1':2});
  await ui.handleClick({dataset: {action: 'vf-clear-selection'}});context=ui.getAssistantContext();assert.equal(context.focused_transaction,false);assert.deepEqual(context.transaction_ids,[1,2,3]);
});
test('budget category drilldown clamps its actual period to the workspace date', async () => {
  const {ui, calls, changes} = fixture(); await ui.refresh();const reads=calls.length;
  await ui.handleClick({dataset: {action: 'vf-envelope', index: '0'}});
  assert.equal(calls.length,reads,'Opening a budget uses its loaded row, not a ledger request');assert.deepEqual(changes.at(-1),{budget:'food'});
  await ui.openBudgetEntries({category:'food'});
  const request = calls.filter(c => c.path.includes('/analysis?')).at(-1);
  assert.match(request.path, /from=2028-02-01&through=2028-02-15/);
});
test('space/account context changes clear private selection and request the new authorized scope', async () => {
  const {ui, ctx, calls} = fixture();await ui.refresh();await ui.handleClick({dataset: {action: 'vf-category', direction: 'out', key: 'food'}});
  ctx.space_id = 8; ctx.scope = 'business'; ctx.account_id = 99; await ui.refresh();
  assert.equal(ui.capture().category, null);assert.match(calls.at(-2).path, /^\/spaces\/8\/analysis/);assert.match(calls.at(-2).path, /account_id=99/);
});

test('one authorized snapshot is reusable only for its exact server inputs and data revision', async () => {
  const {ui,ctx}=fixture();assert.equal(ui.hasCurrentSnapshot(),false);await ui.refresh();
  const original=ui.capture();assert.equal(ui.hasCurrentSnapshot(),true);
  for(const changed of [{category:'food',subcategory:'groceries',ledger:true},{query:'market',ledgerSort:'newest',page:2,focusedEntry:'line:1'}]){ui.restore({...original,...changed});assert.equal(ui.hasCurrentSnapshot(),true);}
  for(const changed of [{period:{from:'2028-01-01',through:'2028-01-31'}},{year:2027},{comparisonYear:2026},{grain:'week'},{basis:'cash'},{program:13},{budgetMonth:'2028-01'}]){ui.restore({...original,...changed});assert.equal(ui.hasCurrentSnapshot(),false);}
  ui.restore(original);ctx.revision=2;assert.equal(ui.hasCurrentSnapshot(),false);await ui.refresh();assert.equal(ui.hasCurrentSnapshot(),true);
  ui.restore({...original,period:{from:'2028-01-01',through:'2028-01-31'}});await ui.refresh();
  ui.restore(original);assert.equal(ui.hasCurrentSnapshot(),false,'Another period is not retained in a second cache');
});

test('an early visual cohort associates with its real dashboard revision only for the same exact projection',()=>{
  const {ui,ctx}=fixture();ctx.revision=0;
  ui.adoptSnapshot({...structuredClone(report),year:2028,grain:'month',scope:{basis:'spending'}},{month:'2028-02',rows:[]});
  assert.equal(ui.hasCurrentSnapshot(),true);ctx.revision=1;assert.equal(ui.hasCurrentSnapshot(),false);
  assert.equal(ui.associateRevision(0),true);assert.equal(ui.hasCurrentSnapshot(),true);
  ui.restore({...ui.capture(),period:{from:'2028-01-01',through:'2028-01-31'}});ctx.revision=2;
  assert.equal(ui.associateRevision(1),false);assert.equal(ui.hasCurrentSnapshot(),false);
});

test('background figures retain exact drilldown and do not apply after an intervening edit or failed read', async () => {
  let rejectBackground=false,approve=true,readReport=structuredClone(report);
  const {ui}=fixture(async path=>{if(rejectBackground)throw Error('Offline');return path.includes('budget-envelopes')?{rows:[]}:structuredClone(readReport);});
  await ui.refresh();await ui.handleClick({dataset:{action:'vf-category',direction:'out',key:'food'}});
  await ui.handleClick({dataset:{action:'vf-subcategory',key:'groceries'}});
  const selection=ui.capture();readReport.summary.spending_cents=90000;
  approve=false;assert.equal(await ui.refresh({background:true,isCurrent:()=>approve}),false);
  assert.equal(ui.getReport().summary.spending_cents,15000);assert.deepEqual(ui.capture(),selection);
  approve=true;assert.equal(await ui.refresh({background:true,isCurrent:()=>approve}),true);
  assert.equal(ui.getReport().summary.spending_cents,90000);assert.deepEqual(ui.capture(),selection);
  rejectBackground=true;assert.equal(await ui.refresh({background:true}),false);
  assert.equal(ui.getReport().summary.spending_cents,90000);assert.equal(ui.isBusy(),false);
  assert.doesNotMatch(ui.render('analysis'),/Offline/);
  await ui.refresh();assert.match(ui.render('analysis'),/Offline/);
  rejectBackground=false;assert.equal(await ui.refresh({background:true}),true);
  assert.doesNotMatch(ui.render('analysis'),/Offline/);
});


test('budget presentation explains each missing allowance without substituting zero or hiding a known plan', () => {
  const row = {target_cents: null, planned_cents: 48000, remaining_cents: null, status: 'draft', reasons: ['draft', 'partial_period', 'tax_split_pending'], partial_period: true};
  const draft = financeBudgetPresentation(row);
  assert.equal(draft.value, 48000);assert.equal(draft.caption, 'Draft allowance');assert.equal(draft.action, 'Confirm budget…');
  assert.deepEqual(draft.messages, ['Draft · needs confirmation', 'Dated plan · not a full-month allowance', 'Tax split pending']);
  for (const [reason, message] of [['cash_not_recorded', 'Cash spending is not fully recorded'], ['category_review_pending', 'Spending categories still need review'], ['needs_opening_balance', 'Reviewed rollover opening needed'], ['needs_prior_month_close', 'Previous month must close before rollover is known'], ['overlapping_targets', 'Parent and child allowances overlap · review targets']]) {
    const presentation = financeBudgetPresentation({target_cents: 35000, planned_cents: 35000, remaining_cents: null, reasons: [reason]});
    assert.equal(presentation.value, 35000);assert.ok(presentation.messages.includes(message));
  }
  const unset = financeBudgetPresentation({status: 'not_budgeted', spent_cents: 700, remaining_cents: null});
  assert.equal(unset.text, 'Set budget');assert.equal(unset.value, null);
  const child = financeBudgetPresentation({uses_parent_budget: '0|food', parent_budget_label: 'Food', remaining_cents: null});
  assert.equal(child.text, 'Uses parent budget');assert.equal(child.caption, 'Food');assert.equal(child.action, null);
  const future = financeBudgetPresentation({target_cents: 35000, remaining_cents: null, reasons: ['cash_not_recorded', 'missing_history']}, {future: true});
  assert.equal(future.value, 35000);assert.equal(future.caption, 'Approved monthly plan');assert.deepEqual(future.messages, ['Plan for a future month']);
});

test('budget cards show dated drafts and recorded-only grocery costs while preserving unknown remaining amounts', async () => {
  const budgets = {future: false, rows: [
    {key: '0|groceries', category: 'groceries', label: 'Groceries', target_cents: 140000, planned_cents: 140000, remaining_cents: null, spent_cents: 64000, reasons: ['cash_not_recorded'], source_notes: ['Cash <spending> is not yet known.']},
    {key: '0|fuel', category: 'fuel', label: 'Fuel', target_cents: 35000, planned_cents: 35000, remaining_cents: null, spent_cents: 21000, category_review_pending: true, reasons: ['category_review_pending']},
    {key: '0|materials', category: 'materials', label: 'Materials', target_cents: null, planned_cents: 45000, remaining_cents: null, spent_cents: 10000, status: 'draft', reasons: ['draft', 'partial_period'], partial_period: true, plan_period: {from: '2028-02-17', through: '2028-02-29'}},
    {key: '0|education', category: 'education', label: 'Education', target_cents: null, remaining_cents: null, spent_cents: 5000, status: 'not_budgeted'},
    {key: '0|travel', category: 'travel', label: 'Travel', target_cents: 50000, planned_cents: 50000, remaining_cents: null, spent_cents: null, reasons: ['missing_history']},
  ]};
  const {ui} = fixture(async path => path.includes('budget-envelopes') ? budgets : structuredClone(report));await ui.refresh();
  const html = ui.render('budget');
  assert.doesNotMatch(html, /Unavailable|\$0\.00/);assert.match(html, /\$1400\.00/);assert.match(html, /\$640\.00 recorded spending/);
  assert.match(html, /Cash spending is not fully recorded/);assert.match(html, /\$210\.00 recorded \/ estimated spending/);
  assert.match(html, /\$450\.00/);assert.match(html, /2028-02-17 – 2028-02-29/);assert.match(html, /Confirm budget…/);
  assert.match(html, /Set budget…/);assert.match(html, /Spending history not available/);assert.match(html, /Cash &lt;spending> is not yet known/);
  assert.equal(budgets.rows[0].remaining_cents, null);
});

test('owner reported monthly cash is visible separately and is not presented as a bank transaction', async () => {
  const budgets = {rows: [{key: '0|groceries', category: 'groceries', label: 'Groceries', target_cents: 140000, planned_cents: 140000,
    bank_spent_cents: 64000, reported_cash_spent_cents: 50000, reported_cash_as_of: '2028-02-15', spent_cents: 114000, remaining_cents: 26000}]};
  const {ui} = fixture(async path => path.includes('budget-envelopes') ? budgets : structuredClone(report));await ui.refresh();
  const html = ui.render('budget');
  assert.match(html, /\$640\.00 bank-recorded spending \+ \$500\.00 reported cash through 2028-02-15/);
  assert.match(html, /\$260\.00/);assert.match(html, /left after bank and reported cash spending/);
  assert.match(html, /Cash report is budget only; transaction charts exclude it/);
  assert.equal(ui.getReport().summary.spending_cents, 15000);
});


test('fund cards show actual saved progress and contribution rather than a monthly spending cap',async()=>{
 const {ui}=fixture(async path=>path.includes('budget-envelopes')?{month:'2028-02',rows:[{key:'0|travel',category:'travel',label:'Travel fund',mode:'fund',target_cents:null,planned_cents:30000,spent_cents:0,bank_spent_cents:0,remaining_cents:null,fund:{saved_cents:0,target_cents:100000,monthly_cents:16667}}],summary:{remaining_cents:null}}:structuredClone(report));await ui.refresh();const html=ui.render('budget');
 assert.match(html,/\$0\.00<\/strong>/);assert.match(html,/saved toward \$1000\.00 fund target/);assert.match(html,/\$166\.67 planned per month/);assert.doesNotMatch(html,/\$300\.00|left after recorded spending/);
});


test('Today with known history and no new records explains the gap without inventing zero actuals',async()=>{
 const today={...structuredClone(report),range:{from:'2028-02-15',through:'2028-02-15'},scope:{calendar_range:'day'},summary:{inflow_cents:null,spending_cents:null,recorded_spending_cents:null},entries:[],inflows:[],categories:[],coverage:{complete:false,history_available:true,latest_recorded_on:'2028-02-14',selected_range_has_evidence:false,selected_range_covered:false}};
 const {ui}=fixture(async path=>path.includes('budget-envelopes')?{rows:[]}:today);await ui.refresh();const html=ui.render('overview');
 assert.match(html,/No receipts recorded today/);assert.match(html,/No purchases recorded today/);assert.match(html,/Latest recorded activity: 2028-02-14/);assert.match(html,/Bank feeds may arrive later/);assert.doesNotMatch(html,/\$0\.00/);assert.equal(ui.getReport().summary.spending_cents,null);
});
