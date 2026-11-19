import './budget-detail.css';

const cents = value => Number.isSafeInteger(value) ? value : null;
const reasons = {
  cash_not_recorded: 'Cash spending is not fully recorded.', category_review_pending: 'Some spending still needs the right category.',
  missing_history: 'This month’s bank history is incomplete.', future_period: 'This is a future plan; actual spending has not happened yet.',
  partial_period: 'This allowance covers part of the month.', partial: 'This allowance covers part of the month.',
  tax_split_pending: 'A tax payment still needs its expense split.', overlapping_targets: 'Category allowances overlap.',
  cash_reports_overlap: 'Cash reports overlap.', needs_opening_balance: 'The opening carry-forward amount needs review.',
  needs_prior_month_close: 'The previous month is still open.', multiple_funds: 'More than one reserve is mapped here; review the plans separately.',
};

const recordedBank = row => Object.hasOwn(row, 'bank_spent_cents') ? cents(row.bank_spent_cents)
  : cents(row.spent_cents) === null ? null : row.spent_cents - (cents(row.reported_cash_spent_cents) ?? 0);

export function budgetDetailPreview(row, values = {}) {
  if (row.mode === 'fund' && row.fund) {
    const target = cents(values.target_cents) ?? row.fund.target_cents;
    const monthly = cents(values.monthly_cents) ?? row.fund.monthly_cents;
    const saved = row.fund.saved_cents;
    return {mode: 'fund', target_cents: target, monthly_cents: monthly, saved_cents: saved,
      still_to_save_cents: Math.max(0, target - saved), remaining_cents: null,
      months_needed: monthly > 0 ? Math.ceil(Math.max(0, target - saved) / monthly) : null};
  }
  const target = cents(values.monthly_cents) ?? cents(row.target_cents) ?? cents(row.planned_cents);
  const bank = recordedBank(row);
  const cash = row.reported_cash_spent_cents === undefined ? 0 : cents(row.reported_cash_spent_cents);
  const pending = cents(row.pending_cents) ?? 0;
  const carry = row.rollover ? cents(row.carryover_cents) : 0;
  const blocked = row.cash_spending_pending || row.category_review_pending || row.partial_period
    || (row.reasons || []).some(reason => reason in reasons);
  return {mode: 'spending', target_cents: target, bank_spent_cents: bank, reported_cash_spent_cents: cash,
    estimated_remaining_cents: target === null || bank === null || cash === null || carry === null || row.partial_period || (row.reasons || []).some(reason => ['missing_history', 'future_period', 'cash_reports_overlap', 'overlapping_targets', 'tax_split_pending'].includes(reason)) ? null : target + carry - bank - cash - pending,
    remaining_cents: blocked || target === null || bank === null || cash === null || carry === null ? null : target + carry - bank - cash - pending};
}

export function budgetInputCents(value) {
  const text = String(value).trim();
  if (!/^(?:\d{1,9}|\d{1,3}(?:,\d{3}){1,2})(?:\.\d{0,2})?$/.test(text)) return null;
  const amount = Math.round(Number(text.replaceAll(',', '')) * 100);
  return amount <= 99999999900 ? amount : null;
}

/** Open the known envelope immediately; only its exact editable native record is fetched. */
export function createBudgetDetail({root, api, endpoint, getContext, esc, precise, onChanged = () => {}, notice = () => {}, onEntries = () => {}}) {
  if (!root) return null;
  const host = document.createElement('div');
  host.innerHTML = '<dialog class="tr-budget-detail" aria-labelledby="tr-budget-detail-title"><div data-budget-detail-body></div></dialog>';
  root.append(host);
  const dialog = host.querySelector('dialog'), body = host.querySelector('[data-budget-detail-body]');
  let row, envelopes, spaceId, initiatingContext, record = null, undo = null, busy = false, generation = 0, dirty = false, readError = null;
  let values = {};
  const sourceId = () => row.record_id ?? row.record_ids?.[0] ?? null;
  const contextKey = () => {const context = getContext() || {};return JSON.stringify([context.space_id, context.scope, context.account_id ?? null, context.month ?? context.budget_month ?? null, context.program_id ?? null]);};
  const close = () => {if(dialog.open)dialog.close();generation++;};
  const current = token => token === generation && contextKey() === initiatingContext && dialog.open;
  const money = amount => cents(amount) === null ? 'Not yet known' : precise(amount);
  const url = suffix => endpoint(suffix, spaceId);
  const refreshAfterSave = token => {void Promise.resolve().then(() => onChanged()).catch(() => {if(current(token))notice('The plan saved. Reopen the budget if its totals have not refreshed yet.');});};
  const formatInput = value => new Intl.NumberFormat('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value / 100);
  const field = (label, name, value) => `<div class="tr-budget-field"><label for="tr-budget-${name}">${esc(label)}</label><span class="tr-budget-stepper">${name === 'monthly_cents' ? '<button type="button" data-budget-step="-1" aria-label="Decrease monthly budget by $10">−</button>' : ''}<span class="tr-budget-dollar">$<input id="tr-budget-${name}" type="text" inputmode="decimal" autocomplete="off" data-budget-money="${name}" value="${formatInput(value)}"></span>${name === 'monthly_cents' ? '<button type="button" data-budget-step="1" aria-label="Increase monthly budget by $10">+</button>' : ''}</span></div>`;

  function previewHtml() {
    const result = budgetDetailPreview(row, values);
    if (result.mode === 'fund') return `<strong>${money(result.saved_cents)} saved <small>of ${money(result.target_cents)}</small></strong><p>${money(result.still_to_save_cents)} to save · ${money(result.monthly_cents)}/month</p>`;
    const estimate = result.remaining_cents === null && result.estimated_remaining_cents !== null;
    const left = result.remaining_cents ?? result.estimated_remaining_cents;
    const split = (row.reasons || []).includes('needs_split');
    const spent = result.bank_spent_cents === null || result.reported_cash_spent_cents === null ? null : result.bank_spent_cents + result.reported_cash_spent_cents;
    const used = spent === null || result.target_cents === null || split && spent <= 0 ? null : result.target_cents === 0 ? (spent > 0 ? 100 : 0) : Math.min(100, Math.max(0, spent / result.target_cents * 100));
    const bar = used === null ? '' : `<div class="tr-budget-usage" role="progressbar" aria-label="Budget spent" aria-valuenow="${Math.round(used)}" aria-valuemin="0" aria-valuemax="100"><i style="width:${used}%"></i></div>`;
    return `<div class="tr-budget-detail-trio"><span><small>Budget</small><b>${money(result.target_cents)}</b></span><span><small>Spent</small><b>${split && result.bank_spent_cents === 0 ? 'Needs split' : money(spent) + (split && spent > 0 ? '+' : '')}</b></span><span><small>Left${estimate ? ' · est.' : ''}</small><b>${split && left !== null && left >= 0 ? '≤ ' : ''}${money(left)}</b></span></div>${bar}`;
  }
  function refreshPreview() {
    const preview = body.querySelector('[data-budget-preview]');
    if (preview) preview.innerHTML = previewHtml();
    const minus = body.querySelector('[data-budget-step="-1"]');
    const plus = body.querySelector('[data-budget-step="1"]');
    if (minus) minus.disabled = values.monthly_cents === 0;
    if (plus) plus.disabled = values.monthly_cents >= 99999999900;

  }
  function render() {
    if (row.mode === 'income_percentage') {
      const left = cents(row.remaining_cents) ?? cents(row.estimated_remaining_cents);
      body.innerHTML = `<header><div><span>Income-based budget · ${esc(envelopes?.month || '')}</span><h2 id="tr-budget-detail-title">${esc(row.label)}</h2></div><button type="button" data-budget-close aria-label="Close budget details">×</button></header><section class="tr-budget-preview"><div class="tr-budget-detail-trio"><span><small>Budget · est.</small><b>${money(row.planned_cents)}</b></span><span><small>Spent</small><b>${money(row.spent_cents)}</b></span><span><small>Left · est.</small><b>${money(left)}</b></span></div></section><p>${Number.isSafeInteger(row.rate_bps) ? esc(row.rate_bps / 100) + '% of recorded household take-home.' : 'The income rule needs review.'}</p><details class="tr-budget-evidence"><summary>Income and spending details</summary><p>${money(row.bank_receipt_basis_cents)} recorded take-home so far. This updates with receipts; borrowing and transfers are excluded.</p>${(row.source_notes || []).map(note => `<p>${esc(note)}</p>`).join('')}${row.reasons?.includes('cash_income_unrecorded') ? '<p>Cash income is not fully recorded, so the budget is an estimate.</p>' : ''}</details><div class="tr-budget-detail-actions"><button type="button" data-budget-entries>Transactions</button></div>`;
      return;
    }
    const fund = row.mode === 'fund';
    const plan = cents(values.monthly_cents) ?? 0;
    const blocked = !record && !!sourceId() || busy || !!readError || row.status === 'multiple_funds';
    const explanation = [...new Set([...(row.reasons || []), ...(envelopes?.future ? ['future_period'] : [])])].map(reason => reasons[reason]).filter(Boolean);
    const dates = record?.kind === 'budget' ? `${record.data.effective_on} – ${record.data.ends_on || 'ongoing'}` : row.plan_period ? `${row.plan_period.from} – ${row.plan_period.through}` : envelopes?.month || '';
    body.innerHTML = `<header><div><span>${esc(envelopes?.month || '')}</span><h2 id="tr-budget-detail-title">${esc(row.label)}</h2></div><button type="button" data-budget-close aria-label="Close budget details">×</button></header>
      <section class="tr-budget-preview" data-budget-preview aria-live="polite">${previewHtml()}</section>
      <details class="tr-budget-evidence"><summary>Details</summary><dl><div><dt>Bank-recorded category spending</dt><dd>${money(recordedBank(row))}</dd></div>${row.reported_cash_as_of ? `<div><dt>Reported cash through ${esc(row.reported_cash_as_of)}</dt><dd>${money(row.reported_cash_spent_cents)}</dd></div>` : ''}<div><dt>Pending purchases</dt><dd>${money(row.pending_cents ?? 0)}</dd></div>${dates ? `<div><dt>Plan dates</dt><dd>${esc(dates)}</dd></div>` : ''}</dl>
      ${fund ? '<p class="tr-budget-note">Category spending is shown for context. It does not prove money was withdrawn from this fund. Saved progress changes only when actual funding is recorded.</p>' : ''}
      ${explanation.length ? `<ul class="tr-budget-reasons">${explanation.map(reason => `<li>${esc(reason)}</li>`).join('')}</ul>` : ''}
      ${(row.source_notes || []).map(note => `<p>${esc(note)}</p>`).join('')}${row.reported_cash_as_of ? '<p>Cash report is budget only; transaction charts exclude it.</p>' : ''}${row.default_from ? `<p>Defaulted from the previous monthly budget. Last saved source: ${esc(row.default_from.source_month)}.</p>` : ''}</details>
      <form data-budget-form>
      ${!fund && cents(row.suggestion?.amount_cents) !== null ? `<p class="tr-budget-suggestion">Last month ${money(row.suggestion.amount_cents)}${row.suggestion.partial ? ' · partial' : ''} <button type="button" data-budget-suggestion>Use amount</button></p>` : ''}
      ${field(fund ? 'Monthly contribution' : row.partial_period ? 'Allowance for the shown dates' : 'Monthly budget', 'monthly_cents', plan)}
      ${fund && row.fund ? field('Fund target', 'target_cents', values.target_cents ?? row.fund.target_cents) + `<label>Target date<input type="date" data-budget-target-date value="${esc(values.target_on || row.fund.target_on)}"></label>` : ''}
      ${!record && sourceId() && !readError ? '<p role="status">Loading this plan’s editing details…</p>' : ''}
      ${readError ? `<p role="alert">${esc(readError)}</p><button type="button" data-budget-retry>Retry plan details</button>` : ''}
      <div class="tr-budget-detail-actions"><button type="submit" ${blocked ? 'disabled' : ''}>${busy ? 'Saving…' : 'Save'}</button>${undo ? '<button type="button" data-budget-undo>Undo last edit</button>' : ''}<button type="button" data-budget-entries>Transactions</button></div></form>`;
    refreshPreview();
  }
  async function loadRecord(token) {
    if (!sourceId() || row.mode === 'income_percentage') return;
    try {
      const result = await api(url(`/budget-records/${sourceId()}${row.mode === 'fund' || !envelopes.month ? '' : `?month=${encodeURIComponent(envelopes.month)}`}`));
      if (!current(token)) return;
      record = result.record; readError = null;
      row.plan_context = result.plan_context ?? row.plan_context;
      if (record.kind === 'goal') row.fund = {...row.fund, ...record.data, record_id: record.id, version: record.version};
      else {
        row.cash_spending_pending = !!(result.aggregate?.cash_spending_pending ?? record.data.cash_spending_pending) || !!row.default_from && (row.cash_spending_pending || !!record.data.reported_cash_as_of);
        row.category_review_pending = !!(result.aggregate?.category_review_pending ?? record.data.category_review_pending) || (row.reasons || []).includes('needs_split');
        row.reasons = (row.reasons || []).filter(reason => !['cash_not_recorded', 'category_review_pending'].includes(reason));
        if (row.cash_spending_pending) row.reasons.push('cash_not_recorded');
        if (row.category_review_pending) row.reasons.push('category_review_pending');
        if (record.data.reported_cash_as_of?.slice(0, 7) === envelopes.month) {
          row.reported_cash_as_of = record.data.reported_cash_as_of;
          row.reported_cash_spent_cents = record.data.reported_cash_spent_cents;
        }
      }
      if (!dirty) values = {monthly_cents: result.aggregate?.monthly_cents ?? record.data.monthly_cents, ...(record.kind === 'goal' ? {target_cents: record.data.target_cents, target_on: record.data.target_on} : {})};
      render();
    } catch (error) {
      if (!current(token)) return;
      readError = error.message || 'Plan details could not load.'; render();
    }
  }
  function open(selected, data) {
    row = structuredClone(selected); envelopes = structuredClone(data || {}); spaceId = Number(getContext()?.space_id);
    if (!spaceId) return;
    initiatingContext = contextKey();
    if (row.uses_parent_budget) row = structuredClone((envelopes.rows || []).find(candidate => candidate.key === row.uses_parent_budget) || row);
    const token = ++generation;
    record = undo = readError = null; busy = dirty = false;
    if (envelopes.future && !(row.reasons || []).includes('future_period')) row.reasons = [...(row.reasons || []), 'future_period'];
    values = {monthly_cents: cents(row.fund?.monthly_cents) ?? cents(row.target_cents) ?? cents(row.planned_cents) ?? 0,
      ...(row.fund ? {target_cents: row.fund.target_cents, target_on: row.fund.target_on} : {})};
    if (!dialog.open) dialog.showModal();
    render(); void loadRecord(token);
  }
  async function save() {
    if (!current(generation)) {close();return;}
    if (busy || readError || (!record && sourceId())) return;
    const token = generation;
    const fields = {monthly_cents: values.monthly_cents, ...(row.mode === 'fund' ? {target_cents: values.target_cents, target_on: values.target_on} : {})};
    if (Object.entries(fields).some(([key, value]) => key !== 'target_on' && (cents(value) === null || value < 0))) {notice('Enter a valid dollar amount.');return;}
    busy = true; render();
    try {
      let result;
      if (record) result = await api(url(`/budget-records/${record.id}`), 'PATCH', {version: record.version, changes: fields, ...(record.kind === 'budget' && row.plan_context ? {month: envelopes.month, plan_context: row.plan_context} : {})});
      else {
        const month = envelopes.month;
        if (!/^\d{4}-\d{2}$/.test(month || '') || row.mode === 'fund' || row.record_ids?.length > 1) throw new Error('Open the individual plan before editing.');
        const end = new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5)), 0)).toISOString().slice(0, 10);
        const data = {category: row.category, monthly_cents: fields.monthly_cents, purpose: 'spending', effective_on: month + '-01', ends_on: end,
          reviewed: true, program_record_id: row.program_id ?? null, cash_spending_pending: !!row.cash_spending_pending, category_review_pending: !!row.category_review_pending};
        const created = await api(url('/records'), 'POST', {kind: 'budget', name: `${row.label} · ${month}`, data});
        result = {record: created, undo: {created: true, version: created.version}};
      }
      if (!current(token)) return;
      record = result.record; row.record_id = record.id; row.record_ids = [record.id]; undo = result.undo;
      row.plan_context = result.plan_context ?? row.plan_context; row.default_from = null;
      row.target_cents = record.kind === 'budget' ? record.data.monthly_cents : null;
      row.planned_cents = row.target_cents;
      if (record.kind === 'goal') row.fund = {...row.fund, ...record.data, record_id: record.id, version: record.version};
      refreshAfterSave(token);
    } catch (error) {if (current(token)) notice(error.message || 'The plan could not save.');}
    finally {if (current(token)) {busy = false; render();}}
  }
  async function undoEdit() {
    if (!current(generation)) {close();return;}
    if (!undo || !record || busy) return;
    const token = generation; busy = true; render();
    try {
      if (undo.created) {
        await api(url(`/records/${record.id}`), 'DELETE', {version: undo.version});
        if (!current(token)) return;
        undo = null; notice('Removed the new allowance.'); dialog.close(); generation++; refreshAfterSave(token); return;
      }
      const result = await api(url(`/budget-records/${record.id}/undo`), 'POST', undo);
      if (!current(token)) return;
      if (result.removed_override) {undo = null;notice('Restored the previous monthly default.');dialog.close();generation++;refreshAfterSave(token);return;}
      record = result.record; undo = null; values = {monthly_cents: record.data.monthly_cents, ...(record.kind === 'goal' ? {target_cents: record.data.target_cents, target_on: record.data.target_on} : {})};
      row.plan_context = result.plan_context ?? row.plan_context;
      row.target_cents = record.kind === 'budget' ? record.data.monthly_cents : null; row.planned_cents = row.target_cents;
      if (record.kind === 'goal') row.fund = {...row.fund, ...record.data};
      notice('Undid the plan edit.'); refreshAfterSave(token);
    } catch (error) {if (current(token)) notice(error.message || 'This edit could not be undone.');}
    finally {if (current(token)) {busy = false; render();}}
  }
  body.addEventListener('input', event => {
    const name = event.target.dataset.budgetMoney;
    if (name) {values[name] = budgetInputCents(event.target.value); dirty = true; refreshPreview();}
    if (event.target.matches('[data-budget-target-date]')) {values.target_on = event.target.value;dirty = true;}
  });
  body.addEventListener('focusout', event => {
    const name = event.target.dataset.budgetMoney;
    if (name && cents(values[name]) !== null) event.target.value = formatInput(values[name]);
  });
  body.addEventListener('submit', event => {event.preventDefault();void save();});
  body.addEventListener('click', event => {
    const step = event.target.closest('[data-budget-step]');
    if (step) {
      if (busy) return;
      if (cents(values.monthly_cents) === null) {notice('Enter a valid budget amount.');return;}
      values.monthly_cents = Math.max(0, Math.min(99999999900, values.monthly_cents + Number(step.dataset.budgetStep) * 1000));
      dirty = true;
      body.querySelector('[data-budget-money="monthly_cents"]').value = formatInput(values.monthly_cents);
      refreshPreview();
    }
    if (event.target.closest('[data-budget-suggestion]') && cents(row.suggestion?.amount_cents) !== null) {values.monthly_cents = Math.max(0, row.suggestion.amount_cents);dirty = true;render();}
    if (event.target.closest('[data-budget-close]')) {dialog.close();generation++;}
    if (event.target.closest('[data-budget-retry]')) {readError = null;void loadRecord(generation);}
    if (event.target.closest('[data-budget-undo]')) void undoEdit();
    if (event.target.closest('[data-budget-entries]')) {dialog.close();generation++;onEntries(row, envelopes);}
  });
  dialog.addEventListener('cancel', () => {generation++;});
  window.addEventListener('popstate', close);
  return {open, close, contextChanged: () => {if(dialog.open && contextKey() !== initiatingContext)close();}, destroy: () => {close();window.removeEventListener('popstate', close);host.remove();}};
}
