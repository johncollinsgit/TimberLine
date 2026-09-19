import { cashTimeline, incomeSeries, monthlyBalances, actualProjectedBalances, incomeRunwaySeries } from './chart-series.js';

export function outlookUI({getData,esc,money,precise,date,plot,chart,line,btn,dialog,evidence,forecastDetail}) {
  // The initial question is whether expected income will cover upcoming cash
  // needs. The cash-balance trend remains available, but does not lead with a
  // "runway" that assumes a large opening balance.
  let horizon=365,view='bars',monthlyView='runway',incomeSource='last-year';
  const month=d=>new Date(`${d}T12:00:00`).toLocaleDateString('en-US',{month:'short',year:'numeric'});
  const jump=(label,view)=>btn(label,'plan-nav',`data-view="${view}"`);
  function html(scenarioId,seasonal) {
    const data=getData(),p=data.planning,scenarios=data.records.filter(r=>r.kind==='scenario');
    const end=data.forecast.daily.slice(0,horizon).at(-1);
    const availableSame=data.forecast.daily.every(r=>r.cash_cents===r.available_cents);
    const gap=(p?.required_monthly_cents||0)-(p?.expected_monthly_cents||0);
    const runway=incomeRunwaySeries(data.income_runway||[],incomeSource);
    const expectedIncome=runway.projected.reduce((sum,value)=>sum+(value??0),0);
    const expectedOutflows=runway.outflows.reduce((sum,value)=>sum+value,0);
    const firstIncomeGap=(data.income_runway||[]).find((row,index)=>runway.gaps[index]!==null&&runway.gaps[index]<0);
    const incomeSourceLabel={"last-year":"the same future dates last year",plan:'your current income plan',recent:'recent reviewed income'}[incomeSource];
    return `<section class="tr-panel tr-outlook" aria-labelledby="tr-outlook-title">
      <header class="tr-outlook-header"><div><p class="tr-overline">LOOKING AHEAD</p><h2 id="tr-outlook-title">Your financial outlook</h2><p class="tr-subtle">Your cash outlook and the income it takes to stay on track.</p></div><span class="tr-status">${data.coverage.provisional||p?.provisional?'Provisional · inputs need review':'Based on reviewed inputs'}</span></header>
      ${p?`<div class="tr-outlook-metrics">
        <button data-action="plan-income"><span>Expected income <small>/ month</small></span><strong>${money(p.expected_monthly_cents)}</strong><em>Reviewed history + your adjustments ↗</em></button>
        <button data-action="plan-nav" data-view="planning"><span>Income needed <small>/ month</small></span><strong>${money(p.required_monthly_cents)}</strong><em>Bills, spending, debt & goals ↗</em></button>
        <button data-action="plan-costs"><span>${gap>0?'Income gap':'Room in your plan'} <small>/ month</small></span><strong class="${gap>0?'tr-gap':''}">${money(Math.abs(gap))}</strong><em>${gap>0?'More income or lower costs needed':'Income above planned needs'} ↗</em></button>
      </div>`:''}
      <div class="tr-outlook-tabs" role="tablist" aria-label="Outlook chart view">${[['lines','Trend lines'],['bars','Month by month'],['comparison','Actual vs. projected']].map(([key,label])=>`<button id="tr-outlook-${key}" role="tab" aria-selected="${view===key}" aria-controls="tr-outlook-charts" tabindex="${view===key?0:-1}" data-action="outlook-view" data-view="${key}">${label}</button>`).join('')}</div>
      <div id="tr-outlook-charts" class="tr-outlook-grid ${(view==='comparison'||(view==='bars'&&monthlyView==='runway'))?'tr-outlook-comparison':''}" role="tabpanel" aria-labelledby="tr-outlook-${view}">
        <section class="tr-outlook-cash" aria-labelledby="tr-cash-title"><div class="tr-chart-heading"><div><h3 id="tr-cash-title">${view==='comparison'?'Actual vs. projected cash':view==='bars'?(monthlyView==='runway'?'Income runway':'Cash by month'):'Cash outlook'}</h3><p>${view==='comparison'?'Recorded balances and future month-end estimates':view==='bars'?(monthlyView==='runway'?'Expected income and cash outflows · next 12 months':'Projected closing balances'):'Balance after bills & spending'} · USD</p></div>${view==='bars'&&monthlyView==='runway'?'':`<div class="tr-horizon" role="group" aria-label="Cash forecast horizon">${[[30,'30D'],[90,'90D'],[365,'1Y']].map(([n,label])=>`<button data-action="forecast-horizon" data-days="${n}" aria-pressed="${horizon===n}">${label}</button>`).join('')}</div>`}</div>
        ${view==='bars'?`<div class="tr-monthly-controls"><div class="tr-segmented" role="group" aria-label="Monthly chart content">${[['runway','Income runway'],['cash','Cash balance']].map(([key,label])=>`<button data-action="monthly-view" data-view="${key}" aria-pressed="${monthlyView===key}">${label}</button>`).join('')}</div>${monthlyView==='runway'?`<div class="tr-segmented" role="group" aria-label="Projected income source">${[['last-year','Same time last year'],['plan','Current income plan'],['recent','Recent reviewed income']].map(([key,label])=>`<button data-action="income-source" data-source="${key}" aria-pressed="${incomeSource===key}">${label}</button>`).join('')}</div>`:''}</div>`:''}
        ${plot('tr-forecast',view==='comparison'?'Actual and projected monthly cash balances in USD':view==='bars'?(monthlyView==='runway'?'Projected income, same-period-last-year income, and expected cash outflows in USD':'Projected closing cash balances by month in USD'):'Observed and projected cash balances in USD','outlook-cash')}
        <div class="tr-cash-result">${view==='bars'&&monthlyView==='runway'?`<div><span>Projected income · next 12 months</span><strong>${money(expectedIncome)}</strong></div><div><span>Expected cash outflows · next 12 months</span><strong>${money(expectedOutflows)}</strong></div><div><span>First income gap</span><strong>${firstIncomeGap?date(firstIncomeGap.date):'None projected'}</strong></div>`:`<div><span>Available in ${horizon===365?'12 months':horizon+' days'}</span><strong>${money(end?.available_cents)}</strong></div><div><span>First projected shortfall · full year</span><strong>${data.forecast.first_shortfall_on?date(data.forecast.first_shortfall_on):'None projected'}</strong></div>`}</div>
        <p class="tr-chart-caption">${view==='bars'&&monthlyView==='runway'?`Projected income currently uses ${incomeSourceLabel}. Change its source to compare the other evidence. Outflows include scheduled bills, debt payments, and estimated cash spending; transfers, asset sales, loan draws, and goal reserves are kept separate. This comparison does not silently add a new income assumption to the cash-balance forecast.`:view==='comparison'?'Actual bars use the last recorded balance in each month, with its date available on selection. The current month compares cash observed so far with its projected closing balance—not two amounts to add together. Missing observations stay blank. These are today’s forward estimates, not archived forecasts.':`${view==='bars'?'Bars use the final projected day in each month; first and last months may be partial. ':''}${availableSame?`Cash and available cash are equal; shown as one ${view==='bars'?'series':'line'}.`:'Available cash subtracts money reserved for goals and medical bills.'}`} Select a ${view==='lines'?'point':'bar'} to see its details.</p>
        </section>
        ${p&&view!=='comparison'&&!(view==='bars'&&monthlyView==='runway')?`<section class="tr-outlook-income" aria-labelledby="tr-income-title"><div class="tr-chart-heading"><div><h3 id="tr-income-title">Income vs. what you need</h3><p>Monthly rate · next 12 months · USD</p></div></div>
        ${plot('tr-income-needs','Expected monthly income, income needed and reviewed historical income in USD','outlook-income')}
        <p class="tr-chart-caption">${incomeSeries(p).sameHistorical?(view==='bars'?'Expected income currently equals your historical average; their bars have the same height.':'Expected income currently equals your historical average. The dashed baseline shares the same path.'):'Expected income includes your source adjustments; the historical baseline uses reviewed income.'} Monthly rates stay flat until you change the plan.</p>
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
    if(view==='comparison') {
      const months=actualProjectedBalances(data.net_worth_history,rows);
      const scenarioMonths=new Map(monthlyBalances((data.comparison?.daily||[]).slice(0,horizon)).map(r=>[r.date.slice(0,7),r]));
      const bars=[bar('Actual · last recorded',months.map(r=>r.actual?.cash_cents??null),'#273b49'),bar('Projected · month end',months.map(r=>r.projected?.cash_cents??null),'#69a99f')];
      if(data.comparison)bars.push(bar('What-if · month end',months.map(r=>scenarioMonths.get(r.month)?.cash_cents??null),'#b86a2d'));
      chart('tr-forecast','bar',months.map(r=>month(`${r.month}-01`)),bars,i=>{
        const row=months[i],scenario=scenarioMonths.get(row.month);
        dialog(`Actual vs. projected · ${month(`${row.month}-01`)}`,`<div class="tr-block"><div class="tr-row">Actual cash${row.actual?` · ${date(row.actual.date)}`:''}<strong>${row.actual?precise(row.actual.cash_cents):'No recorded balance'}</strong></div><div class="tr-row">Projected cash${row.projected?` · ${date(row.projected.date)}`:''}<strong>${row.projected?precise(row.projected.cash_cents):'No forward estimate'}</strong></div>${scenario?`<div class="tr-row">What-if cash · ${date(scenario.date)}<strong>${precise(scenario.cash_cents)}</strong></div>`:''}<p class="tr-subtle">Balances are snapshots, not income or spending totals. A midmonth observation is not a completed month. Forward estimates use the current forecast; no past prediction is inferred.</p><div class="tr-actions">${jump('Review accounts','accounts')}</div></div>`,null);
      },{beginAtZero:true});
      return;
    }
    if(view==='bars') {
      if(monthlyView==='runway') {
        const rows=data.income_runway||[],runway=incomeRunwaySeries(rows,incomeSource);
        const sourceLabel={"last-year":"Same-time-last-year income",plan:'Current income plan',recent:'Recent reviewed income'}[incomeSource];
        chart('tr-forecast','bar',rows.map(row=>month(row.date)),[
          {...bar('Expected cash outflows',runway.outflows,'#b45b31'),order:3},
          {...line('Projected income',runway.projected,'#187a70'),type:'line',order:1,pointRadius:3},
          {...line('Income · same time last year',runway.lastYear,'#566c93'),type:'line',borderDash:[4,4],order:2,pointRadius:2},
        ],i=>{
          const row=rows[i],income=runway.projected[i],gap=runway.gaps[i];
          dialog(`Income runway · ${month(row.date)}`,`<div class="tr-block"><div class="tr-row">Projected income · ${sourceLabel}<strong>${income===null?'No estimate':precise(income)}</strong></div><div class="tr-row">Income · same time last year<strong>${row.last_year_income_cents===null?'No recorded history':precise(row.last_year_income_cents)}</strong></div><div class="tr-row">Expected cash outflows<strong>${precise(row.outflow_cents)}</strong></div><div class="tr-row">Income after expected outflows<strong class="${gap!==null&&gap<0?'tr-gap':''}">${gap===null?'Needs income history':precise(gap)}</strong></div><p class="tr-subtle">${date(row.from)} – ${date(row.through)} compared with the same dates last year. Outflows are bills, debt payments, and estimated cash spending. They do not include transfers, loan draws, asset sales, or savings reserves.</p><div class="tr-actions">${row.last_year_income_ids?.length?btn('View last-year income','plan-evidence',`data-ids="${esc(JSON.stringify(row.last_year_income_ids))}"`):''}${jump('Edit expected income','planning')}</div></div>`,null);
        },{beginAtZero:true});
        return;
      }
      const months=monthlyBalances(rows),comparison=new Map((data.comparison?.daily||[]).map(r=>[r.date,r]));
      const bars=[bar(series.sameAvailable?'Projected cash · available':'Projected cash',months.map(r=>r.cash_cents),'#187a70')];
      if(!series.sameAvailable)bars.push(bar('Available after reserves',months.map(r=>r.available_cents),'#5365b8'));
      if(data.comparison)bars.push(bar('What-if · available',months.map(r=>comparison.get(r.date)?.available_cents??null),'#b86a2d'));
      chart('tr-forecast','bar',months.map(r=>`${month(r.date)} · through ${date(r.date)}`),bars,i=>forecastDetail(months[i]),{beginAtZero:true,tickLabels:months.map(r=>month(r.date))});
    } else chart('tr-forecast','line',series.rows.map(r=>`${date(r.date)}, ${r.date.slice(0,4)}${r.observed?' · observed':''}`),sets,i=>series.rows[i].observed?evidence({date:series.rows[i].date}):forecastDetail(series.rows[i]),{boundary:series.boundary,tickLabels:series.rows.map(r=>date(r.date))});
    if(!p)return;
    const incomes=incomeSeries(p);
    chart('tr-income-needs',view==='bars'?'bar':'line',p.monthly.map(r=>month(r.date)),view==='bars'?[
      bar('Expected income',incomes.expected,'#187a70'),bar('Income needed',incomes.required,'#b45b31'),bar('Historical income',incomes.historical,'#566c93'),
    ]:[
      {...line('Expected income',incomes.expected,'#187a70'),borderWidth:incomes.sameHistorical?5:2.5,order:1},
      {...line('Income needed',incomes.required,'#b45b31'),borderDash:[8,4]},
      {...line('Historical income',incomes.historical,'#566c93'),borderDash:[2,5]},
    ],i=>{
      const row=p.monthly[i];
      dialog(`Income plan · ${month(row.date)}`,`<div class="tr-block"><div class="tr-row">Expected monthly income<strong>${precise(row.expected_income_cents)}</strong></div><div class="tr-row">Committed costs<strong>${precise(p.fixed_monthly_cents)}</strong></div><div class="tr-row">Adjustable spending<strong>${precise(p.flexible_monthly_cents)}</strong></div><div class="tr-row">Goals & extra debt payments<strong>${precise(p.required_monthly_cents-p.fixed_monthly_cents-p.flexible_monthly_cents)}</strong></div><div class="tr-row">Total monthly income needed<strong>${precise(row.required_income_cents)}</strong></div><p class="tr-subtle">Monthly rates, including the first partial month. Based on ${p.history_days} days of reviewed income. Missing debt terms and unreviewed transactions can change this plan.</p><div class="tr-actions">${jump('Inspect income & costs','planning')}</div></div>`,null);
    },{beginAtZero:true});
  }
  const bar=(label,values,color)=>({label,data:values,backgroundColor:color,borderColor:color,borderRadius:3,maxBarThickness:24});
  return {html,draw,setView(value){if(['lines','bars','comparison'].includes(value))view=value;},setMonthlyView(value){if(['runway','cash'].includes(value))monthlyView=value;},setIncomeSource(value){if(['last-year','plan','recent'].includes(value))incomeSource=value;},setHorizon(value){if([30,90,365].includes(value))horizon=value;}};
}
