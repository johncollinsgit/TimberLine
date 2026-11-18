import Chart from 'chart.js/auto';
import { planningUI } from './planning.js';
import { chartPresentation } from './chart-theme.js';
import { outlookUI } from './outlook.js';
import { decisionSupportHtml } from './decision-support.js';
import {salesTaxHtml,taxRemittanceReview} from './sales-tax.js';
import {moneyFlowHtml,moneyFlowTrendSeries} from './money-flow.js';
import {reportNavigation} from './navigation-history.js';
import {cardActivityHtml,cardMoney} from './card-activity.js';
import {lenderInterestYtdHtml} from './lender-interest-ytd.js';
import {incomeSourceSlices,incomeReceiptsCaption,incomeSourcesHtml} from './income-sources.js';
import './lender-interest-ytd.css';
import './profit-loss.css';
import { accountingComponentsHtml } from './accounting-report.js';
import { questionSummaryHtml, supportBusinessChoices, monthlyBudgetRecords } from './question-summary.js';
import { budgetMappingPayload, budgetMappingHtml, budgetPreviewHtml } from './budget-import.js';
import { welcomeUI } from './welcome.js';
import { debtPlannerUI } from './debt-planner.js';
import { initHouseholdMobile } from './household-mobile.js';
import './app.css';
import './household-mobile.css';
import './money-flow.css';
import './card-activity.css';
import { minor, percentShare, rateMillis, recordedDifference } from './money.js';
import {saveBankLinkSession, restoreBankLinkSession, clearBankLinkSession, bankOAuthReturnUrl, bankLinkExitMessage} from './bank-link-session.js';
import {createVisualFinance} from './visual-finance.js';
import './visual-finance.css';
import {initTrajectoryAssistant} from './assistant-ui.js';
import {canRefreshFinance, createForegroundRefresh} from './foreground-refresh.js';

const root = document.querySelector('#trajectory');
if (root) boot();
function boot() {
  const mobile = initHouseholdMobile(root);
  const $ = (s) => root.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = (v) => v === null || v === undefined ? 'Unavailable' : new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}).format(v/100);
  const precise = (v) => v === null || v === undefined ? 'Unavailable' : new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v/100);
  const evidenceMoney=(tx,value)=>tx.amount_basis==='owned_credit_activity'?cardMoney(value,tx.currency):precise(value);
  const date = (v) => v ? new Date(v.slice(0,10)+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}) : 'Not observed';
  const title = (s) => (data?.workspace_settings?.income_categories?.[s] || s).replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
  const spaces = JSON.parse(root.dataset.spaces || '[]');
  const incoming = new URLSearchParams(window.location.search);
  let accountFilter = Number.isSafeInteger(Number(incoming.get('account_id')))&&Number(incoming.get('account_id'))>0?Number(incoming.get('account_id')):null;
  $('.tr-toolbar > .tr-actions')?.insertAdjacentHTML('afterbegin','<label class="tr-account-picker">Account <select id="tr-account-filter" aria-label="Filter financial figures by account"><option value="">All accounts</option></select></label>');
  let bankStorage;
  try {bankStorage = window.sessionStorage;} catch {bankStorage = null;}
  const oauthReturn = incoming.has('oauth_state_id');
  const receivedRedirectUri = oauthReturn ? bankOAuthReturnUrl(window.location.href) : null;
  const resumedBankLink = receivedRedirectUri ? restoreBankLinkSession(bankStorage, spaces) : null;
  const requestedSpace = oauthReturn ? spaces.find(s=>s.id===resumedBankLink?.space_id) : spaces.find(s=>s.id===Number(incoming.get('space')));
  const spaceFor = (kind) => requestedSpace?.kind===kind ? requestedSpace : spaces.find(s=>s.kind===kind);
  const personalSpace = spaceFor('household');
  let businessSpace = spaceFor('business');
  let loadVersion = 0;
  let accountingBasis = 'accrual';
  let reportingRange = 'month', incomingMode = 'cash', compareYear = false;
  let decisionMonth = '', forecastExpanded = false, overviewExpanded = false;
  let linkInProgress = false;
  let scope = requestedSpace?.kind==='business' ? 'business' : personalSpace ? 'personal' : 'business';
  let active = (scope==='personal' ? personalSpace : businessSpace)?.id, data, evidenceRows=null, tab = 'overview', charts = [], chartTables = {}, filter = {}, scenarioId = '', historyMonth = '', budgetSource = null, budgetMonth = '', seasonal = false, moneyFlowMode = 'spending', lenderInterestYear = null, formAction;
  let flowDialog=null,restoringNavigation=false,displayedReportContext=null,assistantFocusedTransaction=null;
  if (['overview','analysis','budget','transactions','accounts','planning','anomalies','tax','sales-tax','profit','companies','bud'].includes(incoming.get('tab'))) {tab=incoming.get('tab');filter=tab==='transactions'?{review:true}:{};}
  if (oauthReturn) tab = 'accounts';
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
  const dashboardPath = (spaceId) => `/trajectory/spaces/${spaceId}/dashboard?range=${reportingRange}&accounting_basis=${accountingBasis}${scope!=='both'&&accountFilter!==null?`&account_id=${accountFilter}`:''}${scenarioId?`&scenario_id=${scenarioId}`:''}${reportingRange==='custom'?`&from=${$('#tr-start').value}&through=${$('#tr-end').value}`:''}&seasonal=${seasonal?1:0}`;
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
    assistantFocusedTransaction=null;
    if (!active) {$('#tr-context').textContent='Your private financial workspace';$('#tr-content').innerHTML='<div class="tr-empty"><h2>Your next chapter starts here.</h2>Your Trajectory pilot has not been enabled yet.<br>Once enabled, connect an account or import a statement to begin.</div>';$('#tr-add').disabled=true;return;}
    const requestedVersion = ++loadVersion;
    if(displayedReportContext!==null&&displayedReportContext!==reportContext()){
      filter={};flowDialog=null;navigation.length=0;$('#tr-back').hidden=true;$('#tr-dialog').close();$('#tr-content').innerHTML='';data=null;
    }
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
      if(requestedVersion!==loadVersion)return;data=result;accountFilter=data.scope_filter?.account_id??null;if(scope!=='both'&&(filter.ids||filter.date||filter.account_id)){const rows=await fetchEvidence(filter);if(requestedVersion!==loadVersion)return;evidenceRows=rows;}
      if(scope!=='both'&&['overview','analysis','budget'].includes(tab)){await visualFinance.refresh();if(requestedVersion!==loadVersion)return;}
      render();void assistant.contextChanged?.().catch(error=>notice(error.message));return true;
    }
    catch(e){if(requestedVersion===loadVersion){accountFilter=data?.scope_filter?.account_id??null;if($('#tr-account-filter'))$('#tr-account-filter').value=accountFilter??'';notice(e.message);}return false;}
    finally {if(requestedVersion===loadVersion){$('#tr-content').classList.remove('tr-loading');$('#tr-content').setAttribute('aria-busy','false');}}
  };
  const metric = (label,value,sub) => `<div class="tr-metric"><div class="tr-kicker">${esc(label)}</div><div class="tr-number">${money(value)}</div><div class="tr-subtle">${esc(sub)}</div></div>`;
  const panel = (heading,body,action='') => `<section class="tr-panel"><header class="tr-panel-head"><h2>${esc(heading)}</h2>${action}</header>${body}</section>`;
  const empty = (message) => `<div class="tr-empty">${esc(message)}</div>`;
  const plot = (id,label,size='') => `<div class="tr-chart ${size}"><canvas id="${id}" role="img" aria-label="${esc(label)}"></canvas></div><button class="tr-chart-data" data-action="chart-data" data-chart="${id}">View chart data</button>`;
  const btn = (label,action,attrs='') => `<button data-action="${action}" ${attrs}>${esc(label)}</button>`;
  const chart = (id,type,labels,datasets,click,extra={}) => {
    const canvas=document.getElementById(id);if(!canvas)return;
    chartTables[id]={labels,datasets,click,formatMoney:extra.formatMoney||money,title:canvas.getAttribute('aria-label')};
    const presentation=chartPresentation(canvas,extra.formatMoney||precise,type,extra);
    if(extra.formatMoney&&presentation.options.scales?.y)presentation.options.scales.y.ticks.callback=value=>extra.formatMoney(value);
    const instance=new Chart(canvas,{type,data:{labels,datasets},plugins:presentation.plugins,options:{...presentation.options,onClick:(event,elements)=>{if(elements[0]&&click)click(elements[0].index,elements[0].datasetIndex);}}});charts.push(instance);
  };
  const line = (label,values,color,fill=false) => ({label,data:values,borderColor:color,backgroundColor:color+'0a',fill,tension:0,borderWidth:2.5,pointRadius:0,pointHoverRadius:4,pointHoverBackgroundColor:'#fff',pointHoverBorderWidth:2,pointHitRadius:16,spanGaps:false});
  const navigation=[];
  const reportContext=()=>JSON.stringify([scope,active,businessSpace?.id,accountFilter,reportingRange,$('#tr-start').value,$('#tr-end').value,scenarioId,seasonal,accountingBasis]);
  const focusDescriptor=()=>{const node=document.activeElement?.closest?.('[data-action]');return node?{action:node.dataset.action,label:node.dataset.label||'',ids:node.dataset.ids||'',key:node.dataset.key||'',entry:node.dataset.entry||''}:null;};
  const captureNavigation=()=>({tab,filter:JSON.parse(JSON.stringify(filter)),scroll:window.scrollY,moneyFlowMode,visualFinance:visualFinance.capture(),depth:navigation.length,flowDialog:flowDialog?{...flowDialog}:null,focus:focusDescriptor()});
  const historyNavigation=reportNavigation({window,capture:captureNavigation,context:reportContext,enabled:()=>!new URLSearchParams(location.search).has('oauth_state_id'),
    invalidate:reason=>{navigation.length=0;filter={};evidenceRows=null;flowDialog=null;$('#tr-back').hidden=true;$('#tr-dialog').close();if(reason==='history'){tab='overview';data=null;$('#tr-content').innerHTML='';void load();}},
    restore:async(previous,isCurrent)=>{
      restoringNavigation=true;flowDialog=null;$('#tr-dialog').close();tab=previous.tab;filter=previous.filter;evidenceRows=null;moneyFlowMode=previous.moneyFlowMode;visualFinance.restore(previous.visualFinance);navigation.length=previous.depth;
      await load();if(!isCurrent()){restoringNavigation=false;return;}
      if(previous.flowDialog&&tab==='money-flow'){
        const node=[...root.querySelectorAll('[data-action="money-flow-category"]')].find(node=>node.dataset.label===previous.flowDialog.label&&node.dataset.ids===previous.flowDialog.ids&&node.getClientRects().length);
        if(node)openFlowCategory(node,false);
      }
      $('#tr-back').hidden=!navigation.length;window.scrollTo(0,previous.scroll);
      const target=previous.focus&&[...root.querySelectorAll('[data-action]')].find(node=>node.dataset.action===previous.focus.action&&(node.dataset.label||'')===previous.focus.label&&(node.dataset.ids||'')===previous.focus.ids&&(!previous.focus.key||(node.dataset.key||'')===previous.focus.key)&&(!previous.focus.entry||(node.dataset.entry||'')===previous.focus.entry)&&node.getClientRects().length);
      target?.focus({preventScroll:true});restoringNavigation=false;historyNavigation.sync();
    }});
  const remember=()=>{historyNavigation.push();navigation.push({});$('#tr-back').hidden=false;};
  const navigate=(view)=>{remember();tab=view;filter={};evidenceRows=null;if(scope!=='both'&&['overview','analysis','budget'].includes(view))void load();else render();window.scrollTo(0,0);};
  const fetchEvidence=async next=>{const batches=next.ids?.length>5000?Array.from({length:Math.ceil(next.ids.length/5000)},(_,index)=>({...next,ids:next.ids.slice(index*5000,(index+1)*5000)})):[next];return (await Promise.all(batches.map(batch=>api(endpoint('/evidence'),'POST',batch)))).flat();};
  const showTransactions = async (next) => {
    const requestedContext=reportContext();
    try {
      let records=null;
      if(next.ids||next.date||next.account_id)records=await fetchEvidence(next);
      if(requestedContext!==reportContext())return;
      remember();flowDialog=null;if($('#tr-dialog').open)$('#tr-dialog').close();evidenceRows=records;filter=next;tab='transactions';render();
    } catch(e){notice(e.message);}
  };
  const planning=planningUI({getData:()=>data,spaces,esc,money,precise,panel,btn,plot,chart,line,dialog,field,api,endpoint,minor,load,navigate,render,changeAccountingBasis:async basis=>{if(!['cash','accrual'].includes(basis))return;accountingBasis=basis;await load();},refreshProfitLoss:async payload=>{const requestedContext=reportContext(),spaceId=active;const result=await api(endpoint('/profit-loss/refresh',spaceId),'POST',payload);if(requestedContext===reportContext()&&!await load())throw new Error('The accounting report was saved, but this screen could not reload. Refresh the page to view it.');return result;},exportProfitLoss:async(format,comparison)=>{
    if(!['csv','pdf','print'].includes(format))return;
    const params=new URLSearchParams({range:reportingRange,accounting_basis:accountingBasis,comparison});
    if(reportingRange==='custom'){params.set('from',$('#tr-start').value);params.set('through',$('#tr-end').value);}
    const url=endpoint(`/profit-loss/export/${format}`)+'?'+params;
    if(format==='csv'){const link=document.createElement('a');link.href=url;link.download='';document.body.append(link);link.click();link.remove();}
    else window.open(url,'_blank','noopener');
  },evidence:showTransactions,reviewBudget:proposal=>{
    if(proposal.space_id!==data.space.id)throw new Error('This proposal belongs to another financial space.');
    const current=proposal.record_id?data.records.find(r=>r.id===proposal.record_id&&r.kind==='budget'):null;
    if(proposal.record_id&&(!current||current.version!==proposal.version))throw new Error('Budget changed; refresh before reviewing this proposal.');
    recordForm('budget',current?{...current,name:proposal.name||current.name,data:{...proposal.data}}:null,{...proposal.data,name:proposal.name||'Reviewed budget proposal'});
  }});
  const outlook=outlookUI({getData:()=>data,esc,money,precise,date,plot,chart,line,btn,dialog,evidence:showTransactions,forecastDetail});
  const welcome=welcomeUI({getData:()=>data,esc,btn,navigate,render,budgetImportAction:'budget-import'});
  const debtPlanner=debtPlannerUI({getData:()=>data,esc,precise,btn,plot,chart,line,render,requestScenario:(extraCents,spaceId)=>api(endpoint('/debt-scenarios',spaceId),'POST',{extra_cents:extraCents,...(accountFilter!==null&&spaceId===active?{account_id:accountFilter}:{})})});
  const visualFinance=createVisualFinance({root,getContext:()=>({space_id:active,scope,account_id:accountFilter,from:data?.range.start,through:data?.range.end,today:data?.decision_support?.as_of}),getSpaces:()=>spaces,api,endpoint,esc,precise,chart,
    onBeforeChange:remember,onChange:()=>render(),onChanged:load,onNavigate:(view,rememberStep=true)=>{if(rememberStep)navigate(view);else{tab=view;filter={};evidenceRows=null;render();window.scrollTo(0,0);}},
    onReviewTransaction:row=>{if(row.editable)transactionForm(row);else void showTransactions({ids:[row.id],economic_costs:true});},
    onEditBudget:()=>navigate('budget-targets'),onAddDefinition:()=>void definitionForm().catch(error=>notice(error.message)),onAsk:()=>assistant.openChat(),onClearAccount:()=>{accountFilter=null;filter={};evidenceRows=null;navigation.length=0;$('#tr-back').hidden=true;void load();},notice});
  const assistant=initTrajectoryAssistant({root,getContext:currentAssistantContext,api,endpoint,notice,onChanged:load});
  function currentAssistantContext(){
    const base={space_id:active,scope,...visualFinance.getAssistantContext()};
    if(assistantFocusedTransaction){const tx=assistantFocusedTransaction;return {...base,from:tx.date,through:tx.date,level:'transaction',category:tx.category,subcategory:null,transaction_ids:[tx.source_transaction_id??tx.id],transaction_versions:{[tx.source_transaction_id??tx.id]:tx.version},selection_truncated:false,focused_transaction:true};}
    if(tab==='transactions'){
      let rows=evidenceRows??data?.transactions??[];
      if(filter.ids)rows=rows.filter(r=>filter.ids.includes(r.id));
      if(filter.category)rows=rows.filter(r=>r.category===filter.category);
      if(filter.review)rows=rows.filter(r=>!r.reviewed);
      return {...base,from:data?.range.start,through:data?.range.end,level:'transactions',category:filter.category??null,subcategory:null,transaction_ids:[...new Set(rows.map(r=>r.id))].slice(0,100),transaction_versions:Object.fromEntries(rows.slice(0,100).map(r=>[r.id,r.version])),selection_truncated:rows.length>100,focused_transaction:false};
    }
    if(!['overview','analysis','budget'].includes(tab))return {...base,from:data?.range.start,through:data?.range.end,level:tab,category:null,subcategory:null,transaction_ids:[],transaction_versions:{},selection_truncated:false,focused_transaction:false};
    return base;
  }
  function render() {
    if(!data)return;
    if(moneyFlowMode==='inflows'&&!data.money_flow?.family_inflows)moneyFlowMode='spending';
    charts.forEach(c=>c.destroy());charts=[];chartTables={};
    root.querySelectorAll('[data-tab]').forEach(b=>{if(b.dataset.tab===tab)b.setAttribute('aria-current','page');else b.removeAttribute('aria-current');});
    $('#tr-context').textContent=tab==='profit'?`${date(data.range.start)} – ${date(data.range.end)} · ${data.business?.profit_loss?.source||'Choose a business and all its accounts'}${data.business?.profit_loss?.basis?' · '+data.business.profit_loss.basis:''}`:`${date(data.range.start)} – ${date(data.range.end)} · ${data.coverage.history_days} days of baseline history${data.scope_filter?.account_id?' · Account: '+data.scope_filter.label:''}${data.coverage.provisional?' · Provisional forecast':''}`;
    $('#tr-add').disabled=scope==='both';$('#tr-compare').disabled=scope==='both';$('#tr-compare').hidden=tab==='profit';
    const accountSelect=$('#tr-account-filter');
    if(accountSelect){accountSelect.innerHTML='<option value="">All accounts</option>'+(data.account_options||[]).map(account=>`<option value="${account.id}"${account.id===accountFilter?' selected':''}>${esc(account.name)}</option>`).join('');accountSelect.disabled=scope==='both';}
    const moneyFlow=()=>scope==='both'?panel('Choose one financial space', '<p class="tr-subtle">Choose Personal or Business at the top to follow its money. A combined diagram could count company pay and household receipts twice.</p>'):moneyFlowHtml({report:data.money_flow,mode:moneyFlowMode,esc,precise,btn});
    const views=scope==='both'?{overview:bothOverview,transactions,'money-flow':moneyFlow}:{overview:()=>visualFinance.render('overview'),analysis:()=>visualFinance.render('analysis'),budget:()=>visualFinance.render('budget'),'budget-targets':budget,'legacy-overview':overview,welcome:welcome.render,accounts,history,transactions,bills,medical,assets,business,planning:accountFilter===null?planning.planning:wholeSpacePlanNotice,anomalies:planning.anomalies,tax:planning.tax,'sales-tax':()=>salesTaxHtml({report:data.sales_tax,esc,precise,btn}),'money-flow':moneyFlow,profit:planning.profit,companies:planning.companies,bud:planning.bud};
    const labels={overview:'Home',analysis:'Analysis',accounts:'Accounts',history:'Cash flow & history',budget:'Your budget','budget-targets':'Manage budget targets','legacy-overview':'Detailed forecast',transactions:'Review transactions',bills:'Bills & goals',medical:'Medical sharing',assets:'Wealth & debt',business:'Business',planning:'Your plan',anomalies:'Category anomalies',tax:'Tax accountant review','sales-tax':'Sales tax payments','money-flow':'Money flow',profit:'Profit & loss',companies:'Businesses & accounts',bud:'Ask Everbranch'};
    $('#tr-page-title').textContent=tab==='welcome'?'Get started':labels[tab];
    $('#tr-content').innerHTML=(views[tab]||combinedOnly)();
    mobile?.syncControls();
    if(tab==='planning'&&scope!=='both'&&accountFilter===null) planning.draw();
    if(tab==='profit'&&scope!=='both'&&accountFilter===null) planning.drawProfit();
    root.classList.toggle('tr-visual-view',['overview','analysis','budget'].includes(tab));
    if(['overview','analysis','budget'].includes(tab)&&scope!=='both')visualFinance.draw();
    if(tab==='legacy-overview'&&scope!=='both') drawOverview();
    if(tab==='assets') drawAssets();
    if(tab==='business') drawBusiness();
    if(tab==='medical') drawMedical();
    if(tab==='accounts') drawAccounts();
    if(tab==='history') drawHistory();
    if(tab==='budget-targets') drawBudget();
    if(tab==='money-flow'&&scope!=='both'){
      const series=moneyFlowTrendSeries(data.money_flow,moneyFlowMode).filter(year=>year.values.some(value=>value!==null)),colors=['#a7834b','#527f94','#397d69'];
      const receipts=moneyFlowMode==='inflows',basis=receipts?'cash':moneyFlowMode;
      chart('tr-flow-trend','line',['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],series.map((year,index)=>({...line(`${year.year} · recorded ${receipts?'receipts':'outflows'}`,year.values,colors[index]),pointRadius:4,pointHoverRadius:6,pointHitRadius:24,pointBackgroundColor:colors[index],pointBorderColor:'#fff',pointBorderWidth:1})),(month,year)=>{const ids=series[year]?.months[month]?.views?.[basis]?.[receipts?'inflow_ids':'outflow_ids'];if(ids?.length)showTransactions({ids,...(basis==='cash'?{cash_movement:true}:{economic_costs:true})});},{beginAtZero:true});
    }
    displayedReportContext=reportContext();historyNavigation.sync();
  }
  function comparisonText(current,previous,comparable=false,previousDate=null) {
    if(!compareYear)return '';
    if(previous===null||previous===undefined)return '<p class="tr-subtle tr-comparison">Same period last year: unavailable</p>';
    const change=!comparable||current===null||current===undefined?null:current-previous;
    return `<p class="tr-subtle tr-comparison">Same period last year: <strong>${precise(previous)}</strong>${previousDate?`<br>Observation date ${esc(previousDate)}`:''}${!comparable?'<br>Partial or unmatched coverage; growth is not calculated.':''}${change===null?'':`<br>Change ${change>=0?'+':'−'}${precise(Math.abs(change))}${previous===0?'':` (${change>=0?'+':''}${(change/Math.abs(previous)*100).toFixed(1)}%)`}`}</p>`;
  }
  function periodMetric(label,key,detail) {
    const value=['cash_cents','net_worth_cents'].includes(key)?data.period_balances?.[key]??(data.range.end===data.household_cards?.as_of?data.summary[key]:null):data.summary[key];
    return `<div class="tr-metric"><div class="tr-kicker">${esc(label)}</div><div class="tr-number">${money(value)}</div><div class="tr-subtle">${esc(detail)}</div>${comparisonText(value,data.period_comparison?.summary?.[key],false,['cash_cents','net_worth_cents'].includes(key)?data.period_comparison?.range?.end:null)}</div>`;
  }
  function overview() {
    const summary=data.summary;
    return `${data.economic_coverage?.complete===false?`<p class="tr-data-note tr-tax-economic-warning"><strong>Economic spending is incomplete.</strong> Known recorded costs: ${precise(data.economic_coverage.known_cost_cents)}. ${precise(data.economic_coverage.unresolved_gross_cents)} of tax cash needs its source or principal/cost split reviewed. Unknown costs are not zero. ${btn('Review tax cash','plan-evidence',`data-ids="${esc(JSON.stringify(data.economic_coverage.unresolved_ids))}"`)}</p>`:''}${accountFilter===null?questionSummaryHtml({summary:data.question_summary,esc,precise,btn}):''}<div class="tr-setup-shortcut"><span>New here, or filling in missing information?</span>${btn('Get started','welcome-open')}</div>${compareYear&&data.period_comparison?`<p class="tr-data-note">Comparing ${esc(data.range.start)} – ${esc(data.range.end)} with ${esc(data.period_comparison.range.start)} – ${esc(data.period_comparison.range.end)}. Missing historical evidence stays unavailable. Source and classification coverage may differ between periods; partial recorded figures do not establish income or spending growth.</p>`:''}${decisionSupportHtml({support:data.decision_support,selectedMonth:decisionMonth,esc,precise,date,btn})}
      ${accountFilter!==null?panel('Selected account',`<p class="tr-subtle">Showing ${esc(data.scope_filter?.label)}. Household cost plans and source totals cannot be attributed to one account. Choose all accounts for your monthly income need, metals cash bridge and complete debt picture.</p>`,btn('Show all accounts','account-filter-clear')):''}
      ${data.summary.review_count?panel('Review queue',`<p class="tr-subtle">${data.summary.review_count} transactions need your category or money-movement decision. Clear reviews make the forecast more useful.</p>`,btn('Review transactions','review')):''}
      ${data.unresolved_deposits.ids.length?panel('Resolve incoming deposits',`<p>${precise(data.unresolved_deposits.amount_cents)} of recent deposits need purpose review. The current forecast excludes these from recurring income.</p>${btn('Review deposits','deposit-evidence')}`):''}
      <details class="tr-forecast-details" id="tr-overview-explore" ${overviewExpanded?'open':''}><summary>Explore balances, spending and forecasts</summary>
      ${accountFilter===null?`<details class="tr-forecast-details" id="tr-forecast-details" ${forecastExpanded?'open':''}><summary>Forecast details · cash trends, scenarios & prior-year comparisons</summary><p class="tr-subtle">Explore the detailed forecast separately from the monthly plan. Forecast cash balances and monthly income rates answer different questions.</p>${outlook.html(scenarioId,seasonal)}</details>`:''}
      <div class="tr-metrics">${periodMetric('Money coming in','income_cents','Earned income only')}${periodMetric('Total spending','spending_cents','Selected period · net of refunds; transfers excluded')}${periodMetric('Cash on hand','cash_cents','Dated observed balances')}${periodMetric('Net worth','net_worth_cents',summary.net_worth_complete?'Assets minus liabilities':'Partial recorded valuation')}</div>
      ${householdCards()}
      ${categoryDonuts()}
      ${spendingExplanation()}
      ${unexpectedExpenses()}
      ${accountFilter===null?planning.summary():''}
      ${commitmentCards()}
      ${incomeSources()}
      <div>${panel('Income and spending',plot('tr-cashflow','Daily income versus spending')+`<p class="tr-subtle">Select a date to inspect its transactions.</p>`)}</div>
      <div class="tr-grid equal">${panel('Coming up next',data.bills.length?data.bills.slice(0,6).map(b=>`<div class="tr-row ${b.status==='overdue'?'tr-row-overdue':''}"><div><strong>${esc(b.name)}</strong><div class="tr-subtle">${b.status==='overdue'?'<span class="tr-overdue">Overdue</span> · ':''}${date(b.date)}${b.estimated?' · Estimated':''}</div></div><div class="tr-money">${money(b.amount_cents)} ${btn('View','edit',`data-id="${b.record_id}"`)}</div></div>`).join(''):empty('Confirm recurring bills to build your calendar.'),btn('See bills','bills'))}${panel('Change your trajectory',data.recommendations.length?data.recommendations.slice(0,5).map((r,i)=>`<div class="tr-row"><div><strong>${esc(r.title)}</strong><div class="tr-subtle">Up to ${money(r.monthly_savings_cents)}/month · ${money(r.yearly_cash_impact_cents)} over a year</div></div>${btn('Explore','recommendation',`data-index="${i}"`)}</div>`).join(''):empty('Review your spending profile to surface supported saving opportunities.'))}</div>
      <div class="tr-grid equal">${accountFilter===null?panel('Five years from here',plot('tr-fiveyear','Monthly five-year cash projection')):''}${panel('Your spending calendar',heatmap())}</div></details>`;
  }
  function wholeSpacePlanNotice(){return panel('Your monthly plan uses all accounts',`<p class="tr-subtle">You are viewing ${esc(data.scope_filter?.label||'one account')}. A single account does not establish the household’s income need, budget or business funding capacity. Choose all accounts to review those plans.</p>`,btn('Show all accounts','account-filter-clear'));}
  const householdLabels={take_home_income:'Take home income',owner_draws:'Owner draws from business',employee_pay:'Employee take home pay',metals_sold:'Metals sold to date',personal_credit_cards:'Owed on personal credit cards',interest_paid:'Interest paid',interest_charged:'Interest charged'};
  function householdCards() {
    const cards=data.household_cards;if(!cards)return '';
    const tiles=Object.entries(householdLabels).filter(([key])=>key!=='interest_charged'||cards[key]).map(([key,label])=>{
      const c=cards[key], prior=data.period_comparison?.household_cards?.[key];
      const comparable=Boolean(c?.current_period_complete&&prior?.current_period_complete&&(key!=='employee_pay'||c.name===prior.name));
      return `<article class="tr-metric"><h3 class="tr-kicker">${esc(c?.name||label)}</h3><div class="tr-number">${precise(c?.amount_cents)}</div><p class="tr-subtle">${c?`${esc(c.period_start)} – ${esc(c.period_end)} · ${c.current_period_complete?'Complete recorded coverage':'Partial / dated evidence'}${key==='employee_pay'&&c.gross_cents!==undefined?'<br>Gross pay: '+precise(c.gross_cents):''}`:'Verified figure needed'}${key==='metals_sold'?'<br>Gross proceeds, including cash; excluded from earned income.':''}${key==='take_home_income'?'<br>Cash received after payroll deductions, including draws once. Excludes borrowing, asset sales and reimbursements.':''}</p>${c?`<p class="tr-source">${esc(c.source)}</p>`:''}${comparisonText(c?.amount_cents,prior?.amount_cents,comparable,prior?.observed_on)}${btn(c?.ids?'View transactions':c?'View evidence':'Add evidence','household-observation',`data-metric="${key}"`)}</article>`;
    }).join('');
    const debt=cards.personal_debt, progress=cards.debt_progress;
    const thermometer=`<div class="tr-payoff"><div class="tr-thermometer" ${progress?`role="progressbar" aria-label="Personal debt payoff" aria-valuemin="0" aria-valuemax="10000" aria-valuenow="${progress.progress_bps}" aria-valuetext="${(progress.progress_bps/100).toFixed(1)} percent reduction from starting debt"`:'aria-hidden="true"'}><div class="tr-thermometer-fill" style="height:${progress?progress.progress_bps/100:0}%"></div></div><div><h3>Personal debt payoff</h3><div class="tr-number">${precise(debt?.amount_cents)}</div><p class="tr-subtle">${progress?`${precise(progress.baseline_cents)} starting debt on ${esc(progress.baseline_on)}<br>${progress.reduction_cents>=0?precise(progress.reduction_cents)+' reduction':precise(-progress.reduction_cents)+' increase'} · ${(progress.progress_bps/100).toFixed(1)}% paid down`:'Add a complete current debt total and a verified starting balance for the same debts to measure progress.'}</p>${debt?`<p class="tr-source">Balance date ${esc(debt.observed_on)} · ${esc(debt.source)}</p>`:''}${btn(debt?'View debt evidence':'Set up debt progress','household-observation','data-metric="personal_debt"')}</div></div>`;
    const observations=data.records.filter(r=>r.kind==='financial_observation').sort((a,b)=>b.data.observed_on.localeCompare(a.data.observed_on)||b.id-a.id);
    const history=`<details class="tr-block"><summary>All financial observations (${observations.length})</summary><p class="tr-subtle">Edit an existing observation to correct it, or add a new dated observation. Coverage is your review of the included sources. Keep source notes specific about which accounts and dates are included.</p><div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Figure</th><th>Evidence period</th><th>Amount</th><th>Coverage & source</th><th></th></tr></thead><tbody>${observations.map(r=>`<tr><td>${esc(r.name)}<br><small>${esc(householdLabels[r.data.metric]||'Personal debt')}</small></td><td>${esc(r.data.period_start)} – ${esc(r.data.period_end)}<br>Verified ${esc(r.data.observed_on)}</td><td>${precise(r.data.amount_cents)}${r.data.gross_cents!==undefined?'<br>Gross '+precise(r.data.gross_cents):''}${r.data.baseline_cents!==undefined?'<br>Baseline '+precise(r.data.baseline_cents)+' on '+esc(r.data.baseline_on):''}</td><td>${r.data.complete?'Complete included coverage':'Partial coverage'}<br>${esc(r.data.source)}</td><td>${btn('Edit','edit',`data-id="${r.id}"`)}</td></tr>`).join('')}</tbody></table></div></details>`;
    const priorDebt=data.period_comparison?.household_cards?.personal_debt;
    return panel('Household income & debt',`<p class="tr-subtle">Dated source observations, entered after review. Missing figures stay unavailable; partial figures are not household totals. Every card follows the selected period. Balances use observations available at its cutoff; totals are never prorated. These summaries do not add transactions or change bank balances.</p><div class="tr-metrics tr-household-metrics">${tiles}</div>${thermometer}${comparisonText(debt?.amount_cents,priorDebt?.amount_cents,Boolean(debt?.current_period_complete&&priorDebt?.current_period_complete),priorDebt?.observed_on)}${history}`,btn('Import source observations','observation-import'));
  }
  function unexpectedExpenses() {
    const unexpected=data.unexpected_expenses;
    if(!unexpected)return '';
    return panel('Biggest unexpected expenses',`<div class="tr-labels"><div><div class="tr-number">${money(unexpected.total_cents)}</div><p class="tr-subtle">${esc(date(unexpected.from))} – ${esc(date(unexpected.through))}, ${unexpected.through.slice(0,4)} · ${unexpected.count} unexpected purchase${unexpected.count===1?'':'s'}</p></div><span class="tr-pill">Face Punched</span></div>${unexpected.items.length?unexpected.items.map((t,i)=>`<div class="tr-row tr-unexpected-row"><span class="tr-unexpected-rank">${i+1}</span><div><strong>${esc(t.merchant)}</strong><div class="tr-subtle">${date(t.date)} · ${esc(title(t.category))}</div></div><div class="tr-money"><strong>${precise(-t.amount_cents)}</strong> ${btn('View transaction','plan-evidence',`data-ids="${esc(JSON.stringify([t.id]))}"`)}</div></div>`).join(''):empty('No purchases marked unexpected this month. Mark a one-time surprise as Face Punched when reviewing a transaction.')}<p class="tr-subtle">Largest posted purchases marked Face Punched in the month ending ${date(unexpected.through)}. Gross charges before refunds; transfers and pending charges are excluded.</p>`,unexpected.count?btn('View all unexpected','plan-evidence',`data-ids="${esc(JSON.stringify(unexpected.ids))}"`):btn('Review transactions','review'));
  }
  function bothOverview(){
    const s=data.summary, combined=data.combined_result;
    return `${panel('Personal + business',`<p class="tr-subtle">This view combines your household with ${esc(businessSpace.name)}. Other businesses are not included. Explicitly matched owner payments are eliminated where available; unmatched transfers remain visible for review.</p><div class="tr-metrics">${metric('External income',s.income_cents,'Company and household income, without matched transfers')}${metric('External spending',s.spending_cents,'Company and household spending, without matched transfers')}${metric('Cash on hand',s.cash_cents,'Observed balances across both')}${metric('Combined net worth',s.net_worth_cents,s.net_worth_complete?'Assets minus liabilities':'Complete the linked valuations')}</div>${combined?`<div class="tr-flow"><div>${esc(combined.business)}<strong>${money(combined.company_owner_outflow_cents)}</strong></div><span>→</span><div>Matched owner income<strong>${money(combined.eliminated_transfers_cents)}</strong></div><span>→</span><div>${esc(combined.household)}<strong>${money(combined.household_spending_cents)}</strong></div></div>`:'<p class="tr-subtle">Link the personal and business spaces to see owner-payment matching and combined net worth.</p>'}`)}${s.review_count?panel('Review queue',`<p class="tr-subtle">${s.review_count} personal or business transactions need review. Choose Review transactions to categorize them without switching spaces.</p>`,btn('Review transactions','review')):''}`;
  }
  function combinedOnly(){return panel('Choose a financial space',`<p class="tr-subtle">The combined view is for your shared trajectory and transaction review. Choose Personal or Business at the top to manage accounts, bills, goals, debt, or company planning.</p>`);}
  function spendingExplanation(){
    const analysis=data.spending_analysis;if(!analysis)return '';
    const categories=[...data.categories].filter(c=>c.amount_cents>0).sort((a,b)=>b.amount_cents-a.amount_cents);
    const rows=analysis.largest_transactions;
    return panel(reportingRange==='year'?'What this year cost':'What this period cost',`<div class="tr-cost-summary"><div><span class="tr-kicker">Total spending · ${esc(date(data.range.start))} – ${esc(date(data.range.end))}</span><div class="tr-number">${precise(data.summary.spending_cents)}</div></div><p class="tr-subtle">${analysis.transaction_count} spending transactions, less ${precise(analysis.refund_cents)} in refunds. Internal transfers, credit-card repayments and loan principal are excluded. Purchases on cards are counted when made. Tax principal is excluded; separate tax cost components can share one bank record.</p></div><div class="tr-grid equal tr-cost-lists"><div><h3>Largest recorded costs</h3>${rows.map(t=>`<button class="tr-cost-row" data-action="plan-evidence" data-ids="${esc(JSON.stringify([t.id]))}"><span><strong>${esc(t.merchant)}</strong><small>${date(t.date)} · ${esc(title(t.category))}</small></span><strong>${precise(-t.amount_cents)} ↗</strong></button>`).join('')||empty('No spending in this period.')}</div><div><h3>Where you spent the most</h3>${categories.map(c=>`<button class="tr-cost-row" data-action="plan-evidence" data-ids="${esc(JSON.stringify(c.ids))}"><span><strong>${esc(title(c.category))}</strong><small>${c.ids.length} contributing records</small></span><strong>${precise(c.amount_cents)} ↗</strong></button>`).join('')||empty('No spending categories in this period.')}</div></div>`);
  }
  function incomingSlices(){return incomeSourceSlices(data.income_sources,incomingMode);}
  function categoryDonuts(){
    const incoming=incomingSlices(), outgoing=data.categories.filter(c=>c.amount_cents>0);
    const total=rows=>rows.reduce((sum,r)=>sum+r.amount_cents,0);
    const body=(id,rows,caption)=>`<div class="tr-donut-total"><strong>${precise(total(rows))}</strong><span>Selected period</span></div>${rows.length?plot(id,caption,'donut'):empty('No matching transactions in this period.')}<p class="tr-subtle">${caption}</p>`;
    return `<div class="tr-grid equal tr-category-donuts">${panel('Money coming in',`<div class="tr-period-tabs tr-income-mode" role="group" aria-label="Incoming money type"><button data-action="donut-mode" data-mode="cash" aria-pressed="${incomingMode==='cash'}">All cash received</button><button data-action="donut-mode" data-mode="income" aria-pressed="${incomingMode==='income'}">Income receipts</button></div>`+body('tr-income-donut',incoming,incomeReceiptsCaption(data.income_sources,incomingMode,precise)))}${panel('Money going out','<div class="tr-donut-context">Net spending · transfers excluded</div>'+body('tr-spending-donut',outgoing,'Spending by category, net of refunds. Transfers, card repayments and loan principal excluded.')+data.categories.filter(c=>c.amount_cents<0).map(c=>`<div class="tr-row"><span>${esc(title(c.category))} · net refund</span><strong>${precise(-c.amount_cents)}</strong>${btn('View','plan-evidence',`data-ids="${esc(JSON.stringify(c.ids))}"`)}</div>`).join(''))}</div>`;
  }
  function drawCategoryDonuts(){
    const colors=['#207b72','#417ea8','#bd8b49','#7962a5','#ca7056','#6b9563','#4f9caf','#ab7292','#8b9270','#b3a063','#617491','#8faaa0'];
    const draw=(id,rows,labels)=>chart(id,'doughnut',labels,[{data:rows.map(r=>r.amount_cents),backgroundColor:rows.map((_,i)=>colors[i%colors.length]),borderWidth:3,borderColor:'#fff',hoverOffset:5}],i=>showTransactions({ids:rows[i].ids}),{cutout:'72%',valueLegend:true});
    const incoming=incomingSlices(),outgoing=data.categories.filter(c=>c.amount_cents>0);
    draw('tr-income-donut',incoming,incoming.map(r=>r.name));draw('tr-spending-donut',outgoing,outgoing.map(r=>title(r.category)));
  }
  function incomeSources(){return incomeSourcesHtml({income:data.income_sources,panel,money,precise,esc,btn,empty});}
  function commitmentCards(){const c=data.commitments;const accountName=id=>data.accounts.find(a=>a.id===id)?.name||'Payment account needs review';return `<div class="tr-grid equal">${panel('Your subscriptions',`<div class="tr-labels"><div class="tr-number">${money(c.subscription_monthly_cents)}<small class="tr-subtle"> / month confirmed</small></div><span class="tr-pill">${c.subscriptions.length} listed</span></div><div class="tr-card-list">${c.subscriptions.map((s,i)=>`<div class="tr-row"><div><strong>${esc(s.name)}</strong><div class="tr-subtle">${s.status&&s.status!=='unknown'?esc(title(s.status))+' · '+esc(title(s.cadence)):s.confirmed?esc(title(s.cadence))+' · Renews '+date(s.next_due_on):'Needs review'+(s.last_paid_on?' · Last paid '+date(s.last_paid_on):'')}<br>${esc(accountName(s.account_id))}</div></div><div class="tr-money">${precise(s.amount_cents)}<br>${s.id?btn('Edit','edit',`data-id="${s.id}"`):btn('Review','subscription-review',`data-index="${i}"`)}</div></div>`).join('')||empty('Imported subscription charges and confirmed renewals appear here.')}</div><p class="tr-subtle">Unconfirmed items are excluded from the monthly total. Annual renewals are spread over 12 months for this comparison.</p>`,btn('Add subscription','subscription-add'))}${panel('Upcoming loan payments',`<div class="tr-card-list">${c.payments.map(p=>`<div class="tr-row"><div><strong>${esc(p.name)}</strong><div class="tr-subtle">${p.due_on?date(p.due_on):'Due date missing'} · ${p.confirmed?'Verified payment': 'Needs review'}${p.status==='past_due_review'?' · Check whether already paid':''}<br>${esc(accountName(p.payment_account_id))}</div></div><div class="tr-money">${precise(p.amount_cents)}<br>${p.id?btn('Details','edit',`data-id="${p.id}"`):btn('Add due date','payment-setup',`data-id="${p.account_id}"`)}</div></div>`).join('')||empty('Add your mortgage, auto loan, and cards to see upcoming payments.')}</div><p class="tr-subtle">A verified next payment can inform cash timing while APR and future payment terms remain incomplete.</p>`,btn('View debts','assets'))}</div>`;}
  function heatmap() {
    const map=new Map(data.daily_series.map(d=>[d.date,d.spending_cents]));
    const max=Math.max(1,...map.values()); const end=new Date(data.range.end+'T12:00:00');
    let html='<div class="tr-heatmap">';
    for(let i=34;i>=0;i--){const d=new Date(end);d.setDate(d.getDate()-i);const key=d.toISOString().slice(0,10),v=map.get(key)||0;html+=`<button data-action="date" data-date="${key}" data-level="${v>0?Math.max(1,Math.ceil(v/max*4)):1}" aria-label="${esc(key+': '+precise(v))}">${d.getDate()}<span>${v?money(v):'—'}</span></button>`;}
    return html+'</div><p class="tr-subtle">Last 35 days; amounts reflect the selected reporting period.</p>';
  }
  function drawOverview() {
    $('#tr-overview-explore')?.addEventListener('toggle',event=>{if(event.target.open!==overviewExpanded){overviewExpanded=event.target.open;render();$('#tr-overview-explore > summary')?.focus({preventScroll:true});}});
    $('#tr-decision-month')?.addEventListener('change',event=>{decisionMonth=event.target.value;render();$('#tr-decision-month')?.focus({preventScroll:true});});
    if(!overviewExpanded)return;
    if(forecastExpanded&&accountFilter===null)outlook.draw();
    $('#tr-forecast-details')?.addEventListener('toggle',event=>{if(event.target.open!==forecastExpanded){forecastExpanded=event.target.open;render();$('#tr-forecast-details > summary')?.focus({preventScroll:true});}});
    chart('tr-cashflow','bar',data.daily_series.map(r=>date(r.date)),[{label:'Income',data:data.daily_series.map(r=>r.income_cents),backgroundColor:'#4c8d72',borderRadius:3},{label:'Spending',data:data.daily_series.map(r=>r.spending_cents),backgroundColor:'#d9b896',borderRadius:3}],i=>showTransactions({date:data.daily_series[i].date}));
    drawCategoryDonuts();
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
    const body=rows.length?`<div class="tr-table-wrap"><table class="tr-table"><caption>${rows.length} transactions${filter.review?' needing review':''}${Object.keys(filter).length&&!filter.review?' · Filtered':''}. ${filter.credit_movement?'Full posted card amounts. Positive credits reduce the card balance; negative charges increase it.':'Positive amounts are money in.'}</caption><thead><tr><th>Date</th>${scope==='both'?'<th>Space</th>':''}<th>Merchant</th><th>Category</th><th>Classification</th><th>Amount</th><th>Review</th></tr></thead><tbody>${rows.map(t=>`<tr><td>${date(t.date)}</td>${scope==='both'?`<td><span class="tr-pill">${esc(t.space_label)}</span></td>`:''}<td>${esc(t.merchant)}<div class="tr-subtle">${esc(title(t.flow))}</div></td><td>${esc(title(t.category))}</td><td>${t.face_punched?'<span class="tr-pill">Face Punched</span> ':''}${t.bullshit_spending?'<span class="tr-pill">Bullshit Spending</span>':''}</td><td class="tr-money ${t.amount_cents>0?'tr-positive':''}">${evidenceMoney(t,t.amount_cents)}${t.tax_purpose?`<div class="tr-subtle">${!['tax_economic_cost','sales_tax_principal','owned_bank_cash'].includes(t.amount_basis)?'Full bank amount; components below<br>':''}${['tax_economic_cost','sales_tax_principal'].includes(t.amount_basis)?`Bank amount ${evidenceMoney(t,t.bank_amount_cents)}<br>Shown: ${t.economic_category?esc(title(t.economic_category)):t.economic_categories?.length?t.economic_categories.map(c=>esc(title(c))).join(', '):'known economic costs'}<br>`:''}${t.tax_purpose.valid&&t.tax_purpose.components?`Sales-tax principal ${evidenceMoney(t,t.tax_purpose.components.tax_principal_cents)}<br>Interest ${evidenceMoney(t,t.tax_purpose.components.interest_cents)} · Penalty ${evidenceMoney(t,t.tax_purpose.components.penalty_cents)} · Fees ${evidenceMoney(t,t.tax_purpose.components.other_fees_cents)}`:esc(t.tax_purpose.issue||'Tax purpose / economic split needs review')}</div>`:''}${t.amount_basis==='owned_bank_cash'?`<div class="tr-subtle">Full bank cash<br>Economic allocation ${evidenceMoney(t,t.allocated_amount_cents)}</div>`:''}${t.amount_basis==='owned_credit_activity'?`<div class="tr-subtle">Full card amount<br>Economic allocation ${evidenceMoney(t,t.allocated_amount_cents)}<br>${esc(t.currency||'Currency unavailable')}</div>`:''}</td><td>${t.editable?btn(t.reviewed?'Edit category':'Categorize','classify',`data-id="${t.id}" data-space-id="${t.space_id||active}"`):'Allocated'}</td></tr>`).join('')}</tbody></table></div>`:empty(filter.review?'Everything in this space is reviewed.':'No transactions match this view.');
    const receipts=scope==='both'?[]:data.records.filter(r=>r.kind==='receipt');
    return panel('Review transactions',`<p class="tr-subtle">Pick a transaction to set its category and money movement. You can flag one-time surprises as Face Punched, mark your own Bullshit Spending, split a card purchase, or teach Trajectory an exact merchant rule.</p>${reviewSuggestions()}${body}`,`<div class="tr-actions">${scope!=='both'?btn('Match transfers','reconciliation'):''}${btn('Needs review','review')}${btn('All selected-period transactions','clear')}${scope!=='both'?btn('Import statement','import'):''}</div>`)+(scope!=='both'?panel('Receipt research',receipts.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><p class="tr-subtle">${esc(r.data.summary)}</p><div class="tr-source">${esc(r.data.source)} · ${esc(r.data.purchased_on)} · ${r.data.transaction_id?'Matched statement #'+r.data.transaction_id:'Not matched to a posted purchase'}</div></div><div>${money(r.data.amount_cents)} ${btn('Review receipt','edit',`data-id="${r.id}"`)}</div></div>`).join('')||empty('Receipts explain existing purchases; they never create a second expense.'),btn('Add receipt evidence','add','data-kind="receipt"')):'');
  }
  function bills() {
    const scheduleRecords=data.records.filter(r=>r.kind==='recurring');
    const overdueIds=new Set(data.bills.filter(b=>b.status==='overdue').map(b=>b.record_id));
    const goals=data.goals.map(g=>`<div class="tr-goal"><div class="tr-labels"><strong>${esc(g.name)}</strong>${btn('Edit','edit',`data-id="${g.id}"`)}</div><div class="tr-progress"><i style="width:${Math.min(100,g.saved_cents/Math.max(1,g.target_cents)*100)}%"></i></div><div class="tr-labels tr-subtle"><span>${money(g.saved_cents)} of ${money(g.target_cents)}</span><span>${date(g.target_on)}</span></div><p class="tr-subtle">${g.on_track?'On track':'Increase contributions'} · ${money(g.required_monthly_cents)}/month needed</p></div>`).join('');
    return `<div class="tr-grid equal">${panel('Bills and recurring income',scheduleRecords.length?scheduleRecords.map(r=>`<div class="tr-row ${overdueIds.has(r.id)?'tr-row-overdue':''}"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${overdueIds.has(r.id)?'<span class="tr-overdue">Overdue</span> · ':''}${esc(title(r.data.cadence))} · ${date(r.data.next_due_on)} · ${r.data.confirmed?'Confirmed':'Needs confirmation'}</div></div><div class="tr-small-actions"><span>${money(r.data.amount_cents)}</span>${btn('Edit','edit',`data-id="${r.id}"`)}</div></div>`).join(''):empty('Add your mortgage, utilities, subscriptions, and payday.'),btn('Add bill','add','data-kind="recurring"'))}${panel('Savings goals',goals||empty('Give your future a target: emergency cash, a purchase, or paying down debt.'),btn('Add goal','add','data-kind="goal"'))}</div>${panel('Detected recurring patterns',data.recurring_suggestions.length?data.recurring_suggestions.map((r,i)=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.cadence))} · ${money(r.data.amount_cents)} · ${r.evidence_ids.length} supporting transactions</div></div>${btn('Review schedule','recurring-suggestion',`data-index="${i}"`)}</div>`).join(''):empty('Recurring suggestions appear after at least three reasonably spaced transactions.'))}`;
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
    return `${cardActivityHtml(data.card_activity,{esc,btn})}${lenderInterestYtdHtml(data.lender_interest_ytd,{esc,selectedYear:lenderInterestYear})}${debtPlanner.html()}<div class="tr-metrics">${metric('Net worth',s.net_worth_cents,s.net_worth_complete?'Assets minus debts':'Partial valuation')}${metric('Interest this period',s.selected_interest_cents,'Observed charges')}${metric('Recorded interest',s.recorded_interest_cents,'All available imported history')}${metric('Remaining interest',s.projected_interest_cents,'Estimated from reviewed debt terms')}</div>
      ${panel('Interest by account',`<div class="tr-table-wrap"><table class="tr-table"><caption>Recorded interest only; missing history is not zero interest.</caption><thead><tr><th>Account</th><th>Selected period</th><th>Year to date</th><th>All available history</th></tr></thead><tbody>${data.interest_by_account.map(a=>`<tr><td>${esc(a.name)} ${a.ids.length?btn('Charges','interest-evidence',`data-id="${a.account_id}"`):''}</td><td>${a.has_evidence?precise(a.selected_cents):'No evidence'}</td><td>${a.has_evidence?precise(a.ytd_cents):'No evidence'}</td><td>${a.has_evidence?precise(a.recorded_cents):'No evidence'}</td></tr>`).join('')}</tbody></table></div>`)}
      ${data.interest_statements.length?panel('Lender interest evidence',`<p class="tr-subtle">YTD recorded interest: ${money(s.ytd_interest_cents)}. Period totals replace overlapping imported charges. A period total cannot determine an individual month’s charges.</p>${data.interest_statements.map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-source">${esc(r.period_start)} – ${esc(r.period_end)} · ${esc(r.source)}</div></div><strong>${money(r.interest_cents)}</strong></div>`).join('')}`):''}
      <div class="tr-grid equal">${panel('Net-worth history',data.net_worth_history.length?plot('tr-networth','Observed net worth over time'):empty('Daily snapshots begin after your accounts are added. History is never fabricated.'))}${panel('Assets and liabilities',plot('tr-allocation','Assets versus liabilities'))}</div>
      ${data.debt_suggestions.length?panel('Bank debt details to review', data.debt_suggestions.map((d,i)=>`<div class="tr-row"><div><strong>${esc(d.name)}</strong><p class="tr-subtle">${esc(d.note)}</p></div>${btn('Review terms','debt-suggestion',`data-index="${i}"`)}</div>`).join('')):''}
      ${panel('Your debts',data.debts.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Debt</th><th>Balance</th><th>APR</th><th>Recorded interest</th><th>Projected interest</th><th>Payoff</th><th></th></tr></thead><tbody>${data.debts.map(d=>`<tr><td>${esc(d.name)}</td><td>${money(d.balance_cents)}</td><td>${((d.rate_millis??d.apr_bps*10)/1000).toFixed(3)}%</td><td>${money(d.recorded_interest_cents)}</td><td>${money(d.projection.interest_cents)}</td><td>${d.projection.payoff_on?date(d.projection.payoff_on)+' '+d.projection.payoff_on.slice(0,4):title(d.projection.status)}</td><td>${btn('Edit','edit',`data-id="${d.id}"`)}</td></tr>`).join('')}</tbody></table></div>${plot('tr-debts','Debt balance through payoff')}`:empty('Add reviewed loan or credit-card terms to project payoff and interest.'),btn('Add debt','add','data-kind="debt"'))}
      <div class="tr-grid equal">${panel('Gold and silver',`<p class="tr-subtle">Spot metal value · ${esc(title(data.quotes.status))}. Coin premiums are not inferred.</p>${data.metals.map(m=>`<div class="tr-row"><div><strong>${esc(m.name)}</strong><div class="tr-subtle">${esc(m.quantity)} × ${esc(m.weight)} ${esc(m.unit)} · ${(m.purity_bps/100).toFixed(2)}% pure<br>Cost ${money(m.cost_basis_cents)} · Gain ${money(m.gain_cents)}</div></div><div><strong>${money(m.value_cents)}</strong><div class="tr-small-actions">${btn('Edit','edit',`data-id="${m.id}"`)}${btn('Record sale','sell',`data-id="${m.id}"`)}</div></div></div>`).join('')||empty('Record physical gold and silver by acquisition lot.')}${data.metals.length?plot('tr-metals','Metals cost basis versus current value','small'):''}`,btn('Add metals','add','data-kind="metal"'))}${panel('Other assets',data.assets.map(a=>`<div class="tr-row"><div><strong>${esc(a.name)}</strong><div class="tr-subtle">${esc(title(a.asset_type))} · Valued ${date(a.observed_on)}</div></div><div>${money(a.value_cents)} ${btn('Edit','edit',`data-id="${a.id}"`)}</div></div>`).join('')||empty('Add a property, vehicle, or manually valued investment.'),btn('Add asset','add','data-kind="asset"'))}</div>`;
  }
  function drawAssets() {
    $('#tr-lender-ytd-year')?.addEventListener('change',e=>{lenderInterestYear=Number(e.target.value);render();});
    debtPlanner.draw();
    chart('tr-networth','line',data.net_worth_history.map(r=>date(r.date)),[line('Net worth',data.net_worth_history.map(r=>r.net_worth_cents),'#176b52',true)]);
    chart('tr-allocation','doughnut',['Assets','Liabilities'],[{data:[Math.max(0,data.summary.assets_cents),Math.max(0,data.summary.liabilities_cents)],backgroundColor:['#397c5e','#cc9c79'],borderWidth:4}]);
    if(data.debts.length){const longest=data.debts.reduce((a,d)=>d.projection.rows.length>a.length?d.projection.rows:a,[]);chart('tr-debts','line',longest.map(r=>date(r.date)+' '+r.date.slice(0,4)),data.debts.map((d,i)=>line(d.name,d.projection.rows.map(r=>r.balance_cents),['#176b52','#b48836','#6b869c'][i%3])));}
    chart('tr-metals','bar',data.metals.map(m=>m.name),[{label:'Cost basis',data:data.metals.map(m=>m.cost_basis_cents),backgroundColor:'#9baea0',borderRadius:4},{label:'Spot value',data:data.metals.map(m=>m.value_cents),backgroundColor:'#b69a52',borderRadius:4}]);
  }
  function business() {
    const b=data.business;
    if(!b)return empty('Choose an authorized business space to see company costs, payroll, and household-support targets. Your household invitation does not grant business access.');
    return `<div class="tr-notice">${b.ledger.income_cents===null?'No QuickBooks report matches this period. Connect or refresh the accounting Branch.':'QuickBooks is the accounting source for this period.'} Bank activity and sales-channel evidence are not added to ledger revenue.</div>
      <div class="tr-metrics">${metric('Company revenue',b.ledger.income_cents,'QuickBooks report')}${metric('Total accounting costs',b.ledger.total_costs_cents,'COGS + expenses + other expenses')}${metric('Reported net income',b.ledger.net_income_cents,'QuickBooks source net; separate from cash')}${metric('Payroll detail',(b.payroll.length?b.payroll.reduce((s,r)=>s+r.total_cents,0):null),b.payroll.length?'Imported pay periods ending in range':'No payroll detail imported')}${metric('Observed owner income',data.transactions.filter(t=>['owner_distribution','owner_wages'].includes(t.flow)).reduce((s,t)=>s+Math.abs(t.amount_cents),0),'Reviewed owner transactions')}</div>
      ${accountingComponentsHtml({report:b.ledger,esc,precise})}
      ${panel('How much must the company produce?',b.reliance.status==='estimated'?`<div class="tr-metrics">${b.reliance.targets.map(t=>metric(`${t.percent}% household support`,t.required_revenue_cents,`${money(t.household_cents)} for lifestyle + goals`)).join('')}</div>${plot('tr-reliance','Required revenue at 25, 50 and 100 percent household support')}`:empty('Review fixed costs, variable margins, reserves, and household needs to calculate reliable targets.'),btn('Review assumptions','add','data-kind="reliance"'))}
      <div class="tr-grid equal">${panel('Sales-channel evidence',b.channels.channels.length?plot('tr-channels','Operational sales by channel')+`<p class="tr-subtle">Source coverage only. Payouts and QuickBooks revenue are not additive.</p>`:empty('Sales appear here from your existing Everbranch integrations.'))}${panel('Company cost review',b.insights.map(i=>`<div class="tr-row"><div><strong>${esc(i.title)}</strong><p class="tr-subtle">${esc(i.explanation)}</p></div><strong>${money(i.cost_cents)}</strong></div>`).join('')||empty('Import employee-level payroll evidence to review overtime and labor costs.'),btn('Import payroll','payroll-import'))}</div>
      ${panel('Employee cost detail',b.payroll.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Employee</th><th>Period ending</th><th>Wages</th><th>Overtime</th><th>Taxes & benefits</th><th>Total</th></tr></thead><tbody>${b.payroll.map(p=>`<tr><td>${esc(p.employee)}</td><td>${date(p.period_end)}</td><td>${money(p.wages_cents)}</td><td>${money(p.overtime_cents)}</td><td>${money(p.employer_taxes_cents+p.benefits_cents)}</td><td>${money(p.total_cents)}</td></tr>`).join('')}</tbody></table></div>`:empty('No employee costs available.'),btn('Add payroll detail','add','data-kind="payroll"'))}
      ${panel('Material cost evidence · all available jobs',b.materials.length?`<div class="tr-table-wrap"><table class="tr-table"><thead><tr><th>Material</th><th>Quantity</th><th>Consumed</th><th>Unit cost</th><th>Consumed cost</th></tr></thead><tbody>${b.materials.map(m=>`<tr><td>${esc(m.name)}</td><td>${esc(m.quantity)} ${esc(m.unit)}</td><td>${esc(m.used_quantity)}</td><td>${money(m.unit_cost_cents)}</td><td>${money(m.consumed_cost_cents)}</td></tr>`).join('')}</tbody></table></div>`:empty('Material quantities and costs appear from your Everbranch jobs. Missing costs remain unavailable.'))}`;
  }
  function drawBusiness(){const b=data.business;if(!b)return;chart('tr-reliance','bar',b.reliance.targets.map(t=>`${t.percent}%`),[{label:'Required monthly revenue',data:b.reliance.targets.map(t=>t.required_revenue_cents),backgroundColor:['#bfd6c8','#6a9e80','#206248'],borderRadius:6}]);chart('tr-channels','bar',b.channels.channels.map(c=>c.label),[{label:'Operational sales',data:b.channels.channels.map(c=>c.revenue_cents),backgroundColor:'#518669',borderRadius:4}]);}
  function accounts() {
    const s=data.summary, pending=Object.values(data.settings.pending_bank_connections||{});
    const groups=[['cash','Cash','#5b9caa'],['credit','Credit cards & credit lines','#d0a364'],['loan','Loans','#b8898e'],['investment','Investments','#799774']];
    const activeFeeds=data.connections.filter(c=>c.status!=='disconnected');
    const inactiveFeeds=data.connections.filter(c=>c.status==='disconnected');
    const connectionRows=activeFeeds.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution_name||'Connected bank feed')}</strong><div class="tr-subtle">${c.status==='mapping_required'?'Choose account owners':'Bank feed'} · ${esc(title(c.status))}${c.synced_at?' · Last synced '+date(c.synced_at):' · Waiting for its first sync'}</div></div><div class="tr-small-actions">${c.managed_here?btn('Account owners','bank-map',`data-id="${c.id}"`)+btn('Sync','sync',`data-id="${c.id}"`)+btn('Reconnect','reconnect',`data-id="${c.id}"`)+btn('Disconnect','disconnect',`data-id="${c.id}"`):'<span class="tr-pill">Managed by connection owner</span>'}</div></div>`).join('');
    const inactiveRows=inactiveFeeds.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution_name||'Former bank feed')}</strong><div class="tr-subtle">Not connected · Reconnect through Plaid to resume syncing.</div></div>${btn('Reconnect','reconnect',`data-id="${c.id}"`)}</div>`).join('');
    const accountRows=groups.map(([kind,label,color])=>{const rows=data.accounts.filter(a=>a.kind===kind);return rows.length?`<h3>${label} <span class="tr-subtle">${money(rows.reduce((v,a)=>v+(a.current_balance_excluded?0:(a.balance_cents??0)),0))}</span></h3>${rows.map(a=>`<div class="tr-row"><div class="tr-account-name"><span class="tr-account-icon" style="color:${color}">${kind==='cash'?'$':kind==='credit'?'▤':'◈'}</span><div><strong>${esc(a.name)}</strong><div class="tr-source">${a.current_balance_excluded?'Historical snapshot · Excluded from current totals · ':a.connection_id?'Connected feed · ':'Manual / imported · '}${esc(a.currency||'USD')} · Observed ${date(a.observed_at)} · History ${a.history_start?a.history_start.slice(0,10):'not imported'}</div></div></div><div><strong>${precise(a.balance_cents)}</strong> ${btn('Activity','account-activity',`data-id="${a.id}"`)} ${!a.connection_id?btn('Update balance','account-balance',`data-id="${a.id}"`):''} ${data.space.kind==='household'&&a.kind==='cash'&&['manual','historical_import'].includes(a.source_type)?btn(a.current_balance_excluded?'Change balance source':'Choose current source','account-source',`data-id="${a.id}"`):''} ${a.current_balance_excluded?'<span class="tr-pill">History retained</span>':btn('Assign owner','plan-assign',`data-id="${a.id}"`)}</div></div>`).join('')}`:'';}).join('')||empty('Add your accounts to see your whole financial picture.');
    const settings=`<div class="tr-grid equal">${panel('Your spending rules',`<p class="tr-subtle">You decide what counts as Bullshit Spending. Face Punched stays a separate one-time classification.</p><div class="tr-actions tr-block">${btn('Edit category defaults','profile')}</div>${data.records.filter(r=>r.kind==='rule').map(r=>`<div class="tr-row"><div><strong>${esc(r.name)}</strong><div class="tr-subtle">${esc(title(r.data.classification.category))} · Exact merchant rule</div></div>${btn('Remove','archive',`data-id="${r.id}"`)}</div>`).join('')}`)}${panel('Sharing and reminders',`<p class="tr-subtle">Personal and business permissions stay separate. Partner invitations share household finances only.</p><div class="tr-actions tr-block">${btn('Invite partner','invite')}${btn('Link business','link-spaces')}${btn('Company → household','combined')}${btn('Set up texting','sms',data.providers.sms?'':'disabled')}${btn('Email reminders','review-email')}</div><p class="tr-subtle">Review emails: ${esc(title(data.review_email?.frequency||'off'))} · ${esc(data.review_email?.email||'')}. ${data.review_email?.frequency!=='off'?'At 9 AM '+esc(data.review_email?.timezone||data.space.timezone)+(data.review_email?.frequency==='weekly'?' on Mondays':'')+', when transactions need review.':''}</p>${data.review_email?.delivery_ready===false?'<p class="tr-subtle">Email delivery needs provider setup.</p>':''}${!data.providers.sms?'<p class="tr-subtle">Text delivery is not enabled for this pilot yet.</p>':''}`)}</div>`;
    return `${panel('Connections',`<p class="tr-subtle">See what is connected now. Connect opens Plaid immediately. A shared login can contain multiple companies. Choose an owner for each account before its first import; new unassigned accounts stay out of your books.</p><div class="tr-actions tr-block">${btn('Connect a bank','connect',data.providers.plaid?'':'disabled')}${btn('Add manual account','account')}${btn('Import statement','import')}${btn('Import Monarch history','monarch-import')}</div>${!data.providers.plaid?`<div class="tr-notice">${data.providers.plaid_sync?'New bank sign-ins are not enabled for this space. Existing feeds can still sync; statement imports remain available.':'Live bank connections need provider setup. Manual accounts and statement imports remain available.'}</div>`:''}${connectionRows||'<div class="tr-data-note">No live bank feed is connected in this space yet. If you previously connected USAA, use Refresh; if it still is not listed, choose Connect a bank to finish or reconnect it through Plaid.</div>'}${inactiveRows}${pending.map(c=>`<div class="tr-row"><div><strong>${esc(c.institution||'Requested bank')}</strong><div class="tr-subtle">${esc(title(c.status||'setup required'))}<br>${esc(c.note||'')}</div></div><span class="tr-pill">Manual evidence</span></div>`).join('')}`)}${panel('Your net worth',`<div class="tr-history-intro"><div><div class="tr-number">${money(s.net_worth_cents)}</div><p class="tr-subtle">${s.net_worth_complete?'Based on recorded assets and liabilities':'Partial picture · missing or unverified coverage'}</p></div>${btn('Manage assets & debt','assets')}</div>${data.cash_history.length?'<h3>Cash balance history</h3>'+plot('tr-cash-history','Imported cash balance history','large')+'<p class="tr-subtle">Cash accounts in the balance export only. Select a date to inspect contributing balances.</p>':data.net_worth_history.length?plot('tr-account-history','Observed net worth history','large'):empty('Balance history begins with observations. Importing transactions does not invent historical account balances.')}`)}<div class="tr-grid">${panel('Your accounts',accountRows,btn('Add account','account'))}${panel('Balance sheet',`<h3>Assets <span class="tr-positive">${money(s.assets_cents)}</span></h3><div class="tr-allocation"><i style="width:${s.assets_cents?Math.max(0,s.cash_cents/s.assets_cents*100):0}%;background:#5b9caa"></i><i style="flex:1;background:#799774"></i></div><div class="tr-row">Liquid cash<strong>${money(s.cash_cents)}</strong></div><div class="tr-row">Other recorded assets<strong>${money(s.assets_cents-s.cash_cents)}</strong></div><h3>Liabilities ${money(s.liabilities_cents)}</h3><p class="tr-subtle">Revolving credit, loans, and unpaid medical bills. Missing property, investments, or debts make this a partial picture.</p>`)} </div>${settings}`;
  }
  function drawAccounts(){const cash=data.cash_history;chart('tr-cash-history','line',cash.map(r=>r.date),[line('Imported cash balances',cash.map(r=>r.cash_cents),'#4398ab',true)],i=>dialog('Cash balances · '+cash[i].date,`<p class="tr-subtle">${esc(cash[i].source)}</p>${Object.entries(cash[i].accounts).map(([name,value])=>`<div class="tr-row">${esc(name)}<strong>${precise(value)}</strong></div>`).join('')}`,null));const rows=data.net_worth_history;chart('tr-account-history','line',rows.map(r=>date(r.date)),[line('Recorded net worth',rows.map(r=>r.net_worth_cents??null),'#4398ab',true)]);}
  function history() {
    const months=data.history.months;
    const selectedMonth=historyMonth||months.at(-1)?.month;
    const current=months.find(m=>m.month===selectedMonth);
    const previous=current?months.find(m=>m.month===`${Number(current.month.slice(0,4))-1}${current.month.slice(4)}`):null;
    const nextMonth=new Date();nextMonth.setMonth(nextMonth.getMonth()+1);const lastYear=`${nextMonth.getFullYear()-1}-${String(nextMonth.getMonth()+1).padStart(2,'0')}`;
    const prior=months.find(m=>m.month===lastYear);
    return `<div class="tr-data-note">${esc(data.history.note)} Imported range: ${esc(data.history.first_on||'none')} – ${esc(data.history.last_on||'none')}.</div>${panel('The story behind your spending',plot('tr-history','Monthly income and spending history','large')+`<p class="tr-subtle">Select any month to open its transactions.</p>`)}<div class="tr-grid equal">${panel('Same month, different year',`<div class="tr-actions"><label for="tr-history-month">Compare month</label><select id="tr-history-month">${months.map(m=>`<option value="${m.month}" ${m.month===selectedMonth?'selected':''}>${m.month}</option>`).join('')}</select></div>${current?`<div class="tr-metrics tr-block">${metric('Selected month',current.spending_cents,current.month)}${metric('Same month last year',previous?.spending_cents,previous?.month||'No imported evidence')}</div>${plot('tr-year-compare','Same-month category comparison')}<p class="tr-subtle">${current.closed_month?'Closed calendar month':'Month in progress'} · ${current.covered_accounts}/${current.spending_accounts} spending accounts have history reaching this month. ${current.review_count} records need review.</p>${btn('Inspect this month','history-detail',`data-month="${current.month}"`)}`:empty('Import history to compare months.')}`)}${panel('What might next month look like?',`<p class="tr-subtle">${prior?'Last year’s corresponding month was '+prior.month+'. This is evidence to compare, not a guaranteed future bill.':'The matching month last year is not in your imported history.'}</p>${metric('Prior matching month',prior?.spending_cents,prior?.month||'Unavailable')}<p class="tr-subtle">Explore recent spending versus seasonal history in your trajectory. Confirm bills and debt terms for accurate timing.</p>${btn('Explore seasonal trajectory','seasonal-forecast')}${prior?btn('See supporting purchases','history-detail',`data-month="${prior.month}"`):''}`)}</div>${panel('Month by month',`<div class="tr-table-wrap"><table class="tr-table"><caption>Income, net spending, and coverage by calendar month</caption><thead><tr><th>Month</th><th>Income</th><th>Spending</th><th>Difference</th><th>Review</th></tr></thead><tbody>${[...months].reverse().map(m=>`<tr><td>${btn(m.month,'history-detail',`data-month="${m.month}"`)}${!m.closed_month?'<span class="tr-pill">In progress</span>':''}</td><td>${precise(m.income_cents)}</td><td>${precise(m.spending_cents)}</td><td>${precise(recordedDifference(m.income_cents,m.spending_cents))}</td><td>${m.review_count} of ${m.transaction_count}</td></tr>`).join('')}</tbody></table></div>`)}`;
  }
  function drawHistory(){const months=data.history.months;chart('tr-history','bar',months.map(m=>m.month),[{label:'Income',data:months.map(m=>m.income_cents),backgroundColor:'#5a9b89',borderRadius:4},{label:'Spending',data:months.map(m=>m.spending_cents),backgroundColor:'#d3b38b',borderRadius:4}],i=>showTransactions({ids:months[i].ids}));const current=months.find(m=>m.month===(historyMonth||months.at(-1)?.month));const previous=months.find(m=>m.month===`${Number(current?.month.slice(0,4))-1}${current?.month.slice(4)}`);const cats=[...new Set([...(current?.categories||[]),...(previous?.categories||[])].map(c=>c.category))];chart('tr-year-compare','bar',cats.map(title),[{label:current?.month||'Current',data:cats.map(c=>current?.categories.find(x=>x.category===c)?.amount_cents??0),backgroundColor:'#4398ab'},{label:previous?.month||'No prior history',data:cats.map(c=>previous?.categories.find(x=>x.category===c)?.amount_cents??null),backgroundColor:'#c7d6cc'}],i=>showTransactions({ids:[...(current?.categories.find(c=>c.category===cats[i])?.ids||[]),...(previous?.categories.find(c=>c.category===cats[i])?.ids||[])]}));$('#tr-history-month')?.addEventListener('change',e=>{historyMonth=e.target.value;render();});}
  function budgetPlans(source=budgetSource){return monthlyBudgetRecords(data.records,data.question_summary,budgetMonth||data.question_summary?.plan_month,source);}
  function budget(){
    if(accountFilter!==null)return wholeSpacePlanNotice();
    const month=budgetMonth||data.question_summary?.plan_month;
    const plans=budgetPlans();
    const months=(data.question_summary?.monthly?.rows||[]).filter(row=>row.month>=data.question_summary.plan_month);
    const sources=[...new Set(budgetPlans(null).map(record=>record.data.source||''))];
    const sum=purpose=>{const rows=plans.filter(record=>record.data.purpose===purpose);return rows.length?rows.reduce((total,record)=>total+record.data.monthly_cents,0):null;};
    return `<div class="tr-actions tr-block"><label for="tr-budget-month">Budget month</label><select id="tr-budget-month">${months.map(row=>`<option value="${esc(row.month)}" ${row.month===month?'selected':''}>${esc(row.label)}</option>`).join('')}</select><label for="tr-budget-source">Source</label><select id="tr-budget-source"><option value="" ${budgetSource===null?'selected':''}>All sources for this month</option>${sources.map(source=>`<option value="${esc(source)}" ${source===budgetSource?'selected':''}>${esc(source||'Entered in Trajectory')}</option>`).join('')}</select></div>
      <div class="tr-data-note">Latest effective targets for ${esc(month)}. These are plans; review amounts and coverage before relying on them. Additional owner distributions, debt reserves and savings stay separate from operating spending.</div>
      ${panel('Give every category a direction',plans.length?`<div class="tr-metrics">${metric('Planned monthly income',sum('income'),'Budget target, not received earnings')}${metric('Monthly spending targets',sum('spending'),'Includes drafts requiring review')}${metric('Savings contributions',sum('savings'),'Transfers, not spending')}${metric('Debt cash reserve',sum('debt_payment'),'Allowance, not additional to scheduled debt cash')}${data.space.kind==='business'?metric('Additional owner distributions',sum('owner_support'),'Separate from employee payroll; affordability unverified'):''}</div>`:empty('No targets selected for this month. Add or import your plan.'),`<div class="tr-actions">${btn('Import budget CSV','budget-import')}${btn('Add budget target','add','data-kind="budget"')}</div>`)}
      ${panel('Spending against your plan',plot('tr-budget-chart','Monthly spending target and selected-period actuals')+`<p class="tr-subtle">Targets refer to ${esc(month)}; actuals follow the report dates at the top. Change the report dates for a matching comparison.</p>`)}
      ${panel('Your targets',plans.map(record=>`<div class="tr-row"><div><strong>${esc(record.name)}</strong><div class="tr-source">${esc(title(record.data.purpose))} · ${esc(record.data.source||'Entered in Trajectory')} · ${esc(record.data.effective_on)}${record.data.ends_on?' through '+esc(record.data.ends_on):''} · ${record.data.reviewed?'Reviewed target':'Needs review'}</div></div><div>${precise(record.data.monthly_cents)} / month ${btn('Edit','edit',`data-id="${record.id}"`)}</div></div>`).join('')||empty('Your saved targets appear here.'))}`;
  }
  function drawBudget(){$('#tr-budget-month')?.addEventListener('change',e=>{budgetMonth=e.target.value;budgetSource=null;render();});$('#tr-budget-source')?.addEventListener('change',e=>{budgetSource=e.target.value||null;render();});const plans=budgetPlans().filter(r=>r.data.purpose==='spending');const cats=[...new Set([...plans.map(r=>r.data.category),...data.categories.map(r=>r.category)])];chart('tr-budget-chart','bar',cats.map(title),[{label:'Monthly target',data:cats.map(c=>plans.filter(r=>r.data.category===c).reduce((s,r)=>s+r.data.monthly_cents,0)),backgroundColor:'#c1d4c4'},{label:'Selected-period actual',data:cats.map(c=>data.economic_coverage?.complete===false?null:(data.categories.find(r=>r.category===c)?.amount_cents??0)),backgroundColor:'#4398ab'}],i=>showTransactions({category:cats[i]}));}

  function field(name,label,type='text',value='',choices=null,full=false) {
    const attrs=`name="${esc(name)}" id="field-${esc(name)}"`;
    const input=choices?`<select ${attrs}>${choices.map(c=>`<option value="${esc(c.value??c)}" ${String(value)===String(c.value??c)?'selected':''}>${esc(c.label??title(String(c)))}</option>`).join('')}</select>`:type==='checkbox'?`<input ${attrs} type="checkbox" ${value?'checked':''}>`:`<input ${attrs} type="${type}" value="${esc(value)}" ${type==='number'?'step="any"':''}>`;
    return `<label class="tr-field ${full?'full':''}" for="field-${esc(name)}">${esc(label)}${input}</label>`;
  }
  function dialog(heading,html,action,save='Save') {
    $('#tr-dialog-title').textContent=heading;$('#tr-fields').innerHTML=html;$('#tr-form-error').hidden=true;$('#tr-save').textContent=save;$('#tr-save').hidden=!action;formAction=action;$('#tr-dialog').showModal();
  }
  function openFlowCategory(target,record=true){
    const merchants=JSON.parse(target.dataset.merchants||'[]'),cash=target.dataset.cash==='true',receipts=target.dataset.inflows==='true';
    if(record)remember();
    flowDialog={label:target.dataset.label||'',ids:target.dataset.ids||''};
    dialog(target.dataset.label||'Category records',`<p class="tr-subtle">${receipts?'Senders for this household receipt type.':'Merchants in this recorded category. Shared allocations keep the other space’s source private.'}</p><div class="tr-actions">${btn(receipts?'View all receipts':'View all category records','money-flow-evidence',`data-cash="${cash}" data-economic-category="${esc(target.dataset.economicCategory||'')}" data-economic-categories="${esc(target.dataset.economicCategories||'[]')}" data-ids="${esc(target.dataset.ids)}"`)}</div>${merchants.map(row=>`<div class="tr-row"><div><strong>${esc(row.label)}</strong><div>${precise(row.amount_cents)}</div></div>${btn('View transactions','money-flow-evidence',`data-cash="${cash}" data-economic-category="${esc(row.economic_category||'')}" data-economic-categories="${esc(JSON.stringify(row.economic_categories||[]))}" data-ids="${esc(JSON.stringify(row.ids))}"`)}</div>`).join('')}`,null);
    historyNavigation.sync();
  }
  function recordForm(kind,record=null,preset={}) {
    if(['business_funding_plan','financial_observation','budget'].includes(kind)&&accountFilter!==null){notice('Choose all accounts before editing whole-space budgets, source totals or funding assumptions.');return;}
    const taxRow=kind==='tax_remittance'?data.sales_tax?.rows?.find(row=>row.transaction_id===(record?.data?.transaction_id??preset.transaction_id)):null;
    if(kind==='tax_remittance'&&!taxRow){dialog('Choose tax bank evidence',`<p class="tr-subtle">Choose a posted bank movement from the selected report dates and account.</p><div class="tr-choices tr-block">${(data.sales_tax?.rows||[]).map(row=>btn(`${row.date} · ${row.account} · ${row.merchant} · ${precise(row.amount_cents)}`,'tax-annotate',`data-id="${row.transaction_id}"`)).join('')||'<p>No tax bank movements identified in this scope.</p>'}</div>`,null);return;}
    const d=kind==='tax_remittance'?taxRemittanceReview(record,taxRow):kind==='business_funding_plan'?{...(record?.data||preset),complete:false,reviewed_on:data.decision_support?.as_of||''}:kind==='lender_interest_ytd'?{...(record?.data||preset),reviewed:false}:record?.data||preset;
    if(!d){notice('Refresh this view before reviewing current bank evidence.');return;}
    const defaults={ministry:'Samaritan Ministries',provider:'Prisma Health',reserve_shares:true,confirmed:false,reviewed:false,priority:1,purity_bps:9999,quantity:1,weight:1,distribution_retention_bps:10000};
    let html=field('name','Name','text',record?.name||preset.name||'',null,true);
    for(const [key,def] of Object.entries(data.definitions[kind])) {
      let value=d[key]??defaults[key]??(['money','percent'].includes(def.type)&&def.required&&!['business_funding_plan','lender_interest_ytd'].includes(kind)?0:'');
      if(['money','signed_money','percent'].includes(def.type)&&value!=='')value=value/100;
      if(def.type==='rate'&&value!=='')value=value/1000;
      const choices=def.type.endsWith('_record')?[{value:'',label:'Choose a record'},...data.records.filter(r=>r.kind===def.type.slice(0,-7)).map(r=>({value:r.id,label:r.name}))]:def.type==='account'?[{value:'',label:'Select account'},...data.accounts.map(a=>({value:a.id,label:a.name}))]:def.type==='business_space'?supportBusinessChoices(data.support_business_options):def.type==='category'?(def.required?data.category_options:[{value:'',label:'All categories'},...data.category_options]):def.choices;
      html+=field(key,def.label,def.type==='boolean'?'checkbox':['date','month'].includes(def.type)?def.type:['money','signed_money','percent','rate','decimal','integer'].includes(def.type)?'number':'text',value,choices);
    }
    if(kind==='tax_remittance')html=`<p class="tr-data-note">Review this current bank evidence: ${esc(taxRow.date)} · ${esc(taxRow.account)} · ${esc(taxRow.merchant)} · ${precise(taxRow.amount_cents)}. Confirm the tax type and bank match again before marking it reviewed.</p>`+html;
    if(kind==='business_funding_plan')html='<p class="tr-data-note">Review remaining receipts and unpaid obligations using current balances. Confirm the complete plan again after balances or debt terms change. A capacity estimate does not schedule or guarantee a distribution.</p>'+html;
    if(kind.startsWith('medical_'))html=`<p class="tr-subtle tr-block">Track financial references only. Submit bills through your ministry separately. Expected shares are never guaranteed income. Matching a statement row prevents duplicate spending; a manual record does not change bank cash.</p>`+html+(record?`<div class="tr-actions tr-block">${btn('Remove record','medical-remove',`data-id="${record.id}"`)}</div>`:'');
    dialog(`${record?'Edit':'Add'} ${title(kind)}`,html,async values=>{
      const payload={};
      for(const [key,def] of Object.entries(data.definitions[kind])) {
        let v=values.get(key);
        if(def.type==='boolean')v=v==='on';
        else if(v===''&&!def.required)continue;
        else if(['money','signed_money','percent'].includes(def.type))v=minor(v);
        else if(def.type==='rate')v=rateMillis(v);
        else if((['integer','account','business_space'].includes(def.type)||def.type.endsWith('_record')))v=Number(v);
        payload[key]=v;
      }
      if(kind==='tax_remittance')payload.expected_bank_source_fingerprint=d.expected_bank_source_fingerprint;
      await api(endpoint(record?`/records/${record.id}`:'/records'),record?'PATCH':'POST',{kind,name:values.get('name'),data:payload,version:record?.version});
    });
  }
  async function definitionForm(){
    if(scope==='both'){notice('Choose one household or organization before organizing categories.');return;}
    const spaceId=active,catalog=await api(endpoint('/attribution/definitions',spaceId));
    if(active!==spaceId)return;
    const definitions=visualFinance.getReport()?.category_definitions||[];
    const parents=definitions.filter(d=>!d.parent_key);
    dialog('Organize categories and programs',`<p class="tr-subtle">Categories describe what money was for. Programs keep activities within one organization separate without duplicating its bank account.</p>${field('kind','What are you adding?','text','category_definition',[{value:'category_definition',label:'Category or subcategory'},{value:'program',label:'Program'}])}${field('name','Name','text','',null,true)}${field('direction','Category type','text','expense',[{value:'expense',label:'Spending'},{value:'income',label:'Incoming source'}])}${field('parent','Parent category (optional)','text','',[{value:'',label:'Top-level category'},...parents.map(d=>({value:d.key,label:d.label}))])}`,async values=>{
      if(active!==spaceId)throw new Error('Your financial workspace changed. Reopen this form.');
      const kind=values.get('kind'),name=values.get('name').trim(),key=name.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,40);
      if(!/^[a-z][a-z0-9_]{0,39}$/.test(key))throw new Error('Choose a name beginning with a letter.');
      const payload={kind,name,key};
      if(kind==='category_definition'){
        payload.direction=values.get('direction');
        const parentKey=values.get('parent');
        if(parentKey){
          let parent=catalog.category_definitions.find(d=>d.key===parentKey&&d.active&&!d.parent_record_id);
          if(!parent){const choice=parents.find(d=>d.key===parentKey);const created=await api(endpoint('/attribution/definitions',spaceId),'POST',{kind:'category_definition',name:choice?.label||title(parentKey),key:parentKey,direction:payload.direction});parent={id:created.record.id};}
          payload.parent_record_id=parent.id;
        }
      }
      await api(endpoint('/attribution/definitions',spaceId),'POST',payload);notice(kind==='program'?'Program added.':'Category added.');
    });
  }
  function chooseAdd() {
    if(scope==='both'){notice('Choose Personal or Business before adding to your plan.');return;}
    const kinds=['recurring','goal','debt',...(data.definitions.debt_balance_observation?['debt_balance_observation']:[]),'asset','metal','scenario',...(data?.business?['payroll','reliance']:['financial_observation','medical_need','medical_bill','medical_payment','medical_share','medical_membership']),...(data.space.kind==='household'&&data.definitions.company_support_source?['company_support_source']:[])];
    dialog('Add to your plan',`<div class="tr-choices tr-block">${kinds.map(k=>btn(title(k),'choose-kind',`data-kind="${k}"`)).join('')}</div>`,null);
  }
  function transactionForm(tx) {
    assistantFocusedTransaction=tx;
    const source=scope==='both'?data.by_space[tx.space_id]:data;
    const verifiedTax=source.sales_tax?.rows?.find(row=>row.transaction_id===tx.id&&row.reviewed&&row.tax_type==='sales_tax');
    if(verifiedTax)tx={...tx,category:'taxes',flow:'sales_tax_remittance',face_punched:false,bullshit_spending:false};
    const matched=source.records.find(r=>['medical_payment','medical_share','medical_membership'].includes(r.kind)&&r.data.transaction_id===tx.id);
    if(matched){recordForm(matched.kind,matched);return;}
    dialog('Categorize transaction',`<p class="tr-subtle">${scope==='both'?esc(tx.space_label)+' · ':''}${esc(tx.merchant)} · ${evidenceMoney(tx,tx.amount_cents)}<br>${esc(tx.explanation)}</p>${tx.flow==='sales_tax_remittance'?'<p class="tr-data-note">This receipt-backed purpose keeps the full bank cash movement and separates principal from known costs. It cannot be learned as an agency merchant rule.</p>':''}${field('category','Category','text',tx.category,source.category_options.map(c=>({value:c,label:source.workspace_settings?.income_categories?.[c]||title(c)})))}${field('flow','Money movement','text',tx.flow,['expense','income','refund','transfer','card_payment','asset_transfer','asset_sale','loan_draw','unclassified_deposit','owner_wages','owner_distribution','reimbursement','debt_payment','duplicate','sales_tax_remittance'])}${field('face_punched','Face Punched · unexpected, one time','checkbox',tx.face_punched)}${field('bullshit_spending','Bullshit Spending · unnecessary to me','checkbox',tx.bullshit_spending)}${field('learn','Remember an exact merchant rule','checkbox',false)}<div class="tr-actions tr-block">${btn('Allocate across spaces','split',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`)}${btn('Undo last review','undo',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`)}${source.space.kind==='household'&&tx.amount_cents<0?btn('Match monthly sharing contribution','membership-match',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`):''}${source.space.kind==='household'?btn('Match medical payment / share','medical-match',`data-id="${tx.id}" data-space-id="${tx.space_id||active}"`):''}</div>`,async v=>{await api(endpoint(`/transactions/${tx.id}`,tx.space_id||active),'PATCH',{version:tx.version,category:v.get('category'),flow:v.get('flow'),face_punched:v.get('face_punched')==='on',bullshit_spending:v.get('bullshit_spending')==='on',learn:v.get('learn')==='on'});});
  }
  function budgetImportForm() {
    if(scope==='both'||accountFilter!==null){notice('Choose one financial space and all accounts before importing budget targets.');return;}
    const spaceId=active;
    const ensureSpace=()=>{if(active!==spaceId||scope==='both'||accountFilter!==null)throw new Error('Your financial view changed. Open the budget import again in its original space.');};
    const headers='name,category,monthly_cents,purpose,effective_on,ends_on,source,reviewed,source_id';
    const upload=async(file,format,mapping=null)=>{
      ensureSpace();
      const body=new FormData();body.set('kind','budget');body.set('file',file);body.set('budget_format',format);
      if(mapping){body.set('column_mapping',JSON.stringify(mapping.column_mapping));body.set('budget_defaults',JSON.stringify(mapping.budget_defaults));}
      const preview=await api(endpoint('/imports/preview',spaceId),'POST',body);ensureSpace();return preview;
    };
    const finalPreview=(preview,categoryMap=Object.create(null))=>{
      ensureSpace();
      if(!preview.token||!preview.preview?.length)throw new Error('This budget preview is not ready to confirm. Preview the file again.');
      dialog('Review every planned target',budgetPreviewHtml({preview,categoryMap,esc,precise}),async()=>{
        ensureSpace();
        const result=await api(endpoint('/imports/confirm',spaceId),'POST',{token:preview.token,category_map:categoryMap});ensureSpace();
        budgetMonth=preview.preview[0]?.effective_on?.slice(0,7)||'';budgetSource=null;tab='budget';
        notice(`${result.created} budget targets imported; ${result.skipped} unchanged existing targets skipped. Review any unreviewed targets before using the monthly plan.`);
      },`Import ${preview.count} planned targets`);
    };
    const showPreview=(file,format,preview)=>{
      ensureSpace();$('#tr-dialog').close();
      if(preview.missing_fields?.length||!preview.preview?.length){
        if(!preview.columns?.length)throw new Error('No CSV columns were available. Check the file and preview it again.');
        dialog('Map your planned budget columns',budgetMappingHtml({preview,esc,field,spaceKind:data.space.kind}),async values=>{
          const mapping=budgetMappingPayload(values,preview.columns,data.space.kind);
          const mapped=await upload(file,format,mapping);showPreview(file,format,mapped);return false;
        },'Preview mapped targets');return;
      }
      if(preview.unmapped_categories?.length){
        const choices=[{value:'',label:'Choose a Trajectory category'},...(preview.category_options||[]).map(category=>({value:category,label:title(category)}))];
        dialog('Map budget categories',`<p class="tr-data-note">Choose the meaning of each source category. No category is guessed. Your choice applies to every row carrying that source label; amounts, purposes and dates remain as previewed.</p>${preview.unmapped_categories.map((category,index)=>field(`budget_category_${index}`,category,'text','',choices)).join('')}`,values=>{
          ensureSpace();const categoryMap=Object.create(null);
          preview.unmapped_categories.forEach((category,index)=>{const selected=values.get(`budget_category_${index}`);if(!preview.category_options.includes(selected))throw new Error('Choose a Trajectory category for every source label.');categoryMap[category]=selected;});
          $('#tr-dialog').close();finalPreview(preview,categoryMap);return false;
        },'Review mapped targets');return;
      }
      finalPreview(preview);
    };
    dialog('Import your budget',`<p class="tr-subtle">Import planned monthly targets from a CSV, up to 5,000 rows. EveryDollar and other exports are mapped explicitly before any target is saved. Use one financial space at a time.</p>${field('budget_format','CSV format','text','template',[{value:'template',label:'Trajectory budget template'},{value:'everydollar',label:'EveryDollar CSV · map columns'},{value:'mapped',label:'Other budget CSV · map columns'}])}<p class="tr-subtle">Template amounts are integer cents; purpose is spending, savings, debt_payment or income${data.space.kind==='business'?', or owner_support for additional owner distributions':''}; effective dates use YYYY-MM-DD. Optional ends_on prevents a holiday or one-time allowance from carrying forward. Source, reviewed and source_id are optional. A stable source_id is recommended for replays.</p><div class="tr-code tr-block">${esc(headers)}</div><a download="trajectory-budget-template.csv" href="data:text/csv;charset=utf-8,${encodeURIComponent(headers+'\n')}">Download the empty CSV template</a><label class="tr-field full tr-block">Budget CSV<input type="file" name="file" accept=".csv,text/csv" required></label>`,async values=>{
      const file=values.get('file'),format=values.get('budget_format');
      if(!file||file.size===0)throw new Error('Choose a budget CSV to preview.');
      showPreview(file,format,await upload(file,format));return false;
    },'Preview budget');
  }
  function importForm(kind='transactions') {
    const observation=kind==='financial_observation';
    const columns=kind==='transactions'?'id,date,merchant,amount,category':(observation?['name',...Object.keys(data.definitions.financial_observation)]:Object.keys(data.definitions.payroll)).join(',');
    dialog(observation?'Import source observations':kind==='payroll'?'Import payroll detail':'Import statement',`<p class="tr-subtle">CSV or XLSX, up to 5,000 rows. Review before importing. ${kind==='transactions'?'Amounts in dollars: income positive, spending negative.':observation?'Money columns are integer cents; complete is 0 or 1. Use a stable source_id for every row. Dated items use one pay/sale date; period totals never add to overlapping dated items. Leave optional fields blank.':'Money columns are integer cents. Regular wages exclude the separate overtime column. All employer costs must be supplied; do not substitute net pay for wages.'}</p><div class="tr-code tr-block">${esc(columns)}</div>${kind==='transactions'?field('account_id','Account','text','',data.accounts.map(a=>({value:a.id,label:a.name}))):''}${field('file','Statement file','file','',null,true)}`,async v=>{
      v.set('kind',kind);const preview=await api(endpoint('/imports/preview'),'POST',v);
      $('#tr-dialog').close();
      dialog('Review import',`<p class="tr-subtle">${preview.count} rows ready. Preview of the first ${preview.preview.length}.</p><div class="tr-table-wrap"><table class="tr-table"><tbody>${preview.preview.map(r=>`<tr><td>${esc(r.date||r.period_end)}</td><td>${esc(r.merchant||r.employee||r.name)}</td><td>${observation?precise(r.amount_cents):money(r.amount_cents??r.wages_cents)}</td>${observation?`<td>Gross ${precise(r.gross_cents)}</td><td>${esc(r.source_id)}</td><td>${esc(r.source)}</td>`:''}</tr>`).join('')}</tbody></table></div>`,async()=>{await api(endpoint('/imports/confirm'),'POST',{token:preview.token});notice(`${preview.count} rows imported.`);},'Confirm import');
      return false;
    },'Preview');
  }
  function forecastDetail(row) {
    dialog(`Projected ${date(row.date)}`,`<div class="tr-block"><div class="tr-row">Cash <strong>${money(row.cash_cents)}</strong></div><div class="tr-row">Available after reserves <strong>${money(row.available_cents)}</strong></div><div class="tr-row">Reserved for goals <strong>${money(row.goal_reserve_cents)}</strong></div><div class="tr-row">Reserved for medical bills <strong>${money(row.medical_reserve_cents||0)}</strong></div>${data.bills.filter(b=>b.date===row.date).map(b=>`<div class="tr-row">${esc(b.name)}<strong>${money(b.amount_cents)}</strong></div>`).join('')}<p class="tr-subtle">This projection includes confirmed schedules, estimated variable spending, debt payments, and goal reserves. Edit those inputs in Bills & goals or Wealth & debt.</p></div>`,null);
  }
  async function mapBankAccounts(id, spaceId=active) {
    const result=await api(endpoint(`/banks/${id}/accounts`, spaceId));
    const fresh=result.accounts.filter(a=>!a.existing);
    if(!fresh.length){notice('All returned accounts already have owners. Use Assign owner to move an imported account.');return;}
    dialog('Choose who owns each bank account',`<p class="tr-subtle">Transactions will be imported only into the company or household you choose. Its authorized financial members can see that account. Unassigned new accounts remain outside your books.</p>${fresh.map((a,i)=>field('bank-'+i,a.name+(a.mask?' (…'+a.mask+')':''),'text','',[{value:'',label:'Leave unassigned'},...spaces.map(s=>({value:s.id,label:s.name}))],true)).join('')}`,async values=>{const assignments=fresh.flatMap((a,i)=>values.get('bank-'+i)?[{id:a.id,space_id:Number(values.get('bank-'+i))}]:[]);if(!assignments.length)throw new Error('Choose an owner for at least one account.');await api(endpoint(`/banks/${id}/accounts`, spaceId),'POST',{assignments});notice('Account ownership saved. History is being synchronized.');},'Assign accounts & import');
  }
  async function connect(connectionId=null, resume=null, redirectUri=null) {
    if(linkInProgress){notice('A bank connection window is already open. Finish or close it before starting another one.');return;}
    const targetSpaceId = resume?.space_id ?? active;
    if(!spaces.some(space=>space.id===targetSpaceId))throw new Error('This financial workspace is no longer available. Refresh to check your access.');
    const bankEndpoint = path => endpoint(path, targetSpaceId);
    linkInProgress=true;
    let handler, finished=false, completing=false;
    const finish=()=>{if(finished)return;finished=true;clearBankLinkSession(bankStorage);handler?.destroy();linkInProgress=false;if(new URLSearchParams(window.location.search).has('oauth_state_id'))window.history.replaceState(window.history.state,'','/trajectory');};
    try {
      const token=resume ?? saveBankLinkSession(bankStorage, {...await api(bankEndpoint('/banks/link'),'POST',{connection_id:connectionId}),space_id:targetSpaceId});
      if(!window.Plaid)await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://cdn.plaid.com/link/v2/stable/link-initialize.js';script.onload=resolve;script.onerror=()=>reject(new Error('Unable to load bank linking.'));document.head.appendChild(script);});
      handler=window.Plaid.create({token:token.link_token,...(redirectUri?{receivedRedirectUri:redirectUri}:{}),onSuccess:async (publicToken,metadata)=>{if(completing||finished)return;completing=true;try{if(token.connection_id)await api(bankEndpoint(`/banks/${token.connection_id}/sync`),'POST',{link_session:token.link_session,institution_name:metadata?.institution?.name||null});else {const result=await api(bankEndpoint('/banks/exchange'),'POST',{public_token:publicToken,link_session:token.link_session,institution_name:metadata?.institution?.name||null});if(result.needs_mapping){finish();await load();await mapBankAccounts(result.connection_id,targetSpaceId);return;}}notice('Bank connected. History is being synchronized.');await load();}catch(e){notice(e.message);}finally{finish();}},onExit:(error,metadata)=>{finish();notice(bankLinkExitMessage(error,metadata));}});
      handler.open();
    } catch(e) {finish();throw e;}
  }
  root.addEventListener('click',async e=>{
    const target=e.target.closest('[data-action]');if(!target)return;
    const a=target.dataset.action,id=Number(target.dataset.id),spaceId=Number(target.dataset.spaceId)||active,r=data?.records.find(r=>r.id===id),tx=[...(evidenceRows||[]),...(data?.review_transactions||[]),...(data?.transactions||[])].find(t=>t.id===id&&(!target.dataset.spaceId||(t.space_id||active)===spaceId));
    try {
      if(a==='vf-inbox'){assistant.openInbox?.();return;}
      if(await visualFinance.handleClick(target))return;
      if(welcome.handle(a,target))return;
      if(await debtPlanner.handle(a,target))return;
      if(a==='welcome-open'){navigate('welcome');return;}
      if(a==='card-activity-evidence'){await showTransactions({ids:JSON.parse(target.dataset.ids),account_id:Number(target.dataset.accountId),credit_movement:true});return;}
      if(a==='tax-bank-review'){const rows=await api(endpoint('/evidence'),'POST',{ids:[id]});if(!rows[0]?.editable)throw new Error('This bank source is not available for review.');transactionForm({...rows[0],category:'taxes',flow:'sales_tax_remittance',face_punched:false,bullshit_spending:false});return;}
      if(a==='money-flow-mode'){if(['spending','cash',...(data.money_flow?.family_inflows?['inflows']:[])].includes(target.dataset.mode)){moneyFlowMode=target.dataset.mode;render();root.querySelector(`[data-action="money-flow-mode"][data-mode="${moneyFlowMode}"]`)?.focus();}return;}
      if(a==='money-flow-category'){openFlowCategory(target);return;}
      if(a==='money-flow-evidence'){if($('#tr-dialog').open&&!flowDialog)$('#tr-dialog').close();await showTransactions({ids:JSON.parse(target.dataset.ids),...(target.dataset.cash==='true'?{cash_movement:true}:target.dataset.economic==='false'?{}:{economic_costs:true,...(target.dataset.economicCategory?{economic_category:target.dataset.economicCategory}:JSON.parse(target.dataset.economicCategories||'[]').length?{economic_categories:JSON.parse(target.dataset.economicCategories)}:{})})});return;}
      if(a==='budget-import'){budgetImportForm();return;}
      if(a==='account-filter-clear'){accountFilter=null;decisionMonth='';scenarioId='';filter={};evidenceRows=null;navigation.length=0;$('#tr-back').hidden=true;debtPlanner.reset();await load();return;}
      if(a==='business-funding-plan'){
        const business=spaces.find(space=>space.id===spaceId&&space.kind==='business');
        if(!business)throw new Error('This business is not available in your financial workspace.');
        if(active!==business.id){welcome.reset();debtPlanner.reset();decisionMonth='';forecastExpanded=false;overviewExpanded=false;accountFilter=null;businessSpace=business;scope='business';active=business.id;scenarioId='';filter={};evidenceRows=null;navigation.length=0;$('#tr-back').hidden=true;tab='overview';renderSpaceTabs();await load();}
        if(data.space.id!==business.id)throw new Error('Refresh this business before reviewing its assumptions.');
        const current=data.records.filter(record=>record.kind==='business_funding_plan').sort((a,b)=>(b.data.reviewed_on||'').localeCompare(a.data.reviewed_on||'')||b.id-a.id)[0];
        recordForm('business_funding_plan',current,{name:'Business funding assumptions',reviewed_on:data.decision_support?.as_of,complete:false});return;
      }
      if(a==='outlook-view'){outlook.setView(target.dataset.view);render();root.querySelector('.tr-outlook-tabs [aria-selected="true"]')?.focus({preventScroll:true});return;}
      if(a==='monthly-view'){outlook.setMonthlyView(target.dataset.view);render();root.querySelector('.tr-monthly-controls [aria-pressed="true"]')?.focus({preventScroll:true});return;}
      if(a==='donut-mode'){incomingMode=target.dataset.mode;render();root.querySelector('.tr-income-mode [aria-pressed="true"]')?.focus({preventScroll:true});return;}
      if(a==='income-source'&&target.dataset.source){outlook.setIncomeSource(target.dataset.source);render();root.querySelector('.tr-monthly-controls [aria-pressed="true"]')?.focus({preventScroll:true});return;}
      if(a==='previous-metric'){outlook.setPreviousMetric(target.dataset.metric);render();root.querySelector('[aria-label="Previous year comparison metric"] [aria-pressed="true"]')?.focus({preventScroll:true});return;}
      if(a==='forecast-horizon'){outlook.setHorizon(Number(target.dataset.days));render();root.querySelector('.tr-horizon [aria-pressed="true"]')?.focus({preventScroll:true});return;}
      if(await planning.handle(a,target))return;
      if(['overview','accounts','history','budget','bills','assets','business','medical','sales-tax'].includes(a)){navigate(a);}
      else if(a==='tax-annotate'){const row=data.sales_tax?.rows?.find(row=>row.transaction_id===id);if(row){const record=data.records.find(r=>r.id===row.record_id);recordForm('tax_remittance',record||null,{transaction_id:id,paid_on:row.date,amount_cents:row.amount_cents,agency:row.agency||row.merchant,tax_type:row.tax_type||'unknown',movement:row.amount_cents<0?'payment':'',reviewed:false});}}
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
      else if(a==='household-observation'){
        const key=target.dataset.metric,c=data.household_cards[key];
        if(c?.ids){await showTransactions({ids:c.ids});return;}
        if(c?.observation_ids){const rows=data.records.filter(r=>c.observation_ids.includes(r.id));dialog('Source observations',`<p class="tr-subtle">Only dated items in the selected period. These records do not create additional ledger income.</p>`+rows.map(r=>`<div class="tr-row tr-block"><div><strong>${esc(r.name)} · ${precise(r.data.amount_cents)}</strong><p class="tr-source">${esc(r.data.period_start)} · ${esc(r.data.source)}</p></div>${btn('Edit','edit',`data-id="${r.id}"`)}</div>`).join(''),null);return;}
        const today=data.household_cards.as_of;
        const balance=['personal_credit_cards','personal_debt'].includes(key);
        recordForm('financial_observation',c?data.records.find(r=>r.id===c.id):null,{name:householdLabels[key]||'Personal debt',metric:key,period_start:balance?data.range.end:data.range.start,period_end:data.range.end,observed_on:balance?data.range.end:today,complete:false});
      }
      else if(a==='account-source'){
        const account=data.accounts.find(row=>row.id===id), version=data.cash_source_version;
        const choices=data.accounts.filter(row=>row.id!==id&&row.kind==='cash'&&row.currency===account.currency&&row.source_type==='connected'&&!row.current_balance_excluded&&row.balance_cents!==null&&data.connections.some(c=>c.id===row.connection_id&&c.status==='connected'));
        const options=[{value:'',label:'Use this snapshot in current totals (unlink)'},...choices.map(row=>({value:row.id,label:`${row.name} · ${row.currency} ${precise(row.balance_cents)} · observed ${date(row.observed_at)} · ${data.connections.find(c=>c.id===row.connection_id)?.institution_name||'Connected bank'}`}))];
        dialog('Choose the current balance source',`<p class="tr-subtle">${esc(account.name)} · ${esc(account.currency)} ${precise(account.balance_cents)} · observed ${date(account.observed_at)}. Choose the connected feed for this same bank account only after checking its name, mask and currency. Its balance will count once; this snapshot and all transaction history stay available. Unlink restores this snapshot to current totals.</p>${field('replacement_account_id','Current balance source','text',account.current_balance_replaced_by_account_id??'',options)}`,async v=>{const replacement=v.get('replacement_account_id');await api(endpoint(`/accounts/${id}`),'PATCH',{replacement_account_id:replacement===''?null:Number(replacement),cash_source_version:version});notice('Current balance source saved. Historical observations and transactions retained.');});
      }
      else if(a==='account-balance'){
        const account=data.accounts.find(a=>a.id===id);
        dialog('Update observed balance',`<p class="tr-subtle">${esc(account.name)} · Record the lender’s actual balance and date. This does not create a payment or change transaction history.</p>${field('balance','Observed balance','number',account.balance_cents===null?'':account.balance_cents/100)}${field('observed_on','Balance date','date',data.household_cards?.as_of||data.range.end)}`,async v=>{await api(endpoint(`/accounts/${id}`),'PATCH',{balance_cents:minor(v.get('balance')),observed_on:v.get('observed_on')});});
      }
      else if(a==='account-activity'){await showTransactions({account_id:id});}
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
        dialog(table.title,`<div class="tr-table-wrap"><table class="tr-table"><caption>${esc(table.title)}</caption><thead><tr><th>Period / category</th>${table.datasets.map(d=>`<th>${esc(d.label||'Value')}</th>`).join('')}<th></th></tr></thead><tbody>${table.labels.map((label,i)=>`<tr><td>${esc(label)}</td>${table.datasets.map(d=>`<td>${esc(table.formatMoney(d.data[i]??null))}</td>`).join('')}<td>${table.click?`<button type="button" data-chart-row="${i}">Inspect</button>`:''}</td></tr>`).join('')}</tbody></table></div>`,null);
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
      else if(a==='observation-import'){if(accountFilter!==null)notice('Choose all accounts before importing whole-household source observations.');else importForm('financial_observation');}
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
      else if(a==='split'){$('#tr-dialog').close();dialog('Allocate this transaction',`<p class="tr-subtle">Original amount: ${evidenceMoney(tx,tx.amount_cents)}. Assign an amount to another authorized space; the remainder stays here. Household/business spaces must be linked, or you must own both businesses. The original bank account and cash movement stay unchanged.</p>${field('space_id','Other authorized space','text','',spaces.filter(s=>s.id!==active).map(s=>({value:s.id,label:s.name})))}${field('mode','Split by','text','percent',['percent','dollars'])}${field('amount','Percentage or signed dollar amount','number',50)}`,async v=>{const other=v.get('mode')==='percent'?percentShare(tx.amount_cents,v.get('amount')):minor(v.get('amount'));await api(endpoint(`/transactions/${id}/split`),'POST',{version:tx.version,splits:[{space_id:active,amount_cents:tx.amount_cents-other},{space_id:Number(v.get('space_id')),amount_cents:other}]});});}
      else if(a==='debt-suggestion')recordForm('debt',null,Object.fromEntries(Object.entries(data.debt_suggestions[Number(target.dataset.index)]).filter(([,v])=>v!==null)));
      else if(a==='sell')dialog('Record a metals sale',field('quantity','Quantity sold','number',1)+field('proceeds','Proceeds after fees ($)','number',0)+field('transaction_id','Matching bank transaction ID (optional)','number',''),async v=>{await api(endpoint(`/records/${id}/sell`),'POST',{quantity:v.get('quantity'),proceeds_cents:minor(v.get('proceeds')),version:r.version,transaction_id:v.get('transaction_id')?Number(v.get('transaction_id')):null});});
      else if(a==='sms')smsForm();
    } catch(e){notice(e.message);}
  });
  function smsForm(){dialog('Text reminders',field('phone','Your mobile number','tel','+1',null,true)+field('consent','I want bill reminders, cash alerts, and weekly summaries','checkbox',false),async v=>{await api(endpoint('/sms/verify'),'POST',{phone:v.get('phone'),consent:v.get('consent')==='on'});dialog('Verify your phone',field('code','Code from your text','text','',null,true),async v2=>{await api(endpoint('/sms/confirm'),'POST',{code:v2.get('code')});},'Verify');return false;},'Send verification code');}
  $('#tr-form').addEventListener('submit',async e=>{e.preventDefault();if(!formAction)return;$('#tr-save').disabled=true;$('#tr-form-error').hidden=true;try{const done=await formAction(new FormData($('#tr-form')));if(done!==false){$('#tr-dialog').close();await load();}}catch(e){$('#tr-form-error').textContent=e.message;$('#tr-form-error').hidden=false;}finally{$('#tr-save').disabled=false;}});
  $('#tr-close').onclick=$('#tr-cancel').onclick=()=>$('#tr-dialog').close();
  $('#tr-dialog').addEventListener('close',()=>{assistantFocusedTransaction=null;if(flowDialog&&!restoringNavigation){const backed=historyNavigation.back();flowDialog=null;if(!backed)historyNavigation.sync();}});
  root.addEventListener('keydown',e=>{
    const flow=e.target.closest('.tr-flow-action');
    if(flow&&['Enter',' '].includes(e.key)){e.preventDefault();flow.dispatchEvent(new MouseEvent('click',{bubbles:true}));return;}
    const button=e.target.closest('[data-action="outlook-view"]');
    if(!button||!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
    e.preventDefault();
    const views=['lines','bars','comparison','previous'],index=views.indexOf(button.dataset.view);
    const next=e.key==='Home'?views[0]:e.key==='End'?views.at(-1):views[(index+(e.key==='ArrowRight'?1:-1)+views.length)%views.length];
    root.querySelector(`[data-action="outlook-view"][data-view="${next}"]`)?.click();
  });
  root.addEventListener('change',e=>{if(e.target.matches('[data-vf-input],[data-vf-category]'))void visualFinance.handleInput(e.target).catch(error=>notice(error.message));});
  $('#tr-add').onclick=chooseAdd;$('#tr-refresh').onclick=load;
  $('#tr-space-tabs').addEventListener('click',e=>{const button=e.target.closest('[data-scope]');if(!button||button.dataset.scope===scope)return;navigation.length=0;$('#tr-back').hidden=true;welcome.reset();debtPlanner.reset();decisionMonth='';forecastExpanded=false;overviewExpanded=false;accountFilter=null;scope=button.dataset.scope;active=(scope==='personal'?personalSpace:businessSpace)?.id||personalSpace?.id||businessSpace?.id;scenarioId='';filter={};budgetSource=null;budgetMonth='';historyMonth='';evidenceRows=null;data=null;$('#tr-dialog').close();$('#tr-content').innerHTML='';renderSpaceTabs();load();});
  $('#tr-account-filter')?.addEventListener('change',async event=>{accountFilter=event.target.value===''?null:Number(event.target.value);decisionMonth='';scenarioId='';filter={};evidenceRows=null;navigation.length=0;$('#tr-back').hidden=true;debtPlanner.reset();await load();});
  $('#tr-compare').onclick=()=>{compareYear=!compareYear;$('#tr-compare').setAttribute('aria-pressed',String(compareYear));render();};
  $('#tr-range').onclick=e=>{const button=e.target.closest('[data-range]');if(!button||button.dataset.range===reportingRange)return;reportingRange=button.dataset.range;filter={};evidenceRows=null;$('#tr-range').querySelectorAll('[data-range]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.range===reportingRange)));$('#tr-date-fields').hidden=reportingRange!=='custom';if(reportingRange!=='custom'||($('#tr-start').value&&$('#tr-end').value))load();};
  for(const selector of ['#tr-start','#tr-end'])$(selector).onchange=()=>{if($('#tr-start').value&&$('#tr-end').value)load();};
  root.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{if(data)navigate(b.dataset.tab);});
  $('#tr-back').onclick=()=>{historyNavigation.back();};
  $('#tr-company').onchange=()=>{welcome.reset();debtPlanner.reset();decisionMonth='';forecastExpanded=false;overviewExpanded=false;accountFilter=null;businessSpace=spaces.find(s=>s.id===Number($('#tr-company').value));active=businessSpace.id;scenarioId='';navigation.length=0;$('#tr-back').hidden=true;renderSpaceTabs();load();};
  if(root.dataset.invite){$('#tr-invite').hidden=false;$('#tr-accept').onclick=async()=>{try{await api('/trajectory/invites/accept','POST',{token:root.dataset.invite});location.href='/trajectory';}catch(e){notice(e.message);}};}
  let foregroundInteraction = 0;
  for(const event of ['pointerdown','keydown','input','change'])document.addEventListener(event,()=>{foregroundInteraction++;},{capture:true,passive:true});
  const foregroundEligible=()=>canRefreshFinance({visible:!document.hidden,online:navigator.onLine!==false,hasData:!!data,tab,scope,
    loading:$('#tr-content').getAttribute('aria-busy')==='true'||visualFinance.isBusy(),
    editing:!!document.querySelector('dialog[open],#tr-mobile-drawer[role="dialog"],#tr-report-controls[data-open],#tr-content details[open]')||document.activeElement?.matches('input,select,textarea,[contenteditable="true"]'),
    linking:linkInProgress,restoring:restoringNavigation,oauth:new URLSearchParams(location.search).has('oauth_state_id'),hasMetals:!!data?.metals?.length});
  const refreshForeground=createForegroundRefresh({eligible:foregroundEligible,
    snapshot:()=>JSON.stringify([reportContext(),tab,visualFinance.capture(),loadVersion,foregroundInteraction]),
    refresh:async isCurrent=>{
      const position=window.scrollY,focus=focusDescriptor(),tableScroll=[...root.querySelectorAll('#tr-content .vf-table-wrap,#tr-content .tr-table-wrap')].map(node=>({left:node.scrollLeft,top:node.scrollTop}));
      try{
        const [result,refreshed]=await Promise.all([api(dashboardPath(active)),tab==='assets'?Promise.resolve(true):visualFinance.refresh({background:true,isCurrent})]);
        if(!refreshed||!isCurrent())return false;
        if((result.scope_filter?.account_id??null)!==accountFilter)return false;
        data=result;render();window.scrollTo(0,position);
        root.querySelectorAll('#tr-content .vf-table-wrap,#tr-content .tr-table-wrap').forEach((node,index)=>{if(tableScroll[index]){node.scrollLeft=tableScroll[index].left;node.scrollTop=tableScroll[index].top;}});
        const target=focus&&[...root.querySelectorAll('[data-action]')].find(node=>node.dataset.action===focus.action&&(node.dataset.label||'')===focus.label&&(node.dataset.ids||'')===focus.ids&&(!focus.key||(node.dataset.key||'')===focus.key)&&(!focus.entry||(node.dataset.entry||'')===focus.entry)&&node.getClientRects().length);
        target?.focus({preventScroll:true});historyNavigation.sync();
        return true;
      }catch{return false;}
    }});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refreshForeground();});
  setInterval(()=>{void refreshForeground();},60000);
  load().finally(()=>mobile?.ready()).then(()=>{
    if(!oauthReturn)return;
    if(resumedBankLink&&receivedRedirectUri)void connect(resumedBankLink.connection_id,resumedBankLink,receivedRedirectUri).catch(error=>notice(error.message));
    else {clearBankLinkSession(bankStorage);window.history.replaceState(window.history.state,'','/trajectory');notice('Your bank sign-in expired or is no longer available. Choose Connect a bank to start again.');}
  });
}
