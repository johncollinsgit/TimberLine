/** A single exploration path: period → source/category → subcategory → editable ledger. */
export const financeColors = ['#257c77', '#4c83c3', '#db8845', '#9170c4', '#ce668c', '#6e9e54', '#43a4b0', '#bb9a3d', '#6279a6', '#c16e52', '#829252', '#9e71a1'];
export const financeColor = key => financeColors[[...String(key)].reduce((n, c) => (n * 31 + c.charCodeAt(0)) >>> 0, 0) % financeColors.length];
const number = n => Number.isSafeInteger(n) ? n : null;
const clone = x => JSON.parse(JSON.stringify(x));
const keyOf = r => String(r.entry_key ?? r.detail_id ?? r.id);
export const financeLabel = value => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());

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
  const selected = state.subcategory ? parent?.children?.find(row => row.key === state.subcategory) : parent;
  if (!selected) return {parent, selected, entries: []};
  const detailKeys = selected.entry_keys ? new Set(selected.entry_keys.map(String)) : null;
  const ids = new Set(selected.ids ?? []);
  const entries = (report.entries ?? []).filter(row => {
    if (state.direction && row.direction !== state.direction) return false;
    if (detailKeys) return detailKeys.has(keyOf(row));
    const categoryMatch = state.direction === 'in' || row.analysis_category === selected.key || (state.subcategory
      ? row.category === selected.key || row.subcategory === selected.key
      : row.category === selected.key || row.parent_category === selected.key || row.parent_key === selected.key || selected.children?.some(child => child.key === row.category));
    return ids.has(row.id) && categoryMatch;
  });
  return {parent, selected, entries};
}

export function financeBudgetRows(report) {
  return (report?.rows ?? []).map(row => ({...row,
    target_cents: number(row.target_cents), spent_cents: number(row.spent_cents), pending_cents: number(row.pending_cents),
    remaining_cents: number(row.remaining_cents), label: row.label ?? financeLabel(row.category ?? row.key),
  }));
}

export function createVisualFinance({root, getContext, getSpaces = () => [], api, endpoint, esc, precise, chart, onBeforeChange, onChange, onChanged, onNavigate, onReviewTransaction, onEditBudget, onAddDefinition, onClearAccount, onAsk, notice}) {
  let state = {version: 1, year: null, comparisonYear: null, grain: 'month', basis: 'spending', period: null, direction: null, category: null, subcategory: null, ledger: false, program: null, ledgerSort: 'largest', query: '', page: 1, budgetMonth: null, focusedEntry: null};
  let report = null, envelopes = null, error = null, loading = false, request = 0, ownerContext = null, loadedProjection = null;
  let busyRow = null;
  const pageSize = 50;
  const stats = (label, value, note, className = '') => `<div class="vf-stat ${className}"><span>${esc(label)}</span><strong>${precise(value)}</strong>${note ? `<small>${esc(note)}</small>` : ''}</div>`;
  const button = (label, action, attrs = '', style = '') => `<button type="button" class="vf-button ${style}" data-action="vf-${action}" ${attrs}>${esc(label)}</button>`;
  const attr = value => esc(JSON.stringify(value));
  const contextKey = () => {const c = getContext(); return JSON.stringify([c.space_id, c.account_id, c.from, c.through]);};
  const dates = () => state.period ?? {from: getContext().from, through: getContext().through};
  const projectionKey = () => JSON.stringify([contextKey(), getContext().revision ?? null, dates(), state.year, state.comparisonYear, state.grain, state.basis, state.program, state.budgetMonth]);
  const monthLabel = month => new Intl.DateTimeFormat('en-US', {month: 'long', year: 'numeric', timeZone: 'UTC'}).format(new Date(`${month}-01T12:00:00Z`));
  const rangeLabel = range => !range ? '' : range.from.slice(0, 7) === range.through.slice(0, 7) ? monthLabel(range.from.slice(0, 7)) : `${range.from} – ${range.through}`;
  const categoryLabel = key => report?.category_definitions?.find(row => row.key === key)?.label ?? financeLabel(key);
  const programPicker = () => (report?.program_options ?? []).length ? `<label>Program<select data-vf-input="program"><option value="">All programs</option>${report.program_options.map(p => `<option value="${p.id}" ${Number(state.program) === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>` : '';
  const overviewActions = () => `<div class="vf-quick-actions">${button('Explore analysis', 'navigate', 'data-view="analysis"', 'vf-primary')}${button('Your budgets', 'navigate', 'data-view="budget"')}${button('Review transactions', 'navigate', 'data-view="transactions"')}</div>`;

  function reset() {
    state = {...state, year: null, comparisonYear: null, period: null, direction: null, category: null, subcategory: null, ledger: false, program: null, query: '', page: 1, budgetMonth: null, focusedEntry: null};
    report = envelopes = error = loadedProjection = null; ownerContext = null; request++;
  }

  async function refresh({background = false, isCurrent = () => true} = {}) {
    const c = getContext();
    if (!c.space_id || c.scope === 'both') return false;
    if (background && (loading || busyRow || ownerContext !== contextKey())) return false;
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
    if (!background) {loading = true; error = null;}
    const responses = await Promise.allSettled([api(endpoint(`/analysis?${params}`)), api(endpoint(`/budget-envelopes?${budgetParams}`))]);
    if (current !== request || ownerContext !== contextKey() || requestedProjection !== projectionKey() || (background && selectedState !== JSON.stringify(state)) || !isCurrent()) return false;
    // A failed quiet read retains the displayed, dated snapshot. An explicit
    // reload continues to show a scoped error rather than substituting data.
    if (background && responses.some(response => response.status !== 'fulfilled')) return false;
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
    return `<div class="vf-data-status"><span class="vf-status-dot" aria-hidden="true"></span><span>${status?.complete === true ? 'Recorded activity' : 'Recorded activity · history may be incomplete'}</span><details><summary>Sources &amp; timing</summary><p>${esc(report?.basis_label ?? (state.basis === 'spending' ? 'Purchases when made, including on credit cards. Repayments and investment transfers are separate.' : 'Money actually moving through owned bank accounts.'))}</p>${notes.map(n => `<p>${esc(n)}</p>`).join('')}<p>Amounts remain in their source currency. Missing history is unavailable; a chart difference is not a bank balance.</p></details></div>`;
  }

  function controls() {
    const years = [...new Set([state.year, state.year - 1, state.year - 2, ...(report?.available_years ?? [])])].sort((a, b) => b - a);
    const programs = report?.program_options ?? [];
    return `<div class="vf-controls"><div class="vf-segmented" role="group" aria-label="Comparison interval">${[['year', 'Annual'], ['month', 'Monthly'], ['week', 'Weekly']].map(([k, label]) => button(label, 'grain', `data-grain="${k}" aria-pressed="${state.grain === k}"`)).join('')}</div><label>Year<select data-vf-input="year">${years.map(y => `<option value="${y}" ${y === state.year ? 'selected' : ''}>${y}</option>`).join('')}</select></label><label>Compare with<select data-vf-input="comparison">${years.filter(y => y !== state.year).map(y => `<option value="${y}" ${y === state.comparisonYear ? 'selected' : ''}>${y}</option>`).join('')}</select></label><label>View<select data-vf-input="basis"><option value="spending" ${state.basis === 'spending' ? 'selected' : ''}>Purchases when made</option><option value="cash" ${state.basis === 'cash' ? 'selected' : ''}>Bank cash movements</option></select></label>${programs.length ? programPicker() : ''}${button('Organize categories', 'definitions')}</div>`;
  }

  function breadcrumbs() {
    const {parent, selected} = financeSelectionRows(report, state);
    return `<nav class="vf-breadcrumbs" aria-label="Analysis path">${button('Analysis', 'level', 'data-level="overview"')}<span aria-hidden="true">›</span>${button(rangeLabel(report?.range ?? dates()), 'level', 'data-level="period"')}${parent ? `<span aria-hidden="true">›</span>${button(parent.label ?? categoryLabel(parent.key), 'level', 'data-level="category"')}` : ''}${state.subcategory && selected ? `<span aria-hidden="true">›</span><span>${esc(selected.label)}</span>` : ''}${state.ledger ? '<span aria-hidden="true">›</span><span aria-current="page">Transactions</span>' : ''}</nav>`;
  }

  function slicesHtml(rows, direction) {
    if (!rows?.length) return '<p class="vf-empty">No recorded activity in this period.</p>';
    const sorted = [...rows].sort((a, b) => (b.amount_cents ?? 0) - (a.amount_cents ?? 0));
    const renderRow = row => `<button type="button" data-action="vf-category" data-key="${esc(row.key)}" data-direction="${direction}"><span class="vf-swatch" style="background:${financeColor(row.key)}" aria-hidden="true"></span><span>${esc(row.label ?? categoryLabel(row.key))}</span><strong>${precise(row.amount_cents)}</strong><span aria-hidden="true">›</span></button>`;
    return `<div class="vf-slice-list">${sorted.slice(0, 6).map(renderRow).join('')}</div>${sorted.length > 6 ? `<details class="vf-more-slices"><summary>All ${sorted.length} ${direction === 'in' ? 'sources' : 'categories'}</summary><div class="vf-slice-list">${sorted.slice(6).map(renderRow).join('')}</div></details>` : ''}`;
  }

  function piesHtml() {
    const inflows = report?.inflows ?? [], categories = report?.categories ?? [];
    return `<section class="vf-period-panel"><div class="vf-section-heading"><div><span class="vf-eyebrow">${esc(rangeLabel(report?.range ?? dates()))}</span><h2>Where your money goes</h2></div>${button('View all transactions', 'ledger-all')}</div><div class="vf-pie-pair"><section class="vf-pie-panel"><div class="vf-pie-heading"><h3>Money coming in</h3><strong>${precise(report?.summary?.inflow_cents)}</strong></div><p class="vf-caption">Sources of funds · borrowing and reimbursements stay distinct.</p>${inflows.some(r => r.amount_cents > 0) ? '<div class="vf-chart vf-donut"><canvas id="vf-income-chart" role="img" aria-label="Incoming funds by source; select a source to explore"></canvas></div>' : '<p class="vf-empty">No recorded incoming funds.</p>'}${slicesHtml(inflows, 'in')}</section><section class="vf-pie-panel"><div class="vf-pie-heading"><h3>${state.basis === 'spending' ? 'Money spent' : 'Money leaving accounts'}</h3><strong>${precise(report?.summary?.spending_cents ?? report?.summary?.outflow_cents)}</strong></div><p class="vf-caption">${state.basis === 'spending' ? 'Purchases when made · card and loan repayments excluded.' : 'Actual cash payments · repayments are included.'}</p>${categories.some(r => r.amount_cents > 0) ? '<div class="vf-chart vf-donut"><canvas id="vf-spending-chart" role="img" aria-label="Outgoing categories; select a category to explore"></canvas></div>' : '<p class="vf-empty">No recorded outgoing costs.</p>'}${slicesHtml(categories, 'out')}${categories.some(r => r.amount_cents < 0) ? '<p class="vf-caption">Net-refund categories remain in the list and totals; negative amounts are not pie slices.</p>' : ''}</section></div></section>`;
  }

  function trendHtml() {
    return `<section class="vf-trend-panel"><div class="vf-section-heading"><div><span class="vf-eyebrow">The bigger picture</span><h2>${state.grain === 'year' ? 'Year' : state.grain === 'week' ? 'Week' : 'Month'} by ${state.grain === 'year' ? 'year' : state.grain === 'week' ? 'week' : 'month'}</h2></div><span class="vf-caption">Tap a bar to explore that period</span></div><div class="vf-chart vf-trend"><canvas id="vf-period-chart" role="img" aria-label="Recorded incoming and spending by period, with comparison year; choose a period to drill down"></canvas></div><details class="vf-chart-alternative"><summary>Choose a period from the list</summary><div class="vf-period-list">${(report?.periods ?? []).map((p, i) => button(`${p.label} · ${precise(p.spending_cents)}`, 'period', `data-period-index="${i}" ${p.from && p.through && !p.future ? '' : 'disabled'}`)).join('')}</div></details></section>`;
  }

  function ledgerHtml(entries, heading = 'Transactions') {
    const costMode = state.direction === 'out', signedAmount = row => costMode ? -row.amount_cents : row.amount_cents;
    const filtered = sortFinanceEntries(entries, state.ledgerSort, state.query), pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    state.page = Math.min(state.page, pages);
    const focused = entries.find(r => keyOf(r) === state.focusedEntry);
    const rows = filtered.slice((state.page - 1) * pageSize, state.page * pageSize), options = report?.category_options ?? [];
    const select = row => (!row.editable && !row.attribution_editable) || row.category_editable === false || row.tax_purpose ? `<span>${esc(row.category_label ?? categoryLabel(row.category))}</span>${row.editable ? button('Review', 'review-row', `data-entry="${esc(keyOf(row))}"`) : '<small>Allocated · read only</small>'}` : `<label class="vf-inline-category"><span class="sr-only">Category for ${esc(row.merchant)}</span><select data-vf-category="${esc(keyOf(row))}" ${busyRow === keyOf(row) ? 'disabled' : ''}>${options.map(c => `<option value="${esc(c)}" ${c === row.category ? 'selected' : ''}>${esc(categoryLabel(c))}</option>`).join('')}</select></label>`;
    return `<section class="vf-ledger"><div class="vf-section-heading"><div><span class="vf-eyebrow">${filtered.length} recorded entries</span><h2>${esc(heading)}</h2></div><strong>${precise(entries.reduce((sum, row) => sum + signedAmount(row), 0))}</strong></div><div class="vf-ledger-controls"><label>Find a transaction<input type="search" data-vf-input="query" value="${esc(state.query)}" placeholder="Merchant, date or category"></label><label>Sort<select data-vf-input="sort">${[['largest', 'Biggest first'], ['smallest', 'Smallest first'], ['newest', 'Newest first'], ['oldest', 'Oldest first'], ['merchant', 'Merchant'], ['category', 'Category']].map(([key, text]) => `<option value="${key}" ${state.ledgerSort === key ? 'selected' : ''}>${text}</option>`).join('')}</select></label></div>${focused ? `<div class="vf-selected-purchase"><span>Selected: <strong>${esc(focused.merchant)}</strong> · ${precise(signedAmount(focused))}</span>${button('Ask about this purchase', 'ask-selected')}${button('Clear selection', 'clear-selection')}</div>` : ''}<div class="vf-table-wrap"><table class="vf-table"><caption>${esc(heading)} · categories save individually. ${costMode ? 'Refunds reduce the cost shown.' : 'Positive amounts are money received; negative amounts are money paid.'}</caption><thead><tr><th scope="col">Date</th><th scope="col">Merchant / description</th><th scope="col">Category</th><th scope="col">${costMode ? 'Cost / refund' : 'Amount'}</th><th scope="col"><span class="sr-only">More detail</span></th></tr></thead><tbody>${rows.map(row => `<tr data-entry-key="${esc(keyOf(row))}" class="${keyOf(row) === state.focusedEntry ? 'vf-selected-row' : ''}"><td>${esc(row.date)}</td><td><button type="button" class="vf-merchant-select" data-action="vf-select-row" data-entry="${esc(keyOf(row))}">${esc(row.merchant)}</button><small>${esc(row.program_name ?? row.account_name ?? '')}${row.amount_basis === 'owned_bank_cash' ? ' · Bank cash' : ''}</small></td><td>${select(row)}</td><td class="vf-money">${precise(signedAmount(row))}</td><td>${button(row.editable ? 'Details' : 'View', 'review-row', `data-entry="${esc(keyOf(row))}"`)}${row.attribution_editable || row.editable ? button('Split / program', 'attribution', `data-entry="${esc(keyOf(row))}"`) : ''}</td></tr>`).join('') || '<tr><td colspan="5">No transactions match this view.</td></tr>'}</tbody></table></div><div class="vf-pagination">${button('Previous', 'page', `data-page="${state.page - 1}" ${state.page === 1 ? 'disabled' : ''}`)}<span>Page ${state.page} of ${pages}</span>${button('Next', 'page', `data-page="${state.page + 1}" ${state.page === pages ? 'disabled' : ''}`)}</div></section>`;
  }

  function categoryHtml() {
    const {parent, selected, entries} = financeSelectionRows(report, state);
    if (!selected) return `<section class="vf-empty"><h2>This category changed</h2><p>Its transactions may have moved after a review.</p>${button('Back to the period', 'level', 'data-level="period"')}</section>`;
    if (state.ledger || !selected.children?.length) return ledgerHtml(entries, selected.label ?? categoryLabel(selected.key));
    const children = selected.children;
    return `<section><div class="vf-section-heading"><div><span class="vf-eyebrow">${esc(parent?.label ?? '')}</span><h2>${esc(selected.label)}</h2></div><strong>${precise(selected.amount_cents)}</strong>${button('View all transactions', 'ledger-category')}</div><div class="vf-category-explore"><div class="vf-chart vf-donut"><canvas id="vf-category-chart" role="img" aria-label="Subcategory amounts; select a slice to explore"></canvas></div><div class="vf-slice-list">${children.map(row => `<button type="button" data-action="vf-subcategory" data-key="${esc(row.key)}"><span class="vf-swatch" style="background:${financeColor(row.key)}" aria-hidden="true"></span><span>${esc(row.label)}</span><strong>${precise(row.amount_cents)}</strong><span aria-hidden="true">›</span></button>`).join('')}</div></div></section>`;
  }

  function envelopeCards(limit = null) {
    if (getContext().account_id) return `<div class="vf-empty"><h3>Your budgets cover all accounts</h3><p>A single account cannot show what remains across your whole spending plan.</p>${button('Show all accounts', 'all-accounts')}</div>`;
    const rows = financeBudgetRows(envelopes), selected = limit ? rows.slice(0, limit) : rows;
    if (envelopes?.error) return `<div class="vf-error" role="status">Budgets could not load. ${esc(envelopes.error)} ${button('Try again', 'retry')}</div>`;
    if (!rows.length) return `<div class="vf-empty"><h3>Give each category a plan</h3><p>No reviewed budget targets are available for this month.</p>${button('Set up a budget', 'legacy-budget')}</div>`;
    return `<div class="vf-envelope-grid">${selected.map((row, index) => {
      const status = {draft:'Draft target · needs review',overlapping_targets:'Parent and child targets overlap',needs_opening_balance:'Rollover opening needs review',unresolved_costs:'Some costs still need review',not_budgeted:'Budget not set'}[row.status];
      const remaining = row.remaining_cents, target = row.target_cents, used = target === null || row.spent_cents === null ? null : target === 0 ? (row.spent_cents > 0 ? 100 : 0) : Math.min(100, Math.max(0, row.spent_cents / target * 100));
      return `<button type="button" class="vf-envelope ${remaining !== null && remaining < 0 ? 'vf-over-budget' : ''}" data-action="vf-envelope" data-index="${index}"><span class="vf-envelope-label"><span class="vf-swatch" style="background:${financeColor(row.category ?? row.key)}"></span>${esc(row.label)}<span aria-hidden="true">›</span></span><strong>${precise(remaining === null ? null : Math.abs(remaining))}</strong><span>${remaining === null ? esc(row.uses_parent_budget ? 'Shares the parent budget' : status ?? 'Budget not set / incomplete') : remaining < 0 ? 'over budget' : 'left to spend'}</span><div class="vf-budget-track" ${used === null ? '' : `role="progressbar" aria-label="${esc(row.label)} budget used" aria-valuenow="${Math.round(used)}" aria-valuemin="0" aria-valuemax="100"`}><i style="width:${used ?? 0}%;background:${financeColor(row.category ?? row.key)}"></i></div><small>${precise(row.spent_cents)} spent · ${precise(target)} planned${row.pending_cents ? ` · ${precise(row.pending_cents)} pending` : ''}</small><small>${row.program_label ? esc(row.program_label) + ' · ' : ''}${row.rollover ? 'Rolling fund' : 'Resets monthly'}${row.carryover_cents ? ` · ${precise(row.carryover_cents)} carried forward` : ''}</small></button>`;
    }).join('')}</div>`;
  }

  function render(view) {
    if (getContext().scope === 'both') return '<div class="vf-empty">Choose Personal or one business to explore its money without counting transfers twice.</div>';
    if (error) return `<section class="vf-error" role="alert"><h2>This financial view could not load</h2><p>${esc(error)}</p>${button('Try again', 'retry')}</section>`;
    if (!report) return '<section class="vf-empty" role="status">Loading your financial picture…</section>';
    const covered = coverageHtml();
    if (view === 'budget') return `<div class="vf-finance" aria-busy="${loading}"><div class="vf-section-heading">${programPicker()}<div><span class="vf-eyebrow">Your monthly spending plan</span><h2>What’s left to spend</h2></div><label>Budget month<input type="month" data-vf-input="budget-month" value="${esc(state.budgetMonth)}"></label>${button('Manage targets', 'legacy-budget')}${button('Add category / program', 'definitions')}</div>${envelopeCards()}<p class="vf-caption">Purchases count when made. Card and loan repayments do not consume a purchase category a second time.</p>${covered}</div>`;
    const metrics = `<div class="vf-stat-row">${stats('Money coming in', report.summary?.inflow_cents, 'Sources of funds')}${stats(state.basis === 'spending' ? 'Purchases & costs' : 'Bank cash out', report.summary?.spending_cents ?? report.summary?.outflow_cents, rangeLabel(report.range))}${!getContext().account_id && Number.isSafeInteger(envelopes?.summary?.remaining_cents) ? stats('Budget left this month', envelopes.summary.remaining_cents, monthLabel(state.budgetMonth), 'vf-budget-stat') : ''}</div>`;
    if (view === 'overview') {const budgets = `<section class="vf-budget-preview"><div class="vf-section-heading"><div><span class="vf-eyebrow">${esc(monthLabel(state.budgetMonth))}</span><h2>What’s left in your budgets</h2></div>${programPicker()}${button('All budgets', 'navigate', 'data-view="budget"')}</div>${envelopeCards(6)}</section>`;return `<div class="vf-finance" aria-busy="${loading}">${getContext().scope === 'personal' ? budgets : ''}${metrics}${overviewActions()}${piesHtml()}${getContext().scope === 'personal' ? '' : budgets}${trendHtml()}${covered}</div>`;}
    return `<div class="vf-finance" aria-busy="${loading}">${controls()}${breadcrumbs()}${state.category ? categoryHtml() : state.ledger ? ledgerHtml(report.entries ?? [], 'Period transactions') : `${metrics}${trendHtml()}${piesHtml()}`}${covered}</div>`;
  }

  function draw() {
    if (!report) return;
    const donut = (id, rows, direction, action) => {
      const positive = rows.filter(r => r.amount_cents > 0);
      if (!root.querySelector(`#${id}`) || !positive.length) return;
      chart(id, 'doughnut', positive.map(r => r.label ?? categoryLabel(r.key)), [{data: positive.map(r => r.amount_cents), backgroundColor: positive.map(r => financeColor(r.key)), borderWidth: 3, borderColor: '#fff', hoverOffset: 8}], i => action ? action(positive[i]) : void selectCategory(positive[i].key, direction), {cutout: '68%'});
    };
    donut('vf-income-chart', report.inflows ?? [], 'in');
    donut('vf-spending-chart', report.categories ?? [], 'out');
    const {selected} = financeSelectionRows(report, state);
    if (selected?.children) donut('vf-category-chart', selected.children, state.direction, row => change({subcategory: row.key, ledger: true, page: 1}, false));
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
    if (!await change({period: {from: selected.from, through: selected.through}, direction: null, category: null, subcategory: null, ledger: false, page: 1})) return;
    onNavigate('analysis', false);
  }
  async function selectCategory(category, direction) {
    await change({direction, category, subcategory: null, ledger: false, page: 1}, false);
    onNavigate('analysis', false);
  }

  async function saveAttributedCategory(row, category) {
    const sourceSpace = row.source_space_id ?? getContext().space_id, transaction = row.source_transaction_id ?? row.id;
    const path = `/transactions/${transaction}/attribution`;
    const description = await api(endpoint(path, sourceSpace));
    if (description.version !== row.version) throw new Error('This transaction changed. Refresh before editing it.');
    const detail = description.lines.find(line => line.detail_id === row.attribution_detail_id)
      ?? (description.lines.length === 1 ? description.lines[0] : null);
    if (!detail) throw new Error('Choose the current allocation detail before changing its category.');
    const definitions = await api(endpoint('/attribution/definitions', detail.space_id));
    const definition = definitions.category_definitions.find(d => d.key === category && d.active);
    const parent = definition?.parent_record_id ? definitions.category_definitions.find(d => d.id === definition.parent_record_id) : null;
    const lines = description.lines.map(line => {
      const {detail_id, ...values} = line;
      return line === detail ? {...values, category: parent?.key ?? category, subcategory_record_id: parent ? definition.id : null} : values;
    });
    await api(endpoint(path, sourceSpace), 'PUT', {version: description.version, lines});
  }

  async function openAttribution(row) {
    const sourceSpace = row.source_space_id ?? getContext().space_id, transaction = row.source_transaction_id ?? row.id;
    const sourcePath = `/transactions/${transaction}/attribution`;
    const description = await api(endpoint(sourcePath, sourceSpace));
    const spaces = description.target_spaces ?? getSpaces();
    const catalogs = new Map();
    for (const id of new Set(description.lines.map(line => line.space_id))) catalogs.set(id, await api(endpoint('/attribution/definitions', id)));
    let lines = description.lines.map(line => ({...line}));
    const modal = document.createElement('dialog'); modal.className = 'vf-attribution-dialog';
    modal.setAttribute('aria-label', 'Assign expense to a category, program or organization');
    root.append(modal);
    const currencyInput = cents => `${cents < 0 ? '-' : ''}${Math.floor(Math.abs(cents) / 100)}.${String(Math.abs(cents) % 100).padStart(2, '0')}`;
    function readLines() {
      return [...modal.querySelectorAll('[data-attribution-line]')].map(el => {
        const amount = el.querySelector('[name="amount"]').value;
        if (!/^-?\d+(\.\d{1,2})?$/.test(amount)) throw new Error('Use dollars with at most two decimal places.');
        const sign = amount.startsWith('-') ? -1 : 1, [whole, decimal = ''] = amount.replace('-', '').split('.');
        const cents = sign * (Number(whole) * 100 + Number(decimal.padEnd(2, '0')));
        if (!Number.isSafeInteger(cents)) throw new Error('Amount is too large.');
        const spaceId = Number(el.querySelector('[name="space"]').value), categoryKey = el.querySelector('[name="category"]').value;
        const catalog = catalogs.get(spaceId), childId = Number(el.querySelector('[name="subcategory"]').value) || null;
        const child = childId ? catalog.category_definitions.find(d => d.id === childId) : null;
        const parent = child ? catalog.category_definitions.find(d => d.id === child.parent_record_id) : null;
        return {space_id: spaceId, amount_cents: cents, category: parent?.key ?? categoryKey, subcategory_record_id: childId,
          program_record_id: Number(el.querySelector('[name="program"]').value) || null,
          settlement_status: el.querySelector('[name="settlement"]').value, settlement_note: el.querySelector('[name="note"]').value || null};
      });
    }
    function paint() {
      modal.innerHTML = `<form><header><div><h2>Who was this expense for?</h2><p>${esc(row.merchant)} · original source ${precise(description.source_amount_cents)}</p></div><button type="button" data-attribution-close aria-label="Close allocation editor">×</button></header><p class="vf-caption">Assign the original amount once. Keep negative amounts for purchases. This changes cost attribution, not bank cash or a reimbursement payment.</p><div class="vf-attribution-lines">${lines.map((line, index) => {
        const catalog = catalogs.get(line.space_id), definitions = catalog.category_definitions.filter(d => d.active), parents = definitions.filter(d => !d.parent_record_id);
        const children = definitions.filter(d => d.parent_record_id && parents.find(p => p.id === d.parent_record_id)?.key === line.category);
        return `<fieldset data-attribution-line="${index}"><legend>Allocation ${index + 1}</legend><label>Organization / household<select name="space">${spaces.map(s => `<option value="${s.id}" ${s.id === line.space_id ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select></label><label>Signed amount ($)<input name="amount" inputmode="decimal" value="${currencyInput(line.amount_cents)}" required></label><label>Category<select name="category">${catalog.categories.filter(c => !definitions.find(d => d.key === c)?.parent_record_id).map(c => `<option value="${esc(c)}" ${line.category === c ? 'selected' : ''}>${esc(definitions.find(d => d.key === c)?.name ?? financeLabel(c))}</option>`).join('')}</select></label><label>Subcategory<select name="subcategory"><option value="">No subcategory</option>${children.map(c => `<option value="${c.id}" ${c.id === line.subcategory_record_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label><label>Program<select name="program"><option value="">General / unassigned</option>${catalog.programs.filter(p => p.active).map(p => `<option value="${p.id}" ${p.id === line.program_record_id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label><label>Reimbursement status<select name="settlement">${[['none', 'Not specified'], ['open', 'Awaiting reimbursement'], ['reimbursed', 'Already reimbursed']].map(([key, name]) => `<option value="${key}" ${key === line.settlement_status ? 'selected' : ''}>${name}</option>`).join('')}</select></label><label class="vf-wide-field">Note<input name="note" value="${esc(line.settlement_note ?? '')}" maxlength="160"></label><div class="vf-attribution-actions"><button type="button" data-attribution-remainder="${index}">Use remaining amount</button>${lines.length > 1 ? `<button type="button" data-attribution-remove="${index}">Remove allocation</button>` : ''}</div></fieldset>`;
      }).join('')}</div><p data-attribution-error role="alert" hidden></p><footer><button type="button" data-attribution-add ${lines.length >= 50 ? 'disabled' : ''}>Add split</button><button type="button" data-attribution-close>Cancel</button><button type="submit" class="tr-primary">Save attribution</button></footer></form>`;
    }
    function failure(e) {const el = modal.querySelector('[data-attribution-error]'); el.hidden = false; el.textContent = e.message;}
    modal.addEventListener('click', event => {
      const node = event.target.closest('button'); if (!node) return;
      if (node.hasAttribute('data-attribution-close')) {modal.close(); return;}
      if (node.hasAttribute('data-attribution-add') || node.hasAttribute('data-attribution-remove') || node.hasAttribute('data-attribution-remainder')) {
        try {
          lines = readLines();
          if (node.hasAttribute('data-attribution-add')) lines.push({...lines[0], amount_cents: 0, settlement_status: 'none', settlement_note: null});
          if (node.hasAttribute('data-attribution-remove')) lines.splice(Number(node.dataset.attributionRemove), 1);
          if (node.hasAttribute('data-attribution-remainder')) {const i = Number(node.dataset.attributionRemainder); lines[i].amount_cents = description.source_amount_cents - lines.reduce((n, line, j) => n + (j === i ? 0 : line.amount_cents), 0);}
          paint();
        } catch (e) {failure(e);}
      }
    });
    modal.addEventListener('change', async event => {
      if (!['space', 'category'].includes(event.target.name)) return;
      try {
        if (event.target.name === 'space' && !catalogs.has(Number(event.target.value))) catalogs.set(Number(event.target.value), await api(endpoint('/attribution/definitions', Number(event.target.value))));
        lines = readLines(); const i = Number(event.target.closest('[data-attribution-line]').dataset.attributionLine);
        if (event.target.name === 'space') {
          const id = lines[i].space_id;
          if (!catalogs.has(id)) catalogs.set(id, await api(endpoint('/attribution/definitions', id)));
          lines[i] = {...lines[i], category: 'uncategorized', subcategory_record_id: null, program_record_id: null};
        } else lines[i].subcategory_record_id = null;
        paint();
      } catch (e) {failure(e);}
    });
    modal.addEventListener('submit', async event => {
      event.preventDefault(); const submit = modal.querySelector('[type="submit"]'); submit.disabled = true;
      try {
        lines = readLines();
        if (lines.reduce((n, line) => n + line.amount_cents, 0) !== description.source_amount_cents) throw new Error('The allocations must total the original source amount.');
        await api(endpoint(sourcePath, sourceSpace), 'PUT', {version: description.version, lines}); modal.close(); notice('Attribution saved. Original bank cash is unchanged.'); await onChanged();
      } catch (e) {failure(e); submit.disabled = false;}
    });
    modal.addEventListener('close', () => modal.remove(), {once: true});
    paint(); modal.showModal();
  }

  async function handleClick(target) {
    const action = target.dataset.action;
    if (!action?.startsWith('vf-')) return false;
    if (action === 'vf-navigate') onNavigate(target.dataset.view);
    else if (action === 'vf-grain') await change({grain: target.dataset.grain, category: null, subcategory: null, ledger: false});
    else if (action === 'vf-period') await selectPeriod(Number(target.dataset.periodIndex));
    else if (action === 'vf-category') await selectCategory(target.dataset.key, target.dataset.direction);
    else if (action === 'vf-subcategory') await change({subcategory: target.dataset.key, ledger: true, page: 1}, false);
    else if (action === 'vf-ledger-category') await change({ledger: true, page: 1}, false);
    else if (action === 'vf-ledger-all') {await change({category: null, subcategory: null, ledger: true, direction: null, page: 1}, false); onNavigate('analysis', false);}
    else if (action === 'vf-page') await change({page: Number(target.dataset.page)}, false, false);
    else if (action === 'vf-level') await change(target.dataset.level === 'category' ? {subcategory: null, ledger: false} : target.dataset.level === 'period' ? {direction: null, category: null, subcategory: null, ledger: false} : {period: null, direction: null, category: null, subcategory: null, ledger: false}, target.dataset.level === 'overview');
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
      if (row) {
        const monthEnd = envelopes?.actual_through ?? envelopes?.range?.through ?? new Date(Date.UTC(Number(state.budgetMonth.slice(0, 4)), Number(state.budgetMonth.slice(5)), 0)).toISOString().slice(0, 10);
        const today = report?.today ?? getContext().today ?? getContext().through;
        if (`${state.budgetMonth}-01` > today) {notice('This is a future budget. Open Plan or Manage targets to review it; there is no actual spending period yet.');return true;}
        const through = monthEnd < today ? monthEnd : today;
        if (await change({period: {from: `${state.budgetMonth}-01`, through}, direction: 'out', category: row.parent_key ?? row.category ?? row.key, subcategory: row.parent_key ? row.category : null, ledger: true, page: 1})) onNavigate('analysis', false);
      }
    } else if (action === 'vf-review-row') {
      const row = report?.entries?.find(r => keyOf(r) === target.dataset.entry);
      if (row) {state.focusedEntry = keyOf(row); if (row.attribution_detail_id && row.attribution_editable) await openAttribution(row); else onReviewTransaction(row);}
    }
    return true;
  }

  async function handleInput(target) {
    if (target.dataset.vfCategory) {
      const row = report?.entries?.find(r => keyOf(r) === target.dataset.vfCategory);
      if ((!row?.editable && !row?.attribution_editable) || row.tax_purpose || row.category_editable === false) return;
      const selected = target.value;
      busyRow = keyOf(row); target.disabled = true;
      try {
        const definition = report.category_definitions?.find(d => d.key === selected);
        if (row.attribution_detail_id || row.detail_id || definition?.parent_key || !row.editable) await saveAttributedCategory(row, selected);
        else await api(endpoint(`/transactions/${row.id}`), 'PATCH', {version: row.version, category: selected, flow: row.flow, face_punched: row.face_punched, bullshit_spending: row.bullshit_spending, learn: false});
        notice('Category saved.'); await onChanged();
      } catch (e) { target.value = row.category; notice(e.message); }
      finally {busyRow = null; if (target.isConnected) target.disabled = false;}
      return;
    }
    const input = target.dataset.vfInput;
    if (input === 'year') {const y = Number(target.value), through = getContext().through; await change({year: y, comparisonYear: y - 1, period: {from: `${y}-01-01`, through: Number(through.slice(0, 4)) === y ? through : `${y}-12-31`}, category: null, subcategory: null, ledger: false});}
    else if (input === 'comparison') await change({comparisonYear: Number(target.value)});
    else if (input === 'basis') await change({basis: target.value, category: null, subcategory: null, ledger: false});
    else if (input === 'program') await change({program: target.value ? Number(target.value) : null, category: null, subcategory: null, ledger: false});
    else if (input === 'budget-month') await change({budgetMonth: target.value});
    else if (input === 'sort') await change({ledgerSort: target.value, page: 1}, false, false);
    else if (input === 'query') {state.query = target.value; state.page = 1; onChange(); const inputNode = root.querySelector('[data-vf-input="query"]'); inputNode?.focus({preventScroll: true}); inputNode?.setSelectionRange(state.query.length, state.query.length);}
  }

  function assistantContext() {
    const selection = financeSelectionRows(report, state), scopedEntries = state.category ? selection.entries : state.ledger ? report?.entries ?? [] : [], focused = state.focusedEntry ? (report?.entries ?? []).find(row => keyOf(row) === state.focusedEntry) : null, entries = focused ? [focused] : scopedEntries;
    return {...dates(), account_id: getContext().account_id ?? null, program_id: state.program,
      level: state.ledger ? 'transactions' : state.category ? 'category' : state.period ? 'period' : 'overview', direction: state.direction,
      category: state.category, subcategory: state.subcategory, basis: state.basis,
      transaction_ids: [...new Set(entries.map(r => r.source_transaction_id ?? r.id))].slice(0, 100), transaction_versions: Object.fromEntries(entries.slice(0, 100).map(r => [r.source_transaction_id ?? r.id, r.version])), selection_truncated: entries.length > 100, focused_transaction: !!focused,
    };
  }

  return {render, draw, refresh, reset, handleClick, handleInput, getAssistantContext: assistantContext,
    capture: () => clone(state),
    restore(value) {if (value?.version === 1) state = {...state, ...clone(value)};},
    getReport: () => report,
    hasCurrentSnapshot: () => !!report && !!envelopes && !error && !envelopes.error && !loading && loadedProjection === projectionKey(),
    isBusy: () => loading || busyRow !== null,
  };
}
