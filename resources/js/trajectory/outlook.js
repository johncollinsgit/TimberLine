import { cashTimeline, incomeSeries } from './chart-series.js';

export function outlookUI({getData,esc,money,precise,date,plot,chart,line,btn,dialog,evidence,forecastDetail}) {
  let horizon=365;
  const month=d=>new Date(`${d}T12:00:00`).toLocaleDateString('en-US',{month:'short',year:'numeric'});
  const jump=(label,view)=>btn(label,'plan-nav',`data-view="${view}"`);
  function html(scenarioId,seasonal) {
    const data=getData(),p=data.planning,scenarios=data.records.filter(r=>r.kind==='scenario');
    const end=data.forecast.daily.slice(0,horizon).at(-1);
    const availableSame=data.forecast.daily.every(r=>r.cash_cents===r.available_cents);
    const gap=(p?.required_monthly_cents||0)-(p?.expected_monthly_cents||0);
    return `<section class="tr-panel tr-outlook" aria-labelledby="tr-outlook-title">
      <header class="tr-outlook-header"><div><p class="tr-overline">LOOKING AHEAD</p><h2 id="tr-outlook-title">Your financial outlook</h2><p class="tr-subtle">Your cash outlook and the income it takes to stay on track.</p></div><span class="tr-status">${data.coverage.provisional||p?.provisional?'Provisional · inputs need review':'Based on reviewed inputs'}</span></header>
      ${p?`<div class="tr-outlook-metrics">
        <button data-action="plan-income"><span>Expected income <small>/ month</small></span><strong>${money(p.expected_monthly_cents)}</strong><em>Reviewed history + your adjustments ↗</em></button>
        <button data-action="plan-nav" data-view="planning"><span>Income needed <small>/ month</small></span><strong>${money(p.required_monthly_cents)}</strong><em>Bills, spending, debt & goals ↗</em></button>
        <button data-action="plan-costs"><span>${gap>0?'Income gap':'Room in your plan'} <small>/ month</small></span><strong class="${gap>0?'tr-gap':''}">${money(Math.abs(gap))}</strong><em>${gap>0?'More income or lower costs needed':'Income above planned needs'} ↗</em></button>
      </div>`:''}
      <div class="tr-outlook-grid">
        <section class="tr-outlook-cash" aria-labelledby="tr-cash-title"><div class="tr-chart-heading"><div><h3 id="tr-cash-title">Cash outlook</h3><p>Balance after bills & spending · USD</p></div><div class="tr-horizon" role="group" aria-label="Cash forecast horizon">${[[30,'30D'],[90,'90D'],[365,'1Y']].map(([n,label])=>`<button data-action="forecast-horizon" data-days="${n}" aria-pressed="${horizon===n}">${label}</button>`).join('')}</div></div>
        ${plot('tr-forecast','Observed and projected cash balances in USD','outlook-cash')}
        <div class="tr-cash-result"><div><span>Available in ${horizon===365?'12 months':horizon+' days'}</span><strong>${money(end?.available_cents)}</strong></div><div><span>First projected shortfall · full year</span><strong>${data.forecast.first_shortfall_on?date(data.forecast.first_shortfall_on):'None projected'}</strong></div></div>
        <p class="tr-chart-caption">${availableSame?'Cash and available cash are equal; shown as one line.':'Available cash subtracts money reserved for goals and medical bills.'} Select a point to see its details.</p>
        </section>
        ${p?`<section class="tr-outlook-income" aria-labelledby="tr-income-title"><div class="tr-chart-heading"><div><h3 id="tr-income-title">Income vs. what you need</h3><p>Monthly rate · next 12 months · USD</p></div></div>
        ${plot('tr-income-needs','Expected monthly income, income needed and reviewed historical income in USD','outlook-income')}
        <p class="tr-chart-caption">${incomeSeries(p).sameHistorical?'Expected income currently equals your historical average. The dashed baseline shares the same path.':'Expected income includes your source adjustments; the historical baseline uses reviewed income.'} Monthly rates stay flat until you change the plan.</p>
        <div class="tr-income-actions">${btn('Edit expected income','plan-income')}${jump('See cost breakdown','planning')}</div>
        </section>`:''}
      </div>
      <div class="tr-outlook-controls"><div class="tr-actions"><label for="tr-scenario">Cash scenario</label><select id="tr-scenario"><option value="">Current path</option>${scenarios.map(s=>`<option value="${s.id}" ${Number(scenarioId)===s.id?'selected':''}>${esc(s.name)}</option>`).join('')}</select>${btn('Create a what-if','add','data-kind="scenario"')}</div><p>Cash scenarios affect the cash outlook. Income adjustments affect the monthly plan.</p></div>
      <details class="tr-assumptions"><summary>Forecast assumptions & missing information</summary><p class="tr-subtle">The cash outlook uses scheduled events and historical spending. The monthly income plan is a separate planning view; amounts are not automatically added to the daily forecast.</p><ul>${[...data.forecast.assumptions,...(p?.assumptions||[])].map(a=>`<li>${esc(a)}</li>`).join('')}</ul><div class="tr-actions">${btn(seasonal?'Seasonal history ✓':'Use seasonal history','toggle-seasonal')}${jump('Review accounts','accounts')}${jump('Income & debt scenarios','planning')}</div></details>
    </section>`;
  }
  function draw() {
    const data=getData(),p=data.planning,rows=data.forecast.daily.slice(0,horizon);
    const history=data.net_worth_history.filter(h=>h.date<=data.range.end).slice(-Math.min(90,horizon));
    const series=cashTimeline(history,rows,data.comparison?.daily||[]);
    const sets=[{...line('Observed cash',series.observed,'#273b49'),pointRadius:series.observed.filter(v=>v!==null).length===1?3:0}, {...line(series.sameAvailable?'Projected cash · available':'Projected cash',series.cash,'#187a70'),borderDash:[6,3]}];
    if(!series.sameAvailable)sets.push(line('Available after reserves',series.available,'#5365b8'));
    if(data.comparison)sets.push({...line('What-if · available',series.comparison,'#b86a2d'),borderDash:[3,4]});
    chart('tr-forecast','line',series.rows.map(r=>`${date(r.date)}, ${r.date.slice(0,4)}${r.observed?' · observed':''}`),sets,i=>series.rows[i].observed?evidence({date:series.rows[i].date}):forecastDetail(series.rows[i]),{boundary:series.boundary,tickLabels:series.rows.map(r=>date(r.date))});
    if(!p)return;
    const incomes=incomeSeries(p);
    chart('tr-income-needs','line',p.monthly.map(r=>month(r.date)),[
      {...line('Expected income',incomes.expected,'#187a70'),borderWidth:incomes.sameHistorical?5:2.5,order:1},
      {...line('Income needed',incomes.required,'#b45b31'),borderDash:[8,4]},
      {...line('Historical income',incomes.historical,'#566c93'),borderDash:[2,5]},
    ],i=>{
      const row=p.monthly[i];
      dialog(`Income plan · ${month(row.date)}`,`<div class="tr-block"><div class="tr-row">Expected monthly income<strong>${precise(row.expected_income_cents)}</strong></div><div class="tr-row">Committed costs<strong>${precise(p.fixed_monthly_cents)}</strong></div><div class="tr-row">Adjustable spending<strong>${precise(p.flexible_monthly_cents)}</strong></div><div class="tr-row">Goals & extra debt payments<strong>${precise(p.required_monthly_cents-p.fixed_monthly_cents-p.flexible_monthly_cents)}</strong></div><div class="tr-row">Total monthly income needed<strong>${precise(row.required_income_cents)}</strong></div><p class="tr-subtle">Monthly rates, including the first partial month. Based on ${p.history_days} days of reviewed income. Missing debt terms and unreviewed transactions can change this plan.</p><div class="tr-actions">${jump('Inspect income & costs','planning')}</div></div>`,null);
    },{beginAtZero:true});
  }
  return {html,draw,setHorizon(value){if([30,90,365].includes(value))horizon=value;}};
}
