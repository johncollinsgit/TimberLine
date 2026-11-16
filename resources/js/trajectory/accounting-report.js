/** Display reported cost sections without inferring a missing total or profit. */
export function accountingComponentsHtml({report,esc,precise}) {
  const fields=[['cogs_cents','Product costs (COGS)'],['expenses_cents','Expenses component'],['other_expenses_cents','Other expenses'],['other_income_cents','Other income']];
  const amount=value=>precise(Number.isSafeInteger(value)?value:null);
  return `<details class="tr-block"><summary>QuickBooks report components</summary><p class="tr-subtle">Product costs and other expenses are separate from the Expenses component. Reported net income also includes other income. These accounting amounts are separate from cash payments.</p><div class="tr-table-wrap"><table class="tr-table"><caption>Reported accounting components</caption><tbody>${fields.map(([key,label])=>`<tr><th scope="row">${esc(label)}</th><td>${amount(report?.[key])}</td></tr>`).join('')}</tbody></table></div></details>`;
}
