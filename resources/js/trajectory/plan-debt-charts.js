import {barChart, ringChart} from './plan-chart-ui.js';

const balancesId = 'debt-balances';
const ratesId = 'debt-rates';
const colors = ['#137064','#6267a7','#c5854b','#437a9b','#92728b','#738448','#b4665f','#638e89'];
const usableBalance = row => typeof row.id === 'string' && row.id.length > 0 && row.currency === 'USD'
  && ['current','dated','recorded'].includes(row.balance_status) && Number.isSafeInteger(row.balance_cents) && row.balance_cents > 0;
const usableRate = row => ['confirmed','reported'].includes(row.rate_status)
  && Number.isSafeInteger(row.rate_millis) && row.rate_millis >= 0 && row.rate_millis <= 100000
  && (row.rate_millis > 0 || row.rate_status === 'confirmed');

export function debtChartAccount(report, chart, key) {
  if (!report || ![balancesId,ratesId].includes(chart)) return null;
  const row = report.inventory?.find(item => item.id === key && usableBalance(item));
  return row && (chart === balancesId || usableRate(row)) ? row : null;
}

export function renderDebtCharts(report, {esc, cash, rate}) {
  if (!report?.inventory?.length) return '';
  const accounts = report.inventory.filter(usableBalance).sort((a,b) => b.balance_cents - a.balance_cents || a.name.localeCompare(b.name));
  if (!accounts.length) return '';
  const rows = accounts.map((account,index) => ({
    key:account.id, label:account.name, value:account.balance_cents, color:colors[index % colors.length],
  }));
  const rates = accounts.flatMap((account,index) => usableRate(account) ? [{
    ...rows[index], value:account.rate_millis, display:rate(account.rate_millis) + (account.rate_status === 'reported' ? ' · reported' : ''),
  }] : []).sort((a,b) => b.value - a.value || a.label.localeCompare(b.label));
  const count = report.balance_review?.length ?? 0;
  const balanceNote = 'Balances use the latest recorded amount for each account.'
    + (count ? ' ' + count + (count === 1 ? ' additional balance needs review.' : ' additional balances need review.') : '');
  const ratesMissing = rows.length - rates.length;
  const rateNote = ratesMissing ? (ratesMissing === 1 ? 'One balance has' : ratesMissing + ' balances have') + ' no recorded APR.' : '';
  const composition = ringChart({id:balancesId, title:'Debt by balance', note:balanceNote, rows, format:cash, esc});
  const comparison = rates.length ? barChart({id:ratesId, title:'Annual interest rates', note:rateNote, rows:rates, format:rate, esc}) : '';
  return composition + comparison;
}
