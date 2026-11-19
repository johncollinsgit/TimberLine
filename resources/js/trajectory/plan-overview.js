/** Display-only allocation: essential costs first; optional goals never create spendable cash. */
export function planOverview(data) {
  const p=data.planning, income=p.expected_monthly_cents, core=p.fixed_monthly_cents+p.flexible_monthly_cents;
  const extraRequested=data.workspace_settings?.debt_extra_cents??0, goalsRequested=p.goals_monthly_cents;
  const available=Math.max(0,income-core), extra=Math.min(available,extraRequested), goals=Math.min(Math.max(0,available-extra),goalsRequested);
  return {income,core,extra,goals,extraRequested,goalsRequested,gap:Math.max(0,core-income),surplus:Math.max(0,income-core-extra-goals),
    months:p.monthly.slice(0,3).map(row=>({...row,label:new Intl.DateTimeFormat('en-US',{month:'short',timeZone:'UTC'}).format(new Date(row.date+'T12:00:00Z')),core_cents:core,gap_cents:Math.max(0,core-row.expected_income_cents),optional_cents:extra+goals}))};
}
