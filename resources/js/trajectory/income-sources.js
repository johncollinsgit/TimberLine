/** Cash receipts stay gross; reviewed customer refunds are separate signed income evidence. */
export function incomeSourceSlices(income, mode) {
  return (income?.sources || []).filter(source => source.amount_cents > 0 && source.key !== 'transfers' && (mode === 'cash' || source.counts_as_income));
}

export function incomeReceiptsCaption(income, mode, precise) {
  if (mode === 'cash') return 'External cash received, including sales, borrowing, purchase refunds and deposits needing review. Internal transfers excluded.';
  const refunds = income?.customer_refunds_cents;
  const basis = refunds > 0 ? `Gross income receipts before ${precise(refunds)} in customer refunds; net recorded income ${precise(income.earned_income_cents)}.` : 'Recorded income receipts.';
  return `${basis} Borrowing, asset sales, purchase refunds and transfers excluded.`;
}

export function incomeSourcesHtml({income, panel, money, precise, esc, btn, empty}) {
  income ??= {earned_income_cents:0,sources:[]};
  const refunds = income.customer_refunds_cents;
  const breakdown = refunds > 0 ? `<p class="tr-subtle">${precise(income.gross_earned_income_cents)} income receipts − ${precise(refunds)} customer refunds = ${precise(income.earned_income_cents)} net recorded income.</p>` : '';
  return panel('Income by source', `<div class="tr-labels"><div><div class="tr-number">${money(income.earned_income_cents)}</div><p class="tr-subtle">Recorded income after customer refunds in this period.</p></div><span class="tr-pill">Cash sources separated</span></div>${breakdown}<div class="tr-card-list">${income.sources.map(source => `<div class="tr-row"><div><strong>${esc(source.name)}</strong><div class="tr-subtle">${esc(source.detail)} · ${source.count} record${source.count === 1 ? '' : 's'}</div></div><div class="tr-money">${precise(source.amount_cents)}<br>${btn('View records','income-source',`data-key="${esc(source.key)}"`)}</div></div>`).join('') || empty('Income sources appear after transactions are imported.')}</div><p class="tr-subtle">Customer refunds reduce income and remain separate from operating costs. Asset sales, borrowed cash, transfers, purchase refunds, and deposits needing a purpose do not inflate recorded income.</p>`);
}
