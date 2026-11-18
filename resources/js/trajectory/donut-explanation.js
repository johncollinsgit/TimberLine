import './donut-explanation.css';
import {donutSliceSummary} from './donut-explanation-data.js';

let activeClose = null;
let nextId = 0;

/** Explanations use the authorized chart snapshot; only an explicit action drills down. */
export function createDonutExplanation({canvas, formatMoney, title, context = {}, onSelect, isCurrent = () => true}) {
  let panel = null, destroyed = false, previousFocus = null;
  const current = () => {
    try { return !destroyed && canvas.isConnected && isCurrent(); } catch { return false; }
  };
  function close({restoreFocus = true} = {}) {
    if (!panel) return;
    const node = panel; panel = null;
    if (activeClose === close) activeClose = null;
    if (node.open) node.close();
    node.remove();
    if (restoreFocus && current()) {
      const target = previousFocus?.isConnected && previousFocus !== document.body ? previousFocus : canvas;
      if (target === canvas && !canvas.hasAttribute('tabindex')) canvas.tabIndex = 0;
      target.focus({preventScroll: true});
    }
  }
  const pop = () => close({restoreFocus: false});
  window.addEventListener('popstate', pop);
  function text(parent, tag, value, className = '') {
    const node = document.createElement(tag); node.textContent = String(value ?? '');
    if (className) node.className = className;
    parent.append(node); return node;
  }
  function onClick(event, elements, chart) {
    if (!current()) return;
    const selected = donutSliceSummary(chart, elements?.[0]);
    if (!selected) return;
    activeClose?.({restoreFocus: false});
    previousFocus = document.activeElement;
    const node = document.createElement('dialog'); panel = node; activeClose = close;
    node.className = 'tr-donut-explanation';
    const headingId = `tr-donut-explanation-${++nextId}`;
    node.setAttribute('aria-labelledby', headingId);
    const header = document.createElement('header'); node.append(header);
    text(header, 'p', title || 'Chart explanation', 'tr-donut-explanation-chart');
    const dismiss = text(header, 'button', '×'); dismiss.type = 'button';
    dismiss.setAttribute('aria-label', 'Close explanation'); dismiss.onclick = () => close();
    text(node, 'h2', selected.label).id = headingId;
    text(node, 'strong', formatMoney(selected.amount), 'tr-donut-explanation-amount');
    text(node, 'p', `${new Intl.NumberFormat('en-US', {style: 'percent', maximumFractionDigits: 1}).format(selected.share)} of the visible slices in this chart.`, 'tr-donut-explanation-share');
    const description = context.describe?.(selected.index, selected.datasetIndex) || context.description ||
      `This slice shows the recorded amount grouped under “${selected.label}” in this chart. It uses the same period and financial view as the chart you selected.`;
    text(node, 'p', description, 'tr-donut-explanation-description');
    const details = document.createElement('dl');
    for (const [label, value] of [['Period', context.period], ['Financial view', context.basis], ['Source', context.source]]) {
      if (!value) continue;
      const row = document.createElement('div'); text(row, 'dt', label); text(row, 'dd', value); details.append(row);
    }
    if (details.children.length) node.append(details);
    const footer = document.createElement('footer'); node.append(footer);
    if (typeof onSelect === 'function') {
      const entries = text(footer, 'button', 'View recorded entries', 'tr-donut-explanation-entries'); entries.type = 'button';
      entries.onclick = () => {
        if (!current()) { close({restoreFocus: false}); return; }
        close({restoreFocus: false});
        onSelect(selected.index, selected.datasetIndex);
      };
    } else text(footer, 'p', 'No recorded-entry drilldown is available for this chart.', 'tr-donut-explanation-note');
    const done = text(footer, 'button', 'Back to chart'); done.type = 'button'; done.onclick = () => close();
    node.addEventListener('cancel', e => {e.preventDefault(); close();});
    node.addEventListener('close', () => close());
    node.addEventListener('click', e => {
      if (e.target !== node) return;
      const b = node.getBoundingClientRect();
      if (e.clientX < b.left || e.clientX > b.right || e.clientY < b.top || e.clientY > b.bottom) close();
    });
    document.body.append(node); node.showModal(); dismiss.focus({preventScroll: true});
  }
  return {onClick, plugin: {id: 'trajectoryDonutExplanation', afterDestroy() {
    destroyed = true; close({restoreFocus: false}); window.removeEventListener('popstate', pop);
  }}};
}
