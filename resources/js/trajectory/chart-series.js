// Currency remains in integer cents. A forecast shares its observed anchor;
// the anchor is never copied into the observed series as a new observation.
export function cashTimeline(history, daily, comparison = []) {
  const observed = history.filter(r => Number.isFinite(r.cash_cents) && (!daily.length || r.date <= daily[0].date)).sort((a,b) => a.date.localeCompare(b.date));
  const prefix = observed.map(() => null);
  const anchor = observed.at(-1);
  const project = (key, rows) => {
    const values = [...prefix, ...daily.map((r,i) => rows[i]?.[key] ?? null)];
    if (anchor && daily.length) values[observed.length - 1] = anchor.cash_cents;
    return values;
  };
  return {
    rows: [...observed.map(r => ({...r, observed:true})), ...daily.map(r => ({...r, observed:false}))],
    observed: [...observed.map(r => r.cash_cents), ...daily.map(() => null)],
    cash: project('cash_cents', daily),
    available: project('available_cents', daily),
    comparison: project('available_cents', comparison),
    boundary: anchor && daily.length ? observed.length - 1 : null,
    sameAvailable: daily.every(r => r.cash_cents === r.available_cents),
  };
}

export function incomeSeries(plan) {
  // These are monthly rates, even in the first partial forecast month.
  // No implied seasonality or annual growth is introduced by the chart.
  return {
    expected: plan.monthly.map(r => r.expected_income_cents),
    required: plan.monthly.map(r => r.required_income_cents),
    historical: plan.monthly.map(() => plan.history_days > 0 ? plan.historical_monthly_cents : null),
    sameHistorical: plan.history_days > 0 && plan.monthly.every(r => r.expected_income_cents === plan.historical_monthly_cents),
  };
}
