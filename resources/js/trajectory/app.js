import Chart from 'chart.js/auto';
import { planningUI } from './planning.js';
import { chartPresentation } from './chart-theme.js';
import { outlookUI } from './outlook.js';
import './app.css';
import { minor, percentShare, rateMillis } from './money.js';

const root = document.querySelector('#trajectory');
if (root) boot();
function boot() {
  const $ = (s) => root.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = (v) => v === null || v === undefined ? 'Unavailable' : new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}).format(v/100);
  const precise = (v) => v === null || v === undefined ? 'Unavailable' : new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v/100);
  const date = (v) => v ? new Date(v.slice(0,10)+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}) : 'Not observed';
  const title = (s) => (data?.workspace_settings?.income_categories?.[s] || s).replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
  const spaces = JSON.parse(root.dataset.spaces || '[]');
  const incoming = new URLSearchParams(window.location.search);
  const requestedSpace = spaces.find(s=>s.id===Number(incoming.get('space')));
  const spaceFor = (kind) => requestedSpace?.kind===kind ? requestedSpace : spaces.find(s=>s.kind===kind);
  const personalSpace = spaceFor('household');
  let businessSpace = spaceFor('business');
  let loadVersion = 0;
  let linkInProgress = false;
  let scope = requestedSpace?.kind==='business' ? 'business' : personalSpace ? 'personal' : 'business';
  let active = (scope==='personal' ? personalSpace : businessSpace)?.id, data, evidenceRows=null, tab = 'overview', charts = [], chartTables = {}, filter = {}, scenarioId = '', historyMonth = '', budgetSource = null, seasonal = false, formAction;
  if (['transactions','accounts','planning','anomalies','tax','profit','companies','bud'].includes(incoming.get('tab'))) {tab=incoming.get('tab');filter=tab==='transactions'?{review:true}:{};}
  const endpoint = (path, spaceId=active) => `/trajectory/spaces/${spaceId}${path}`;
  const renderSpaceTabs = () => {
    const tabs = [personalSpace&&['personal','Personal'], businessSpace&&['business','Business'], personalSpace&&businessSpace&&['both','Both']].filter(Boolean);
    $('#tr-company-label').hidden=scope==='personal'||!businessSpace;
    $('#tr-company').innerHTML=spaces.filter(s=>s.kind==='business').map(s=>`<option value="${s.id}" ${s.id===businessSpace?.id?'selected':''}>${esc(s.name)}</option>`).join('');
    $('#tr-space-tabs').innerHTML=tabs.map(([key,label])=>`<button type="button" data-scope="${key}" role="tab" aria-selected="${scope===key}">${label}</button>`).join('');
  };
  renderSpaceTabs();
  const api = async (path, method='GET', body=null) => {
    const headers = {Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''};
    if (body && !(body instanceof FormData)) headers['Content-Type']='application/json';
    const response = await fetch(path,{method,headers,body:body ? body instanceof FormData ? body : JSON.stringify(body) : null});
    let result; try {result=await response.json();} catch {throw new Error('The server could not complete this request. Please refresh and try again.');}
    if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : result.message || 'Request failed.');
    return result;
  };
  const notice = (message) => {$('#tr-feedback').textContent=message;$('#tr-feedback').hidden=false;};
  const dashboardPath = (spaceId) => `/trajectory/spaces/${spaceId}/dashboard?range=${$('#tr-range').value}${scenarioId?`&scenario_id=${scenarioId}`:''}${$('#tr-range').value==='custom'?`&from=${$('#tr-start').value}&through=${$('#tr-end').value}`:''}&seasonal=${seasonal?1:0}`;
  const withSource = (rows, source) => (rows||[]).map(row=>({...row,space_id:source.id,space_label:source.name}));
  const combinedDashboard = (personal, business, combined) => ({
    combined:true,
    space:{id:null,name:'Personal + '+business.space.name,kind:'combined'},
    range:personal.range,
    coverage:{history_days:Math.min(personal.coverage.history_days,business.coverage.history_days),provisional:personal.coverage.provisional||business.coverage.provisional,blockers:[]},
    summary:{
      // A linked-space reconciliation is required before combining flows, so
      // company-to-household transfers never inflate the combined totals.
      income_cents:combined?.external_income_cents ?? null,
      spending_cents:combined?.external_spending_cents ?? null,
      cash_cents:personal.summary.cash_cents+business.summary.cash_cents,
      net_worth_cents:combined?.net_worth_cents ?? null,
      net_worth_complete:combined?.net_worth_complete ?? false,
      review_count:personal.summary.review_count+business.summary.review_count,
    },
    transactions:[...withSource(personal.transactions,personal.space),...withSource(business.transactions,business.space)].sort((a,b)=>b.date.localeCompare(a.date)),
    review_transactions:[...withSource(personal.review_transactions,personal.space),...withSource(business.review_transactions,business.space)].sort((a,b)=>b.date.localeCompare(a.date)),
    review_suggestions:[...(personal.review_suggestions||[]).map(s=>({...s,transactions:withSource(s.transactions,personal.space)})),...(business.review_suggestions||[]).map(s=>({...s,transactions:withSource(s.transactions,business.space)}))].sort((a,b)=>b.count-a.count||b.amount_cents-a.amount_cents),
    records:[],category_options:personal.category_options,definitions:personal.definitions,
    by_space:{[personal.space.id]:personal,[business.space.id]:business},
    combined_result:combined,
  });
  const load = async () => {
    if (!active) {$('#tr-context').textContent='Your private financial workspace';$('#tr-content').innerHTML='<div class="tr-empty"><h2>Your next chapter starts here.</h2>Your Trajectory pilot has not been enabled yet.<br>Once enabled, connect an account or import a statement to begin.</div>';$('#tr-add').disabled=true;return;}
    const requestedVersion = ++loadVersion;
    evidenceRows=null;
    $('#tr-content').classList.add('tr-loading');$('#tr-content').setAttribute('aria-busy','true');
    try {
      let result;
      if(scope==='both'&&personalSpace&&businessSpace){
        const [personal,business]=await Promise.all([api(dashboardPath(personalSpace.id)),api(dashboardPath(businessSpace.id))]);
        let combined=null;
        try {combined=await api(endpoint(`/combined?business_id=${businessSpace.id}&from=${personal.range.start}&through=${personal.range.end}`,personalSpace.id));} catch {}
        result=combinedDashboard(personal,business,combined);
      } else result=await api(dashboardPath(active));
      if(requestedVersion!==loadVersion)return;data=result;if(scope!=='both'&&(filter.ids||filter.date)){const rows=await api(endpoint('/evidence'),'POST',filter);if(requestedVersion!==loadVersion)return;evidenceRows=rows;}render();
    }
    catch(e){if(requestedVersion===loadVersion)notice(e.message);}
    finally {if(requestedVersion===loadVersion){$('#tr-content').classList.remove('tr-loading');$('#tr-content').setAttribute('aria-busy','false');}}
  };
  const metric = (label,value,sub) => `<div class="tr-metric"><div class="tr-kicker">${esc(label)}</div><div class="tr-number">${money(value)}</div><div class="tr-subtle">${esc(sub)}</div></div>`;
  const panel = (heading,body,action='') => `<section class="tr-panel"><header class="tr-panel-head"><h2>${esc(heading)}</h2>${action}</header>${body}</section>`;
  const empty = (message) => `<div class="tr-empty">${esc(message)}</div>`;
  const plot = (id,label,size='') => `<div class="tr-chart ${size}"><canvas id="${id}" role="img" aria-label="${esc(label)}"></canvas></div><button class="tr-chart-data" data-action="chart-data" data-chart="${id}">View chart data</button>`;
  const btn = (label,action,attrs='') => `<button data-action="${action}" ${attrs}>${esc(label)}</button>`;
  const chart = (id,type,labels,datasets,click,extra={}) => {
    const canvas=document.getElementById(id);if(!canvas)return;
    chartTables[id]={labels,datasets,click,title:canvas.getAttribute('aria-label')};
    const presentation=chartPresentation(canvas,precise,type,extra);
    const instance=new Chart(canvas,{type,data:{labels,datasets},plugins:presentation.plugins,options:{...presentation.options,onClick:(event,elements)=>{if(elements[0]&&click)click(elements[0].index);}}});charts.push(instance);
  };
  const line = (label,values,color,fill=false) => ({label,data:values,borderColor:color,backgroundColor:color+'0a',fill,tension:0,borderWidth:2.5,pointRadius:0,pointHoverRadius:4,pointHoverBackgroundColor:'#fff',pointHoverBorderWidth:2,pointHitRadius:16,spanGaps:false});
  const navigation=[];
  const remember=()=>{navigation.push({tab,filter:{...filter},evidenceRows,scroll:window.scrollY});$('#tr-back').hidden=false;};
  const navigate=(view)=>{remember();tab=view;filter={};evidenceRows=null;render();window.scrollTo(0,0);};
  const showTransactions = async (next) => {
    const requestedSpace=active;
    try {
      const records=(next.ids||next.date)?await api(endpoint('/evidence'),'POST',next):null;
      if(requestedSpace!==active)return;
      remember();evidenceRows=records;filter=next;tab='transactions';render();
    } catch(e){notice(e.message);}
  };
  const planning=planningUI({getData:()=>data,spaces,esc,money,precise,panel,btn,plot,chart,line,dialog,field,api,endpoint,minor,load,navigate,evidence:showTransactions});
  const outlook=outlookUI({getData:()=>data,esc,money,precise,date,plot,chart,line,btn,dialog,evidence:showTransactions,forecastDetail});
  function render() {
    if(!data)return;
    charts.forEach(c=>c.destroy());charts=[];chartTables={};
    root.querySelectorAll('[data-tab]').forEach(b=>{if(b.dataset.tab===tab)b.setAttribute('aria-current','page');else b.removeAttribute('aria-current');});
    $('#tr-context').textContent=`${date(data.range.start)} – ${date(data.range.end)} · ${data.coverage.history_days} days of baseline history${data.coverage.provisional?' · Provisional forecast':''}`;
    $('#tr-add').disabled=scope==='both';
    const views=scope==='both'?{overview:bothOverview,transactions}:{overview,accounts,history,budget,transactions,bills,medical,assets,business,planning:planning.planning,anomalies:planning.anomalies,tax:planning.tax,profit:planning.profit,companies:planning.companies,bud:planning.bud};
    const labels={overview:'Your trajectory',accounts:'Accounts',history:'Cash flow & history',budget:'Your budget',transactions:'Review transactions',bills:'Bills & goals',medical:'Medical sharing',assets:'Wealth & debt',business:'Business',planning:'Income & scenarios',anomalies:'Category anomalies',tax:'Tax accountant review',profit:'Profit & loss',companies:'Businesses & accounts',bud:'Ask Bud'};
    $('#tr-page-title').textContent=labels[tab];
    $('#tr-page-subtitle').textContent=scope==='both'?'Your household and company, side by side.':tab==='overview'?'A clearer picture of today. A better plan for tomorrow.':'Your money, with the context that matters.';
    $('#tr-content').innerHTML=(views[tab]||combinedOnly)();
    if(tab==='planning'&&scope!=='both') planning.draw();
    if(tab==='overview'&&scope!=='both') drawOverview();
    if(tab==='assets') drawAssets();
    if(tab==='business') drawBusiness();
    if(tab==='medical') drawMedical();
    if(tab==='accounts') drawAccounts();
    if(tab==='history') drawHistory();
    if(tab==='budget') drawBudget();
  }
  function overview() {
    const summary=data.summary;
    return `${data.summary.review_count?panel('Review queue',`<p class="tr-subtle">${data.summary.review_count} transactions need your category or money-movement decision. Clear reviews make the forecast more useful.</p>`,btn('Review transactions','review')):''}
      ${data.unresolved_deposits.ids.length?panel('Resolve incoming deposits',`<p>${precise(data.unresolved_deposits.amount_cents)} of recent deposits need purpose review. The current forecast excludes these from recurring income.</p>${btn('Review deposits','deposit-evidence')}`):''}
      <div class="tr-metrics">${metric('Money coming in',summary.income_cents,'Earned income only')}${metric('Money going out',summary.spending_cents,'Transfers excluded')}${metric('Cash on hand',summary.cash_cents,'Observed account balances')}${metric('Net worth',summary.net_worth_cents,summary.net_worth_complete?'Assets minus liabilities':'Personal obligations less recorded assets · values missing')}</div>
      ${outlook.html(scenarioId,seasonal)}
      ${planning.summary()}
      ${commitmentCards()}
      ${incomeSources()}
      <div class="tr-grid">${panel('Income and spending',plot('tr-cashflow','Daily income versus spending')+`<p class="tr-subtle">Select a date to inspect its transactions.</p>`)}${panel('Where your money went',data.categories.length?plot('tr-categories','Spending by category'):empty('Import transactions to see your spending breakdown.'))}</div>
      <div class="tr-grid equal">${panel('Coming up next',data.bills.length?data.bills.slice(0,6).map(b=>`<div class="tr-row"><div><strong>${esc(b.name)}</strong><div class="tr-subtle">${date(b.date)}${b.estimated?' · Estimated':''}</div></div><div class="tr-money">${money(b.amount_cents)} ${btn('View','edit',`data-id="${b.record_id}"`)}</div></div>`).join(''):empty('Confirm recurring bills to build your calendar.'),btn('See bills','bills'))}${panel('Change your trajectory',data.recommendations.length?data.recommendations.slice(0,5).map((r,i)=>`<div class="tr-row"><div><strong>${esc(r.title)}</strong><div class="tr-subtle">Up to ${money(r.monthly_savings_cents)}/month · ${money(r.yearly_cash_impact_cents)} over a year</div></div>${btn('Explore','recommendation',`data-index="${i}"`)}</div>`).join(''):empty('Review your spending profile to surface supported saving opportunities.'))}</div>
      <div class="tr-grid equal">${panel('Five years from here',plot('tr-fiveyear','Monthly five-year cash projection'))}${panel('Your spending calendar',heatmap())}</div>`;
  }
  function bothOverview(){
    const s=data.summary, combined=data.combined_result;
    return `${panel('Personal + business',`<p class="tr-subtle">This view combines your household with ${esc(businessSpace.name)}. Other businesses are not included. Explicitly matched owner payments are eliminated where available; unmatched transfers remain visible for review.</p><div class="tr-metrics">${metric('External income',s.income_cents,'Company and household income, without matched transfers')}${metric('External spending',s.spending_cents,'Company and household spending, without matched transfers')}${metric('Cash on hand',s.cash_cents,'Observed balances across both')}${metric('Combined net worth',s.net_worth_cents,s.net_worth_complete?'Assets minus liabilities':'Complete the linked valuations')}</div>${combined?`<div class="tr-flow"><div>${esc(combined.business)}<strong>${money(combined.company_owner_outflow_cents)}</strong></div><span>→</span><div>Matched owner income<strong>${money(combined.eliminated_transfers_cents)}</strong></div><span>→</span><div>${esc(combined.household)}<strong>${money(combined.household_spending_cents)}</strong></div></div>`:'<p class="tr-subtle">Link the personal and business spaces to see owner-payment matching and combined net worth.</p>'}`)}${s.review_count?panel('Review queue',`<p class="tr-subtle">${s.review_count} personal or business transactions need review. Choose Review transactions to categorize them without switching spaces.</p>`,btn('Review transactions','review')):''}`;
  }
  function combinedOnly(){return panel('Choose a financial space',`<p class="tr-subtle">The combined view is for your shared trajectory and transaction review. Choose Personal or Business at the top to manage accounts, bills, goals, debt, or company planning.</p>`);}
  function incomeSources(){const income=data.income_sources||{earned_income_cents:0,sources:[]};return panel('Income by source',`<div class="tr-labels"><div><div class="tr-number">${money(income.earned_income_cents)}</div><p class="tr-subtle">Counted as earned income in this period.</p></div><span class="tr-pill">Cash sources separated</span></div><div class="tr-card-list">${income.sources.map(s=>`<div class="tr-row"><div><strong>${esc(s.name)}</strong><div class="tr-subtle">${esc(s.detail)} · ${s.count} record${s.count===1?'':'s'}</div></div><div class="tr-money">${precise(s.amount_cents)}<br>${btn('View records','income-source',`data-key="${esc(s.key)}"`)}</div></div>`).join('')||empty('Income sources appear after transactions are imported.')}</div><p class="tr-subtle">Asset sales, borrowed cash, transfers, refunds, and deposits needing a purpose do not inflate earned income.</p>`);}
  function commitmentCards(){const c=data.commitments;const accountName=id=>data.accounts.find(a=>a.id===id)?.name||'Payment account needs review';return `<div class="tr-grid equal">${panel('Your subscriptions',`<div class="tr-labels"><div class="tr-number">${money(c.subscription_monthly_cents)}<small class="tr-subtle"> / month confirmed</small></div><span class="tr-pill">${c.subscriptions.length} listed</span></div><div class="tr-card-list">${c.subscriptions.map((s,i)=>`<div class="tr-row"><div><strong>${esc(s.name)}</strong><div class="tr-subtle">${s.status&&s.status!=='unknown'?esc(title(s.status))+' · '+esc(title(s.cadence)):s.confirmed?esc(title(s.cadence))+' · Renews '+date(s.next_due_on):'Needs review'+(s.last_paid_on?' · Last paid '+date(s.last_paid_on):'')}<br>${esc(accountName(s.account_id))}</div></div><div class="tr-money">${precise(s.amount_cents)}<br>${s.id?btn('Edit','edit',`data-id="${s.id}"`):btn('Review','subscription-review',`data-index="${i}"`)}</div></div>`).join('')||empty('Imported subscription charges and confirmed renewals appear here.')}</div><p class="tr-subtle">Unconfirmed items are excluded from the monthly total. Annual renewals are spread over 12 months for this comparison.</p>`,btn('Add subscription','subscription-add'))}${panel('Upcoming loan payments',`<div class="tr-card-list">${c.payments.map(p=>`<div class="tr-row"><div><strong>${esc(p.name)}</strong><div class="tr-subtle">${p.due_on?date(p.due_on):'Due date missing'} · ${p.confirmed?'Verified payment': 'Needs review'}${p.status==='past_due_review'?' · Check whether already paid':''}<br>${esc(accountName(p.payment_account_id))}</div></div><div class="tr-money">${precise(p.amount_cents)}<br>${p.id?btn('Details','edit',`data-id="${p.id}"`):btn('Add due date','payment-setup',`data-id="${p.account_id}"`)}</div></div>`).join('')||empty('Add your mortgage, auto loan, and cards to see upcoming payments.')}</div><p class="tr-subtle">A verified next payment can inform cash timing while APR and future payment terms remain incomplete.</p>`,btn('View debts','assets'))}</div>`;}
  function heatmap() {
    const map=new Map(data.daily_series.map(d=>[d.date,d.spending_cents]));
    const max=Math.max(1,...map.values()); const end=new Date(data.range.end+'T12:00:00');
    let html='<div class="tr-heatmap">';
    for(let i=34;i>=0;i--){const d=new Date(end);d.setDate(d.getDate()-i);const key=d.toISOString().slice(0,10),v=map.get(key)||0;html+=`<button data-action="date" data-date="${key}" data-level="${v>0?Math.max(1,Math.ceil(v/max*4)):1}" aria-label="${esc(key+': '+precise(v))}">${d.getDate()}<span>${v?money(v):'—'}</span></button>`;}
    return html+'</div><p class="tr-subtle">Last 35 days; amounts reflect the selected reporting period.</p>';
  }
  function drawOverview() {
    outlook.draw();
    chart('tr-cashflow','bar',data.daily_series.map(r=>date(r.date)),[{label:'Income',data:data.daily_series.map(r=>r.income_cents),backgroundColor:'#4c8d72',borderRadius:3},{label:'Spending',data:data.daily_series.map(r=>r.spending_cents),backgroundColor:'#d9b896',borderRadius:3}],i=>showTransactions({date:data.daily_series[i].date}));
    const cats=data.categories.filter(c=>c.amount_cents>0);
    chart('tr-categories','doughnut',cats.map(c=>title(c.category)),[{data:cats.map(c=>c.amount_cents),backgroundColor:['#205c48','#4f8970','#8fbaa1','#becb9d','#b39760','#dfbf8e','#a6b8bc','#759498'],borderWidth:3,borderColor:'#fff'}],i=>showTransactions({category:cats[i].category}));
    chart('tr-fiveyear','line',data.forecast.monthly.map(r=>date(r.date)+' '+r.date.slice(0,4)),[line('Available cash',data.forecast.monthly.map(r=>r.available_cents),'#176b52',true)]);
    $('#tr-scenario')?.addEventListener('change',e=>{scenarioId=e.target.value;load();});
  }
  function reviewSuggestions() {
    const suggestions=data.review_suggestions||[];
    if(!suggestions.length)return '';
    return `<div class="tr-review-suggestions"><div class="tr-labels"><div><h3>Suggested bulk reviews</h3><p class="tr-subtle">Built from your past choices and clear merchant-title context. Nothing changes until you apply a suggestion.</p></div><span class="tr-pill">${suggestions.reduce((total,s)=>total+s.count,0)} ready</span></div>${suggestions.map((s,i)=>`<div class="tr-row tr-suggestion"><div><strong>${esc(s.merchant)} → ${esc(title(s.category))}</strong><div class="tr-subtle">${s.count} purchases · ${precise(s.amount_cents)} total · ${esc(s.evidence)}</div></div>${btn(`Review ${s.count} together`,'bulk-suggestion',`data-index="${i}"`)}</div>`).join('')}</div>`;
  }
  function transactions() {
    let rows=filter.review?(data.review_transactions||[]):(evidenceRows??data.transactions);
    if(filter.category)rows=rows.filter(t=>t.category===filter.category);
    if(filter.date)rows=rows.filter(t=>t.date===filter.date);
    if(filter.review)rows=rows.filter(t=>!t.reviewed);
    if(filter.ids)rows=rows.filter(t=>filter.ids.includes(t.id));
    const body=rows.length?`<div class="tr-table-wrap"><table class="tr-table"><caption>${rows.length} transactions${filter.review?' needing review':''}${Object.keys(filter).length&&!filter.review?' · Filtered':''}. Positive amounts are money in.</caption><thead><tr><th>Date</th>${scope==='both'?'<th>Space</th>':''}<th>Merchant</th><th>Category</th><th>Classification</th><th>Amount</th><th>Review</th></tr></thead><tbody>${rows.map(t=>`<tr><td>${date(t.date)}</td>${scope==='both'?`<td><span class="tr-pill">${esc(t.space_label)}</span></td>`:''}<td>${esc(t.merchant)}<div class="tr-subtle">${esc(title(t.flow))}</div></td><td>${esc(title(t.category))}</td><td>${t.face_punched?'<span class="tr-pill">Face Punched</span> ':''}${t.bullshit_spending?'<span class="tr-pill">Bullshit Spending</span>':''}</td><td class="tr-money ${t.amount_cents>0?'tr-positive':''}">${precise(t.amount_cents)}</td><td>${t.editable?btn(t.reviewed?'Edit category':'Categorize','classify',`data-id="${t.id}" data-space-id="${t.space_id||active}"`):'Allocated'}</td></tr>`).join('')}</tbody></table></div>`:empty(filter.review?'Everything in this space is reviewed.':'No transactions match this view.');
    const receipts=scope==='both'?[]:data.records.filter(r=>r.kind==='receipt');
    return panel('Review transactions',`<p class="tr-subtle">Pick a transaction to set its category and money movement. You can flag one-time surprises as Face Punched, mark your own Bullshit Spending, split a card purchase, or teach Trajectory an exact merchant rule.</p>${reviewSuggestions()}${body}`,`<div class="tr-actions">${scope!=='both'?btn('Match transfers','reconciliation'):''}${btn('Needs review','review')}${btn('All selected-period transactions','clear')}${scope!=='both'?btn('Import statement','import'):''}</div>`)+(scope!=='both'?panel('Receipt research',receipts.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><p class="tr-subtle">${esc(r.data.summary)}</p><div class="tr-source">${esc(r.data.source)} · ${esc(r.data.purchased_on)} · ${r.data.transaction_id?'Matched statement #'+r.data.transaction_id:'Not matched to a posted purchase'}</div></div><div>${money(r.data.amount_cents)} ${btn('Review receipt','edit',`data-id="${r.id}"`)}</div></div>`).join('')||empty('Receipts explain existing purchases; they never create a second expense.'),btn('Add receipt evidence','add','data-kind="receipt"')):'');
  }
  function bills() {
    const scheduleRecords=data.records.filter(r=>r.kind==='recurring');
    const goals=data.goals.map(g=>`<div class="tr-goal"><div class="tr-labels"><strong>${esc(g.name)}</strong>${btn('Edit','edit',`data-id="${g.id}"`)}</div><div class="tr-progress"><i style="width:${Math.min(100,g.saved_cents/Math.max(1,g.target_cents)*100)}%"></i></div><div class="tr-labels tr-subtle"><span>${money(g.saved_cents)} of ${money(g.target_cents)}</span><span>${date(g.target_on)}</span></div><p class="tr-subtle">${g.on_track?'On track':'Increase contributions'} · ${money(g.required_monthly_cents)}/month needed</p></div>`).join('');
    return `<div class="tr-grid equal">${panel('Bills and recurring income',scheduleRecords.length?scheduleRecords.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.cadence))} · ${date(r.data.next_due_on)} · ${r.data.confirmed?'Confirmed':'Needs confirmation'}</div></div><div class="tr-small-actions"><span>${money(r.data.amount_cents)}</span>${btn('Edit','edit',`data-id="${r.id}"`)}</div></div>`).join(''):empty('Add your mortgage, utilities, subscriptions, and payday.'),btn('Add bill','add','data-kind="recurring"'))}${panel('Savings goals',goals||empty('Give your future a target: emergency cash, a purchase, or paying down debt.'),btn('Add goal','add','data-kind="goal"'))}</div>${panel('Detected recurring patterns',data.recurring_suggestions.length?data.recurring_suggestions.map((r,i)=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.cadence))} · ${money(r.data.amount_cents)} · ${r.evidence_ids.length} supporting transactions</div></div>${btn('Review schedule','recurring-suggestion',`data-index="${i}"`)}</div>`).join(''):empty('Recurring suggestions appear after at least three reasonably spaced transactions.'))}`;
  }
  function medical() {
    const m=data.medical;
    if(!m)return empty('Medical sharing is private to your household. Choose your Personal space.');
    const records=data.records.filter(r=>['medical_payment','medical_share','medical_membership'].includes(r.kind));
    return `<div class="tr-notice">Track Prisma bills and Samaritan Ministries sharing from submission through payment. Received shares reimburse medical costs; expected shares stay outside income, net worth, and the baseline forecast.</div>
      <div class="tr-metrics">${metric('Still owed to providers',m.owed_cents,'Included in liabilities')}${metric('Paid to providers',m.paid_cents,'Opening payments + tracked payments')}${metric('Shares received',m.received_cents,'Actual receipts, separate from earnings')}${metric('Reserved for bills',m.reserve_cents,'Held from available cash')}</div>
      <div class="tr-actions tr-block">${btn('Add medical need','add','data-kind="medical_need"')}${btn('Monthly membership / share','membership')}${btn('Record monthly contribution','add','data-kind="medical_membership"')}</div>
      <p class="tr-subtle">Monthly membership contributions are a separate recurring health expense. Enter the amount as a negative number and confirm its payment account. Use Record monthly contribution to match each imported payment, including payments sent to different members. Those matches use the confirmed schedule without repeating historical payments. Provider plans here assume no interest and payments from cash; track lender financing separately.</p>
      ${m.issues.map(i=>`<div class="tr-notice">${esc(i)}</div>`).join('')}
      ${m.needs.length?panel('Medical costs and sharing by need',plot('tr-medical','Medical bills, provider payments, received and expected shares')):''}
      ${m.needs.map(n=>panel(n.name,`<div class="tr-block tr-medical-need"><p class="tr-subtle">${esc(n.ministry)} · ${esc(title(n.status))}${n.submitted_on?' · Submitted '+date(n.submitted_on):''}</p><div class="tr-flow tr-medical-flow"><div>Paid to providers<strong>${money(n.paid_cents)}</strong></div><div>Still owed<strong>${money(n.owed_cents)}</strong></div><div>Shares received<strong>${money(n.received_cents)}</strong></div></div><p class="tr-subtle">Net paid after received sharing: ${money(n.net_paid_cents)}. Expected payments: ${money(n.expected_cents)} (unconfirmed). Remaining reviewed sharing target: ${money(n.remaining_sharing_cents)}. Reserved cash: ${money(n.reserve_cents)}.</p>
      <div class="tr-actions">${btn('Add provider bill','medical-child',`data-kind="medical_bill" data-parent-key="medical_need_record_id" data-id="${n.id}"`)}${btn('Pay across provider invoices','medical-child',`data-kind="medical_payment" data-parent-key="medical_need_record_id" data-id="${n.id}"`)}${btn('Record expected / received share','medical-child',`data-kind="medical_share" data-parent-key="medical_need_record_id" data-id="${n.id}"`)}${n.evidence_ids.length?btn('Share deposit evidence','medical-evidence',`data-ids="${esc(JSON.stringify(n.evidence_ids))}"`):''}</div>
      ${n.bills.map(b=>`<div class="tr-row"><div><strong>${esc(b.name)} · ${esc(b.provider)}</strong><div class="tr-subtle">Invoice ${esc(b.reference)} · Net bill ${money(b.billed_cents-b.adjustment_cents)}<br>Paid ${money(b.paid_cents)} · Owed ${money(b.owed_cents)} · Next payment ${date(b.next_due_on)}${b.confirmed?'':' · Plan needs review'}</div></div><div class="tr-small-actions">${btn('Edit bill','edit',`data-id="${b.id}"`)}${btn('Record provider payment','medical-child',`data-kind="medical_payment" data-parent-key="medical_bill_record_id" data-id="${b.id}"`)}${b.evidence_ids.length?btn('Payment evidence','medical-evidence',`data-ids="${esc(JSON.stringify(b.evidence_ids))}"`):''}</div></div>`).join('')||empty('Add each provider invoice once, including any amount paid upfront.')}</div>`,btn('Edit need','edit',`data-id="${n.id}"`))).join('')||empty('Start with a medical need, then add its bills, provider payments, and incoming shares. Multiple bills and multiple monthly receipts can belong to one need.')}
      ${panel('Payment and sharing history',records.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${r.kind==='medical_payment'?'Paid to provider':r.kind==='medical_membership'?'Monthly contribution':title(r.data.status)+' share'} · ${date(r.data.paid_on)} · ${r.data.transaction_id?'Statement #'+r.data.transaction_id:'Manual / unmatched'}</div></div><div>${money(r.data.amount_cents)} ${btn('Edit','edit',`data-id="${r.id}"`)}</div></div>`).join('')||empty('Record payments as they happen. Change an expected share to received when it arrives; do not add the same receipt twice.'))}`;
  }
  function drawMedical(){
    if(!data.medical?.needs.length)return;
    const needs=data.medical.needs;
    chart('tr-medical','bar',needs.map(n=>n.name),[
      {label:'Net provider bills',data:needs.map(n=>n.paid_cents+n.owed_cents),backgroundColor:'#6b869c'},
      {label:'Paid to providers',data:needs.map(n=>n.paid_cents),backgroundColor:'#397c5e'},
      {label:'Shares received',data:needs.map(n=>n.received_cents),backgroundColor:'#b48836'},
      {label:'Expected shares (excluded from forecast)',data:needs.map(n=>n.expected_cents),backgroundColor:'#d7d5cb'}
    ],i=>recordForm('medical_need',data.records.find(r=>r.id===needs[i].id)));
  }
  function assets() {
    const s=data.summary;
    return `<div class="tr-metrics">${metric('Net worth',s.net_worth_cents,s.net_worth_complete?'Assets minus debts':'Partial valuation')}${metric('Interest this period',s.selected_interest_cents,'Observed charges')}${metric('Recorded interest',s.recorded_interest_cents,'All available imported history')}${metric('Remaining interest',s.projected_interest_cents,'Estimated from reviewed debt terms')}</div>
      ${panel('Interest by account',`<div class="tr-table-wrap"><table class="tr-table"><caption>Recorded interest only; missing history is not zero interest.</caption><thead><tr><th>Account</th><th>Selected period</th><th>Year to date</th><th>All available history</th></tr></thead><tbody>${data.interest_by_account.map(a=>`<tr><td>${esc(a.name)} ${a.ids.length?btn('Charges','interest-evidence',`data-id="${a.account_id}"`):''}</td><td>${a.has_evidence?precise(a.selected_cents):'No evidence'}</td><td>${a.has_evidence?precise(a.ytd_cents):'No evidence'}</td><td>${a.has_evidence?precise(a.recorded_cents):'No evidence'}</td></tr>`).join('')}</tbody></table></div>`)}
      ${data.interest_statements.length?panel('Lender interest evidence',`<p class="tr-subtle">YTD recorded interest: ${money(s.ytd_interest_cents)}. Period totals replace overlapping imported charges. A period total cannot determine an individual month’s charges.</p>${data.interest_statements.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-source">${esc(r.period_start)} – ${esc(r.period_end)} · ${esc(r.source)}</div></div><strong>${money(r.interest_cents)}</strong></div>`).join('')}`):''}
      <div class="tr-grid equal">${panel('Net-worth history',data.net_worth_history.length?plot('tr-networth','Observed net worth over time'):empty('Daily snapshots begin after your accounts are added. History is never fabricated.'))}${panel('Assets and liabilities',plot('tr-allocation','Assets versus liabilities'))}</div>
      ${data.debt_suggestions.length?panel('Bank debt details to review', data.debt_suggestions.map((d,i)=>`<div class="tr-row"><div><strong>${esc(d.name)}</strong><p class="tr-subtle">${esc(d.note)}</p></div>${btn('Review terms','debt-suggestion',`data-index="${i}"`)}</div>`).join('')):''}
      ${panel('Your debts',data.debts.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Debt</th><th>Balance</th><th>APR</th><th>Recorded interest</th><th>Projected interest</th><th>Payoff</th><th></th></tr></thead><tbody>${data.debts.map(d=>`<tr><td>${esc(d.name)}</td><td>${money(d.balance_cents)}</td><td>${((d.rate_millis??d.apr_bps*10)/1000).toFixed(3)}%</td><td>${money(d.recorded_interest_cents)}</td><td>${money(d.projection.interest_cents)}</td><td>${d.projection.payoff_on?date(d.projection.payoff_on)+' '+d.projection.payoff_on.slice(0,4):title(d.projection.status)}</td><td>${btn('Edit','edit',`data-id="${d.id}"`)}</td></tr>`).join('')}</tbody></table></div>${plot('tr-debts','Debt balance through payoff')}`:empty('Add reviewed loan or credit-card terms to project payoff and interest.'),btn('Add debt','add','data-kind="debt"'))}
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
  function accounts() {
    const s=data.summary, pending=Object.values(data.settings.pending_bank_connections||{});
    const groups=[['cash','Cash','#5b9caa'],['credit','Credit cards','#d0a364'],['loan','Loans','#b8898e'],['investment','Investments','#799774']];
    const activeFeeds=data.connections.filter(c=>c.status!=='disconnected');
    const inactiveFeeds=data.connections.filter(c=>c.status==='disconnected');
    const connectionRows=activeFeeds.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution_name||'Connected bank feed')}</strong><div class="tr-subtle">${c.status==='mapping_required'?'Choose account owners':'Bank feed'} · ${esc(title(c.status))}${c.synced_at?' · Last synced '+date(c.synced_at):' · Waiting for its first sync'}</div></div><div class="tr-small-actions">${c.managed_here?btn('Account owners','bank-map',`data-id="${c.id}"`)+btn('Sync','sync',`data-id="${c.id}"`)+btn('Reconnect','reconnect',`data-id="${c.id}"`)+btn('Disconnect','disconnect',`data-id="${c.id}"`):'<span class="tr-pill">Managed by connection owner</span>'}</div></div>`).join('');
    const inactiveRows=inactiveFeeds.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution_name||'Former bank feed')}</strong><div class="tr-subtle">Not connected · Reconnect through Plaid to resume syncing.</div></div>${btn('Reconnect','reconnect',`data-id="${c.id}"`)}</div>`).join('');
    const accountRows=groups.map(([kind,label,color])=>{const rows=data.accounts.filter(a=>a.kind===kind);return rows.length?`<h3>${label} <span class="tr-subtle">${money(rows.reduce((v,a)=>v+(a.balance_cents??0),0))}</span></h3>${rows.map(a=>`<div class="tr-row"><div class="tr-account-name"><span class="tr-account-icon" style="color:${color}">${kind==='cash'?'$':kind==='credit'?'▤':'◈'}</span><div><strong>${esc(a.name)}</strong><div class="tr-source">${a.connection_id?'Connected feed · ':'Manual / imported · '}Observed ${date(a.observed_at)} · History ${a.history_start?a.history_start.slice(0,10):'not imported'}</div></div></div><div><strong>${precise(a.balance_cents)}</strong> ${btn('Activity','account-activity',`data-id="${a.id}"`)} ${btn('Assign owner','plan-assign',`data-id="${a.id}"`)}</div></div>`).join('')}`:'';}).join('')||empty('Add your accounts to see your whole financial picture.');
    const settings=`<div class="tr-grid equal">${panel('Your spending rules',`<p class="tr-subtle">You decide what counts as Bullshit Spending. Face Punched stays a separate one-time classification.</p><div class="tr-actions tr-block">${btn('Edit category defaults','profile')}</div>${data.records.filter(r=>r.kind==='rule').map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.classification.category))} · Exact merchant rule</div></div>${btn('Remove','archive',`data-id="${r.id}"`)}</div>`).join('')}`)}${panel('Sharing and reminders',`<p class="tr-subtle">Personal and business permissions stay separate. Partner invitations share household finances only.</p><div class="tr-actions tr-block">${btn('Invite partner','invite')}${btn('Link business','link-spaces')}${btn('Company → household','combined')}${btn('Set up texting','sms',data.providers.sms?'':'disabled')}${btn('Email reminders','review-email')}</div><p class="tr-subtle">Review emails: ${esc(title(data.review_email?.frequency||'off'))} · ${esc(data.review_email?.email||'')}. ${data.review_email?.frequency!=='off'?'At 9 AM '+esc(data.review_email?.timezone||data.space.timezone)+(data.review_email?.frequency==='weekly'?' on Mondays':'')+', when transactions need review.':''}</p>${data.review_email?.delivery_ready===false?'<p class="tr-subtle">Email delivery needs provider setup.</p>':''}${!data.providers.sms?'<p class="tr-subtle">Text delivery is not enabled for this pilot yet.</p>':''}`)}</div>`;
    return `${panel('Connections',`<p class="tr-subtle">See what is connected now. Connect opens Plaid immediately. A shared login can contain multiple companies. Choose an owner for each account before its first import; new unassigned accounts stay out of your books.</p><div class="tr-actions tr-block">${btn('Connect a bank','connect',data.providers.plaid?'':'disabled')}${btn('Add manual account','account')}${btn('Import statement','import')}${btn('Import Monarch history','monarch-import')}</div>${!data.providers.plaid?'<div class="tr-notice">Live bank connections need provider setup. Manual accounts and statement imports remain available.</div>':''}${connectionRows||'<div class="tr-data-note">No live bank feed is connected in this space yet. If you previously connected USAA, use Refresh; if it still is not listed, choose Connect a bank to finish or reconnect it through Plaid.</div>'}${inactiveRows}${pending.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution||'Requested bank')}</strong><div class="tr-subtle">${esc(title(c.status||'setup required'))}<br>${esc(c.note||'')}</div></div><span class="tr-pill">Manual evidence</span></div>`).join('')}`)}${panel('Your net worth',`<div class="tr-history-intro"><div><div class="tr-number">${money(s.net_worth_cents)}</div><p class="tr-subtle">${s.net_worth_complete?'Based on recorded assets and liabilities':'Partial picture · missing or unverified coverage'}</p></div>${btn('Manage assets & debt','assets')}</div>${data.cash_history.length?'<h3>Cash balance history</h3>'+plot('tr-cash-history','Imported cash balance history','large')+'<p class="tr-subtle">Cash accounts in the balance export only. Select a date to inspect contributing balances.</p>':data.net_worth_history.length?plot('tr-account-history','Observed net worth history','large'):empty('Balance history begins with observations. Importing transactions does not invent historical account balances.')}`)}<div class="tr-grid">${panel('Your accounts',accountRows,btn('Add account','account'))}${panel('Balance sheet',`<h3>Assets <span class="tr-positive">${money(s.assets_cents)}</span></h3><div class="tr-allocation"><i style="width:${s.assets_cents?Math.max(0,s.cash_cents/s.assets_cents*100):0}%;background:#5b9caa"></i><i style="flex:1;background:#799774"></i></div><div class="tr-row">Liquid cash<strong>${money(s.cash_cents)}</strong></div><div class="tr-row">Other recorded assets<strong>${money(s.assets_cents-s.cash_cents)}</strong></div><h3>Liabilities ${money(s.liabilities_cents)}</h3><p class="tr-subtle">Credit cards, loans, and unpaid medical bills. Missing property, investments, or debts make this a partial picture.</p>`)} </div>${settings}`;
  }
  function drawAccounts(){const cash=data.cash_history;chart('tr-cash-history','line',cash.map(r=>r.date),[line('Imported cash balances',cash.map(r=>r.cash_cents),'#4398ab',true)],i=>dialog('Cash balances · '+cash[i].date,`<p class="tr-subtle">${esc(cash[i].source)}</p>${Object.entries(cash[i].accounts).map(([name,value])=>`<div class="tr-row">${esc(name)}<strong>${precise(value)}</strong></div>`).join('')}`,null));const rows=data.net_worth_history;chart('tr-account-history','line',rows.map(r=>date(r.date)),[line('Recorded net worth',rows.map(r=>r.net_worth_cents??null),'#4398ab',true)]);}
  function history() {
    const months=data.history.months;
    const selectedMonth=historyMonth||months.at(-1)?.month;
    const current=months.find(m=>m.month===selectedMonth);
    const previous=current?months.find(m=>m.month===`${Number(current.month.slice(0,4))-1}${current.month.slice(4)}`):null;
    const nextMonth=new Date();nextMonth.setMonth(nextMonth.getMonth()+1);const lastYear=`${nextMonth.getFullYear()-1}-${String(nextMonth.getMonth()+1).padStart(2,'0')}`;
    const prior=months.find(m=>m.month===lastYear);
    return `<div class="tr-data-note">${esc(data.history.note)} Imported range: ${esc(data.history.first_on||'none')} – ${esc(data.history.last_on||'none')}.</div>${panel('The story behind your spending',plot('tr-history','Monthly income and spending history','large')+`<p class="tr-subtle">Select any month to open its transactions.</p>`)}<div class="tr-grid equal">${panel('Same month, different year',`<div class="tr-actions"><label for="tr-history-month">Compare month</label><select id="tr-history-month">${months.map(m=>`<option value="${m.month}" ${m.month===selectedMonth?'selected':''}>${m.month}</option>`).join('')}</select></div>${current?`<div class="tr-metrics tr-block">${metric('Selected month',current.spending_cents,current.month)}${metric('Same month last year',previous?.spending_cents,previous?.month||'No imported evidence')}</div>${plot('tr-year-compare','Same-month category comparison')}<p class="tr-subtle">${current.closed_month?'Closed calendar month':'Month in progress'} · ${current.covered_accounts}/${current.spending_accounts} spending accounts have history reaching this month. ${current.review_count} records need review.</p>${btn('Inspect this month','history-detail',`data-month="${current.month}"`)}`:empty('Import history to compare months.')}`)}${panel('What might next month look like?',`<p class="tr-subtle">${prior?'Last year’s corresponding month was '+prior.month+'. This is evidence to compare, not a guaranteed future bill.':'The matching month last year is not in your imported history.'}</p>${metric('Prior matching month',prior?.spending_cents,prior?.month||'Unavailable')}<p class="tr-subtle">Explore recent spending versus seasonal history in your trajectory. Confirm bills and debt terms for accurate timing.</p>${btn('Explore seasonal trajectory','seasonal-forecast')}${prior?btn('See supporting purchases','history-detail',`data-month="${prior.month}"`):''}`)}</div>${panel('Month by month',`<div class="tr-table-wrap"><table class="tr-table"><caption>Income, net spending, and coverage by calendar month</caption><thead><tr><th>Month</th><th>Income</th><th>Spending</th><th>Difference</th><th>Review</th></tr></thead><tbody>${[...months].reverse().map(m=>`<tr><td>${btn(m.month,'history-detail',`data-month="${m.month}"`)}${!m.closed_month?'<span class="tr-pill">In progress</span>':''}</td><td>${precise(m.income_cents)}</td><td>${precise(m.spending_cents)}</td><td>${precise(m.income_cents-m.spending_cents)}</td><td>${m.review_count} of ${m.transaction_count}</td></tr>`).join('')}</tbody></table></div>`)}`;
  }
  function drawHistory(){const months=data.history.months;chart('tr-history','bar',months.map(m=>m.month),[{label:'Income',data:months.map(m=>m.income_cents),backgroundColor:'#5a9b89',borderRadius:4},{label:'Spending',data:months.map(m=>m.spending_cents),backgroundColor:'#d3b38b',borderRadius:4}],i=>showTransactions({ids:months[i].ids}));const current=months.find(m=>m.month===(historyMonth||months.at(-1)?.month));const previous=months.find(m=>m.month===`${Number(current?.month.slice(0,4))-1}${current?.month.slice(4)}`);const cats=[...new Set([...(current?.categories||[]),...(previous?.categories||[])].map(c=>c.category))];chart('tr-year-compare','bar',cats.map(title),[{label:current?.month||'Current',data:cats.map(c=>current?.categories.find(x=>x.category===c)?.amount_cents??0),backgroundColor:'#4398ab'},{label:previous?.month||'No prior history',data:cats.map(c=>previous?.categories.find(x=>x.category===c)?.amount_cents??null),backgroundColor:'#c7d6cc'}],i=>showTransactions({ids:[...(current?.categories.find(c=>c.category===cats[i])?.ids||[]),...(previous?.categories.find(c=>c.category===cats[i])?.ids||[])]}));$('#tr-history-month')?.addEventListener('change',e=>{historyMonth=e.target.value;render();});}
  function budgetPlans(){const rows=data.records.filter(r=>r.kind==='budget');const latest=[...rows].sort((a,b)=>b.data.effective_on.localeCompare(a.data.effective_on))[0]?.data.source||'';return rows.filter(r=>(r.data.source||'')===(budgetSource??latest));}
  function budget(){const plans=budgetPlans();const categories=[...new Set([...plans.map(r=>r.data.category),...data.categories.map(r=>r.category)])];return `<div class="tr-actions tr-block"><label for="tr-budget-source">Budget version</label><select id="tr-budget-source">${[...new Set(data.records.filter(r=>r.kind==='budget').map(r=>r.data.source||''))].map(source=>`<option value="${esc(source)}" ${source===(plans[0]?.data.source||'')?'selected':''}>${esc(source||'Entered in Trajectory')}</option>`).join('')}</select></div><div class="tr-data-note">Targets are plans, not actual bills. Savings and debt-payment targets stay separate from consumption. Review dated imported targets before using them for decisions.</div>${panel('Give every category a direction',plans.length?`<div class="tr-metrics">${metric('Planned monthly income',plans.filter(r=>r.data.purpose==='income').reduce((s,r)=>s+r.data.monthly_cents,0),'Budget target, not observed earnings')}${metric('Monthly spending targets',plans.filter(r=>r.data.purpose==='spending').reduce((s,r)=>s+r.data.monthly_cents,0),'User-entered plan')}${metric('Savings contributions',plans.filter(r=>r.data.purpose==='savings').reduce((s,r)=>s+r.data.monthly_cents,0),'Transfers, not spending')}${metric('Debt payment targets',plans.filter(r=>r.data.purpose==='debt_payment').reduce((s,r)=>s+r.data.monthly_cents,0),'Confirm terms in Wealth & debt')}</div>`:empty('Add a target or import your existing budget through private onboarding.'),btn('Add budget target','add','data-kind="budget"'))}${panel('Spending against your plan',plot('tr-budget-chart','Monthly spending target and selected-period actuals')+`<p class="tr-subtle">Targets are monthly; actuals follow the selected date range.</p>`)}${panel('Your targets',plans.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-source">${esc(title(r.data.purpose))} · ${esc(r.data.source||'Entered in Trajectory')} · ${esc(r.data.effective_on)} · ${r.data.reviewed?'Reviewed':'Needs review'}</div></div><div>${money(r.data.monthly_cents)} / month ${btn('Edit','edit',`data-id="${r.id}"`)}</div></div>`).join('')||empty('Your saved targets appear here.'))}`;}
  function drawBudget(){$('#tr-budget-source')?.addEventListener('change',e=>{budgetSource=e.target.value;render();});const plans=budgetPlans().filter(r=>r.data.purpose==='spending');const cats=[...new Set([...plans.map(r=>r.data.category),...data.categories.map(r=>r.category)])];chart('tr-budget-chart','bar',cats.map(title),[{label:'Monthly target',data:cats.map(c=>plans.filter(r=>r.data.category===c).reduce((s,r)=>s+r.data.monthly_cents,0)),backgroundColor:'#c1d4c4'},{label:'Selected-period actual',data:cats.map(c=>data.categories.find(r=>r.category===c)?.amount_cents||0),backgroundColor:'#4398ab'}],i=>showTransactions({category:cats[i]}));}

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
    const defaults={ministry:'Samaritan Ministries',provider:'Prisma Health',reserve_shares:true,confirmed:false,reviewed:false,priority:1,purity_bps:9999,quantity:1,weight:1,distribution_retention_bps:10000};
    let html=field('name','Name','text',record?.name||preset.name||'',null,true);
    for(const [key,def] of Object.entries(data.definitions[kind])) {
      let value=d[key]??defaults[key]??(['money','percent'].includes(def.type)&&def.required?0:'');
      if(['money','percent'].includes(def.type)&&value!=='')value=value/100;
      if(def.type==='rate'&&value!=='')value=value/1000;
      const choices=def.type.endsWith('_record')?[{value:'',label:'Choose a record'},...data.records.filter(r=>r.kind===def.type.slice(0,-7)).map(r=>({value:r.id,label:r.name}))]:def.type==='account'?[{value:'',label:'Select account'},...data.accounts.map(a=>({value:a.id,label:a.name}))]:def.type==='category'?(def.required?data.category_options:[{value:'',label:'All categories'},...data.category_options]):def.choices;
      html+=field(key,def.label,def.type==='boolean'?'checkbox':def.type==='date'?'date':['money','percent','rate','decimal','integer'].includes(def.type)?'number':'text',value,choices);
    }
    if(kind.startsWith('medical_'))html=`<p class="tr-subtle tr-block">Track financial references only. Submit bills through your ministry separately. Expected shares are never guaranteed income. Matching a statement row prevents duplicate spending; a manual record does not change bank cash.</p>`+html+(record?`<div class="tr-actions tr-block">${btn('Remove record','medical-remove',`data-id="${record.id}"`)}</div>`:'');
    dialog(`${record?'Edit':'Add'} ${title(kind)}`,html,async values=>{
      const payload={};
      for(const [key,def] of Object.entries(data.definitions[kind])) {
        let v=values.get(key);
        if(def.type==='boolean')v=v==='on';
        else if(v===''&&!def.required)continue;
        else if(['money','percent'].includes(def.type))v=minor(v);
        else if(def.type==='rate')v=rateMillis(v);
        else if((['integer','account'].includes(def.type)||def.type.endsWith('_record')))v=Number(v);
        payload[key]=v;
      }
      await api(endpoint(record?`/records/${record.id}`:'/records'),record?'PATCH':'POST',{kind,name:values.get('name'),data:payload,version:record?.version});
    });
  }
  function chooseAdd() {
    if(scope==='both'){notice('Choose Personal or Business before adding to your plan.');return;}
    const kinds=['recurring','goal','debt','asset','metal','scenario',...(data?.business?['payroll','reliance']:['medical_need','medical_bill','medical_payment','medical_share','medical_membership'])];
    dialog('Add to your plan',`<div class="tr-choices tr-block">${kinds.map(k=>btn(title(k),'choose-kind',`data-kind="${k}"`)).join('')}</div>`,null);
  }
  function transactionForm(tx) {
    const source=scope==='both'?data.by_space[tx.space_id]:data;
    const matched=source.records.find(r=>['medical_payment','medical_share','medical_membership'].includes(r.kind)&&r.data.transaction_id===tx.id);
    if(matched){recordForm(matched.kind,matched);return;}
    dialog('Categorize transaction',`<p class="tr-subtle">${scope==='both'?esc(tx.space_label)+' · ':''}${esc(tx.merchant)} · ${precise(tx.amount_cents)}<br>${esc(tx.explanation)}</p>${field('category','Category','text',tx.category,source.category_options.map(c=>({value:c,label:source.workspace_settings?.income_categories?.[c]||title(c)})))}${field('flow','Money movement','text',tx.flow,['expense','income','refund','transfer','card_payment','asset_transfer','asset_sale','loan_draw','unclassified_deposit','owner_wages','owner_distribution','reimbursement','debt_payment','duplicate'])}${field('face_punched','Face Punched · unexpected, one time','checkbox',tx.face_punched)}${field('bullshit_spending','Bullshit Spending · unnecessary to me','checkbox',tx.bullshit_spending)}${field('learn','Remember an exact merchant rule','checkbox',false)}<div class="tr-actions tr-block">${btn('Split personal / business','split',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`)}${btn('Undo last review','undo',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`)}${source.space.kind==='household'&&tx.amount_cents<0?btn('Match monthly sharing contribution','membership-match',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`):''}${source.space.kind==='household'?btn('Match medical payment / share','medical-match',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`):''}</div>`,async v=>{await api(endpoint(`/transactions/${tx.id}`,tx.space_id||active),'PATCH',{version:tx.version,category:v.get('category'),flow:v.get('flow'),face_punched:v.get('face_punched')==='on',bullshit_spending:v.get('bullshit_spending')==='on',learn:v.get('learn')==='on'});});
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
    dialog(`Projected ${date(row.date)}`,`<div class="tr-block"><div class="tr-row">Cash <strong>${money(row.cash_cents)}</strong></div><div class="tr-row">Available after reserves <strong>${money(row.available_cents)}</strong></div><div class="tr-row">Reserved for goals <strong>${money(row.goal_reserve_cents)}</strong></div><div class="tr-row">Reserved for medical bills <strong>${money(row.medical_reserve_cents||0)}</strong></div>${data.bills.filter(b=>b.date===row.date).map(b=>`<div class="tr-row">${esc(b.name)}<strong>${money(b.amount_cents)}</strong></div>`).join('')}<p class="tr-subtle">This projection includes confirmed schedules, estimated variable spending, debt payments, and goal reserves. Edit those inputs in Bills & goals or Wealth & debt.</p></div>`,null);
  }
  async function mapBankAccounts(id) {
    const result=await api(endpoint(`/banks/${id}/accounts`));
    const fresh=result.accounts.filter(a=>!a.existing);
    if(!fresh.length){notice('All returned accounts already have owners. Use Assign owner to move an imported account.');return;}
    dialog('Choose who owns each bank account',`<p class="tr-subtle">Transactions will be imported only into the company or household you choose. Its authorized financial members can see that account. Unassigned new accounts remain outside your books.</p>${fresh.map((a,i)=>field('bank-'+i,a.name+(a.mask?' (…'+a.mask+')':''),'text','',[{value:'',label:'Leave unassigned'},...spaces.map(s=>({value:s.id,label:s.name}))],true)).join('')}`,async values=>{const assignments=fresh.flatMap((a,i)=>values.get('bank-'+i)?[{id:a.id,space_id:Number(values.get('bank-'+i))}]:[]);if(!assignments.length)throw new Error('Choose an owner for at least one account.');await api(endpoint(`/banks/${id}/accounts`),'POST',{assignments});notice('Account ownership saved. History is being synchronized.');},'Assign accounts & import');
  }
  async function connect(connectionId=null) {
    if(linkInProgress){notice('A bank connection window is already open. Finish or close it before starting another one.');return;}
    linkInProgress=true;
    let handler;
    const finish=()=>{handler?.destroy();linkInProgress=false;};
    try {
      const token=await api(endpoint('/banks/link'),'POST',{connection_id:connectionId});
      if(!window.Plaid)await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://cdn.plaid.com/link/v2/stable/link-initialize.js';script.onload=resolve;script.onerror=()=>reject(new Error('Unable to load bank linking.'));document.head.appendChild(script);});
      handler=window.Plaid.create({token:token.link_token,onSuccess:async (publicToken,metadata)=>{try{if(connectionId)await api(endpoint(`/banks/${connectionId}/sync`),'POST',{institution_name:metadata?.institution?.name||null});else {const result=await api(endpoint('/banks/exchange'),'POST',{public_token:publicToken,institution_name:metadata?.institution?.name||null});if(result.needs_mapping){finish();await load();await mapBankAccounts(result.connection_id);return;}}notice('Bank connected. History is being synchronized.');await load();}catch(e){notice(e.message);}finally{finish();}},onExit:finish});
      handler.open();
    } catch(e) {linkInProgress=false;throw e;}
  }
  root.addEventListener('click',async e=>{
    const target=e.target.closest('[data-action]');if(!target)return;
    const a=target.dataset.action,id=Number(target.dataset.id),spaceId=Number(target.dataset.spaceId)||active,r=data?.records.find(r=>r.id===id),tx=[...(evidenceRows||[]),...(data?.review_transactions||[]),...(data?.transactions||[])].find(t=>t.id===id&&(!target.dataset.spaceId||(t.space_id||active)===spaceId));
    try {
      if(a==='forecast-horizon'){outlook.setHorizon(Number(target.dataset.days));render();root.querySelector('.tr-horizon [aria-pressed="true"]')?.focus({preventScroll:true});return;}
      if(await planning.handle(a,target))return;
      if(['overview','accounts','history','budget','bills','assets','business','medical'].includes(a)){navigate(a);}
      else if(a==='review-email'){const p=data.review_email;dialog('Review email reminders',`<p class="tr-subtle tr-block">Send to ${esc(p.email)} for ${esc(data.space.name)}. Includes the total waiting for review and the four most recent transactions. Daily emails arrive around 9 AM; weekly emails arrive Monday around 9 AM. Empty queues do not generate email. These settings apply only to you.</p>${field('frequency','Frequency','text',p.frequency,[{value:'off',label:'Off'},{value:'daily',label:'Daily'},{value:'weekly',label:'Weekly · Mondays'}])}${field('timezone','Timezone','text',p.timezone,null,true)}`,async v=>{await api(endpoint('/review-email'),'PATCH',{frequency:v.get('frequency'),timezone:v.get('timezone')});notice('Your review email preference is saved.');});}
      else if(a==='transactions'){remember();tab=a;filter={review:true};render();}
      else if(a==='subscription-add')recordForm('recurring',null,{category:'subscriptions',cadence:'monthly',expense_type:'fixed'});
      else if(a==='subscription-review'){const s=data.commitments.subscriptions[Number(target.dataset.index)];recordForm('recurring',null,{name:s.name,merchant:s.merchant,account_id:s.account_id,amount_cents:-s.amount_cents,category:'subscriptions',cadence:'monthly',expense_type:'fixed',confirmed:false});}
      else if(a==='payment-setup')recordForm('payment_notice',null,{account_id:id,confirmed:false});
      else if(a==='monarch-import')dialog('Import Monarch history',`<p class="tr-subtle">Use the original Transactions CSV. Account types are reviewed next; balances stay missing until observed. Source categories remain reviewable. Importing again preserves corrections and matches source IDs.</p><label class="tr-field full">Monarch transactions CSV<input type="file" name="file" accept=".csv" required></label>`,async v=>{v.set('kind','monarch');const preview=await api(endpoint('/imports/preview'),'POST',v);$('#tr-dialog').close();dialog('Review imported accounts',`<p class="tr-subtle">${preview.count} transactions across ${preview.accounts.length} accounts. Select the type of every account.</p>`+preview.accounts.map((name,i)=>field('account-'+i,name,'text','',[{value:'',label:'Choose type'},'cash','credit','loan','investment'])).join(''),async values=>{const kinds={};preview.accounts.forEach((name,i)=>kinds[name]=values.get('account-'+i));await api(endpoint('/imports/confirm'),'POST',{token:preview.token,account_kinds:kinds});notice('History imported. Review balances, categories, and transfer matches.');},'Import history');return false;},'Preview import');
      else if(a==='toggle-seasonal'){seasonal=!seasonal;await load();}
      else if(a==='seasonal-forecast'){seasonal=true;tab='overview';await load();}
      else if(a==='history-detail'){const m=data.history.months.find(m=>m.month===target.dataset.month);if(m)await showTransactions({ids:m.ids});}
      else if(a==='deposit-evidence'){await showTransactions({ids:data.unresolved_deposits.ids});}
      else if(a==='income-source'){const source=data.income_sources.sources.find(s=>s.key===target.dataset.key);if(source)await showTransactions({ids:source.ids});}
      else if(a==='interest-evidence'){await showTransactions({ids:data.interest_by_account.find(x=>x.account_id===id).ids});}
      else if(a==='account-activity'){const requestedSpace=active;const rows=await api(endpoint('/evidence'),'POST',{account_id:id});if(requestedSpace!==active)return;remember();evidenceRows=rows;filter={};tab='transactions';render();}
      else if(a==='membership')recordForm('recurring',null,{name:'Monthly Samaritan sharing',merchant:'Samaritan Ministries',cadence:'monthly',category:'health',expense_type:'fixed',amount_cents:0});
      else if(a==='medical-child')recordForm(target.dataset.kind,null,{[target.dataset.parentKey]:id});
      else if(a==='membership-match'){$('#tr-dialog').close();recordForm('medical_membership',null,{name:tx.merchant,transaction_id:tx.id,paid_on:tx.date,amount_cents:Math.abs(tx.amount_cents)});}
      else if(a==='medical-match'){$('#tr-dialog').close();recordForm(tx.amount_cents<0?'medical_payment':'medical_share',null,{name:tx.merchant,transaction_id:tx.id,paid_on:tx.date,amount_cents:Math.abs(tx.amount_cents),status:'received'});}
      else if(a==='medical-evidence')showTransactions({ids:JSON.parse(target.dataset.ids)});
      else if(a==='medical-remove'){$('#tr-dialog').close();dialog('Remove this medical record?',`<p class="tr-subtle">Dependent records must be removed or reassigned first. Any matched statement row returns to its previous classification. Audit history is preserved.</p>`,async()=>{await api(endpoint(`/records/${id}`),'DELETE',{version:r.version});},'Remove record');}
      else if(a==='add')recordForm(target.dataset.kind);
      else if(a==='choose-kind'){$('#tr-dialog').close();recordForm(target.dataset.kind);}
      else if(a==='chart-data'){
        const table=chartTables[target.dataset.chart];
        dialog(table.title,`<div class="tr-table-wrap"><table class="tr-table"><caption>${esc(table.title)}</caption><thead><tr><th>Period / category</th>${table.datasets.map(d=>`<th>${esc(d.label||'Value')}</th>`).join('')}<th></th></tr></thead><tbody>${table.labels.map((label,i)=>`<tr><td>${esc(label)}</td>${table.datasets.map(d=>`<td>${money(d.data[i]??null)}</td>`).join('')}<td>${table.click?`<button type="button" data-chart-row="${i}">Inspect</button>`:''}</td></tr>`).join('')}</tbody></table></div>`,null);
        $('#tr-fields').querySelectorAll('[data-chart-row]').forEach(b=>b.onclick=()=>{$('#tr-dialog').close();table.click(Number(b.dataset.chartRow));});
      }
      else if(a==='edit')recordForm(r.kind,r);
      else if(a==='classify')transactionForm(tx);
      else if(a==='bulk-suggestion'){
        const suggestion=data.review_suggestions[Number(target.dataset.index)];
        const sourceId=suggestion.transactions[0]?.space_id||active;
        const rows=suggestion.transactions.map(t=>`<tr><td>${esc(t.date)}</td><td>${esc(t.merchant)}</td><td class="tr-money">${precise(t.amount_cents)}</td></tr>`).join('');
        dialog(`Review ${suggestion.count} ${suggestion.merchant} purchases`, `<p class="tr-subtle">${esc(suggestion.evidence)}</p><div class="tr-row"><span>Category</span><strong>${esc(title(suggestion.category))}</strong></div><div class="tr-row"><span>Purchases</span><strong>${suggestion.count} · ${precise(suggestion.amount_cents)}</strong></div><div class="tr-table-wrap tr-suggestion-transactions"><table class="tr-table"><caption>Every transaction that will be changed</caption><thead><tr><th scope="col">Date</th><th scope="col">Merchant</th><th scope="col">Amount</th></tr></thead><tbody>${rows}</tbody></table></div><p class="tr-subtle">This applies the shown category to these exact purchases only. It does not create a rule for future purchases.</p>`, async()=>{await api(endpoint('/transactions/bulk-classify',sourceId),'POST',{transactions:suggestion.transactions.map(t=>({id:t.id,version:t.version})),category:suggestion.category,flow:suggestion.flow,face_punched:suggestion.face_punched,bullshit_spending:suggestion.bullshit_spending});notice(`${suggestion.count} transactions reviewed.`);},'Apply to these purchases');
      }
      else if(a==='date')showTransactions({date:target.dataset.date});
      else if(a==='review'){remember();tab='transactions';filter={review:true};evidenceRows=null;render();}
      else if(a==='clear')showTransactions({});
      else if(a==='reconciliation'){
        const matches=await api(endpoint('/reconciliation'));
        dialog('Review possible matches',matches.length?matches.map((m,i)=>`<div class="tr-block"><div class="tr-row"><div><strong>${esc(m.left.merchant)} ↔ ${esc(m.right.merchant)}</strong><p class="tr-subtle">${esc(m.explanation)} ${precise(m.left.amount_cents)} / ${precise(m.right.amount_cents)}</p></div><button type="button" data-match="${i}">Review</button></div></div>`).join(''):empty('No likely transfer or duplicate pairs in the recent history.'),null);
        $('#tr-fields').querySelectorAll('[data-match]').forEach(button=>button.onclick=()=>{const match=matches[Number(button.dataset.match)];dialog('Confirm matched transactions',`<p class="tr-subtle">${esc(match.left.merchant)} ↔ ${esc(match.right.merchant)}</p>${field('kind','Purpose','text',match.kind,match.kind==='duplicate'?['duplicate']:['transfer','card_payment','owner_wages','owner_distribution'])}`,async v=>{await api(endpoint('/reconciliation'),'POST',{left_id:match.left.id,right_id:match.right.id,left_version:match.left.version,right_version:match.right.version,kind:v.get('kind')});});});
      }
      else if(a==='combined'){
        const company=businessSpace;if(!company)throw new Error('Link an authorized business first.');
        const combined=await api(endpoint('/combined?business_id='+company.id+'&from='+data.range.start+'&through='+data.range.end));
        dialog('Company → household',`<div class="tr-block"><div class="tr-flow"><div>${esc(combined.business)}<strong>${money(combined.company_owner_outflow_cents)}</strong></div><span>→</span><div>Matched owner income<strong>${money(combined.eliminated_transfers_cents)}</strong></div><span>→</span><div>Household spending<strong>${money(combined.household_spending_cents)}</strong></div></div><p class="tr-subtle">${money(combined.unmatched_owner_income_cents)} of household owner income still needs matching. Matched transfers are eliminated from combined external income and spending.</p><p>Combined net worth: <strong>${money(combined.net_worth_cents)}</strong> ${combined.net_worth_complete?'':'· incomplete valuation'}</p><p class="tr-subtle">${money(combined.excluded_business_equity_cents)} in linked business equity excluded to avoid counting company assets twice. ${esc(combined.net_worth_setup||'')}</p></div>`,null);
      }
      else if(a==='import')importForm();
      else if(a==='payroll-import')importForm('payroll');
      else if(a==='bank-map')await mapBankAccounts(id);
      else if(a==='connect')await connect();
      else if(a==='reconnect')await connect(id);
      else if(a==='sync'){await api(endpoint(`/banks/${id}/sync`),'POST',{});notice('Sync queued.');}
      else if(a==='disconnect')dialog('Disconnect this bank?',`<p class="tr-subtle">Stop future synchronization and revoke this connection. Your imported history remains in Trajectory.</p>`,async()=>{await api(endpoint(`/banks/${id}`),'DELETE');},'Disconnect');
      else if(a==='archive'){await api(endpoint(`/records/${id}`),'DELETE',{version:r.version});await load();}
      else if(a==='undo'){await api(endpoint(`/transactions/${id}/undo`,spaceId),'POST',{version:tx.version});$('#tr-dialog').close();await load();}
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
  $('#tr-space-tabs').addEventListener('click',e=>{const button=e.target.closest('[data-scope]');if(!button||button.dataset.scope===scope)return;navigation.length=0;$('#tr-back').hidden=true;scope=button.dataset.scope;active=(scope==='personal'?personalSpace:businessSpace)?.id||personalSpace?.id||businessSpace?.id;scenarioId='';filter={};budgetSource=null;historyMonth='';evidenceRows=null;data=null;$('#tr-dialog').close();$('#tr-content').innerHTML='';renderSpaceTabs();load();});
  $('#tr-range').onchange=()=>{filter={};$('#tr-date-fields').hidden=$('#tr-range').value!=='custom';if($('#tr-range').value!=='custom'||($('#tr-start').value&&$('#tr-end').value))load();};
  for(const selector of ['#tr-start','#tr-end'])$(selector).onchange=()=>{if($('#tr-start').value&&$('#tr-end').value)load();};
  root.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{if(data)navigate(b.dataset.tab);});
  $('#tr-back').onclick=()=>{const previous=navigation.pop();if(!previous)return;({tab,filter,evidenceRows}=previous);render();$('#tr-back').hidden=!navigation.length;window.scrollTo(0,previous.scroll);};
  $('#tr-company').onchange=()=>{businessSpace=spaces.find(s=>s.id===Number($('#tr-company').value));active=businessSpace.id;scenarioId='';navigation.length=0;$('#tr-back').hidden=true;renderSpaceTabs();load();};
  if(root.dataset.invite){$('#tr-invite').hidden=false;$('#tr-accept').onclick=async()=>{try{await api('/trajectory/invites/accept','POST',{token:root.dataset.invite});location.href='/trajectory';}catch(e){notice(e.message);}};}
  document.addEventListener('visibilitychange',()=>{if(!document.hidden && tab==='assets' && data?.metals.length && !$('#tr-dialog').open)load();});
  setInterval(()=>{if(!document.hidden && tab==='assets' && data?.metals.length && !$('#tr-dialog').open)load();},60000);
  load();
}
