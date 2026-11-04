import { minor } from './money.js';

const labels = {minimum: 'Keep current payments', snowball: 'Snowball · smallest balance', avalanche: 'Avalanche · highest APR', custom: 'Custom extra · avalanche'};
const colors = {minimum: '#6b7280', snowball: '#27796a', avalanche: '#376fa0', custom: '#a0662f'};

export function debtPlannerUI({getData, esc, precise, btn, plot, chart, line, render, requestScenario = null}) {
  const results = new Map();
  const pending = new Set();
  const key = () => getData()?.space?.id;
  const fingerprint = () => JSON.stringify(getData()?.debt_planner || null);
  const current = () => {
    const cached = results.get(key());
    return cached?.fingerprint === fingerprint() ? cached.result : getData()?.debt_planner;
  };
  const date = value => value ? new Date(`${value}T12:00:00`).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'}) : 'Not established';
  function html() {
    const data = getData();
    if (!data?.space || !['household', 'business'].includes(data.space.kind)) return '';
    const p = current();
    const title = data.space.kind === 'business' ? 'Business debt planner' : 'Household debt planner';
    if (!p) return `<section class="tr-panel"><h2>${title}</h2><p>Review your debt balances and lender terms to compare payoff scenarios.</p>${btn('Review debt terms', 'assets')}</section>`;
    const debts = p.debts || [], scenarios = p.scenarios || [], missing = p.missing || [];
    const extra = Number.isSafeInteger(p.extra_monthly_cents) ? (p.extra_monthly_cents / 100).toFixed(2) : '';
    return `<section class="tr-panel tr-debt-planner" aria-labelledby="tr-debt-planner-title">
      <header><p class="tr-overline">YOUR PAYOFF OPTIONS</p><h2 id="tr-debt-planner-title">${title}</h2><p class="tr-subtle">${esc(data.space.name)} · source balances as of ${esc(date(p.as_of))} · ${p.complete ? 'Recorded debt inventory reviewed' : 'Partial debt inventory'}</p></header>
      <p>Compare the same recorded debts using four payment approaches. These are scenarios you can explore; they do not schedule payments.</p>
      ${missing.length ? `<div class="tr-notice"><strong>Payoff dates need more evidence.</strong><ul>${missing.map(x => `<li>${esc(x)}</li>`).join('')}</ul>${btn('Review lender terms', 'assets')}</div>` : ''}
      ${debts.length ? `<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Debt</th><th>Principal balance</th><th>APR</th><th>Recorded payment</th><th>Next due</th></tr></thead><tbody>${debts.map(d => `<tr><th scope="row">${esc(d.name)}</th><td>${precise(d.balance_cents)}</td><td>${Number.isInteger(d.rate_millis) ? esc((d.rate_millis / 1000).toFixed(3).replace(/\.?0+$/, '')) + '%' : 'Unknown'}</td><td>${precise(d.payment_cents)} · ${esc(d.payment_cadence || 'monthly')}</td><td>${esc(date(d.next_due_on))}</td></tr>`).join('')}</tbody></table></div>${plot('tr-debt-composition', 'Recorded principal balance by debt, in dollars')}` : '<p class="tr-data-note">No reviewed debts are available. Missing accounts are not zero balances.</p>'}
      ${requestScenario ? `<div class="tr-block"><label class="tr-field" for="tr-debt-extra">Extra per month for the custom scenario<input id="tr-debt-extra" name="extra_monthly" type="text" inputmode="decimal" value="${esc(extra)}" placeholder="Amount in dollars" ${pending.has(key()) ? 'disabled' : ''}></label><div class="tr-actions">${btn(pending.has(key()) ? 'Calculating…' : 'Compare extra payment', 'debt-scenario-run', `type="button" ${pending.has(key()) ? 'disabled' : ''}`)}</div><p class="tr-subtle">Use an amount you choose. Trajectory does not assume business cash is household income or that estimated income is available.</p></div>` : ''}
      ${scenarios.length ? `<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Approach</th><th>Projected payoff</th><th>Future interest</th><th>Extra / month</th></tr></thead><tbody>${scenarios.map(s => `<tr><th scope="row">${esc(labels[s.method] || s.method)}</th><td>${s.status === 'projected' ? esc(date(s.payoff_on)) : 'Not paid off within model horizon'}</td><td>${s.interest_cents == null ? 'Not established' : precise(s.interest_cents)}</td><td>${precise(s.extra_monthly_cents)}</td></tr>`).join('')}</tbody></table></div><div class="tr-grid equal"><div><h3>How the balance changes</h3>${plot('tr-debt-scenarios', 'Projected debt balances for each payoff approach, in dollars')}</div><div><h3>Future interest to payoff</h3>${plot('tr-debt-interest', 'Projected future interest for scenarios with an established payoff, in dollars')}</div></div><details class="tr-block"><summary>See payoff order and dates</summary>${scenarios.map(s => `<h3>${esc(labels[s.method])}</h3>${(s.payoff_order || []).length ? `<ol>${s.payoff_order.map(d => `<li>${esc(d.name)} · ${esc(date(d.date))}</li>`).join('')}</ol>` : '<p>No payoff within the model horizon.</p>'}`).join('')}</details>` : '<p class="tr-data-note">No payoff comparison is shown until balances, APRs, payment amounts, schedules and allocation are supported.</p>'}
      <details class="tr-block"><summary>What these scenarios assume</summary><ul>${(p.assumptions || ['Current balances and lender terms need review before estimating payoff.']).map(a => `<li>${esc(a)}</li>`).join('')}</ul></details>
      <p class="tr-data-note">This space stays separate from other households and businesses. Rates may change. Missing liabilities and unsupported lender terms remain outside any estimated result.</p>
    </section>`;
  }
  function draw() {
    const p = current();
    if (!p) return;
    const debts = p.debts || [], scenarios = p.scenarios || [];
    if (debts.length) chart('tr-debt-composition', 'bar', debts.map(d => d.name), [{label: 'Recorded principal', data: debts.map(d => d.balance_cents ?? null), backgroundColor: '#376fa0'}]);
    if (!scenarios.length) return;
    const dates = [...new Set(scenarios.flatMap(s => (s.rows || []).map(r => r.date)))].sort();
    const datasets = scenarios.map(s => {
      let index = -1;
      const rows = s.rows || [];
      const values = dates.map(d => {
        while (rows[index + 1] && rows[index + 1].date <= d) index++;
        if (index < 0) return null;
        if (d > rows.at(-1).date && s.status !== 'projected') return null;
        return rows[index].balance_cents;
      });
      return line(labels[s.method], values, colors[s.method]);
    });
    chart('tr-debt-scenarios', 'line', dates, datasets);
    chart('tr-debt-interest', 'bar', scenarios.map(s => labels[s.method]), [{label: 'Future interest to payoff', data: scenarios.map(s => s.interest_cents ?? null), backgroundColor: scenarios.map(s => colors[s.method])}]);
  }
  async function handle(action, target) {
    if (action !== 'debt-scenario-run' || !requestScenario) return false;
    const space = key(), source = fingerprint();
    if (pending.has(space)) return true;
    const input = target.closest('.tr-debt-planner')?.querySelector('[name="extra_monthly"]');
    const extra = minor(input?.value ?? '');
    if (extra < 0) throw new Error('Extra payment must be zero or a positive amount.');
    pending.add(space);
    render();
    try {
      const result = await requestScenario(extra, space);
      if (key() === space && fingerprint() === source) {
        results.set(space, {fingerprint: source, result});
      }
    } finally {
      pending.delete(space);
      if (key() === space) render();
    }
    return true;
  }
  return {html, render: html, draw, handle, reset: () => {results.clear(); pending.clear();}};
}
