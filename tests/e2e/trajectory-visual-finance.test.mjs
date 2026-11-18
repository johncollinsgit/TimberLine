import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createVisualFinance, financeBudgetRows, financeColor, financeSelectionRows, sortFinanceEntries} from '../../resources/js/trajectory/visual-finance.js';

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
  const ui = createVisualFinance({root: {querySelector: () => null}, getContext: () => ctx, api, endpoint: (path, id = ctx.space_id) => `/spaces/${id}${path}`, esc, precise, chart: () => {}, onBeforeChange: () => changes.push('remember'), onChange: () => changes.push('render'), onChanged: async () => changes.push('reload'), onNavigate: () => {}, onReviewTransaction: () => {}, onEditBudget: () => {}, onAddDefinition: () => {}, notice: x => notices.push(x)});
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
test('default month requests exact scope and home puts budget remaining before charts', async () => {
  const {ui, calls} = fixture(); await ui.refresh();
  assert.match(calls[0].path, /analysis\?year=2028&grain=month&from=2028-02-01&through=2028-02-15&basis=spending/);
  assert.match(calls[1].path, /budget-envelopes\?month=2028-02/);
  const html = ui.render('overview');
  assert.ok(html.indexOf('What’s left in your budgets') < html.indexOf('Money coming in'));
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
test('analysis failures show retry and never substitute old or zero financial data', async () => {
  const {ui} = fixture(async path => {if (path.includes('analysis')) throw Error('No access to this program'); return {rows: []};});
  await ui.refresh();const html = ui.render('analysis'); assert.match(html, /No access to this program/); assert.match(html, /Try again/); assert.ok(!html.includes('$0.00'));
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
  const {ui, calls} = fixture(); await ui.refresh();await ui.handleClick({dataset: {action: 'vf-envelope', index: '0'}});
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
