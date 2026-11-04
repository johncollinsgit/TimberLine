// This guide reads the current space and opens its existing editors/importers.
// It never writes financial records or treats an unfinished step as a zero.
const stepKeys = ['accounts', 'budget', 'income', 'bills', 'debt', 'sources'];

export function welcomeState(data = {}) {
  const records = Array.isArray(data.records) ? data.records.filter(r => r.active !== false) : [];
  const accounts = Array.isArray(data.accounts) ? data.accounts : [];
  const connections = Array.isArray(data.connections) ? data.connections : [];
  const count = kind => records.filter(r => r.kind === kind).length;
  return {
    accounts: accounts.length,
    connections: connections.filter(c => c.active !== false).length,
    budgets: count('budget'),
    bills: count('recurring'),
    debts: count('debt'),
    incomeSources: Array.isArray(data.planning?.sources) ? data.planning.sources.length : 0,
    sourceObservations: count('financial_observation'),
    missingDebtTerms: Array.isArray(data.planning?.debt_strategy?.missing) ? data.planning.debt_strategy.missing : [],
    blockers: Array.isArray(data.coverage?.blockers) ? data.coverage.blockers : [],
    bankLinkReady: data.providers?.plaid === true,
  };
}

export function welcomeUI({getData, esc, btn, navigate, render: redraw, budgetImportAction = null}) {
  const selected = new Map();
  const native = (label, action, attrs = '') => btn(label, action, `type="button" ${attrs}`);
  function step() {
    const key = getData()?.space?.id;
    return selected.get(key) || 'accounts';
  }
  function select(key) {
    if (!stepKeys.includes(key)) return;
    selected.set(getData()?.space?.id, key);
    redraw();
  }
  function cards(data, state) {
    const budgetImport = budgetImportAction ? native('Import an existing budget', budgetImportAction) : '';
    return [
      {
        key: 'accounts', label: 'Accounts', status: state.accounts ? `${state.accounts} accounts recorded` : 'Choose your first account',
        heading: 'Start with the accounts you want to see.',
        text: 'Add a manual account, import a statement, or connect a bank. Choose household or business ownership before importing a shared bank login. You can start without a live connection.',
        controls: native('Add an account', 'account') + native('Import a statement', 'import') + native('Import Monarch history', 'monarch-import') + (state.bankLinkReady ? native('Connect a bank', 'connect') : ''),
        note: state.bankLinkReady ? 'Bank linking is optional. Review each account owner and its last balance observation.' : 'Live bank linking needs provider setup for this space. Manual accounts and statement imports are available.',
      },
      {
        key: 'budget', label: 'Budget', status: state.budgets ? `${state.budgets} targets recorded` : 'Bring your plan or build one',
        heading: 'Give your money a direction.',
        text: 'Bring an existing budget or add monthly targets for spending, savings and debt. Imported targets need review; a plan does not establish income received or a lender payment.',
        controls: native('Add a budget target', 'add', 'data-kind="budget"') + budgetImport + native('Review your budget', 'welcome-go', 'data-view="budget"'),
        note: 'Keep the original budget dates and source. Unknown amounts can stay unknown while you gather evidence.',
      },
      {
        key: 'income', label: 'Income', status: state.incomeSources ? `${state.incomeSources} sources in the plan` : 'Review what comes home',
        heading: 'Separate your income from money moving around.',
        text: 'Review pay, owner draws and other income sources. Borrowing, transfers, reimbursements and asset sales need their own classifications. Expected income is a plan; take-home income needs dated evidence.',
        controls: native('Review income sources', 'welcome-go', 'data-view="planning"') + native('Review transactions', 'review') + native('Add source evidence', 'add', 'data-kind="financial_observation"'),
        note: 'Use net employee pay for take-home cash and preserve gross pay separately. Record an owner draw once, even when both bank accounts show it.',
      },
      {
        key: 'bills', label: 'Bills', status: state.bills ? `${state.bills} recurring schedules recorded` : 'Add the bills you know',
        heading: 'Put your upcoming bills on the calendar.',
        text: 'Add amounts, due dates and payment accounts from current bills. Review imported schedules before confirming them. A missing bill amount does not mean there is nothing due.',
        controls: native('Add a recurring bill', 'add', 'data-kind="recurring"') + native('Review bills and goals', 'welcome-go', 'data-view="bills"'),
        note: 'Keep medical payment plans, card payments and transfers distinct from new purchases so spending is counted once.',
      },
      {
        key: 'debt', label: 'Debt', status: state.debts ? `${state.debts} debt records` : 'Confirm balances and terms',
        heading: 'Use the lender’s terms for your payoff plan.',
        text: 'Record each balance, rate, required payment and due date from a current statement. Actual autopay and the contractual minimum may differ. Leave missing terms open for review.',
        controls: native('Add a debt', 'add', 'data-kind="debt"') + native('Review debt and interest', 'welcome-go', 'data-view="assets"'),
        note: state.missingDebtTerms.length ? `${state.missingDebtTerms.length} debt inputs still need review. Open Wealth & debt to see the missing terms.` : 'Recorded debt does not establish that every liability or lender term has been supplied.',
      },
      {
        key: 'sources', label: 'Sources & review', status: 'Check dates and coverage',
        heading: 'See what supports your picture.',
        text: 'Review account ownership, balance dates, imported history and source evidence. Choose one balance source for the same cash account when a live feed replaces a manual snapshot.',
        controls: native('Review accounts and sources', 'welcome-go', 'data-view="accounts"') + native('Review transactions', 'review') + native('Add source evidence', 'add', 'data-kind="financial_observation"'),
        note: state.blockers.length ? `${state.blockers.length} coverage notes remain visible below. You can continue while these are unresolved.` : 'Review dates and completeness before using a figure for a decision. No coverage notes does not certify lender readiness.',
      },
    ];
  }
  function render() {
    const data = getData();
    if (!data?.space) return '';
    const state = welcomeState(data), steps = cards(data, state), active = step();
    const index = steps.findIndex(s => s.key === active), current = steps[index];
    return `<section class="tr-panel tr-welcome" aria-labelledby="tr-welcome-title">
      <header><p class="tr-overline">GET STARTED</p><h2 id="tr-welcome-title">A clearer picture, one step at a time.</h2><p class="tr-subtle">Set up ${esc(data.space.name || 'your space')} at your pace. Your saved records stay available when you leave this guide.</p></header>
      <nav class="tr-actions tr-block" aria-label="Financial setup steps">${steps.map((s, i) => native(`${i + 1}. ${s.label}`, 'welcome-step', `data-step="${s.key}" aria-pressed="${active === s.key}" aria-controls="tr-welcome-step"`)).join('')}</nav>
      <div id="tr-welcome-step" class="tr-block" aria-live="polite"><p class="tr-kicker">Step ${index + 1} of ${steps.length} · ${esc(current.status)}</p><h3>${esc(current.heading)}</h3><p>${esc(current.text)}</p><div class="tr-actions tr-block">${current.controls}</div><p class="tr-data-note">${esc(current.note)}</p></div>
      ${active === 'sources' && state.blockers.length ? `<details class="tr-block"><summary>Review coverage notes (${state.blockers.length})</summary><ul>${state.blockers.map(note => `<li>${esc(note)}</li>`).join('')}</ul></details>` : ''}
      <footer class="tr-actions tr-block">${index > 0 ? native('Previous step', 'welcome-step', `data-step="${steps[index - 1].key}"`) : ''}${index < steps.length - 1 ? native('Next step', 'welcome-step', `data-step="${steps[index + 1].key}"`) : ''}${native('Continue with unknowns', 'welcome-continue')}</footer>
      <p class="tr-subtle">This guide does not mark your finances complete. Missing figures stay unavailable, and forecasts keep their coverage notes.</p>
    </section>`;
  }
  function handle(action, target) {
    if (action === 'welcome-step') { select(target.dataset.step); return true; }
    if (action === 'welcome-continue') { navigate('overview'); return true; }
    if (action === 'welcome-go' && ['accounts', 'budget', 'planning', 'bills', 'assets'].includes(target.dataset.view)) { navigate(target.dataset.view); return true; }
    return false;
  }
  return {render, html: render, handle, reset: () => selected.clear()};
}
