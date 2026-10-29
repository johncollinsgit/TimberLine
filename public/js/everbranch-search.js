/* Everbranch search v1. Shared by Teams and the standalone campus demo. No dependencies. */
(function (global) {
  'use strict';
  const normalize = value => String(value ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
  const words = value => normalize(value).split(' ').filter(Boolean);
  function parse(value) {
    const raw = String(value || '').trim().slice(0, 500), commandsOnly = raw.startsWith('>');
    const text = commandsOnly ? raw.slice(1).trim() : raw;
    const phrases = [...text.matchAll(/"([^"]+)"/g)].map(match => normalize(match[1])).filter(Boolean);
    return { text: normalize(text.replace(/"([^"]+)"/g, '$1')), terms: words(text), phrases, commandsOnly };
  }
  function distance(a, b, max) {
    a = [...a]; b = [...b];
    if (Math.abs(a.length - b.length) > max) return max + 1;
    let previous = Array.from({ length: b.length + 1 }, (_, i) => i), beforePrevious;
    for (let i = 1; i <= a.length; i++) {
      const row = [i];
      for (let j = 1; j <= b.length; j++) {
        row[j] = Math.min(row[j - 1] + 1, previous[j] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) row[j] = Math.min(row[j], beforePrevious[j - 2] + 1);
      }
      beforePrevious = previous; previous = row;
    }
    return previous[b.length];
  }
  const prepared = new WeakMap();
  function prepare(document) {
    const signature = [document.title, document.body, document.identifier, document.id, document.updated_at].join('\u0000');
    let cached = prepared.get(document);
    if (!cached || cached.signature !== signature) {
      const title = normalize(document.title), body = normalize(document.body), id = normalize(document.identifier || document.id);
      cached = { signature, title, body, id, titleWords: title.split(' ').filter(Boolean), allWords: (title + ' ' + body).split(' ').filter(Boolean), idWords: id.split(' ').filter(Boolean), updated: -(Date.parse(document.updated_at || '') || 0) };
      prepared.set(document, cached);
    }
    return cached;
  }
  function score(document, input) {
    const query = typeof input === 'string' ? parse(input) : input;
    if (query.commandsOnly && document.type !== 'command') return null;
    const { title, body, id, titleWords, allWords, idWords, updated } = prepare(document);
    const combined = title + ' ' + body;
    const compactId = id.replace(/ /g, ''), compactQuery = query.text.replace(/ /g, '');
    if (compactQuery && compactId === compactQuery) return [0, 0, document.completed || document.archived ? 1 : 0, updated, String(document.id)];
    if (!query.phrases.every(phrase => combined.includes(phrase))) return null;
    if (!query.terms.length) return [0, 0, 0, 0, String(document.id)];
    let typos = 0, bodyMatches = 0;
    for (let i = 0; i < query.terms.length; i++) {
      const term = query.terms[i], last = i === query.terms.length - 1;
      const match = list => list.some(word => word === term || (last && word.startsWith(term)));
      if (match(titleWords) || match(idWords) || (query.terms.length === 1 && compactId.startsWith(compactQuery))) continue;
      if (match(allWords)) { bodyMatches++; continue; }
      // Never fuzzy-match identifiers. Only natural-language fields participate.
      const length = [...term].length, allowed = length >= 9 ? 2 : length >= 5 ? 1 : 0;
      if (!allowed) return null;
      let best = allowed + 1, inTitle = false;
      for (const word of allWords) {
        const variants = last ? [word, ...[length - 1, length, length + 1].filter(n => n > 0 && n < [...word].length).map(n => [...word].slice(0, n).join(''))] : [word];
        const edit = Math.min(...variants.map(value => distance(term, value, allowed)));
        if (edit < best) { best = edit; inTitle = titleWords.includes(word); }
      }
      if (best > allowed) return null;
      typos += best; if (!inTitle) bodyMatches++;
    }
    const compact = text => text.replace(/ /g, '');
    const tier = compact(id) === compact(query.text) ? 0 : title === query.text ? 1 : title.startsWith(query.text) ? 2 : !bodyMatches ? 3 : 4;
    return [tier, typos, document.completed || document.archived ? 1 : 0, updated, String(document.id)];
  }
  const compare = (a, b) => { for (let i = 0; i < a.length; i++) { if (a[i] < b[i]) return -1; if (a[i] > b[i]) return 1; } return 0; };
  const labelFor = value => String(value || '').replace(/[_-]/g, ' ').replace(/^./, letter => letter.toUpperCase());
  function search(documents, input, filters = {}) {
    const query = parse(input), seen = new Set();
    return documents.filter(doc => {
      if (seen.has(doc.id)) return false;
      seen.add(doc.id);
      return (!filters.type || doc.type === filters.type) && (!filters.project || (doc.projects || []).includes(filters.project)) && (!filters.person || (doc.people || []).includes(filters.person)) && (filters.state !== 'active' || !(doc.completed || doc.archived)) && (filters.state !== 'closed' || doc.completed || doc.archived);
    }).map(document => ({ document, rank: score(document, query) })).filter(row => row.rank).sort((a, b) => compare(a.rank, b.rank)).map(row => row.document);
  }
  function element(tag, text, className) { const node = document.createElement(tag); if (text != null) node.textContent = text; if (className) node.className = className; return node; }
  function highlight(node, text, input) {
    node.replaceChildren();
    const terms = parse(input).terms, source = String(text || '');
    // Render plain text nodes and marks only, including untrusted source content.
    for (const token of source.split(/(\s+)/)) {
      const normalized = normalize(token);
      node.append(normalized && terms.some(term => normalized.includes(term)) ? element('mark', token) : document.createTextNode(token));
    }
  }
  function palette(options) {
    const host = options.host, returnTarget = () => options.returnFocus?.() || input;
    host.classList.add('eb-search');
    const form = element('form'), label = element('label', 'Search workspace or run a command', 'eb-sr'), input = element('input'), shortcut = element('kbd', /Mac|iPhone|iPad/.test(global.navigator?.platform || '') ? '⌘ K' : 'Ctrl K');
    const uid = options.id || 'eb-global';
    input.id = uid + '-query'; label.htmlFor = input.id;
    input.type = 'search'; input.maxLength = 500; input.placeholder = 'Search workspace or run a command…'; input.autocomplete = 'off'; input.spellcheck = false;
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', uid + '-list'); input.setAttribute('aria-haspopup', 'listbox');
    form.setAttribute('role', 'search'); form.append(label, input, shortcut);
    const panel = element('section', null, 'eb-search-panel'), head = element('div', null, 'eb-search-head'), heading = element('strong', 'Search workspace'), close = element('button', 'Close', 'eb-search-close');
    close.type = 'button'; close.setAttribute('aria-label', 'Close workspace search');
    head.append(heading, close);
    const filters = element('div', null, 'eb-search-filters'), selects = {};
    for (const [key, title, items] of [['type', 'All types', []], ['project', 'All projects / areas', []], ['person', 'Anyone', []], ['state', 'All work', [{ id: 'active', title: 'Active work' }, { id: 'closed', title: 'Completed / archived' }]]]) {
      const select = element('select'); select.setAttribute('aria-label', title); select.append(new Option(title, ''));
      for (const item of items) select.append(new Option(item.title, item.id));
      select.onchange = () => run(); selects[key] = select; filters.append(select);
    }
    const status = element('p', '', 'eb-search-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    const list = element('div', null, 'eb-search-list'); list.id = uid + '-list'; list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', 'Workspace search results');
    const more = element('button', 'View all results', 'eb-search-more'); more.type = 'button';
    const footer = element('div', '↑ ↓ Navigate   ↵ Open   Esc Close   > Commands', 'eb-search-footer');
    panel.append(head, filters, status, list, more, footer); panel.hidden = true; host.replaceChildren(form, panel);
    let serial = 0, controller, timer, rows = [], active = -1, cursor = null, expanded = false, scope = '', recent = [], opened = false, composing = false, bodyOverflow;
    const selectedFilters = () => Object.fromEntries(Object.entries(selects).map(([key, select]) => [key, select.value]));
    const recentKey = () => 'everbranch-search-v1:' + scope;
    function changeScope(next) {
      if (scope === next) return;
      serial++; controller?.abort(); closePanel(false); scope = next; input.value = ''; recent = []; rows = []; list.replaceChildren();
      Object.values(selects).forEach(select => { select.value = ''; });
      try { const stored = JSON.parse(sessionStorage.getItem(recentKey()) || '[]'); recent = Array.isArray(stored) ? stored.filter(id => typeof id === 'string').slice(0, 8) : []; } catch { /* Session storage is optional. */ }
    }
    function choose(index) { active = index; [...list.children].forEach((node, i) => node.setAttribute('aria-selected', String(i === active))); if (active >= 0 && list.children[active]) { input.setAttribute('aria-activedescendant', list.children[active].id); list.children[active].scrollIntoView({ block: 'nearest' }); } else input.removeAttribute('aria-activedescendant'); }
    function closePanel(focus = true) { opened = false; panel.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); host.removeAttribute('role'); host.removeAttribute('aria-modal'); if (bodyOverflow !== undefined) { document.body.style.overflow = bodyOverflow; bodyOverflow = undefined; } serial++; controller?.abort(); clearTimeout(timer); if (focus) { input.onfocus = null; returnTarget()?.focus({ preventScroll: true }); input.onfocus = () => { if (!opened) show(); }; } }
    function expand() {
      opened = true; panel.hidden = false; input.setAttribute('aria-expanded', 'true');
      if (global.matchMedia?.('(max-width: 650px)').matches) { host.setAttribute('role', 'dialog'); host.setAttribute('aria-modal', 'true'); host.setAttribute('aria-label', 'Search workspace'); if (bodyOverflow === undefined) bodyOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; }
    }
    function show() { if (options.available?.() === false) return; options.beforeOpen?.(); expand(); run(); }
    async function activate(index) {
      const row = rows[index]; if (!row) return;
      const currentScope = scope, ticket = serial;
      try {
        const accepted = await options.open(row, () => scope === currentScope && ticket === serial && options.available?.() !== false);
        if (scope !== currentScope || ticket !== serial || accepted === false) return;
        if (row.type !== 'command') { recent = [row.id, ...recent.filter(id => id !== row.id)].slice(0, 8); try { sessionStorage.setItem(recentKey(), JSON.stringify(recent)); } catch { /* Optional. */ } }
        closePanel(false);
      } catch (error) { status.textContent = error.message || 'This item could not be opened. Search again.'; }
    }
    function facets(data) {
      for (const key of ['type', 'project', 'person']) {
        if (!data?.[key]) continue;
        const select = selects[key], value = select.value;
        const previousLabel = select.selectedOptions[0]?.textContent;
        select.replaceChildren(select.options[0]);
        for (const item of data[key]) select.append(new Option(key === 'type' ? labelFor(item.title) : item.title, item.id));
        if (key === 'type' && value && ![...select.options].some(option => option.value === value)) select.append(new Option(previousLabel || labelFor(value), value));
        select.value = value; if (select.value !== value) select.value = '';
      }
    }
    function paint(result, append) {
      const previousActive = active;
      rows = append ? [...rows, ...(result.items || [])] : (result.items || []);
      cursor = result.next_cursor || null; facets(result.facets);
      list.replaceChildren(); active = -1; input.removeAttribute('aria-activedescendant');
      for (const [i, row] of rows.entries()) {
        const option = element('div', null, 'eb-search-option'); option.id = uid + '-result-' + i; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
        const badge = element('span', row.type === 'command' ? '⌘' : ({ task: '✓', project: '▦', file: '▤', person: '◉', request: '◇', job: '⚒' }[row.type] || '◈'), 'eb-search-icon'); badge.setAttribute('aria-hidden', 'true');
        const text = element('div', null, 'eb-search-copy'), title = element('strong'); highlight(title, row.title, input.value);
        const context = element('small', [labelFor(row.type), row.context, labelFor(row.status), row.source, row.synced_at ? 'Synced ' + new Date(row.synced_at).toLocaleString() : ''].filter(Boolean).join(' · '));
        text.append(title, context);
        if (row.snippet || row.body) { const snippet = element('span', null, 'eb-search-snippet'); highlight(snippet, row.snippet || row.body.slice(0, 180), input.value); text.append(snippet); }
        option.append(badge, text); option.onpointermove = () => choose(i); option.onmousedown = event => event.preventDefault(); option.onclick = () => activate(i); list.append(option);
      }
      if (rows.length) choose(append ? Math.max(0, previousActive) : 0);
      status.textContent = rows.length ? (input.value.trim() ? rows.length + ' results' : 'Recent items and commands') + (cursor ? ' · More available' : '') : 'No results. Try fewer words or clear a filter.';
      more.hidden = !cursor; more.textContent = expanded ? 'Load more results' : 'View all results'; more.disabled = false;
    }
    async function run(append = false) {
      clearTimeout(timer); if (!opened || options.available?.() === false) return;
      const ticket = ++serial, currentScope = scope; controller?.abort(); controller = new AbortController();
      if (!append) { expanded = false; cursor = null; rows = []; list.replaceChildren(); input.removeAttribute('aria-activedescendant'); }
      status.textContent = 'Searching…'; more.disabled = true;
      try {
        const result = await options.search({ q: input.value, filters: selectedFilters(), cursor: append ? cursor : null, limit: append ? 25 : 8, recent, signal: controller.signal });
        if (ticket !== serial || currentScope !== scope || !opened) return;
        paint(result, append);
      } catch (error) {
        if (ticket !== serial || error.name === 'AbortError') return;
        list.replaceChildren(); rows = []; cursor = null; more.hidden = true; status.textContent = error.message || 'Search is unavailable. Try again.';
      }
    }
    form.onsubmit = event => { event.preventDefault(); if (!opened) show(); else if (active >= 0) activate(active); else if (rows.length) activate(0); else run(); };
    input.onfocus = () => { if (!opened) show(); };
    input.addEventListener('compositionstart', () => { composing = true; }); input.addEventListener('compositionend', () => { composing = false; run(); });
    input.oninput = () => { serial++; controller?.abort(); rows = []; list.replaceChildren(); input.removeAttribute('aria-activedescendant'); cursor = null; more.hidden = true; status.textContent = 'Searching…'; if (!opened) expand(); clearTimeout(timer); if (!composing) timer = setTimeout(() => run(), 100); };
    host.addEventListener('keydown', event => {
      if (event.isComposing) return;
      if (event.key === 'Escape' && opened) { event.preventDefault(); event.stopPropagation(); closePanel(); }
      if (event.target === input && ['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); if (!opened) show(); else choose(Math.max(0, Math.min(rows.length - 1, active + (event.key === 'ArrowDown' ? 1 : -1)))); }
      if (event.key === 'Tab' && opened && global.matchMedia?.('(max-width: 650px)').matches) {
        const controls = [input, close, ...Object.values(selects), ...(!more.hidden ? [more] : [])]; const index = controls.indexOf(document.activeElement);
        if (event.shiftKey && index === 0) { event.preventDefault(); controls.at(-1).focus(); } else if (!event.shiftKey && index === controls.length - 1) { event.preventDefault(); input.focus(); }
      }
    });
    const outside = event => { if (opened && !host.contains(event.target)) closePanel(false); };
    const shortcutHandler = event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && options.available?.() !== false) { if (document.querySelector('dialog[open]') && !host.closest('dialog')) return; event.preventDefault(); event.stopImmediatePropagation(); input.focus(); show(); } };
    document.addEventListener('pointerdown', outside); document.addEventListener('keydown', shortcutHandler, true);
    host.addEventListener('focusout', () => { setTimeout(() => { if (opened && !host.contains(document.activeElement)) closePanel(false); }, 0); });
    close.onclick = () => closePanel(); more.onclick = () => { expanded = true; run(true); };
    changeScope(options.scope || '');
    return { input, panel, show, close: closePanel, refresh: () => opened && run(), setScope: changeScope, destroy() { closePanel(false); document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', shortcutHandler, true); host.replaceChildren(); } };
  }
  global.EverbranchSearch = { normalize, words, parse, prepare, score, compare, search, palette, distance };
})(globalThis);
