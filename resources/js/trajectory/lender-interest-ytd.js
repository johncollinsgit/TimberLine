import {cardMoney} from './card-activity.js';

export function lenderInterestYtdHtml(report,{esc,selectedYear=null}){
  if(!report)return '';
  const years=report.years||[],year=years.find(row=>row.year===Number(selectedYear))||years.find(row=>row.year===report.default_year),rows=year?.rows||[];
  const heading='<header class="tr-panel-head"><h2>Lender-reported annual interest</h2></header>';
  if(report.available===false)return `<section class="tr-panel tr-lender-ytd">${heading}<p class="tr-data-note">This report period is in the future. Actual lender totals are unavailable.</p></section>`;
  const sources=row=>(row.sources||[]).length?`<details class="tr-lender-source"><summary>Source${row.sources.length>1?'s':''}</summary>${row.sources.map(s=>`<p>${esc(s.name)}<br>${esc(s.source)}<br>Verified ${esc(s.observed_on)}</p>`).join('')}</details>`:'';
  return `<section class="tr-panel tr-lender-ytd" aria-label="Lender-reported annual interest">${heading}
    <div class="tr-actions"><label for="tr-lender-ytd-year">Calendar year</label><select id="tr-lender-ytd-year">${years.map(y=>`<option value="${esc(y.year)}" ${y.year===year?.year?'selected':''}>${esc(y.year)}</option>`).join('')}</select></div>
    <p class="tr-subtle">${esc(report.note)} Report cutoff: ${esc(report.cutoff)}.</p>
    ${rows.length?`<div class="tr-table-wrap"><table class="tr-table"><caption>${esc(year.year)} cumulative interest · each lender’s statement date</caption><thead><tr><th>Card and source</th><th>Interest charged</th><th>Statement through</th></tr></thead><tbody>${rows.map(row=>`<tr><th scope="row">${esc(row.account)}${sources(row)}</th><td>${cardMoney(row.interest_charged_cents,row.currency)}${row.issue?`<div class="tr-subtle">${esc(row.issue)}</div>`:''}</td><td>${esc(row.reported_through||'Unavailable')}</td></tr>`).join('')}</tbody></table></div>
    <p class="tr-lender-coverage">${esc(year.reported_accounts)} of ${esc(year.owned_accounts)} owned credit accounts have usable lender totals.${year.different_currencies?' Different currencies; no subtotal.':year.reported_subset_cents!==null?` Reported subset: <strong>${cardMoney(year.reported_subset_cents,year.currency)}</strong>${year.different_cutoffs?' · different statement cutoffs':''}.`:''} This is not a complete business or household interest total.</p>`:'<p class="tr-empty">No owned credit accounts in this account scope.</p>'}
  </section>`;
}
