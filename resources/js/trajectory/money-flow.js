const kindNames={income:'Classified income',support:'Company distributions',borrowing:'Borrowed money',asset:'Asset-sale proceeds',refund:'Purchase refunds',reimbursement:'Reimbursements',medical:'Medical sharing · bill reimbursements',debt_credit:'Debt-payment credits',tax_credit:'Sales-tax returns & cost credits',review:'Needs review · incoming'};
const palette=['#397d69','#527f94','#a7834b','#766b94','#af7968','#699078','#6e8892','#b58a3e'];
const ids=rows=>[...new Set(rows.flatMap(row=>row.ids||[]))];
const sum=rows=>rows.reduce((total,row)=>total+row.amount_cents,0);
const payrollCategories=new Set(['payroll','owner_payroll','studio_payroll','event_payroll']);
function condensed(rows,limit,label){
  const sorted=rows.filter(row=>Number.isSafeInteger(row.amount_cents)&&row.amount_cents>0).map(row=>({...row})).sort((a,b)=>b.amount_cents-a.amount_cents||String(a.key).localeCompare(String(b.key)));
  if(sorted.length<=limit)return sorted;
  const rest=sorted.slice(limit-1);
  return [...sorted.slice(0,limit-1),{key:'other',label,kind:rest.some(row=>row.kind==='review')?'review':'outflow',amount_cents:sum(rest),ids:ids(rest),economic_categories:[...new Set(rest.flatMap(row=>row.economic_categories||[row.economic_category].filter(Boolean)))],merchants:rest.flatMap(row=>row.merchants||[])}];
}

/** Every layer uses nonnegative integer cents; difference nodes have no source IDs. */
export function moneyFlowGraph(view){
  const byKind=new Map();
  for(const row of view?.inflows||[]){const purpose=row.purpose_key||row.kind||'review';if(!byKind.has(purpose))byKind.set(purpose,[]);byKind.get(purpose).push(row);}
  const incoming=[...byKind].flatMap(([purpose,rows])=>{const kind=rows[0].kind||'review',label=rows[0].purpose_label||kindNames[kind]||'incoming money';return condensed(rows,3,`Other ${label}`).map(row=>({...row,kind,purpose_key:purpose,purpose_label:label}));});
  const preserved=(view?.outflows||[]).filter(row=>row.kind==='review'||payrollCategories.has(row.key));
  const outgoing=[...condensed((view?.outflows||[]).filter(row=>row.kind!=='review'&&!payrollCategories.has(row.key)),Math.max(2,7-preserved.length),'Other outgoing categories'),...preserved];
  const incomingTotal=sum(incoming),outgoingTotal=sum(outgoing),total=Math.max(incomingTotal,outgoingTotal);
  const nodes=[],links=[];
  if(!Number.isSafeInteger(incomingTotal)||!Number.isSafeInteger(outgoingTotal))return {nodes,links,total_cents:0,error:'amount_range'};
  const node=(id,column,row)=>{const value={...row,id,column};nodes.push(value);return value;};
  const link=(source,target,row)=>links.push({source:source.id,target:target.id,amount_cents:row.amount_cents,ids:row.ids||[],kind:row.kind});
  if(!total)return {nodes,links,total_cents:0,inflow_cents:incomingTotal,outflow_cents:outgoingTotal};
  const pool=node('pool',1,{label:'Recorded activity',amount_cents:total,kind:'pool',ids:[]});
  for(const [index,row] of incoming.entries()){const item=node(`in-${index}`,0,row);link(item,pool,row);}
  if(outgoingTotal>incomingTotal){const gap=node('funding-gap',0,{label:view.tax_split_unknown_ids?.length?'Known subset funding gap · tax costs unresolved':'Outflows above recorded inflows',amount_cents:outgoingTotal-incomingTotal,kind:'difference',ids:[]});link(gap,pool,gap);}
  if(incomingTotal>outgoingTotal)outgoing.push({key:'difference',label:view.tax_split_unknown_ids?.length?'Known subset difference · tax costs unresolved':'Recorded inflows above outflows',amount_cents:incomingTotal-outgoingTotal,kind:'difference',ids:[],merchants:[]});
  for(const [index,row] of outgoing.entries()){
    const category=node(`out-${index}`,2,{...row,color:palette[index%palette.length]});link(pool,category,row);
    const merchants=row.kind==='difference'?[{...row,label:'Derived difference · not savings'}]:condensed(row.merchants||[],3,'Other merchants');
    for(const [merchantIndex,merchant] of merchants.entries()){const item=node(`merchant-${index}-${merchantIndex}`,3,{...merchant,color:category.color});link(category,item,merchant);}
  }
  return {nodes,links,total_cents:total,inflow_cents:incomingTotal,outflow_cents:outgoingTotal};
}

export function moneyFlowCompactGraph(view){
  const full=moneyFlowGraph(view);if(!full.total_cents)return full;
  const groups=new Map(),short={...kindNames,income:'Classified income',support:'Company draws',asset:'Asset sales',medical:'Medical bill shares',review:'Needs review'};
  for(const row of full.nodes.filter(node=>node.column===0)){
    const key=row.kind==='difference'?row.id:row.purpose_key||row.kind;
    if(!groups.has(key))groups.set(key,{...row,id:`compact-${key}`,label:row.kind==='difference'?(view.tax_split_unknown_ids?.length?'Known subset gap':'Recorded funding gap'):row.purpose_key!==row.kind&&row.purpose_label?row.purpose_label:short[row.kind]||row.label,amount_cents:0,ids:[]});
    const group=groups.get(key);group.amount_cents+=row.amount_cents;group.ids.push(...row.ids);
  }
  const pool={id:'compact-pool',column:1,label:'Recorded activity',kind:'pool',amount_cents:full.total_cents,ids:[]};
  const outgoing=full.nodes.filter(node=>node.column===2).map(node=>({...node,id:`compact-${node.id}`,label:node.kind==='difference'?(view.tax_split_unknown_ids?.length?'Known subset difference':'Inflows above outflows'):node.label}));
  const nodes=[...groups.values(),pool,...outgoing],links=[];
  for(const row of groups.values())links.push({source:row.id,target:pool.id,amount_cents:row.amount_cents,ids:row.ids,kind:row.kind});
  for(const row of outgoing)links.push({source:pool.id,target:row.id,amount_cents:row.amount_cents,ids:row.ids,kind:row.kind});
  return {...full,nodes,links};
}

export function moneyFlowLayout(graph,compact=false){
  const columns=compact?[0,1,2]:[0,1,2,3],flowHeight=compact?240:360,scale=graph.total_cents?flowHeight/graph.total_cents:0,gap=compact?12:18;
  const nodes=graph.nodes.map(node=>({...node,band:node.amount_cents*scale,height:Math.max(64,node.amount_cents*scale),x:(compact?[8,177,208]:[18,430,640,960])[node.column],width:node.column===1?6:compact?(node.column===0?132:144):node.column===3?180:225}));
  const whole=column=>nodes.filter(node=>node.column===column).reduce((n,node)=>n+node.height+gap,0)-gap;
  const height=Math.max(compact?350:500,...columns.map(whole))+30;
  for(const column of columns){
    const current=nodes.filter(node=>node.column===column);let y=(height-whole(column))/2;
    for(const node of current){node.y=y;node.port=y+(node.height-node.band)/2;node.anchor=node.column===0?node.x+node.width:node.x;y+=node.height+gap;}
  }
  const lookup=new Map(nodes.map(node=>[node.id,node])),usedIn=new Map(),usedOut=new Map();
  const links=graph.links.map(link=>{
    const source=lookup.get(link.source),target=lookup.get(link.target),band=link.amount_cents*scale;
    const sy=source.port+(usedOut.get(source.id)||0)+band/2,ty=target.port+(usedIn.get(target.id)||0)+band/2;
    usedOut.set(source.id,(usedOut.get(source.id)||0)+band);usedIn.set(target.id,(usedIn.get(target.id)||0)+band);
    const sx=source.anchor+6,tx=target.anchor,middle=(sx+tx)/2;
    return {...link,band,path:`M ${sx} ${sy} C ${middle} ${sy}, ${middle} ${ty}, ${tx} ${ty}`,color:source.column===1?target.color:source.color};
  });
  return {nodes,links,width:compact?360:1160,height};
}

function wrapLabel(value,limit){
  const lines=[];let current='';
  for(const word of String(value).split(/\s+/).flatMap(word=>{const chars=[...word];return Array.from({length:Math.ceil(chars.length/limit)},(_,index)=>chars.slice(index*limit,(index+1)*limit).join(''));})){if(current&&current.length+word.length+1>limit){lines.push(current);current=word;}else current+=(current?' ':'')+word;}
  if(current)lines.push(current);
  return lines.length>3?[...lines.slice(0,2),lines.slice(2).join(' ').slice(0,limit-1)+'…']:lines;
}

export function moneyFlowTrendSeries(report,mode='spending'){
  return (report?.history?.years||[]).map(year=>({year:year.year,values:year.months.map(month=>Number.isSafeInteger(month.views?.[mode]?.outflow_cents)?month.views[mode].outflow_cents:null),months:year.months}));
}

function historyHtml(report,mode,{esc,precise,btn}){
  const history=report?.history;if(!history)return '';
  const source=point=>point?.ids?.length?btn('View records','money-flow-evidence',`data-cash="${mode==='cash'}" data-ids="${esc(JSON.stringify(point.ids))}"`):'';
  const current=history.current.views[mode],prior=history.prior.views[mode];
  const months=Array.from({length:12},(_,index)=>new Intl.DateTimeFormat('en-US',{month:'short',timeZone:'UTC'}).format(new Date(Date.UTC(2020,index,1))));
  return `<section class="tr-panel tr-money-flow-history" aria-labelledby="tr-flow-history-title"><header class="tr-panel-head"><h2 id="tr-flow-history-title">Last year and the monthly trend</h2></header><p class="tr-subtle">Recorded totals; coverage can differ. Missing history stays unavailable.</p><details class="tr-flow-history-coverage"><summary>Coverage and comparison basis</summary><p class="tr-subtle">${esc(history.note)}</p></details><div class="tr-table-wrap" tabindex="0"><table class="tr-table"><caption>Same calendar period · recorded ${mode==='cash'?'cash movements':'economic activity'}</caption><thead><tr><th scope="col">Period</th><th scope="col">Recorded inflows · all types</th><th scope="col">Recorded outflows · gross</th><th scope="col">Evidence</th></tr></thead><tbody>${[[history.current,current],[history.prior,prior]].map(([period,point])=>`<tr><th scope="row">${esc(period.from)} – ${esc(period.through)}</th><td>${precise(point.inflow_cents)}</td><td>${precise(point.outflow_cents)}</td><td>${source(point)}</td></tr>`).join('')}</tbody></table></div>
    <h3>Monthly recorded outflows</h3><p class="tr-subtle">Whole calendar-year actuals, separate from the selected-period comparison above. Gaps mean unavailable history; the current month is partial. Tap a point for outflow records.</p>${moneyFlowTrendSeries(report,mode).some(year=>year.values.some(value=>value!==null))?'<div class="tr-chart"><canvas id="tr-flow-trend" role="img" aria-label="Monthly recorded outflows by year; gaps mean unavailable history"></canvas></div>':'<p class="tr-empty">No monthly outflow evidence is available in this scope.</p>'}
    <details class="tr-flow-table"><summary>Monthly inflows, outflows and source records</summary><div class="tr-table-wrap" tabindex="0"><table class="tr-table"><caption>Three-year monthly recorded amounts · coverage may differ</caption><thead><tr><th scope="col">Month</th>${history.years.map(year=>`<th scope="col">${esc(year.year)} · incoming / outgoing</th>`).join('')}</tr></thead><tbody>${months.map((month,index)=>`<tr><th scope="row">${month}</th>${history.years.map(year=>{const point=year.months[index].views[mode];return `<td><span>In ${precise(point.inflow_cents)}</span><br><span>Out ${precise(point.outflow_cents)}</span><br>${source(point)}</td>`;}).join('')}</tr>`).join('')}</tbody></table></div></details></section>`;
}

function diagram(view,{esc,precise,cash=false},compact=false){
  const graph=compact?moneyFlowCompactGraph(view):moneyFlowGraph(view);
  if(graph.error)return '<div class="tr-empty">Amounts exceed the supported exact-cent display range. Use source records; no approximate money flow is drawn.</div>';
  if(!graph.total_cents)return '<div class="tr-empty">No included non-zero flows in this scope. Check coverage and the excluded-record table below.</div>';
  const layout=moneyFlowLayout(graph,compact),suffix=compact?'phone':'desktop';
  const attrs=row=>{
    if(!row.ids?.length)return '';
    const category=compact&&row.column===2;
    return `class="tr-flow-action" role="button" tabindex="0" data-action="${category?'money-flow-category':'money-flow-evidence'}" data-label="${esc(row.label)}" data-merchants="${esc(JSON.stringify(category?condensed(row.merchants||[],8,'Other merchants'):[]))}" data-cash="${cash}" data-economic-category="${esc(row.economic_category||'')}" data-economic-categories="${esc(JSON.stringify(row.economic_categories||[]))}" data-ids="${esc(JSON.stringify(row.ids))}" aria-label="${esc(row.label)} · ${esc(precise(row.amount_cents))} · ${category?'View merchants and records':`View ${row.ids.length} transactions`}"`;
  };
  const headings=compact?[['Sources',8],['Where it went',208]]:[['Incoming sources',18],['Activity',410],['Outgoing categories',640],['Merchants',960]];
  return `<div class="tr-flow-scroll tr-flow-${suffix}" role="region" aria-label="${compact?'Phone source-to-category overview; tap categories for merchants':'Money flow from sources to categories and merchants'}"><svg class="tr-flow-svg" viewBox="0 0 ${layout.width} ${layout.height+40}" width="${layout.width}" height="${layout.height+40}" aria-labelledby="tr-flow-title-${suffix} tr-flow-desc-${suffix}"><title id="tr-flow-title-${suffix}">Recorded money flow</title><desc id="tr-flow-desc-${suffix}">Sources pass through a shared activity anchor; the bands do not establish that one receipt funded a particular purchase. Select a labeled branch for its source records. Differences are derived, not savings.</desc>
    ${headings.map(([label,x])=>`<text class="tr-flow-column" x="${x}" y="22">${label}</text>`).join('')}
    <g transform="translate(0 32)">${layout.links.map(link=>`<path d="${link.path}" class="tr-flow-band ${link.kind==='difference'?'tr-flow-derived':''}" stroke="${esc(link.color||'#6c9b8d')}" stroke-width="${Math.max(.5,link.band)}"><title>${esc(precise(link.amount_cents))}</title></path>`).join('')}
    ${layout.nodes.map(node=>{
      const lines=wrapLabel(node.label,compact?18:node.column===3?23:29),labelX=node.column===0?node.x:node.anchor+12,firstY=node.y+node.height/2-(lines.length*16+20)/2+13;
      const barHeight=Math.max(3,node.band),barY=node.port-(barHeight-node.band)/2,color=node.kind==='difference'?'#ab7859':node.kind==='review'?'#af8836':node.color||'#397d69';
      return `<g ${attrs(node)}><title>${esc(node.label)} · ${esc(precise(node.amount_cents))}</title>${node.column===1?'':`<rect class="tr-flow-hit" x="${node.x}" y="${node.y}" width="${node.width+6}" height="${node.height}" rx="5"/>`}<rect x="${node.anchor}" y="${barY}" width="6" height="${barHeight}" rx="2" class="tr-flow-anchor ${node.kind==='difference'?'tr-flow-derived':''}" fill="${esc(color)}"/>${node.column===1?'':`<text class="tr-flow-label" x="${labelX}" y="${firstY}">${lines.map((line,index)=>`<tspan x="${labelX}" dy="${index?16:0}">${esc(line)}</tspan>`).join('')}</text><text class="tr-flow-amount" x="${labelX}" y="${firstY+lines.length*16+3}">${esc(precise(node.amount_cents))}${node.kind==='difference'&&!compact?' · derived':''}</text>`}</g>`;
    }).join('')}</g></svg></div>`;
}

export function moneyFlowHtml({report,mode='spending',esc,precise:formatMoney,btn}){
  const precise=value=>formatMoney(Number.isSafeInteger(value)?value:null);
  const view=report?.views?.[mode];
  if(!view)return '<div class="tr-empty">Recorded money flow is unavailable for this view.</div>';
  const inspect=(row,cash=mode==='cash')=>row.ids?.length?btn('View transactions','money-flow-evidence',`data-cash="${cash}" data-economic="${mode==='spending'&&(!row.amount_basis||row.economic_category==='sales_tax_principal')}" data-economic-category="${esc(row.economic_category||'')}" data-economic-categories="${esc(JSON.stringify(row.economic_categories||[]))}" data-ids="${esc(JSON.stringify(row.ids))}"`):'Derived · no transaction';
  const percentage=(amount,total)=>Number.isSafeInteger(total)&&total>0?`${(amount/total*100).toFixed(1)}%`:'Unavailable';
  const rows=(groups,total,section)=>groups.map(row=>`<tr><th scope="row">${esc(section)} · ${esc(row.label)}</th><td>${esc(precise(row.amount_cents))}</td><td>${percentage(row.amount_cents,total)}</td><td>${row.ids.length}</td><td>${inspect(row)}</td></tr>`).join('');
  const difference=view.difference_cents;
  const differenceLabel=difference===null?'Activity difference unavailable':difference<0?'Outflows above recorded inflows':difference>0?'Recorded inflows above outflows':'Recorded inflows equal outflows';
  return `<section class="tr-panel tr-money-flow" aria-labelledby="tr-flow-title"><header class="tr-panel-head"><h2 id="tr-flow-title">Follow your money</h2></header>
    <div class="tr-period-tabs tr-flow-modes" role="group" aria-label="Money flow view"><button type="button" data-action="money-flow-mode" data-mode="spending" aria-pressed="${mode==='spending'}">Spending</button><button type="button" data-action="money-flow-mode" data-mode="cash" aria-pressed="${mode==='cash'}">Cash movement</button></div>
    <p class="tr-subtle"><span class="tr-flow-long">${mode==='spending'?'Economic purchases, including credit-card purchases and authorized shared allocations. Debt repayments and sales-tax principal are excluded; known tax interest, penalties and fees remain.':'Full posted bank movements on your owned cash accounts. Debt repayments appear here; card purchases and other spaces’ cash do not.'}</span><span class="tr-flow-short">${mode==='spending'?'Purchases and known costs. Repayments and sales-tax principal excluded.':'Owned bank cash. Debt payments included; card purchases excluded.'}</span></p>
    <div class="tr-data-note tr-flow-coverage"><span class="tr-flow-long"><strong>Recorded coverage · may be partial.</strong> ${report.coverage?.history_start?`Account history starts ${esc(report.coverage.history_start)}. `:''}Report dates and the account filter apply. This diagram does not establish your cash balance or savings.</span><span class="tr-flow-short">Partial recorded coverage. Not a cash balance or savings.</span></div>
    <div class="tr-grid equal"><div class="tr-metric"><span class="tr-kicker"><span class="tr-flow-long">Recorded inflows · all types</span><span class="tr-flow-short">In · all types</span></span><div class="tr-number">${precise(view.inflow_cents)}</div></div><div class="tr-metric"><span class="tr-kicker"><span class="tr-flow-long">Recorded outflows · gross</span><span class="tr-flow-short">Out · gross</span></span><div class="tr-number">${precise(view.outflow_cents)}</div></div><div class="tr-metric"><span class="tr-kicker"><span class="tr-flow-long">${esc(differenceLabel)}</span><span class="tr-flow-short">Difference</span></span><div class="tr-number">${precise(difference===null?null:Math.abs(difference))}</div><small class="tr-flow-long">Derived activity difference</small></div></div><p class="tr-flow-difference"><span class="tr-flow-short">${esc(differenceLabel)} · derived, not savings.</span></p>
    ${view.tax_split_unknown_ids?.length?`<p class="tr-data-note tr-tax-economic-warning"><strong>Economic totals unavailable.</strong> Known outgoing subset: ${precise(view.known_outflow_cents)}. ${precise(view.tax_split_unknown_gross_cents)} of tax cash has an unknown split or changed evidence. The diagram shows only the known subset; it is not total operating spending. ${inspect({ids:view.tax_split_unknown_ids,amount_basis:'allocated'},false)}</p>`:''}
    ${view.review_inflow_cents||view.review_outflow_cents?`<p class="tr-flow-review-note">Needs review: ${precise(view.review_inflow_cents)} in · ${precise(view.review_outflow_cents)} out. <span class="tr-flow-long">These amounts are included and are not verified income or categorized expenses.</span><span class="tr-flow-short">Included; purpose unverified.</span></p>`:''}
    <p class="tr-subtle"><span class="tr-flow-long">Tap a labeled branch for its records. Small categories and merchants may be grouped without dropping cents.</span><span class="tr-flow-short">Tap a category for merchants and records.</span></p>
    ${view.outflows.some(row=>payrollCategories.has(row.key))?'<p class="tr-subtle tr-flow-payroll-note">Owner, studio and event payroll separate matched net-pay distributions. Payroll retains taxes and unassigned withdrawals. These bank amounts are not total employer cost.</p>':''}
    ${diagram(view,{esc,precise,cash:mode==='cash'})}${diagram(view,{esc,precise,cash:mode==='cash'},true)}
    <details class="tr-flow-table"><summary>All flow amounts and transactions</summary><p class="tr-subtle">${esc(view.denominator||'Incoming percentages use recorded inflows. Outgoing percentages use recorded outflows.')}</p><div class="tr-table-wrap" tabindex="0"><table class="tr-table"><caption>Recorded incoming sources, outgoing categories and merchants</caption><thead><tr><th scope="col">Branch</th><th scope="col">Amount</th><th scope="col">Share of its direction</th><th scope="col">Records</th><th scope="col">Evidence</th></tr></thead><tbody>${rows(view.inflows,view.inflow_cents,'Incoming')}${(view.outflows||[]).map(row=>rows([row],view.outflow_cents,'Outgoing')+rows(row.merchants||[],view.outflow_cents,'Merchant')).join('')||''}${!(view.inflows.length+view.outflows.length)?'<tr><td colspan="5">No included non-zero records in this scope.</td></tr>':''}</tbody></table></div></details>
    <details class="tr-flow-table"><summary>Known category refunds and net spending</summary><p class="tr-subtle">Only reviewed purchase/refund classifications appear here. A shared category does not establish a matched invoice or vendor. The diagram shows refunds separately to conserve gross movements.</p><div class="tr-table-wrap" tabindex="0"><table class="tr-table"><caption>Known spending and refunds by category</caption><thead><tr><th scope="col">Category</th><th scope="col">Gross purchases</th><th scope="col">Known refunds</th><th scope="col">Net recorded spending</th><th scope="col">Evidence</th></tr></thead><tbody>${(view.refund_categories||[]).map(row=>`<tr><th scope="row">${esc(row.label)}</th><td>${precise(row.gross_cents)}</td><td>${precise(row.refund_cents)}</td><td>${precise(row.net_cents)}</td><td>${inspect(row)}</td></tr>`).join('')||'<tr><td colspan="5">No reviewed category amounts in this scope.</td></tr>'}</tbody></table></div></details>
    <details class="tr-flow-table"><summary>Excluded and zero-amount records · ${view.excluded.reduce((n,row)=>n+row.ids.length,0)} records</summary><p class="tr-subtle">Excluded amounts are shown as gross absolute movements, not spending. Both legs of an internal transfer may be present. Unreviewed exclusions remain inspectable.</p><div class="tr-table-wrap" tabindex="0"><table class="tr-table"><caption>Records outside this diagram</caption><thead><tr><th scope="col">Reason</th><th scope="col">Gross amount</th><th scope="col">Signed total</th><th scope="col">Needs review</th><th scope="col">Evidence</th></tr></thead><tbody>${view.excluded.map(row=>`<tr><th scope="row">${esc(row.label)}</th><td>${precise(row.amount_cents)}</td><td>${precise(row.signed_cents)}</td><td>${row.review_count}</td><td>${inspect(row,row.amount_basis==='owned_bank_cash')}</td></tr>`).join('')||'<tr><td colspan="5">No excluded records in this scope.</td></tr>'}</tbody></table></div></details>
    <details class="tr-flow-table"><summary>How to read this view</summary>${(report.coverage?.notes||[]).map(note=>`<p class="tr-subtle">${esc(note)}</p>`).join('')}${view.shared_allocation_count?`<p class="tr-subtle">${view.shared_allocation_count} authorized shared allocation(s); the other space’s merchant and account stay private.</p>`:''}${view.stale_source_count?'<p class="tr-subtle">Some bank sources changed while loading. Refresh before relying on Cash movement.</p>':''}</details>
  </section>${historyHtml(report,mode,{esc,precise,btn})}`;
}
