const int = value => Number.isSafeInteger(value) ? value : null;
const label = value => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
export const reviewRowKey = row => `${row.source_transaction_id ?? row.id}:${row.version}:${row.attribution_detail_id ?? 0}`;

export function transactionIdentityHtml(row, {esc}) {
  const source = row.source_identity;
  if (!source) return '';
  const paidBy = source.paid_by_label ? `<span>${esc(source.paid_by_label)}</span>` : '';
  if (!source.authorized_source) return `<div class="tr-transaction-source">${paidBy}</div>`;
  const account = source.account_name ? `<span>${source.account_kind === 'credit' ? 'Credit account' : 'Account'}: ${esc(source.account_name)}</span>` : '<span>Account not recorded</span>';
  const card = source.card_evidence_status === 'verified' && /^\d{4}$/.test(source.card_last4 ?? '')
    ? `${source.card_type === 'debit' ? 'Debit card' : source.card_type === 'credit' ? 'Credit card' : 'Physical card'} ••••${esc(source.card_last4)}${source.card_label ? ` · ${esc(source.card_label)}` : ''}`
    : source.card_evidence_status === 'needs_review' ? 'Physical card evidence needs review' : 'Physical card not recorded';
  return `<div class="tr-transaction-source">${paidBy}${account}<span>${card}</span></div>`;
}

/** The legacy bank row can contain one or several native economic detail lines. */
export function normalizedReviewRow(row, definitions = []) {
  const details = row.allocation_details ?? [];
  const current = {...row, source_space_id: row.source_space_id ?? row.source_identity?.source_space_id,
    attribution_editable: row.attribution_editable ?? row.source_identity?.authorized_source === true};
  if (row.attribution_detail_id || details.length !== 1) return current;
  const detail = details[0], leaf = definitions.find(d => (d.record_id ?? d.id) === detail.subcategory_record_id);
  return {...current, source_transaction_id: row.id,
    attribution_detail_id: detail.id ?? detail.detail_id, program_record_id: detail.program_record_id,
    subcategory_record_id: detail.subcategory_record_id, category: leaf?.key ?? detail.category};
}

/** Explicit owner choice only; the proposal itself never writes or learns a rule. */
export async function saveTransactionCategory({row, category, targetSpace, categoryDefinitions = [], api, endpoint, isCurrent = () => true}) {
  if (!isCurrent()) throw Error('Your financial view changed. Review this purchase again.');
  const sourceSpace = row.source_space_id ?? targetSpace, id = row.source_transaction_id ?? row.id;
  const definition = categoryDefinitions.find(d => d.key === category);
  if (row.attribution_detail_id || row.allocation_details?.length || definition?.parent_key || !row.editable) {
    const path = `/transactions/${id}/attribution`, description = await api(endpoint(path, sourceSpace));
    if (description.version !== row.version) throw Error('This transaction changed. Refresh before confirming.');
    const eligible = description.lines.filter(line => line.space_id === targetSpace);
    const detail = description.lines.find(line => line.detail_id === row.attribution_detail_id) ?? (eligible.length === 1 ? eligible[0] : null);
    if (!detail) throw Error('Choose the current allocation detail before changing its category.');
    const catalog = await api(endpoint('/attribution/definitions', detail.space_id));
    const chosen = catalog.category_definitions.find(d => d.key === category && d.active);
    const parent = chosen?.parent_record_id ? catalog.category_definitions.find(d => d.id === chosen.parent_record_id) : null;
    const lines = description.lines.map(line => {const {detail_id, ...values} = line;return line === detail ? {...values, category: parent?.key ?? category, subcategory_record_id: parent ? chosen.id : null} : values;});
    if (!isCurrent()) throw Error('Your financial view changed. Review this purchase again.');
    const result = await api(endpoint(path, sourceSpace), 'PUT', {version: description.version, lines});
    return {kind: 'attribution', id, space_id: sourceSpace, version: result.version, event_id: result.event_id};
  }
  const result = await api(endpoint(`/transactions/${id}`, sourceSpace), 'PATCH', {version: row.version, category, flow: row.flow,
    face_punched: row.face_punched === true, bullshit_spending: row.bullshit_spending === true, learn: false});
  if (!Number.isSafeInteger(result.version)) throw Error('The save response did not include its new version. Refresh to verify the result.');
  return {kind: 'classification', id, space_id: sourceSpace, version: result.version};
}

export function createTransactionReview({getContext, canRefine = () => false, api, endpoint, esc, precise, onRender, onChanged, onChangeCategory, onSaved, notice}) {
  let generation = 0, cohort = null, rows = new Map(), proposals = new Map(), pending = new Set(), errors = new Map(), declined = new Set(), undo = null;
  const scopeKey = () => JSON.stringify(getContext());
  function clear() {generation++;cohort = null;rows.clear();proposals.clear();pending.clear();errors.clear();declined.clear();undo = null;}
  function synchronize(visibleRows) {
    const context = scopeKey();
    if (cohort !== context) {clear();cohort = context;}
    rows = new Map(visibleRows.map(row => [reviewRowKey(row), row]));
    const candidates = [...rows].filter(([key, row]) => (!row.reviewed || row.category === 'uncategorized' || canRefine(row)) && ['expense', 'refund'].includes(row.flow)
      && (row.editable || row.attribution_editable || row.source_identity?.authorized_source) && !row.tax_purpose && row.category_editable !== false
      && !proposals.has(key) && !pending.has(key) && !errors.has(key) && !declined.has(key));
    if (!candidates.length) return;
    const currentGeneration = generation, target = getContext().space_id, snapshot = cohort;
    for (let offset = 0; offset < candidates.length; offset += 50) {
      const batch = candidates.slice(offset, offset + 50);batch.forEach(([key]) => pending.add(key));
      const payload = {rows: batch.map(([, row]) => ({id: row.source_transaction_id ?? row.id, version: row.version, attribution_detail_id: row.attribution_detail_id ?? null}))};
      const ctx = getContext();if (ctx.account_id) payload.account_id = ctx.account_id;if (ctx.program_id) payload.program_id = ctx.program_id;
      void api(endpoint('/classification-suggestions', target), 'POST', payload).then(response => {
        if (generation !== currentGeneration || cohort !== snapshot || scopeKey() !== snapshot) return;
        if (response.space_id !== target || response.readonly !== true) throw Error('The suggestion belongs to another financial view.');
        for (const proposal of response.rows ?? []) {const row = rows.get(proposal.key);if (row && row.version === proposal.version) proposals.set(proposal.key, proposal);}
        for (const [key, row] of batch) if (!proposals.has(key)) proposals.set(key, {key, version: row.version, category: null, editable: false, confidence: 'none', reason: 'No consistent suggestion is available. Choose the category yourself.'});
      }).catch(error => {if (generation === currentGeneration && cohort === snapshot && scopeKey() === snapshot) batch.forEach(([key]) => errors.set(key, error.message));})
        .finally(() => {if (generation === currentGeneration && cohort === snapshot && scopeKey() === snapshot) {batch.forEach(([key]) => pending.delete(key));onRender();}});
    }
  }
  function render(row) {
    if (cohort !== scopeKey()) return '';
    const key = reviewRowKey(row), proposal = proposals.get(key);
    if (declined.has(key)) return `<div class="tr-category-suggestion"><span>Suggestion dismissed. Your category is unchanged.</span><button type="button" data-action="category-review-change" data-review-key="${esc(key)}">Choose category</button></div>`;
    if (pending.has(key)) return '<div class="tr-category-suggestion" role="status">Checking title and your prior classifications…</div>';
    if (errors.has(key)) return `<div class="tr-category-suggestion"><span>${esc(errors.get(key))}</span><button type="button" data-action="category-review-retry" data-review-key="${esc(key)}">Try suggestion again</button><button type="button" data-action="category-review-refresh" data-review-key="${esc(key)}">Refresh purchases</button></div>`;
    if (!proposal) return '';
    const guessed = proposal.category !== null && proposal.category !== undefined;
    return `<div class="tr-category-suggestion"><strong>${guessed ? `Suggested: ${esc(proposal.category_label ?? label(proposal.category))}` : proposal.editable ? 'Choose this purchase’s category' : 'Review this financial purpose'}</strong><span>${esc(proposal.reason)}</span>${proposal.confidence && proposal.confidence !== 'none' ? `<small>${esc(label(proposal.confidence))} confidence · suggestion only</small>` : ''}${proposal.has_conflicts ? '<small>Mixed-use history · confirm this purchase’s purpose.</small>' : ''}${row.pending ? '<small>Pending purchase · bank details may change.</small>' : ''}<div class="tr-category-suggestion-actions">${guessed && proposal.editable ? `<button type="button" data-action="category-review-confirm" data-review-key="${esc(key)}">Confirm ${esc(proposal.category_label ?? label(proposal.category))}</button>` : ''}${proposal.editable ? `<button type="button" data-action="category-review-change" data-review-key="${esc(key)}">${guessed ? 'Change' : 'Choose category'}</button>` : ''}<button type="button" data-action="category-review-decline" data-review-key="${esc(key)}">Decline</button></div></div>`;
  }
  function renderUndo() {return undo && undo.context === scopeKey() ? `<aside class="tr-review-undo" role="status"><span>Category saved for ${esc(undo.merchant)}.</span><button type="button" data-action="category-review-undo">Undo</button></aside>` : '';}
  async function saveChoice(row, category) {
    const snapshot = scopeKey(), key = reviewRowKey(row);
    if (cohort !== snapshot || rows.get(key)?.version !== row.version) throw Error('This purchase changed. Refresh its review.');
    const result = await onSaved(row, category, () => scopeKey() === snapshot && rows.get(key)?.version === row.version);
    if (scopeKey() === snapshot) {proposals.delete(key);declined.delete(key);undo = {...result, merchant: row.merchant, context: snapshot};}
    return result;
  }
  async function handleClick(action, target) {
    if (!action.startsWith('category-review-')) return false;
    const snapshot = scopeKey(), key = target.dataset.reviewKey, row = rows.get(key);
    if (action === 'category-review-undo') {
      const saved = undo;if (!saved || saved.context !== snapshot) return true;
      target.disabled = true;
      try {
        const path = `/transactions/${saved.id}/${saved.kind === 'attribution' ? 'attribution/undo' : 'undo'}`;
        await api(endpoint(path, saved.space_id), 'POST', {version: saved.version, ...(saved.kind === 'attribution' ? {event_id: saved.event_id} : {})});
        if (scopeKey() === snapshot) {undo = null;notice('Undid this category change.');await onChanged();}
      } catch (error) {if (scopeKey() === snapshot) notice(error.message);}finally {if (target.isConnected) target.disabled = false;}
      return true;
    }
    if (!row || cohort !== snapshot) {notice('This purchase changed. Refresh its review.');return true;}
    if (action === 'category-review-change') {onChangeCategory(row);return true;}
    if (action === 'category-review-refresh') {errors.clear();proposals.clear();await onChanged();return true;}
    if (action === 'category-review-decline') {declined.add(key);onRender();return true;}
    if (action === 'category-review-retry') {errors.delete(key);synchronize([...rows.values()]);onRender();return true;}
    if (action === 'category-review-confirm') {
      const proposal = proposals.get(key);if (!proposal?.category || !proposal.editable || proposal.version !== row.version) return true;
      target.disabled = true;
      try {
        await saveChoice(row, proposal.category);
        if (scopeKey() !== snapshot) return true;
        notice('Category confirmed.');await onChanged();
      } catch (error) {if (scopeKey() === snapshot) {errors.set(key, error.message);proposals.delete(key);notice(error.message);onRender();}}
      finally {if (target.isConnected) target.disabled = false;}
      return true;
    }
    return true;
  }
  return {synchronize, render, renderUndo, handleClick, saveChoice, reset: clear};
}


export function renderLegacyTransactionLedger({rows,scope,active,filter,transactionReview,esc,title,date,evidenceMoney,btn,empty}) {
  return rows.length?`${transactionReview.renderUndo()}<div class="tr-table-wrap tr-review-table-wrap"><table class="tr-table tr-review-table"><caption>${rows.length} transactions${filter.review?' needing review':''}${Object.keys(filter).length&&!filter.review?' · Filtered':''}. ${filter.credit_movement?'Full posted card amounts. Positive credits reduce the card balance; negative charges increase it.':'Positive amounts are money in.'}</caption><thead><tr><th>Date</th>${scope==='both'?'<th>Space</th>':''}<th>Merchant</th><th>Category</th><th>Classification</th><th>Amount</th><th>Review</th></tr></thead><tbody>${rows.map(t=>`<tr><td data-label="Date" class="tr-review-date">${date(t.date)}</td>${scope==='both'?`<td><span class="tr-pill">${esc(t.space_label)}</span></td>`:''}<td data-label="Merchant" class="tr-review-merchant">${esc(t.merchant)}${transactionIdentityHtml(t,{esc})}<div class="tr-subtle">${esc(title(t.flow))}</div></td><td data-label="Category" class="tr-review-category">${esc(title(t.category))}${transactionReview.render(t)}</td><td data-label="Flags">${t.face_punched?'<span class="tr-pill">Face Punched</span> ':''}${t.bullshit_spending?'<span class="tr-pill">Bullshit Spending</span>':''}</td><td data-label="Amount" class="tr-money tr-review-amount ${t.amount_cents>0?'tr-positive':''}">${evidenceMoney(t,t.amount_cents)}${t.tax_purpose?`<div class="tr-subtle">${!['tax_economic_cost','sales_tax_principal','owned_bank_cash'].includes(t.amount_basis)?'Full bank amount; components below<br>':''}${['tax_economic_cost','sales_tax_principal'].includes(t.amount_basis)?`Bank amount ${evidenceMoney(t,t.bank_amount_cents)}<br>Shown: ${t.economic_category?esc(title(t.economic_category)):t.economic_categories?.length?t.economic_categories.map(c=>esc(title(c))).join(', '):'known economic costs'}<br>`:''}${t.tax_purpose.valid&&t.tax_purpose.components?`Sales-tax principal ${evidenceMoney(t,t.tax_purpose.components.tax_principal_cents)}<br>Interest ${evidenceMoney(t,t.tax_purpose.components.interest_cents)} · Penalty ${evidenceMoney(t,t.tax_purpose.components.penalty_cents)} · Fees ${evidenceMoney(t,t.tax_purpose.components.other_fees_cents)}`:esc(t.tax_purpose.issue||'Tax purpose / economic split needs review')}</div>`:''}${t.amount_basis==='owned_bank_cash'?`<div class="tr-subtle">Full bank cash<br>Economic allocation ${evidenceMoney(t,t.allocated_amount_cents)}</div>`:''}${t.amount_basis==='owned_credit_activity'?`<div class="tr-subtle">Full card amount<br>Economic allocation ${evidenceMoney(t,t.allocated_amount_cents)}<br>${esc(t.currency||'Currency unavailable')}</div>`:''}</td><td data-label="Actions" class="tr-review-actions">${t.editable||t.attribution_editable?btn(t.reviewed?'Edit category':'Categorize','classify',`data-id="${t.id}" data-space-id="${t.space_id||active}"`):'Allocated'}</td></tr>`).join('')}</tbody></table></div>`:empty(filter.review?'Everything in this space is reviewed.':'No transactions match this view.');
}
