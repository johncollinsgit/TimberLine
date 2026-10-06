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

// A cash balance is a stock, so monthly bars use the last available day,
// never the sum of daily balances. Partial months retain their actual date.
export function monthlyBalances(daily) {
  const months=new Map();
  for(const row of [...daily].sort((a,b)=>a.date.localeCompare(b.date))) months.set(row.date.slice(0,7),row);
  return [...months.values()];
}

// Observations and predictions remain distinct, including in the current month.
// Null means missing evidence, not a zero balance or an inferred past forecast.
export function actualProjectedBalances(history, daily) {
  if (!daily.length) return [];
  const cutoff=daily[0].date;
  const start=new Date(`${cutoff.slice(0,7)}-01T12:00:00Z`);
  start.setUTCMonth(start.getUTCMonth()-6);
  const actual=new Map(monthlyBalances(history.filter(r=>Number.isFinite(r.cash_cents)&&r.date<=cutoff)).map(r=>[r.date.slice(0,7),r]));
  const projected=new Map(monthlyBalances(daily).map(r=>[r.date.slice(0,7),r]));
  const end=daily.at(-1).date.slice(0,7),rows=[];
  for(let cursor=start;cursor.toISOString().slice(0,7)<=end;cursor.setUTCMonth(cursor.getUTCMonth()+1)) {
    const month=cursor.toISOString().slice(0,7);
    rows.push({month,actual:actual.get(month)??null,projected:projected.get(month)??null});
  }
  return rows;
}

// Income and outflows are monthly flows, never a stacked or cumulative cash
// balance. The selected projection can use the matching month last year, the
// editable plan, or the current recent-history baseline.
export function incomeRunwaySeries(rows, source = 'last-year') {
  const key = source === 'plan' ? 'plan_income_cents' : source === 'recent' ? 'recent_income_cents' : 'last_year_income_cents';
  const projected = rows.map(row => row[key] ?? null);
  const lastYear = rows.map(row => row.last_year_income_cents ?? null);
  const outflows = rows.map(row => row.outflow_cents ?? 0);
  const gaps = projected.map((income, i) => income === null ? null : income - outflows[i]);
  return {projected,lastYear,outflows,gaps,sameAsLastYear:projected.every((amount,i)=>amount===lastYear[i])};
}

// Calendar-year comparisons preserve missing months as null. A missing month
// is unavailable evidence, never a zero-dollar month. The row keeps both
// years' transaction IDs so a selected bar can open the records behind it.
export function previousYearSeries(months, year, metric = 'spending') {
  const indexed=new Map(months.map(row=>[row.month,row]));
  const amount=row=>{
    if(!row)return null;
    if(metric==='income')return row.income_cents;
    if(metric==='net')return row.income_cents-row.spending_cents;
    return row.spending_cents;
  };
  const rows=Array.from({length:12},(_,index)=>{
    const number=String(index+1).padStart(2,'0');
    const current=indexed.get(`${year}-${number}`)??null;
    const previous=indexed.get(`${year-1}-${number}`)??null;
    return {month:number,current,previous,current_cents:amount(current),previous_cents:amount(previous)};
  });
  return {
    rows,
    current:rows.map(row=>row.current_cents),
    previous:rows.map(row=>row.previous_cents),
    currentTotal:rows.reduce((sum,row)=>sum+(row.current_cents??0),0),
    previousTotal:rows.reduce((sum,row)=>sum+(row.previous_cents??0),0),
    currentCount:rows.filter(row=>row.current_cents!==null).length,
    previousCount:rows.filter(row=>row.previous_cents!==null).length,
    comparableCurrentTotal:rows.filter(row=>row.current_cents!==null&&row.previous_cents!==null&&row.current?.closed_month!==false).reduce((sum,row)=>sum+row.current_cents,0),
    comparablePreviousTotal:rows.filter(row=>row.current_cents!==null&&row.previous_cents!==null&&row.current?.closed_month!==false).reduce((sum,row)=>sum+row.previous_cents,0),
    comparableCount:rows.filter(row=>row.current_cents!==null&&row.previous_cents!==null&&row.current?.closed_month!==false).length,
  };
}
