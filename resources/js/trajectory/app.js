import Chart from 'chart.js/auto';
import './app.css';
import { minor, percentShare } from './money.js';

const root = document.querySelector('#trajectory');
if (root) boot();
function boot() {
  const $ = (s) => root.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = (v) => v === null || v === undefined ? 'Unavailable' : new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}).format(v/100);
  const precise = (v) => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v/100);
  const date = (v) => v ? new Date(v.slice(0,10)+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}) : 'Not observed';
  const title = (s) => s.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
  const spaces = JSON.parse(root.dataset.spaces || '[]');
  let active = spaces[0]?.id, data, tab = 'overview', charts = [], chartTables = {}, filter = {}, scenarioId = '', formAction;
  $('#tr-space').innerHTML = spaces.map(s=>`<option value="${s.id}">${esc(s.name)} · ${s.kind==='household'?'Personal':'Business'}</option>`).join('');
  const api = async (path, method='GET', body=null) => {
    const headers = {Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''};
    if (body && !(body instanceof FormData)) headers['Content-Type']='application/json';
    const response = await fetch(path,{method,headers,body:body ? body instanceof FormData ? body : JSON.stringify(body) : null});
    let result; try {result=await response.json();} catch {throw new Error('The server could not complete this request. Please refresh and try again.');}
    if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : result.message || 'Request failed.');
    return result;
  };
  const endpoint = (path) => `/trajectory/spaces/${active}${path}`;
  const notice = (message) => {$('#tr-feedback').textContent=message;$('#tr-feedback').hidden=false;};
  const load = async () => {
    if (!active) {$('#tr-context').textContent='Your private financial workspace';$('#tr-content').innerHTML='<div class="tr-empty"><h2>Your next chapter starts here.</h2>Your Trajectory pilot has not been enabled yet.<br>Once enabled, connect an account or import a statement to begin.</div>';$('#tr-add').disabled=true;return;}
    $('#tr-content').classList.add('tr-loading');$('#tr-content').setAttribute('aria-busy','true');
    try {data=await api(endpoint(`/dashboard?range=${$('#tr-range').value}${scenarioId?`&scenario_id=${scenarioId}`:''}`));render();}
    catch(e){notice(e.message);}
    finally {$('#tr-content').classList.remove('tr-loading');$('#tr-content').setAttribute('aria-busy','false');}
  };
  const metric = (label,value,sub) => `<div class="tr-metric"><div class="tr-kicker">${esc(label)}</div><div class="tr-number">${money(value)}</div><div class="tr-subtle">${esc(sub)}</div></div>`;
  const panel = (heading,body,action='') => `<section class="tr-panel"><header class="tr-panel-head"><h2>${esc(heading)}</h2>${action}</header>${body}</section>`;
  const empty = (message) => `<div class="tr-empty">${esc(message)}</div>`;
  const plot = (id,label,size='') => `<div class="tr-chart ${size}"><canvas id="${id}" role="img" aria-label="${esc(label)}"></canvas></div><button class="tr-chart-data" data-action="chart-data" data-chart="${id}">View chart data</button>`;
  const btn = (label,action,attrs='') => `<button data-action="${action}" ${attrs}>${esc(label)}</button>`;
  const chart = (id,type,labels,datasets,click) => {
    const canvas=document.getElementById(id);if(!canvas)return;
    chartTables[id]={labels,datasets,click,title:canvas.getAttribute('aria-label')};
    const instance=new Chart(canvas,{type,data:{labels,datasets},options:{responsive:true,maintainAspectRatio:false,animation:!matchMedia('(prefers-reduced-motion: reduce)').matches,interaction:{intersect:false,mode:'index'},plugins:{legend:{position:'bottom',labels:{usePointStyle:true,boxWidth:7,font:{size:11},padding:20}},tooltip:{callbacks:{label:(ctx)=>`${ctx.dataset.label || ctx.label}: ${money(ctx.parsed.y ?? ctx.parsed)}`}}},scales:type==='doughnut'?{}:{x:{grid:{display:false},ticks:{maxTicksLimit:8,font:{size:10}}},y:{grid:{color:'#edf1ed'},border:{display:false},ticks:{callback:(v)=>money(v),font:{size:10}}}},onClick:(event,elements)=>{if(elements[0]&&click)click(elements[0].index);}}});charts.push(instance);
  };
  const line = (label,values,color,fill=false) => ({label,data:values,borderColor:color,backgroundColor:color+'14',fill,tension:.2,borderWidth:2,pointRadius:0,pointHitRadius:12});
  const showTransactions = (next) => {filter=next;tab='transactions';render();};
  function render() {
    charts.forEach(c=>c.destroy());charts=[];chartTables={};
    root.querySelectorAll('[data-tab]').forEach(b=>{if(b.dataset.tab===tab)b.setAttribute('aria-current','page');else b.removeAttribute('aria-current');});
    $('#tr-context').textContent=`${date(data.range.start)} – ${date(data.range.end)} · ${data.coverage.history_days} days of baseline history${data.coverage.provisional?' · Provisional forecast':''}`;
    const views={overview,transactions,bills,assets,business,connections};
    $('#tr-content').innerHTML=views[tab]();
    if(tab==='overview') drawOverview();
    if(tab==='assets') drawAssets();
    if(tab==='business') drawBusiness();
  }
  function overview() {
    const summary=data.summary, end=data.forecast.daily.at(-1);
    const scenarios=data.records.filter(r=>r.kind==='scenario');
    return `${data.coverage.blockers.length?`<div class="tr-notice">${data.coverage.blockers.map(esc).join(' ')} ${btn('Review accounts','connections')}</div>`:''}
      <div class="tr-metrics">${metric('Money coming in',summary.income_cents,'This selected period')}${metric('Money going out',summary.spending_cents,'Transfers excluded')}${metric('Cash on hand',summary.cash_cents,'Observed account balances')}${metric('Net worth',summary.net_worth_cents,summary.net_worth_complete?'Assets minus liabilities':'Partial · values missing')}</div>
      ${panel('Where you’re headed',`<p class="tr-subtle">Cash after planned bills and spending. Goal reserves show what remains available.</p>${plot('tr-forecast','Projected daily cash and cash available after goals','large')}<div class="tr-chart-foot"><div class="tr-subtle">Available in 12 months<strong>${money(end?.available_cents)}</strong></div><div class="tr-subtle">First projected shortfall<strong>${data.forecast.first_shortfall_on?date(data.forecast.first_shortfall_on):'None in this scenario'}</strong></div><div class="tr-subtle">Plan confidence<strong>${data.coverage.provisional?'Needs more evidence':'90-day baseline'}</strong></div></div><p class="tr-subtle">${data.forecast.assumptions.map(esc).join(' ')}</p>`,`<div class="tr-actions"><label class="sr-only" for="tr-scenario">Compare a scenario</label><select id="tr-scenario"><option value="">Current path</option>${scenarios.map(s=>`<option value="${s.id}" ${Number(scenarioId)===s.id?'selected':''}>${esc(s.name)}</option>`).join('')}</select>${btn('What if…','add','data-kind="scenario"')}</div>`)}
      <div class="tr-grid">${panel('Income and spending',plot('tr-cashflow','Daily income versus spending')+`<p class="tr-subtle">Select a date to inspect its transactions.</p>`)}${panel('Where your money went',data.categories.length?plot('tr-categories','Spending by category'):empty('Import transactions to see your spending breakdown.'))}</div>
      <div class="tr-grid equal">${panel('Coming up next',data.bills.length?data.bills.slice(0,6).map(b=>`<div class="tr-row"><div><strong>${esc(b.name)}</strong><div class="tr-subtle">${date(b.date)}${b.estimated?' · Estimated':''}</div></div><div class="tr-money">${money(b.amount_cents)} ${btn('View','edit',`data-id="${b.record_id}"`)}</div></div>`).join(''):empty('Confirm recurring bills to build your calendar.'),btn('See bills','bills'))}${panel('Change your trajectory',data.recommendations.length?data.recommendations.slice(0,5).map((r,i)=>`<div class="tr-row"><div><strong>${esc(r.title)}</strong><div class="tr-subtle">Up to ${money(r.monthly_savings_cents)}/month · ${money(r.yearly_cash_impact_cents)} over a year</div></div>${btn('Explore','recommendation',`data-index="${i}"`)}</div>`).join(''):empty('Review your spending profile to surface supported saving opportunities.'))}</div>
      <div class="tr-grid equal">${panel('Five years from here',plot('tr-fiveyear','Monthly five-year cash projection'))}${panel('Your spending calendar',heatmap())}</div>`;
  }
  function heatmap() {
    const map=new Map(data.daily_series.map(d=>[d.date,d.spending_cents]));
    const max=Math.max(1,...map.values()); const end=new Date(data.range.end+'T12:00:00');
    let html='<div class="tr-heatmap">';
    for(let i=34;i>=0;i--){const d=new Date(end);d.setDate(d.getDate()-i);const key=d.toISOString().slice(0,10),v=map.get(key)||0;html+=`<button data-action="date" data-date="${key}" data-level="${v>0?Math.max(1,Math.ceil(v/max*4)):1}" aria-label="${esc(key+': '+precise(v))}">${d.getDate()}<span>${v?money(v):'—'}</span></button>`;}
    return html+'</div><p class="tr-subtle">Last 35 days; amounts reflect the selected reporting period.</p>';
  }
  function drawOverview() {
    const rows=data.forecast.daily;
    const history=data.net_worth_history.filter(h=>h.date<=data.range.end && h.cash_cents!==undefined).slice(-90);
    const prefix=history.map(()=>null);
    const sets=[line('Observed cash',history.map(h=>h.cash_cents).concat(rows.map(()=>null)),'#345547'),line('Current path · cash',prefix.concat(rows.map(r=>r.cash_cents)),'#8fada1'),line('Available after goals',prefix.concat(rows.map(r=>r.available_cents)),'#176b52',true)];
    if(data.comparison)sets.push({...line('Scenario · available',prefix.concat(data.comparison.daily.map(r=>r.available_cents)),'#b48836'),borderDash:[5,4]});
    chart('tr-forecast','line',history.map(r=>date(r.date)).concat(rows.map(r=>date(r.date))),sets,i=>i<history.length?showTransactions({date:history[i].date}):forecastDetail(rows[i-history.length]));
    chart('tr-cashflow','bar',data.daily_series.map(r=>date(r.date)),[{label:'Income',data:data.daily_series.map(r=>r.income_cents),backgroundColor:'#4c8d72',borderRadius:3},{label:'Spending',data:data.daily_series.map(r=>r.spending_cents),backgroundColor:'#d9b896',borderRadius:3}],i=>showTransactions({date:data.daily_series[i].date}));
    const cats=data.categories.filter(c=>c.amount_cents>0);
    chart('tr-categories','doughnut',cats.map(c=>title(c.category)),[{data:cats.map(c=>c.amount_cents),backgroundColor:['#205c48','#4f8970','#8fbaa1','#becb9d','#b39760','#dfbf8e','#a6b8bc','#759498'],borderWidth:3,borderColor:'#fff'}],i=>showTransactions({category:cats[i].category}));
    chart('tr-fiveyear','line',data.forecast.monthly.map(r=>date(r.date)+' '+r.date.slice(0,4)),[line('Available cash',data.forecast.monthly.map(r=>r.available_cents),'#176b52',true)]);
    $('#tr-scenario')?.addEventListener('change',e=>{scenarioId=e.target.value;load();});
  }
  function transactions() {
    let rows=data.transactions;
    if(filter.category)rows=rows.filter(t=>t.category===filter.category);
    if(filter.date)rows=rows.filter(t=>t.date===filter.date);
    if(filter.review)rows=rows.filter(t=>!t.reviewed);
    if(filter.ids)rows=rows.filter(t=>filter.ids.includes(t.id));
    const body=rows.length?`<div class="tr-table-wrap"><table class="tr-table"><caption>${rows.length} transactions${Object.keys(filter).length?' · Filtered':''}. Positive amounts are money in.</caption><thead><tr><th>Date</th><th>Merchant</th><th>Category</th><th>Classification</th><th>Amount</th><th>Review</th></tr></thead><tbody>${rows.map(t=>`<tr><td>${date(t.date)}</td><td>${esc(t.merchant)}<div class="tr-subtle">${esc(title(t.flow))}</div></td><td>${esc(title(t.category))}</td><td>${t.face_punched?'<span class="tr-pill">Face Punched</span> ':''}${t.bullshit_spending?'<span class="tr-pill">Bullshit Spending</span>':''}</td><td class="tr-money ${t.amount_cents>0?'tr-positive':''}">${precise(t.amount_cents)}</td><td>${t.editable?btn(t.reviewed?'Edit':'Review','classify',`data-id="${t.id}"`):'Allocated'}</td></tr>`).join('')}</tbody></table></div>`:empty('No transactions match this view.');
    return panel('Your transactions',body,`<div class="tr-actions">${btn('Match transfers','reconciliation')}${btn('Needs review','review')}${btn('Clear filters','clear')}${btn('Import statement','import')}</div>`);
  }
  function bills() {
    const scheduleRecords=data.records.filter(r=>r.kind==='recurring');
    const goals=data.goals.map(g=>`<div class="tr-goal"><div class="tr-labels"><strong>${esc(g.name)}</strong>${btn('Edit','edit',`data-id="${g.id}"`)}</div><div class="tr-progress"><i style="width:${Math.min(100,g.saved_cents/Math.max(1,g.target_cents)*100)}%"></i></div><div class="tr-labels tr-subtle"><span>${money(g.saved_cents)} of ${money(g.target_cents)}</span><span>${date(g.target_on)}</span></div><p class="tr-subtle">${g.on_track?'On track':'Increase contributions'} · ${money(g.required_monthly_cents)}/month needed</p></div>`).join('');
    return `<div class="tr-grid equal">${panel('Bills and recurring income',scheduleRecords.length?scheduleRecords.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.cadence))} · ${date(r.data.next_due_on)} · ${r.data.confirmed?'Confirmed':'Needs confirmation'}</div></div><div class="tr-small-actions"><span>${money(r.data.amount_cents)}</span>${btn('Edit','edit',`data-id="${r.id}"`)}</div></div>`).join(''):empty('Add your mortgage, utilities, subscriptions, and payday.'),btn('Add bill','add','data-kind="recurring"'))}${panel('Savings goals',goals||empty('Give your future a target: emergency cash, a purchase, or paying down debt.'),btn('Add goal','add','data-kind="goal"'))}</div>${panel('Detected recurring patterns',data.recurring_suggestions.length?data.recurring_suggestions.map((r,i)=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.cadence))} · ${money(r.data.amount_cents)} · ${r.evidence_ids.length} supporting transactions</div></div>${btn('Review schedule','recurring-suggestion',`data-index="${i}"`)}</div>`).join(''):empty('Recurring suggestions appear after at least three reasonably spaced transactions.'))}`;
  }
  function assets() {
    const s=data.summary;
    return `<div class="tr-metrics">${metric('Net worth',s.net_worth_cents,s.net_worth_complete?'Assets minus debts':'Partial valuation')}${metric('Interest this period',s.selected_interest_cents,'Observed charges')}${metric('Recorded interest',s.recorded_interest_cents,'All available imported history')}${metric('Remaining interest',s.projected_interest_cents,'Estimated from reviewed debt terms')}</div>
      <div class="tr-grid equal">${panel('Net-worth history',data.net_worth_history.length?plot('tr-networth','Observed net worth over time'):empty('Daily snapshots begin after your accounts are added. History is never fabricated.'))}${panel('Assets and liabilities',plot('tr-allocation','Assets versus liabilities'))}</div>
      ${data.debt_suggestions.length?panel('Bank debt details to review', data.debt_suggestions.map((d,i)=>`<div class="tr-row"><div><strong>${esc(d.name)}</strong><p class="tr-subtle">${esc(d.note)}</p></div>${btn('Review terms','debt-suggestion',`data-index="${i}"`)}</div>`).join('')):''}
      ${panel('Your debts',data.debts.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Debt</th><th>Balance</th><th>APR</th><th>Recorded interest</th><th>Projected interest</th><th>Payoff</th><th></th></tr></thead><tbody>${data.debts.map(d=>`<tr><td>${esc(d.name)}</td><td>${money(d.balance_cents)}</td><td>${(d.apr_bps/100).toFixed(2)}%</td><td>${money(d.recorded_interest_cents)}</td><td>${money(d.projection.interest_cents)}</td><td>${d.projection.payoff_on?date(d.projection.payoff_on)+' '+d.projection.payoff_on.slice(0,4):title(d.projection.status)}</td><td>${btn('Edit','edit',`data-id="${d.id}"`)}</td></tr>`).join('')}</tbody></table></div>${plot('tr-debts','Debt balance through payoff')}`:empty('Add reviewed loan or credit-card terms to project payoff and interest.'),btn('Add debt','add','data-kind="debt"'))}
      <div class="tr-grid equal">${panel('Gold and silver',`<p class="tr-subtle">Spot metal value · ${esc(title(data.quotes.status))}. Coin premiums are not inferred.</p>${data.metals.map(m=>`<div class="tr-row"><div><strong>${esc(m.name)}</strong><div class="tr-subtle">${esc(m.quantity)} × ${esc(m.weight)} ${esc(m.unit)} · ${(m.purity_bps/100).toFixed(2)}% pure<br>Cost ${money(m.cost_basis_cents)} · Gain ${money(m.gain_cents)}</div></div><div><strong>${money(m.value_cents)}</strong><div class="tr-small-actions">${btn('Edit','edit',`data-id="${m.id}"`)}${btn('Record sale','sell',`data-id="${m.id}"`)}</div></div></div>`).join('')||empty('Record physical gold and silver by acquisition lot.')}${data.metals.length?plot('tr-metals','Metals cost basis versus current value','small'):''}`,btn('Add metals','add','data-kind="metal"'))}${panel('Other assets',data.assets.map(a=>`<div class="tr-row"><div><strong>${esc(a.name)}</strong><div class="tr-subtle">${esc(title(a.asset_type))} · Valued ${date(a.observed_on)}</div></div><div>${money(a.value_cents)} ${btn('Edit','edit',`data-id="${a.id}"`)}</div></div>`).join('')||empty('Add a property, vehicle, or manually valued investment.'),btn('Add asset','add','data-kind="asset"'))}</div>`;
  }
  function drawAssets() {
    chart('tr-networth','line',data.net_worth_history.map(r=>date(r.date)),[line('Net worth',data.net_worth_history.map(r=>r.net_worth_cents),'#176b52',true)]);
    chart('tr-allocation','doughnut',['Assets','Liabilities'],[{data:[Math.max(0,data.summary.assets_cents),Math.max(0,data.summary.liabilities_cents)],backgroundColor:['#397c5e','#cc9c79'],borderWidth:4}]);
    if(data.debts.length){const longest=data.debts.reduce((a,d)=>d.projection.rows.length>a.length?d.projection.rows:a,[]);chart('tr-debts','line',longest.map(r=>date(r.date)+' '+r.date.slice(0,4)),data.debts.map((d,i)=>line(d.name,d.projection.rows.map(r=>r.balance_cents),['#176b52','#b48836','#6b869c'][i%3])));}
    chart('tr-metals','bar',data.metals.map(m=>m.name),[{label:'Cost basis',data:data.metals.map(m=>m.cost_basis_cents),backgroundColor:'#9baea0',borderRadius:4},{label:'Spot value',data:data.metals.map(m=>m.value_cents),backgroundColor:'#b69a52',borderRadius:4}]);
  }
  function business() {
    const b=data.business;
    if(!b)return empty('Choose an authorized business space to see company costs, payroll, and household-support targets. Your household invitation does not grant business access.');
    return `<div class="tr-notice">${b.ledger.income_cents===null?'No QuickBooks report matches this period. Connect or refresh the accounting Branch.':'QuickBooks is the accounting source for this period.'} Bank activity and sales-channel evidence are not added to ledger revenue.</div>
      <div class="tr-metrics">${metric('Company revenue',b.ledger.income_cents,'QuickBooks report')}${metric('Company expenses',b.ledger.expenses_cents,'QuickBooks report')}${metric('Payroll detail',(b.payroll.length?b.payroll.reduce((s,r)=>s+r.total_cents,0):null),b.payroll.length?'Imported pay periods ending in range':'No payroll detail imported')}${metric('Observed owner income',data.transactions.filter(t=>['owner_distribution','owner_wages'].includes(t.flow)).reduce((s,t)=>s+Math.abs(t.amount_cents),0),'Reviewed owner transactions')}</div>
      ${panel('How much must the company produce?',b.reliance.status==='estimated'?`<div class="tr-metrics">${b.reliance.targets.map(t=>metric(`${t.percent}% household support`,t.required_revenue_cents,`${money(t.household_cents)} for lifestyle + goals`)).join('')}</div>${plot('tr-reliance','Required revenue at 25, 50 and 100 percent household support')}`:empty('Review fixed costs, variable margins, reserves, and household needs to calculate reliable targets.'),btn('Review assumptions','add','data-kind="reliance"'))}
      <div class="tr-grid equal">${panel('Sales-channel evidence',b.channels.channels.length?plot('tr-channels','Operational sales by channel')+`<p class="tr-subtle">Source coverage only. Payouts and QuickBooks revenue are not additive.</p>`:empty('Sales appear here from your existing Everbranch integrations.'))}${panel('Company cost review',b.insights.map(i=>`<div class="tr-row"><div><strong>${esc(i.title)}</strong><p class="tr-subtle">${esc(i.explanation)}</p></div><strong>${money(i.cost_cents)}</strong></div>`).join('')||empty('Import employee-level payroll evidence to review overtime and labor costs.'),btn('Import payroll','payroll-import'))}</div>
      ${panel('Employee cost detail',b.payroll.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Employee</th><th>Period ending</th><th>Wages</th><th>Overtime</th><th>Taxes & benefits</th><th>Total</th></tr></thead><tbody>${b.payroll.map(p=>`<tr><td>${esc(p.employee)}</td><td>${date(p.period_end)}</td><td>${money(p.wages_cents)}</td><td>${money(p.overtime_cents)}</td><td>${money(p.employer_taxes_cents+p.benefits_cents)}</td><td>${money(p.total_cents)}</td></tr>`).join('')}</tbody></table></div>`:empty('No employee costs available.'),btn('Add payroll detail','add','data-kind="payroll"'))}
      ${panel('Material cost evidence · all available jobs',b.materials.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Material</th><th>Quantity</th><th>Consumed</th><th>Unit cost</th><th>Consumed cost</th></tr></thead><tbody>${b.materials.map(m=>`<tr><td>${esc(m.name)}</td><td>${esc(m.quantity)} ${esc(m.unit)}</td><td>${esc(m.used_quantity)}</td><td>${money(m.unit_cost_cents)}</td><td>${money(m.consumed_cost_cents)}</td></tr>`).join('')}</tbody></table></div>`:empty('Material quantities and costs appear from your Everbranch jobs. Missing costs remain unavailable.'))}`;
  }
  function drawBusiness(){const b=data.business;if(!b)return;chart('tr-reliance','bar',b.reliance.targets.map(t=>`${t.percent}%`),[{label:'Required monthly revenue',data:b.reliance.targets.map(t=>t.required_revenue_cents),backgroundColor:['#bfd6c8','#6a9e80','#206248'],borderRadius:6}]);chart('tr-channels','bar',b.channels.channels.map(c=>c.label),[{label:'Operational sales',data:b.channels.channels.map(c=>c.revenue_cents),backgroundColor:'#518669',borderRadius:4}]);}
  function connections() {
    return `<div class="tr-grid equal">${panel('Bank accounts',`<p class="tr-subtle">Connect USAA, Chase, or Relay through the bank picker. Each account’s available history and debt coverage may differ.</p><div class="tr-actions" style="margin:18px 0">${btn('Connect a bank','connect',data.providers.plaid?'':'disabled')}${btn('Add manual account','account')}${btn('Import statement','import')}</div>${!data.providers.plaid?'<div class="tr-notice">Bank connections are not configured yet. Manual accounts and statement imports are available.</div>':''}${data.accounts.map(a=>`<div class="tr-row"><div><strong>${esc(a.name)}</strong><div class="tr-subtle">${esc(title(a.kind))} · Observed ${date(a.observed_at)}<br>History begins ${date(a.history_start)}</div></div><div>${money(a.balance_cents)} ${btn('Update balance','balance',`data-id="${a.id}"`)}</div></div>`).join('')}${data.connections.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution_name||'Bank connection')}</strong><div class="tr-subtle">${esc(title(c.status))} · ${date(c.synced_at)}</div></div><div class="tr-small-actions">${btn('Sync','sync',`data-id="${c.id}"`)}${btn('Reconnect','reconnect',`data-id="${c.id}"`)}${btn('Disconnect','disconnect',`data-id="${c.id}"`)}</div></div>`).join('')}`)}
      ${panel('Your spending rules',`<p class="tr-subtle">You decide what counts as Bullshit Spending. Face Punched stays a separate one-time classification.</p><div class="tr-actions" style="margin:18px 0">${btn('Edit category defaults','profile')}</div>${data.records.filter(r=>r.kind==='rule').map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.classification.category))} · Exact merchant rule</div></div>${btn('Remove','archive',`data-id="${r.id}"`)}</div>`).join('')}`)}</div>
      <div class="tr-grid equal">${panel('Sharing and linked spaces',`<p class="tr-subtle">Personal and business permissions stay separate. A partner invitation shares household finances only.</p><div class="tr-actions" style="margin:18px 0">${btn('Invite partner','invite')}${btn('Link business','link-spaces')}${btn('Company → household','combined')}</div><div id="tr-share-result"></div>`)}${panel('Text reminders',`<p class="tr-subtle">Opt in to bill reminders, low-cash alerts, weekly summaries, and simple expense-classification replies.</p><div class="tr-actions" style="margin:18px 0">${btn('Set up texting','sms',data.providers.sms?'':'disabled')}</div>${!data.providers.sms?'<p class="tr-subtle">Text delivery is not enabled for this pilot yet.</p>':''}`)}</div>`;
  }
  function field(name,label,type='text',value='',choices=null,full=false) {
    const attrs=`name="${esc(name)}" id="field-${esc(name)}"`;
    const input=choices?`<select ${attrs}>${choices.map(c=>`<option value="${esc(c.value??c)}" ${String(value)===String(c.value??c)?'selected':''}>${esc(c.label??title(String(c)))}</option>`).join('')}</select>`:type==='checkbox'?`<input ${attrs} type="checkbox" ${value?'checked':''}>`:`<input ${attrs} type="${type}" value="${esc(value)}" ${type==='number'?'step="any"':''}>`;
    return `<label class="tr-field ${full?'full':''}" for="field-${esc(name)}">${esc(label)}${input}</label>`;
  }
  function dialog(heading,html,action,save='Save') {
    $('#tr-dialog-title').textContent=heading;$('#tr-fields').innerHTML=html;$('#tr-form-error').hidden=true;$('#tr-save').textContent=save;$('#tr-save').hidden=!action;formAction=action;$('#tr-dialog').showModal();
  }
  function recordForm(kind,record=null,preset={}) {
    const d=record?.data||preset;
    const defaults={confirmed:false,reviewed:false,priority:1,purity_bps:9999,quantity:1,weight:1,distribution_retention_bps:10000};
    let html=field('name','Name','text',record?.name||preset.name||'',null,true);
    for(const [key,def] of Object.entries(data.definitions[kind])) {
      let value=d[key]??defaults[key]??(['money','percent'].includes(def.type)?0:'');
      if(['money','percent'].includes(def.type))value=value/100;
      const choices=['debt_record','recurring_record'].includes(def.type)?[{value:'',label:'Choose a record'},...data.records.filter(r=>r.kind===def.type.replace('_record','')).map(r=>({value:r.id,label:r.name}))]:def.type==='account'?[{value:'',label:'Select account'},...data.accounts.map(a=>({value:a.id,label:a.name}))]:def.type==='category'?(def.required?data.category_options:[{value:'',label:'All categories'},...data.category_options]):def.choices;
      html+=field(key,def.label,def.type==='boolean'?'checkbox':def.type==='date'?'date':['money','percent','decimal','integer'].includes(def.type)?'number':'text',value,choices);
    }
    dialog(`${record?'Edit':'Add'} ${title(kind)}`,html,async values=>{
      const payload={};
      for(const [key,def] of Object.entries(data.definitions[kind])) {
        let v=values.get(key);
        if(def.type==='boolean')v=v==='on';
        else if(v===''&&!def.required)continue;
        else if(['money','percent'].includes(def.type))v=minor(v);
        else if(['integer','account','debt_record','recurring_record'].includes(def.type))v=Number(v);
        payload[key]=v;
      }
      await api(endpoint(record?`/records/${record.id}`:'/records'),record?'PATCH':'POST',{kind,name:values.get('name'),data:payload,version:record?.version});
    });
  }
  function chooseAdd() {
    const kinds=['recurring','goal','debt','asset','metal','scenario',...(data?.business?['payroll','reliance']:[])];
    dialog('Add to your plan',`<div class="tr-choices tr-block">${kinds.map(k=>btn(title(k),'choose-kind',`data-kind="${k}"`)).join('')}</div>`,null);
  }
  function transactionForm(tx) {
    dialog('Review transaction',`<p class="tr-subtle">${esc(tx.merchant)} · ${precise(tx.amount_cents)}<br>${esc(tx.explanation)}</p>${field('category','Category','text',tx.category,data.category_options)}${field('flow','Money movement','text',tx.flow,['expense','income','refund','transfer','card_payment','asset_transfer','owner_wages','owner_distribution','reimbursement','debt_payment','duplicate'])}${field('face_punched','Face Punched · unexpected, one time','checkbox',tx.face_punched)}${field('bullshit_spending','Bullshit Spending · unnecessary to me','checkbox',tx.bullshit_spending)}${field('learn','Remember an exact merchant rule','checkbox',false)}<div class="tr-actions tr-block">${btn('Split personal / business','split',`data-id="${tx.id}"`)}${btn('Undo last review','undo',`data-id="${tx.id}"`)}</div>`,async v=>{await api(endpoint(`/transactions/${tx.id}`),'PATCH',{version:tx.version,category:v.get('category'),flow:v.get('flow'),face_punched:v.get('face_punched')==='on',bullshit_spending:v.get('bullshit_spending')==='on',learn:v.get('learn')==='on'});});
  }
  function importForm(kind='transactions') {
    const columns=kind==='transactions'?'id,date,merchant,amount,category':Object.keys(data.definitions.payroll).join(',');
    dialog(kind==='payroll'?'Import payroll detail':'Import statement',`<p class="tr-subtle">CSV or XLSX, up to 5,000 rows. Review before importing. ${kind==='transactions'?'Amounts in dollars: income positive, spending negative.':'Money columns are integer cents. Regular wages exclude the separate overtime column. All employer costs must be supplied; do not substitute net pay for wages.'}</p><div class="tr-code tr-block">${esc(columns)}</div>${kind==='transactions'?field('account_id','Account','text','',data.accounts.map(a=>({value:a.id,label:a.name}))):''}${field('file','Statement file','file','',null,true)}`,async v=>{
      v.set('kind',kind);const preview=await api(endpoint('/imports/preview'),'POST',v);
      $('#tr-dialog').close();
      dialog('Review import',`<p class="tr-subtle">${preview.count} rows ready. Preview of the first ${preview.preview.length}.</p><div class="tr-table-wrap"><table class="tr-table"><tbody>${preview.preview.map(r=>`<tr><td>${esc(r.date||r.period_end)}</td><td>${esc(r.merchant||r.employee)}</td><td>${money(r.amount_cents??r.wages_cents)}</td></tr>`).join('')}</tbody></table></div>`,async()=>{await api(endpoint('/imports/confirm'),'POST',{token:preview.token});notice(`${preview.count} rows imported.`);},'Confirm import');
      return false;
    },'Preview');
  }
  function forecastDetail(row) {
    dialog(`Projected ${date(row.date)}`,`<div class="tr-block"><div class="tr-row">Cash <strong>${money(row.cash_cents)}</strong></div><div class="tr-row">Available after goals <strong>${money(row.available_cents)}</strong></div><div class="tr-row">Reserved for goals <strong>${money(row.goal_reserve_cents)}</strong></div>${data.bills.filter(b=>b.date===row.date).map(b=>`<div class="tr-row">${esc(b.name)}<strong>${money(b.amount_cents)}</strong></div>`).join('')}<p class="tr-subtle">This projection includes confirmed schedules, estimated variable spending, debt payments, and goal reserves. Edit those inputs in Bills & goals or Wealth & debt.</p></div>`,null);
  }
  async function connect(connectionId=null) {
    const token=await api(endpoint('/banks/link'),'POST',{connection_id:connectionId});
    if(!window.Plaid)await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://cdn.plaid.com/link/v2/stable/link-initialize.js';script.onload=resolve;script.onerror=()=>reject(new Error('Unable to load bank linking.'));document.head.appendChild(script);});
    const handler=window.Plaid.create({token:token.link_token,onSuccess:async publicToken=>{try{if(connectionId)await api(endpoint(`/banks/${connectionId}/sync`),'POST',{});else await api(endpoint('/banks/exchange'),'POST',{public_token:publicToken});notice('Bank connected. History is being synchronized.');await load();}catch(e){notice(e.message);}finally{handler.destroy();}},onExit:()=>handler.destroy()});handler.open();
  }
  root.addEventListener('click',async e=>{
    const target=e.target.closest('[data-action]');if(!target)return;
    const a=target.dataset.action,id=Number(target.dataset.id),r=data?.records.find(r=>r.id===id),tx=data?.transactions.find(t=>t.id===id);
    try {
      if(['overview','bills','assets','business','connections','transactions'].includes(a)){tab=a;render();}
      else if(a==='add')recordForm(target.dataset.kind);
      else if(a==='choose-kind'){$('#tr-dialog').close();recordForm(target.dataset.kind);}
      else if(a==='chart-data'){
        const table=chartTables[target.dataset.chart];
        dialog(table.title,`<div class="tr-table-wrap"><table class="tr-table"><caption>${esc(table.title)}</caption><thead><tr><th>Period / category</th>${table.datasets.map(d=>`<th>${esc(d.label||'Value')}</th>`).join('')}<th></th></tr></thead><tbody>${table.labels.map((label,i)=>`<tr><td>${esc(label)}</td>${table.datasets.map(d=>`<td>${money(d.data[i]??null)}</td>`).join('')}<td>${table.click?`<button type="button" data-chart-row="${i}">Inspect</button>`:''}</td></tr>`).join('')}</tbody></table></div>`,null);
        $('#tr-fields').querySelectorAll('[data-chart-row]').forEach(b=>b.onclick=()=>{$('#tr-dialog').close();table.click(Number(b.dataset.chartRow));});
      }
      else if(a==='edit')recordForm(r.kind,r);
      else if(a==='classify')transactionForm(tx);
      else if(a==='date')showTransactions({date:target.dataset.date});
      else if(a==='review')showTransactions({review:true});
      else if(a==='clear')showTransactions({});
      else if(a==='reconciliation'){
        const matches=await api(endpoint('/reconciliation'));
        dialog('Review possible matches',matches.length?matches.map((m,i)=>`<div class="tr-block"><div class="tr-row"><div><strong>${esc(m.left.merchant)} ↔ ${esc(m.right.merchant)}</strong><p class="tr-subtle">${esc(m.explanation)} ${precise(m.left.amount_cents)} / ${precise(m.right.amount_cents)}</p></div><button type="button" data-match="${i}">Review</button></div></div>`).join(''):empty('No likely transfer or duplicate pairs in the recent history.'),null);
        $('#tr-fields').querySelectorAll('[data-match]').forEach(button=>button.onclick=()=>{const match=matches[Number(button.dataset.match)];dialog('Confirm matched transactions',`<p class="tr-subtle">${esc(match.left.merchant)} ↔ ${esc(match.right.merchant)}</p>${field('kind','Purpose','text',match.kind,match.kind==='duplicate'?['duplicate']:['transfer','card_payment','owner_wages','owner_distribution'])}`,async v=>{await api(endpoint('/reconciliation'),'POST',{left_id:match.left.id,right_id:match.right.id,left_version:match.left.version,right_version:match.right.version,kind:v.get('kind')});});});
      }
      else if(a==='combined'){
        const company=spaces.find(s=>s.kind==='business');if(!company)throw new Error('Link an authorized business first.');
        const combined=await api(endpoint('/combined?business_id='+company.id));
        dialog('Company → household',`<div class="tr-block"><div class="tr-flow"><div>${esc(combined.business)}<strong>${money(combined.company_owner_outflow_cents)}</strong></div><span>→</span><div>Matched owner income<strong>${money(combined.eliminated_transfers_cents)}</strong></div><span>→</span><div>Household spending<strong>${money(combined.household_spending_cents)}</strong></div></div><p class="tr-subtle">${money(combined.unmatched_owner_income_cents)} of household owner income still needs matching. Matched transfers are eliminated from combined external income and spending.</p><p>Combined net worth: <strong>${money(combined.net_worth_cents)}</strong> ${combined.net_worth_complete?'':'· incomplete valuation'}</p><p class="tr-subtle">${money(combined.excluded_business_equity_cents)} in linked business equity excluded to avoid counting company assets twice. ${esc(combined.net_worth_setup||'')}</p></div>`,null);
      }
      else if(a==='import')importForm();
      else if(a==='payroll-import')importForm('payroll');
      else if(a==='connect')await connect();
      else if(a==='reconnect')await connect(id);
      else if(a==='sync'){await api(endpoint(`/banks/${id}/sync`),'POST',{});notice('Sync queued.');}
      else if(a==='disconnect')dialog('Disconnect this bank?',`<p class="tr-subtle">Stop future synchronization and revoke this connection. Your imported history remains in Trajectory.</p>`,async()=>{await api(endpoint(`/banks/${id}`),'DELETE');},'Disconnect');
      else if(a==='archive'){await api(endpoint(`/records/${id}`),'DELETE',{version:r.version});await load();}
      else if(a==='undo'){await api(endpoint(`/transactions/${id}/undo`),'POST',{version:tx.version});$('#tr-dialog').close();await load();}
      else if(a==='recurring-suggestion'){const suggestion=data.recurring_suggestions[Number(target.dataset.index)];recordForm('recurring',null,{...suggestion.data,name:suggestion.name});}
      else if(a==='recommendation'){const rec=data.recommendations[Number(target.dataset.index)];dialog(rec.title,`<p class="tr-subtle">${esc(rec.explanation)}<br>Observed opportunity: ${money(rec.monthly_savings_cents)}/month. Use a scenario to explore a realistic reduction.</p><div class="tr-actions tr-block">${btn('View evidence','evidence',`data-index="${target.dataset.index}"`)}${btn('Try this saving','apply-recommendation',`data-index="${target.dataset.index}"`)}</div>`,null);}
      else if(a==='apply-recommendation'){const rec=data.recommendations[Number(target.dataset.index)];$('#tr-dialog').close();recordForm('scenario',null,{name:rec.title,reduction_category:rec.category,spending_reduction_bps:5000});}
      else if(a==='evidence'){$('#tr-dialog').close();showTransactions({ids:data.recommendations[Number(target.dataset.index)].evidence_ids});}
      else if(a==='account')dialog('Add a manual account',field('name','Account name')+field('kind','Type','text','cash',['cash','credit','loan','investment'])+field('balance','Current balance ($)','number',0)+field('observed_on','Balance date','date',new Date().toISOString().slice(0,10)),async v=>{await api(endpoint('/accounts'),'POST',{name:v.get('name'),kind:v.get('kind'),balance_cents:minor(v.get('balance')),observed_on:v.get('observed_on')});});
      else if(a==='balance'){const account=data.accounts.find(a=>a.id===id);dialog('Update observed balance',field('balance','Current balance ($)','number',account.balance_cents/100)+field('observed_on','Balance date','date',new Date().toISOString().slice(0,10)),async v=>{await api(endpoint(`/accounts/${id}`),'PATCH',{balance_cents:minor(v.get('balance')),observed_on:v.get('observed_on')});});}
      else if(a==='invite')dialog('Invite a household partner',field('email','Partner email','email','',null,true),async v=>{const invite=await api(endpoint('/invites'),'POST',{email:v.get('email')});dialog('Share this invitation',`<p class="tr-subtle">Only the signed-in user with that email can accept. This link expires in seven days.</p><div class="tr-code tr-block">${esc(invite.invite_url)}</div>`,null);return false;},'Create invitation');
      else if(a==='link-spaces')dialog('Link an authorized business',field('business_id','Business space','text','',spaces.filter(s=>s.kind==='business').map(s=>({value:s.id,label:s.name}))),async v=>{await api(endpoint('/links'),'POST',{business_id:Number(v.get('business_id'))});});
      else if(a==='profile')dialog('Your Bullshit Spending defaults',data.category_options.map(c=>field(c,title(c),'checkbox',data.settings.discretionary_categories.includes(c))).join(''),async v=>{await api(endpoint('/settings'),'PATCH',{discretionary_categories:data.category_options.filter(c=>v.get(c)==='on')});});
      else if(a==='split'){$('#tr-dialog').close();dialog('Split this transaction',`<p class="tr-subtle">Original amount: ${precise(tx.amount_cents)}. Assign an amount to the linked space; the remainder stays here.</p>${field('space_id','Other authorized space','text','',spaces.filter(s=>s.id!==active).map(s=>({value:s.id,label:s.name})))}${field('mode','Split by','text','percent',['percent','dollars'])}${field('amount','Percentage or signed dollar amount','number',50)}`,async v=>{const other=v.get('mode')==='percent'?percentShare(tx.amount_cents,v.get('amount')):minor(v.get('amount'));await api(endpoint(`/transactions/${id}/split`),'POST',{version:tx.version,splits:[{space_id:active,amount_cents:tx.amount_cents-other},{space_id:Number(v.get('space_id')),amount_cents:other}]});});}
      else if(a==='debt-suggestion')recordForm('debt',null,Object.fromEntries(Object.entries(data.debt_suggestions[Number(target.dataset.index)]).filter(([,v])=>v!==null)));
      else if(a==='sell')dialog('Record a metals sale',field('quantity','Quantity sold','number',1)+field('proceeds','Proceeds after fees ($)','number',0)+field('transaction_id','Matching bank transaction ID (optional)','number',''),async v=>{await api(endpoint(`/records/${id}/sell`),'POST',{quantity:v.get('quantity'),proceeds_cents:minor(v.get('proceeds')),version:r.version,transaction_id:v.get('transaction_id')?Number(v.get('transaction_id')):null});});
      else if(a==='sms')smsForm();
    } catch(e){notice(e.message);}
  });
  function smsForm(){dialog('Text reminders',field('phone','Your mobile number','tel','+1',null,true)+field('consent','I want bill reminders, cash alerts, and weekly summaries','checkbox',false),async v=>{await api(endpoint('/sms/verify'),'POST',{phone:v.get('phone'),consent:v.get('consent')==='on'});dialog('Verify your phone',field('code','Code from your text','text','',null,true),async v2=>{await api(endpoint('/sms/confirm'),'POST',{code:v2.get('code')});},'Verify');return false;},'Send verification code');}
  $('#tr-form').addEventListener('submit',async e=>{e.preventDefault();if(!formAction)return;$('#tr-save').disabled=true;$('#tr-form-error').hidden=true;try{const done=await formAction(new FormData($('#tr-form')));if(done!==false){$('#tr-dialog').close();await load();}}catch(e){$('#tr-form-error').textContent=e.message;$('#tr-form-error').hidden=false;}finally{$('#tr-save').disabled=false;}});
  $('#tr-close').onclick=$('#tr-cancel').onclick=()=>$('#tr-dialog').close();
  $('#tr-add').onclick=chooseAdd;$('#tr-refresh').onclick=load;
  $('#tr-space').onchange=e=>{active=Number(e.target.value);scenarioId='';filter={};load();};
  $('#tr-range').onchange=()=>{filter={};load();};
  root.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{tab=b.dataset.tab;filter={};if(data)render();});
  if(root.dataset.invite){$('#tr-invite').hidden=false;$('#tr-accept').onclick=async()=>{try{await api('/trajectory/invites/accept','POST',{token:root.dataset.invite});location.href='/trajectory';}catch(e){notice(e.message);}};}
  document.addEventListener('visibilitychange',()=>{if(!document.hidden && tab==='assets' && data?.metals.length && !$('#tr-dialog').open)load();});
  setInterval(()=>{if(!document.hidden && tab==='assets' && data?.metals.length && !$('#tr-dialog').open)load();},60000);
  load();
}
