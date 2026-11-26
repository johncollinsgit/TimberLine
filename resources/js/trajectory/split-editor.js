import {minor, percentShare} from './money.js';

const dollars = cents => (Math.abs(cents) / 100).toFixed(2);

export function splitAmounts(total, lines, remainderIndex) {
  const amounts = lines.map((line, i) => {
    if (i === remainderIndex || !line.value.trim()) return 0;
    if (line.value.startsWith('-')) throw new Error('Enter positive amounts for each part.');
    return line.mode === 'percent' ? percentShare(total, line.value) : minor(line.value);
  });
  const fixed = amounts.reduce((sum, value) => sum + value, 0);
  const remaining = total - fixed;
  if (remainderIndex >= 0) amounts[remainderIndex] = Math.max(0, remaining);
  const balanced = remainderIndex >= 0 ? remaining > 0 : remaining === 0;
  return {amounts, fixed, remaining, complete: balanced && amounts.every(amount => amount > 0)};
}

export async function openSplitEditor({root, row, sourceSpace, api, endpoint, esc, precise, categoryLabel, notice, onChanged}) {
  const path = endpoint(`/transactions/${row.source_transaction_id ?? row.id}/attribution`, sourceSpace);
  const source = await api(path);
  if (!source.lines?.length) throw new Error('An allocated amount is required to split this transaction.');
  const total = Math.abs(source.source_amount_cents);
  const sign = Math.sign(source.source_amount_cents);
  const catalogs = new Map();
  async function catalogFor(id) {
    if (!catalogs.has(id)) catalogs.set(id, await api(endpoint('/attribution/definitions', id)));
    return catalogs.get(id);
  }
  await Promise.all([...new Set(source.lines.map(line => line.space_id))].map(catalogFor));
  const lines = source.lines.map(line => ({...line, value: dollars(line.amount_cents), mode: 'dollars'}));
  let remainder = lines.length === 1 ? -1 : lines.length - 1;
  let busy = false;
  const modal = document.createElement('dialog');
  modal.className = 'vf-attribution-dialog';
  modal.setAttribute('aria-label', 'Split transaction');
  root.append(modal);
  const options = (items, selected) => items.map(([id, label]) => `<option value="${esc(id)}" ${String(id) === String(selected ?? '') ? 'selected' : ''}>${esc(label)}</option>`).join('');
  const sourceAccount = source.source_account?.name ?? row.source_identity?.account_name;
  const status = text => {const el = modal.querySelector('[role="alert"]'); el.textContent = text; el.hidden = !text;};
  function part(line, index) {
    const catalog = catalogs.get(line.space_id), defs = catalog.category_definitions.filter(d => d.active);
    const categories = catalog.categories.filter(c => !defs.find(d => d.key === c)?.parent_record_id);
    const current = defs.find(d => d.key === line.category);
    const children = current ? defs.filter(d => d.parent_record_id === current.id) : [];
    const programs = catalog.programs.filter(p => p.active);
    const reimbursable = !source.pending && sign < 0 && line.space_id !== sourceSpace;
    const isRemainder = index === remainder;
    return `<fieldset data-attribution-line="${index}"><legend>Part ${index + 1}</legend>
      <label>Category<select name="category">${options(categories.map(c => [c,defs.find(d => d.key === c)?.name ?? categoryLabel(c)]),line.category)}</select></label>
      <label>${isRemainder ? 'Remaining amount ($)' : line.mode === 'percent' ? 'Percentage (%)' : 'Amount ($)'}<input name="amount" inputmode="decimal" autocomplete="off" ${isRemainder ? 'readonly' : ''} value="${esc(line.value)}"></label>
      <label>Budget account<select name="budget_account_id">${options([['', line.space_id === sourceSpace ? 'Use source account' : 'Unassigned'], ...(catalog.budget_accounts ?? []).map(a => [a.id,a.name])],line.budget_account_id)}</select></label>
      <label>Workspace<select name="space_id">${options(source.target_spaces.map(s => [s.id,s.name]),line.space_id)}</select></label>
      ${children.length ? `<label>Subcategory<select name="subcategory_record_id">${options([['','No subcategory'],...children.map(c => [c.id,c.name])],line.subcategory_record_id)}</select></label>` : ''}
      ${programs.length ? `<label>Program<select name="program_record_id">${options([['','Unassigned'],...programs.map(p => [p.id,p.name])],line.program_record_id)}</select></label>` : ''}
      ${reimbursable ? `<label>Reimbursement<select name="settlement_status">${options([['none','None'],['open','Awaiting reimbursement'],['reimbursed','Already reimbursed']],line.settlement_status)}</select></label>` : ''}
      <label>${reimbursable ? 'Settlement note' : 'Allocation note'}<input name="settlement_note" maxlength="1000" ${line.settlement_status === 'reimbursed' ? 'required' : ''} value="${esc(line.settlement_note ?? '')}"></label>
      <div class="vf-attribution-actions">${isRemainder ? '<span>Remainder calculated automatically</span>' : `<label>Enter as<select name="mode">${options([['dollars','Dollars'],['percent','Percentage']],line.mode)}</select></label>${lines.length > 1 ? `<button type="button" data-use-remainder="${index}">Use remainder here</button>` : ''}`}
      ${lines.length > 1 ? `<button type="button" data-remove-part="${index}">Remove part</button>` : ''}</div></fieldset>`;
  }
  function progress() {
    const save = modal.querySelector('[type="submit"]'), label = modal.querySelector('[data-split-status]'), bar = modal.querySelector('progress');
    try {
      const values = splitAmounts(total, lines, remainder);
      const remaining = values.remaining;
      if (remainder >= 0) modal.querySelector(`[data-attribution-line="${remainder}"] [name="amount"]`).value = dollars(values.amounts[remainder]);
      const balance = remainder >= 0 ? 'in the remaining part' : 'left to assign';
      label.textContent = remaining < 0 ? `${precise(-remaining)} over the total` : `${precise(values.fixed)} assigned · ${precise(remaining)} ${balance}`;
      bar.value = Math.min(total, values.fixed);
      save.disabled = busy || !values.complete;
    } catch (error) {
      label.textContent = error.message;
      bar.value = 0;
      save.disabled = true;
    }
  }
  function render() {
    modal.innerHTML = `<form><header><div><h2>Split transaction</h2><p>${esc(row.merchant)} · ${esc(row.date ?? '')}</p></div><strong>${precise(total)}</strong></header>
      <p class="vf-split-source"><strong>Source account</strong> ${esc(sourceAccount ?? 'Unavailable')}</p>
      <p class="vf-caption">Assign each part to a budget account and category. The original posting stays in the source account.</p>
      <div class="vf-attribution-lines">${lines.map(part).join('')}</div>
      <button type="button" data-add-part ${lines.length >= 50 ? 'disabled' : ''}>Add part</button>
      <div class="vf-split-progress"><progress max="${total}" value="0" aria-label="Amount assigned outside the remaining part"></progress><span data-split-status role="status"></span></div>
      <p role="alert" hidden></p><footer><button type="button" data-close-part>Cancel</button><button type="submit" class="tr-primary">Save allocation</button></footer></form>`;
    // Keep the accessible label independent of option text inside a wrapping label.
    for (const select of modal.querySelectorAll('label > select')) {
      select.setAttribute('aria-label',select.parentElement.firstChild.textContent.trim());
    }
    progress();
  }
  function focusPart(index, name) {modal.querySelector(`[data-attribution-line="${index}"] [name="${name}"]`)?.focus();}
  function fixAmount(index, amount) {lines[index].mode = 'dollars'; lines[index].value = dollars(amount);}
  modal.addEventListener('input', event => {
    const field = event.target;
    if (!['amount','settlement_note'].includes(field.name) || busy) return;
    const i = Number(field.closest('fieldset').dataset.attributionLine);
    if (field.name === 'amount') {if (i !== remainder) lines[i].value = field.value; progress();}
    else lines[i].settlement_note = field.value;
  });
  modal.addEventListener('change', async event => {
    const field = event.target;
    if (field.tagName !== 'SELECT' || busy) return;
    const i = Number(field.closest('fieldset').dataset.attributionLine), line = lines[i];
    if (field.name === 'space_id') {
      const target = Number(field.value);
      busy = true;
      modal.querySelector('form').inert = true;
      try {
        await catalogFor(target);
        Object.assign(line, {space_id:target, budget_account_id:null, category:'uncategorized', subcategory_record_id:null, program_record_id:null, settlement_status:'none', settlement_note:null});
      } catch (error) {field.value = String(line.space_id); status(error.message); return;}
      finally {busy = false; modal.querySelector('form').inert = false;}
    } else if (field.name === 'category') {
      line.category = field.value; line.subcategory_record_id = null;
    } else if (field.name === 'mode') {
      if (line.mode !== field.value) {line.mode = field.value; line.value = '';}
    } else if (field.name === 'settlement_status') line.settlement_status = field.value;
    else line[field.name] = Number(field.value) || null;
    render(); focusPart(i, field.name === 'mode' ? 'amount' : field.name);
  });
  modal.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button || busy) return;
    if (button.hasAttribute('data-close-part')) {modal.close(); return;}
    if (button.hasAttribute('data-add-part')) {
      const next = lines.length;
      if (remainder < 0) {
        try {
          const {remaining} = splitAmounts(total,lines,-1);
          if (remaining < 0) {status('Reduce the amount before adding a part.'); focusPart(0,'amount'); return;}
          remainder = remaining > 0 && lines[0].value.trim() ? next : 0;
        } catch (error) {status(error.message); focusPart(0,'amount'); return;}
      }
      lines.push({space_id:sourceSpace, category:'uncategorized', value:'', mode:'dollars', settlement_status:'none'});
      render(); focusPart(next, remainder === next ? 'category' : 'amount');
    } else if (button.hasAttribute('data-remove-part')) {
      const i = Number(button.dataset.removePart);
      lines.splice(i,1);
      if (lines.length === 1) {
        if (i !== remainder) fixAmount(0,total);
        remainder = -1;
      } else if (i <= remainder) remainder = Math.max(0,remainder - 1);
      render(); focusPart(Math.max(0,remainder),'category');
    } else if (button.hasAttribute('data-use-remainder')) {
      const i = Number(button.dataset.useRemainder);
      try {
        const {amounts} = splitAmounts(total, lines, remainder);
        fixAmount(remainder, amounts[remainder]); remainder = i;
        render(); focusPart(i,'category');
      } catch (error) {status(error.message);}
    }
  });
  modal.addEventListener('cancel', event => {if (busy) event.preventDefault();});
  modal.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    try {
      const result = splitAmounts(total,lines,remainder);
      if (!result.complete) throw new Error('Give every part a positive amount within the transaction total.');
      const payload = lines.map(({value, mode, ...line}, i) => ({...line, amount_cents:sign * result.amounts[i]}));
      busy = true; modal.querySelector('form').inert = true;
      await api(path,'PUT',{version:source.version, lines:payload});
      modal.close(); notice('Allocation saved.'); await onChanged();
    } catch (error) {busy = false; if (modal.open) {modal.querySelector('form').inert = false; status(error.message); progress();} else notice(error.message);}
  });
  modal.addEventListener('close', () => modal.remove(), {once:true});
  render(); modal.showModal();
}
