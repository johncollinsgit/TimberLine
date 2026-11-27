const palette = ['#197b70', '#5b70c2', '#d1873b', '#a85e86', '#417fa7', '#8e77ad', '#719048', '#bc654e', '#568b97'];
const dialogs = new WeakMap();
const amount = Number.isSafeInteger;

export function initPlanChartDetails(root, dialog) {
  dialogs.set(root, dialog);
}

export function openChartDetail(button, {title, body}) {
  const show = dialogs.get(button.closest('#trajectory'));
  if (!show || !button.isConnected) return;
  // Use the same focus trap, Escape handling and dismissal as other report details.
  button.focus({preventScroll:true});
  show(title, '<div class="pt-chart-detail">' + body + '</div>', null);
}

function normalizeRows(rows, format) {
  return rows.map((row, index) => ({...row,
    color:/^#[0-9a-f]{6}$/i.test(row.color ?? '') ? row.color : palette[index % palette.length],
    display:row.display ?? (amount(row.value) ? format(row.value) : 'Unavailable'),
  }));
}

function action(id, row, esc) {
  return `type="button" data-action="pt-chart-detail" data-chart="${esc(id)}" data-key="${esc(row.key)}" aria-haspopup="dialog"`;
}

function frame(id, title, note, contents, esc) {
  return `<section class="pt-chart-card" data-pt-chart="${esc(id)}" aria-label="${esc(title)}">
    <header class="pt-chart-heading"><h2>${esc(title)}</h2>${note ? `<p>${esc(note)}</p>` : ''}</header>${contents}</section>`;
}

// Intersect a ray with the enclosing square. The circular frame clips the outer
// edge, giving each native button the same hit area as its visible ring segment.
function perimeter(turn) {
  const angle = turn * 2 * Math.PI, x = Math.sin(angle), y = -Math.cos(angle);
  const scale = 50 / Math.max(Math.abs(x),Math.abs(y));
  return (50+x*scale).toFixed(5) + '% ' + (50+y*scale).toFixed(5) + '%';
}

function wedge(from, through) {
  const turns = [from, ...[1/8,3/8,5/8,7/8].filter(corner => from < corner && corner < through), through];
  return 'polygon(50% 50%, ' + turns.map(perimeter).join(', ') + ')';
}

export function ringChart({id, title, rows, format, esc, note='', totalLabel='Total'}) {
  const data = normalizeRows(rows,format);
  const complete = data.length > 0 && data.every(r => amount(r.value) && r.value >= 0);
  const sum = complete ? data.reduce((n,r) => n + r.value,0) : null;
  const total = amount(sum) ? sum : null;
  let cumulative = 0;
  const sectors = total > 0 ? data.filter(r => r.value > 0).map(r => {
    const start = cumulative;
    cumulative += r.value;
    return `<button class="pt-ring-segment" ${action(id,r,esc)} tabindex="-1" aria-label="${esc(r.label + ', ' + r.display)}" title="${esc(r.label + ' · ' + r.display)}"
      style="--pt-color:${r.color};clip-path:${wedge(start/total,cumulative/total)}"></button>`;
  }).join('') : '';
  const legend = data.map(r => `<li><button class="pt-chart-legend-button" ${action(id,r,esc)}>
      <i style="--pt-color:${r.color}" aria-hidden="true"></i><span>${esc(r.label)}</span><strong>${esc(r.display)}</strong></button></li>`).join('');
  const ring = `<div class="pt-ring"><div class="pt-ring-marks">${sectors}</div><div class="pt-ring-center"><span>${esc(totalLabel)}</span>
    <strong>${esc(total === null ? 'Unavailable' : format(total))}</strong></div></div>`;
  return frame(id,title,note,`<div class="pt-ring-layout">${ring}<ul class="pt-chart-legend">${legend}</ul></div>`,esc);
}

export function barChart({id, title, rows, format, esc, note=''}) {
  const data = normalizeRows(rows,format);
  const known = data.map(r => r.value).filter(amount);
  const min = Math.min(0,...known), max = Math.max(0,...known), span = max-min;
  const zero = span ? -min/span*100 : 0;
  const bars = data.map(r => {
    const known = amount(r.value);
    const left = known && r.value < 0 ? (r.value-min)/span*100 : zero;
    const width = known && span ? Math.abs(r.value)/span*100 : 0;
    return `<li><button class="pt-chart-bar" ${action(id,r,esc)}>
      <span class="pt-bar-caption"><span>${esc(r.label)}</span><strong>${esc(r.display)}</strong></span>
      <span class="pt-bar-track${known ? '' : ' pt-bar-missing'}" aria-hidden="true">${min < 0 ? `<i class="pt-bar-axis" style="left:${zero}%"></i>` : ''}
      ${known ? `<i class="pt-bar-fill" style="--pt-color:${r.color};left:${left}%;width:${width}%"></i>` : ''}</span></button></li>`;
  }).join('');
  return frame(id,title,note,`<ul class="pt-chart-bars">${bars}</ul>`,esc);
}
