const roles=['name','category','amount','purpose','effective_on','source','reviewed','source_id'];
const purposes=['spending','savings','debt_payment','income'];

export function budgetMappingPayload(values,columns) {
  const column_mapping={};
  for(const role of roles){
    const column=values.get(`map_${role}`);
    if(column){if(!columns.includes(column))throw new Error('Choose columns from this CSV.');column_mapping[role]=column;}
  }
  for(const role of ['name','category','amount'])if(!column_mapping[role])throw new Error('Choose the name, category and planned amount columns.');
  if(values.get('planned_amounts')!=='on')throw new Error('Confirm that the selected amount column contains planned monthly targets.');
  const amount_units=values.get('amount_units');
  if(!['dollars','cents'].includes(amount_units))throw new Error('Choose dollars or integer cents for the planned amount.');
  const budget_defaults={amount_units,amount_basis:'planned',reviewed:values.get('default_reviewed')==='on'};
  for(const role of ['purpose','effective_on','source']){
    const value=String(values.get(`default_${role}`)||'').trim();
    if(value)budget_defaults[role]=value;
  }
  if(!column_mapping.purpose&&!purposes.includes(budget_defaults.purpose))throw new Error('Map a purpose column or choose a purpose for these rows.');
  if(!column_mapping.effective_on&&!budget_defaults.effective_on)throw new Error('Map an effective date column or supply the budget effective date.');
  return {column_mapping,budget_defaults};
}

export function budgetMappingHtml({preview,esc,field}) {
  const choices=[{value:'',label:'Not mapped'},...(preview.columns||[]).map(column=>({value:column,label:column}))];
  const labels={name:'Target name',category:'Source category or group',amount:'Planned monthly amount',purpose:'Purpose (optional if chosen below)',effective_on:'Effective date (optional if entered below)',source:'Source notes (optional)',reviewed:'Reviewed flag (optional)',source_id:'Stable source ID (recommended)'};
  return `<p class="tr-data-note">Choose the columns in your actual export. EveryDollar versions vary, so no column is guessed. Select planned monthly targets, not spent, remaining, transaction or received amounts.</p>
    <details class="tr-block" open><summary>Sample rows from this file</summary><div class="tr-table-wrap"><table class="tr-table"><thead><tr>${(preview.columns||[]).map(column=>`<th>${esc(column)}</th>`).join('')}</tr></thead><tbody>${(preview.sample||[]).map(row=>`<tr>${(preview.columns||[]).map(column=>`<td>${esc(String(row[column]??'').slice(0,200))}</td>`).join('')}</tr>`).join('')}</tbody></table></div></details>
    ${roles.map(role=>field(`map_${role}`,labels[role],'text','',choices)).join('')}
    ${field('amount_units','Planned amount units','text','',[{value:'',label:'Choose units'},{value:'dollars',label:'Dollars, e.g. 125.50'},{value:'cents',label:'Integer cents, e.g. 12550'}])}
    ${field('planned_amounts','The chosen amounts are planned monthly targets, not actual spending or remaining balances','checkbox',false)}
    <p class="tr-subtle">For fields without a mapped column, choose defaults below. A mapped purpose column must use spending, savings, debt_payment or income. Dates must use YYYY-MM-DD.</p>
    ${field('default_purpose','Default purpose','text','',[{value:'',label:'Choose if no purpose column'},...purposes.map(purpose=>({value:purpose,label:purpose.replaceAll('_',' ')}))])}
    ${field('default_effective_on','Default effective date','date','')}${field('default_source','Default source notes (optional)','text','')}
    ${field('default_reviewed','Mark rows without a mapped reviewed flag as reviewed targets; I have checked their amounts and purposes','checkbox',false)}
    <p class="tr-subtle">Targets remain plans. Savings and debt-payment purposes stay separate from spending. Unknown category names are mapped in the next step.</p>`;
}

export function budgetPreviewHtml({preview,categoryMap={},esc,precise}) {
  const rows=preview.preview||[];
  const categoryFor=row=>Object.hasOwn(categoryMap,row._source_category??row.category)?categoryMap[row._source_category??row.category]:row.category;
  return `<p class="tr-data-note">${rows.length} planned targets. Confirm names, exact amounts, purposes, dates and categories before saving. This import creates no transactions, income received or bank balances. Targets marked unreviewed need review before entering the monthly cost plan.</p>
    ${(preview.warnings||[]).map(warning=>`<p class="tr-subtle">${esc(warning)}</p>`).join('')}
    <p class="tr-subtle">Replaying the same unchanged source skips existing targets. Edited or archived targets and changed sources are protected; this import does not overwrite them.</p>
    <div class="tr-table-wrap"><table class="tr-table"><caption>All ${rows.length} targets in this preview · amounts in USD</caption><thead><tr><th>Name</th><th>Monthly planned</th><th>Purpose</th><th>Effective</th><th>Category</th><th>Review</th><th>Source reference</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.name)}</td><td>${precise(row.monthly_cents)}</td><td>${esc(row.purpose)}</td><td>${esc(row.effective_on)}</td><td>${esc(categoryFor(row))}${row._source_category&&row._source_category!==categoryFor(row)?`<br><small>From ${esc(row._source_category)}</small>`:''}</td><td>${row.reviewed?'Reviewed target':'Needs review'}${row._existing_record_id?'<br>Existing source; unchanged replay only':''}</td><td>${esc(row.source_id)}<br><small>${esc(row.source)}</small></td></tr>`).join('')}</tbody></table></div>`;
}
