import '../../css/team-operations.css';

// Business workspaces share the Teams shell and services. Private drafts stay in memory.
export function installOperations(nativeRoot) {
  const root = document.getElementById('team-operations');
  if (!root) return;
  const $ = name => document.getElementById('tops-' + name);
  const el = (tag, text = '', cls = '') => { const n = document.createElement(tag); n.textContent = text; n.className = cls; return n; };
  const button = (text, action, cls = '') => { const n = el('button', text, cls); n.type = 'button'; n.onclick = () => Promise.resolve(action()).catch(report); return n; };
  const label = value => String(value || '').replaceAll('_', ' ').replace(/^./, c => c.toUpperCase());
  const money = cents => cents === null || cents === undefined ? 'Unknown' : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(cents / 100);
  const date = value => value ? new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(value.slice(0, 10) + 'T12:00:00Z')) : 'Unscheduled';
  const admin = root.dataset.admin === '1';
  let areas = [], members = [], area, view = 'overview', page = 1, serial = 0, returnFocus, draft, busy = false, identityLost = false, timer, searchTimer, editorSequence = 0, pageCursors = [null], statisticsYear = new Date().getFullYear();
  const drafts = new Map();
  const emailDrafts = new Map();
  async function api(path, options = {}) {
    if (identityLost && !options.verify) throw new Error('Verify the original account before continuing. Area drafts are retained in this page.');
    const url = new URL(root.dataset.url + path, location.origin);
    url.searchParams.set('tenant', root.dataset.tenant);
    if (area) url.searchParams.set('area', area.id);
    Object.entries(options.query || {}).forEach(([key, value]) => { if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, value); });
    const response = await fetch(url, { credentials: 'same-origin', method: options.method || 'GET', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Team-Viewer': root.dataset.user, 'X-Team-Workspace': nativeRoot.dataset.workspaceId }, body: options.body ? JSON.stringify(options.body) : undefined });
    let data;
    try { data = await response.json(); } catch { throw new Error('The server did not return a valid response. Your draft remains available.'); }
    if ([401, 419].includes(response.status) || data.error === 'page_identity_changed' || (response.ok && (response.headers.get('X-Team-Viewer') !== root.dataset.user || response.headers.get('X-Team-Workspace') !== nativeRoot.dataset.workspaceId))) {
      identityLost = true; serial++; personalTicket++; keepDraft(); $('content').replaceChildren(); $('personal').replaceChildren(); $('personal').hidden = true; $('detail-body').replaceChildren(); $('detail').close(); $('editor').close();
      $('content').inert = false; $('content').removeAttribute('aria-busy');
      $('content').append(button('Verify original account', async () => { await api('/areas', { verify: true }); identityLost = false; await load(); }));
      throw new Error('Your account or workspace changed. Restore and verify the original account before continuing. Drafts are retained in this page.');
    }
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'This request could not be completed.');
    return data;
  }
  function report(error) { const text = error.message || 'This action could not be completed.'; $('notice').textContent = text; if ($('editor').open) $('editor-notice').textContent = text; else if ($('detail').open) { let node = $('detail-body').querySelector('.tops-inline-error'); if (!node) { node = el('p', '', 'tops-inline-error'); node.setAttribute('role', 'alert'); $('detail-body').prepend(node); } node.textContent = text; } }
  function note(text, parent = $('content')) { parent.append(el('p', text, 'tops-muted')); }
  function section(title, parent = $('content')) { const n = el('section', '', 'tops-section'); n.append(el('h3', title)); parent.append(n); return n; }
  function empty(title, text, parent = $('content')) { const box = el('div', '', 'tops-empty'); box.append(el('h3', title), el('p', text)); parent.append(box); }
  function metricGrid(items) { const grid = el('div', '', 'tops-metrics'); for (const [title, value, sub] of items) { const box = el('div'); box.append(el('span', title), el('strong', value)); if (sub) box.append(el('small', sub)); grid.append(box); } $('content').append(grid); }
  function table(headers, rows, parent = $('content')) {
    const wrapper = el('div', '', 'tops-table-scroll'), table = el('table', '', 'tops-table'), head = el('thead'), tr = el('tr'), body = el('tbody');
    for (const h of headers) { const th = el('th', h); th.scope = 'col'; tr.append(th); } head.append(tr);
    for (const cells of rows) { const row = el('tr'); for (const cell of cells) { const td = el('td'); td.append(cell instanceof Node ? cell : document.createTextNode(String(cell ?? '—'))); row.append(td); } body.append(row); }
    table.append(head, body); wrapper.append(table); parent.append(wrapper);
  }
  function field(form, name, text, value = '', type = 'text', options) {
    const wrap = el('label', text), input = el(type === 'textarea' ? 'textarea' : options ? 'select' : 'input');
    if (input.tagName === 'INPUT') input.type = type;
    input.name = name; input.value = value ?? ''; input.maxLength = type === 'textarea' ? 20000 : 500;
    if (options) { for (const option of options) { const o = el('option', typeof option === 'string' ? label(option) : option.name); o.value = typeof option === 'string' ? option : option.id; input.append(o); } input.value = value ?? ''; }
    wrap.append(input); form.append(wrap); return input;
  }
  function dialog(title) { if (!$('detail').open) returnFocus = document.activeElement; $('detail-title').textContent = title; const body = el('div'); $('detail-body').replaceChildren(body); if (!$('detail').open) $('detail').showModal(); return body; }
  function closeDetail() { $('detail').close(); returnFocus?.focus(); }
  $('detail-close').onclick = closeDetail;
  $('detail').addEventListener('close', () => { if (returnFocus?.isConnected) returnFocus.focus(); else $('heading').focus(); });
  function controls() {
    const url = new URL(location.href); url.searchParams.set('area', area.id); url.searchParams.set('area_view', view); history.replaceState(null, '', url);
    $('heading').textContent = area.name; $('eyebrow').textContent = area.profile === 'events' ? 'EVENT OPERATIONS' : 'WHOLESALE RELATIONSHIPS';
    $('subtitle').textContent = area.profile === 'events' ? 'Plan the next occurrence. Keep the details connected.' : 'A clear next step for every account.';
    $('tabs').replaceChildren(); $('groups').replaceChildren();
    const groups = Object.entries(area.groups || {}), currentGroup = groups.find(([, tabs]) => tabs.includes(view));
    for (const [name, tabs] of groups) { const visible = tabs.filter(v => admin || !['statistics', 'staffing', 'orders', 'automations', 'campaigns'].includes(v)); if (!visible.length) continue; const b = button(name, () => { view = visible[0]; page = 1; controls(); return load(); }); b.setAttribute('aria-current', currentGroup?.[0] === name ? 'step' : 'false'); $('groups').append(b); }
    for (const tab of (currentGroup?.[1] || area.views).filter(v => admin || !['statistics', 'staffing', 'orders', 'automations', 'campaigns'].includes(v))) {
      const b = button(label(tab), () => { view = tab; page = 1; controls(); return load(); }); b.setAttribute('aria-current', view === tab ? 'page' : 'false'); $('tabs').append(b);
    }
    $('settings').hidden = !admin;
    $('new').hidden = !area.capabilities?.write || ['statistics', 'documents', 'connections', 'communications', 'orders', 'staffing'].includes(view) || (!admin && ['automations', 'campaigns', 'templates'].includes(view));
    $('new').textContent = 'New ' + ({ pipeline: 'opportunity', followups: 'follow-up', prospects: 'prospect', campaigns: 'campaign', templates: 'template', automations: 'rule' }[view] || (area.profile === 'events' ? 'event' : 'account'));
    $('prev').hidden = $('next').hidden = true;
    $('search').closest('label').hidden = ['connections', 'statistics', 'communications', 'orders', 'prospects', 'followups', 'templates'].includes(view);
  }
  async function openArea(item, initialView = 'overview') {
    if (window.TeamPremium?.context && !window.TeamPremium.context.ready()) return;
    if (area) keepDraft();
    area = item; view = item.views.includes(initialView) ? initialView : 'overview'; page = 1; serial++;
    root.classList.add('tops-active'); $('panel').hidden = false;
    document.querySelectorAll('#tops-areas button').forEach(b => b.setAttribute('aria-current', b.dataset.area === area.id ? 'page' : 'false'));
    controls(); $('heading').focus(); await load();
    const memberArea = item.id; const result = await api('/areas/' + memberArea + '/members'); if (area.id === memberArea) members = result.items;
  }
  function renderAreas() {
    $('areas').replaceChildren(); for (const item of areas) { const b = button(item.name, () => openArea(item)); b.dataset.area = item.id; $('areas').append(b); }
    $('setup').hidden = !admin;
  }
  function native() { keepDraft(); serial++; $('panel').hidden = true; root.classList.remove('tops-active'); const url = new URL(location.href); url.searchParams.delete('area'); url.searchParams.delete('area_view'); history.replaceState(null, '', url); }
  $('menu').onclick = () => document.querySelector('[data-teams-menu]')?.click();
  $('native').onclick = () => { native(); document.querySelector('#tw-search')?.focus(); };
  document.querySelector('.teams-sidebar')?.addEventListener('click', event => { if (event.target.closest('[data-team-mode],#teams-create,#teams-project-list button,#teams-favorite-list button')) native(); });
  let personalTicket = 0;
  window.TeamOperations = {
    area: async areaId => { const target = areas.find(a => a.id === areaId); if (!target) throw new Error('This area is no longer accessible.'); await openArea(target); },
    create: async areaId => { const target = areas.find(a => a.id === areaId); if (!target) throw new Error('This area is no longer accessible.'); await openArea(target); edit(); },
    open: async (areaId, recordId) => { const target = areas.find(a => a.id === areaId); if (!target) throw new Error('This area is no longer accessible.'); await openArea(target); await details({ id: recordId, title: 'Work details' }); },
    renderPersonal: async (context, workPage = 1) => {
      const node = $('personal'), mode = document.getElementById('tw-mode').value, q = document.getElementById('tw-search').value.trim(), ticket = ++personalTicket;
      node.hidden = !['home', 'mine'].includes(mode) && !q || !!context.selectedProject;
      if (node.hidden || identityLost) return;
      node.replaceChildren(el('h3', q ? 'Matching work across Events and Wholesale' : 'Assigned work across your areas'));
      try { const result = await api('/operations/work', { query: { q, mine: q ? 0 : 1, page: workPage } }); if (ticket !== personalTicket) return;
        if (!result.items.length) note('No matching area work returned. Use a work area to check source coverage and current connections.', node);
        else table(['Work', 'Area', 'Date', 'Source'], result.items.map(i => [button(i.title, () => window.TeamOperations.open(i.area_id, i.id), 'tops-record-title'), i.area_name, date(i.starts_on), i.source]), node);
        note(result.notice, node); if (workPage > 1) node.append(button('Previous area results', () => window.TeamOperations.renderPersonal(context, workPage - 1))); if (result.has_more) node.append(button('More area results', () => window.TeamOperations.renderPersonal(context, workPage + 1)));
      } catch (error) { if (ticket === personalTicket) note('Area work is unavailable: ' + error.message, node); }
    },
  };
  $('setup').onclick = () => {
    const body = dialog('Create a work area'), form = el('form', '', 'tops-form'); body.append(form);
    const name = field(form, 'name', 'Area name'), profile = field(form, 'profile', 'Workspace profile', 'events', 'text', ['events', 'wholesale']); name.required = true; name.maxLength = 100;
    note('Wholesale uses the tenant’s existing Wholesale entitlement and commerce services. Connections and automation rules belong to this area.', form);
    form.append(button('Create area', async () => { if (!form.reportValidity()) return; const item = await api('/areas', { method: 'POST', body: { name: name.value, profile: profile.value } }); item.capabilities = { write: true }; areas.push(item); renderAreas(); closeDetail(); await openArea(item); }, 'primary'));
  };
  $('settings').onclick = () => {
    const body = dialog(area.name + ' settings'), form = el('form', '', 'tops-form'); body.append(form);
    const stages = field(form, 'stages', 'Stages, one per line', area.settings.stages.join('\n'), 'textarea');
    const user = field(form, 'user_id', 'Workspace member', '', 'text', [{ id: '', name: 'Choose a member (optional)' }, ...members]), role = field(form, 'role', 'Area access', 'viewer', 'text', ['viewer', 'editor', 'coordinator', 'remove']);
    note('Area membership also requires current company, project and source access. Financial information remains restricted to company admins.', form);
    form.append(button('Save area settings', async () => { const input = { expected_version: area.version, stages: stages.value.split('\n').map(s => s.trim()).filter(Boolean) }; if (user.value) Object.assign(input, { user_id: Number(user.value), role: role.value }); const updated = await api('/areas/' + area.id, { method: 'PUT', body: input }); Object.assign(area, updated); closeDetail(); controls(); }, 'primary'));
  };
  $('refresh').onclick = () => load();
  $('prev').onclick = () => { if (page > 1) page--; load(); };
  $('next').onclick = () => { page++; load(); };
  $('search').oninput = () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; load(); }, 300); };
  function query(kind) { return { kind, page, q: $('search').value.trim(), cursor: pageCursors[page - 1] }; }
  async function load() {
    if (!area || $('panel').hidden) return;
    const ticket = ++serial, current = area.id; $('notice').textContent = 'Loading ' + label(view).toLowerCase() + '…';
    if (page === 1) pageCursors = [null];
    $('content').setAttribute('aria-busy', 'true');
    $('content').inert = true;
    try {
      let result;
      if (view === 'connections') result = await api('/integrations');
      else if (view === 'templates') result = await api('/communications/templates');
      else if (view === 'statistics') result = await api('/statistics', { query: { year: statisticsYear } });
      else if (view === 'communications') result = await api('/communications', { query: { page } });
      else if (area.profile === 'wholesale' && view === 'orders') result = await api('/wholesale/orders', { query: { page } });
      else if (area.profile === 'wholesale' && ['accounts', 'prospects', 'followups'].includes(view)) {
        const reads = await Promise.allSettled([page > 1 && !pageCursors[page - 1] ? Promise.resolve({ items: [], count: 0, has_more: false, next_cursor: null }) : api('/operations/records', { query: query({ accounts: 'account', prospects: 'prospect', followups: 'followup' }[view]) }), api('/wholesale/' + view, { query: { page } })]);
        if (reads[0].status !== 'fulfilled') throw reads[0].reason;
        result = reads[0].value; if (reads[1].status === 'fulfilled') { result.commerce = reads[1].value; result.has_more ||= result.commerce.has_more; } else result.commerceNotice = reads[1].reason.message;
      }
      else result = await api('/operations/records', { query: query({ documents: 'document', staffing: 'staff', pipeline: 'opportunity', campaigns: 'campaign', automations: 'rule', calendar: 'event', events: 'event', accounts: 'account' }[view] || (area.profile === 'events' ? 'event' : 'account')) });
      if (ticket !== serial || current !== area.id) return;
      $('content').replaceChildren(); $('notice').textContent = result.notice || result.freshness?.notice || '';
      pageCursors[page] = result.next_cursor;
      $('scope').textContent = result.count !== undefined ? result.count + ' visible records · page ' + page : result.total !== undefined ? result.total + ' accessible records · page ' + page : '';
      $('prev').hidden = page === 1; $('next').hidden = !result.has_more;
      if (view === 'connections') renderConnections(result);
      else if (view === 'templates') renderTemplates(result);
      else if (view === 'automations') renderAutomations(result.items);
      else if (view === 'campaigns') { note('Campaign plans retain wording for review. Automated external sequences are not enabled in this candidate. Individual email updates are prepared from an account using its current permissions.'); renderRecords(result.items); }
      else if (view === 'statistics') renderStats(result);
      else if (view === 'calendar') renderCalendar(result.items);
      else if (view === 'pipeline') renderPipeline(result.items);
      else if (view === 'communications') renderAttempts(result.items);
      else if (area.profile === 'wholesale' && view === 'orders') renderCommerce(result.items);
      else {
        if (view === 'overview') renderWorkflow(result);
        if (view === 'applications') { note('Application, acceptance and payment information for this page. Source notes appear as confirmed fields after review.'); table(['Event', 'Application', 'Acceptance', 'Payment', 'Next action'], result.items.map(i => { const d = i.source ? i.data.reviewed_fields || {} : i.data; return [button(i.title, () => details(i)), d.application_status || 'Needs confirmation', d.acceptance_status || 'Needs confirmation', d.payment_status || 'Needs confirmation', d.next_action || '—']; })); return; }
        renderRecords(result.items);
        if (result.commerce) { section('Existing Wholesale service'); note('Source records are shown separately from Teams relationship notes. Review identity links before merging customer context.'); renderCommerce(result.commerce.items); }
        if (result.commerceNotice) note('Existing Wholesale source unavailable: ' + result.commerceNotice);
      }
    } catch (error) { if (ticket === serial) { $('content').replaceChildren(); empty('This view is unavailable', 'The source or access check did not complete. This does not mean there are no records.'); if (page > 1) $('content').append(button('Return to first page', () => { page = 1; return load(); })); report(error); } }
    finally { if (ticket === serial) { $('content').removeAttribute('aria-busy'); $('content').inert = false; } }
  }
  function renderRecords(items, parent = $('content')) {
    if (!items?.length) { empty('Ready for connected work', 'Create a draft or choose a source in Connections. Unavailable source work is never represented as an empty complete import.', parent); return; }
    table(['Name', 'Status', 'Date', 'Source'], items.map(item => [button(item.title, () => details(item), 'tops-record-title'), el('span', label(item.status), 'tops-badge'), item.starts_on ? date(item.starts_on) : '—', item.source ? 'Connected · read only' : 'Teams']), parent);
  }
  function navigate(tab) { view = tab; page = 1; controls(); return load(); }
  function renderWorkflow(result) {
    const cards = area.profile === 'events' ? [
      ['01', 'Apply & confirm', 'Track applications, acceptance and fees before committing the team.', 'applications', 'Review applications'],
      ['02', 'Prepare & staff', 'Confirm logistics, assignments and event documents.', 'events', 'Prepare events'],
      ['03', 'Brief & run', 'Use a reviewed update template and keep the team informed.', 'communications', 'Communications'],
      ['04', 'Review & improve', 'Review source completeness, costs and what to repeat next time.', admin ? 'statistics' : 'documents', admin ? 'Review results' : 'Event documents'],
    ] : [
      ['01', 'Find & qualify', 'Research prospects and agree on the next step.', 'prospects', 'Review prospects'],
      ['02', 'Build the relationship', 'Keep contact details, requirements and opportunities together.', 'accounts', 'Browse accounts'],
      ['03', 'Coordinate & follow up', 'Track orders, follow-ups and reviewed conversations.', 'followups', 'Follow-ups'],
      ['04', 'Retain & grow', 'Prepare reorder check-ins and reusable account communications.', 'templates', 'Email templates'],
    ];
    const grid = el('div', '', 'tops-workflow');
    for (const [number, name, text, target, action] of cards) { const card = el('section'); card.append(el('span', number, 'tops-step'), el('h3', name), el('p', text), button(action, () => navigate(target))); grid.append(card); }
    $('content').append(grid);
    const status = section('Keep this workspace dependable'); note(result.notice || result.freshness?.notice || 'Source coverage must be verified in Connections.', status);
    status.append(button('Manage connections', () => navigate('connections')), button('Email templates', () => navigate('templates'))); if (admin) status.append(button('Automation options', () => navigate('automations')));
    section(area.profile === 'events' ? 'Events on this page' : 'Accounts on this page');
  }
  function renderTemplates(data) {
    const grid = el('div', '', 'tops-workflow'); $('content').append(grid);
    for (const starter of data.starters) { const card = section(starter.name, grid); note(starter.data.subject, card); note(starter.data.text.split('\n').slice(0, 4).join(' '), card); if (admin) card.append(button('Customize this template', () => edit({ kind: 'email_template', title: starter.name, status: 'draft', data: starter.data }, true))); }
    const saved = section('Your reusable templates'); if (!data.items.length) note('Customize a starter or create a template. Publish it when the wording is ready for your team.', saved);
    else table(['Template', 'State', 'Subject', 'Actions'], data.items.map(t => [t.name, label(t.status), t.data.subject, admin ? button('Edit template', async () => edit(await api('/operations/records/' + t.id))) : 'Available in the email composer']), saved);
    note('Available fields: ' + data.tokens.map(t => '{{' + t + '}}').join(' · '), saved);
  }
  function renderAutomations(items) {
    note('Reminders create Teams Inbox updates. Outbound email and SMS use a separate reviewed action and sender consent.');
    const options = area.profile === 'events' ? [['morning_digest', 'Coordinator morning digest', 'Upcoming dated work for the next seven days.'], ['day_before', 'Day-before staff reminder', 'An event starts tomorrow in the rule timezone.'], ['missing_information', 'Missing logistics', 'An event needs a venue or instructions.'], ['assignment_changed', 'Assignment update', 'An assignment changed in the last day.'], ['stage_changed', 'Stage change', 'Work entered the stage you choose.']] : [['followup_due', 'Due follow-up reminder', 'A dated follow-up is due or overdue.'], ['reorder_check', 'Reorder check-in reminder', 'An account reached your chosen interval since its last recorded order.'], ['missing_information', 'Missing account details', 'A contact or next action needs attention.'], ['morning_digest', 'Weekly work in a morning digest', 'Dated work for the next seven days.'], ['assignment_changed', 'Assignment update', 'An assignment changed in the last day.'], ['stage_changed', 'Pipeline stage change', 'Work entered the stage you choose.']];
    const grid = el('div', '', 'tops-workflow'); $('content').append(grid);
    for (const [trigger, name, text] of options) { const card = section(name, grid); note(text, card); card.append(button('Configure reminder', () => edit({ kind: 'rule', title: name, status: 'draft', data: { trigger, channel: 'inbox', enabled: false } }, true))); }
    section('Configured rules'); if (!items.length) empty('No active automation', 'Choose an option, preview its audience and schedule, then enable it after review.');
    else table(['Rule', 'Trigger', 'Schedule', 'State', 'Actions'], items.map(r => [r.title, label(r.data.trigger), (r.data.local_time || '08:00') + ' · ' + (r.data.timezone || 'America/New_York'), r.data.enabled && r.status !== 'paused' ? 'Active · Teams Inbox' : 'Paused', button('Review rule', () => edit(r))]));
  }
  function renderPipeline(items) {
    note('Open opportunities on this page. Use Statistics for complete pipeline values. Use a record’s Stage field to move it with the keyboard.');
    const board = el('div', '', 'tops-board'); $('content').append(board);
    const stages = [...new Set([...area.settings.stages, ...items.map(i => i.status)])];
    for (const stage of stages) { const lane = el('section', '', 'tops-lane'), matches = items.filter(i => i.status === stage); lane.append(el('h3', label(stage) + ' · ' + matches.length)); for (const item of matches) { const card = el('div', '', 'tops-pipeline-card'); card.append(button(item.title, () => details(item)), el('p', money(item.data.amount_cents))); lane.append(card); } board.append(lane); }
  }
  function renderCalendar(items) {
    const today = new Date(), input = el('input'); input.type = 'month'; input.value = today.toISOString().slice(0, 7); input.setAttribute('aria-label', 'Calendar month'); $('content').append(input);
    note('Calendar shows records from this page. Page through the list to inspect more events. Dates use each source’s date-only values.');
    const grid = el('div', '', 'tops-calendar'); $('content').append(grid);
    function paint() { grid.replaceChildren(); const [year, month] = input.value.split('-').map(Number); if (!year || !month) return; const first = new Date(Date.UTC(year, month - 1, 1)), days = new Date(Date.UTC(year, month, 0)).getUTCDate();
      for (const day of ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']) grid.append(el('strong', day, 'tops-weekday'));
      for (let i = 0; i < first.getUTCDay(); i++) grid.append(el('div', '', 'tops-day tops-blank'));
      for (let i = 1; i <= days; i++) { const key = input.value + '-' + String(i).padStart(2, '0'), day = el('section', '', 'tops-day'); day.append(el('span', String(i))); for (const item of items.filter(r => r.starts_on && r.starts_on <= key && (r.ends_on || r.starts_on) >= key)) day.append(button(item.title, () => details(item))); grid.append(day); }
    } input.onchange = paint; paint(); const unscheduled = items.filter(i => !i.starts_on); if (unscheduled.length) renderRecords(unscheduled, section('Unscheduled events'));
  }
  function renderStats(data) {
    if (area.profile === 'events') {
      const filters = el('div', '', 'tops-form'); $('content').append(filters); const year = field(filters, '', 'Reporting year', data.year, 'number'); year.min = 2000; year.max = 2100; filters.append(button('Show this year', () => { if (!year.reportValidity() || !year.value) return; statisticsYear = Number(year.value); return load(); }));
      metricGrid([['Net sales', money(data.net_sales_cents)], ['Recorded expenses', money(data.expense_cents)], ['Event profit', money(data.profit_cents)], ['Event margin', data.margin_percent === null ? 'Unknown' : data.margin_percent + '%']]);
      note(data.basis); if (!data.complete) note('Partial financial coverage: ' + data.excluded_rows + ' rows excluded. Connect and reconcile source data before treating these totals as complete.');
      table(['Expense category', 'Recorded amount', 'Missing rows'], [...new Set([...Object.keys(data.costs || {}), ...Object.keys(data.missing_cost_categories || {})])].map(key => [label(key), money(data.costs[key]), data.missing_cost_categories?.[key] || 0]), section('Expense breakdown'));
      table(['Event', 'Date', 'Net sales', 'Profit', 'Margin'], data.events.map(e => [e.title, date(e.date), money(e.net_sales_cents), money(e.profit_cents), e.margin_percent === null ? 'Unknown' : e.margin_percent + '%']), section('Recorded events'));
      const forecast = section('Year-end profit scenarios'); note('Exact-title historical occurrence daily sales and recorded costs. Review series comparability. ' + data.forecast.unknown_events + ' upcoming events remain unknown and are excluded from these scenario subtotals. Weather does not change the arithmetic.', forecast);
      table(['Low', 'Base', 'High', 'Unknown events'], [[money(data.forecast.year_end_profit_cents.low), money(data.forecast.year_end_profit_cents.base), money(data.forecast.year_end_profit_cents.high), data.forecast.unknown_events]], forecast);
      table(['Upcoming event', 'Evidence', 'Sample size'], data.forecast.events.map(e => [button(e.title, () => details(e)), label(e.status), e.sample_size]), forecast);
      const comparisons = section('Compare recorded performance'), dimension = field(comparisons, '', 'Compare by', 'month', 'text', ['month', 'series', 'location', 'year']), output = el('div'); comparisons.append(output);
      const paint = () => { output.replaceChildren(); table(['Group', 'Occurrences', 'Net sales', 'Expenses', 'Profit'], Object.entries(data.comparisons?.[dimension.value] || {}).map(([key, value]) => [key, value.occurrences, money(value.net_sales_cents), money(value.expense_cents), money(value.profit_cents)]), output); }; dimension.onchange = paint; paint();
      if (data.discrepancies?.length) table(['Event', 'Recorded profit', 'Calculated profit'], data.discrepancies.map(d => [d.title, money(d.recorded_profit_cents), money(d.calculated_profit_cents)]), section('Source discrepancies excluded from totals'));
    } else {
      metricGrid([['Weighted open pipeline', money(data.weighted_pipeline_cents)], ['Actuals', 'Qualified orders only']]); note(data.basis);
      table(['Metric', 'Value'], Object.entries(data.qualified_metrics || {}).map(([k, v]) => [label(k), typeof v === 'object' ? 'See order detail' : v]));
      table(['Opportunity', 'Stage', 'Value', 'Probability', 'Weighted value'], data.pipeline.map(p => [p.title, label(p.stage), money(p.amount_cents), p.probability + '%', money(p.weighted_cents)]), section('Open pipeline'));
    }
  }
  function renderCommerce(items) {
    if (!items?.length) { empty('No records returned', 'The existing tenant-scoped Wholesale service returned no matching records.'); return; }
    table(['Record', 'Status', 'Details'], items.map(item => [button(item.title, () => {
      const body = dialog(item.title); table(['Field', 'Value'], Object.entries(item).map(([key, value]) => [label(key), typeof value === 'object' ? '—' : value ?? '—']), body);
      if (admin && view === 'prospects') for (const action of ['qualify', 'request_research', 'mark_do_not_contact', 'convert']) body.append(button(label(action), async () => { await api('/wholesale/prospect', { method: 'POST', body: { id: item.id, action } }); closeDetail(); await load(); }));
    }), label(item.status), [item.city, item.state, item.due_at ? date(item.due_at) : '', item.amount !== undefined ? '$' + item.amount : ''].filter(Boolean).join(' · ')]));
  }
  async function details(item) {
    const captured = area.id, body = dialog(item.title); note('Checking current access…', body);
    const record = await api('/operations/records/' + item.id); if (captured !== area.id || !$('detail').open) return;
    body.replaceChildren(); note(label(record.status) + ' · ' + (record.source ? 'Connected source · read only' : 'Teams draft') + ' · Version ' + record.version, body);
    if (record.data.source_url) { const a = el('a', 'Open in Asana'); a.href = record.data.source_url; a.target = '_blank'; a.rel = 'noopener noreferrer'; if (a.href.startsWith('https://app.asana.com/')) body.append(a); }
    const data = Object.entries(record.data).filter(([key, value]) => !key.startsWith('_') && !['source_url', 'fields', 'original_notes', 'source_values', 'costs'].includes(key) && value !== null && typeof value !== 'object');
    table(['Property', 'Value'], [['Starts', date(record.starts_on)], ['Ends', date(record.ends_on)], ...data.map(([key, value]) => [label(key), String(value)])], body);
    if (record.data.original_notes) { const notes = section('Original source notes', body); notes.append(el('p', record.data.original_notes, 'tops-notes')); }
    if (record.assignments) { const assignments = section('Recorded assignments and compensation', body); note(record.identity_notice, assignments); table(['Event', 'Date', 'Hours', 'Payroll', 'Pay week', 'Sheet source'], record.assignments.map(a => [a.title, date(a.starts_on), a.hours ?? 'Unknown', money(a.payroll_cents), a.pay_week ?? '—', a.source_tab + ' · row ' + a.source_row]), assignments); }
    if (record.data.fields?.length) { const fields = section('Structured note candidates', body); note('Review each candidate against its source passage. Approval adds a Teams field; the original notes remain unchanged.', fields); const choices = [];
      table(['Approve', 'Field', 'Candidate', 'Source passage', 'Review'], record.data.fields.map((f, index) => { const input = el('input'); input.type = 'checkbox'; input.setAttribute('aria-label', 'Approve ' + label(f.field) + ': ' + f.value); input.disabled = !area.capabilities?.write || f.review_state === 'approved'; choices.push({ index, input }); return [input, label(f.field), f.value, f.passage, label(f.review_state)]; }), fields);
      if (area.capabilities?.write) fields.append(button('Approve selected fields', async () => { const selected = choices.filter(c => c.input.checked).map(c => c.index); if (!selected.length) throw new Error('Choose the fields you have reviewed.'); await api('/operations/records/' + record.id + '/review', { method: 'POST', body: { request_id: crypto.randomUUID(), expected_version: record.version, reviewed: true, action: 'fields', field_indices: selected } }); await details(record); }));
    }
    if (record.capabilities.edit) body.append(button('Edit ' + label(record.kind).toLowerCase(), () => { closeDetail(); edit(record); }, 'primary'));
    if (record.kind === 'event') body.append(button('Weather at this event', async () => { const result = await api('/events/' + record.id + '/weather'); const weather = section('Weather', body); note(result.notice || result.status || result.source || 'Weather result', weather); if (result.daily) table(['Date', 'High', 'Low', 'Precipitation'], result.daily.time.map((d, i) => [date(d), result.daily.temperature_2m_max?.[i], result.daily.temperature_2m_min?.[i], result.daily.precipitation_sum?.[i]]), weather); }));
    if (record.kind === 'event' && !record.source && !record.data.created_asana) body.append(button('Review creation in Asana', () => compose(record, 'asana.create')));
    if (record.kind === 'event' || record.kind === 'account') body.append(button('Prepare an email update', () => compose(record, 'gmail.send')));
    if (record.kind === 'event' && admin) body.append(button('Review financial assumptions', () => financialReview(record)));
    if (['event', 'account', 'followup'].includes(record.kind)) body.append(button('Prepare a briefing', async () => { const result = await api('/communications/briefing', { method: 'POST', body: { record_ids: [record.id], request_id: crypto.randomUUID(), ai: true } }); const briefing = section('Briefing draft', body); briefing.append(el('p', result.answer, 'tops-notes')); note((result.notice || 'Review before sharing.') + ' Sources: ' + result.sources.join(', '), briefing); }));
    if (record.kind === 'document') {
      if (record.status === 'linked') { const a = el('a', 'Download scanned document'); a.href = root.dataset.url + '/operations/documents/' + record.id + '/download?tenant=' + encodeURIComponent(root.dataset.tenant); body.append(a); }
      note('Scan: ' + label(record.data.scan), body);
      const targets = await api('/operations/records', { query: { kind: area.profile === 'events' ? 'event' : 'account' } });
      const target = field(body, 'target_id', 'Link to event / account', record.data.target_id || '', 'text', [{ id: '', name: 'Choose a destination' }, ...targets.items.map(i => ({ id: i.id, name: i.title + ' · ' + date(i.starts_on) }))]);
      body.append(button('Review and link document', async () => { await api('/operations/documents/' + record.id + '/review', { method: 'POST', body: { target_id: target.value, expected_version: record.version, reviewed: true } }); closeDetail(); await load(); }));
    }
  }
  async function financialReview(record) {
    const body = dialog(record.title + ' · financial review'); note('These Teams review decisions supplement source records. The compensation workbook remains authoritative.', body);
    const assumptions = section('Year-end profit override', body), form = el('div', '', 'tops-form'); assumptions.append(form);
    const current = record.data._teams_enrichment?.forecast_override;
    const low = field(form, 'low', 'Low profit estimate ($)', current ? (current.profit_cents.low / 100).toFixed(2) : '', 'number'), base = field(form, 'base', 'Base profit estimate ($)', current ? (current.profit_cents.base / 100).toFixed(2) : '', 'number'), high = field(form, 'high', 'High profit estimate ($)', current ? (current.profit_cents.high / 100).toFixed(2) : '', 'number');
    for (const input of [low, base, high]) input.step = '.01';
    const reason = field(form, 'reason', 'Evidence and assumptions', current?.reason || '', 'textarea');
    note('Confirm the occurrence dates, expected costs and comparable history. This override becomes stale when source dates or source version changes.', assumptions);
    assumptions.append(button('Save reviewed forecast assumptions', async () => { const cents = value => { if (!/^-?\d{1,9}(\.\d{1,2})?$/.test(value)) throw new Error('Use dollars and cents for each scenario.'); const negative = value.startsWith('-'), [whole, fraction = ''] = value.replace('-', '').split('.'); return (Number(whole) * 100 + Number(fraction.padEnd(2, '0'))) * (negative ? -1 : 1); }; await api('/operations/records/' + record.id + '/review', { method: 'POST', body: { request_id: crypto.randomUUID(), expected_version: record.version, action: 'forecast', reviewed: true, low_cents: cents(low.value), base_cents: cents(base.value), high_cents: cents(high.value), reason: reason.value } }); closeDetail(); await load(); }));
    const sales = section('Square reconciliation', body); note('Loading existing tenant Square history…', sales); const selected = new Set(record.data._teams_enrichment?.square_orders?.ids || []); let salesPage = 1;
    async function readSales() { const result = await api('/events/' + record.id + '/sales', { query: { page: salesPage } }); if (!body.isConnected) return; sales.replaceChildren(el('h3', 'Square reconciliation')); note(result.notice, sales);
      table(['Allocate', 'Order', 'Closed', 'Order total', 'Recorded refunds', 'Review'], result.items.map(o => { const input = el('input'); input.type = 'checkbox'; input.setAttribute('aria-label', 'Allocate Square order ' + o.id); input.checked = selected.has(o.id); input.disabled = o.allocation_conflict; input.onchange = () => { if (input.checked) selected.add(o.id); else selected.delete(o.id); }; return [input, o.id, o.closed_at ? date(o.closed_at) : 'Unknown', o.currency === 'USD' ? money(o.order_total_cents) : 'Check currency: ' + (o.currency || 'Unknown'), o.currency === 'USD' ? money(o.recorded_refunds_cents) : 'Check currency', o.allocation_conflict ? 'Already mapped to another event · review' : o.offline ? 'Offline transmission recorded' : 'Date-window candidate']; }), sales);
      if (salesPage > 1) sales.append(button('Previous orders', () => { salesPage--; return readSales(); })); if (result.has_more) sales.append(button('More orders', () => { salesPage++; return readSales(); }));
      sales.append(button('Save reviewed order allocation', async () => { await api('/operations/records/' + record.id + '/review', { method: 'POST', body: { request_id: crypto.randomUUID(), expected_version: record.version, action: 'square', reviewed: true, order_ids: [...selected] } }); closeDetail(); await load(); }));
    }
    try { await readSales(); } catch (error) { sales.replaceChildren(el('h3', 'Square reconciliation')); note(error.message, sales); }
  }
  const editorFields = ['venue', 'address', 'city', 'state', 'timezone', 'environment', 'organizer', 'email', 'phone', 'setup_time', 'loadout_time', 'event_hours', 'booth', 'instructions', 'application_status', 'acceptance_status', 'payment_status', 'staffing_status'];
  function kindForView() { return { pipeline: 'opportunity', prospects: 'prospect', followups: 'followup', templates: 'email_template', campaigns: 'campaign', automations: 'rule' }[view] || (area.profile === 'events' ? 'event' : 'account'); }
  function edit(record, starter = false) {
    const remembered = !record ? drafts.get(area.id) : null;
    if (draft?.dirty && draft.area === area.id && record && record.id !== draft.id) { $('notice').textContent = 'Save or discard your current area draft before editing another record.'; return; }
    if (remembered) record = { ...remembered, ...remembered.values, data: { ...remembered.data, ...remembered.values }, version: remembered.expected_version };
    returnFocus = document.activeElement; editorSequence++;
    draft = { area: area.id, id: record?.id || crypto.randomUUID(), request_id: crypto.randomUUID(), expected_version: record?.version || 0, kind: record?.kind || kindForView(), data: { ...(record?.data || {}) }, dirty: false };
    if (remembered) { draft.request_id = remembered.request_id; draft.dirty = true; }
    const form = $('editor-fields'); form.replaceChildren(); $('editor-notice').textContent = '';
    $('editor-title').textContent = (record?.id ? 'Edit ' : 'New ') + label(draft.kind).toLowerCase();
    const configuration = ['email_template', 'rule', 'campaign'].includes(draft.kind);
    const title = field(form, 'title', draft.kind === 'event' ? 'Event title' : 'Name', record?.title); title.required = true;
    if (!configuration) field(form, 'assigned_user_id', 'Assigned team member', record?.assigned_user_id || '', 'text', [{ id: '', name: 'Unassigned' }, ...members.filter(m => m.area_access)]);
    const matches = el('div', '', 'tops-prefill'); matches.setAttribute('aria-live', 'polite'); form.append(matches);
    if (draft.kind !== 'rule') field(form, 'status', configuration ? 'Publication state' : 'Stage', record?.status || (configuration ? 'draft' : draft.kind === 'event' ? 'research' : area.settings.stages[0]), 'text', draft.kind === 'email_template' ? ['draft', 'published', 'archived'] : configuration ? ['draft', 'paused'] : [...new Set([...(area.settings.stages || []), record?.status, 'draft', 'paused'].filter(Boolean))]);
    if (!configuration) { field(form, 'starts_on', 'Starts', record?.starts_on, 'date'); field(form, 'ends_on', 'Ends', record?.ends_on, 'date'); }
    const names = configuration ? [] : draft.kind === 'event' ? editorFields : ['contact', 'email', 'phone', 'address', 'website', 'shipping', 'pickup', 'custom_labels', 'custom_scents', 'requirements', 'next_action', 'last_contact_on', 'last_order_on'];
    for (const name of names) field(form, name, label(name), record?.data[name], ['instructions', 'requirements'].includes(name) ? 'textarea' : name.endsWith('_on') ? 'date' : 'text');
    if (draft.kind === 'event') { field(form, 'latitude', 'Confirmed venue latitude', record?.data.latitude || ''); field(form, 'longitude', 'Confirmed venue longitude', record?.data.longitude || ''); }
    if (draft.kind === 'opportunity') { field(form, 'amount_cents', 'Value in cents', record?.data.amount_cents || '', 'number'); const probability = field(form, 'probability', 'Probability (%)', record?.data.probability || '', 'number'); probability.min = 0; probability.max = 100; field(form, 'account_id', 'Linked Teams account ID', record?.data.account_id || ''); }
    if (!configuration) field(form, 'notes', 'Notes', record?.data.notes || '', 'textarea');
    if (draft.kind === 'email_template') {
      field(form, 'subject', 'Email subject', record?.data.subject || '').required = true; field(form, 'text', 'Message', record?.data.text || '', 'textarea').required = true;
      field(form, 'cta_label', 'Button label (optional)', record?.data.cta_label || ''); field(form, 'cta_url', 'Button link (https)', record?.data.cta_url || '', 'url');
      note('Personalize with {{recipient_name}}, {{company_name}}, {{record_title}}, {{starts_on}}, {{ends_on}}, {{venue}}, {{address}}, {{setup_time}}, {{event_hours}}, {{instructions}}, {{contact_name}}, {{next_action}}, {{shipping}} or {{pickup}}. Preview against an event or account before sending.', form);
    }
    if (draft.kind === 'campaign') { field(form, 'subject', 'Campaign subject', record?.data.subject || ''); field(form, 'text', 'Campaign wording', record?.data.text || '', 'textarea'); note('Campaign plan only. This does not enroll recipients or send messages. External sequences require the established campaign audience, approval and suppression workflow.', form); }
    if (draft.kind === 'rule') {
      field(form, 'trigger', 'When to remind', record?.data.trigger || 'missing_information', 'text', area.profile === 'events' ? ['morning_digest', 'day_before', 'missing_information', 'assignment_changed', 'stage_changed'] : ['morning_digest', 'followup_due', 'reorder_check', 'missing_information', 'assignment_changed', 'stage_changed']); field(form, 'local_time', 'Reminder time', record?.data.local_time || '08:00', 'time'); field(form, 'timezone', 'Timezone', record?.data.timezone || 'America/New_York');
      field(form, 'quiet_start', 'Quiet hours start', record?.data.quiet_start || '22:00', 'time'); field(form, 'quiet_end', 'Quiet hours end', record?.data.quiet_end || '07:00', 'time');
      field(form, 'stage', 'Target stage (for stage changes)', record?.data.stage || area.settings.stages[0], 'text', area.settings.stages); if (area.profile === 'wholesale') field(form, 'days_after', 'Reorder interval (days)', record?.data.days_after || 60, 'number');
      const audience = field(form, 'audience_ids', 'Reminder recipients', '', 'text', members.filter(m => m.area_access)); audience.multiple = true; audience.size = Math.min(5, Math.max(2, members.length)); for (const option of audience.options) option.selected = (record?.data.audience_ids || []).map(String).includes(option.value);
      field(form, 'enabled', 'Internal reminder rule', record?.data.enabled ? 'true' : 'false', 'text', [{ id: 'false', name: 'Paused' }, { id: 'true', name: 'Enabled after review' }]);
      note('Delivery: Teams Inbox. No email or text is sent by this rule. Choose recipients with current area access; record permissions are checked again at delivery. Assignment and stage changes are collected into a scheduled digest.', form);
      const preview = el('div', '', 'tops-rule-preview'); form.append(preview);
      form.append(button('Preview matching work & recipients', async () => { const values = Object.fromEntries(new FormData($('form'))); const data = { ...values, enabled: values.enabled === 'true', channel: 'inbox', audience_ids: [...audience.selectedOptions].map(o => Number(o.value)) }; const result = await api('/communications/automations/preview', { method: 'POST', body: { data } }); preview.replaceChildren(); note(result.notice, preview); note(result.channel + ' · ' + result.time + ' · ' + result.timezone, preview); table(['Recipient', 'Matches now', 'Work sample'], result.items.map(i => [i.recipient, i.count, i.records.map(r => r.title).join('\n')]), preview); }));
    }
    form.oninput = () => { draft.dirty = true; draft.request_id = null; };
    let prefillTimer;
    title.addEventListener('input', () => { if (draft.kind !== 'event') return; clearTimeout(prefillTimer); const q = title.value.trim(), sequence = editorSequence, draftId = draft.id; matches.replaceChildren(); if (q.length < 2) return;
      prefillTimer = setTimeout(async () => { try { const found = await api('/events/prefill', { query: { q } }); if (sequence !== editorSequence || draftId !== draft?.id || q !== title.value.trim()) return; for (const previous of found.items.filter(i => i.id !== draft.id).slice(0, 5)) matches.append(button(previous.title + ' · ' + date(previous.starts_on), () => { for (const [key, value] of Object.entries(previous.prefill)) { const input = form.querySelector('[name="' + key + '"]'); if (input && !input.value && typeof value === 'string') input.value = value; } draft.dirty = true; draft.request_id = null; $('editor-notice').textContent = previous.notice; })); } catch { if (sequence === editorSequence) matches.textContent = 'Previous-event search is unavailable. Your entry is retained.'; } }, 300);
    });
    if (starter) draft.dirty = true;
    if (!$('editor').open) $('editor').showModal(); title.focus();
  }
  function updateDraftNotice() { $('draft').hidden = !drafts.size && !emailDrafts.size; const ids = [...new Set([...drafts.keys(), ...[...emailDrafts.values()].map(d => d.area)])]; $('draft').querySelector('span').textContent = 'Drafts retained in this page: ' + ids.map(id => areas.find(a => a.id === id)?.name || 'Area').join(', '); }
  function keepDraft() { if (draft?.dirty) { draft.values = Object.fromEntries(new FormData($('form'))); const audience = $('form').querySelector('[name=audience_ids]'); if (audience?.multiple) { draft.data.audience_ids = [...audience.selectedOptions].map(o => o.value); delete draft.values.audience_ids; } drafts.set(draft.area, draft); } updateDraftNotice(); }
  $('new').onclick = () => edit();
  document.addEventListener('keydown', event => { if ($('panel').hidden || $('detail').open || $('editor').open || event.target.closest('input,textarea,select,[contenteditable]')) return; if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !window.TeamSearchPalette) { event.preventDefault(); event.stopImmediatePropagation(); ($('search').closest('label').hidden ? $('groups').querySelector('button') : $('search'))?.focus(); } else if (event.key.toLowerCase() === 'n' && !event.ctrlKey && !event.metaKey && !event.altKey && !$('new').hidden) { event.preventDefault(); event.stopImmediatePropagation(); edit(); } }, true);
  $('editor-close').onclick = () => { keepDraft(); $('editor').close(); returnFocus?.focus(); };
  $('editor').addEventListener('cancel', keepDraft);
  $('resume').onclick = async () => { if (drafts.has(area?.id)) edit(); else { const retained = [...emailDrafts.values()].find(d => d.area === area?.id); if (retained) { const record = await api('/operations/records/' + retained.recordId); await compose(record, 'gmail.send'); } else $('notice').textContent = 'Return to the area where you started this draft to resume it.'; } };
  $('discard').onclick = () => { if (busy) return; drafts.delete(area?.id); for (const [key, value] of emailDrafts) if (value.area === area?.id) emailDrafts.delete(key); if (draft?.area === area?.id) draft = null; editorSequence++; updateDraftNotice(); $('editor').close(); $('detail').close(); };
  $('form').onsubmit = async event => {
    event.preventDefault(); if (busy || !draft || draft.area !== area.id) return;
    const values = Object.fromEntries(new FormData($('form'))); const input = { id: draft.id, request_id: draft.request_id ||= crypto.randomUUID(), expected_version: draft.expected_version, kind: draft.kind, title: values.title, status: draft.kind === 'rule' ? (values.enabled === 'true' ? 'active' : 'paused') : values.status, starts_on: values.starts_on || null, ends_on: values.ends_on || null, data: { ...draft.data } };
    input.assigned_user_id = values.assigned_user_id ? Number(values.assigned_user_id) : null;
    for (const [key, value] of Object.entries(values)) if (!['title', 'status', 'starts_on', 'ends_on', 'assigned_user_id'].includes(key)) input.data[key] = ['amount_cents', 'probability', 'days_after'].includes(key) ? (value === '' ? null : Number(value)) : key === 'enabled' ? value === 'true' : key === 'audience_ids' ? value.split(',').map(s => s.trim()).filter(Boolean) : value;
    if (draft.kind === 'rule') Object.assign(input.data, { reviewed: true, external: false, channel: 'inbox', audience_ids: [...$('form').querySelector('[name=audience_ids]').selectedOptions].map(o => o.value) });
    if (draft.kind === 'campaign') Object.assign(input.data, { reviewed: true, external: true, enabled: false, channel: 'gmail' });
    busy = true; $('save').disabled = true; $('editor-fields').querySelectorAll('input,select,textarea').forEach(i => i.disabled = true); $('editor-notice').textContent = 'Saving…';
    try { await api('/operations/records', { method: 'POST', body: input }); drafts.delete(draft.area); draft = null; $('editor').close(); updateDraftNotice(); await load(); returnFocus?.focus(); }
    catch (error) { $('editor-fields').querySelectorAll('input,select,textarea').forEach(i => i.disabled = false); draft.dirty = true; keepDraft(); $('editor-notice').textContent = error.message + ' Your draft is retained. Retry unchanged content to reconcile the original request.'; }
    finally { busy = false; $('save').disabled = false; $('editor-fields').querySelectorAll('input,select,textarea').forEach(i => i.disabled = false); }
  };
  async function renderConnections(data) {
    note('Each person connects their own accounts. Existing automation connections stay independent. Read access and send consent are separate.');
    const actions = section('Add an account');
    for (const provider of ['asana', 'gmail', ...(admin ? ['sheets'] : [])]) actions.append(button('Connect ' + label(provider), async () => { const result = await api('/integrations/' + provider + '/connect', { method: 'POST', body: { send: false } }); if (drafts.size || emailDrafts.size || draft?.dirty) throw new Error('Save or discard your retained area draft before leaving for OAuth.'); location.assign(result.url); }));
    if (!data.items.length) empty('No accounts connected', 'Connect an account, then select the source to read. Provider credentials must be configured by the platform administrator.');
    for (const connection of data.items) {
      const box = section(label(connection.provider) + ' · ' + connection.account_name); note(label(connection.status) + ' · ' + label(connection.sync.status || 'not refreshed') + ' · Last successful refresh: ' + (connection.last_success_at ? new Date(connection.last_success_at).toLocaleString() : 'Never'), box);
      if (!connection.sync.complete) note('Coverage is incomplete. Previously retained records may be stale.', box);
      const form = el('div', '', 'tops-form'); box.append(form);
      if (connection.provider === 'asana') projectPicker(form, connection, data.preset);
      if (connection.provider === 'sheets') { field(form, 'spreadsheet_id', 'Canonical spreadsheet ID', connection.source.spreadsheet_id || data.preset.spreadsheet_id); field(form, 'tabs', 'Tabs, one per line', (connection.source.tabs || data.preset.tabs || []).join('\n'), 'textarea'); note('Staff continue editing Sheets. Review mapping and identity backups before trusting financial totals. Identity writes require a separate rollout flag.', form); }
      if (connection.provider === 'gmail') field(form, 'after', 'Reconcile mail after', connection.source.after || new Date(Date.now() - 90 * 86400000).toISOString().slice(0, 10), 'date');
      form.append(button('Verify and select source', async () => { const source = {}; form.querySelectorAll('[name]').forEach(i => source[i.name] = i.name === 'tabs' ? i.value.split('\n').map(s => s.trim()).filter(Boolean) : i.value); if (form.selectedProjects) { source.asana_projects = form.selectedProjects(); source.asana_project = source.asana_projects[0] || ''; } await api('/integrations/' + connection.provider + '/' + connection.id + '/selection', { method: 'PUT', body: { expected_generation: connection.generation, source } }); await load(); }));
      box.append(button('Refresh this source', async () => { $('notice').textContent = 'Refreshing connected source…'; await api('/integrations/' + connection.provider + '/' + connection.id + '/refresh', { method: 'POST' }); await load(); }), button('Disconnect account', async () => { await api('/integrations/' + connection.provider + '/' + connection.id, { method: 'DELETE' }); await load(); }));
      if (connection.provider !== 'asana') box.append(button('Enable / renew push updates', async () => { await api('/integrations/' + connection.provider + '/' + connection.id + '/watch', { method: 'POST' }); $('notice').textContent = 'Push watch registered. Scheduled reconciliation also remains active.'; }));
      if (connection.provider === 'sheets') {
        box.append(button('Preview mapping and back up source', async () => {
          const preview = await api('/integrations/sheets/' + connection.id + '/preview', { method: 'POST' }), body = dialog('Spreadsheet mapping preview'); note(preview.notice, body); note('Backup: ' + preview.backup_id, body);
          table(['Tab', 'Mapped rows', 'Allocation review', 'Headers / errors'], preview.tabs.map(t => [t.name, t.mapped_rows, t.allocation_review_rows, t.headers.join(' · ') + (t.errors.length ? '\n' + t.errors.join('\n') : '')]), body);
          body.append(button('Initialize reviewed hidden identifiers', async () => { const result = await api('/integrations/sheets/' + connection.id + '/identities', { method: 'POST', body: { backup_id: preview.backup_id, reviewed: true } }); note(result.notice, body); }));
          body.append(button('Approve this source mapping', async () => { const source = Object.fromEntries(Object.entries(connection.source).filter(([k]) => ['asana_workspace', 'asana_project', 'spreadsheet_id', 'tabs', 'after'].includes(k))); await api('/integrations/sheets/' + connection.id + '/selection', { method: 'PUT', body: { expected_generation: connection.generation, source, mapping_approved: true } }); closeDetail(); await load(); }));
        }));
        box.append(button('Authorize hidden identifier writes', async () => { if (drafts.size || emailDrafts.size || draft?.dirty) throw new Error('Save or discard area drafts before OAuth.'); const result = await api('/integrations/sheets/connect', { method: 'POST', body: { send: true } }); location.assign(result.url); }));
      }
      if (connection.provider === 'asana' || connection.provider === 'gmail') box.append(button('Authorize ' + (connection.provider === 'asana' ? 'reviewed event creation' : 'email sending'), async () => { if (drafts.size || emailDrafts.size || draft?.dirty) throw new Error('Save or discard area drafts before OAuth.'); const result = await api('/integrations/' + connection.provider + '/connect', { method: 'POST', body: { send: true } }); location.assign(result.url); }));
    }
  }
  function projectPicker(form, connection, preset) {
    const workspace = field(form, 'asana_workspace', 'Asana workspace', connection.source.asana_workspace || preset.asana_workspace || '', 'text', [{ id: connection.source.asana_workspace || preset.asana_workspace || '', name: 'Current selection · verify access' }]);
    const search = field(form, '', 'Search loaded projects', '', 'search'); search.removeAttribute('name');
    const selectedIds = new Set([connection.source.asana_project || preset.asana_project, ...(connection.source.asana_projects || [])].filter(Boolean));
    const project = field(form, 'asana_projects', 'Selected projects', '', 'text', [...selectedIds].map(id => ({ id, name: 'Selected project · ' + id }))); project.multiple = true; project.size = 7;
    for (const option of project.options) option.selected = true;
    form.selectedProjects = () => [...selectedIds];
    if (area.profile === 'wholesale') field(form, 'asana_account_model', 'How accounts are organized in Asana', connection.source.asana_account_model || preset.asana_account_model || 'tasks', 'text', [{ id: 'projects', name: 'One client per project; tasks are follow-ups' }, { id: 'tasks', name: 'One client per task' }]);
    note('Choose projects with Ctrl / Command or Shift. Selections remain while searching or loading more. The first selected project is the target for separately reviewed event creation.', form);
    const archivedWrap = el('label', 'Include archived projects'), archived = el('input'); archived.type = 'checkbox'; archivedWrap.append(archived); form.append(archivedWrap);
    const status = el('p', '', 'tops-muted'); form.append(status);
    let projects = [], cursor = null;
    const base = '/integrations/asana/' + connection.id + '/catalog';
    const paint = () => { project.replaceChildren(); const known = new Set(projects.map(p => p.gid)); const choices = [...[...selectedIds].filter(id => !known.has(id)).map(gid => ({ gid, name: 'Selected project · ' + gid })), ...projects]; for (const p of choices.filter(p => p.name.toLowerCase().includes(search.value.toLowerCase()))) { const o = el('option', p.name + (p.archived ? ' · archived' : '')); o.value = p.gid; o.selected = selectedIds.has(p.gid); project.append(o); } status.textContent = selectedIds.size + ' selected · ' + projects.length + ' projects loaded' + (cursor ? ' · more available' : ''); };
    project.onchange = () => { for (const option of project.options) { if (option.selected) selectedIds.add(option.value); else selectedIds.delete(option.value); } status.textContent = selectedIds.size + ' selected · ' + projects.length + ' projects loaded'; };
    const more = button('Load more projects', () => loadProjects(true)); more.hidden = true; form.append(more);
    async function loadProjects(append = false) {
      if (!workspace.value || connection.status !== 'connected') return; status.textContent = 'Loading project catalog…';
      try { const result = await api(base, { query: { workspace: workspace.value, include_archived: archived.checked ? 1 : 0, cursor: append ? cursor : null } }); projects = append ? [...projects, ...result.items] : result.items; cursor = result.next_cursor; more.hidden = !cursor; paint(); status.textContent = projects.length + ' projects loaded' + (cursor ? ' · more available' : ''); }
      catch (error) { status.textContent = error.message; }
    }
    workspace.onchange = () => { cursor = null; selectedIds.clear(); return loadProjects(); }; archived.onchange = () => loadProjects(); search.oninput = paint;
    form.append(button('Browse workspaces', async () => { const result = await api(base, { query: { resource: 'workspaces' } }); const selected = workspace.value; workspace.replaceChildren(); for (const w of result.items) { const o = el('option', w.name); o.value = w.gid; workspace.append(o); } if ([...workspace.options].some(o => o.value === selected)) workspace.value = selected; await loadProjects(); }));
    if (workspace.value) loadProjects();
  }
  function emailPreview(message, parent) {
    const frame = el('iframe', '', 'tops-email-preview'); frame.title = 'Formatted email preview'; frame.setAttribute('sandbox', ''); frame.referrerPolicy = 'no-referrer';
    frame.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; font-src \'none\'; form-action \'none\'; base-uri \'none\'">' + message.html;
    parent.append(frame); note('Plain-text alternative', parent); parent.append(el('p', message.text, 'tops-notes'));
  }
  async function compose(record, kind) {
    const currentArea = area.id, data = await api('/integrations'); if (currentArea !== area.id) return;
    const connections = data.items.filter(c => c.provider === (kind === 'asana.create' ? 'asana' : 'gmail') && c.status === 'connected');
    const body = dialog(kind === 'asana.create' ? 'Review new Asana event' : 'Prepare an email update'), form = el('div', '', 'tops-form'); body.append(form);
    if (!connections.length) { note('Connect the corresponding account in Connections first.', body); return; }
    const connection = field(form, 'connection_id', 'Sender / source account', connections[0].id, 'text', connections.map(c => ({ id: c.id, name: c.account_name })));
    if (kind === 'asana.create') {
      note('Review current dates, contacts, instructions and target project. An uncertain outcome is held for reconciliation.', body);
      body.append(el('p', record.title + ' · ' + date(record.starts_on) + '–' + date(record.ends_on), 'tops-notes'));
      let request = crypto.randomUUID(); form.append(button('Prepare reviewed creation', async () => { const result = await api('/communications', { method: 'POST', body: { request_id: request, kind, record_id: record.id, expected_record_version: record.version, connection_id: connection.value, reviewed: true } }); const preview = section('Prepared creation', body); note(result.notice, preview); preview.append(button('Create this reviewed event in Asana', async () => { const sent = await api('/communications/' + result.id + '/dispatch', { method: 'POST', body: { reviewed: true } }); note('Outcome: ' + label(sent.status), preview); })); }));
      form.oninput = () => { request = crypto.randomUUID(); }; return;
    }
    const catalog = await api('/communications/templates'); if (!body.isConnected || currentArea !== area.id) return;
    const draftKey = currentArea + ':' + record.id, retained = emailDrafts.get(draftKey);
    const user = field(form, 'recipient_user_id', 'Workspace recipient', retained?.values.recipient_user_id || '', 'text', [{ id: '', name: 'Choose a recipient' }, ...members.filter(m => m.area_access)]);
    const templates = [...catalog.items.filter(t => t.status === 'published').map(t => ({ ...t, key: 'saved:' + t.id })), ...catalog.starters.map(t => ({ ...t, key: 'starter:' + t.id }))];
    const template = field(form, '', 'Start with a template', '', 'text', [{ id: '', name: 'Write a custom update' }, ...templates.map(t => ({ id: t.key, name: t.name }))]); template.removeAttribute('name');
    const subject = field(form, 'subject', 'Subject', retained?.values.subject || record.title), text = field(form, 'text', 'Message', retained?.values.text || '', 'textarea');
    const ctaLabel = field(form, 'cta_label', 'Button label (optional)', retained?.values.cta_label || ''), ctaUrl = field(form, 'cta_url', 'Button link (https)', retained?.values.cta_url || '', 'url');
    if (retained && connections.some(c => c.id === retained.connection)) connection.value = retained.connection;
    note('Write in plain language; Teams formats the email and includes a plain-text alternative. Template fields use reviewed details only. Missing details appear in the preview for correction.', form);
    const preview = section('Review before preparing', body); let revision = 0, request = retained?.request || crypto.randomUUID();
    const content = () => ({ record_id: record.id, recipient_user_id: Number(user.value), subject: subject.value, text: text.value, cta_label: ctaLabel.value, cta_url: ctaUrl.value });
    const invalidate = () => { revision++; request = crypto.randomUUID(); emailDrafts.set(draftKey, { area: currentArea, recordId: record.id, values: content(), connection: connection.value, request }); updateDraftNotice(); preview.replaceChildren(el('h3', 'Review before preparing')); note('Preview this version before preparing it.', preview); };
    form.oninput = invalidate;
    template.onchange = () => { const selected = templates.find(t => t.key === template.value); if (!selected) return; subject.value = selected.data.subject; text.value = selected.data.text; ctaLabel.value = selected.data.cta_label || ''; ctaUrl.value = selected.data.cta_url || ''; invalidate(); };
    form.append(button('Preview formatted email', async () => {
      const ticket = revision, sender = connection.value, result = await api('/communications/templates/preview', { method: 'POST', body: content() }); if (ticket !== revision || !body.isConnected) return;
      preview.replaceChildren(el('h3', result.subject)); note('From: ' + connections.find(c => c.id === sender).account_name + ' · To: ' + members.find(m => m.id === user.value)?.name, preview); emailPreview(result, preview);
      if (result.missing_fields.length) { note('Complete before sending: ' + result.missing_fields.map(label).join(', '), preview); return; }
      note('This is a preview. Prepare the message to retain a reviewed send draft.', preview);
      preview.append(button('Prepare this reviewed email', async () => { if (ticket !== revision) return; const prepared = await api('/communications', { method: 'POST', body: { ...content(), request_id: request, kind, expected_record_version: result.record_version, connection_id: sender, formatted: true, reviewed: true } }); if (ticket !== revision || !body.isConnected) return; const action = section('Prepared email', preview); note(prepared.notice, action); action.append(button('Send this reviewed email', async () => { const sent = await api('/communications/' + prepared.id + '/dispatch', { method: 'POST', body: { reviewed: true } }); if (sent.status === 'accepted') { emailDrafts.delete(draftKey); updateDraftNotice(); } note('Outcome: ' + label(sent.status) + '. Provider acceptance is recorded separately from delivery.', action); })); }, 'primary'));
    }, 'primary'));
  }
  function renderAttempts(items) { note('Your prepared and submitted actions. Unknown outcomes require reconciliation; retrying does not send a second copy.'); if (!items.length) empty('No communication attempts yet', 'Prepare an update from an event or account.'); else table(['Action', 'State', 'Created', 'Review'], items.map(a => [label(a.kind), label(a.status), new Date(a.created_at).toLocaleString(), ['unknown', 'executing'].includes(a.status) ? button('Reconcile outcome', async () => { const result = await api('/communications/' + a.id + '/reconcile', { method: 'POST' }); await load(); if (result.notice) $('notice').textContent = result.notice; }) : '—'])); }
  addEventListener('beforeunload', event => { if (draft?.dirty || drafts.size || emailDrafts.size) { event.preventDefault(); event.returnValue = ''; } });
  document.addEventListener('visibilitychange', () => { clearInterval(timer); if (!document.hidden) timer = setInterval(() => { if (!$('panel').hidden && !draft?.dirty && !$('detail').open && !$('editor').open) load(); }, 60000); });
  document.dispatchEvent(new Event('visibilitychange'));
  api('/areas').then(async data => { areas = data.items; renderAreas(); const url = new URL(location.href), initial = areas.find(a => a.id === url.searchParams.get('area')); if (initial) await openArea(initial, url.searchParams.get('area_view') || 'overview'); }).catch(report);
}
