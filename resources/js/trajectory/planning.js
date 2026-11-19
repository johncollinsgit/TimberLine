import {planOverview} from './plan-overview.js';
import {profitLossHtml,profitLossSeries,profitMoney,profitAccountLedgerHtml} from './profit-loss.js';
export function planningUI({getData, spaces, esc, money, precise, panel, btn, plot, chart, line, dialog, field, api, endpoint, minor, load, navigate, evidence, reviewBudget, render, changeAccountingBasis, exportProfitLoss, refreshProfitLoss}) {
  const conversations=new Map(), questionRequests=new Map(), budgetDrafts=new Map();
  let accountRequestSequence=0;
  let draftSequence=0, profitComparison='previous_year', profitAggregation='monthly';
  const contextKey=()=>[getData().space.id,getData().range.start,getData().range.end,getData().scope_filter?.account_id||'all',getData().business?.profit_loss?.requested_basis||'',getData().business?.profit_loss?.snapshot_id||'',getData().business?.profit_loss?.observed_at||'',getData().business?.profit_loss?profitComparison:''].join(':');
  const refreshContextKey=()=>[getData().space.id,getData().range.start,getData().range.end,getData().scope_filter?.account_id||'all',getData().business?.profit_loss?.requested_basis||'',profitComparison].join(':');
  const label = key => getData().workspace_settings?.income_categories?.[key] || key.replaceAll('_',' ');
  const reviewButton = (text,ids) => ids?.length ? btn(text,'plan-evidence',`data-ids="${esc(JSON.stringify(ids))}"`) : '<span class="tr-subtle">No supporting records</span>';
  const jump = (text,tab) => btn(text,'plan-nav',`data-view="${tab}"`);
  const tile = (name,value,note,action) => `<div class="tr-metric"><div class="tr-kicker">${esc(name)}</div><div class="tr-number">${money(value)}</div><p class="tr-subtle">${esc(note)}</p>${action||''}</div>`;
  function summary(){
    const p=getData().planning;if(!p)return '';
    return panel('Your income & cost plan',`<div class="tr-metrics">${tile('Expected income',p.expected_monthly_cents,'Per month · reviewed history plus your overrides',jump('Review income','planning'))}${tile('Income needed',p.required_monthly_cents,'Per month · commitments, spending and goals',jump('See the breakdown','planning'))}${tile('Same period last year',p.last_year_spending_cents,`${p.last_year_from} – ${p.last_year_through} · imported coverage`,reviewButton('See past spending',p.last_year_ids))}${tile('Income gap',p.income_gap_cents,p.provisional?'Provisional · review missing inputs':'Estimated monthly gap',jump('Explore scenarios','planning'))}</div>`,jump('Income & scenarios','planning'));
  }
  function planning(){
    const data=getData(),p=data.planning,view=planOverview(data),d=p.debt_strategy;
    const stat=(name,value,kind)=>`<button type="button" class="tr-plan-stat" data-action="plan-overview-detail" data-kind="${kind}"><span>${name}</span><strong>${precise(value)}</strong></button>`;
    return `<div class="tr-plan-overview"><div class="tr-plan-status"><span>Monthly plan${p.provisional?' · estimated':''}</span>${btn('Adjust income','plan-income')}</div>
      <section class="tr-plan-stats" aria-label="This month’s plan">${stat('Expected income',view.income,'income')}${stat('Planned expenses',view.core,'costs')}${stat(view.gap?'Shortfall':'After expenses',view.gap||Math.max(0,view.income-view.core),'balance')}</section>
      <section class="tr-plan-next"><span>${view.gap?'Savings or additional income needed':'Available after expenses'}</span><strong>${precise(view.gap||Math.max(0,view.income-view.core))}<small> / month</small></strong><div>${view.gap?jump('Review budget','budget'):btn('Debt & goals','plan-overview-detail','data-kind="goals"')}${btn('Adjust plan','plan-costs')}</div></section>
      <div class="tr-plan-chart-grid"><section class="tr-panel"><header class="tr-panel-head"><h2>Income sources</h2>${btn('Edit','plan-income')}</header>${p.sources.some(source=>source.expected_monthly_cents>0)?plot('tr-plan-sources','Expected monthly income by source','large'):'<p class="tr-subtle">Add your expected income to see its sources.</p>'}</section>
      <section class="tr-panel"><header class="tr-panel-head"><h2>Income vs expenses</h2></header>${plot('tr-plan-outlook','Monthly income, essential spending and optional goals','large')}<p class="tr-plan-caption">Current monthly assumptions · tap a month</p></section></div>
      <section class="tr-panel"><header class="tr-panel-head"><h2>Next three months</h2></header><div class="tr-plan-months">${view.months.map((month,index)=>`<button type="button" data-action="plan-overview-detail" data-kind="month" data-index="${index}"><span>${esc(month.label)}</span><strong>${precise(month.gap_cents||Math.max(0,month.expected_income_cents-month.core_cents))}</strong><small>${month.gap_cents?'Savings needed':'After expenses'}</small><i style="--plan-ratio:${Math.min(100,month.expected_income_cents?month.core_cents/month.expected_income_cents*100:100)}%;--plan-color:${month.gap_cents?'#ae6445':'#408c7b'}"></i></button>`).join('')}</div></section>
      <section class="tr-plan-goals"><div><h2>Goals from the surplus</h2><span>${precise(view.goals)} of ${precise(view.goalsRequested)} / month</span></div>${btn('Review','plan-overview-detail','data-kind="goals"')}</section>
      <details class="tr-plan-advanced"><summary>Assumptions & debt options</summary><div class="tr-block"><p class="tr-subtle">${p.assumptions.map(a=>esc(a)).join('<br>')}</p><p class="tr-subtle">Optional goals above are limited to the surplus after expenses and extra debt. The saved forecast and targets change only when you edit them.</p>
      <h3>Debt payoff</h3>${d.status==='setup_required'?`<ul>${d.missing.map(name=>`<li>${esc(name)}</li>`).join('')}</ul>${jump('Review debt terms','assets')}`:d.strategies.map((strategy,index)=>`<div class="tr-row"><span>${esc(strategy.method)} · ${strategy.months===null?'Beyond 50 years':strategy.months+' months'}</span><strong>${precise(strategy.monthly_budget_cents)} / month</strong>${btn('Payoff order','plan-payoff',`data-index="${index}"`)}</div>`).join('')}
      <h3>Previous year</h3><div class="tr-row"><span>Recorded spending</span><strong>${precise(p.last_year_spending_cents)}</strong>${reviewButton('Purchases',p.last_year_ids)}</div><div class="tr-row"><span>Loan proceeds</span><strong>${precise(p.prior_year_borrowing_cents)}</strong>${reviewButton('Loan history',p.borrowing_ids)}</div></div></details></div>`;
  }
  function anomalies(){
    const items=getData().anomalies||[];
    const labels={fit:'Does it belong?',pattern:'Is this your usual treatment?',practice:'Similar-business practice'};
    return panel('Category anomalies',`<p class="tr-subtle">Three independent checks: purchase context, your reviewed history, and business practice. Category-fit hints can flag a mismatch without past examples. Your history requires three other reviewed examples and 80% agreement. Peer statistics appear only when backed by actual data; general guidance is labeled separately.</p>${items.map((a,i)=>`<article class="tr-anomaly"><h3>${esc(a.transaction.merchant)} · ${precise(a.transaction.amount_cents)}</h3><p>${esc(a.transaction.date)} · ${esc(label(a.transaction.category))}${a.suggested_category?' → <strong>'+esc(label(a.suggested_category))+'</strong>':''}</p><span class="tr-pill">${esc(a.rating)}</span><div class="tr-checks">${Object.entries(a.checks).map(([key,c])=>`<section><h4>${labels[key]}</h4><small>${esc(c.status.replaceAll('_',' '))}</small><p>${esc(c.reason)}</p>${c.basis?'<p class="tr-subtle">'+esc(c.basis)+'</p>':''}${c.source_url?'<a href="'+esc(c.source_url)+'" target="_blank" rel="noopener noreferrer">Business-expense reference ↗</a>':''}</section>`).join('')}</div><div class="tr-actions">${reviewButton('Compare supporting purchases',[a.transaction.id,...a.support_ids])}${btn('This is normal','plan-anomaly',`data-index="${i}" data-decision="normal"`)}${btn('Review correction','plan-anomaly',`data-index="${i}" data-decision="change"`)}</div></article>`).join('')||'<div class="tr-empty">No supported category mismatches in the available records.</div>'}`);
  }
  function tax(){
    if(getData().space.kind!=='business')return panel('Business tax review','<p>Select Business and a company at the top to review its entity treatment, state guidance, and supporting expense records.</p>');
    const t=getData().tax_review;
    return `${panel('Tax accountant review',`<p>${esc(getData().space.name)} · ${esc(t.profile.state||'State needed')} · ${esc((t.profile.federal_treatment||'Tax treatment needed').replaceAll('_',' '))} · ${t.profile.tax_year||'Tax year needed'}</p><ul class="tr-subtle">${t.notes.map(n=>`<li>${esc(n)}</li>`).join('')}</ul><p class="tr-subtle">References checked ${t.sources_checked_on}; verify changes for your filing year.</p><div class="tr-actions">${t.sources.map(s=>`<a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer">${esc(s.label)} ↗</a>`).join('')}</div>`,btn('Edit business / tax profile','plan-profile'))}
    ${t.categories.map(c=>panel(label(c.category),`<div class="tr-labels"><strong>${precise(c.amount_cents)} · ${c.count} purchases</strong><span class="tr-pill">${c.readiness_score}/100 evidence readiness</span></div><p>${esc(c.rating)}</p><p class="tr-subtle">${esc(c.guidance)}</p><p class="tr-subtle">${c.reviewed_count} reviewed · ${c.receipt_count} with a linked receipt</p>${reviewButton('Inspect these purchases',c.ids)}`)).join('')}`;
  }
  function profit(){
    const data=getData();
    return profitLossHtml({report:data.business?.profit_loss,space:data.space,range:data.range,comparison:profitComparison,aggregation:profitAggregation,esc,btn,insightThread:(conversations.get(contextKey())||[]).join('')});
  }
  function drawProfit(){
    const report=getData().business?.profit_loss;if(!report)return;
    const series=profitLossSeries(report,profitAggregation,profitComparison);
    if(series.rows.length)chart('tr-pnl-trend-chart','line',series.labels,series.datasets.map(item=>({...line(item.label,item.data,item.color),pointRadius:3,pointHitRadius:24,hidden:!!item.hidden,...(item.comparison?{borderDash:[6,4]}:{})})),index=>{
      const row=series.rows[index];
      dialog('Accounting report · '+row.label,`<p class="tr-subtle">${esc(row.period?.start)} – ${esc(row.period?.end)} · ${esc(report.basis||'Basis unreported')} · ${esc(report.currency||'Currency unreported')}</p>${series.datasets.map(item=>`<div class="tr-row"><span>${esc(item.label)}</span><strong>${esc(profitMoney(item.data[index],report.currency))}</strong></div>`).join('')}<p class="tr-subtle">Saved report figures or complete nonoverlapping monthly totals. Missing components stay unavailable. Change the custom date range to open this period’s full statement.</p>`,null);
    },{beginAtZero:true,formatMoney:value=>profitMoney(value,report.currency)});
    document.querySelector('#tr-pnl-comparison')?.addEventListener('change',event=>{profitComparison=event.target.value;render?.();document.querySelector('#tr-pnl-comparison')?.focus();});
  }
  function companies(){return panel('Businesses & account ownership',`<p class="tr-subtle">Each company has separate books, access, and tax settings. Both shows your household with the company selected above. A shared bank login can feed accounts into different companies; only the connection owner can change those assignments.</p>${spaces.map(s=>`<div class="tr-row"><div><strong>${esc(s.name)}</strong><p class="tr-subtle">${s.kind==='household'?'Private household':'Business · separate financial access'}</p></div><a class="tr-link" href="/trajectory?space=${s.id}&tab=accounts">Open accounts</a></div>`).join('')}<div class="tr-actions tr-block">${btn('Add an entitled business','plan-add-business')}${btn('Edit current profile','plan-profile')}</div><p class="tr-subtle">New companies must have an Everbranch workspace, financial access, and Trajectory entitlement. Linking your household does not grant company access.</p>`);}
  function bud(){return panel('Ask Everbranch',`<p class="tr-subtle">Ask about ${esc(getData().space.name)} and the selected period. Each answer identifies whether it used enabled AI or calculated guidance, and shows missing coverage. Budget changes open a draft for your review; no money moves from this chat.</p><div class="tr-actions">${['What income do I need this month?','What bills are upcoming?','How do metals proceeds affect my gap?','Can the business pay us?','Compare debt payoff methods'].map(q=>btn(q,'plan-bud-prompt',`data-question="${esc(q)}"`)).join('')}</div><label class="tr-field full tr-block">Your question<textarea id="tr-bud-question" rows="3" maxlength="2000"></textarea></label>${btn('Ask Everbranch','plan-bud')}<div id="tr-bud-thread" role="log" aria-live="polite">${(conversations.get(contextKey())||[]).join('')}</div>`);}
  function draw(){
    const data=getData(),p=data.planning;if(!p)return;
    const view=planOverview(data),sources=p.sources.filter(source=>source.expected_monthly_cents>0),colors=['#408c7b','#5b83c1','#9274ba','#d49450','#bd6e90','#6c974e'];
    chart('tr-plan-sources','doughnut',sources.map(source=>source.label),[{data:sources.map(source=>source.expected_monthly_cents),backgroundColor:sources.map((_,index)=>colors[index%colors.length]),borderColor:'#fff',borderWidth:3}],index=>{
      const source=sources[index];dialog(source.label,`<div class="tr-row"><span>Expected / month</span><strong>${precise(source.expected_monthly_cents)}</strong></div><p class="tr-subtle">${source.user_entered?'Your entered estimate':'Estimate from reviewed income history'}</p>${reviewButton('View receipts',source.ids)}${btn('Edit expected income','plan-income')}`,null);
    },{cutout:'70%',explanation:{title:'Expected monthly income',period:data.range.start+' – '+data.range.end,basis:'Planning estimates',source:data.space.name,description:'Reviewed income history and your entered estimates. Loans, asset-sale principal and medical reimbursements are separate cash sources.'}});
    chart('tr-plan-outlook','bar',view.months.map(month=>month.label),[
      {label:'Expected income',data:view.months.map(month=>month.expected_income_cents),backgroundColor:'#408c7b',stack:'income',borderRadius:4},
      {label:'Bills & minimum debt',data:view.months.map(()=>p.fixed_monthly_cents),backgroundColor:'#5b83c1',stack:'expenses'},
      {label:'Spending plan',data:view.months.map(()=>p.flexible_monthly_cents),backgroundColor:'#9274ba',stack:'expenses'},
      {label:'Extra debt from surplus',data:view.months.map(()=>view.extra),backgroundColor:'#d49450',stack:'expenses'},
      {label:'Goals from surplus',data:view.months.map(()=>view.goals),backgroundColor:'#8aad7b',stack:'expenses',borderRadius:4},
    ],index=>void handle('plan-overview-detail',{dataset:{kind:'month',index:String(index)}}),{beginAtZero:true,stacked:true});
  }
  async function handle(a,target){
    if(!a.startsWith('plan-'))return false;
    const data=getData(),p=data.planning;
    if(a==='plan-overview-detail'){
      const view=planOverview(data),kind=target.dataset.kind;
      if(kind==='income')dialog('Income sources',p.sources.map(source=>`<div class="tr-row"><span>${esc(source.label)}<small>${source.user_entered?'Your estimate':'Reviewed history'}</small></span><strong>${precise(source.expected_monthly_cents)} / month</strong>${reviewButton('Receipts',source.ids)}</div>`).join('')+btn('Edit expected income','plan-income'),null);
      else if(kind==='costs')dialog('Planned expenses',p.fixed_records.map(row=>`<div class="tr-row"><span>${esc(row.name)}</span><strong>${precise(row.monthly_cents)}</strong>${btn('Details','edit',`data-id="${row.record_id}"`)}</div>`).join('')+`<div class="tr-row"><span>Spending plan</span><strong>${precise(p.flexible_monthly_cents)}</strong>${reviewButton('Purchases',p.flexible_ids)}</div>`,null);
      else if(kind==='goals')dialog('Goals from the surplus',`<div class="tr-row"><span>After expenses</span><strong>${precise(Math.max(0,view.income-view.core))}</strong></div><div class="tr-row"><span>Extra debt payment</span><strong>${precise(view.extra)} of ${precise(view.extraRequested)}</strong></div><div class="tr-row"><span>Savings goals</span><strong>${precise(view.goals)} of ${precise(view.goalsRequested)}</strong></div><div class="tr-row"><span>Unallocated surplus</span><strong>${precise(view.surplus)}</strong></div><p class="tr-subtle">Monthly planning amounts. Money is set aside only when you transfer it.</p>${jump('Manage savings goals','bills')}`,null);
      else {const month=view.months[Number(target.dataset.index)]??view.months[0];dialog(kind==='month'?month.label+' · monthly estimate':'Monthly funding',`<div class="tr-row"><span>Expected income</span><strong>${precise(view.income)}</strong></div><div class="tr-row"><span>Planned expenses</span><strong>${precise(view.core)}</strong></div><div class="tr-row"><span>${view.gap?'Savings or additional income needed':'After expenses'}</span><strong>${precise(view.gap||Math.max(0,view.income-view.core))}</strong></div><p class="tr-subtle">The current forecast holds monthly income and costs constant. These are full-month estimates; bill timing and seasonal changes can change the result.</p>${btn('Edit income','plan-income')}${btn('Edit spending','plan-costs')}`,null);}
    }
    else if(a==='plan-profit-basis'){await changeAccountingBasis?.(target.dataset.basis);}
    else if(a==='plan-profit-trend'){profitAggregation=target.dataset.period;render?.();document.querySelector(`[data-action="plan-profit-trend"][data-period="${profitAggregation}"]`)?.focus();}
    else if(a==='plan-profit-refresh'){
      const report=data.business?.profit_loss;if(!report?.capabilities?.report_refresh)return true;
      const period=target.dataset.target==='comparison'?report.comparison?.[profitComparison]?.period:report.period;
      if(!period?.start||!period?.end)throw new Error('Choose an exact report period before loading accounting data.');
      const key=refreshContextKey(),label=target.textContent;target.disabled=true;target.textContent='Loading…';
      const status=document.querySelector('#tr-pnl-refresh-status');if(status)status.textContent='Loading the selected accounting report from QuickBooks…';
      try{
        await refreshProfitLoss?.({from:period.start,through:period.end,accounting_basis:report.requested_basis||report.basis||'accrual',...(target.dataset.target!=='comparison'&&report.snapshot_id?{snapshot_id:report.snapshot_id}:{})});
        if(getData()?.space&&refreshContextKey()===key){const message=document.querySelector('#tr-pnl-refresh-status');if(message)message.textContent='The accounting report has been loaded. Saved figures are shown below.';}
      }catch(error){if(getData()?.space&&refreshContextKey()===key){const message=document.querySelector('#tr-pnl-refresh-status');if(message)message.textContent='Report request could not be completed. '+error.message;}}
      finally{if(target.isConnected){target.disabled=false;target.textContent=label;}}
    }
    else if(a==='plan-profit-export'){await exportProfitLoss?.(target.dataset.format,profitComparison);}
    else if(a==='plan-profit-jump'){const node=document.querySelector(target.dataset.section==='net'?'#tr-pnl-net':'#tr-pnl-section-'+target.dataset.section);if(node){if(node.tagName==='DETAILS')node.open=true;node.scrollIntoView({block:'center',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});(node.querySelector('summary')||node).focus({preventScroll:true});}}
    else if(a==='plan-profit-account'){
      const report=data.business?.profit_loss,find=rows=>{for(const row of rows||[]){if(String(row.key||row.id||row.label)===target.dataset.key)return row;const child=find(row.children);if(child)return child;}return null;},row=find([...(report?.statement?.sections||[]).flatMap(section=>section.lines||[]),...(report?.statement?.unclassified_lines||[])]);
      if(row){
        const source=`<p class="tr-subtle">${esc(report.period?.start||data.range.start)} – ${esc(report.period?.end||data.range.end)} · ${esc(report.basis||'Basis unreported')}<br>Source: ${esc(row.source||report.source)}.</p>`;
        if(!report.snapshot_id||!row.id||row.transaction_detail_available===false){
          dialog(row.label,source+'<p class="tr-data-note">Individual accounting transactions are unavailable for this saved account. Its source account identity, report section or original connection needs review. Bank activity is separate supporting evidence.</p>',null);
        }else{
          const request=++accountRequestSequence,key=contextKey(),snapshot=report.snapshot_id;
          dialog(row.label,source+`<div class="tr-pnl-account-request" data-request="${request}" aria-busy="true"><p role="status" aria-live="polite">Loading accounting transactions…</p></div>`,null);
          const container=document.querySelector(`.tr-pnl-account-request[data-request="${request}"]`);
          const current=()=>{const latest=getData();return latest?.space&&container?.isConnected&&document.querySelector('#tr-dialog')?.open&&key===contextKey()&&latest.business?.profit_loss?.snapshot_id===snapshot;};
          try{
            const query=new URLSearchParams({snapshot_id:String(snapshot),account_key:String(row.key)});
            const detail=await api(endpoint('/profit-loss/accounts/transactions')+'?'+query);
            if(current())container.innerHTML=profitAccountLedgerHtml(detail,{esc});
          }catch(error){if(current())container.innerHTML=`<div class="tr-pnl-ledger"><p class="tr-data-note" role="alert">${esc(error.message||'Accounting transactions could not be loaded. Try again after refreshing the report.')}</p>${btn('Try again','plan-profit-account',`data-key="${esc(row.key)}" type="button"`)}<p class="tr-pnl-detail-note">No bank rows have been substituted and no accounting entry has been changed.</p></div>`;}
          finally{if(current())container.setAttribute('aria-busy','false');}
        }
      }
    }
    else if(a==='plan-profit-evidence'){await evidence({ids:JSON.parse(target.dataset.ids),economic_costs:true,economic_category:target.dataset.category});}
    else if(a==='plan-nav'){document.querySelector('#tr-dialog').close();navigate(target.dataset.view);}
    else if(a==='plan-evidence'){document.querySelector('#tr-dialog').close();await evidence({ids:JSON.parse(target.dataset.ids)});}
    else if(a==='plan-income')dialog('Expected monthly income',`<p class="tr-subtle">Blank uses reviewed history. An amount replaces that source’s historical estimate. These are planning inputs, not bank transactions.</p>${p.sources.map(s=>field('income-'+s.category,s.label+' ($/month)','number',s.user_entered?s.expected_monthly_cents/100:'')).join('')}`,async v=>{const income_expectations={};p.sources.forEach(s=>{if(v.get('income-'+s.category)!=='')income_expectations[s.category]=minor(v.get('income-'+s.category));});await api(endpoint('/workspace'),'PATCH',{income_expectations});});
    else if(a==='plan-costs')dialog('Spending and debt scenario',field('flex','Adjustable spending / month ($)','number',p.flexible_monthly_cents/100)+field('extra','Extra debt payment / month ($)','number',(data.workspace_settings.debt_extra_cents||0)/100)+field('months','Debt payoff target (months)','number',data.workspace_settings.debt_target_months||36),async v=>{await api(endpoint('/workspace'),'PATCH',{flexible_monthly_cents:minor(v.get('flex')),debt_extra_cents:minor(v.get('extra')),debt_target_months:Number(v.get('months'))});});
    else if(a==='plan-payoff'){const s=p.debt_strategy.strategies[Number(target.dataset.index)];dialog('Payoff order · '+s.method,`<div class="tr-block">${s.payoff_order.map(r=>`<div class="tr-row">${esc(r.name)}<strong>Month ${r.month}</strong></div>`).join('')||'<p>No debts paid off within the modeled horizon.</p>'}</div>`,null);}
    else if(a==='plan-anomaly'){const item=data.anomalies[Number(target.dataset.index)],decision=target.dataset.decision;dialog(decision==='normal'?'Keep this normal exception?':'Review category correction',`<p class="tr-subtle">${esc(item.transaction.merchant)} · ${esc(item.transaction.date)} · ${precise(item.transaction.amount_cents)}<br>${esc(item.reason)}</p>${decision==='normal'?'<p class="tr-block">Keep '+esc(label(item.transaction.category))+'. This exception is remembered until the purchase changes.</p>':field('category','Choose the category that fits','text',item.suggested_category||'',[{value:'',label:'Choose after reviewing'},...item.category_choices.map(c=>({value:c,label:label(c)}))])}`,async v=>{await api(endpoint(`/transactions/${item.transaction.id}/anomaly`),'POST',{version:item.transaction.version,decision,...(decision==='change'?{category:v.get('category')}:{})});});}
    else if(a==='plan-profile'){const profile=data.tax_review.profile;dialog('Finance space profile',field('name','Display name','text',data.space.name)+field('state','State (two-letter code)','text',profile.state||'')+field('treatment','Federal tax treatment','text',profile.federal_treatment||'unknown',['unknown','sole_proprietor','single_member_llc','partnership','s_corporation','c_corporation'])+field('year','Tax year','number',profile.tax_year||new Date().getFullYear())+field('industry','Industry / type','text',profile.industry||''),async v=>{await api(endpoint('/workspace'),'PATCH',{name:v.get('name'),tax_profile:{state:v.get('state').toUpperCase(),federal_treatment:v.get('treatment'),tax_year:Number(v.get('year')),industry:v.get('industry')}});location.reload();});}
    else if(a==='plan-add-category')dialog('Add your income category',field('label','Category name','text','',null,true),async v=>{const name=v.get('label').trim(),key=name.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'').slice(0,40);await api(endpoint('/workspace'),'PATCH',{income_categories:{[key]:name}});});
    else if(a==='plan-assign'){const account=data.accounts.find(x=>x.id===Number(target.dataset.id));dialog('Assign account ownership',`<p class="tr-subtle">Move ${esc(account.name)} and its transaction history to the selected company or household. People authorized there will be able to see those records. Linked plans, split purchases and reconciled transfers must be resolved first.</p>${field('target','Account belongs to','text','',[{value:'',label:'Choose owner'},...spaces.filter(s=>s.id!==data.space.id).map(s=>({value:s.id,label:s.name}))])}`,async v=>{await api(endpoint(`/accounts/${account.id}/assign`),'POST',{target_space_id:Number(v.get('target'))});},'Move account and history');}
    else if(a==='plan-add-business'){const choices=await api('/trajectory/businesses');dialog('Add an existing business',choices.some(t=>!t.space_id)?field('tenant','Entitled workspace','text','',choices.filter(t=>!t.space_id).map(t=>({value:t.id,label:t.name}))):'<p class="tr-subtle">All eligible businesses are already listed. Enable Trajectory for another Everbranch workspace to add it.</p>',choices.some(t=>!t.space_id)?async v=>{const r=await api('/trajectory/businesses','POST',{tenant_id:Number(v.get('tenant'))});location.href=`/trajectory?space=${r.space.id}&tab=accounts`;return false;}:null);}
    else if(a==='plan-budget-review'){
      const draft=budgetDrafts.get(target.dataset.draft);
      if(!draft||draft.context!==contextKey()||draft.proposal.space_id!==data.space.id)throw new Error('This budget draft belongs to a different view. Ask again in the current space.');
      const proposal=draft.proposal,current=proposal.record_id?data.records.find(row=>row.id===proposal.record_id&&row.kind==='budget'):null;
      if(proposal.record_id&&(!current||current.version!==proposal.expected_version))throw new Error('This budget changed. Refresh and prepare a new draft.');
      if(typeof reviewBudget!=='function')throw new Error('Open Budget to review and save this proposed target.');
      await reviewBudget({...proposal,version:proposal.expected_version});
    }
    else if(a==='plan-bud-prompt'||a==='plan-bud'){
      const input=document.querySelector('#tr-bud-question'),question=target.dataset.question||input.value.trim();if(!question)return true;
      const key=contextKey(),range=document.querySelector('#tr-range').value,requestKey=key+'\n'+question;
      const requestId=questionRequests.get(requestKey)||globalThis.crypto?.randomUUID?.();
      if(requestId)questionRequests.set(requestKey,requestId);
      target.disabled=true;
      try{
        const r=await api(endpoint('/bud'),'POST',{question,range,...(data.business?.profit_loss?{accounting_basis:data.business.profit_loss.requested_basis||data.business.profit_loss.basis||'accrual',comparison:profitComparison}:{}),...(data.scope_filter?.account_id?{account_id:data.scope_filter.account_id}:{}),use_ai:!!requestId,...(requestId?{request_id:requestId}:{}),...(range==='custom'?{from:data.range.start,through:data.range.end}:{})});
        const drafts=(r.proposals||[]).filter(proposal=>proposal.type==='budget'&&proposal.requires_review===true&&proposal.space_id===data.space.id);
        const draftActions=drafts.map((proposal,index)=>{const token=(requestId||'core-'+(++draftSequence))+':'+index;budgetDrafts.set(token,{context:key,proposal});return btn('Review budget draft','plan-budget-review',`data-draft="${esc(token)}"`);}).join('');
        const status=r.ai?.status?.replaceAll('_',' '),partial=r.ai?.source_coverage?.complete===false;
        const html=`<article class="tr-bud-answer"><strong>${esc(question)}</strong><p style="white-space:pre-wrap">${esc(r.reply)}</p><small>${esc(r.mode)}${status?' · '+esc(status):''}</small>${r.notice?`<p class="tr-data-note">${esc(r.notice)}</p>`:''}${partial?'<p class="tr-data-note">AI context is partial. Unverified mixed totals were excluded.</p>':''}<div class="tr-actions">${(r.links||[]).map(l=>jump(l.label,l.tab)).join('')}${draftActions}</div></article>`;
        conversations.set(key,[...(conversations.get(key)||[]).slice(-19),html]);
        if(!['outcome_unconfirmed','request_not_replayed','usage_review_required'].includes(r.ai?.status))questionRequests.delete(requestKey);
        if(contextKey()===key&&document.querySelector('#tr-bud-thread')){document.querySelector('#tr-bud-thread').innerHTML=conversations.get(key).join('');input.value='';}
      }finally{target.disabled=false;}
    }

    return true;
  }
  return {summary,planning,anomalies,tax,profit,companies,bud,draw,drawProfit,handle};
}
