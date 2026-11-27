import {minor} from './money.js';
import {installmentAmounts, bridgeTarget, temporaryMedicalDraw} from './plan-scenario.js';
import {createPlanDebt} from './plan-debt.js';
import {createPlanCharts} from './plan-charts.js';
import './plan-trajectory.css';

const moneyValue = value => Number.isSafeInteger(value);
const groupKey = group => group.month + '/' + group.source_key;
const recordKey = record => record.data.receipt_month + '/' + record.data.source_key;
const prettyDate = (value, options) => new Intl.DateTimeFormat('en-US', {timeZone:'UTC', ...options}).format(new Date(value + 'T12:00:00Z'));
const monthName = month => prettyDate(month + '-01', {month:'short', year:'numeric'});
const dateName = date => prettyDate(date, {month:'short', day:'numeric', year:'numeric'});

export function createPlanTrajectory({getData, esc, precise, btn, api, endpoint, load, notice}) {
  const charts = createPlanCharts({esc, format:precise, monthName, dateName});
  const debt = createPlanDebt({getData, esc, precise, api, endpoint, load,
    getMonthlyTarget:() => spendingBasis(getData().household_trajectory.monthly).target});
  let workspace = null, scenario, receipts, basis, saving = false;

  function reset() {
    if (workspace !== getData().space.id) {
      workspace = getData().space.id;
      basis = 'history'; debt.reset();
      scenario = {months:6, cash:'0', medicalCash:'0', assets:new Set()};
      receipts = {key:null, count:'', edited:false};
      saving = false;
    }
  }
  const money = value => moneyValue(value) ? precise(value) : 'Unavailable';
  const line = (name, value) => `<div class="pt-line"><span>${esc(name)}</span><strong>${esc(value)}</strong></div>`;
  const live = (node, text) => {if (node && node.textContent !== text) node.textContent = text;};

  function savedRecords() {
    return (getData().records ?? []).filter(record => record.kind === 'receipt_payment_plan' && record.active !== false);
  }
  function selectReceipts(groups, key) {
    const records = savedRecords();
    const group = groups.find(group => groupKey(group) === key)
      ?? groups.find(group => records.some(record => recordKey(record) === groupKey(group))) ?? groups[0];
    if (!group) return null;
    const saved = records.find(record => recordKey(record) === groupKey(group));
    if (receipts.key !== groupKey(group) || !receipts.edited) {
      receipts = {key:groupKey(group), count:saved ? String(saved.data.installments) : '', edited:false};
    }
    return {group, saved};
  }
  function allocationText(group, count) {
    const split = installmentAmounts(group.received_cents, Number(count));
    if (!split) return 'Enter the number of monthly payments to see the amount.';
    const part = (n, cents) => n + (n === 1 ? ' payment of ' : ' payments of ') + money(cents);
    return (split.highCount ? part(split.highCount, split.high) + ' and ' : '') + part(split.lowCount, split.low) + '.';
  }
  function receiptsPanel(medical) {
    const groups = medical.receipt_groups;
    const selected = selectReceipts(groups, receipts.key);
    if (!selected) return '<section class="pt-panel pt-receipts"><h2>Medical payment fund</h2><p>No posted medical-sharing deposits are available for a payment plan.</p></section>';
    const {group, saved} = selected;
    return `<section class="pt-panel pt-receipts"><header><h2>Medical payment fund</h2></header>
      <form data-pt-receipt-form><div class="pt-fields">
        <label class="pt-field">Deposits received<select name="receipts">${groups.map(g => `<option value="${esc(groupKey(g))}" ${groupKey(g) === receipts.key ? 'selected' : ''}>${esc(g.source_label)} · ${esc(monthName(g.month))}</option>`).join('')}</select></label>
        <label class="pt-field">Monthly payments<input type="number" name="installments" inputmode="numeric" min="1" max="1200" step="1" required placeholder="Number of payments" value="${esc(receipts.count)}"></label>
      </div>
      <div data-pt-received>${esc(money(group.received_cents))} received in ${group.count} posted deposit${group.count === 1 ? '' : 's'}.</div>
      <p class="pt-scenario-output" data-pt-allocation aria-live="polite">${esc(allocationText(group, receipts.count))}</p>
      <div class="pt-actions"><button type="submit" ${saving ? 'disabled' : ''}>Save payment plan</button><span class="pt-quiet" data-pt-save-status role="status">${saved && !receipts.edited ? 'Saved' : ''}</span></div></form>
      <p class="pt-note">This spreads the original deposits across payments. Bank cash already reflects those deposits. Provider balances are tracked in Medical sharing.</p>
      ${medical.bills.some(b => b.owed_cents > 0) ? `<details class="pt-detail"><summary>Provider balances</summary>${medical.bills.filter(b => b.owed_cents > 0).map(b => line(b.name, money(b.owed_cents))).join('')}${btn('Open medical sharing','plan-nav','data-view="medical"')}</details>` : ''}
    </section>`;
  }

  function cashPanel(report) {
    const cash = report.cash;
    const notes = {stale:'Some balances are older observations.', partial:'Some accounts have no usable balance.', unavailable:'No usable bank balance is available.', medical_review:'Some medical deposits still need to be matched to a case.'};
    const usable = cash.available_cents;
    return `<section class="pt-panel pt-cash"><header><h2>Cash in the bank</h2></header>
      <div class="pt-target">${esc(money(cash.observed_balance_cents))}</div><p class="pt-note">Observed household cash balances. ${esc(notes[cash.status] || '')}</p>
      ${cash.accounts.some(a => a.status === 'stale') && cash.accounts.some(a => a.status === 'current') ? line('From current bank observations', money(cash.accounts.filter(a => a.status === 'current').reduce((sum,a) => sum + a.balance_cents,0))) : ''}
      ${line('Recorded medical reserve', money(cash.medical_reserved_cents))}
      ${line('After recorded medical reserve', money(cash.after_known_reserves_cents))}
      ${report.medical.unlinked_share_cents > 0 ? line('Medical deposits awaiting a case match', money(report.medical.unlinked_share_cents)) : ''}
      <p class="pt-note">${moneyValue(usable) ? 'Allow for other earmarks before choosing how much bank cash to use.' : 'Review the balances and earmarks before using cash for ordinary expenses.'}</p>
      <details class="pt-detail"><summary>Bank balances and dates</summary>${cash.accounts.map(a => line(a.name + (a.observed_on ? ' · ' + dateName(a.observed_on) : ''), a.currency === 'USD' ? money(a.balance_cents) : a.currency + ' · excluded')).join('') || '<p>No cash accounts recorded.</p>'}</details>
    </section>`;
  }

  function spendingBasis(monthly) {
    const history = moneyValue(monthly.historical_sustainable_cents) && (basis === 'history' || !moneyValue(monthly.override_sustainable_cents));
    return {usesOverride:!history && monthly.flexible_source === 'owner_override', target:history ? monthly.historical_sustainable_cents : monthly.sustainable_cents,
      variable:history ? monthly.historical_flexible_cents : monthly.flexible_cents,
      label:history || monthly.flexible_source !== 'owner_override' ? 'Variable spending from prior months' : 'Your variable spending estimate'};
  }
  function scenarioInputs(report) {
    let amount;
    try {amount = minor(scenario.cash);} catch {return {error:'Enter a cash amount in dollars and cents.'};}
    if (amount < 0) return {error:'Enter a cash amount of zero or more.'};
    const maximum = report.cash.after_known_reserves_cents;
    if (amount > 0 && (!moneyValue(maximum) || amount > Math.max(0, maximum))) return {error:'Use an amount covered by the observed bank cash after the recorded medical reserve.'};
    const proceeds = report.assets.reduce((sum, asset) => sum + (scenario.assets.has(asset.id) && moneyValue(asset.value_cents) ? asset.value_cents : 0), 0);
    let medicalCash;
    try {medicalCash = minor(scenario.medicalCash);} catch {return {error:'Enter a temporary medical cash amount in dollars and cents.'};}
    if (medicalCash < 0) return {error:'Enter a temporary medical cash amount of zero or more.'};
    let replacement = 0, remainingMonths = 0;
    if (medicalCash) {
      const selected = selectReceipts(report.medical.receipt_groups, receipts.key);
      const bank = report.cash.observed_balance_cents;
      const draw = temporaryMedicalDraw({received:selected?.group.received_cents, count:Number(receipts.count),
        months:scenario.months, bankAfterOtherDraws:moneyValue(bank) ? bank - amount : null, borrowed:medicalCash});
      if (draw.error === 'schedule') return {error:'Choose a medical payment schedule that extends beyond this scenario.'};
      if (draw.error) return {error:'The amount exceeds the observed cash left after this period’s medical payments.'};
      ({replacement, remainingMonths} = draw);
    }
    const result = bridgeTarget(spendingBasis(report.monthly).target, amount + medicalCash, proceeds, scenario.months);
    if (result === null) return {error:'A monthly expense estimate is needed to model a cash cushion.'};
    return {result, proceeds, cash:amount, replacement, remainingMonths};
  }
  function scenarioMessage(report) {
    const outcome = scenarioInputs(report);
    if (outcome.error) return outcome.error;
    const headline = money(outcome.result) + ' take-home per month for ' + scenario.months + ' months';
    return outcome.replacement
      ? headline + '. Then replenish the temporary draw with ' + money(outcome.replacement) + ' extra per month for ' + outcome.remainingMonths + ' months.'
      : headline;
  }
  function scenarioPanel(report) {
    return `<section class="pt-panel pt-scenario"><header><h2>Try a cash cushion</h2></header>
      <div class="pt-fields"><label class="pt-field">Bank cash you can use after earmarks<input name="cash" type="text" inputmode="decimal" value="${esc(scenario.cash)}"></label>
      <label class="pt-field">Spread over<select name="months">${[3,6,12].map(n => `<option value="${n}" ${n === scenario.months ? 'selected' : ''}>${n} months</option>`).join('')}</select></label></div>
      ${report.medical.receipt_groups.length ? `<details class="pt-detail"><summary>Model temporary use of medical funds</summary>
        <label class="pt-field">Medical cash still on hand to use temporarily<input name="medical-cash" type="text" inputmode="decimal" value="${esc(scenario.medicalCash)}"></label>
        <p class="pt-note">Use the schedule shown in Medical payment fund. This scenario starts with its first payment, allows for the payments due during the period, and shows the extra earnings needed to replace your draw afterward. Enter only cash you have available for this purpose.</p></details>` : ''}
      <h3>Sellable assets</h3>
      ${report.assets.length ? `<div class="pt-assets">${report.assets.map(a => `<div class="pt-asset-choice"><label><input type="checkbox" name="asset" value="${a.id}" ${scenario.assets.has(a.id) ? 'checked' : ''} ${!moneyValue(a.value_cents) ? 'disabled' : ''}><span>${esc(a.name)}<small>${esc([a.source, a.observed_on && dateName(a.observed_on), a.status === 'stale' && 'older quote'].filter(Boolean).join(' · '))}</small></span><strong>${esc(money(a.value_cents))}</strong></label>${getData().records?.some(r => r.id === a.id && ['metal','asset'].includes(r.kind)) ? `<button type="button" data-action="edit" data-id="${Number(a.id)}" aria-label="Edit sale estimate for ${esc(a.name)}">Edit estimate</button>` : ''}</div>`).join('')}</div>` : '<p class="pt-note">Add an asset with estimated net sale proceeds, or enter a metal holding.</p>'}
      <div class="pt-actions">${btn('Add an asset','pt-add-asset')}${btn('Add metals','pt-add-metal')}</div>
      <p class="pt-scenario-output" aria-live="polite" data-pt-bridge-result>${esc(scenarioMessage(report))}</p>
      <p class="pt-note">This estimate spreads the cash you enter and the proceeds from selected sales evenly over the period. The ongoing monthly target resumes afterward.</p>
    </section>`;
  }
  function spendingTable(rows) {
    return `<table class="pt-table"><thead><tr><th>Month</th><th>Spending</th><th>Loan payments</th></tr></thead><tbody>${rows.map(row =>
      `<tr><th scope="row">${esc(monthName(row.month))}${row.status === 'partial' ? '<small>Partial history</small>' : ''}</th><td>${esc(money(row.spending_cents))}</td><td>${esc(money(row.debt_service_cents))}</td></tr>`).join('')}</tbody></table>`;
  }
  function historyBreakdown(history) {
    const average = key => history.length === 3 && history.every(row => moneyValue(row[key]))
      ? Math.round(history.reduce((sum, row) => sum + row[key], 0) / history.length) : null;
    const ordinary = history.length === 3 && history.every(r => moneyValue(r.spending_before_financing_cents) && moneyValue(r.medical_payment_cents))
      ? Math.round(history.reduce((sum,r) => sum + r.spending_before_financing_cents - r.medical_payment_cents,0) / history.length) : null;
    return line('Before interest and provider payments', money(ordinary))
      + line('Provider payments included in spending', money(average('medical_payment_cents')))
      + line('Spending before financing interest', money(average('spending_before_financing_cents')))
      + line('Interest included in recorded spending', money(average('interest_cents')))
      + line('Recorded spending per month', money(average('spending_cents')))
      + line('Loan payments excluded from spending', money(average('debt_service_cents')))
      + line('Card payments excluded from spending', money(average('card_payment_cents')));
  }
  function render(report) {
    reset();
    const monthly = report.monthly, choice = spendingBasis(monthly), {target} = choice, income = monthly.expected_income_cents;
    const difference = moneyValue(target) && moneyValue(income) ? target - income : null;
    const extras = monthly.goals_cents + monthly.debt_extra_cents;
    return `<div class="pt-trajectory">
      <div class="pt-monthly" data-pt-monthly data-space-id="${Number(getData().space.id)}">
      <section class="pt-hero"><div><span class="pt-kicker">Monthly take-home needed</span><strong class="pt-target" data-pt-target>${esc(money(target))}</strong><p class="pt-note">Projected spending and recorded required payments, before optional savings and extra debt payoff.${getData().planning?.debt_strategy?.missing?.length ? ' Some debt payments still need current lender terms.' : monthly.status !== 'estimated' ? ' Based on incomplete records.' : ''}</p></div>
        <div class="pt-earnings">${line('Expected take-home', money(income))}<div data-pt-gap>${line(difference !== null && difference < 0 ? 'Above monthly target' : 'Additional income needed', money(difference === null ? null : Math.abs(difference)))}</div>${btn('Review income','plan-income')}</div>
      </section>
      <div class="pt-grid pt-charts-grid"><section class="pt-panel pt-history">
        ${moneyValue(monthly.historical_sustainable_cents) && moneyValue(monthly.override_sustainable_cents) ? `<label class="pt-field">Estimate from<select name="pt-basis"><option value="history" ${basis === 'history' ? 'selected' : ''}>Previous months’ spending</option><option value="saved" ${basis === 'saved' ? 'selected' : ''}>My saved estimate</option></select></label>` : ''}
        <div data-pt-variable>${charts.target(report, choice)}</div>
        ${extras ? line('Optional savings and extra debt payments', money(extras)) : ''}<div data-pt-optional>${extras ? line('Including optional goals', money(moneyValue(target) ? target + extras : null)) : ''}</div>
        <details class="pt-detail"><summary>Previous three calendar months</summary>${historyBreakdown(monthly.history)}${spendingTable(monthly.history)}<p class="pt-note">Spending includes purchases, refunds and interest charges. Loan and credit-card payments are separate. The forecast matches scheduled costs before averaging the remaining spending.</p></details>
        <div class="pt-actions">${btn('Adjust spending','plan-costs')}</div></section>
      <section class="pt-panel pt-pace">${charts.pace(report)}</section></div>
      <div class="pt-grid">${cashPanel(report)}${receiptsPanel(report.medical)}</div>
      ${scenarioPanel(report)}</div>
    </div>`;
  }

  function renderDebt() {
    reset();
    return '<div class="pt-trajectory"><section class="pt-debt-section" aria-label="Debt payoff"></section></div>';
  }

  function input(event) {
    const target = event.target, surface = target.closest('.pt-trajectory');
    if (!surface) return;
    const report = getData()?.household_trajectory;
    if (!report) return;
    if (target.closest('.pt-debt-section')) {debt.input(target); return;}
    if (target.name === 'pt-basis') {
      basis = target.value;
      const monthly = report.monthly, choice = spendingBasis(monthly), income = monthly.expected_income_cents;
      const gap = moneyValue(choice.target) && moneyValue(income) ? choice.target - income : null;
      live(surface.querySelector('[data-pt-target]'), money(choice.target));
      surface.querySelector('[data-pt-gap]').innerHTML = line(gap !== null && gap < 0 ? 'Above monthly target' : 'Additional income needed', money(gap === null ? null : Math.abs(gap)));
      surface.querySelector('[data-pt-variable]').innerHTML = charts.target(report, choice);
      const extras = monthly.goals_cents + monthly.debt_extra_cents;
      surface.querySelector('[data-pt-optional]').innerHTML = extras ? line('Including optional goals', money(moneyValue(choice.target) ? choice.target + extras : null)) : '';
      live(surface.querySelector('[data-pt-bridge-result]'), scenarioMessage(report));
    } else if (target.closest('.pt-scenario')) {
      if (target.name === 'cash') scenario.cash = target.value;
      else if (target.name === 'medical-cash') scenario.medicalCash = target.value;
      else if (target.name === 'months') scenario.months = Number(target.value);
      else if (target.name === 'asset') {
        if (target.checked) scenario.assets.add(Number(target.value)); else scenario.assets.delete(Number(target.value));
      } else return;
      live(surface.querySelector('[data-pt-bridge-result]'), scenarioMessage(report));
    } else if (target.closest('.pt-receipts')) {
      const selected = selectReceipts(report.medical.receipt_groups, target.name === 'receipts' ? target.value : receipts.key);
      if (!selected) return;
      if (target.name === 'installments') {receipts.count = target.value; receipts.edited = true;}
      else if (target.name === 'receipts') target.form.elements.installments.value = receipts.count;
      else return;
      const g = selected.group;
      live(surface.querySelector('[data-pt-received]'), money(g.received_cents) + ' received in ' + g.count + ' posted deposit' + (g.count === 1 ? '' : 's') + '.');
      live(surface.querySelector('[data-pt-allocation]'), allocationText(g, receipts.count));
      live(surface.querySelector('[data-pt-bridge-result]'), scenarioMessage(report));
      live(surface.querySelector('[data-pt-save-status]'), selected.saved && !receipts.edited ? 'Saved' : '');
    }
  }
  async function save(form) {
    if (saving || !form.reportValidity()) return;
    const data = getData(), report = data?.household_trajectory;
    const group = report?.medical.receipt_groups.find(row => groupKey(row) === form.elements.receipts.value);
    const count = Number(form.elements.installments.value);
    if (!group || !installmentAmounts(group.received_cents, count)) return;
    const record = savedRecords().find(r => recordKey(r) === groupKey(group));
    const spaceId = data.space.id, button = form.querySelector('[type="submit"]');
    saving = true; button.disabled = true;
    try {
      await api(endpoint(record ? '/records/' + record.id : '/records', spaceId), record ? 'PATCH' : 'POST', {
        kind:'receipt_payment_plan', name:group.source_label + ' · ' + monthName(group.month),
        data:{receipt_month:group.month, source_key:group.source_key, installments:count}, ...(record ? {version:record.version} : {}),
      });
      if (getData()?.space.id !== spaceId || !form.isConnected) return;
      receipts.edited = false; saving = false;
      const refreshed = await load();
      if (getData()?.space.id === spaceId) notice(refreshed === false ? 'Payment plan saved. Refresh to read it back.' : 'Payment plan saved.');
    } finally {saving = false; if (button.isConnected) button.disabled = false;}
  }
  async function handle(action, button) {
    if (action === 'pt-chart-detail') {
      if (button.closest('.pt-debt-section')) return debt.handle(action, button);
      const data = getData();
      return data?.household_trajectory ? charts.open(button, data, spendingBasis(data.household_trajectory.monthly)) : false;
    }
    if (action.startsWith('pt-debt-')) return debt.handle(action, button);
    return false;
  }
  return {render, renderDebt, input, save, handle, mountDebt:surface => debt.open(surface.querySelector('.pt-debt-section'))};
}
