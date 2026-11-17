export function cardMoney(value,currency){
  if(!Number.isSafeInteger(value)||!/^[A-Z]{3}$/.test(currency||''))return 'Unavailable';
  try{return new Intl.NumberFormat('en-US',{style:'currency',currency,minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100);}catch{return 'Unavailable';}
}

export function cardActivityHtml(report,{esc,btn}){
  if(!report)return '';
  const shareLabel=report.space_kind==='household'?'Household share':'This space’s share';
  const cards=(report.cards||[]).map(card=>{
    const money=value=>cardMoney(value,card.currency),groups=card.groups||[],byKey=new Map(groups.map(row=>[row.key,row]));
    const known=card.posted_count>0&&card.currency;
    const amount=key=>byKey.has(key)?byKey.get(key).net_outlay_cents:known?0:null;
    const evidence=(label,row)=>row?.ids?.length?btn(label,'card-activity-evidence',`data-account-id="${card.account_id}" data-ids="${esc(JSON.stringify(row.ids))}"`):'';
    const metric=(label,key,invert=false)=>{const value=amount(key);return `<div><dt>${esc(label)}</dt><dd>${money(value===null?null:invert?-value:value)}</dd>${evidence(`View ${label.toLowerCase()}`,byKey.get(key))}</div>`;};
    const sourceLines=point=>(point?.sources||[]).map(source=>`<p class="tr-source">${esc(source.name)} · ${esc(source.observed_on)}<br>${esc(source.source)}</p>`).join('');
    const opening=card.opening,closing=card.closing,bridge=card.bridge;
    const boundaries=`<dl class="tr-card-boundaries"><div><dt>Opening evidence</dt><dd>${money(opening?.amount_cents)}</dd></div><div><dt>Balance at report cutoff</dt><dd>${money(closing?.amount_cents)}</dd></div></dl>${opening?.conflict||closing?.conflict?'<p class="tr-data-note">Balance evidence conflicts. Review the dated records before comparing debt.</p>':''}${sourceLines(opening)}${sourceLines(closing)}`;
    const change=card.jan1_balance_change_cents;
    const annual=card.from?.slice(5)==='01-01'&&card.from.slice(0,4)===card.through?.slice(0,4);
    const comparison=annual?(Number.isSafeInteger(change)?`<p class="tr-card-change">January 1 opening evidence <strong>${money(opening.amount_cents)}</strong>. <strong>${money(Math.abs(change))} ${change<0?'lower':change>0?'higher':'change'} since January 1</strong> through ${esc(card.through)}.</p>`:'<p class="tr-card-change">January 1 comparison unavailable: consistent opening and report-cutoff evidence is needed.</p>'):'';
    const equation=bridge?.reconciles?`<p class="tr-data-note">Recorded charges and credits reconcile to the dated balances. History coverage may still be partial.</p><p class="tr-card-equation">${money(opening.amount_cents)} opening + ${money(card.recorded_charges_cents)} charges − ${money(card.recorded_credits_cents)} credits = ${money(closing.amount_cents)}</p>`:Number.isSafeInteger(bridge?.difference_cents)?`<p class="tr-data-note">Recorded activity does not reconcile to the dated balances. Difference: ${money(bridge.difference_cents)}. Review missing history or adjustments.</p>`:'<p class="tr-subtle">A balance equation is unavailable without matching dated boundaries and usable posted records.</p>';
    return `<article class="tr-card-account" data-card-account="${card.account_id}"><header><div><h3>${esc(card.name)}</h3><p class="tr-subtle">${esc(card.currency||'Currency unavailable')} · ${esc(card.from)} – ${esc(card.through)}</p></div><div class="tr-card-latest"><span>Latest recorded balance</span><strong>${money(card.latest_balance?.amount_cents)}</strong><small>As of ${esc(card.latest_balance?.observed_on||'not observed')}</small></div></header>
      ${card.period_available===false?'<p class="tr-data-note">This report period is in the future. Actual card activity and period balances are unavailable; the latest balance above is a separate dated observation.</p>':comparison}
      ${card.period_available===false?'':card.posted_count?`<p class="tr-card-physical">Full card charges ${money(card.recorded_charges_cents)} · full card credits ${money(card.recorded_credits_cents)}.</p><dl class="tr-card-figures">${metric('Other card purchases, net','purchases')}${metric('Metals net outlay','metals')}${metric('Net repayments','payments',true)}</dl>`:'<p class="tr-empty">No posted card activity is recorded in these dates. This does not establish zero spending or zero pending charges.</p>'}
      ${card.review_count?`<p class="tr-data-note">${card.review_count} posted record(s) need purpose review. Their amounts remain in the breakdown; they are not verified purchases or repayments.</p>`:''}
      ${byKey.has('purchases')?`<p class="tr-card-share">${esc(shareLabel)} of classified other purchases: <strong>${money(card.purchase_allocation?.space_share_cents)}</strong>. Allocated outside this space: <strong>${money(card.purchase_allocation?.outside_share_cents)}</strong>. These shares are already within the card amount.</p>`:''}
      <details class="tr-card-detail"><summary>Breakdown and balance comparison</summary>
        <p class="tr-subtle">Reviewed purpose groups are separate from physical card totals. Credits reduce the recorded card balance; they are not earned income.</p>
        ${groups.length?`<div class="tr-table-wrap" tabindex="0"><table class="tr-table"><caption>Posted card source amounts · ${esc(card.currency||'unknown currency')}</caption><thead><tr><th>Purpose</th><th>Charges</th><th>Credits</th><th>Records</th></tr></thead><tbody>${groups.map(row=>`<tr><th scope="row">${esc(row.label)}${row.key==='duplicate'?' · excluded':''}</th><td>${money(row.debits_cents)}</td><td>${money(row.credits_cents)}</td><td>${evidence('View records',row)}</td></tr>`).join('')}</tbody></table></div>`:''}
        ${boundaries}${equation}${(card.notes||[]).map(note=>`<p class="tr-subtle">${esc(note)}</p>`).join('')}
      </details></article>`;
  }).join('');
  return `<section class="tr-panel tr-card-activity" aria-labelledby="tr-card-activity-title"><header class="tr-panel-head"><h2 id="tr-card-activity-title">Credit card activity</h2></header><p class="tr-subtle">Selected-period posted records, one card at a time. Pending activity may be missing. Card charges and cash repayments are separate views of money.</p>${cards||'<p class="tr-empty">No owned credit cards in this account scope. Choose all accounts or an owned credit card.</p>'}</section>`;
}
