import {openSplitEditor} from './split-editor.js';
import {transactionIdentityHtml, refundMatchLabel, reviewRowKey, saveTransactionCategory} from './transaction-review.js';
/** A single exploration path: period → source/category → purpose → merchant → editable ledger. */
export const financeColors = ['#257c77', '#4c83c3', '#db8845', '#9170c4', '#ce668c', '#6e9e54', '#43a4b0', '#bb9a3d', '#6279a6', '#c16e52', '#829252', '#9e71a1'];
export const financeColor = key => financeColors[[...String(key)].reduce((n, c) => (n * 31 + c.charCodeAt(0)) >>> 0, 0) % financeColors.length];
const number = n => Number.isSafeInteger(n) ? n : null;
const clone = x => JSON.parse(JSON.stringify(x));
const keyOf = r => String(r.entry_key ?? r.detail_id ?? r.id);
export const financeLabel = value => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());

// Only collapse the two full entries represented in this particular ledger view.
// A bank credit can also appear as an incoming source or as separate split details.
export function groupRefundEntries(entries) {
  const groups = new Map();
  const memberships = new Map();
  for (const row of entries) {
    const match = row.refund_match;
    if (match?.status !== 'matched' || match.bank_level || row.attribution_detail_id || row.allocation_details?.length
        || Math.abs(row.amount_cents) !== match.amount_cents) continue;
    const id = row.source_transaction_id ?? row.id;
    const pair = [id, match.counterpart_transaction_id].sort((a, b) => a - b);
    const key = [row.source_space_id ?? row.source_identity?.source_space_id, row.account_id, row.amount_basis, row.direction, ...pair].join(':');
    const members = groups.get(key) ?? [];
    members.push(row);
    groups.set(key, members);
    memberships.set(row, key);
  }
  const seen = new Set();
  const result = [];
  for (const row of entries) {
    const key = memberships.get(row);
    if (seen.has(key)) continue;
    const members = groups.get(key);
    const purchase = members?.find(r => r.refund_match.role === 'purchase');
    const refund = members?.find(r => r.refund_match.role === 'refund');
    if (members?.length === 2 && purchase && refund && purchase.amount_cents === -refund.amount_cents) {
      result.push({key, purchase, refund});
      seen.add(key);
    } else {
      result.push({row});
    }
  }
  return result;
}

// A refund in a spending report can also appear as an incoming bank receipt.
// In the full-period ledger, count the credit once. Its inflow drilldown uses the original receipt.
function periodLedgerEntries(report) {
  const entries = report?.entries ?? [];
  const sourceKey = row => [row.account_id, row.source_transaction_id ?? row.id, row.amount_cents].join(':');
  const refunds = new Set(entries.filter(row => row.direction === 'out' && row.refund_match?.role === 'refund'
    && !row.refund_match.bank_level && !row.attribution_detail_id && row.amount_cents === row.refund_match.amount_cents).map(sourceKey));
  return entries.filter(row => !(row.direction === 'in' && row.amount_basis === 'owned_bank_cash' && refunds.has(sourceKey(row))));
}

export function sortFinanceEntries(entries, sort = 'largest', query = '') {
  const text = query.trim().toLocaleLowerCase();
  const rows = entries.filter(row => !text || [row.merchant, row.category_label, row.category, row.subcategory_label, row.date].some(x => String(x ?? '').toLocaleLowerCase().includes(text)));
  return [...rows].sort((a, b) => {
    let result;
    if (sort === 'oldest') result = a.date.localeCompare(b.date);
    else if (sort === 'newest') result = b.date.localeCompare(a.date);
    else if (sort === 'merchant') result = String(a.merchant ?? '').localeCompare(String(b.merchant ?? ''));
    else if (sort === 'category') result = String(a.category_label ?? a.category).localeCompare(String(b.category_label ?? b.category));
    else if (sort === 'smallest') result = (a.direction === 'out' ? -a.amount_cents : a.amount_cents) - (b.direction === 'out' ? -b.amount_cents : b.amount_cents);
    else result = (b.direction === 'out' ? -b.amount_cents : b.amount_cents) - (a.direction === 'out' ? -a.amount_cents : a.amount_cents);
    return result || a.date.localeCompare(b.date) || keyOf(a).localeCompare(keyOf(b));
  });
}

export function financeSelectionRows(report, state) {
  const groups = state.direction === 'in' ? report?.inflows ?? [] : report?.categories ?? [];
  const parent = groups.find(row => row.key === state.category);
  const subcategory = state.subcategory ? parent?.children?.find(row => row.key === state.subcategory && row.group_kind !== 'merchant') : null;
  const branch = state.subcategory ? subcategory : parent;
  const merchant = state.merchantGroup ? branch?.children?.find(row => row.key === state.merchantGroup && row.group_kind === 'merchant') : null;
  const selected = state.merchantGroup ? merchant : branch;
  if (!selected) return {parent, subcategory, merchant, selected, entries: []};
  const detailKeys = selected.entry_keys ? new Set(selected.entry_keys.map(String)) : null;
  const ids = new Set(selected.ids ?? []);
  const entries = (report.entries ?? []).filter(row => {
    if (state.direction && row.direction !== state.direction) return false;
    if (detailKeys) return detailKeys.has(keyOf(row));
    const category = selected.category_key ?? selected.key;
    const categoryMatch = state.direction === 'in' || row.analysis_category === category || (state.subcategory || merchant
      ? row.category === category || row.subcategory === category
      : row.category === category || row.parent_category === category || row.parent_key === category || selected.children?.some(child => child.key === row.category));
    return ids.has(row.id) && categoryMatch;
  });
  return {parent, subcategory, merchant, selected, entries};
}

export function financeBudgetRows(report) {
  return (report?.rows ?? []).map(row => ({...row,
    target_cents: number(row.target_cents), planned_cents: number(row.planned_cents), spent_cents: number(row.spent_cents), pending_cents: number(row.pending_cents),
    remaining_cents: number(row.remaining_cents), estimated_remaining_cents: number(row.estimated_remaining_cents), label: row.label ?? financeLabel(row.category ?? row.key),
  })).sort((a,b) => {
    const amount = row => row.mode === 'fund' ? number(row.fund?.target_cents) : number(row.target_cents) ?? number(row.planned_cents);
    const left = amount(a), right = amount(b);
    return left === right ? a.label.localeCompare(b.label) : left === null ? 1 : right === null ? -1 : right-left;
  });
}

/** Summarize only spending allowances; savings goals never consume the monthly budget. */
export function financeBudgetTotals(report) {
  const rows = financeBudgetRows(report).filter(row => row.mode !== 'fund' && !row.uses_parent_budget);
  const overlapping = row => row.status === 'overlapping_targets' || (row.reasons ?? []).includes('overlapping_targets');
  const plans = rows.map(row => overlapping(row) ? null : number(row.target_cents) ?? number(row.planned_cents));
  const remaining = rows.map(row => overlapping(row) ? null : number(row.remaining_cents) ?? number(row.estimated_remaining_cents));
  const sumKnown = values => values.some(value => value !== null) ? values.reduce((sum, value) => sum + (value ?? 0), 0) : null;
  return {
    total: number(report?.summary?.target_cents) ?? sumKnown(plans),
    left: report?.future ? null : number(report?.summary?.remaining_cents) ?? sumKnown(remaining),
    partialTotal: number(report?.summary?.target_cents) === null && plans.some(value => value === null),
    partialLeft: number(report?.summary?.remaining_cents) === null && remaining.some(value => value === null),
    estimated: rows.some(row => number(row.target_cents) === null && number(row.planned_cents) !== null || number(row.remaining_cents) === null && number(row.estimated_remaining_cents) !== null),
  };
}

const budgetIconPaths = {
  groceries: '<path d="m3 3 2 2 2 12h11l3-9H6M9 21h.01M18 21h.01M9 3v5M14 3v5"/>',
  food: '<path d="M5 3v7a3 3 0 0 0 6 0V3M8 3v18M20 21V3c-4 3-4 10 0 10"/>',
  fuel: '<path d="M3 21h12M5 21V4h8v17M5 8h8M13 11h3v6a2 2 0 0 0 4 0V8l-3-3"/>',
  home: '<path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/>',
  repairs: '<path d="m14 6 4 4M7 17l-3 3M14 3a6 6 0 0 0-7 7L2 15l7 7 5-5a6 6 0 0 0 7-7l-5 3-5-5Z"/>',
  travel: '<path d="m3 11 7-1 6-7 3 1-3 7 5 3v2l-7-1-4 6H8l2-7-7-1Z"/>',
  gifts: '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8C5 8 5 2 8 3c3 0 4 5 4 5Zm0 0c7 0 7-6 4-5-3 0-4 5-4 5Z"/>',
  health: '<path d="M20 13 12 21 4 13C-2 6 7-1 12 6c5-7 14 0 8 7Z"/>',
  giving: '<path d="m2 16 4-3h5a2 2 0 0 1 0 4H8m4 0 7-4a2 2 0 0 1 3 2l-9 6H7l-5-2M13 8c-6-4 0-8 2-4 2-4 8 0 2 4l-2 2Z"/>',
  education: '<path d="m2 8 10-5 10 5-10 5Zm4 2v7c4 3 8 3 12 0v-7M22 8v8"/>',
  clothes: '<path d="m8 3-6 4 3 5 3-2v11h8V10l3 2 3-5-6-4a4 4 0 0 1-8 0Z"/>',
  utilities: '<path d="m13 2-9 12h7l-1 8 10-12h-7Z"/>',
  payroll: '<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 3a4 4 0 0 1 0 8M22 21v-3a7 7 0 0 0-4-6"/>',
  debt: '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
  shopping: '<path d="M4 7h16l1 14H3ZM8 7V6a4 4 0 0 1 8 0v1"/>',
  default: '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M3 8h18M16 12h5v5h-5Z"/>',
};
function budgetIcon(row) {
  const value = `${row.category ?? ''} ${row.label}`.toLowerCase();
  const key = /grocer/.test(value) ? 'groceries' : /fuel|gasoline|transport/.test(value) ? 'fuel' : /repair|tools|maintenance/.test(value) ? 'repairs'
    : /travel|holiday|vacation/.test(value) ? 'travel' : /gift/.test(value) ? 'gifts' : /health|medical|insurance/.test(value) ? 'health'
    : /giving|donat|church/.test(value) ? 'giving' : /school|education|books/.test(value) ? 'education' : /clothes|clothing/.test(value) ? 'clothes'
    : /utilities|electric|phone|internet/.test(value) ? 'utilities' : /payroll|wages|staff/.test(value) ? 'payroll' : /debt|loan|credit|interest/.test(value) ? 'debt'
    : /dining|food|meal|date night|eating/.test(value) ? 'food' : /home|household|housing|rent/.test(value) ? 'home' : /shopping|supplies|materials/.test(value) ? 'shopping' : 'default';
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${budgetIconPaths[key]}</svg>`;
}

/** Plan amounts and recorded allowance are distinct; a missing amount is never a zero. */
export function financeBudgetPresentation(row, {future = false} = {}) {
  const reasons = new Set(row.reasons ?? []);
  if (row.status && row.status !== 'reviewed_plan') reasons.add(row.status);
  if (row.uses_parent_budget) reasons.add('uses_parent_budget');
  if (future) reasons.add('future_period');
  const messages = {
    draft: 'Estimated plan', partial: 'Dated plan · not a full-month allowance', partial_period: 'Dated plan · not a full-month allowance',
    overlapping_targets: 'Parent and child allowances overlap · review targets', needs_opening_balance: 'Reviewed rollover opening needed',
    needs_prior_month_close: 'Previous month must close before rollover is known', tax_split_pending: 'Tax split pending', unresolved_costs: 'Tax split pending',
    cash_not_recorded: 'Cash spending is not fully recorded', cash_reports_overlap: 'Cash reports overlap · review their scope', category_review_pending: 'Spending categories still need review',
    missing_history: 'Spending history is not available for this month', future_period: 'Plan for a future month',
  };
  let value = null, text = 'Set budget', caption = 'No allowance set';
  const plan = number(row.target_cents) ?? number(row.planned_cents);
  if (row.uses_parent_budget) {text = 'Uses parent budget';caption = row.parent_budget_label ?? 'Shared allowance';}
  else if (number(row.remaining_cents) !== null && !future) {value = Math.abs(row.remaining_cents);caption = row.remaining_cents < 0 ? 'over recorded allowance' : row.reported_cash_as_of ? 'left after bank and reported cash spending' : 'left after recorded spending';}
  else if (number(row.estimated_remaining_cents) !== null && !future) {value = Math.abs(row.estimated_remaining_cents);caption = row.estimated_remaining_cents < 0 ? 'over budget · est.' : 'left · est.';}
  else if (plan !== null) {value = plan;caption = reasons.has('draft') ? 'Draft allowance' : reasons.has('overlapping_targets') ? 'Entered allowance · review overlap' : row.partial_period ? 'Dated allowance plan' : 'Approved monthly plan';}
  else if (reasons.size && !reasons.has('not_budgeted')) {text = 'Review budget';caption = 'Allowance needs review';}
  return {value, text, caption, messages: [...new Set([...reasons].filter(reason => !future || !['cash_not_recorded', 'category_review_pending', 'tax_split_pending', 'unresolved_costs', 'missing_history'].includes(reason)).map(reason => messages[reason]).filter(Boolean))],
    action: row.uses_parent_budget ? null : plan === null ? 'Set budget…' : null};
}

export function financeBudgetCompact(row, {future = false} = {}) {
  const plan = number(row.target_cents) ?? number(row.planned_cents);
  const exact = number(row.remaining_cents);
  const estimate = number(row.estimated_remaining_cents);
  const needsSplit = (row.reasons ?? []).includes('needs_split');
  return {plan, spent: future ? null : number(row.spent_cents), left: future ? null : exact ?? estimate,
    needsSplit, estimated: exact === null && estimate !== null,
    tag: row.mode === 'income_percentage' ? Number.isSafeInteger(row.rate_bps) ? `${row.rate_bps / 100}% of take-home · est.` : 'Review income rule' : row.partial_period ? 'Dated plan' : future ? 'Planned' : needsSplit ? 'Needs split' : row.uses_parent_budget ? 'Shares parent budget' : exact === null && estimate !== null ? 'Est.' : row.default_from ? 'Previous budget' : row.target_status === 'draft' ? 'Est.' : ''};
}

export function createVisualFinance({root, getContext, getSpaces = () => [], api, endpoint, esc, precise, chart, onBeforeChange, onChange, onChanged, onCategorySaved = onChanged, onNavigate, onReviewTransaction, onEditBudget, onAddDefinition, onClearAccount, onAsk, notice, transactionReview = null, homeBills = () => '', onOpenBudget = null}) {
  let state = {version: 1, year: null, comparisonYear: null, grain: 'month', basis: 'spending', period: null, direction: null, category: null, subcategory: null, merchantGroup: null, ledger: false, program: null, expandedRefund: null, budgetAccount: null, ledgerSort: 'largest', query: '', page: 1, budgetMonth: null, focusedEntry: null};
  let report = null, envelopes = null, error = null, loading = false, request = 0, ownerContext = null, loadedProjection = null;
  let retainedLedger = null, visibleLedgerRows = [];
  const categorySaves = new Map();
  const pageSize = 50;
  const stats = (label, value, note, className = '', unknown = null) => `<div class="vf-stat ${className}"><span>${esc(label)}</span><strong>${Number.isSafeInteger(value)||unknown===null?precise(value):esc(unknown)}</strong>${note ? `<small>${esc(note)}</small>` : ''}</div>`;
  const button = (label, action, attrs = '', style = '') => `<button type="button" class="vf-button ${style}" data-action="vf-${action}" ${attrs}>${esc(label)}</button>`;
  const attr = value => esc(JSON.stringify(value));
  const contextKey = () => {const c = getContext(); return JSON.stringify([c.space_id, c.account_id, c.from, c.through]);};
  const dates = () => state.period ?? {from: getContext().from, through: getContext().through};
  const projectionKey = (revision = getContext().revision ?? null) => JSON.stringify([contextKey(), revision, dates(), state.year, state.comparisonYear, state.grain, state.basis, state.program, state.budgetMonth]);
  const ledgerPath = () => JSON.stringify([projectionKey(null), state.direction, state.category, state.subcategory, state.merchantGroup]);
  const writeKey = row => [row.source_space_id ?? getContext().space_id, row.source_transaction_id ?? row.id, row.version].join(':');
  const monthLabel = month => new Intl.DateTimeFormat('en-US', {month: 'long', year: 'numeric', timeZone: 'UTC'}).format(new Date(`${month}-01T12:00:00Z`));
  const rangeLabel = range => !range ? '' : range.from===range.through ? new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(range.from+'T12:00:00Z')) : range.from.slice(0,7)===range.through.slice(0,7)&&range.from.endsWith('-01') ? monthLabel(range.from.slice(0,7)) : `${range.from} – ${range.through}`;
  const partialSpending = () => state.basis === 'spending' && report?.summary?.spending_cents == null
    && Number.isSafeInteger(report?.summary?.recorded_spending_cents) && report?.coverage?.spending?.complete === false;
  const spendingValue = () => partialSpending() ? report.summary.recorded_spending_cents : report?.summary?.spending_cents ?? report?.summary?.outflow_cents;
  const emptyRecordedPeriod = () => report?.coverage?.history_available === true && report.coverage.selected_range_has_evidence === false;
  const periodMoney = value => Number.isSafeInteger(value) || !emptyRecordedPeriod() ? precise(value) : 'None recorded';
  function spendingReviewHtml() {
    if (!partialSpending()) return '';
    const review=report.coverage.spending,rows=review.review_rows??[];
    return `<aside class="vf-data-status"><div><strong>Recorded costs · tax split pending</strong><p>${precise(review.unresolved_gross_cents)} in tax payments is separate from the recorded-cost subtotal above. Sales tax principal settles tax collected; any interest or fees still need to be identified before the full cost total is final.</p><details><summary>${rows.length} tax payment${rows.length===1?'':'s'} to review</summary>${rows.map(row=>`<p>${esc(row.date)} · ${esc(row.merchant)} · ${precise(Math.abs(row.bank_amount_cents))}</p>`).join('')}</details></div></aside>`;
  }
  const categoryLabel = key => report?.category_definitions?.find(row => row.key === key)?.label ?? financeLabel(key);
  const programPicker = () => (report?.program_options ?? []).length ? `<label>Program<select data-vf-input="program"><option value="">All programs</option>${report.program_options.map(p => `<option value="${p.id}" ${Number(state.program) === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>` : '';
  const overviewActions = () => `<div class="vf-quick-actions">${button('Explore analysis', 'navigate', 'data-view="analysis"', 'vf-primary')}${button('Your budgets', 'navigate', 'data-view="budget"')}${button('Review transactions', 'navigate', 'data-view="transactions"')}</div>`;

  function reset() {
    state = {...state, year: null, comparisonYear: null, period: null, direction: null, category: null, subcategory: null, merchantGroup: null, ledger: false, program: null, expandedRefund: null, budgetAccount: null, query: '', page: 1, budgetMonth: null, focusedEntry: null};
    report = envelopes = error = loadedProjection = retainedLedger = null; ownerContext = null; request++;
  }

  async function refresh({background = false, afterSave = false, isCurrent = () => true} = {}) {
    const c = getContext();
    if (!c.space_id || c.scope === 'both') return false;
    const quiet = background || afterSave;
    if (quiet && (loading || ownerContext !== contextKey() || (background && categorySaves.size))) return false;
    if (ownerContext !== contextKey()) { reset(); ownerContext = contextKey(); }
    const current = ++request, dateRange = dates();
    state.year ??= Number(dateRange.through.slice(0, 4));
    state.comparisonYear ??= state.year - 1;
    state.budgetMonth ??= dateRange.through.slice(0, 7);
    const params = new URLSearchParams({year: String(state.year), grain: state.grain, from: dateRange.from, through: dateRange.through, basis: state.basis, comparison_year: String(state.comparisonYear)});
    const budgetParams = new URLSearchParams({month: state.budgetMonth});
    if (c.account_id) params.set('account_id', String(c.account_id));
    if (state.program) { params.set('program_id', String(state.program)); budgetParams.set('program_id', String(state.program)); }
    const selectedState = JSON.stringify(state);
    const requestedProjection = projectionKey();
    if (!quiet) {loading = true; error = null;}
    const responses = await Promise.allSettled([api(endpoint(`/analysis?${params}`)), api(endpoint(`/budget-envelopes?${budgetParams}`))]);
    if (current !== request || ownerContext !== contextKey() || requestedProjection !== projectionKey() || (background && selectedState !== JSON.stringify(state)) || !isCurrent()) return false;
    // A failed quiet read retains the displayed, dated snapshot. An explicit
    // reload continues to show a scoped error rather than substituting data.
    if (quiet && responses.some(response => response.status !== 'fulfilled')) return false;
    loading = false;
    if (responses[0].status === 'fulfilled') {report = responses[0].value; error = null;}
    else {report = null; error = responses[0].reason.message;}
    if (responses[1].status === 'fulfilled') envelopes = responses[1].value;
    else envelopes = {error: responses[1].reason.message};
    loadedProjection=responses.every(response=>response.status==='fulfilled')?requestedProjection:null;
    return true;
  }

  async function change(next, reload = true, remember = true) {
    const originalContext = contextKey();
    if (remember) onBeforeChange(); state = {...state, focusedEntry: null, ...next};
    if (reload) { loading = true; onChange(); await refresh(); }
    if (originalContext !== contextKey()) return false;
    onChange();
    return true;
  }

  function coverageHtml() {
    const status = report?.coverage;
    const notes = Array.isArray(status?.notes) ? status.notes : status?.note ? [status.note] : [];
    return `${spendingReviewHtml()}<div class="vf-data-status"><span class="vf-status-dot" aria-hidden="true"></span><span>${status?.complete === true ? 'Recorded activity' : 'Recorded activity · history may be incomplete'}</span><details><summary>Sources &amp; timing</summary><p>${esc(report?.basis_label ?? (state.basis === 'spending' ? 'Purchases when made, including on credit cards. Repayments and investment transfers are separate.' : 'Money actually moving through owned bank accounts.'))}</p>${notes.map(n => `<p>${esc(n)}</p>`).join('')}<p>Amounts remain in their source currency. Missing history is unavailable; a chart difference is not a bank balance.</p></details></div>`;
  }

  function controls() {
    const years = [...new Set([state.year, state.year - 1, state.year - 2, ...(report?.available_years ?? [])])].sort((a, b) => b - a);
    const programs = report?.program_options ?? [];
    return `<div class="vf-controls"><div class="vf-segmented" role="group" aria-label="Comparison interval">${[['year', 'Annual'], ['month', 'Monthly'], ['week', 'Weekly']].map(([k, label]) => button(label, 'grain', `data-grain="${k}" aria-pressed="${state.grain === k}"`)).join('')}</div><label>Year<select data-vf-input="year">${years.map(y => `<option value="${y}" ${y === state.year ? 'selected' : ''}>${y}</option>`).join('')}</select></label><label>Compare with<select data-vf-input="comparison">${years.filter(y => y !== state.year).map(y => `<option value="${y}" ${y === state.comparisonYear ? 'selected' : ''}>${y}</option>`).join('')}</select></label><label>View<select data-vf-input="basis"><option value="spending" ${state.basis === 'spending' ? 'selected' : ''}>Purchases when made</option><option value="cash" ${state.basis === 'cash' ? 'selected' : ''}>Bank cash movements</option></select></label>${programs.length ? programPicker() : ''}${button('Organize categories', 'definitions')}</div>`;
  }

  function breadcrumbs() {
    const current = financeSelectionRows(report, state);
    const {parent, subcategory, merchant} = !current.selected && retainedLedger?.path === ledgerPath() ? retainedLedger : current;
    return `<nav class="vf-breadcrumbs" aria-label="Analysis path">${button('Analysis', 'level', 'data-level="overview"')}<span aria-hidden="true">›</span>${button(rangeLabel(report?.range ?? dates()), 'level', 'data-level="period"')}${parent ? `<span aria-hidden="true">›</span>${button(parent.label ?? categoryLabel(parent.key), 'level', 'data-level="category"')}` : ''}${subcategory ? `<span aria-hidden="true">›</span>${button(subcategory.label, 'level', 'data-level="subcategory"')}` : ''}${merchant ? `<span aria-hidden="true">›</span><span>${esc(merchant.label)}</span>` : ''}${state.ledger ? '<span aria-hidden="true">›</span><span aria-current="page">Transactions</span>' : ''}</nav>`;
  }

  function slicesHtml(rows, direction) {
    if (!rows?.length) return '<p class="vf-empty">No recorded activity in this period.</p>';
    const sorted = [...rows].sort((a, b) => (b.amount_cents ?? 0) - (a.amount_cents ?? 0));
    const renderRow = row => `<button type="button" data-action="vf-category" data-key="${esc(row.key)}" data-direction="${direction}"><span class="vf-swatch" style="background:${financeColor(row.key)}" aria-hidden="true"></span><span>${esc(row.label ?? categoryLabel(row.key))}</span><strong>${precise(row.amount_cents)}</strong><span aria-hidden="true">›</span></button>`;
    return `<div class="vf-slice-list">${sorted.slice(0, 6).map(renderRow).join('')}</div>${sorted.length > 6 ? `<details class="vf-more-slices"><summary>All ${sorted.length} ${direction === 'in' ? 'sources' : 'categories'}</summary><div class="vf-slice-list">${sorted.slice(6).map(renderRow).join('')}</div></details>` : ''}`;
  }

  function piesHtml() {
    const inflows = report?.inflows ?? [], categories = report?.categories ?? [];
    return `<section class="vf-period-panel"><div class="vf-section-heading"><div><span class="vf-eyebrow">${esc(rangeLabel(report?.range ?? dates()))}</span><h2>Where your money goes</h2></div>${button('View all transactions', 'ledger-all')}</div><div class="vf-pie-pair"><section class="vf-pie-panel"><div class="vf-pie-heading"><h3>Money coming in</h3><strong>${periodMoney(report?.summary?.inflow_cents)}</strong></div><p class="vf-caption">Sources of funds · borrowing and reimbursements stay distinct.</p>${inflows.some(r => r.amount_cents > 0) ? '<div class="vf-chart vf-donut"><canvas id="vf-income-chart" role="img" aria-label="Incoming funds by source; select a source to explore"></canvas></div>' : '<p class="vf-empty">No recorded incoming funds.</p>'}${slicesHtml(inflows, 'in')}</section><section class="vf-pie-panel"><div class="vf-pie-heading"><h3>${partialSpending() ? 'Recorded costs' : state.basis === 'spending' ? 'Money spent' : 'Money leaving accounts'}</h3><strong>${periodMoney(spendingValue())}</strong></div><p class="vf-caption">${partialSpending() ? 'Recorded purchases and refunds · tax payment split pending.' : state.basis === 'spending' ? 'Purchases when made · card and loan repayments excluded.' : 'Actual cash payments · repayments are included.'}</p>${categories.some(r => r.amount_cents > 0) ? '<div class="vf-chart vf-donut"><canvas id="vf-spending-chart" role="img" aria-label="Outgoing categories; select a category to explore"></canvas></div>' : '<p class="vf-empty">No recorded outgoing costs.</p>'}${slicesHtml(categories, 'out')}${categories.some(r => r.amount_cents < 0) ? '<p class="vf-caption">Net-refund categories remain in the list and totals; negative amounts are not pie slices.</p>' : ''}</section></div></section>`;
  }

  function trendHtml() {
    return `<section class="vf-trend-panel"><div class="vf-section-heading"><div><span class="vf-eyebrow">The bigger picture</span><h2>${state.grain === 'year' ? 'Year' : state.grain === 'week' ? 'Week' : 'Month'} by ${state.grain === 'year' ? 'year' : state.grain === 'week' ? 'week' : 'month'}</h2></div><span class="vf-caption">Tap a bar to explore that period</span></div><div class="vf-chart vf-trend"><canvas id="vf-period-chart" role="img" aria-label="Recorded incoming and spending by period, with comparison year; choose a period to drill down"></canvas></div><details class="vf-chart-alternative"><summary>Choose a period from the list</summary><div class="vf-period-list">${(report?.periods ?? []).map((p, i) => button(`${p.label} · ${precise(p.spending_cents)}`, 'period', `data-period-index="${i}" ${p.from && p.through && !p.future ? '' : 'disabled'}`)).join('')}</div></details></section>`;
  }

  function budgetAccountId(row) {
    if (row.budget_account_id) return row.budget_account_id;
    const identity = row.source_identity;
    return identity?.authorized_source && identity.source_space_id === getContext().space_id ? identity.account_id : null;
  }

  function ledgerHtml(entries, heading = 'Transactions') {
    const costMode = state.direction === 'out';
    const signedAmount = row => costMode ? -row.amount_cents : row.amount_cents;
    const budgetAccounts = state.basis === 'spending' && state.direction !== 'in' ? report?.account_options ?? [] : [];
    const budgetFiltered = budgetAccounts.length && state.budgetAccount
      ? entries.filter(row => row.direction === 'out' && budgetAccountId(row) === state.budgetAccount)
      : entries;
    const filtered = sortFinanceEntries(budgetFiltered, state.ledgerSort, state.query);
    const groups = groupRefundEntries(filtered);
    const pages = Math.max(1, Math.ceil(groups.length / pageSize));
    state.page = Math.min(state.page, pages);
    const page = groups.slice((state.page - 1) * pageSize, state.page * pageSize);
    const focused = filtered.find(row => keyOf(row) === state.focusedEntry);
    const categoryControl = row => {
      if ((!row.editable && !row.attribution_editable) || row.category_editable === false || row.tax_purpose) {
        return `<span>${esc(row.category_label ?? categoryLabel(row.category))}</span>${row.editable ? button('Review', 'review-row', `data-entry="${esc(keyOf(row))}"`) : '<small>Allocated · read only</small>'}`;
      }
      const pending = categorySaves.get(writeKey(row));
      const value = pending?.entry === keyOf(row) ? pending.category : row.category;
      const options = (report?.category_options ?? []).map(c => `<option value="${esc(c)}" ${c === value ? 'selected' : ''}>${esc(categoryLabel(c))}</option>`);
      return `<label class="vf-inline-category"><span class="sr-only">Category for ${esc(row.merchant)}</span><select data-vf-category="${esc(keyOf(row))}" data-vf-version="${esc(row.version)}" ${pending ? 'disabled' : ''}>${options.join('')}</select></label>`;
    };
    const entryHtml = (row, paired = false) => {
      visibleLedgerRows.push(row);
      const key = esc(keyOf(row));
      const context = [row.program_name, row.source_identity ? null : row.account_name, row.amount_basis === 'owned_bank_cash' ? 'Bank cash' : null].filter(Boolean).join(' · ');
      const matchLabel = refundMatchLabel(row, esc);
      return `<tr data-list-key="${esc(reviewRowKey(row))}" data-entry-key="${key}" class="${keyOf(row) === state.focusedEntry ? 'vf-selected-row' : ''} ${paired ? 'vf-pair-detail' : ''}">
        <td data-label="Date" class="tr-review-date">${esc(row.date)}</td>
        <td data-label="Merchant" class="tr-review-merchant"><button type="button" class="tr-list-merchant-mobile" data-action="transaction-list-detail">${esc(row.merchant)}</button><button type="button" class="vf-merchant-select tr-list-merchant-desktop" data-action="vf-select-row" data-entry="${key}">${esc(row.merchant)}</button>${transactionIdentityHtml(row, {esc})}${context ? `<small>${esc(context)}</small>` : ''}</td>
        <td data-label="Category" class="tr-review-category">${categoryControl(row)}${transactionReview?.render(row) ?? ''}</td>
        <td data-label="Amount" class="vf-money tr-review-amount">${precise(signedAmount(row))}${matchLabel}</td>
        <td data-label="Actions" class="tr-review-actions">${button(row.editable ? 'Details' : 'View', 'review-row', `data-entry="${key}"`)}${row.attribution_editable || row.editable ? button('Split', 'attribution', `data-entry="${key}" aria-label="Split transaction for ${esc(row.merchant)}"`) : ''}</td></tr>`;
    };
    const pairHtml = group => {
      const {purchase, refund, key} = group;
      const expanded = state.expandedRefund === key;
      const sameCategory = purchase.category === refund.category;
      const category = sameCategory ? categoryLabel(purchase.category) : [purchase, refund].map(row => categoryLabel(row.category)).join(' / ');
      return `<tr class="vf-refund-pair" data-refund-pair="${esc(key)}">
        <td class="tr-review-date">${esc(purchase.date)}</td>
        <td class="tr-review-merchant"><strong>${esc(purchase.merchant)}</strong><small>Purchase &amp; return · ${precise(Math.abs(purchase.amount_cents))}</small></td>
        <td class="tr-review-category">${esc(category)}</td>
        <td class="vf-money tr-review-amount">${precise(signedAmount(purchase) + signedAmount(refund))}<span class="tr-refund-label">Net after return</span></td>
        <td class="tr-review-actions">${button(expanded ? 'Hide pair' : 'View pair', 'refund-pair', `data-pair="${esc(key)}" aria-expanded="${expanded}"`)}</td>
      </tr>${expanded ? entryHtml(purchase, true) + entryHtml(refund, true) : ''}`;
    };
    visibleLedgerRows = [];
    const body = page.map(group => group.row ? entryHtml(group.row) : pairHtml(group)).join('');
    const sorts = [['largest', 'Biggest first'], ['smallest', 'Smallest first'], ['newest', 'Newest first'], ['oldest', 'Oldest first'], ['merchant', 'Merchant'], ['category', 'Category']];
    const accountPicker = budgetAccounts.length ? `<label>Budget account<select data-vf-input="budget-account"><option value="">All accounts</option>${budgetAccounts.map(account => `<option value="${esc(String(account.id))}" ${state.budgetAccount === account.id ? 'selected' : ''}>${esc(account.name)}</option>`).join('')}</select></label>` : '';
    return `<section class="vf-ledger"><div class="vf-section-heading"><div><span class="vf-eyebrow">${filtered.length} recorded entries</span><h2>${esc(heading)}</h2></div><strong>${precise(filtered.reduce((sum, row) => sum + signedAmount(row), 0))}</strong></div>
      <div class="vf-ledger-controls"><label>Find a transaction<input type="search" data-vf-input="query" value="${esc(state.query)}" placeholder="Merchant, date or category"></label>${accountPicker}<label>Sort<select data-vf-input="sort">${sorts.map(([key, text]) => `<option value="${key}" ${state.ledgerSort === key ? 'selected' : ''}>${text}</option>`).join('')}</select></label></div>
      ${focused ? `<div class="vf-selected-purchase"><span>Selected: <strong>${esc(focused.merchant)}</strong> · ${precise(signedAmount(focused))}</span>${button('Ask about this purchase', 'ask-selected')}${button('Clear selection', 'clear-selection')}</div>` : ''}${transactionReview?.renderUndo() ?? ''}
      <div class="vf-table-wrap tr-review-table-wrap"><table class="vf-table tr-review-table"><caption class="sr-only">${esc(heading)}</caption><thead><tr><th scope="col">Date</th><th scope="col">Merchant</th><th scope="col">Category</th><th scope="col">${costMode ? 'Cost / refund' : 'Amount'}</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>${body || '<tr><td colspan="5" class="tr-review-empty">No transactions match this view.</td></tr>'}</tbody></table></div>
      <div class="vf-pagination">${button('Previous', 'page', `data-page="${state.page - 1}" ${state.page === 1 ? 'disabled' : ''}`)}<span>Page ${state.page} of ${pages}</span>${button('Next', 'page', `data-page="${state.page + 1}" ${state.page === pages ? 'disabled' : ''}`)}</div></section>`;
  }

  // Reconcile the existing ledger after a scoped read; the chart controls, search,
  // scroll container and every unaffected purchase stay mounted.
  function patchLedger() {
    const ledger = root.querySelector('.vf-finance .vf-ledger');
    if (!ledger || !report) return false;
    const doc = ledger.ownerDocument, selection = financeSelectionRows(report, state);
    const entries = state.category ? selection.entries : periodLedgerEntries(report);
    const heading = selection.selected?.label ?? ledger.querySelector('h2').textContent;
    const template = doc.createElement('template');
    template.innerHTML = ledgerHtml(entries, heading);
    const next = template.content.firstElementChild;
    const tbody = ledger.querySelector('tbody'), newBody = next.querySelector('tbody');
    const rowKey = tr => tr.dataset.entryKey ?? tr.dataset.refundPair ?? '__empty__';
    const existing = new Map([...tbody.rows].map(tr => [rowKey(tr), tr]));
    const wanted = new Set([...newBody.rows].map(rowKey));
    const pendingFocus = [...categorySaves.values()].find(edit => edit.focused && edit.target.isConnected)?.target;
    const focus = doc.activeElement === doc.body ? pendingFocus : doc.activeElement;
    const focusedRow = tbody.contains(focus ?? null) ? focus.closest('tr') : null;
    const focusedIndex = focusedRow?.sectionRowIndex ?? -1;
    for (const [key, tr] of existing) if (!wanted.has(key)) tr.remove();
    let cursor = tbody.firstElementChild;
    for (const newRow of [...newBody.rows]) {
      const tr = existing.get(rowKey(newRow)) ?? newRow;
      if (tr !== newRow && !tr.isEqualNode(newRow)) {
        tr.className = newRow.className;
        if (newRow.dataset.listKey) tr.dataset.listKey = newRow.dataset.listKey;
        for (const [i, cell] of [...tr.cells].entries()) {
          const fresh = newRow.cells[i];
          if (!cell.isEqualNode(fresh)) cell.replaceWith(fresh.cloneNode(true));
        }
      }
      if (tr !== cursor) tbody.insertBefore(tr, cursor);
      cursor = tr.nextElementSibling;
    }
    for (const selector of ['.vf-section-heading .vf-eyebrow', '.vf-section-heading h2', '.vf-section-heading > strong', 'caption']) {
      const current = ledger.querySelector(selector), text = next.querySelector(selector).textContent;
      if (current.textContent !== text) current.textContent = text;
    }
    const trail = root.querySelector('.vf-finance .vf-breadcrumbs');
    if (trail) {
      const crumbs = doc.createElement('template');
      crumbs.innerHTML = breadcrumbs();
      const fresh = crumbs.content.firstElementChild;
      if (!trail.isEqualNode(fresh)) trail.replaceChildren(...fresh.childNodes);
    }
    for (const selector of ['.vf-pagination','.vf-selected-purchase','.tr-review-undo']) {
      const current = ledger.querySelector(selector), fresh = next.querySelector(selector);
      if (current && !fresh) current.remove();
      else if (fresh && !current) ledger.querySelector('.vf-table-wrap').before(fresh);
      else if (current && !current.isEqualNode(fresh)) current.replaceChildren(...fresh.childNodes);
    }
    if (focusedIndex >= 0 && !focus.isConnected) {
      const controls = '[data-vf-category]:enabled, [data-action="vf-review-row"]';
      const rows = [...tbody.rows];
      const nextControl = [...rows.slice(focusedIndex), ...rows.slice(0, focusedIndex).reverse()].map(tr => tr.querySelector(controls)).find(Boolean);
      (nextControl ?? ledger.querySelector('[data-vf-input="query"]')).focus({preventScroll: true});
    }
    return true;
  }

  function categoryHtml() {
    const {parent, subcategory, merchant, selected, entries} = financeSelectionRows(report, state);
    if (!selected && retainedLedger?.path === ledgerPath()) return ledgerHtml([], retainedLedger.heading);
    if (!selected) return `<section class="vf-empty"><h2>This category changed</h2><p>Its transactions may have moved after a review.</p>${button('Back to the period', 'level', 'data-level="period"')}</section>`;
    if (state.ledger || !selected.children?.length) {
      const heading = selected.label ?? categoryLabel(selected.key);
      retainedLedger = {path: ledgerPath(), heading, parent, subcategory, merchant};
      return ledgerHtml(entries, heading);
    }
    const children = selected.children;
    const amountsLabel = children.every(row => row.group_kind === 'merchant') ? 'Merchant amounts' : children.some(row => row.group_kind === 'merchant') ? 'Subcategory and merchant amounts' : 'Subcategory amounts';
    return `<section><div class="vf-section-heading"><div><span class="vf-eyebrow">${esc(parent?.label ?? '')}</span><h2>${esc(selected.label)}</h2></div><strong>${precise(selected.amount_cents)}</strong>${button('View all transactions', 'ledger-category')}</div><div class="vf-category-explore"><div class="vf-chart vf-donut"><canvas id="vf-category-chart" role="img" aria-label="${amountsLabel}; select a slice to explore"></canvas></div><div class="vf-slice-list">${children.map(row => `<button type="button" data-action="vf-subcategory" data-key="${esc(row.key)}"><span class="vf-swatch" style="background:${financeColor(row.key)}" aria-hidden="true"></span><span>${esc(row.label)}</span><strong>${precise(row.amount_cents)}</strong><span aria-hidden="true">›</span></button>`).join('')}</div></div></section>`;
  }

  function envelopeCards(limit = null, funds = false) {
    if (getContext().account_id) return `<div class="vf-empty"><h3>Your budgets cover all accounts</h3>${button('Show all accounts', 'all-accounts')}</div>`;
    const rows = financeBudgetRows(envelopes);
    const matching = rows.map((row, index) => ({row, index})).filter(({row}) => (row.mode === 'fund') === funds);
    const selected = limit ? matching.slice(0, limit) : matching;
    if (envelopes?.error) return `<div class="vf-error" role="status">Budgets could not load. ${esc(envelopes.error)} ${button('Try again', 'retry')}</div>`;
    if (!selected.length) return funds ? '' : `<div class="vf-empty"><h3>No category budgets yet</h3>${button('Set up a budget', 'legacy-budget')}</div>`;
    const money = amount => amount === null ? '—' : precise(amount);
    return `<div class="vf-envelope-grid vf-budget-list${funds ? ' vf-goal-list' : ''}">${selected.map(({row, index}) => {
      const color = financeColor(row.category ?? row.key), compact = financeBudgetCompact(row, {future: envelopes?.future === true});
      let used = compact.plan === null || compact.spent === null || compact.needsSplit && compact.spent <= 0 ? null : compact.plan === 0 ? (compact.spent > 0 ? 100 : 0) : Math.min(100, Math.max(0, compact.spent / compact.plan * 100));
      let allowance = compact.plan === null ? row.uses_parent_budget ? 'Shared' : 'Set budget' : `${money(compact.plan)}${row.target_status === 'draft' ? ' est.' : ''}${row.partial_period ? ' dated' : ''}`;
      let spent = compact.needsSplit && compact.spent === 0 ? 'Needs split' : `${money(compact.spent)}${compact.needsSplit && compact.spent > 0 ? '+' : ''} spent`;
      let left = `${compact.needsSplit && compact.left !== null && compact.left >= 0 ? '≤ ' : ''}${money(compact.left)} left${compact.estimated ? ' · est.' : ''}`;
      if (row.mode === 'income_percentage' && Number.isSafeInteger(row.rate_bps)) allowance += ` · ${row.rate_bps / 100}%`;
      if (funds) {
        const saved = number(row.fund?.saved_cents), cap = number(row.fund?.target_cents);
        allowance = `${money(cap)} goal`;
        spent = `${money(saved)} saved`;
        left = cap === null || saved === null ? '— to save' : `${money(Math.max(0, cap-saved))} to save`;
        used = saved === null || cap === null ? null : cap === 0 ? 100 : Math.min(100, Math.max(0, saved/cap*100));
      }
      const over = !funds && compact.left !== null && compact.left < 0;
      return `<article class="vf-envelope-shell"><button type="button" class="vf-envelope vf-budget-list-row ${over ? 'vf-over-budget' : ''}" data-action="vf-envelope" data-index="${index}" style="--budget-color:${color}"><span class="vf-budget-row-heading"><span class="vf-budget-icon">${budgetIcon(row)}</span><span class="vf-envelope-label">${esc(row.label)}</span><span class="vf-budget-chevron" aria-hidden="true">›</span></span><span class="vf-budget-track ${used === null ? 'vf-budget-track-unknown' : ''}" ${used === null ? '' : `role="progressbar" aria-label="${esc(row.label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(used)}" aria-valuetext="${esc(spent)} of ${esc(allowance)}; ${esc(left)}"`}><i style="width:${used ?? 0}%;background:${over ? '#b4533d' : color}" aria-hidden="true"></i><span class="vf-budget-row-amounts"><span>${esc(spent)}<span class="vf-budget-row-limit"> / ${esc(allowance)}</span></span><b>${esc(left)}</b></span></span></button></article>`;
    }).join('')}</div>`;
  }

  function budgetSummary() {
    if (getContext().account_id || envelopes?.error) return '';
    const totals = financeBudgetTotals(envelopes);
    const metric = (label, value, partial) => `<div><span>${label}</span><strong>${value === null ? '—' : precise(value)}</strong>${partial ? '<small>Partial</small>' : totals.estimated ? '<small>Est.</small>' : ''}</div>`;
    return `<section class="vf-budget-totals" aria-label="Monthly budget totals">${metric('Total budget', totals.total, totals.partialTotal)}${metric('Budget left', totals.left, totals.partialLeft)}</section>`;
  }

  function seasonalCards() {
    const cards = envelopes?.seasonal_spending;
    if (getContext().scope !== 'personal' || getContext().account_id || envelopes?.month !== state.budgetMonth || !cards?.length) return '';
    const format = new Intl.DateTimeFormat('en-US', {month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'});
    const day = iso => new Date(iso + 'T12:00:00Z');
    return `<section class="vf-stat-row" aria-label="Seasonal spending">${cards.map(card => {
      const period = card.through ? format.formatRange(day(card.from), day(card.through)) : `Starts ${format.format(day(card.from))}`;
      const note = card.through && !card.range_covered ? `${period} · Partial account history` : period;
      return stats(`${categoryLabel(card.category)} spending`, card.net_spent_cents, note, '', card.through ? 'No history' : 'Upcoming');
    }).join('')}</section>`;
  }

  function savingsGoals() {
    if (getContext().account_id || !envelopes?.rows?.some(row => row.mode === 'fund')) return '';
    return `<details class="vf-savings-goals"><summary>Savings goals <span>After monthly expenses</span></summary>${envelopeCards(null, true)}</details>`;
  }

  function render(view) {
    visibleLedgerRows = [];
    if (getContext().scope === 'both') return '<div class="vf-empty">Choose Personal or one business to explore its money without counting transfers twice.</div>';
    if (error) return `<section class="vf-error" role="alert"><h2>This financial view could not load</h2><p>${esc(error)}</p>${button('Try again', 'retry')}</section>`;
    if (!report) return '<section class="vf-empty" role="status">Loading your financial picture…</section>';
    const covered = coverageHtml();
    if (view === 'budget') return `<div class="vf-finance" aria-busy="${loading}">${budgetSummary()}${seasonalCards()}<div class="vf-section-heading vf-budget-tools">${programPicker()}<label>Budget month<input type="month" data-vf-input="budget-month" value="${esc(state.budgetMonth)}"></label>${button('Manage targets', 'legacy-budget')}${button('Add category / program', 'definitions')}</div>${envelopeCards()}${savingsGoals()}</div>`;
    const emptyToday = emptyRecordedPeriod();
    const latest = report.coverage?.latest_recorded_on;
    const latestNote = emptyToday ? latest && /^\d{4}-\d{2}-\d{2}$/.test(latest) ? `Bank activity through ${new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',timeZone:'UTC'}).format(new Date(latest+'T12:00:00Z'))}` : 'No bank activity yet' : null;
    const metrics = `<div class="vf-stat-row">${stats('Money coming in', report.summary?.inflow_cents, latestNote??'Sources of funds','',emptyToday?'None recorded':null)}${stats(partialSpending() ? 'Recorded costs' : state.basis === 'spending' ? 'Purchases & costs' : 'Bank cash out', spendingValue(), partialSpending() ? 'Tax payment split pending' : latestNote??rangeLabel(report.range),'',emptyToday?'None recorded':null)}${!getContext().account_id && Number.isSafeInteger(envelopes?.summary?.remaining_cents) ? stats('Budget left this month', envelopes.summary.remaining_cents, monthLabel(state.budgetMonth), 'vf-budget-stat') : ''}</div>`;
    if (view === 'overview') {const budgets = `<section class="vf-budget-preview"><div class="vf-section-heading"><div><span class="vf-eyebrow">${esc(monthLabel(state.budgetMonth))}</span><h2>What’s left in your budgets</h2></div>${programPicker()}${button('All budgets', 'navigate', 'data-view="budget"')}</div>${envelopeCards(6)}</section>`;return `<div class="vf-finance" aria-busy="${loading}">${metrics}${seasonalCards()}${overviewActions()}${piesHtml()}${budgets}${trendHtml()}${homeBills()}${covered}</div>`;}
    return `<div class="vf-finance" aria-busy="${loading}">${controls()}${breadcrumbs()}${state.category ? categoryHtml() : state.ledger ? ledgerHtml(periodLedgerEntries(report), 'Period transactions') : `${metrics}${trendHtml()}${piesHtml()}`}${covered}</div>`;
  }

  function draw() {
    if (!report) return;
    const donut = (id, rows, direction, action) => {
      const positive = rows.filter(r => r.amount_cents > 0);
      if (!root.querySelector(`#${id}`) || !positive.length) return;
      chart(id, 'doughnut', positive.map(r => r.label ?? categoryLabel(r.key)), [{data: positive.map(r => r.amount_cents), backgroundColor: positive.map(r => financeColor(r.key)), borderWidth: 3, borderColor: '#fff', hoverOffset: 8}], i => action ? action(positive[i]) : void selectCategory(positive[i].key, direction), {cutout: '68%',explanation:{period:`${report.range.from} – ${report.range.through}`,basis:direction==='in'?'Cash received':state.basis==='spending'?'Purchases when made':'Bank cash movements',source:report.scope?.space_name??getSpaces().find(space=>space.id===getContext().space_id)?.name??'Recorded activity',description:direction==='in'?'Cash received by source. Borrowing, asset sales, company support and reimbursements remain distinct; receipts are not all earned income.':state.basis==='spending'?`${partialSpending()?'Recorded costs only; the complete spending total is pending tax-payment splits. ':''}Recorded purchases and refunds include purchases on cards. Repayments and transfers are excluded. Shares compare the positive categories displayed; refunds can reduce the net total.`:'Actual cash leaving owned bank accounts. Loan principal, card repayments and investments are separate cash uses rather than household consumption.'}});
    };
    donut('vf-income-chart', report.inflows ?? [], 'in');
    donut('vf-spending-chart', report.categories ?? [], 'out');
    const {selected} = financeSelectionRows(report, state);
    if (selected?.children) donut('vf-category-chart', selected.children, state.direction, row => selectChild(row.key));
    const periods = report.periods ?? [];
    if (root.querySelector('#vf-period-chart')) chart('vf-period-chart', 'bar', periods.map(p => p.label), [
      {label: 'Money in', data: periods.map(p => p.inflow_cents), backgroundColor: '#348e84', borderRadius: 4},
      {label: state.basis === 'spending' ? 'Purchases & costs' : 'Bank cash out', data: periods.map(p => p.spending_cents ?? p.outflow_cents), backgroundColor: '#5b83c1', borderRadius: 4},
      {label: `${state.comparisonYear} · ${state.basis === 'spending' ? 'purchases & costs' : 'cash out'}`, data: periods.map(p => p.comparison?.spending_cents ?? p.comparison?.outflow_cents ?? null), backgroundColor: '#b2a1d1', borderRadius: 4},
    ], (index, dataset) => selectPeriod(index, dataset), {beginAtZero: true});
  }

  async function selectPeriod(index, dataset = 0) {
    const p = report?.periods?.[index], selected = dataset === 2 ? p?.comparison : p;
    if (!selected?.from || !selected?.through || selected.future) return;
    if (!await change({period: {from: selected.from, through: selected.through}, direction: null, category: null, subcategory: null, merchantGroup: null, ledger: false, page: 1})) return;
    onNavigate('analysis', false);
  }
  async function selectCategory(category, direction) {
    const groups = direction === 'in' ? report?.inflows : report?.categories;
    if (!groups?.some(row => row.key === category)) return;
    await change({direction, category, subcategory: null, merchantGroup: null, ledger: false, page: 1}, false);
    onNavigate('analysis', false);
  }

  async function selectChild(key) {
    const {selected} = financeSelectionRows(report, state);
    const child = selected?.children?.find(row => row.key === key);
    if (!child) return;
    const next = child.group_kind === 'merchant' ? {merchantGroup: child.key} : {subcategory: child.key, merchantGroup: null};
    await change({...next, ledger: !child.children?.length, page: 1}, false);
  }

  async function openAttribution(row) {
    const sourceSpace = row.source_space_id ?? row.space_id ?? getContext().space_id;
    await openSplitEditor({root, row, sourceSpace, api, endpoint, esc, precise, categoryLabel: financeLabel, notice, onChanged});
  }

  async function handleClick(target) {
    const action = target.dataset.action;
    if (!action?.startsWith('vf-')) return false;
    if (action === 'vf-navigate') onNavigate(target.dataset.view);
    else if (action === 'vf-grain') await change({grain: target.dataset.grain, category: null, subcategory: null, merchantGroup: null, ledger: false});
    else if (action === 'vf-period') await selectPeriod(Number(target.dataset.periodIndex));
    else if (action === 'vf-category') await selectCategory(target.dataset.key, target.dataset.direction);
    else if (action === 'vf-subcategory') await selectChild(target.dataset.key);
    else if (action === 'vf-ledger-category') await change({ledger: true, page: 1}, false);
    else if (action === 'vf-ledger-all') {await change({category: null, subcategory: null, merchantGroup: null, ledger: true, direction: null, page: 1}, false); onNavigate('analysis', false);}
    else if (action === 'vf-refund-pair') {
      state.expandedRefund = state.expandedRefund === target.dataset.pair ? null : target.dataset.pair;
      onChange();
      Array.from(root.querySelectorAll('[data-pair]')).find(node => node.dataset.pair === target.dataset.pair)?.focus({preventScroll: true});
    }
    else if (action === 'vf-page') await change({page: Number(target.dataset.page)}, false, false);
    else if (action === 'vf-level') await change(target.dataset.level === 'subcategory' ? {merchantGroup: null, ledger: false} : target.dataset.level === 'category' ? {subcategory: null, merchantGroup: null, ledger: false} : target.dataset.level === 'period' ? {direction: null, category: null, subcategory: null, merchantGroup: null, ledger: false} : {period: null, direction: null, category: null, subcategory: null, merchantGroup: null, ledger: false}, target.dataset.level === 'overview');
    else if (action === 'vf-retry') {await refresh(); onChange();}
    else if (action === 'vf-select-row') {state.focusedEntry = target.dataset.entry; onChange();}
    else if (action === 'vf-clear-selection') {state.focusedEntry = null; onChange();}
    else if (action === 'vf-ask-selected') onAsk?.();
    else if (action === 'vf-legacy-budget') onEditBudget();
    else if (action === 'vf-all-accounts') onClearAccount?.();
    else if (action === 'vf-definitions') onAddDefinition();
    else if (action === 'vf-attribution') {const row = report?.entries?.find(r => keyOf(r) === target.dataset.entry); if (row) await openAttribution(row);}
    else if (action === 'vf-envelope') {
      const row = financeBudgetRows(envelopes)[Number(target.dataset.index)];
      if (row) onOpenBudget?.(row,envelopes);
    } else if (action === 'vf-review-row') {
      const row = report?.entries?.find(r => keyOf(r) === target.dataset.entry);
      if (row) {state.focusedEntry = keyOf(row); if (row.attribution_detail_id && row.attribution_editable) await openAttribution(row); else onReviewTransaction(row);}
    }
    return true;
  }

  async function openBudgetEntries(row, budgetReport = envelopes) {
    const month = budgetReport?.month ?? state.budgetMonth;
    const monthEnd = budgetReport?.actual_through ?? budgetReport?.range?.through ?? new Date(Date.UTC(Number(month.slice(0,4)),Number(month.slice(5)),0)).toISOString().slice(0,10);
    const today = report?.today ?? getContext().today ?? getContext().through;
    if (`${month}-01` > today) {notice('This is a future plan. There is no actual spending period yet.');return false;}
    const through = monthEnd < today ? monthEnd : today;
    if (await change({period:{from:`${month}-01`,through},direction:'out',category:row.parent_key??row.category??row.key,subcategory:row.parent_key?row.category:null,merchantGroup:null,ledger:true,page:1})) {onNavigate('analysis',false);return true;}
    return false;
  }
  async function handleInput(target) {
    if (target.dataset.vfCategory) {
      const row = report?.entries?.find(r => keyOf(r) === target.dataset.vfCategory);
      if (!row || (target.dataset.vfVersion && Number(target.dataset.vfVersion) !== row.version)) {
        if (row) target.value = row.category;
        notice('This transaction changed. Refresh before editing it.');
        return;
      }
      if ((!row.editable && !row.attribution_editable) || row.tax_purpose || row.category_editable === false) return;
      const category = target.value, operation = writeKey(row);
      const pending = categorySaves.get(operation);
      if (pending) {target.value = pending.entry === keyOf(row) ? pending.category : row.category;return;}
      const {space_id: targetSpace, scope, view} = getContext(), projection = projectionKey(null);
      const isCurrent = () => projection === projectionKey(null) && view === getContext().view && scope === getContext().scope;
      const edit = {entry: keyOf(row), category, target, focused: target.ownerDocument?.activeElement === target};
      categorySaves.set(operation, edit);
      target.disabled = true;
      let saved = false;
      try {
        const result = await saveTransactionCategory({row, category, targetSpace, categoryDefinitions: report.category_definitions, api, endpoint, isCurrent});
        saved = true;
        const updated = await onCategorySaved({row, category, result, isCurrent});
        if (isCurrent()) notice(updated === false ? 'Category saved. Refresh to update this list and its totals.' : 'Category saved.');
      } catch (error) {
        if (isCurrent()) {
          if (!saved) target.value = row.category;
          notice(saved ? 'Category saved. Refresh to update this list and its totals.' : error.message);
        }
      } finally {
        categorySaves.delete(operation);
        if (target.isConnected) target.disabled = false;
        if (isCurrent()) for (const control of root.querySelectorAll?.('[data-vf-category]:disabled') ?? []) {
          const current = report?.entries?.find(entry => keyOf(entry) === control.dataset.vfCategory);
          if (current && writeKey(current) === operation) control.disabled = false;
        }
      }
      return;
    }
    const input = target.dataset.vfInput;
    if (input === 'year') {const y = Number(target.value), through = getContext().through; await change({year: y, comparisonYear: y - 1, period: {from: `${y}-01-01`, through: Number(through.slice(0, 4)) === y ? through : `${y}-12-31`}, category: null, subcategory: null, merchantGroup: null, ledger: false});}
    else if (input === 'comparison') await change({comparisonYear: Number(target.value)});
    else if (input === 'basis') await change({basis: target.value, category: null, subcategory: null, merchantGroup: null, ledger: false});
    else if (input === 'program') await change({program: target.value ? Number(target.value) : null, category: null, subcategory: null, merchantGroup: null, ledger: false});
    else if (input === 'budget-month') await change({budgetMonth: target.value});
    else if (input === 'budget-account') await change({budgetAccount: target.value ? Number(target.value) : null, expandedRefund: null, page: 1}, false, false);
    else if (input === 'sort') await change({ledgerSort: target.value, page: 1}, false, false);
    else if (input === 'query') {state.query = target.value; state.page = 1; onChange(); const inputNode = root.querySelector('[data-vf-input="query"]'); inputNode?.focus({preventScroll: true}); inputNode?.setSelectionRange(state.query.length, state.query.length);}
  }

  function assistantContext() {
    const selection = financeSelectionRows(report, state);
    const ledger = state.category ? selection.entries : state.ledger ? periodLedgerEntries(report) : [];
    const account = (state.ledger || state.category) && state.basis === 'spending' && state.direction !== 'in' ? state.budgetAccount : null;
    const scopedEntries = account ? ledger.filter(row => row.direction === 'out' && budgetAccountId(row) === account) : ledger;
    const focused = state.focusedEntry ? scopedEntries.find(row => keyOf(row) === state.focusedEntry) : null;
    const entries = focused ? [focused] : scopedEntries;
    return {...dates(), account_id: getContext().account_id ?? null, budget_account_id: account ?? null, program_id: state.program,
      level: state.ledger ? 'transactions' : state.category ? 'category' : state.period ? 'period' : 'overview', direction: state.direction,
      category: state.category, subcategory: selection.subcategory?.category_key ?? selection.subcategory?.key ?? null, basis: state.basis,
      transaction_ids: [...new Set(entries.map(r => r.source_transaction_id ?? r.id))].slice(0, 100), transaction_versions: Object.fromEntries(entries.slice(0, 100).map(r => [r.source_transaction_id ?? r.id, r.version])), selection_truncated: entries.length > 100, focused_transaction: !!focused,
    };
  }

  return {render, draw, refresh, patchLedger, reset, openAttribution, openBudgetEntries, getVisibleLedgerRows: () => visibleLedgerRows, handleClick, handleInput, getAssistantContext: assistantContext,
    adoptSnapshot(initialReport, initialBudgets) {
      reset();ownerContext=contextKey();report=initialReport;envelopes=initialBudgets;
      loading=false;
      state.year=report.year;state.comparisonYear=state.year-1;state.grain=report.grain;state.basis=report.scope.basis;state.budgetMonth=envelopes.month;
      loadedProjection=projectionKey();
    },
    associateRevision(previousRevision) {
      if(!report||!envelopes||error||loading||envelopes.error||loadedProjection!==projectionKey(previousRevision))return false;
      loadedProjection=projectionKey();return true;
    },
    clearExploration() {
      const through = getContext().through, year = Number(through.slice(0,4));
      state = {...state, year, comparisonYear: year-1, grain: 'month', period: null, direction: null, category: null, subcategory: null, merchantGroup: null, ledger: false, program: null, expandedRefund: null, budgetAccount: null, query: '', page: 1, focusedEntry: null};
    },
    capture: () => clone(state),
    restore(value) {if (value?.version === 1) state = {...state, merchantGroup: null, ...clone(value)};},
    getReport: () => report,
    getBudgets: () => envelopes,
    hasCurrentSnapshot: () => !!report && !!envelopes && !error && !envelopes.error && !loading && loadedProjection === projectionKey(),
    isBusy: () => loading || categorySaves.size > 0,
  };
}
