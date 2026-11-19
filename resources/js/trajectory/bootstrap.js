export const isVisualFinanceView = view => ['overview','analysis','budget'].includes(view);
export const isVisualContext = value => value?.projection === 'visual_context';
export const isPlanContext = value => value?.projection === 'plan_context';
export const isMetadataContext = value => isVisualContext(value) || isPlanContext(value);
export function visualContextPath(accountId=null){return '/visual-context'+(accountId===null?'':`?account_id=${accountId}`);}
/** Metadata is explicitly distinct from the full legacy Dashboard projection. */
export function validateVisualContext(value,space,accountId=null){
  if(!isVisualContext(value)||value.space?.id!==space?.id||value.space?.kind!==space?.kind
    ||(value.scope_filter?.account_id??null)!==accountId||!Array.isArray(value.available)
    ||!value.available.includes('editing_catalogs')||!Array.isArray(value.records)
    ||!Array.isArray(value.category_options)||!Array.isArray(value.accounts)
    ||!value.definitions||!isoDate(value.today))throw Error('Editing details did not match the selected financial workspace. Refresh to try again.');
  return value;
}
export function validatePlanContext(value, space, accountId = null, request = {}) {
  if (!isPlanContext(value)) throw Error('The plan did not match the selected workspace.');
  validateVisualContext({...value, projection: 'visual_context'}, space, accountId);
  if (!value.available.includes('planning') || !value.planning || value.range?.key !== (request.range ?? 'month')
    || !isoDate(value.range?.start) || !isoDate(value.range?.end) || value.range.start > value.range.end || value.range.end > value.today
    || request.range === 'custom' && (value.range.start !== request.from || value.range.end !== request.through)) throw Error('The plan did not match the selected period.');
  return value;
}
const periods=new Set(['day','week','month','quarter','year','all','custom']);
const isoDate=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(value);

export function visualBootstrapRequest({range='month',from=null,through=null,accountId=null,basis='spending'}={}){
  if(!periods.has(range)||!['spending','cash'].includes(basis))throw Error('Choose a supported financial period.');
  if(range==='custom'&&(!isoDate(from)||!isoDate(through)||from>through))throw Error('Choose the start and end dates.');
  const params=new URLSearchParams({grain:'month',basis});
  if(range==='custom'){params.set('from',from);params.set('through',through);}else params.set('range',range);
  if(accountId!==null)params.set('account_id',String(accountId));
  return {range,from,through,basis,analysisPath:`/analysis?${params}`,budgetPath:range==='custom'?`/budget-envelopes?month=${through.slice(0,7)}`:'/budget-envelopes'};
}

/** Dates are supplied by the authorized server projection, never the device clock. */
export function visualBootstrapMetadata(report,budgets,space,accountId=null,request={range:'month',basis:'spending'}){
  const range=report?.range,today=report?.today,key=request.range??'month';
  const expected=key==='custom'?{from:request.from,through:request.through}:report?.calendar_ranges?.[key]??(key==='month'&&isoDate(today)?{from:`${today.slice(0,7)}-01`,through:today}:null);
  const budget=report?.budget_context??{month:today?.slice(0,7),actual_through:today};
  if(!space||!periods.has(key)||report?.scope?.space_id!==space.id||report.scope.kind!==space.kind
    ||(report.scope.account_id??null)!==accountId||(report.scope.program_id??null)!==null||report.scope.basis!==(request.basis??'spending')
    ||(report.scope.calendar_range!==undefined&&report.scope.calendar_range!==key)||!isoDate(today)
    ||!expected||!isoDate(expected.from)||!isoDate(expected.through)||range?.from!==expected.from||range?.through!==expected.through
    ||budgets?.month!==budget.month||budgets?.actual_through!==budget.actual_through)throw Error('The financial responses did not match the selected workspace and period. Refresh to try again.');
  return {space,range:{start:range.from,end:range.through},today,
    account_options:report.account_options??[],scope_filter:{account_id:accountId,label:accountId?report.account_options?.find(account=>account.id===accountId)?.name??'Selected account':'All accounts'}};
}

/** Exact, scoped subset of a previously authorized projection. Unknown totals stay null. */
export function deriveVisualPeriod(report,budgets,request){
  const key=request.range??'month';
  const target=key==='custom'?{from:request.from,through:request.through}:report?.calendar_ranges?.[key];
  if(!target||!isoDate(target.from)||!isoDate(target.through)||!report?.range||target.from<report.range.from||target.through>report.range.through)return null;
  if((request.basis??'spending')!==report.scope.basis||(report.scope.program_id??null)!==null)return null;
  if(target.from===report.range.from&&target.through===report.range.through){
    const exact=structuredClone(report);exact.scope.calendar_range=key;
    return {report:exact,budgets:structuredClone(budgets)};
  }
  if(key==='custom'||report.year!==Number(target.through.slice(0,4))||!Array.isArray(report.entries))return null;
  const month=target.through.slice(0,7);
  if(budgets?.month!==month||report.budget_context?.month!==month)return null;
  const rows=report.entries.filter(row=>row.date>=target.from&&row.date<=target.through);
  const groups=(values,direction)=>{
    const result=[];
    for(const group of values??[]){
      if(!Array.isArray(group.entry_keys))throw Error('This projection does not include exact source keys.');
      const keys=new Set(group.entry_keys),selected=rows.filter(row=>row.direction===direction&&keys.has(row.entry_key));
      if(!selected.length)continue;
      const amount=selected.reduce((sum,row)=>sum+row.amount_cents,0);
      result.push({...structuredClone(group),amount_cents:direction==='out'?-amount:amount,
        ids:[...new Set(selected.map(row=>row.id))],entry_keys:selected.map(row=>row.entry_key),
        children:groups(group.children??[],direction)});
    }
    return result.sort((a,b)=>b.amount_cents-a.amount_cents);
  };
  try{
    const selected=structuredClone(report),available=target.covered===true||target.has_evidence===true;
    selected.range={from:target.from,through:target.through,start:target.from,end:target.through};selected.scope.calendar_range=key;
    selected.entries=structuredClone(rows);selected.inflows=groups(report.inflows,'in');selected.categories=groups(report.categories,'out');
    const cost=-rows.filter(row=>row.direction==='out').reduce((sum,row)=>sum+row.amount_cents,0);
    const incoming=rows.filter(row=>row.direction==='in').reduce((sum,row)=>sum+row.amount_cents,0);
    let spendingComplete=true;
    if(selected.coverage?.spending){
      const review=selected.coverage.spending,unresolved=(review.review_rows??[]).filter(row=>row.date>=target.from&&row.date<=target.through);
      spendingComplete=unresolved.length===0;
      selected.coverage.spending={...review,complete:spendingComplete,review_rows:unresolved,
        unresolved_ids:[...new Set(unresolved.map(row=>row.id))],unresolved_gross_cents:unresolved.reduce((sum,row)=>sum+Math.abs(row.bank_amount_cents),0),known_cost_cents:cost};
    }
    selected.summary={...selected.summary,inflow_cents:available?incoming:null,spending_cents:available&&spendingComplete?cost:null,recorded_spending_cents:available?cost:null};
    selected.coverage={...selected.coverage,status:available?'partial':'unavailable',selected_range_covered:target.covered===true,selected_range_has_evidence:target.has_evidence===true};
    return {report:selected,budgets:structuredClone(budgets)};
  }catch{return null;}
}

/** Memory only; every edit/explicit refresh or source-scope change clears the cohort. */
export function createRangeSnapshotCache({now=()=>Date.now(),ttl=300000,maxEntries=8}={}){
  const snapshots=[];
  return {
    clear(){snapshots.length=0;},
    remember(cohort,report,budgets){
      snapshots.push({cohort,report:structuredClone(report),budgets:structuredClone(budgets),at:now()});
      if(snapshots.length>maxEntries)snapshots.splice(0,snapshots.length-maxEntries);
    },
    find(cohort,request){
      for(let i=snapshots.length-1;i>=0;i--){
        const item=snapshots[i],age=now()-item.at;
        if(item.cohort!==cohort||age<0||age>=ttl)continue;
        const result=deriveVisualPeriod(item.report,item.budgets,request);if(result)return result;
      }
      return null;
    }
  };
}
