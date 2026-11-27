/** Move the actual report controls between the toolbar and the compact options sheet. */
export function initReportControls(root) {
  const doc = root.ownerDocument;
  const compact = doc.defaultView.matchMedia('(max-width: 900px)');
  const find = selector => root.querySelector(selector);
  const panel = find('#tr-report-controls');
  const trigger = find('#tr-date-toggle');
  const done = find('#tr-date-close');
  const options = find('#tr-report-options');
  const optionsToggle = find('#tr-options-toggle');
  const actions = find('#tr-mobile-top-actions');
  const context = find('#tr-context-controls');
  const analysis = find('#tr-analysis-controls');
  const grouping = find('#tr-grouping-controls');
  const caption = find('#tr-mobile-period-label');
  const content = find('#tr-content');
  const range = find('#tr-range');
  const positions = new Map();
  const headerActions = [find('[data-action="vf-inbox"]'), find('#tr-add'), trigger];
  const scope = find('#tr-space-tabs');
  let sheetOpen = false;
  let optionsOpen = false;

  function move(node, destination) {
    if (node.parentNode === destination) return;
    if (!positions.has(node)) {
      const marker = doc.createComment('report control');
      node.before(marker);
      positions.set(node, marker);
    }
    destination.append(node);
  }

  function restore(node) {
    const marker = positions.get(node);
    if (!marker) return;
    if (marker.isConnected) marker.replaceWith(node);
    else node.remove();
    positions.delete(node);
  }

  function updateCaption() {
    const dates = find('#tr-context').textContent.split(' · ')[0];
    const company = find('#tr-company-label').hidden ? '' : find('#tr-company').selectedOptions[0]?.textContent.trim();
    caption.textContent = company ? `${company} · ${dates}` : dates;
    caption.title = caption.textContent;
  }

  function visibility() {
    const phone = compact.matches;
    panel.hidden = phone && !sheetOpen;
    panel.toggleAttribute('data-open', phone && sheetOpen);
    options.hidden = !phone && !optionsOpen;
    optionsToggle.hidden = phone;
    trigger.setAttribute('aria-expanded', String(phone && sheetOpen));
    optionsToggle.setAttribute('aria-expanded', String(!phone && optionsOpen));
    if (phone) {
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-labelledby', 'tr-report-controls-title');
    } else {
      panel.removeAttribute('role');
      panel.removeAttribute('aria-labelledby');
    }
  }

  function sync() {
    const focus = doc.activeElement;
    const ownedFocus = panel.contains(focus) || headerActions.includes(focus) || scope.contains(focus);
    // A report render replaces its source content. Discard the previous controls
    // along with their source markers before mounting the new report's inputs.
    for (const [node, marker] of positions) if (!marker.isConnected) restore(node);
    const controls = content.querySelector('.vf-controls');
    if (controls) move(controls, analysis);
    const interval = analysis.querySelector('.vf-segmented');
    if (interval) move(interval, grouping);
    grouping.hidden = !grouping.querySelector('.vf-segmented');

    const budgetControls = '.vf-section-heading>label, .vf-section-heading>[data-action="vf-legacy-budget"], .vf-section-heading>[data-action="vf-definitions"]';
    if (compact.matches) {
      for (const action of headerActions) move(action, actions);
      move(scope, context);
      for (const node of content.querySelectorAll(budgetControls)) move(node, analysis);
    } else {
      for (const node of [...headerActions, scope]) restore(node);
      for (const node of [...analysis.children]) if (!node.matches('.vf-controls')) restore(node);
    }
    visibility();
    updateCaption();
    if (!ownedFocus || doc.activeElement === focus) return;
    // Keep keyboard focus on the corresponding picker when report data arrives.
    const key = ['vfInput','grain','action'].find(key => focus.dataset[key]);
    const replacement = focus.isConnected ? focus : key && [...panel.querySelectorAll('select,button')].find(node => node.dataset[key] === focus.dataset[key]);
    if (replacement?.getClientRects().length) replacement.focus({preventScroll:true});
    else if (compact.matches && !sheetOpen) trigger.focus({preventScroll:true});
  }

  function close(returnFocus = true) {
    if (!sheetOpen && !optionsOpen) return;
    const opener = sheetOpen ? trigger : optionsToggle;
    sheetOpen = false;
    optionsOpen = false;
    visibility();
    if (returnFocus) opener.focus({preventScroll:true});
  }

  function toggleSheet() {
    if (sheetOpen) {close(); return;}
    sheetOpen = true;
    visibility();
    range.focus({preventScroll:true});
  }
  function toggleOptions() {
    optionsOpen = !optionsOpen;
    visibility();
  }
  function escape(event) {
    if ((sheetOpen || optionsOpen) && event.key === 'Escape' && !event.defaultPrevented) {
      event.preventDefault();
      close();
    }
  }
  function outside(event) {
    if (sheetOpen && !panel.contains(event.target) && !trigger.contains(event.target)) close(false);
    else if (optionsOpen && !options.contains(event.target) && !optionsToggle.contains(event.target)) close(false);
  }
  function choose(event) {
    if (sheetOpen && event.target.closest('[data-scope],#tr-refresh')) close();
  }
  function setRange(event) {
    if (sheetOpen && event.target === range && range.value !== 'custom') close();
  }
  function resize() {close(false); sync();}
  function finish() {close();}
  const observer = new doc.defaultView.MutationObserver(updateCaption);
  observer.observe(find('#tr-context'), {childList:true, subtree:true, characterData:true});
  const bindings = [
    [trigger, 'click', toggleSheet], [done, 'click', finish], [optionsToggle, 'click', toggleOptions],
    [doc, 'keydown', escape], [doc, 'pointerdown', outside], [doc, 'focusin', outside],
    [panel, 'click', choose], [panel, 'change', setRange], [compact, 'change', resize],
  ];
  for (const [node, type, handler] of bindings) node.addEventListener(type, handler);
  sync();
  return {sync, close, destroy() {
    for (const [node, type, handler] of bindings) node.removeEventListener(type, handler);
    observer.disconnect();
    close(false);
    for (const node of positions.keys()) restore(node);
    panel.hidden = false;
  }};
}
