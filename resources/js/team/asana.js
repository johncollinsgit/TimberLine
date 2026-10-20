import { renderPlanning } from './planning.js';
/** Live read-only Asana UI. No source content or credentials enter browser storage. */
export function installAsana(root, api) {
  const { node } = api, $ = id => document.getElementById('tw-' + id);
  let connection, selectedProject = '', projects = new Map(), pages = new Map(), generation = 0, renderGeneration = 0, catalogCursor = null, catalog = new Map(), loading = false, timer;
  const active = new Set();
  const endpoint = path => { const value = new URL(root.dataset.asanaUrl + path, location.origin); value.searchParams.set('tenant', root.dataset.tenant); return value; };
  function safeLink(url, label) { const link = node('a', label); try { const parsed = new URL(url); if (parsed.protocol !== 'https:') return node('span', label); link.href = parsed.href; link.target = '_blank'; link.rel = 'noopener noreferrer'; } catch { return node('span', label); } return link; }
  async function request(path, { method = 'GET', body } = {}) {
    const epoch = generation, controller = new AbortController(); active.add(controller);
    try {
      const response = await fetch(endpoint(path), { credentials: 'same-origin', method, signal: controller.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'X-Team-Viewer': root.dataset.user, 'X-Team-Workspace': root.dataset.workspaceId, ...(connection?.connection_id ? { 'X-Team-Connection': connection.connection_id } : {}) }, body: body === undefined ? undefined : JSON.stringify(body) });
      if (epoch !== generation) throw new Error('Connection changed.');
      const identity = response.headers.get('X-Team-Viewer') === root.dataset.user && response.headers.get('X-Team-Workspace') === root.dataset.workspaceId;
      if ([401, 419].includes(response.status) || (response.ok && !identity)) { reset(); api.context?.invalidate(); throw new Error('Your account changed. Reload Teams to verify access.'); }
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        if (data.error === 'page_identity_changed') { reset(); api.context?.invalidate(); throw new Error('Your account changed. Verify access before continuing.'); }
        if (response.status === 403) { pages.clear(); projects.clear(); $('asana-work').replaceChildren(); $('asana-project-nav')?.replaceChildren(); if ($('asana-detail').open) $('asana-detail-body').replaceChildren(node('p', 'This Asana work is no longer accessible.', 'teams-source-error')); }
        if (response.status === 409) { reset(); $('asana-connection-status').textContent = 'Reconnect your Asana account.'; }
        const delay = response.headers.get('Retry-After'); if (response.status === 429 && delay) pauseUntil = Date.now() + Number(delay) * 1000;
        throw Object.assign(new Error(data.message || 'Asana could not load this work.'), { status: response.status });
      }
      if (path !== '/selection' && connection?.connection_id && data.connection_id && data.connection_id !== connection.connection_id) { reset(); throw new Error('Your Asana connection changed. Reload Teams.'); }
      return data;
    } finally { active.delete(controller); }
  }
  function reset() { generation++; renderGeneration++; for (const controller of active) controller.abort(); active.clear(); pages.clear(); projects.clear(); catalog.clear(); connection = null; selectedProject = ''; loading = false; $('asana-work').replaceChildren(); $('asana-project-nav')?.replaceChildren(); $('asana-catalog').replaceChildren(); $('asana-workspace').replaceChildren(); $('asana-connection-status').textContent = ''; $('asana-picker-status').textContent = ''; $('asana-project-query').value = ''; chosen.clear(); $('asana-picker').close(); $('asana-detail-body').replaceChildren(); $('asana-detail').close(); clearTimeout(timer); }
  let pauseUntil = 0;
  function message(text, error = false) { $('asana-notice').textContent = text; $('asana-notice').className = error ? 'teams-source-error' : 'muted'; }
  function schedule() { clearTimeout(timer); timer = setTimeout(async () => { if (api.context?.canRefreshAsana()) await refresh(); else schedule(); }, 60000); }
  async function status() {
    const result = await request('/status'); connection = result;
    $('asana-connection-status').textContent = result.connected ? result.account_name + ' · Read only' : result.status === 'reconnect' ? 'Reconnect to continue' : 'Connect Asana to bring your work together';
    $('asana-connect').textContent = result.status === 'reconnect' ? 'Reconnect Asana' : 'Connect Asana'; $('asana-connect').hidden = result.connected; $('asana-disconnect').hidden = !result.connection_id; $('asana-choose').hidden = !result.connected;
    $('asana-connect').disabled = !result.configured; $('asana-setup').hidden = result.configured;
    return result;
  }
  $('asana-connect').onclick = async () => { try { const data = await request('/connect', { method: 'POST' }); location.assign(data.url); } catch (error) { $('asana-picker-status').textContent = error.message; } };
  $('asana-disconnect').onclick = async () => { try { await request('/connection', { method: 'DELETE' }); reset(); await status(); $('asana-picker').close(); } catch (error) { $('asana-picker-status').textContent = error.message; } };
  $('asana-manage').onclick = async () => { if (!api.context?.ready()) return; $('asana-picker').showModal(); try { await status(); chosen = new Set(connection.selected_projects.map(String)); if (connection.connected) await loadWorkspaces(); } catch (error) { $('asana-picker-status').textContent = error.message; } };
  $('asana-choose').onclick = () => loadWorkspaces().catch(error => { $('asana-picker-status').textContent = error.message; });
  $('asana-catalog-more').onclick = () => loadCatalog(true).catch(error => { $('asana-picker-status').textContent = error.message; });
  $('asana-archive').onchange = () => loadCatalog(false).catch(error => { $('asana-picker-status').textContent = error.message; });
  $('asana-project-query').oninput = renderCatalog;
  let workspaceCursor;
  async function loadWorkspaces(append = false) {
    const result = await request('/workspaces' + (append && workspaceCursor ? '?cursor=' + encodeURIComponent(workspaceCursor) : ''));
    if (!append) $('asana-workspace').replaceChildren();
    for (const workspace of result.items) $('asana-workspace').append(new Option(workspace.name, workspace.gid));
    workspaceCursor = result.next_cursor; $('asana-workspaces-more').hidden = !result.has_more;
    if (!append && result.items.length) await loadCatalog(false);
  }
  $('asana-workspaces-more').onclick = () => loadWorkspaces(true).catch(error => { $('asana-picker-status').textContent = error.message; });
  $('asana-workspace').onchange = () => loadCatalog(false).catch(error => { $('asana-picker-status').textContent = error.message; });
  let chosen = new Set();
  async function loadCatalog(append) {
    if (!append) { catalog.clear(); catalogCursor = null; }
    $('asana-picker-status').textContent = 'Loading your accessible projects…';
    const result = await request('/projects?workspace=' + $('asana-workspace').value + '&archived=' + ($('asana-archive').checked ? '1' : '0') + (append && catalogCursor ? '&cursor=' + encodeURIComponent(catalogCursor) : ''));
    result.items.forEach(project => catalog.set(String(project.gid), project)); catalogCursor = result.next_cursor; renderCatalog();
    $('asana-picker-status').textContent = catalog.size + ' accessible projects loaded. Search filters loaded projects. ' + (result.has_more ? 'Load more to continue.' : ''); $('asana-catalog-more').hidden = !result.has_more; $('asana-save-selection').hidden = false;
  }
  function renderCatalog() {
    const container = $('asana-catalog'); container.replaceChildren(); const query = $('asana-project-query').value.toLowerCase();
    for (const project of catalog.values()) { if (!project.name.toLowerCase().includes(query)) continue; const label = node('label', '', 'teams-connection-project'), check = node('input'); check.type = 'checkbox'; check.checked = chosen.has(String(project.gid)); check.onchange = () => { if (check.checked) chosen.add(String(project.gid)); else chosen.delete(String(project.gid)); }; label.append(check, node('span', project.name), node('span', project.archived ? 'Archived' : 'Asana', 'teams-asana-badge')); container.append(label); }
  }
  $('asana-save-selection').onclick = async () => { try { connection = await request('/selection', { method: 'PUT', body: { projects: [...chosen] } }); pages.clear(); projects.clear(); selectedProject = ''; $('asana-picker').close(); await api.context.load(); } catch (error) { $('asana-picker-status').textContent = error.message; } };
  $('asana-close').onclick = () => $('asana-picker').close();
  $('asana-detail-close').onclick = () => $('asana-detail').close();
  let detailEpoch = 0, detailId = null;
  $('asana-detail').addEventListener('close', () => { detailEpoch++; detailId = null; $('asana-detail-body').replaceChildren(); });
  async function openTask(id) {
    if (!$('asana-detail').open && !api.context?.ready()) return; api.context.clearDetail(); const epoch = ++detailEpoch;
    const dialog = $('asana-detail'), body = $('asana-detail-body'); body.replaceChildren(node('p', 'Loading task…', 'muted')); if (!dialog.open) dialog.showModal();
    try {
      const task = await request('/tasks/' + id); if (epoch !== detailEpoch) return; detailId = id;
      body.replaceChildren(); body.append(node('span', 'Asana · Read only', 'teams-asana-badge'), node('h2', task.name), safeLink(task.permalink_url, 'Open in Asana ↗'));
      const info = node('dl', '', 'teams-asana-properties'); for (const [label, value] of [['Assignee', task.assignee?.name || 'Unassigned'], ['Due', task.due_on || (task.due_at ? new Date(task.due_at).toLocaleString() : 'No due date')], ['Status', task.completed ? 'Completed' : 'Incomplete'], ...(task.custom_fields || []).map(field => [field.name, field.display_value ?? '—'])]) info.append(node('dt', label), node('dd', value)); body.append(info);
      body.append(node('h3', 'Description')); const description = node('p', task.notes || 'No description.', 'teams-original-notes'); body.append(description);
      for (const [kind, label] of [['subtasks', 'Subtasks'], ['attachments', 'Files'], ['stories', 'Conversation & activity']]) { const section = node('section', '', 'comment'); section.append(node('h3', label)); body.append(section); await related(id, kind, section, null, epoch); }
    } catch (error) { if (epoch === detailEpoch) { body.replaceChildren(node('p', error.message, 'teams-source-error')); } }
  }
  async function related(id, kind, section, cursor, epoch) {
    try {
      const result = await request('/tasks/' + id + '/' + kind + (cursor ? '?cursor=' + encodeURIComponent(cursor) : '')); if (epoch !== detailEpoch) return;
      for (const item of result.items) {
        if (kind === 'attachments') section.append(safeLink(item.permanent_url, item.name));
        else if (kind === 'subtasks') { const button = node('button', (item.completed ? '✓ ' : '○ ') + item.name, 'task-open'); button.onclick = () => openTask(item.gid); section.append(button); }
        else { const story = node('div', '', 'comment'); story.append(node('small', (item.created_by?.name || 'Asana') + ' · ' + new Date(item.created_at).toLocaleString()), node('p', item.text || 'Activity')); section.append(story); }
      }
      if (!result.items.length && !cursor) section.append(node('p', 'None returned by Asana.', 'muted'));
      if (result.has_more) { const more = node('button', 'Load more'); more.onclick = async () => { more.disabled = true; await related(id, kind, section, result.next_cursor, epoch); more.remove(); }; section.append(more); }
    } catch (error) { if (epoch === detailEpoch) section.append(node('p', error.message, 'teams-source-error')); }
  }
  function taskRow(task) {
    const row = node('div', '', 'task-row' + (task.completed ? ' is-complete' : '')); row.dataset.sourceKey = task.key;
    const complete = node('span', task.completed ? '✓' : '○', 'complete'); complete.setAttribute('aria-label', task.completed ? 'Completed in Asana' : 'Incomplete in Asana'); const open = node('button', task.name, 'task-open'); open.type = 'button'; open.onclick = () => openTask(task.gid);
    const assignee = node('div', task.assignee?.name || 'Unassigned', 'teams-task-assignee'), due = node('span', task.due_on || (task.due_at ? new Date(task.due_at).toLocaleDateString() : 'No date'), 'teams-due'); row.append(complete, open, assignee, due, node('span', 'Asana', 'teams-asana-badge'), node('span', '↗')); return row;
  }
  function navigation() {
    const container = $('asana-project-nav'); if (!container) return; container.replaceChildren();
    for (const [id, project] of projects) { const button = node('button', '', 'teams-project-button'); button.append(node('span', '', 'teams-project-dot'), node('span', project.name)); if (selectedProject === id) button.setAttribute('aria-current', 'page'); button.onclick = () => { if (!api.context?.ready()) return; selectedProject = id; $('source-filter').value = 'asana'; $('project-filter').value = ''; $('mode').value = 'all'; api.context.clearDetail(); document.body.classList.remove('teams-menu-open'); api.context.load(); }; container.append(button); }
  }
  function paint(ctx) {
    navigation();
    const work = $('asana-work'); work.replaceChildren();
    if (!ctx || $('mode').value === 'inbox' || $('source-filter').value === 'native' || (!selectedProject && ctx.selectedProject)) return;
    if (!connection?.connected) {
      const panel = node('div', '', 'teams-empty'); panel.append(node('h3', 'Bring your work together'), node('p', 'Connect your Asana account to view selected projects here.'));
      const connect = node('button', 'Set up Asana'); connect.onclick = () => $('asana-manage').click(); panel.append(connect); work.append(panel); return;
    }
    const query = $('search').value.trim().toLowerCase(), onlyMine = ['home', 'mine'].includes($('mode').value), completed = $('completion').value;
    const candidates = new Map();
    for (const [projectId, page] of pages) { if (selectedProject && selectedProject !== projectId) continue; for (const task of page.items) { const prior = candidates.get(task.key); if (!prior) candidates.set(task.key, task); else prior.memberships = [...new Map([...prior.memberships, ...task.memberships].map(member => [member.project.gid, member])).values()]; } }
    let tasks = [...candidates.values()].filter(task => (!query || task.name.toLowerCase().includes(query) || (task.custom_fields || []).some(field => String(field.display_value || '').toLowerCase().includes(query))) && (!onlyMine || String(task.assignee?.gid) === String(connection?.external_user_id)) && (completed !== 'completed' || task.completed));
    const dateFilter = $('date-filter').value, timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC', today = new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
    tasks = tasks.filter(task => { const day = task.due_on || (task.due_at ? new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date(task.due_at)) : null); if (dateFilter === 'undated') return !day; if (dateFilter === 'today') return day === today; if (dateFilter === 'overdue') return day && day < today; if (dateFilter === 'tomorrow') { const next = new Date(today + 'T12:00:00'); next.setDate(next.getDate() + 1); return day === next.toLocaleDateString('en-CA'); } if (dateFilter === 'week') { const current = new Date(today + 'T12:00:00'), start = new Date(current); start.setDate(current.getDate() - (current.getDay() + 6) % 7); const end = new Date(start); end.setDate(end.getDate() + 6); return day && day >= start.toLocaleDateString('en-CA') && day <= end.toLocaleDateString('en-CA'); } return true; });
    if ($('priority-filter').value) { work.append(node('p', 'Asana priority is represented by custom fields and cannot use the native priority filter.', 'teams-source-error')); tasks = []; }
    if (onlyMine) tasks = tasks.filter(task => !task.completed || $('mode').value !== 'home');
    if (ctx.layout === 'calendar') tasks = tasks.filter(task => (task.due_on || task.due_at?.slice(0, 10) || '').slice(0, 7) === $('month').value);
    const sort = $('sort').value; if (['due', 'start', 'title'].includes(sort)) tasks.sort((a, b) => String(sort === 'title' ? a.name : a[sort + '_on'] || a[sort + '_at'] || '9999').localeCompare(String(sort === 'title' ? b.name : b[sort + '_on'] || b[sort + '_at'] || '9999')) || a.gid.localeCompare(b.gid));
    const heading = node('div', '', 'teams-connection-title'); heading.append(node('h2', selectedProject ? projects.get(selectedProject)?.name || 'Asana work' : 'Your Asana work'), node('span', 'Live · Read only', 'teams-asana-badge')); work.append(heading);
    const partial = [...pages.values()].some(page => page.has_more);
    work.append(node('p', `${tasks.length} matching tasks in loaded Asana pages. ${partial ? 'More source work is available below. ' : ''}Teams totals above exclude Asana. Search applies to loaded task titles and fields.`, 'muted'));
    const list = node('div', '', ctx.layout === 'board' ? 'board' : 'teams-asana-list');
    if (['timeline', 'workload', 'dashboard'].includes(ctx.layout)) {
      const members = [...new Map(tasks.filter(task => task.assignee).map(task => [String(task.assignee.gid), { id: String(task.assignee.gid), name: task.assignee.name }])).values()];
      renderPlanning(list, tasks.map(task => ({ ...task, id: task.gid, title: task.name, completed_at: task.completed ? 'completed' : null, assignee_id: task.assignee ? String(task.assignee.gid) : null })), { node, layout: ctx.layout, members, openTask });
    } else if (ctx.layout === 'board') {
      const groups = new Map(); for (const task of tasks) { const section = task.memberships.find(member => !selectedProject || String(member.project.gid) === selectedProject)?.section; const id = section?.gid || ''; if (!groups.has(id)) groups.set(id, { name: section?.name || 'Unsectioned', tasks: [] }); groups.get(id).tasks.push(task); }
      for (const group of groups.values()) { const column = node('section', '', 'board-column'); column.append(node('h2', group.name + ' · ' + group.tasks.length)); group.tasks.forEach(task => column.append(taskRow(task))); list.append(column); }
    } else if (ctx.layout === 'calendar' && $('calendar-style').value !== 'agenda') {
      const wrap = node('div', '', 'teams-calendar-wrap'), calendar = node('div', '', 'teams-calendar'); for (const day of ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']) calendar.append(node('div', day, 'teams-calendar-weekday'));
      const [year, month] = $('month').value.split('-').map(Number), first = new Date(Date.UTC(year, month - 1, 1)), offset = (first.getUTCDay() + 6) % 7, count = new Date(Date.UTC(year, month, 0)).getUTCDate();
      for (let index = 0; index < Math.ceil((offset + count) / 7) * 7; index++) { const day = index - offset + 1; if (day < 1 || day > count) { calendar.append(node('div', '', 'teams-calendar-empty')); continue; } const cell = node('section', '', 'teams-calendar-day'); cell.append(node('div', String(day), 'teams-calendar-date')); const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`; tasks.filter(task => (task.due_on || task.due_at?.slice(0, 10)) === date).forEach(task => cell.append(taskRow(task))); calendar.append(cell); } wrap.append(calendar); list.append(wrap);
    } else tasks.forEach(task => list.append(taskRow(task)));
    work.append(list);
    if (!tasks.length) work.append(node('p', connection?.selected_projects.length ? 'No loaded Asana tasks match this view.' : 'Choose projects to see your Asana work.', 'teams-empty'));
    for (const [id, page] of pages) if (page.has_more && (!selectedProject || selectedProject === id)) { const more = node('button', 'Load more from ' + (projects.get(id)?.name || 'Asana')); more.onclick = async () => { more.disabled = true; try { await loadProject(id, page.next_cursor); paint(api.context); } catch (error) { message(error.message, true); more.disabled = false; } }; work.append(more); }
    navigation();
    if (selectedProject) $('page-title').textContent = projects.get(selectedProject)?.name || 'Asana project';
  }
  async function loadProject(id, cursor) {
    const complete = ['all', 'completed'].includes($('completion').value) && $('mode').value !== 'home', response = await request('/tasks?project=' + id + '&completed=' + (complete ? '1' : '0') + (cursor ? '&cursor=' + encodeURIComponent(cursor) : ''));
    projects.set(String(id), response.project); if (connection) connection.external_user_id = response.external_user_id;
    const items = cursor ? [...(pages.get(id)?.items || []), ...response.items] : response.items; pages.set(id, { ...response, items });
  }
  async function refresh(force = false) {
    if (loading || document.hidden || !api.context || pauseUntil > Date.now()) return;
    const current = ++renderGeneration; loading = true;
    try {
      if (!connection || force) await status();
      if (!connection?.connected) { paint(api.context); return; }
      if (detailId && $('asana-detail').open) await request('/tasks/' + detailId);
      message('Refreshing Asana…');
      const ids = connection.selected_projects.map(String); const wanted = selectedProject ? ids.filter(id => id === selectedProject) : ids;
      // Clear source pages before fresh reads: errors never leave revoked source content on screen.
      pages.clear(); $('asana-work').replaceChildren();
      const errors = [];
      for (const id of wanted) { try { await loadProject(id); } catch (error) { projects.delete(id); if (error.name === 'AbortError') throw error; errors.push(error.message); } if (current !== renderGeneration) return; }
      paint(api.context); message(errors.length ? 'Some Asana work could not load. ' + [...new Set(errors)].join(' ') : ids.length ? 'Updated ' + new Date().toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) + ' · Read only' : 'Connect your day: choose Asana projects to get started.', errors.length > 0);
    } catch (error) { if (error.name !== 'AbortError') message(error.message, true); }
    finally { loading = false; schedule(); }
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) { generation++; renderGeneration++; active.forEach(controller => controller.abort()); active.clear(); pages.clear(); projects.clear(); $('asana-work').replaceChildren(); $('asana-project-nav')?.replaceChildren(); $('asana-detail').close(); clearTimeout(timer); loading = false; } else if (api.context?.canRefreshAsana()) refresh(true); else schedule(); });
  let filterSignature;
  return {
    get selectedProject() { return selectedProject; },
    clearProject() { selectedProject = ''; },
    reset,
    invalidate() { connection = null; pages.clear(); filterSignature = undefined; },
    render(ctx) { const signature = [$('completion').value, $('mode').value === 'home'].join(':'); if (filterSignature !== signature) { filterSignature = signature; pages.clear(); } navigation(); paint(ctx); if (!connection || !pages.size) refresh(); },
  };
}
