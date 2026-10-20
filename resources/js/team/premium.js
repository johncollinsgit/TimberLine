import { installAsana } from './asana.js';
import { renderPlanning } from './planning.js';

const icons = {
  home: '<path d="m3 10 9-7 9 7v10H4V10m5 10v-7h6v7"/>',
  mine: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  all: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  inbox: '<path d="M4 4h16l2 12v4H2v-4L4 4Zm-2 12h6l2 3h4l2-3h6"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.1M3 12h.1M3 18h.1"/>',
  board: '<rect x="3" y="4" width="7" height="16" rx="1"/><rect x="14" y="4" width="7" height="10" rx="1"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18"/>',
  search: '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
};
function icon(name) { const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.innerHTML = icons[name] || icons.all; return svg; }
export function installPremium(root) {
  let context, focusedRow, favorites = new Set(), views = [], projectSearch = '', preferenceRequest = 0;
  let selectionMode = false, selectedIds = new Set(), completionBatch = null, completing = false;
  const $ = id => document.getElementById('tw-' + id);
  const node = (tag, text = '', className = '') => { const item = document.createElement(tag); item.textContent = text; item.className = className; return item; };
  const api = { get context() { return context; }, node, icon, get favorites() { return favorites; } };
  const asana = root.dataset.asanaEnabled === '1' ? installAsana(root, api) : null;
  if (root.dataset.surface === 'embedded') {
    const topbar = document.querySelector('[data-app-shell-topbar]');
    const updateInset = () => root.style.setProperty('--teams-host-offset', Math.max(0, topbar?.getBoundingClientRect().bottom || 0) + 'px');
    if (topbar) new ResizeObserver(updateInset).observe(topbar);
    addEventListener('resize', updateInset); updateInset();
  }
  document.querySelectorAll('[data-team-mode]').forEach(button => button.prepend(icon(button.dataset.teamMode)));
  root.querySelectorAll('[data-team-layout]').forEach(button => { button.prepend(icon(button.dataset.teamLayout)); button.setAttribute('aria-controls', 'tw-list-card'); button.onclick = () => { if (!context?.ready()) return; if (['home', 'inbox'].includes($('mode').value)) $('mode').value = 'all'; $('layout').value = button.dataset.teamLayout; $('layout').dispatchEvent(new Event('change', { bubbles: true })); }; });
  root.querySelector('.teams-tabs').addEventListener('keydown', event => { if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return; event.preventDefault(); const tabs = [...root.querySelectorAll('[data-team-layout]')], index = tabs.indexOf(document.activeElement), next = tabs[(index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length]; next.focus(); next.click(); });
  root.querySelectorAll('[data-team-search-icon]').forEach(item => item.prepend(icon('search')));
  $('filter-toggle').onclick = () => { const expanded = $('filters').hidden; $('filters').hidden = !expanded; $('filter-toggle').setAttribute('aria-expanded', String(expanded)); };
  $('clear-filters').onclick = () => { if (!context?.ready()) return; $('completion').value = 'incomplete'; $('priority-filter').value = ''; $('date-filter').value = 'all'; $('sort').value = ''; $('search').value = ''; $('completion').dispatchEvent(new Event('change', { bubbles: true })); };
  $('refresh').onclick = () => { if (context?.ready()) { asana?.invalidate(); context.load(); } };
  $('calendar-style').onchange = () => { if (context?.ready()) context.load(); };
  $('more-views').onchange = () => { if (!$('more-views').value || !context?.ready()) return; if (['home', 'inbox'].includes($('mode').value)) $('mode').value = 'all'; $('layout').value = $('more-views').value; $('layout').dispatchEvent(new Event('change', { bubbles: true })); };
  $('save-view').onclick = async () => { if (!context?.ready()) return; $('view-name').value = ''; $('view-dialog').showModal(); };
  $('view-close').onclick = () => $('view-dialog').close();
  $('view-form').onsubmit = async event => { event.preventDefault(); const name = $('view-name').value.trim(); if (!name) return; const view = { id: crypto.randomUUID(), name, layout: $('layout').value, project: $('project-filter').value || null, completion: $('completion').value, sort: $('sort').value || null, date_filter: $('date-filter').value }; if (await preferences({ views: [...views, view] })) $('view-dialog').close(); };
  $('saved-views').onchange = () => { const view = views.find(item => item.id === $('saved-views').value); if (!view || !context?.ready()) return; asana?.clearProject(); $('source-filter').value = 'native'; $('layout').value = view.layout; $('project-filter').value = view.project || ''; $('completion').value = view.completion; $('sort').value = view.sort || ''; $('date-filter').value = view.date_filter; $('mode').value = 'all'; $('search').value = ''; $('layout').dispatchEvent(new Event('change', { bubbles: true })); };
  $('remove-view').onclick = async () => { const id = $('saved-views').value; if (!id || !context?.ready()) return; await preferences({ views: views.filter(view => view.id !== id) }); };
  $('saved-views').addEventListener('change', () => { $('remove-view').disabled = !$('saved-views').value; });
  const menu = document.querySelector('[data-teams-menu]');
  const sidebar = document.querySelector('.teams-sidebar');
  const backdrop = node('button', '', 'teams-menu-backdrop'); backdrop.type = 'button'; backdrop.tabIndex = -1; backdrop.hidden = true; backdrop.setAttribute('aria-label', 'Dismiss navigation'); document.body.append(backdrop);
  function syncMenu() {
    if (!sidebar || !menu) return;
    const mobile = innerWidth <= 800, open = mobile && document.body.classList.contains('teams-menu-open');
    menu.setAttribute('aria-expanded', String(open)); sidebar.inert = mobile && !open; root.inert = open; backdrop.hidden = !open;
    if (open) { sidebar.setAttribute('role', 'dialog'); sidebar.setAttribute('aria-modal', 'true'); }
    else { sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal'); if (mobile && sidebar.contains(document.activeElement)) menu.focus(); }
  }
  function setMenu(open) { document.body.classList.toggle('teams-menu-open', open); syncMenu(); if (open) document.querySelector('[data-teams-menu-close]')?.focus(); }
  if (menu) menu.onclick = () => setMenu(!document.body.classList.contains('teams-menu-open'));
  backdrop.onclick = () => setMenu(false);
  document.querySelector('[data-teams-menu-close]')?.addEventListener('click', () => setMenu(false));
  sidebar?.addEventListener('click', event => { if (event.target.closest('#teams-create')) setMenu(false); });
  addEventListener('resize', () => { if (innerWidth > 800) document.body.classList.remove('teams-menu-open'); syncMenu(); });
  new MutationObserver(syncMenu).observe(document.body, { attributes: true, attributeFilter: ['class'] }); syncMenu();
  $('project-nav-search')?.addEventListener('input', event => { projectSearch = event.target.value.toLowerCase(); if (context) navigation(context); });
  let sourceChoice = 'all';
  $('source-filter').onchange = () => { if (context?.ready()) { sourceChoice = $('source-filter').value; asana?.clearProject(); context.load(); } else $('source-filter').value = sourceChoice; };
  const resize = node('div', '', 'teams-resize'); resize.setAttribute('role', 'separator'); resize.setAttribute('aria-label', 'Task panel width'); resize.setAttribute('aria-orientation', 'vertical'); resize.tabIndex = 0; resize.hidden = true; root.append(resize);
  resize.setAttribute('aria-valuemin', '360'); resize.setAttribute('aria-valuemax', String(Math.round(innerWidth * .8))); resize.setAttribute('aria-valuenow', '480');
  $('detail').setAttribute('role', 'dialog');
  const detailMode = () => $('detail').setAttribute('aria-modal', String(innerWidth <= 600)); detailMode(); addEventListener('resize', detailMode);
  const setWidth = width => { const value = Math.max(360, Math.min(innerWidth * .8, width)); document.body.style.setProperty('--detail-width', value + 'px'); resize.setAttribute('aria-valuenow', String(Math.round(value))); };
  resize.addEventListener('keydown', event => { if (['ArrowLeft', 'ArrowRight'].includes(event.key)) { event.preventDefault(); setWidth($('detail').getBoundingClientRect().width + (event.key === 'ArrowLeft' ? 30 : -30)); } });
  resize.onpointerdown = event => { resize.setPointerCapture(event.pointerId); resize.onpointermove = e => setWidth(innerWidth - e.clientX); resize.onpointerup = () => { resize.onpointermove = null; }; };
  const observer = new MutationObserver(() => { resize.hidden = !root.classList.contains('detail-open') || $('detail').hidden; }); observer.observe(root, { attributes: true, attributeFilter: ['class'] }); observer.observe($('detail'), { attributes: true, attributeFilter: ['hidden'] });
  function detailTabs() {
    const tabs = node('div', '', 'teams-detail-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Task information');
    const panels = {};
    const groups = { Details: ['edit-form', 'dependencies', 'repeat-form', 'task-template-form', 'fields', 'subtasks', 'membership-form', 'personal-sharing-form', 'personal-grants'], Files: ['files', 'file-form', 'file-status', 'file-progress', 'file-cancel'], Conversation: ['comments', 'comment-form'], Activity: ['history'] };
    // Move whole existing sections, preserving every original handler and form identity.
    const moved = new Set();
    for (const [name, ids] of Object.entries(groups)) {
      const panel = node('div', '', 'teams-detail-section'); panel.id = 'teams-panel-' + name.toLowerCase(); panel.setAttribute('role', 'tabpanel'); panel.hidden = name !== 'Details';
      for (const id of ids) { let item = $(id); if (!item) continue; while (item.parentElement !== $('detail') && item.parentElement !== root) item = item.parentElement; if (item.parentElement === $('detail') && !moved.has(item)) { panel.append(item); moved.add(item); } }
      panels[name] = panel; $('detail').append(panel);
      const button = node('button', name); button.type = 'button'; button.id = 'teams-tab-' + name.toLowerCase(); button.setAttribute('role', 'tab'); button.setAttribute('aria-selected', String(name === 'Details')); button.setAttribute('aria-controls', panel.id); panel.setAttribute('aria-labelledby', button.id);
      button.onclick = () => { for (const [key, value] of Object.entries(panels)) value.hidden = key !== name; for (const tab of tabs.children) tab.setAttribute('aria-selected', String(tab === button)); }; tabs.append(button);
    }
    $('detail-meta').after(tabs);
    tabs.addEventListener('keydown', event => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const buttons = [...tabs.children], index = buttons.indexOf(document.activeElement); const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length; buttons[next].focus(); buttons[next].click(); });
    new MutationObserver(() => { if ($('detail').dataset.loadedId) { tabs.firstElementChild.click(); if (!$('detail').contains(document.activeElement)) { $('detail-heading').tabIndex = -1; $('detail-heading').focus({ preventScroll: true }); } } }).observe($('detail'), { attributes: true, attributeFilter: ['data-loaded-id'] });
  }
  detailTabs();
  async function preferences(value) {
    const sequence = ++preferenceRequest;
    try {
      const data = await context.preference(value);
      if (sequence !== preferenceRequest) return;
      favorites = new Set(data.favorites || []); views = data.views || []; const current = $('saved-views').value; $('saved-views').replaceChildren(new Option('Saved views', '')); for (const view of views) $('saved-views').append(new Option(view.name, view.id)); $('saved-views').value = views.some(view => view.id === current) ? current : ''; $('remove-view').disabled = !$('saved-views').value; if (context) navigation(context); return true;
    } catch (error) { context?.notice(error.message); return false; }
  }
  let loadedPreferences = false;
  function projectButton(project, container, ctx) {
    const button = node('button', '', 'teams-project-button'); button.type = 'button'; button.dataset.projectId = project.id;
    button.append(node('span', '', 'teams-project-dot'), node('span', project.name));
    if ($('project-filter').value === project.id && !asana?.selectedProject) button.setAttribute('aria-current', 'page');
    button.onclick = () => { if (!ctx.ready()) return; asana?.clearProject(); $('source-filter').value = 'all'; ctx.navigateProject(project.id); setMenu(false); };
    container.append(button);
  }
  function navigation(ctx) {
    const projectNav = document.getElementById('teams-project-list'), favoriteNav = document.getElementById('teams-favorite-list');
    for (const container of [projectNav, favoriteNav]) container?.replaceChildren();
    for (const project of ctx.projects.filter(p => !p.archived && p.name.toLowerCase().includes(projectSearch))) { if (projectNav) projectButton(project, projectNav, ctx); if (favorites.has(project.id) && favoriteNav) projectButton(project, favoriteNav, ctx); }
    if (projectNav && !projectNav.childElementCount) projectNav.append(node('p', 'Your projects will appear here.', 'teams-projects-empty'));
    if (favoriteNav && !favoriteNav.childElementCount) favoriteNav.append(node('p', 'Star a project to keep it close.', 'teams-projects-empty'));
    const project = ctx.projects.find(p => p.id === $('project-filter').value);
    $('page-title').textContent = project?.name || ({ home: 'Home', mine: 'My Tasks', inbox: 'Inbox', all: 'All work' }[$('mode').value] || 'Your work');
    $('favorite-project').hidden = !project; $('favorite-project').textContent = favorites.has(project?.id) ? '★ Starred' : '☆ Star project';
    $('favorite-project').setAttribute('aria-pressed', String(favorites.has(project?.id)));
    $('favorite-project').onclick = () => { if (!project || !ctx.ready()) return; const next = new Set(favorites); if (next.has(project.id)) next.delete(project.id); else next.add(project.id); preferences({ favorites: [...next] }); };
  }
  function row(task, ctx, layout) {
    const item = node('div', '', 'task-row' + (task.completed_at ? ' is-complete' : '')); item.dataset.taskId = task.id;
    const complete = node('button', task.completed_at ? '✓' : '', 'complete'); complete.setAttribute('aria-label', (task.completed_at ? 'Reopen ' : 'Complete ') + task.title); complete.disabled = !task.can_edit; complete.onclick = () => ctx.mutate(task, { kind: 'task.complete', completed: !task.completed_at });
    const open = node('button', task.title, 'task-open'); open.type = 'button'; open.onclick = () => { focusedRow = open; ctx.openTask(task.id); };
    const fieldProject = ctx.projects.find(project => project.id === ctx.selectedProject);
    if (fieldProject && task.custom_fields?.[fieldProject.id]) { const fields = Object.entries(task.custom_fields[fieldProject.id]).filter(([, value]) => value !== null && value !== ''); if (fields.length) open.append(node('small', fields.slice(0, 3).map(([id, value]) => (fieldProject.custom_fields?.[id]?.name || 'Field') + ': ' + (typeof value === 'object' ? JSON.stringify(value) : String(value))).join(' · '))); }
    const person = ctx.members.find(member => member.id === task.assignee_id), assignee = node('div', '', 'teams-task-assignee');
    assignee.append(node('span', person?.name?.split(' ').map(v => v[0]).slice(0, 2).join('') || '–', 'teams-avatar'));
    if (task.can_edit && layout === 'list') {
      const select = node('select', '', 'teams-inline'); select.setAttribute('aria-label', 'Assignee for ' + task.title); select.append(new Option('Unassigned', '')); for (const member of ctx.members) select.append(new Option(member.name, member.id)); if (task.assignee_id && !person) select.append(new Option('Current assignee', task.assignee_id)); select.value = task.assignee_id || ''; select.onchange = async () => { select.disabled = true; const value = select.value; const saved = await ctx.inlineUpdate(task, { assignee_id: value || null }); if (!saved) { select.value = task.assignee_id || ''; select.disabled = false; } }; assignee.append(select);
    } else assignee.append(node('span', person?.name || (task.assignee_id ? 'Assigned member' : 'Unassigned'), 'teams-person'));
    const due = node('div', '', 'teams-due');
    if (task.can_edit && layout === 'list' && !task.due_at) { const input = node('input', '', 'teams-inline'); input.type = 'date'; input.value = task.due_on || ''; input.setAttribute('aria-label', 'Due date for ' + task.title); input.onchange = async () => { input.disabled = true; const saved = await ctx.inlineUpdate(task, { due_on: input.value || null }); if (!saved) { input.value = task.due_on || ''; input.disabled = false; } }; due.append(input); }
    else due.textContent = task.due_on ? new Date(task.due_on + 'T12:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : task.due_at ? new Date(task.due_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'No date';
    if ((task.due_on || '').slice(0, 10) < new Date().toLocaleDateString('en-CA') && task.due_on && !task.completed_at) due.classList.add('teams-overdue');
    const priority = node('span', task.priority || 'normal', 'teams-priority teams-priority-' + (task.priority || 'normal'));
    const more = node('button', '···', 'teams-star'); more.setAttribute('aria-label', 'Open actions for ' + task.title); more.onclick = open.onclick;
    if (selectionMode) { const check = node('input'); check.type = 'checkbox'; check.className = 'teams-task-select'; check.setAttribute('aria-label', 'Select ' + task.title); check.checked = selectedIds.has(task.id); check.onchange = () => { if (check.checked) selectedIds.add(task.id); else selectedIds.delete(task.id); selectionBar(ctx); }; item.append(check); } else item.append(complete);
    item.append(open, assignee, due, priority, more);
    if (layout === 'board') {
      const project = ctx.projects.find(p => p.id === ctx.selectedProject);
      if (task.can_edit && project?.can_add_task) {
        const move = node('select'); move.setAttribute('aria-label', 'Move ' + task.title + ' to section'); move.append(new Option('No section', '')); for (const [id, name] of Object.entries(project.sections)) move.append(new Option(name, id)); move.value = task.memberships[project.id]?.section_id || '';
        const moveTo = section => ctx.mutate(task, { kind: 'task.membership', project_id: project.id, section_id: section || null, position: task.memberships[project.id]?.position || 0 }); move.onchange = async () => { move.disabled = true; if (!await moveTo(move.value)) { move.value = task.memberships[project.id]?.section_id || ''; move.disabled = false; } }; item.append(move); item.draggable = true; item.ondragstart = event => { if (!ctx.ready()) { event.preventDefault(); return; } event.dataTransfer.setData('application/x-team-task', task.id); item.classList.add('teams-dragging'); }; item.ondragend = () => item.classList.remove('teams-dragging');
      }
    }
    return item;
  }
  function empty(container, title, message) { const block = node('div', '', 'teams-empty'); block.append(icon('mine'), node('h3', title), node('p', message)); container.append(block); }
  function selectionBar(ctx) {
    $('selection-bar')?.remove(); const bar = node('div', '', 'teams-selection-bar'); bar.id = 'tw-selection-bar';
    const toggle = node('button', selectionMode ? 'Done selecting' : 'Select tasks'); toggle.onclick = () => { if (completing || !ctx.ready()) return; selectionMode = !selectionMode; selectedIds.clear(); ctx.load(); }; bar.append(toggle);
    if (selectionMode) {
      const count = node('span', selectedIds.size + ' selected', 'muted'), all = node('button', 'Select loaded tasks'); all.onclick = () => { selectedIds = new Set(ctx.data.tasks.map(task => task.id)); renderNative(ctx); };
      const complete = node('button', completionBatch ? 'Retry same completion' : 'Complete selected'); complete.disabled = completing || (!completionBatch && (!selectedIds.size || ctx.data.tasks.some(task => selectedIds.has(task.id) && !task.can_edit)));
      complete.onclick = async () => {
        if (completing || !ctx.ready()) return;
        completionBatch ??= ctx.data.tasks.filter(task => selectedIds.has(task.id) && task.can_edit).map(task => ({ kind: 'task.complete', id: task.id, expected_version: task.version, completed: true, idempotency_key: crypto.randomUUID() }));
        completing = true; complete.disabled = true;
        try { while (completionBatch.length) { await ctx.command(completionBatch[0]); completionBatch.shift(); } completionBatch = null; selectedIds.clear(); ctx.notice('Selected tasks completed.'); await ctx.load(); }
        catch (error) { ctx.notice('Completion is unconfirmed. Retry the same completion request. ' + error.message); }
        finally { completing = false; if (context) selectionBar(context); }
      };
      bar.append(count, all, complete);
    }
    $('list').before(bar);
  }
  function renderNative(ctx) {
    const list = $('list'), data = ctx.data, layout = ctx.layout, project = ctx.projects.find(p => p.id === ctx.selectedProject);
    list.className = layout === 'board' ? 'board' : ''; list.replaceChildren();
    selectedIds = new Set([...selectedIds].filter(id => data.tasks.some(task => task.id === id))); selectionBar(ctx);
    if (['timeline', 'workload', 'dashboard'].includes(layout)) { renderPlanning(list, data.tasks, { node, layout, members: ctx.members, openTask: ctx.openTask }); return; }
    if (layout === 'board') {
      if (!project) { empty(list, 'A place for every next step', 'Choose a project to see its section board.'); return; }
      for (const [id, name] of [['', 'Unsectioned'], ...Object.entries(project.sections)]) {
        const column = node('section', '', 'board-column'), tasks = data.tasks.filter(t => (t.memberships[project.id]?.section_id || '') === id), heading = node('h2', name); heading.append(node('span', String(tasks.length), 'teams-section-count')); column.append(heading);
        for (const task of tasks) column.append(row(task, ctx, layout));
        if (project.can_add_task) { const add = node('button', '+ Add task'); add.onclick = () => { if (!ctx.ready()) return; ctx.createTask(); $('create-section').value = id; }; column.append(add); }
        column.ondragover = e => { if (!e.dataTransfer.types.includes('application/x-team-task')) return; e.preventDefault(); column.classList.add('teams-drop-target'); }; column.ondragleave = () => column.classList.remove('teams-drop-target'); column.ondrop = e => { e.preventDefault(); column.classList.remove('teams-drop-target'); const task = data.tasks.find(t => t.id === e.dataTransfer.getData('application/x-team-task')); if (task?.can_edit && project.can_add_task && ctx.ready()) ctx.mutate(task, { kind: 'task.membership', project_id: project.id, section_id: id || null, position: task.memberships[project.id]?.position || 0 }); }; list.append(column);
      }
    } else if (layout === 'calendar' && $('calendar-style').value !== 'agenda') {
      const wrap = node('div', '', 'teams-calendar-wrap'), calendar = node('div', '', 'teams-calendar'); calendar.setAttribute('aria-label', 'Task calendar, UTC');
      for (const day of ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']) calendar.append(node('div', day, 'teams-calendar-weekday'));
      const [year, month] = $('month').value.split('-').map(Number), start = new Date(Date.UTC(year, month - 1, 1)), offset = (start.getUTCDay() + 6) % 7, days = new Date(Date.UTC(year, month, 0)).getUTCDate();
      for (let index = 0; index < Math.ceil((offset + days) / 7) * 7; index++) { const day = index - offset + 1; if (day < 1 || day > days) { calendar.append(node('div', '', 'teams-calendar-empty')); continue; } const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`, cell = node('section', '', 'teams-calendar-day'), title = node('div', '', 'teams-calendar-date'); title.append(node('span', String(day))); if (new Date().toISOString().slice(0, 10) === date) cell.classList.add('is-today'); if (data.can_create_task) { const add = node('button', '+'); add.setAttribute('aria-label', 'Create task for ' + date); add.onclick = () => ctx.ready() && ctx.createTask(null, date); title.append(add); } cell.append(title); for (const task of data.tasks.filter(t => (t.due_on || t.due_at?.slice(0, 10)) === date)) cell.append(row(task, ctx, layout)); calendar.append(cell); }
      wrap.append(calendar); list.append(wrap); const undated = node('button', 'View unscheduled work'); undated.onclick = () => { if (!ctx.ready()) return; $('layout').value = 'list'; $('date-filter').value = 'undated'; $('date-filter').dispatchEvent(new Event('change', { bubbles: true })); }; list.append(undated);
    } else {
      const header = node('div', '', 'teams-table-head'); for (const label of ['', 'Task name', 'Assignee', 'Due date', 'Priority', '']) header.append(node('span', label)); list.append(header);
      const groups = project ? [['', 'Unsectioned'], ...Object.entries(project.sections)] : [['all', $('mode').value === 'home' ? 'Next up for you' : 'Tasks']];
      const sections = layout === 'calendar' ? [...new Set(data.tasks.map(t => t.due_on || t.due_at?.slice(0, 10) || 'No date'))].map(date => [date, date]) : groups;
      for (const [id, name] of sections) { const tasks = data.tasks.filter(t => layout === 'calendar' ? (t.due_on || t.due_at?.slice(0, 10) || 'No date') === id : !project || (t.memberships[project.id]?.section_id || '') === id); if (!tasks.length) continue; const section = node('details', '', 'teams-section'); section.open = true; const heading = node('summary', name, 'teams-section-heading'); heading.append(node('span', String(tasks.length), 'teams-section-count')); section.append(heading); tasks.forEach(task => section.append(row(task, ctx, layout))); list.append(section); }
      if (!data.tasks.length) empty(list, 'A little room to breathe', 'No tasks match this view. Adjust your filters or create the next step.');
    }
  }
  document.addEventListener('keydown', event => {
    if (document.body.classList.contains('teams-menu-open') && innerWidth <= 800) {
      if (event.key === 'Escape') { event.preventDefault(); setMenu(false); return; }
      if (event.key === 'Tab') { const controls = [...sidebar.querySelectorAll('button,a[href],input,select,textarea')].filter(item => !item.disabled && item.getClientRects().length); if (event.shiftKey && document.activeElement === controls[0] || !event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); (event.shiftKey ? controls.at(-1) : controls[0])?.focus(); } }
      return;
    }
    if (event.key === 'Tab' && innerWidth <= 600 && root.classList.contains('detail-open') && !$('detail').hidden && !document.querySelector('dialog[open]')) { const controls = [...$('detail').querySelectorAll('button,a[href],input,select,textarea,[tabindex="0"]')].filter(item => !item.disabled && item.getClientRects().length); if ((event.shiftKey && (document.activeElement === controls[0] || document.activeElement === $('detail-heading'))) || (!event.shiftKey && document.activeElement === controls.at(-1))) { event.preventDefault(); (event.shiftKey ? controls.at(-1) : controls[0])?.focus(); } }
    if (event.key === 'Escape') { if (document.querySelector('dialog[open]')) return; if (root.classList.contains('detail-open')) { $('draft-close').click(); focusedRow?.focus(); } document.body.classList.remove('teams-menu-open'); }
    const typing = /INPUT|TEXTAREA|SELECT/.test(event.target.tagName) || event.target.isContentEditable;
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); if (context?.ready()) $('search').focus(); }
    if (typing || event.metaKey || event.ctrlKey || event.altKey || document.querySelector('dialog[open]')) return;
    if (event.key === 'n' && context?.ready()) { event.preventDefault(); ctxCreate(); }
    if (['ArrowDown', 'ArrowUp'].includes(event.key) && event.target.closest('#tw-list')) { const rows = [...$('list').querySelectorAll('.task-open')]; const current = rows.indexOf(document.activeElement); event.preventDefault(); rows[Math.max(0, Math.min(rows.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)))]?.focus(); }
  });
  function ctxCreate() { if (context?.data.can_create_task) context.createTask(); }
  return {
    get context() { return context; },
    navigate() { asana?.clearProject(); $('source-filter').value = 'all'; setMenu(false); },
    reset() { asana?.reset(); for (const id of ['teams-project-list', 'teams-favorite-list']) document.getElementById(id)?.replaceChildren(); $('selection-bar')?.remove(); selectedIds.clear(); selectionMode = false; $('page-title').textContent = 'Verify your work access'; favorites.clear(); views = []; $('saved-views').replaceChildren(new Option('Saved views', '')); $('view-name').value = ''; $('view-dialog').close(); loadedPreferences = false; context = null; },
    render(ctx) {
      context = ctx; if (!loadedPreferences) { loadedPreferences = true; preferences(); }
      $('more-views').value = ['timeline', 'workload', 'dashboard'].includes(ctx.layout) ? ctx.layout : '';
      navigation(ctx); root.querySelectorAll('[data-team-layout]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.teamLayout === ctx.layout)));
      const activeFilters = ['completion', 'priority-filter', 'date-filter', 'sort'].filter(id => $(id).value !== ({ completion: 'incomplete', 'date-filter': 'all' }[id] || ''));
      $('filter-toggle').textContent = '☷ Filters' + (activeFilters.length ? ' · ' + activeFilters.length : '');
      $('filter-toggle').title = activeFilters.map(id => $(id).selectedOptions[0]?.textContent).join(' · ');
      $('clear-filters').disabled = !activeFilters.length && !$('search').value;
      $('remove-view').disabled = !$('saved-views').value;
      $('calendar-style').hidden = ctx.layout !== 'calendar'; $('month-label').hidden = ctx.layout !== 'calendar';
      for (const button of $('home-counts').children) { const match = button.textContent.match(/^(.*): (\d+)$/); if (match) button.replaceChildren(node('span', match[1]), node('strong', match[2], 'teams-home-number')); }
      $('home-title').textContent = 'Make room for your best work.';
      const nativeHidden = $('mode').value !== 'inbox' && ($('source-filter').value === 'asana' || !!asana?.selectedProject);
      $('save-view').disabled = nativeHidden;
      $('count').hidden = nativeHidden; $('list-heading').hidden = nativeHidden;
      if (nativeHidden) { $('prev').hidden = true; $('next').hidden = true; }
      if ($('mode').value !== 'inbox') { renderNative(ctx); $('list').hidden = nativeHidden; }
      else { $('list').hidden = false; $('list').classList.add('teams-inbox'); }
      if ($('selection-bar')) $('selection-bar').hidden = nativeHidden || $('mode').value === 'inbox';
      asana?.render(ctx); window.TeamOperations?.renderPersonal(ctx); return $('mode').value !== 'inbox';
    },
  };
}
