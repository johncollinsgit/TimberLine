import {minor} from './money.js';
import {openChartDetail} from './plan-chart-ui.js';
import {debtChartAccount, renderDebtCharts} from './plan-debt-charts.js';

const methods = {avalanche:'Highest interest first', snowball:'Smallest balance first', minimum:'Required payments only', custom:'Custom extra · highest interest first'};
const isAmount = Number.isSafeInteger;
const date = value => value ? new Intl.DateTimeFormat('en-US', {month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(value + 'T12:00:00Z')) : 'Unknown';
const rate = value => isAmount(value) ? (value / 1000).toLocaleString('en-US', {maximumFractionDigits:3}) + '%' : 'Unknown';
const cadence = {monthly:'monthly',biweekly:'every two weeks',weekly:'weekly',quarterly:'quarterly',once:'one payment'};

export function createPlanDebt({getData, esc, precise, api, endpoint, load, getMonthlyTarget}) {
  let source, method, extra, report, previewExtra, panel, busy, feedback, sequence = 0;
  function reset() {
    ++sequence;
    source = report = panel = null;
    method = 'avalanche'; extra = '0'; previewExtra = null; feedback = ''; busy = false;
  }
  reset();
  const saved = () => getData()?.debt_payoff ?? {method:null,extra_cents:0,version:0};
  const cash = value => isAmount(value) ? precise(value) : 'Unknown';
  const pair = (label, value) => `<div class="pt-debt-fact"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
  function dollars() {
    const value = method === 'minimum' ? 0 : minor(extra);
    if (value < 0 || value > 100000000000) throw new Error('Enter a monthly extra between $0 and $1 billion.');
    return value;
  }
  function rowMoney(row, cents) {
    if (!isAmount(cents) || !row.currency || row.currency === 'USD') return cash(cents);
    return (cents/100).toLocaleString('en-US',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + row.currency;
  }
  function payment(row) {
    const amount = row.cash_payment_cents ?? (isAmount(row.payment_cents) ? row.payment_cents + (row.escrow_cents ?? 0) + (row.fees_cents ?? 0) : null);
    if (!isAmount(amount)) return 'Unknown';
    const schedule = cadence[row.cash_payment_cadence ?? row.cash_cadence ?? row.payment_cadence];
    return rowMoney(row,amount) + (schedule ? ' · ' + schedule : '');
  }
  const reportedPayment = row => ['reported','statement','last_statement'].includes(row.payment_status);
  function accountFacts(row) {
    const statement = row.latest_statement_interest, ytd = row.ytd_statement_interest, charge = row.interest;
    const reported = reportedPayment(row);
    const interestLabel = term => rowMoney(row,term.cents) + (term.from ? ' · ' + date(term.from) + ' – ' + date(term.through)
      : term.through ? ' through ' + date(term.through) : '');
    const interestSource = {posted:'posted transactions', statement:'lender statement'}[charge?.source];
    return `<div class="pt-debt-facts">${pair(reported ? 'Previously reported payment' : 'Recorded cash payment', payment(row))}
      ${isAmount(row.cash_payment_cents) && isAmount(row.payment_cents) ? pair('Lender principal and interest', rowMoney(row,row.payment_cents) + (cadence[row.payment_cadence] ? ' · ' + cadence[row.payment_cadence] : '')) : ''}
      ${row.payment_notice && isAmount(row.payment_notice.amount_cents) ? pair('Dated lender notice', rowMoney(row,row.payment_notice.amount_cents) + (row.payment_notice.due_on ? ' due ' + date(row.payment_notice.due_on) : '') + (row.payment_notice.observed_on ? ' · recorded ' + date(row.payment_notice.observed_on) : '')) : ''}
      ${pair(reported ? 'Previously reported due date' : 'Recorded due date', date(row.cash_next_due_on ?? row.next_due_on))}${row.terms_observed_on && reported ? pair('Terms reported on',date(row.terms_observed_on)) : ''}${isAmount(row.rate_millis) ? pair('Annual interest rate', rate(row.rate_millis) + (row.rate_status === 'reported' ? ' · reported' : '')) : ''}
      ${pair('Interest this month', isAmount(charge?.cents) ? interestLabel(charge) + (interestSource ? ' · ' + interestSource : '') : 'Unknown')}
      ${isAmount(statement?.cents) ? pair('Latest statement interest',interestLabel(statement)) : ''}${isAmount(ytd?.cents) ? pair('Statement interest year to date',interestLabel(ytd)) : ''}
      ${row.maturity_on ? pair('Contract maturity',date(row.maturity_on)) : ''}</div>
      ${row.missing?.length ? `<p class="pt-note">To review · ${esc(row.missing.map(key => ({confirmed_terms:'lender terms',fresh_balance:'updated balance',next_due_on:'next unpaid due date',maturity_on:'contract maturity',rate:'annual rate',payment:'required payment',balance:'account balance',payment_allocation:'lender payment allocation',duplicate_terms:'overlapping debt records',interest_paid_through:'date interest was paid through'}[key] ?? key.replaceAll('_',' '))).join(', '))}.</p>` : ''}`;
  }
  function account(row) {
    const edit = row.record_id && getData()?.records?.some(r => r.id === row.record_id)
      ? `<button type="button" data-action="edit" data-id="${Number(row.record_id)}">Edit terms</button>`
      : row.account_id ? `<button type="button" data-action="pt-enter-terms" data-id="${Number(row.account_id)}">Enter terms</button>` : '';
    return `<details class="pt-debt-account"><summary><span><strong>${esc(row.name)}</strong><small>${esc(row.balance_observed_on ? 'Balance on ' + date(row.balance_observed_on) : 'Balance date unknown')}</small><small>${esc(reportedPayment(row) ? 'Last reported payment · ' : 'Payment · ')}${esc(payment(row))}</small></span><span class="pt-debt-balance">${esc(rowMoney(row,row.balance_cents))}<small>${esc(rate(row.rate_millis))} annual rate</small></span></summary>
      ${accountFacts(row)}${edit}</details>`;
  }
  function chartDetail(button) {
    if (!panel?.contains(button) || getData() !== source || report?.space_id !== source?.space.id) return false;
    const row = debtChartAccount(report, button.dataset.chart, button.dataset.key);
    if (!row) return false;
    const amount = rowMoney(row,row.balance_cents);
    const balance = row.balance_observed_on ? amount + ' as of ' + date(row.balance_observed_on) : amount;
    openChartDetail(button, {title:row.name, body:pair('Recorded balance', balance) + accountFacts(row)});
    return true;
  }
  function inventory() {
    if (!report) return '';
    const rows = report.inventory ?? [], other = report.balance_review ?? [];
    return `<section class="pt-panel pt-debt-inventory"><header><h2>Cards and loans</h2></header>
      ${rows.map(account).join('') || '<p>No outstanding balances have been recorded.</p>'}
      ${other.length ? `<details class="pt-detail"><summary>Balances to review (${other.length})</summary>${other.map(account).join('')}</details>` : ''}
      <div class="pt-actions"><button type="button" data-action="add" data-kind="debt">Add a debt</button></div></section>`;
  }
  function comparison() {
    if (!report) return '';
    let amount;
    try {amount = dollars();} catch {return '';}
    const core = getMonthlyTarget(), income = getData().household_trajectory.monthly.expected_income_cents;
    const target = isAmount(core) ? core + amount : null;
    const impact = isAmount(target) ? `<div class="pt-debt-facts pt-impact">${pair('Monthly take-home with this extra',cash(target))}${isAmount(income) ? pair(target >= income ? 'Additional income needed' : 'Above the monthly target',cash(Math.abs(target-income))) : ''}</div>` : '';
    if (previewExtra !== amount) return impact + '<p class="pt-note">Compare again to see the estimate for this amount.</p>';
    const best = report.recommendation, options = report.method_comparisons ?? [];
    const name = best?.priority_name;
    return `${impact}${best ? `<div class="pt-scenario-output"><strong>${esc(best.status === 'estimated' ? 'Suggested approach' : 'Payoff priority')} · ${esc(best.status === 'estimated' ? methods[best.method] ?? 'Review terms' : name ?? 'Review terms')}</strong>${best.status === 'estimated' && name ? `<p>First balance · ${esc(name)}${isAmount(best.rate_millis) ? ' · ' + esc(rate(best.rate_millis)) : ''}</p>` : ''}<p>${esc(best.reason ?? '')}</p></div>` : ''}
      ${options.length ? `<table class="pt-table"><thead><tr><th>Approach</th><th>Projected payoff</th><th>Future interest</th></tr></thead><tbody>${options.map(o => `<tr><th scope="row">${esc(methods[o.method] ?? o.method)}</th><td>${esc(o.payoff_on ? date(o.payoff_on) : 'Beyond model horizon')}</td><td>${esc(cash(o.interest_cents))}</td></tr>`).join('')}</tbody></table><p class="pt-note">Both estimates keep rates fixed and redirect each freed payment to the next balance. They assume no new borrowing.</p>` : '<p class="pt-note">Payoff dates and total future interest need current balances, rates and payment terms.</p>'}`;
  }
  function render() {
    const current = saved();
    return `<div class="pt-debt-charts pt-charts-grid" data-pt-debt-charts ${report?.inventory?.length ? '' : 'hidden'}>${renderDebtCharts(report,{esc,cash,rate})}</div><section class="pt-panel pt-debt-controls"><header><h2>Payoff scenario</h2></header>
      <div class="pt-fields"><label class="pt-field">Payoff order<select name="pt-debt-method">${Object.entries(methods).filter(([key]) => key !== 'custom' || method === 'custom').map(([key,label]) => `<option value="${key}" ${method === key ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>
      <label class="pt-field">Extra per month ($)<input name="pt-debt-extra" inputmode="decimal" type="text" value="${esc(method === 'minimum' ? '0' : extra)}" ${method === 'minimum' ? 'disabled' : ''}></label></div>
      <div class="pt-actions"><button type="button" data-action="pt-debt-preview">Compare approaches</button><button class="pt-primary" type="button" data-action="pt-debt-apply">Apply to budget</button></div>
      <p class="pt-note">${current.method ? 'In your budget · ' + esc(methods[current.method]) + ' · ' + esc(cash(current.extra_cents)) + ' extra per month.' : 'Choose an extra amount above required payments. Applying a scenario reserves it in your monthly budget.'}</p>
      <p class="pt-quiet" role="status" data-pt-debt-status>${esc(feedback)}</p><div data-pt-debt-comparison>${comparison()}</div></section><div data-pt-inventory>${inventory()}</div>`;
  }
  function paint() {
    if (!panel?.isConnected) return;
    panel.setAttribute('aria-busy',String(busy));
    for (const button of panel.querySelectorAll('.pt-debt-controls button, .pt-debt-controls select, .pt-debt-controls input')) {
      button.disabled = busy || button.name === 'pt-debt-extra' && method === 'minimum';
    }
    panel.querySelector('[data-pt-debt-status]').textContent = feedback;
    panel.querySelector('[data-pt-debt-comparison]').innerHTML = comparison();
  }
  async function preview(amount, data, ticket) {
    const response = await api(endpoint('/debt-scenarios',data.space.id),'POST',{view:'household_plan',extra_cents:amount});
    if (ticket !== sequence || getData() !== data) return false;
    report = response; previewExtra = amount;
    if (panel?.isConnected) {
      const charts = panel.querySelector('[data-pt-debt-charts]');
      charts.innerHTML = renderDebtCharts(report,{esc,cash,rate});
      charts.hidden = !charts.firstElementChild;
      panel.querySelector('[data-pt-inventory]').innerHTML = inventory();
    }
    return true;
  }
  async function work(apply) {
    if (busy) return;
    const data = getData(), ticket = ++sequence;
    busy = true; feedback = apply ? 'Updating budget…' : 'Comparing…'; paint();
    try {
      const amount = dollars();
      if ((!report || previewExtra !== amount) && !await preview(amount,data,ticket)) return;
      if (apply) {
        await api(endpoint('/workspace', data.space.id),'PATCH',{debt_payoff_method:method,debt_extra_cents:amount,debt_payoff_version:saved().version});
        if (ticket !== sequence || getData() !== data) return;
        feedback = 'Budget updated.'; busy = false;
        if (panel?.isConnected) await load();
      } else feedback = '';
    } catch (error) {
      if (ticket !== sequence) return;
      feedback = error.message;
      if (error.status === 409 && panel?.isConnected) await load();
    } finally {
      if (ticket === sequence) {busy = false; paint();}
    }
  }
  async function open(node) {
    const data = getData();
    if (!node || !data?.household_trajectory) return;
    if (source !== data) {
      const sameSpace = source?.space.id === data.space.id;
      ++sequence; source = data; busy = false; report = null; previewExtra = null;
      const current = saved();
      method = current.method ?? 'avalanche'; extra = ((current.extra_cents ?? 0)/100).toFixed(2);
      if (!sameSpace) feedback = '';
      panel = null;
    }
    if (panel !== node || !node.firstElementChild) {
      panel = node; panel.innerHTML = render(); paint();
    }
    if (!report && !busy) await work(false);
  }
  function input(target) {
    if (target.name === 'pt-debt-method') {
      method = target.value;
      const field = panel.querySelector('[name="pt-debt-extra"]');
      field.value = method === 'minimum' ? '0' : extra;
    } else if (target.name === 'pt-debt-extra') extra = target.value; else return;
    feedback = ''; paint();
  }
  async function handle(action, button) {
    if (action === 'pt-chart-detail') return chartDetail(button);
    if (!['pt-debt-preview','pt-debt-apply'].includes(action) || !button.closest('.pt-debt-section')) return false;
    await work(action === 'pt-debt-apply');
    return true;
  }
  return {open,render,reset,input,handle};
}
