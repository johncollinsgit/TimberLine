(() => {
  'use strict';
  const root = document.getElementById('team-work-app'); if (!root) return;
  const premium = root.dataset.premium === '1' ? window.TeamPremium : null;
  const $ = id => document.getElementById('tw-' + id);
  let projects = [], members = [], selected = null, editingProject = null, parentId = null, page = 1, generation = 0;
  let createTaskId, createTaskKey, createProjectId, createProjectKey;
  let detailGeneration = 0, projectSearchPage = 1;
  let followUpDraft = null;
  let overview = null, statusDraft = null, statusGeneration = 0, statusBefore = null, statusCandidates = [], statusOwnerProbe = 0;
  let pendingMutations = 0, workspaceNavigating = false, workspacePage = 1, workspaceGeneration = 0;
  let taskFiles = [], fileDraft = null, fileAbort = null;
  let dependencyCandidates = [], templateCatalog = [], templateSelection = null, templateRequest = 0, canUseTemplates = false, templateCapture = null;
  const status = message => {
    if (workspaceNavigating) return;
    $('status').textContent = message;
    for (const dialog of root.querySelectorAll('dialog[open]')) {
      let notice = dialog.querySelector('.dialog-status');
      if (!notice) { notice = document.createElement('p'); notice.className = 'dialog-status'; notice.setAttribute('role', 'status'); dialog.prepend(notice); }
      notice.textContent = message;
    }
  };
  // A completed operation must not leave a stale saving/error message in a reopened editor.
  for (const dialog of root.querySelectorAll('dialog')) dialog.addEventListener('beforetoggle', event => {
    if (event.newState === 'open') dialog.querySelector('.dialog-status')?.remove();
  });
  const element = (tag, value, className) => { const e = document.createElement(tag); e.textContent = value; if (className) e.className = className; return e; };
  const standalone = root.dataset.surface === 'standalone';
  function surfaceUrl(value) {
    const target = new URL(value, location.origin), embedded = new URL(root.dataset.otherEntryUrl, location.origin), own = new URL(root.dataset.entryUrl, location.origin);
    if (target.origin !== location.origin) throw new Error('This work link is outside this application.');
    if (standalone && target.pathname === embedded.pathname) target.pathname = own.pathname;
    return target;
  }
  function syncSurfaceLinks() {
    const current = new URL(location.href);
    for (const link of document.querySelectorAll('[data-team-surface-link]')) {
      const target = new URL(root.dataset.otherEntryUrl, location.origin);
      for (const key of ['task', 'comment', 'project']) if (current.searchParams.has(key)) target.searchParams.set(key, current.searchParams.get(key));
      link.href = target.href;
    }
    for (const button of document.querySelectorAll('[data-team-mode]')) { if (button.dataset.teamMode === $('mode').value) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current'); }
  }
  const url = path => { const u = new URL(path, location.origin); u.searchParams.set('tenant', root.dataset.tenant); return u; };
  async function request(path, init = {}) {
    if (workspaceNavigating) throw new Error('Opening the selected workspace.');
    if (coreAccessBlocked) throw new Error('Verify the original account and work access first.');
    const mutation = !['GET', 'HEAD'].includes((init.method || 'GET').toUpperCase());
    if (mutation) pendingMutations++;
    try {
    const epoch = coreEpoch;
    const response = await fetch(url(path), { credentials: 'same-origin', ...init, headers: { Accept: 'application/json', ...init.headers, 'X-Team-Viewer': root.dataset.user, 'X-Team-Workspace': root.dataset.workspaceId } });
    const data = await response.json();
    if (epoch !== coreEpoch) throw new Error('Access verification changed. The result is unconfirmed.');
    const identityChanged = response.ok && (response.headers.get('X-Team-Viewer') !== root.dataset.user || response.headers.get('X-Team-Workspace') !== root.dataset.workspaceId);
    if (workspaceNavigating) throw new Error('Opening the selected workspace.');
    if (!response.ok || identityChanged) {
      if (identityChanged || [401, 403, 404, 419].includes(response.status)) {
        const retainedStatus = statusDraft?.retained || (statusDraft && projectStatusDirty() ? { code: $('status-code').value, summary: $('status-summary').value, highlights: $('status-highlights').value, blockers: $('status-blockers').value, next: $('status-next').value } : null);
        const retained = followUpDraft && ($('follow-title').value.trim() || $('follow-description').value.trim() || followUpDraft.payload) ? { title: $('follow-title').value, notes: $('follow-description').value } : null;
        $('follow-source').textContent = '';
        sealCore();
        if (retainedStatus) { statusDraft.references = []; sealStatusDraft(retainedStatus, data.saved === false, await verifyStatusOwner()); }
        if (retained) {
          followUpDraft.retained = retained; followUpDraft.ownerVerified = await verifyStatusOwner() === 'owner';
          $('follow-title').value = followUpDraft.ownerVerified ? retained.title : ''; $('follow-description').value = followUpDraft.ownerVerified ? retained.notes : '';
          $('follow-discard').disabled = !followUpDraft.ownerVerified;
          followUpDraft.sealed = true; followUpDraft.retrySafe = data.saved === false;
          $('follow-source').textContent = 'Access changed. Source details are hidden. Your new draft text is retained here for copying or explicit discard; creation is not confirmed.';
          for (const control of $('follow-form').querySelectorAll('input,textarea,select')) control.disabled = true;
          for (const id of ['follow-title', 'follow-description']) { $(id).disabled = false; $(id).readOnly = true; }
          $('follow-submit').disabled = true; $('follow-dialog').showModal();
        }
        throw Object.assign(new Error('Access or session changed. The save result may be unconfirmed. Verify the original account and access.'), { status: response.status, saved: data.saved, reason: data.error });
      }
      const messages = { assignee_lacks_access: 'This person cannot access the task. Your changes were not saved. Share the project or choose another assignee.', invalid_assignee: 'This assignee is unavailable. Your changes were not saved. Choose an active workspace member.', sharing_review_required: 'Review the sharing checkbox before saving. Your changes have not been saved.', invalid_date_order: 'The start date must be before the due date. Your changes were not saved.', invalid_date: 'Use a valid date or a time with an explicit time zone. Your changes were not saved.', invalid_mention: 'The mentioned person cannot access this task. Your comment was not saved. Choose another recipient.', recurrence_dst_gap_review_required: 'The next repeat time does not exist when clocks change. Nothing was saved. Change its due time or stop recurrence before completing.', recurrence_dst_overlap_review_required: 'The next repeat time occurs twice. Nothing was saved. Choose the earlier or later occurrence in recurrence settings.', recurrence_subtasks_require_review: 'This task has subtasks. Stop recurrence before completing, or review the subtask plan. Nothing was saved.', dependency_cycle: 'This link would create a dependency cycle. Nothing was saved.', template_restricted_or_too_large: 'This snapshot contains restricted descendants or exceeds 50 tasks / 20 sections. Source work is unchanged.', invalid_section: 'This section is no longer available. Your changes were not saved. Choose another section.' };
      const problem = new Error(response.status === 409 ? 'This record changed. Your text is still in the editor; copy it before reopening and reviewing both versions.' : Object.values(data.errors || {}).flat().join(' ') || messages[data.error] || 'The update was not saved. Review the fields and try again.');
      Object.assign(problem, { status: response.status, saved: data.saved ?? (response.status === 422 && data.errors ? false : undefined), reason: data.error }); throw problem;
    }
    return data;
    } finally { if (mutation) pendingMutations--; }
  }
  function workspaceReady() {
    if (coreAccessBlocked || coreSave || coreSaving || coreClosed) { coreNotice(); status('Resume retained work or confirm the current upload or save before changing workspace.'); return false; }
    if (projectStatusDirty()) { status('Finish or explicitly discard your project status draft first. Your text stays here.'); return false; }
    if (followUpDraft && ($('follow-title').value.trim() || $('follow-description').value.trim() || followUpDraft.payload)) { status('Finish or explicitly discard your follow-up draft before changing workspace or opening related work. Your text stays here.'); return false; }
    if (pendingMutations || fileAbort || fileDraft || $('file-input').files.length) { status('Finish or cancel the current upload or save before changing workspace. Your work stays here.'); return false; }
    if (selected && (!taskDraftReady() || !planningDraftReady())) return false;
    if ($('comment-body').value.trim()) { status('Post or clear your unsent comment before changing workspace. Your text stays here.'); return false; }
    if ([...root.querySelectorAll('dialog[open]')].some(dialog => dialog.id !== 'tw-workspace-dialog')) { status('Finish or close the open editor before changing workspace. Your input stays here.'); return false; }
    return true;
  }
  async function loadWorkspaces() {
    const current = ++workspaceGeneration;
    $('workspace-list').replaceChildren(); $('workspace-status').textContent = 'Loading accessible workspaces.';
    $('workspace-prev').hidden = true; $('workspace-next').hidden = true;
    try {
      const endpoint = url(root.dataset.workspacesUrl); endpoint.searchParams.set('q', $('workspace-query').value); endpoint.searchParams.set('page', workspacePage);
      const data = await request(endpoint); if (current !== workspaceGeneration) return;
      if (data.workspace?.id !== root.dataset.workspaceId) throw new Error('Workspace response did not match this page. Reload to verify access.');
      for (const item of data.items) {
        const row = element('div', '', 'task-row'), button = element('button', item.name + (item.current ? ' (current workspace)' : ''));
        button.type = 'button'; button.disabled = item.current;
        button.onclick = () => {
          if (!workspaceReady()) return;
          const target = surfaceUrl(item.url);
          if (target.origin !== location.origin || target.pathname !== new URL(root.dataset.entryUrl, location.origin).pathname || target.searchParams.get('tenant') !== item.slug) { status('This workspace link is unavailable. Refresh the list.'); return; }
          workspaceNavigating = true; generation++; detailGeneration++; workspaceGeneration++;
          root.setAttribute('aria-busy', 'true'); $('workspace-status').textContent = 'Opening ' + item.name + '.';
          for (const control of root.querySelectorAll('button,input,select,textarea')) control.disabled = true;
          location.assign(target.href);
        };
        row.append(button); $('workspace-list').append(row);
      }
      $('workspace-status').textContent = data.items.length ? 'Page ' + data.page + ' of accessible Team workspaces.' : 'No matching accessible Team workspaces.';
      $('workspace-prev').hidden = workspacePage <= 1; $('workspace-next').hidden = !data.has_more;
    } catch (error) { if (current === workspaceGeneration) $('workspace-status').textContent = error.message; }
  }
  $('workspaces').onclick = () => { if (!workspaceReady()) return; workspacePage = 1; $('workspace-query').value = ''; $('workspace-dialog').showModal(); loadWorkspaces(); };
  $('workspace-search').onsubmit = event => { event.preventDefault(); workspacePage = 1; loadWorkspaces(); };
  $('workspace-prev').onclick = () => { if (workspacePage > 1) { workspacePage--; loadWorkspaces(); } };
  $('workspace-next').onclick = () => { workspacePage++; loadWorkspaces(); };
  // Restored-document handling is installed with the draft guard below.
  async function command(input) {
    status('Saving…');
    return request(root.dataset.commandUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ ...input, idempotency_key: input.idempotency_key || crypto.randomUUID() }) });
  }
  function options(select, values, emptyLabel = 'Unassigned') {
    select.replaceChildren(new Option(emptyLabel, ''));
    for (const item of values) select.add(new Option(item.name, item.id));
  }
  function sections(projectSelect, sectionSelect) {
    const project = projects.find(p => p.id === projectSelect.value);
    options(sectionSelect, Object.entries(project?.sections || {}).map(([id, name]) => ({ id, name })), 'No section');
  }
  function clearDetail() {
    root.dispatchEvent(new Event('team:detail-close'));
    const address = new URL(location.href); address.searchParams.delete('task'); address.searchParams.delete('comment'); if ($('project-filter').value) address.searchParams.set('project', $('project-filter').value); else address.searchParams.delete('project'); window.history.replaceState(null, '', address); syncSurfaceLinks();
    $('copy-link').disabled = true; $('follow-up').disabled = true; $('follow-up-context').hidden = true; $('follow-up-context').replaceChildren();
    fileAbort?.abort(); fileAbort = null; fileDraft = null; taskFiles = [];
    $('files').replaceChildren(); $('file-form').hidden = true; $('file-progress').value = 0; $('file-status').textContent = ''; $('file-input').value = '';
    detailGeneration++;
    selected = null; delete $('detail').dataset.loadedId; $('detail').setAttribute('aria-busy', 'false'); root.classList.remove('detail-open'); $('detail-heading').textContent = 'Task details'; $('detail-meta').textContent = 'Open a task to review its context.';
    for (const id of ['title', 'description', 'start', 'due']) { $(id).value = ''; $(id).disabled = true; }
    options($('assignee'), members); $('priority').value = 'normal';
    for (const id of ['assignee', 'priority', 'save', 'complete', 'subtask']) $(id).disabled = true;
    $('comment-body').value = ''; $('comment-edit-body').value = ''; editingCommentId = null; planningInitial = null;
    $('comments').replaceChildren(); $('subtasks').replaceChildren(); $('fields').replaceChildren(); $('dependencies').replaceChildren(); $('repeat-form').reset(); $('task-template-form').reset(); $('dependency-task').replaceChildren(); $('dependency-status').textContent = ''; $('repeat-status').textContent = ''; $('repeat-next').hidden = true; $('repeat-editor').hidden = true; $('dependency-controls').hidden = true; $('task-template-form').hidden = true; dependencyCandidates = []; $('history').textContent = ''; $('comment-form').hidden = true; $('membership-form').hidden = true; $('personal-sharing-form').hidden = true; $('personal-grants').replaceChildren();
  }
  function clearProjectResults() {
    $('project-results').hidden = true; $('project-result-list').replaceChildren(); $('project-result-context').textContent = '';
    $('project-result-prev').hidden = true; $('project-result-next').hidden = true;
  }
  function renderProjectResults(result) {
    clearProjectResults(); if (!$('search').value.trim()) return;
    $('project-results').hidden = false;
    $('project-result-context').textContent = result.items.length + ' of ' + result.total + ' matching projects on page ' + result.page + '. Searches names and descriptions across accessible projects, including archived projects. Task filters below do not limit these results.';
    for (const item of result.items) {
      const row = element('div', '', 'task-row'), open = element('button', item.name, 'task-open');
      open.type = 'button'; open.dataset.projectId = item.id;
      open.append(element('small', 'Project' + (item.archived ? ' - Archived (read only)' : ' - Open project')));
      open.onclick = () => {
        if (!workspaceReady()) return;
        $('project-filter').value = item.id; $('mode').value = 'all'; $('layout').value = 'list'; $('search').value = '';
        $('date-filter').value = 'all'; $('completion').value = 'all'; $('priority-filter').value = ''; $('sort').value = '';
        page = 1; projectSearchPage = 1; clearDetail(); load();
      };
      row.append(open); $('project-result-list').append(row);
    }
    if (!result.items.length) $('project-result-list').append(element('p', 'No matching projects on this page.', 'muted'));
    $('project-result-prev').hidden = result.page <= 1; $('project-result-next').hidden = !result.has_more;
  }
  $('project-result-prev').onclick = () => { if (projectSearchPage > 1) { projectSearchPage--; load(); } };
  $('project-result-next').onclick = () => { projectSearchPage++; load(); };
  async function load() {
    const current = ++generation; clearProjectResults();
    try {
      const endpoint = url(root.dataset.dataUrl); endpoint.searchParams.set('q', $('search').value); endpoint.searchParams.set('mine', ['mine', 'home'].includes($('mode').value) ? '1' : '0'); endpoint.searchParams.set('page', page); endpoint.searchParams.set('project_page', projectSearchPage);
      endpoint.searchParams.set('completion', $('mode').value === 'home' ? 'incomplete' : $('completion').value);
      endpoint.searchParams.set('date_filter', $('date-filter').value);
      endpoint.searchParams.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
      if ($('sort').value) endpoint.searchParams.set('sort', $('sort').value);
      if ($('priority-filter').value) endpoint.searchParams.set('priority', $('priority-filter').value);
      const layout = ['inbox', 'home'].includes($('mode').value) ? 'list' : $('layout').value;
      $('month-label').hidden = layout !== 'calendar';
      if (layout === 'calendar') endpoint.searchParams.set('month', $('month').value);
      if ($('project-filter').value) endpoint.searchParams.set('project_id', $('project-filter').value);
      const data = await request(endpoint); if (current !== generation) return;
      if (data.workspace?.id !== root.dataset.workspaceId) throw new Error('Workspace response did not match this page. Reload to verify access.');
      acceptedFilters = Object.fromEntries(filterIds.map(id => [id, $(id).value]));
      projects = data.projects; members = data.members; renderProjectResults(data.project_search); syncSurfaceLinks();
      canUseTemplates = data.can_create_project; templateCatalog = projects.flatMap(project => (project.templates || []).map(template => ({ ...template, project, choice: project.id + '/' + template.id }))); $('templates').disabled = !templateCatalog.length;
      $('home').hidden = $('mode').value !== 'home';
      for (const control of ['layout', 'completion']) $(control).closest('label').hidden = ['home', 'inbox'].includes($('mode').value); $('home-counts').replaceChildren();
      $('list-heading').textContent = ({ home: 'Your open work', mine: 'My Tasks', inbox: 'Inbox' })[$('mode').value] || 'Tasks';
      if (data.home) {
        const labels = { all: 'All open', today: 'Today', tomorrow: 'Tomorrow', overdue: 'Overdue', week: 'This week', undated: 'No due date' };
        for (const [filter, count] of Object.entries(data.home.counts)) { const button = element('button', labels[filter] + ': ' + count); button.onclick = () => { if (!coreNavigationReady()) return; $('date-filter').value = filter; $('project-filter').value = ''; $('search').value = ''; $('priority-filter').value = ''; page = 1; clearDetail(); load(); }; $('home-counts').append(button); }
        $('home-context').textContent = 'All accessible work assigned to you, excluding completed tasks. ' + data.home.today + ' in ' + data.home.timezone + '. Week is Monday through Sunday; counts may overlap. Calendar agenda uses UTC.';
      }
      const projectFilter = $('project-filter').value; options($('project-filter'), projects.map(p => ({ ...p, name: p.name + (p.archived ? ' · Archived' : '') })), 'All accessible projects'); $('project-filter').value = projectFilter;
      $('edit-project').hidden = !projects.find(p => p.id === projectFilter)?.can_edit; $('overview').hidden = !projects.some(p => p.id === projectFilter);
      $('new-project').disabled = !data.can_create_project; $('new-task').disabled = !data.can_create_task; const sidebarCreate = document.getElementById('teams-create'); if (sidebarCreate) sidebarCreate.disabled = !data.can_create_task;
      $('count').textContent = data.tasks.length + ' of ' + data.total + ' matching tasks on page ' + page; $('prev').hidden = page <= 1; $('next').hidden = !data.has_more;
      $('list').replaceChildren();
      $('list').classList.toggle('board', layout === 'board');
      if (premium?.render({ data, layout, page, projects, members, selectedProject: projectFilter, openTask, mutate, createTask, load, clearDetail, notice: status,
        ready: () => coreNavigationReady() && workspaceReady(), command,
        canRefreshAsana: () => !coreAccessBlocked && !coreDirty() && !pendingMutations && !workspaceNavigating,
        inlineUpdate: async (task, changes) => { if (!coreNavigationReady() || !workspaceReady()) return false; await openTask(task.id); if (selected?.id !== task.id || !selected.can_edit) return false; for (const [field, value] of Object.entries(changes)) $(({ assignee_id: 'assignee', due_on: 'due' })[field]).value = value || ''; return saveCore('edit', { kind: 'task.update', id: task.id, expected_version: selected.version, changes }); },
        invalidate: () => { sealCore(); status('Account changed. Verify your original account before continuing.'); },
        preference: values => request(root.dataset.preferencesUrl, values === undefined ? {} : { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(values) }),
        navigateProject: id => { if (!coreNavigationReady() || !workspaceReady()) return; enterProject(id); load(); }
      })) return;
      if ($('mode').value === 'inbox') {
        const inbox = await request(root.dataset.inboxUrl); if (current !== generation) return;
        $('count').textContent = inbox.items.length + ' accessible Inbox items'; $('prev').hidden = true; $('next').hidden = true;
        for (const item of inbox.items) {
          const row = element('div', '', 'task-row'); const open = element('button', item.title, 'task-open');
          open.append(element('small', (item.read_at ? 'Read · ' : 'Unread · ') + ({ 'task.assigned': 'Assigned to you', 'comment.mentioned': 'Mentioned in a comment', 'dependency.unblocked': 'Dependencies unblocked' }[item.event_type] || 'Work activity')));
          open.onclick = async () => { try { await inboxUpdate(item.id, { read: true }); if (item.operation_id && window.TeamOperations) await window.TeamOperations.open(item.area_id, item.operation_id); else await openTask(item.task_id); } catch (error) { status(error.message); } };
          const archive = element('button', 'Archive'); archive.onclick = async () => { try { await inboxUpdate(item.id, { archived: true }); await load(); } catch (error) { status(error.message); } };
          row.append(open, archive); $('list').append(row);
        }
        if (!inbox.items.length) $('list').append(element('p', 'No accessible Inbox activity.', 'muted'));
        return;
      }
      if (layout === 'board' && !projectFilter) { $('list').append(element('p', 'Choose a project to view its section board.')); return; }
      const targets = {};
      if (layout === 'board') {
        const project = projects.find(p => p.id === projectFilter);
        for (const [id, name] of [['', 'No section'], ...Object.entries(project.sections)]) {
          const column = element('section', '', 'board-column'); column.append(element('h2', name)); $('list').append(column); targets[id] = column;
        }
        $('count').textContent += '. Board columns show this page only; use Next page for more.';
      }
      if (layout === 'calendar') $('count').textContent += '. Due dates in this month, page ' + page + '. Timed deadlines use UTC; undated work is available in List.';
      for (const task of data.tasks) {
        const row = element('div', '', 'task-row' + (task.completed_at ? ' is-complete' : ''));
        const complete = element('button', task.completed_at ? '✓' : '○', 'complete'); complete.setAttribute('aria-label', (task.completed_at ? 'Reopen ' : 'Complete ') + task.title); complete.disabled = !task.can_edit;
        complete.onclick = () => mutate(task, { kind: 'task.complete', completed: !task.completed_at });
        const open = element('button', task.title, 'task-open'); open.append(element('small', (task.due_on || task.due_at || 'No due date') + ' · ' + (members.find(m => m.id === task.assignee_id)?.name || (task.assignee_id ? 'Assigned' : 'Unassigned'))));
        open.onclick = () => openTask(task.id); row.append(complete, open);
        let target = $('list');
        if (layout === 'board') {
          const project = projects.find(p => p.id === projectFilter); target = targets[task.memberships[projectFilter].section_id || ''];
          if (task.can_edit && project.can_add_task) {
            const move = document.createElement('select'); move.setAttribute('aria-label', 'Move ' + task.title + ' to section'); options(move, Object.entries(project.sections).map(([id, name]) => ({ id, name })), 'No section'); move.value = task.memberships[projectFilter].section_id || '';
            move.onchange = () => mutate(task, { kind: 'task.membership', project_id: projectFilter, section_id: move.value || null, position: task.memberships[projectFilter].position }); row.append(move);
          }
        }
        if (layout === 'calendar') {
          const day = task.due_on || new Date(task.due_at).toISOString().slice(0, 10);
          if (!targets[day]) { const section = element('section', '', 'comment'); section.append(element('h2', day)); const create = element('button', 'Create for ' + day); create.disabled = !data.can_create_task; create.onclick = () => createTask(null, day); section.append(create); $('list').append(section); targets[day] = section; }
          target = targets[day];
        }
        target.append(row);
      }
      if (!data.tasks.length) $('list').append(element('p', 'No accessible tasks match this view.', 'muted'));
    } catch (error) { status(error.message); }
  }
  async function openTask(id, commentId = null, internal = false) {
    if (!internal && !coreNavigationReady()) return;
    $('detail').hidden = false; clearDetail(); const current = detailGeneration; $('detail').setAttribute('aria-busy', 'true');
    try {
      const task = await request(root.dataset.taskUrl + '/' + id); if (current !== detailGeneration) return; task.url = surfaceUrl(task.url).href; selected = task; root.classList.add('detail-open'); $('detail-heading').textContent = task.title;
      $('detail-meta').textContent = 'Version ' + task.version + (task.parent_id ? ' · Subtask' : '') + (task.due_at ? ' · Timed deadline: ' + task.due_at : '');
      $('title').value = task.title; $('description').value = task.description; $('start').value = task.start_on || ''; $('due').value = task.due_on || '';
      options($('assignee'), members); if (task.assignee_id && !members.some(m => m.id === task.assignee_id)) $('assignee').add(new Option('Current assignee', task.assignee_id));
      $('assignee').value = task.assignee_id || ''; $('priority').value = task.priority;
      for (const control of ['title', 'description', 'assignee', 'start', 'due', 'priority', 'save', 'complete', 'subtask']) $(control).disabled = !task.can_edit;
      if (coreSaving || coreSave || coreAccessBlocked || coreRecovering) freezeCore(true);
      $('complete').textContent = task.completed_at ? 'Reopen task' : 'Complete task'; $('history').textContent = task.events.length + ' recorded activity events';
      const history = element('details', ''); history.append(element('summary', 'Activity history (UTC)'));
      const historyList = element('ol', '');
      for (const event of task.events) historyList.append(element('li', event.at + ' · ' + event.kind + (event.fields.length ? ' · ' + event.fields.join(', ') : '')));
      history.append(historyList); $('history').append(history);
      $('comments').replaceChildren();
      for (const comment of Object.values(task.comments)) {
        const row = element('div', '', 'comment'); row.dataset.commentId = comment.id; row.tabIndex = -1; row.append(element('p', comment.deleted_at ? 'Comment deleted' : (members.find(m => m.id === comment.author_id)?.name || 'Member') + ': ' + comment.body));
        if (task.can_edit && comment.author_id === root.dataset.user && !comment.deleted_at) {
          const edit = element('button', 'Edit comment'); edit.onclick = () => { if (!coreNavigationReady()) return; editingCommentId = comment.id; $('comment-edit-body').value = comment.body; $('comment-dialog').showModal(); };
          const remove = element('button', 'Delete comment'); remove.onclick = () => mutate(selected, { kind: 'task.comment.delete', comment_id: comment.id }); row.append(edit, remove);
        }
        if (!$('new-task').disabled && !comment.deleted_at) { const follow = element('button', 'Create follow-up from comment'); follow.type = 'button'; follow.onclick = () => startFollowUp(comment.id); row.append(follow); }
        $('comments').append(row);
      }
      options($('comment-mention'), members, 'No mention');
      $('file-form').hidden = !task.can_edit; await loadFiles(task.id, current); if (current !== detailGeneration) return;
      $('comment-form').hidden = !task.can_edit; $('membership-form').hidden = !task.can_edit;
      $('personal-sharing-form').hidden = !task.can_manage_sharing; $('personal-ack').checked = false;
      options($('personal-user'), members.filter(m => m.id !== task.creator_id), 'Choose a member');
      for (const [userId, grant] of Object.entries(task.collaborators || {})) $('personal-grants').append(element('p', (members.find(m => m.id === userId)?.name || 'Unavailable member') + ': ' + grant));
      renderSubtasks(task); renderFields(task); renderPlanning(task);
      options($('membership-project'), projects.filter(p => p.can_add_task), 'Choose a project'); $('sharing').checked = false; sections($('membership-project'), $('membership-section'));
      $('copy-link').disabled = false; $('follow-up').disabled = $('new-task').disabled;
      if (task.follow_up) {
        $('follow-up-context').hidden = false; $('follow-up-context').append(element('span', 'Follow-up source: ' + task.follow_up.title + (task.follow_up.source_changed ? ' (changed since this follow-up was created). ' : '. ')));
        const source = element('button', 'Open source task'); source.type = 'button'; source.onclick = () => { if (workspaceReady() && taskDraftReady() && planningDraftReady()) openTask(task.follow_up.task_id, task.follow_up.comment_id || null); }; $('follow-up-context').append(source);
      }
      const address = new URL(task.url, location.origin);
      if (commentId && task.comments[commentId] && !task.comments[commentId].deleted_at) {
        address.searchParams.set('comment', commentId); const row = [...$('comments').children].find(row => row.dataset.commentId === commentId); row?.scrollIntoView({ block: 'center' }); row?.focus();
      } else if (commentId) status('That comment is no longer available. The currently permitted task is open.');
      window.history.replaceState(null, '', address); syncSurfaceLinks();
      if (coreSaving || coreSave || coreAccessBlocked || coreRecovering) freezeCore(true);
      $('detail').dataset.loadedId = task.id; $('detail').setAttribute('aria-busy', 'false');
    } catch (error) { if (current !== detailGeneration) return; $('detail').setAttribute('aria-busy', 'false'); status(error.message); }
  }
  function followSource(task, commentId) {
    const comment = commentId ? task.comments[commentId] : null;
    if (commentId && (!comment || comment.deleted_at)) throw new Error('The source comment is no longer available. Your new text is retained.');
    $('follow-source').textContent = 'Source: ' + task.title + ' (version ' + task.version + ').' + (comment ? ' Comment preview: ' + comment.body.slice(0, 2000) + (comment.body.length > 2000 ? ' [preview shortened]' : '') : '');
    return { id: task.id, version: task.version };
  }
  function followDestinations(value = '') {
    const available = projects.filter(project => project.can_add_task);
    options($('follow-project'), available.map(project => ({ id: project.id, name: project.name + (project.privacy === 'private' ? ' (private project)' : ' (workspace-visible project)') })), 'Private personal task');
    if (value && !available.some(project => project.id === value)) { const old = new Option('Previously selected project is unavailable; choose a destination', value); old.disabled = true; $('follow-project').add(old); }
    $('follow-project').value = value; $('follow-project').required = root.dataset.role === 'guest';
  }
  function startFollowUp(commentId = null) {
    if (!coreNavigationReady()) return;
    if (projectStatusDirty()) { status('Finish your project status draft first. Your text stays here.'); return; }
    if (!selected || pendingMutations || fileAbort || fileDraft || $('file-input').files.length) { status('Finish or cancel the current save or file before creating a follow-up.'); return; }
    if (!taskDraftReady() || !planningDraftReady()) return;
    if ($('comment-body').value.trim()) { status('Post or clear your unsent comment first. Your text stays here.'); return; }
    if (followUpDraft) {
      if (followUpDraft.source.id !== selected.id || followUpDraft.commentId !== commentId) { status('Finish the existing follow-up draft before starting another. Your text stays here.'); $('follow-dialog').showModal(); return; }
      $('follow-dialog').showModal(); return;
    }
    $('follow-form').reset(); for (const control of $('follow-form').querySelectorAll('input,textarea,select,button')) control.disabled = false;
    for (const id of ['follow-title', 'follow-description']) $(id).readOnly = false;
    followDestinations(); options($('follow-assignee'), members); $('follow-submit').textContent = 'Create reviewed follow-up';
    followUpDraft = { id: crypto.randomUUID(), key: crypto.randomUUID(), source: followSource(selected, commentId), commentId, payload: null, retrySafe: true, sealed: false };
    $('follow-dialog').showModal();
  }
  $('follow-up').onclick = () => startFollowUp();
  $('copy-link').onclick = async () => {
    if (!selected) return; $('link-heading').textContent = 'Task link'; const link = selected.url;
    try { if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable'); await navigator.clipboard.writeText(link); status('Task link copied. Opening it requires current workspace and task access.'); }
    catch { $('link-value').value = link; $('link-dialog').showModal(); $('link-value').select(); status('Copy the displayed task link.'); }
  };
  $('follow-discard').onclick = () => {
    if (followUpDraft?.sealed && !followUpDraft.ownerVerified) { status('Verify the original account before accessing this draft.'); return; }
    if (followUpDraft?.payload && !followUpDraft.retrySafe) { status('Creation is unconfirmed. Retry the same request to confirm its result before discarding this draft.'); return; }
    if (!confirm('Discard this unsent follow-up draft? Source work and any already saved task remain unchanged.')) return;
    followUpDraft = null; $('follow-form').reset(); $('follow-source').textContent = ''; $('follow-dialog').close(); status('Unsent follow-up draft discarded. Source work is unchanged.');
  };
  $('follow-form').onsubmit = async event => {
    event.preventDefault(); const draft = followUpDraft; if (!draft || draft.sealed || pendingMutations) return;
    draft.payload ??= { kind: 'task.follow_up', id: draft.id, idempotency_key: draft.key, source_task_id: draft.source.id, source_version: draft.source.version,
      ...(draft.commentId ? { source_comment_id: draft.commentId } : {}), title: $('follow-title').value, description: $('follow-description').value,
      assignee_id: $('follow-assignee').value || null, due_on: $('follow-due').value || null, memberships: $('follow-project').value ? { [$('follow-project').value]: {} } : {}, destination_versions: $('follow-project').value ? { [$('follow-project').value]: projects.find(project => project.id === $('follow-project').value)?.version } : {}, acknowledge_sharing: $('follow-ack').checked };
    draft.retrySafe = false; for (const control of $('follow-form').querySelectorAll('input,textarea,select')) control.disabled = true; $('follow-submit').disabled = true;
    try {
      const reply = await command(draft.payload); followUpDraft = null; $('follow-form').reset(); $('follow-dialog').close(); $('mode').value = 'all'; $('search').value = ''; $('project-filter').value = ''; $('date-filter').value = 'all'; $('completion').value = 'all'; $('priority-filter').value = ''; $('layout').value = 'list'; page = 1;
      await load(); await openTask(reply.result.id); status(reply.replayed ? 'Follow-up confirmed. The original request created one task.' : 'Follow-up created. Source notes and history are unchanged.');
    } catch (error) {
      if (draft.sealed) { status('Access changed. Your new draft text is retained; source details are hidden.'); return; }
      if (error.saved === false && error.reason !== 'idempotency_mismatch') {
        draft.retrySafe = true; draft.payload = null; for (const control of $('follow-form').querySelectorAll('input,textarea,select')) control.disabled = false; $('follow-ack').checked = false;
        if (error.status === 409) { try { const source = await request(root.dataset.taskUrl + '/' + draft.source.id); const directory = await request(root.dataset.dataUrl); if (directory.workspace?.id !== root.dataset.workspaceId) throw new Error('Workspace response did not match this page.'); const destination = $('follow-project').value; projects = directory.projects; members = directory.members; followDestinations(destination); draft.source = followSource(source, draft.commentId); status('The source or destination changed. Review the updated source and destination again. Your new text is retained.'); } catch (failure) { status(failure.message); } }
        else status(error.message);
        $('follow-submit').textContent = 'Create reviewed follow-up';
      } else { $('follow-submit').textContent = 'Retry same reviewed request'; status('Creation is unconfirmed. Your text and request identity are retained. Retry the same request to confirm; do not start another copy.'); }
      if (!draft.sealed) $('follow-submit').disabled = false;
    }
  };
  addEventListener('beforeunload', event => { if (followUpDraft && ($('follow-title').value.trim() || $('follow-description').value.trim() || followUpDraft.payload)) { event.preventDefault(); event.returnValue = ''; } });
  async function mutate(task, input) {
    if (['task.fields', 'task.recurrence'].includes(input.kind)) return saveCore(input.kind === 'task.fields' ? 'fields' : 'recurrence', { ...input, id: task.id, expected_version: task.version });
    if (!coreNavigationReady()) return false;
    if (input.kind === 'task.complete' && selected?.id === task.id && (!taskDraftReady() || !planningDraftReady())) return false;
    try { await command({ ...input, id: task.id, expected_version: task.version }); status('Saved.'); await load(); if (selected?.id === task.id) await openTask(task.id); return true; }
    catch (error) { status(error.message); return false; }
  }
  $('edit-form').onsubmit = e => { e.preventDefault(); if (selected) saveCore('edit', { id: selected.id, expected_version: selected.version, kind: 'task.update', changes: { title: $('title').value, description: $('description').value, assignee_id: $('assignee').value || null, start_on: $('start').value || null, start_at: $('start').value ? null : selected.start_at, due_on: $('due').value || null, due_at: $('due').value ? null : selected.due_at, priority: $('priority').value } }); };
  $('complete').onclick = () => selected && mutate(selected, { kind: 'task.complete', completed: !selected.completed_at });
  $('back').onclick = () => { if (coreNavigationReady()) clearDetail(); };
  function createTask(parent = null, due = '') {
    if (coreClosed === 'create' || coreStash?.create) { recoverCore(); return; }
    if (!coreNavigationReady()) return;
    parentId = parent; createTaskId = crypto.randomUUID(); createTaskKey = crypto.randomUUID(); $('create-heading').textContent = parent ? 'Create a subtask' : 'Create a task'; $('create-form').reset();
    options($('create-project'), projects.filter(p => p.can_add_task), parent ? 'Inherit parent access' : 'Private personal capture'); $('create-project').value = $('project-filter').value;
    options($('create-assignee'), members); sections($('create-project'), $('create-section')); $('create-due').value = due; createInitial = JSON.stringify(createValues()); $('task-dialog').showModal();
  }
  $('new-task').onclick = () => createTask(); $('subtask').onclick = () => selected && createTask(selected.id);
  $('create-project').onchange = () => sections($('create-project'), $('create-section'));
  $('create-form').onsubmit = async e => {
    e.preventDefault(); const project = $('create-project').value;
    await saveCore('create', { kind: 'task.create', id: createTaskId, idempotency_key: createTaskKey, title: $('create-title').value, parent_id: parentId, assignee_id: $('create-assignee').value || null, due_on: $('create-due').value || null,
      memberships: project ? { [project]: { section_id: $('create-section').value || null, position: 0 } } : {} });
  };
  let editingCommentId = null;
  $('comment-form').onsubmit = async e => { e.preventDefault(); if (selected) await saveCore('comment', { id: selected.id, expected_version: selected.version, kind: 'task.comment', comment_id: crypto.randomUUID(), body: $('comment-body').value, mentioned_user_ids: $('comment-mention').value ? [$('comment-mention').value] : [] }); };
  $('comment-edit-form').onsubmit = async e => { e.preventDefault(); if (selected) await saveCore('comment-edit', { id: selected.id, expected_version: selected.version, kind: 'task.comment.update', comment_id: editingCommentId, body: $('comment-edit-body').value }); };
  async function inboxUpdate(id, values) { const endpoint = new URL(root.dataset.inboxUrl); endpoint.pathname += '/' + id; return request(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(values) }); }
  $('membership-project').onchange = () => { sections($('membership-project'), $('membership-section')); const value = selected?.memberships[$('membership-project').value]; $('membership-section').value = value?.section_id || ''; $('position').value = value?.position || 0; };
  function membership(remove = false) { if (selected) mutate(selected, { kind: 'task.membership', project_id: $('membership-project').value, section_id: $('membership-section').value || null, position: Number($('position').value), acknowledge_sharing: $('sharing').checked, remove }); }
  $('personal-sharing-form').onsubmit = e => { e.preventDefault(); if (selected) mutate(selected, { kind: 'task.collaborator', user_id: $('personal-user').value, access: $('personal-access').value === 'remove' ? null : $('personal-access').value, acknowledge_sharing: $('personal-ack').checked }); };
  $('membership-form').onsubmit = e => { e.preventDefault(); membership(); }; $('membership-remove').onclick = () => membership(true);
  function editProject(project = null) {
    if (!coreNavigationReady()) return;
    editingProject = project; createProjectId = crypto.randomUUID(); createProjectKey = crypto.randomUUID(); $('project-form').reset(); $('project-heading').textContent = project ? 'Project settings' : 'New project'; $('project-name').value = project?.name || ''; $('project-description').value = project?.description || ''; $('project-privacy').value = project?.privacy || 'private';
    $('project-start').value = project?.start_on || ''; $('project-due').value = project?.due_on || '';
    $('archive-project').hidden = !project; $('archive-project').textContent = project?.archived ? 'Reopen project' : 'Archive project'; $('member-form').hidden = !project; $('section-form').hidden = !project;
    $('project-template-form').hidden = !project || project.archived; $('field-definition-form').hidden = !project || project.archived; $('owner-form').hidden = !project || project.archived; $('owner-ack').checked = false; options($('owner-user'), members.filter(m => m.id !== project?.owner_id), 'Choose an existing editor');
    options($('member-user'), members.filter(m => m.id !== project?.owner_id), 'Choose a member'); $('project-dialog').showModal();
  }
  function enterProject(id) {
    premium?.navigate();
    $('project-filter').value = id; $('mode').value = 'all'; $('layout').value = 'list'; $('search').value = '';
    $('date-filter').value = 'all'; $('priority-filter').value = ''; $('completion').value = premium ? 'incomplete' : 'all'; $('sort').value = '';
    page = 1; clearDetail();
  }
  $('new-project').onclick = () => editProject(); $('edit-project').onclick = () => editProject(projects.find(p => p.id === $('project-filter').value));
  $('project-form').onsubmit = async e => {
    e.preventDefault(); const values = { name: $('project-name').value, description: $('project-description').value, privacy: $('project-privacy').value, start_on: $('project-start').value || null, due_on: $('project-due').value || null };
    try { const reply = await command(editingProject ? { kind: 'project.update', id: editingProject.id, expected_version: editingProject.version, changes: values, acknowledge_sharing: $('project-sharing').checked } : { kind: 'project.create', id: createProjectId, idempotency_key: createProjectKey, ...values });
      $('project-dialog').close(); status('Project saved.'); await load(); if (!editingProject) enterProject(reply.result.id); else $('project-filter').value = reply.result.id; page = 1; await load(); }
    catch (error) { status(error.message); }
  };
  async function projectMutation(input) { try { await command({ ...input, id: editingProject.id, expected_version: editingProject.version }); $('project-dialog').close(); await load(); status('Project updated.'); } catch (error) { status(error.message); } }
  $('archive-project').onclick = () => projectMutation({ kind: 'project.archive', archived: !editingProject.archived });
  $('member-form').onsubmit = e => { e.preventDefault(); projectMutation({ kind: 'project.member', user_id: $('member-user').value, access: $('member-access').value === 'remove' ? null : $('member-access').value }); };
  $('owner-form').onsubmit = e => { e.preventDefault(); projectMutation({ kind: 'project.transfer_owner', user_id: $('owner-user').value, acknowledge_sharing: $('owner-ack').checked }); };
  $('section-form').onsubmit = e => { e.preventDefault(); projectMutation({ kind: 'project.section', section_id: crypto.randomUUID(), name: $('section-name').value }); };
  $('field-definition-form').onsubmit = e => { e.preventDefault(); projectMutation({ kind: 'project.field', field_id: crypto.randomUUID(), name: $('field-name').value, field_type: $('field-type').value, choices: $('field-type').value === 'enum' ? $('field-choices').value.split('\n').map(v => v.trim()).filter(Boolean) : [] }); };
  function taskDraftReady() {
    if (!selected) return false;
    const changed = $('title').value !== selected.title || $('description').value !== selected.description || $('assignee').value !== (selected.assignee_id || '') || $('start').value !== (selected.start_on || '') || $('due').value !== (selected.due_on || '') || $('priority').value !== selected.priority;
    if (changed) status('Save your task edits before changing planning or opening related work. Your input remains in the editor.');
    return !changed;
  }
  function planningDraftReady() {
    if (!selected) return false;
    const rule = selected.recurrence; const frequency = $('repeat-frequency').value;
    const changed = frequency !== (rule?.frequency || '') || (frequency && (Number($('repeat-interval').value) !== rule?.interval || $('repeat-zone').value !== rule?.timezone || $('repeat-overlap').value !== rule?.overlap || $('repeat-until').value !== (rule?.until_on || '') || $('repeat-limit').value !== String(rule?.max_occurrences || '')));
    if (changed) status('Save or reset recurrence settings before completing or changing related work. Nothing was completed; your input remains.');
    return !changed;
  }
  function renderPlanning(task) {
    $('dependencies').replaceChildren(); $('dependency-controls').open = false; $('dependency-controls').hidden = !task.can_edit;
    $('dependency-status').textContent = task.dependency_details_unavailable ? 'Some dependency details are unavailable. Blocking status remains unknown until reviewed.' : task.is_blocked ? 'Blocked by incomplete work. Completion remains your decision.' : 'No unresolved visible dependencies.';
    for (const item of task.dependencies || []) {
      const row = element('div', '', 'task-row'); const open = element('button', item.title + (item.completed ? ' — Complete' : ' — Incomplete'), 'task-open'); open.onclick = () => taskDraftReady() && planningDraftReady() && openTask(item.id); row.append(open);
      if (task.can_edit) { const remove = element('button', 'Remove dependency'); remove.onclick = () => taskDraftReady() && planningDraftReady() && mutate(selected, { kind: 'task.dependency', blocker_id: item.id, remove: true }); row.append(remove); } $('dependencies').append(row);
    }
    $('dependency-clear').hidden = !task.dependency_details_unavailable; options($('dependency-task'), [], 'Find matching work first'); dependencyCandidates = [];
    $('repeat-status').textContent = task.recurrence ? 'Repeats ' + task.recurrence.frequency + ' every ' + task.recurrence.interval + ' in ' + task.recurrence.timezone + (task.recurrence_finished ? '. Series limit reached.' : '.') : 'Does not repeat.';
    $('repeat-editor').hidden = !task.can_edit; $('repeat-editor').open = false; $('repeat-form').hidden = !task.can_edit;
    $('repeat-frequency').value = task.recurrence?.frequency || ''; $('repeat-interval').value = task.recurrence?.interval || 1; $('repeat-zone').value = task.recurrence?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; $('repeat-overlap').value = task.recurrence?.overlap || 'reject'; $('repeat-until').value = task.recurrence?.until_on || ''; $('repeat-limit').value = task.recurrence?.max_occurrences || ''; $('repeat-ack').checked = false;
    planningInitial = JSON.stringify(planningValues());
    $('repeat-next').hidden = !task.next_occurrence; $('repeat-next').onclick = () => task.next_occurrence && taskDraftReady() && planningDraftReady() && openTask(task.next_occurrence.id);
    const owned = projects.filter(project => project.can_edit && !project.archived && task.memberships[project.id]);
    $('task-template-form').hidden = !!task.parent_id || !owned.length; options($('task-template-project'), owned, 'Choose an owned project'); $('task-template-name').value = '';
  }
  $('repeat-form').onsubmit = e => { e.preventDefault(); if (!taskDraftReady()) return; const frequency = $('repeat-frequency').value; mutate(selected, { kind: 'task.recurrence', acknowledge_sharing: $('repeat-ack').checked, rule: frequency ? { frequency, interval: Number($('repeat-interval').value), timezone: $('repeat-zone').value, overlap: $('repeat-overlap').value, until_on: $('repeat-until').value || null, max_occurrences: $('repeat-limit').value ? Number($('repeat-limit').value) : null } : null }); };
  $('dependency-search').onsubmit = async e => { e.preventDefault(); const current = detailGeneration; try { const endpoint = url(root.dataset.dataUrl); endpoint.searchParams.set('q', $('dependency-query').value); const data = await request(endpoint); if (current !== detailGeneration || !selected) return; dependencyCandidates = data.tasks.filter(task => task.id !== selected.id); options($('dependency-task'), dependencyCandidates.map(task => ({ id: task.id, name: task.title + ' — ' + (task.due_on || task.due_at || 'No due date') + (task.completed_at ? ' — Complete' : ' — Incomplete') })), 'Choose a task'); status('Showing the first ' + dependencyCandidates.length + ' accessible matches; refine the search for more work.'); } catch (error) { status(error.message); } };
  $('dependency-form').onsubmit = async e => { e.preventDefault(); if (!taskDraftReady() || !planningDraftReady()) return; const candidate = dependencyCandidates.find(task => task.id === $('dependency-task').value); if (!candidate) { status('Find and choose an accessible task first.'); return; } const original = selected.id; if ($('dependency-direction').value === 'blocks') { if (await mutate(candidate, { kind: 'task.dependency', blocker_id: selected.id })) await openTask(original); } else await mutate(selected, { kind: 'task.dependency', blocker_id: candidate.id }); };
  $('dependency-clear').onclick = () => taskDraftReady() && planningDraftReady() && mutate(selected, { kind: 'task.dependency', remove_unavailable: true });
  async function captureTemplate(project, kind, name, taskId = null) {
    try { const signature = JSON.stringify([project.id, kind, name, taskId]); if (templateCapture && templateCapture.signature !== signature) { status('Retry the previous snapshot or refresh and review saved templates before starting another copy.'); return; } if (!templateCapture) { const input = { kind: 'project.template', id: project.id, expected_version: project.version, template_type: kind, template_id: crypto.randomUUID(), idempotency_key: crypto.randomUUID(), name }; if (taskId) input.source_task_id = taskId; templateCapture = { signature, input }; } await command(templateCapture.input); templateCapture = null; $('project-dialog').close(); await load(); status('Template snapshot saved. Review it with Use a template before copying.'); } catch (error) { status(error.message); }
  }
  $('project-template-form').onsubmit = e => { e.preventDefault(); if (editingProject) captureTemplate(editingProject, 'project', $('project-template-name').value); };
  $('task-template-form').onsubmit = e => { e.preventDefault(); if (!taskDraftReady()) return; const project = projects.find(item => item.id === $('task-template-project').value); if (project) captureTemplate(project, 'task', $('task-template-name').value, selected.id); else status('Choose an owned project first.'); };
  $('templates').onclick = () => { if (!coreNavigationReady()) return; if (selected && (!taskDraftReady() || !planningDraftReady())) return; templateSelection = null; $('template-use-form').reset(); options($('template-choice'), templateCatalog.map(item => ({ id: item.choice, name: item.project.name + ' / ' + item.name + ' (' + item.kind + ')' })), 'Choose a template'); $('template-preview').value = ''; $('template-meta').textContent = ''; $('template-use').disabled = true; $('template-remove').hidden = true; $('template-dialog').showModal(); };
  $('template-ack').onchange = () => { $('template-use').disabled = !templateSelection || !$('template-ack').checked || !canUseTemplates || !templateSelection.project.can_add_task; };
  $('template-choice').onchange = async () => {
    const current = ++templateRequest; const item = templateCatalog.find(item => item.choice === $('template-choice').value); templateSelection = null; $('template-use').disabled = true; $('template-remove').hidden = true; $('template-ack').checked = false; $('template-preview').value = ''; if (!item) return;
    try { const preview = await request(root.dataset.projectUrl + '/' + item.project.id + '/templates/' + item.id); if (current !== templateRequest || !$('template-dialog').open) return;
      $('template-remove').hidden = !item.project.can_edit; templateSelection = { ...item, preview, copyId: crypto.randomUUID(), copyKey: crypto.randomUUID() }; const lines = [preview.name, preview.description || '', 'Sections: ' + (preview.sections.map(section => section.name).join(', ') || 'None'), '', 'Tasks']; for (const [index, task] of preview.tasks.entries()) { lines.push((index + 1) + '. ' + (task.parent ? 'Subtask: ' : '') + task.title, 'Priority: ' + task.priority, task.description || 'No description', ''); } $('template-preview').value = lines.join('\n'); $('template-meta').textContent = preview.copy_rules + ' Snapshot version ' + preview.version + ', captured ' + preview.captured_at + '.';
      $('template-name-label').hidden = item.kind !== 'project'; $('template-new-name').required = item.kind === 'project'; $('template-new-name').value = preview.name + ' copy';
      if (!canUseTemplates || !item.project.can_add_task) $('template-meta').textContent += ' Current workspace member and project edit access are required to create a copy.';
    } catch (error) { status(error.message); }
  };
  $('template-remove').onclick = async () => { const item = templateSelection; if (!item || !item.project.can_edit || !confirm('Remove this reusable template? Existing work, copies and source history stay unchanged.')) return; try { await command({ kind: 'project.template', id: item.project.id, expected_version: item.preview.project_version, template_id: item.id, remove: true }); $('template-dialog').close(); templateSelection = null; await load(); status('Template removed. Existing work and source history are unchanged.'); } catch (error) { status(error.message); } };
  $('template-use-form').onsubmit = async e => {
    e.preventDefault(); const item = templateSelection; if (!item || !$('template-ack').checked) return;
    try { const reply = await command({ kind: item.kind === 'project' ? 'project.from_template' : 'task.from_template', id: item.copyId, idempotency_key: item.copyKey, project_id: item.project.id, project_version: item.preview.project_version, template_id: item.id, template_version: item.preview.version, acknowledge_sharing: true, ...(item.kind === 'project' ? { name: $('template-new-name').value } : {}) }); $('template-dialog').close(); $('mode').value = 'all'; $('layout').value = 'list'; $('date-filter').value = 'all'; $('search').value = ''; $('priority-filter').value = ''; $('completion').value = 'all'; page = 1; clearDetail(); await load(); $('project-filter').value = item.kind === 'project' ? reply.result.id : item.project.id; await load(); if (item.kind === 'task') await openTask(reply.result.id); status('Reviewed copy created; source work is unchanged.'); } catch (error) { status(error.message); }
  };
  for (const button of root.querySelectorAll('[data-close]')) button.onclick = () => document.getElementById(button.dataset.close).close();
  for (const id of ['search', 'mode', 'project-filter', 'layout', 'completion', 'priority-filter', 'month', 'date-filter', 'sort']) $(id)[id === 'search' ? 'oninput' : 'onchange'] = () => { if (!coreNavigationReady()) { $(id).value = acceptedFilters[id] ?? ''; return; } acceptedFilters[id] = $(id).value; page = 1; projectSearchPage = 1; clearDetail(); load(); };
  $('month').value = new Date().toISOString().slice(0, 7);
  function renderSubtasks(task) {
    $('subtasks').replaceChildren();
    if (!task.subtasks.length) $('subtasks').append(element('p', 'No accessible subtasks.', 'muted'));
    for (const [index, child] of task.subtasks.entries()) {
      const row = element('div', '', 'task-row'); const open = element('button', (child.completed_at ? 'Completed: ' : '') + child.title, 'task-open'); open.onclick = () => openTask(child.id); row.append(open);
      if (task.can_edit && !task.subtasks_truncated) for (const [label, offset] of [['Move up', -1], ['Move down', 1]]) {
        const move = element('button', label); move.disabled = index + offset < 0 || index + offset >= task.subtasks.length;
        move.onclick = () => { const ids = task.subtasks.map(c => c.id); [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]]; mutate(task, { kind: 'task.subtask_order', child_ids: ids }); }; row.append(move);
      }
      $('subtasks').append(row);
    }
    if (task.subtasks_truncated) $('subtasks').append(element('p', 'Showing the first 100 accessible subtasks. Reordering is unavailable for this large set.', 'muted'));
  }
  function renderFields(task) {
    $('fields').replaceChildren();
    for (const project of projects.filter(p => task.memberships[p.id] && Object.keys(p.custom_fields || {}).length)) {
      const form = element('form', '', 'detail-form comment'); form.append(element('h2', project.name)); const inputs = [];
      for (const field of Object.values(project.custom_fields)) {
        const label = element('label', field.name); const control = document.createElement(field.type === 'enum' ? 'select' : 'input');
        if (field.type === 'enum') options(control, field.choices.map(v => ({ id: v, name: v })), 'No value');
        else { control.type = field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'; if (field.type === 'number') control.step = 'any'; else if (field.type === 'text') control.maxLength = 2000; }
        control.value = task.custom_fields?.[project.id]?.[field.id] ?? ''; control.disabled = !task.can_edit || !project.can_add_task; control.dataset.draftField = field.id; control.dataset.draftProject = project.id; control.dataset.savedValue = control.value; control.dataset.draftWritable = String(!control.disabled); label.append(control); form.append(label); inputs.push([field, control]);
      }
      form.dataset.draftProjectForm = project.id; const save = element('button', 'Save project fields'); save.dataset.draftWritable = String(task.can_edit && project.can_add_task); save.disabled = !task.can_edit || !project.can_add_task; form.append(save);
      form.onsubmit = e => { e.preventDefault(); const values = Object.fromEntries(inputs.map(([field, control]) => [field.id, control.value === '' ? null : field.type === 'number' ? Number(control.value) : control.value])); mutate(task, { kind: 'task.fields', project_id: project.id, project_version: project.version, values }); }; $('fields').append(form);
    }
  }
  $('next').onclick = () => { page++; load(); }; $('prev').onclick = () => { page--; load(); };
  async function loadFiles(taskId, current = detailGeneration) {
    const result = await request(root.dataset.taskUrl + '/' + taskId + '/files');
    if (current !== detailGeneration || selected?.id !== taskId) return;
    taskFiles = result.files; $('files').replaceChildren();
    const labels = { uploading: 'Upload paused - select the same file to resume.', quarantined: 'Stored privately. Awaiting security scan; download is unavailable.', rejected: 'Security or file validation failed; download is unavailable.', ready: 'Available to authorized collaborators.' };
    for (const file of taskFiles) {
      const row = element('div', '', 'comment'); row.append(element('p', file.filename), element('p', labels[file.status] || file.status, 'muted'));
      if (file.downloadable) { const link = element('a', 'Download attachment'); link.href = url(root.dataset.filesUrl + '/' + file.id + '/download'); link.target = '_blank'; link.rel = 'noopener'; row.append(link); }
      if (file.can_manage && ['uploading', 'quarantined', 'rejected'].includes(file.status)) {
        const cancel = element('button', 'Cancel upload'); cancel.type = 'button'; cancel.onclick = async () => { try { await request(root.dataset.filesUrl + '/' + file.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }); await loadFiles(taskId); } catch (error) { status(error.message); } }; row.append(cancel);
      }
      if (file.can_manage && file.status === 'quarantined') {
        const retry = element('button', 'Retry security check'); retry.type = 'button'; retry.onclick = async () => { try { const result = await request(root.dataset.filesUrl + '/' + file.id + '/complete', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }); if (result.downloadable) await openTask(taskId); else await loadFiles(taskId); } catch (error) { status(error.message); } }; row.append(retry);
      }
      $('files').append(row);
    }
  }
  $('file-form').onsubmit = async event => {
    event.preventDefault(); if (!selected?.can_edit) return;
    const taskId = selected.id; const current = detailGeneration; const file = $('file-input').files[0];
    if (!file) { $('file-status').textContent = 'Choose a file first.'; return; }
    if (file.size < 1 || file.size > Number(root.dataset.maxFileBytes)) { $('file-status').textContent = 'Choose a non-empty file up to 10 MB.'; return; }
    $('file-send').disabled = true; $('file-cancel').disabled = false; fileAbort = new AbortController();
    const abort = fileAbort;
    try {
      $('file-status').textContent = 'Preparing file...';
      const buffer = await file.arrayBuffer(); const digest = [...new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))].map(b => b.toString(16).padStart(2, '0')).join('');
      if (current !== detailGeneration || abort.signal.aborted) return;
      const resumable = taskFiles.find(item => item.can_manage && ['uploading', 'quarantined'].includes(item.status) && item.filename === file.name && item.sha256 === digest && item.size === file.size);
      fileDraft = fileDraft?.sha256 === digest && fileDraft.taskId === taskId ? fileDraft : { taskId, sha256: digest, clientKey: resumable?.client_key || crypto.randomUUID(), id: resumable?.id, cancelled: false };
      const draft = fileDraft;
      const mime = file.type || ({ txt: 'text/plain', pdf: 'application/pdf', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg' }[file.name.split('.').pop().toLowerCase()] || 'application/octet-stream');
      let upload = await request(root.dataset.taskUrl + '/' + taskId + '/files', { method: 'POST', signal: abort.signal, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ client_key: draft.clientKey, filename: file.name, mime_type: mime, size: file.size, sha256: digest }) });
      draft.id = upload.id;
      $('file-progress').max = file.size; $('file-progress').value = upload.received_bytes;
      for (let index = 0; index < Math.ceil(file.size / upload.chunk_bytes); index++) {
        if (current !== detailGeneration || abort.signal.aborted) return;
        if (upload.received_chunks.includes(index)) continue;
        $('file-status').textContent = 'Uploading ' + Math.round(100 * $('file-progress').value / file.size) + '%';
        upload = await request(root.dataset.filesUrl + '/' + upload.id + '/chunks/' + index, { method: 'PUT', signal: abort.signal, headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: buffer.slice(index * upload.chunk_bytes, Math.min(file.size, (index + 1) * upload.chunk_bytes)) });
        $('file-progress').value = upload.received_bytes;
      }
      $('file-status').textContent = 'Upload received. Checking file security...'; $('file-cancel').disabled = true;
      const result = await request(root.dataset.filesUrl + '/' + upload.id + '/complete', { method: 'POST', signal: abort.signal, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
      if (current !== detailGeneration) return;
      $('file-status').textContent = result.downloadable ? 'Attachment saved.' : 'Stored privately. Download waits for a successful security scan.';
      fileDraft = null; if (result.downloadable) { status('Attachment saved.'); await openTask(taskId); } else await loadFiles(taskId, current);
    } catch (error) {
      if (current === detailGeneration && !fileDraft?.cancelled) { $('file-status').textContent = error.name === 'AbortError' ? 'Upload paused. Retry to resume.' : 'Upload paused. Your original file is unchanged. Retry to resume. ' + error.message; await loadFiles(taskId, current).catch(() => {}); }
    } finally { if (current === detailGeneration) { $('file-send').disabled = false; $('file-cancel').disabled = true; } }
  };
  $('file-cancel').onclick = async () => {
    const draft = fileDraft; if (draft) draft.cancelled = true; fileAbort?.abort();
    try { if (draft?.id) await request(root.dataset.filesUrl + '/' + draft.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }); fileDraft = null; $('file-status').textContent = 'Upload cancelled.'; if (selected) await loadFiles(selected.id); }
    catch (error) { $('file-status').textContent = 'Cancellation was not confirmed. Retry from the upload list. ' + error.message; }
  };
  const statusLabels = { on_track: 'On track', at_risk: 'At risk', off_track: 'Off track', on_hold: 'On hold', complete: 'Complete' };
  function projectStatusDirty() { return !!statusDraft && (statusDraft.retained || statusDraft.payload || statusDraft.references.length || $('status-code').value !== statusDraft.initialCode || ['summary', 'highlights', 'blockers', 'next'].some(field => $('status-' + field).value.trim())); }
  function clearOverview() { overview = null; statusGeneration++; statusCandidates = []; $('overview-heading').textContent = 'Project overview'; $('overview-body').replaceChildren(); $('status-history').replaceChildren(); $('status-references').replaceChildren(); $('status-candidate').replaceChildren(); $('status-audience').textContent = ''; $('status-reference-note').textContent = ''; $('status-older').hidden = true; $('status-latest').hidden = true; $('project-link').disabled = true; $('overview').hidden = true; }
  async function verifyStatusOwner() {
    const probe = ++statusOwnerProbe, controller = new AbortController(), timer = setTimeout(() => controller.abort(), 5000);
    try { const response = await fetch(url(root.dataset.workspacesUrl), { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { Accept: 'application/json' } }); if (!response.ok) return 'unverified'; const data = await response.json(); if (probe !== statusOwnerProbe) return 'unverified'; return typeof data.viewer_id === 'string' ? (data.viewer_id === root.dataset.user ? 'owner' : 'other') : 'unverified'; } catch { return 'unverified'; } finally { clearTimeout(timer); }
  }
  function sealStatusDraft(retained, retrySafe, identity = 'unverified') {
    statusDraft.sealed = true; statusDraft.retrySafe = retrySafe; statusDraft.references = []; statusDraft.retained = retained; statusDraft.ownerVerified = identity === 'owner';
    $('status-form').hidden = false; $('status-code').value = statusDraft.ownerVerified ? retained.code : '';
    for (const field of ['summary', 'highlights', 'blockers', 'next']) { $('status-' + field).value = statusDraft.ownerVerified ? retained[field] : ''; $('status-' + field).disabled = false; $('status-' + field).readOnly = true; }
    for (const id of ['status-code', 'status-submit', 'status-search', 'status-add', 'status-candidate', 'status-query', 'status-ack']) $(id).disabled = true;
    $('status-audience').textContent = statusDraft.ownerVerified ? 'Access changed. Project and reference details are hidden. Your new text is retained for copying or explicit discard; posting is not confirmed.' : 'Sign in to the original account, then verify to recover this draft. Project details and draft text are hidden.';
    $('status-verify').hidden = statusDraft.ownerVerified; $('status-verify').disabled = false;
    if (identity === 'other') { workspaceNavigating = true; $('status').textContent = 'Account changed. Project data is hidden. Verify the original account or refresh.'; }
    $('status-discard').disabled = !statusDraft.ownerVerified; $('status-resume').hidden = false; $('overview-dialog').showModal();
  }
  function renderStatusReferences() {
    $('status-references').replaceChildren();
    for (const item of statusDraft?.references || []) { const row = element('div', '', 'task-row'); row.append(element('span', item.available ? item.title : 'Previously selected task is unavailable')); const remove = element('button', 'Remove reference'); remove.type = 'button'; remove.disabled = !!statusDraft.payload || statusDraft.sealed; remove.onclick = () => { statusDraft.references = statusDraft.references.filter(ref => ref.id !== item.id); renderStatusReferences(); $('status-resume').hidden = !projectStatusDirty(); }; row.append(remove); $('status-references').append(row); }
  }
  function renderOverview(data, append = false) {
    if (data.viewer_id !== root.dataset.user) {
      const retained = statusDraft?.retained || (projectStatusDirty() ? Object.fromEntries(['code', 'summary', 'highlights', 'blockers', 'next'].map(field => [field, $('status-' + field).value])) : null);
      clearOverview(); clearDetail(); projects = []; members = []; $('list').replaceChildren(); options($('project-filter'), [], 'Refresh to verify your account');
      if (retained) sealStatusDraft(retained, statusDraft.retrySafe, 'other'); else { workspaceNavigating = true; $('status').textContent = 'Account changed. Refresh to verify current workspace access.'; }
      return false;
    }
    overview = data; $('overview-heading').textContent = data.project.name; $('project-link').disabled = false; $('overview-body').replaceChildren();
    const info = data.project, state = info.current_status;
    $('overview-body').append(element('p', info.description || 'No project description.'), element('p', 'Owner: ' + (members.find(item => item.id === info.owner_id)?.name || 'Project member')), element('p', 'Start: ' + (info.start_on || info.start_at || 'Not set') + ' · Due: ' + (info.due_on || info.due_at || 'Not set')), element('p', (info.archived ? 'Archived · ' : '') + (info.privacy === 'private' ? 'Private project' : 'Workspace-visible project')), element('p', 'Current status: ' + (state ? statusLabels[state.code] : 'No status posted')), element('p', data.progress.completed_tasks + ' of ' + data.progress.visible_tasks + ' visible project tasks complete.'));
    $('status-history').replaceChildren(); $('status-latest').hidden = !append;
    for (const update of data.updates) {
      const card = element('article', '', 'comment'); card.dataset.statusId = update.id; card.append(element('h3', statusLabels[update.code]), element('small', update.created_at + ' · ' + (members.find(item => item.id === update.author_id)?.name || 'Project member')), element('p', update.summary));
      for (const [key, title] of [['highlights', 'Highlights'], ['blockers', 'Blockers'], ['next_steps', 'Next steps']]) if (update[key]) card.append(element('h4', title), element('p', update[key]));
      for (const reference of update.references) { const button = element('button', reference.title + (reference.completed ? ' (complete)' : '')); button.type = 'button'; button.onclick = () => { if (projectStatusDirty()) { status('Finish your status draft first. Your text stays here.'); return; } $('overview-dialog').close(); openTask(reference.id); }; card.append(button); }
      $('status-history').append(card);
    }
    if (!$('status-history').children.length) $('status-history').append(element('p', 'No status updates yet.'));
    statusBefore = data.next_before; $('status-older').hidden = !statusBefore; $('status-form').hidden = !data.can_post_status && !projectStatusDirty();
    $('status-audience').textContent = info.privacy === 'private' ? 'New status text is shared with current permitted readers of this private project.' : 'New status text is shared with permitted workspace project readers.';
    return true;
  }
  async function overviewData(id, before = null) { const endpoint = url(root.dataset.projectUrl + '/' + id + '/overview'); if (before) endpoint.searchParams.set('before', before); for (const ref of statusDraft?.references || []) endpoint.searchParams.append('selected[]', ref.id); const data = await request(endpoint); data.project_url = surfaceUrl(data.project_url).href; return data; }
  async function openOverview(id, renew = false) {
    if (!coreNavigationReady()) return false;
    if (!id || pendingMutations || fileAbort || fileDraft || $('file-input').files.length) { status('Finish the current save or upload first.'); return; }
    if (selected && (!taskDraftReady() || !planningDraftReady())) return;
    if ($('comment-body').value.trim() || (followUpDraft && ($('follow-title').value.trim() || $('follow-description').value.trim() || followUpDraft.payload))) { status('Finish your unsent comment or follow-up first. Your text stays here.'); return; }
    if (projectStatusDirty() && statusDraft.project.id !== id) { status('Resume and finish your existing status draft before opening another project.'); return; }
    $('project-filter').value = id; clearDetail(); const current = ++statusGeneration; $('overview-dialog').showModal(); $('overview-heading').textContent = 'Loading project overview';
    try {
      const data = await overviewData(id); if (current !== statusGeneration) return; if (!renderOverview(data)) return false;
      if (!statusDraft || !projectStatusDirty()) {
        $('status-form').reset(); for (const control of $('status-form').querySelectorAll('input,textarea,select,button')) { control.disabled = false; if ('readOnly' in control) control.readOnly = false; }
        statusDraft = data.can_post_status ? { project: { id: data.project.id, version: data.project.version }, initialCode: data.project.current_status?.code || 'on_track', references: [], key: crypto.randomUUID(), payload: null, retrySafe: true, sealed: false } : null;
        $('status-code').value = statusDraft?.initialCode || 'on_track'; $('status-submit').textContent = 'Post reviewed status'; $('status-verify').hidden = true;
      } else if (renew) {
        statusDraft.project = { id: data.project.id, version: data.project.version }; statusDraft.references = statusDraft.references.map(old => { const ref = data.selected_references.find(item => item.id === old.id); return ref ? { ...ref, available: true } : { id: old.id, available: false }; }); $('status-ack').checked = false;
        if (!data.can_post_status) { const retained = Object.fromEntries(['summary', 'highlights', 'blockers', 'next'].map(key => [key, $('status-' + key).value])); retained.code = $('status-code').value; sealStatusDraft(retained, true, await verifyStatusOwner()); }
      }
      renderStatusReferences(); $('status-resume').hidden = !projectStatusDirty();
      const address = new URL(data.project_url, location.origin); window.history.replaceState(null, '', address); syncSurfaceLinks(); return true;
    } catch (error) { if (current === statusGeneration) status(error.message); return false; }
  }
  $('overview').onclick = () => openOverview($('project-filter').value);
  async function recoverStatusDraft() { const draft = statusDraft; if (!draft?.sealed) return; const identity = await verifyStatusOwner(); if (statusDraft === draft) sealStatusDraft(draft.retained, draft.retrySafe, identity); }
  $('status-resume').onclick = () => { if (statusDraft?.sealed) recoverStatusDraft(); else if (statusDraft) openOverview(statusDraft.project.id); };
  $('status-verify').onclick = recoverStatusDraft;
  $('overview-dialog').addEventListener('close', () => { statusGeneration++; $('status-resume').hidden = !projectStatusDirty(); });
  $('status-form').addEventListener('input', () => { $('status-resume').hidden = !projectStatusDirty(); });
  $('status-latest').onclick = () => { if (overview) openOverview(overview.project.id); };
  $('status-older').onclick = async () => { if (!overview || !statusBefore) return; const id = overview.project.id, current = statusGeneration; try { const data = await overviewData(id, statusBefore); if (current === statusGeneration && overview?.project.id === id) renderOverview(data, true); } catch (error) { status(error.message); } };
  $('status-search').onclick = async () => {
    if (!statusDraft || statusDraft.payload || statusDraft.sealed) return; const current = statusGeneration;
    try { const endpoint = url(root.dataset.dataUrl); endpoint.searchParams.set('project_id', statusDraft.project.id); endpoint.searchParams.set('q', $('status-query').value); const data = await request(endpoint); if (current !== statusGeneration) return; statusCandidates = data.tasks; options($('status-candidate'), data.tasks.map(task => ({ id: task.id, name: task.title })), 'Choose a visible project task'); $('status-reference-note').textContent = 'Showing ' + data.tasks.length + ' of ' + data.total + ' matching visible project tasks; refine the search if needed.'; } catch (error) { status(error.message); }
  };
  $('status-add').onclick = () => { if (!statusDraft || statusDraft.payload || statusDraft.sealed) return; const task = statusCandidates.find(item => item.id === $('status-candidate').value); if (!task) return; if (statusDraft.references.length >= 20) { status('Choose at most 20 task references.'); return; } if (!statusDraft.references.some(item => item.id === task.id)) statusDraft.references.push({ id: task.id, title: task.title, version: task.version, available: true }); renderStatusReferences(); $('status-resume').hidden = false; };
  $('status-discard').onclick = async () => { const draft = statusDraft; if (draft?.sealed) { const identity = await verifyStatusOwner(); if (statusDraft !== draft) return; if (identity !== 'owner') { sealStatusDraft(draft.retained, draft.retrySafe, identity); return; } } if (statusDraft?.payload && !statusDraft.retrySafe) { status('Posting is unconfirmed. Retry the same request before discarding this draft.'); return; } if (!confirm('Discard only this unsent status draft? Posted history and project notes remain unchanged.')) return; statusDraft = null; $('status-form').reset(); $('status-references').replaceChildren(); $('status-resume').hidden = true; $('overview-dialog').close(); };
  $('project-link').onclick = async () => { if (!overview) return; const link = overview.project_url; try { if (!navigator.clipboard?.writeText) throw Error('Clipboard unavailable'); await navigator.clipboard.writeText(link); status('Project link copied. Opening it requires current project access.'); } catch { $('link-heading').textContent = 'Project link'; $('link-value').value = link; $('link-dialog').showModal(); $('link-value').select(); } };
  $('status-form').onsubmit = async event => {
    event.preventDefault(); const draft = statusDraft; if (!draft || draft.sealed || pendingMutations) return; if (draft.references.some(ref => !ref.available)) { status('Remove unavailable references or choose currently visible project tasks before posting. Your text stays here.'); return; }
    draft.payload ??= { kind: 'project.status', id: draft.project.id, idempotency_key: draft.key, expected_version: draft.project.version, status_code: $('status-code').value, summary: $('status-summary').value, highlights: $('status-highlights').value, blockers: $('status-blockers').value, next_steps: $('status-next').value, references: Object.fromEntries(draft.references.map(ref => [ref.id, ref.version])), acknowledge_sharing: $('status-ack').checked };
    draft.retrySafe = false; for (const control of $('status-form').querySelectorAll('input,textarea,select,button')) control.disabled = true;
    let reply;
    try { reply = await command(draft.payload); }
    catch (error) {
      if (draft.sealed) { status('Access changed. Your new status text is retained; project details are hidden.'); return; }
      if (error.saved === false && error.reason !== 'idempotency_mismatch') { draft.retrySafe = true; draft.payload = null; for (const control of $('status-form').querySelectorAll('input,textarea,select,button')) control.disabled = false; $('status-ack').checked = false; if (error.status === 409) { await openOverview(draft.project.id, true); if (draft.sealed) { status('Posting access changed. Your new text is retained for copying.'); return; } status('Project or reference changed. Review the refreshed audience and references again. Your new text is retained.'); } else status(error.message); $('status-submit').textContent = 'Post reviewed status'; }
      else { $('status-submit').disabled = false; $('status-submit').textContent = 'Retry same reviewed status'; status('Posting is unconfirmed. Your text and request identity are retained. Retry the same status to confirm its result.'); }
      return;
    }
    statusDraft = null; $('status-form').reset(); $('status-resume').hidden = true; await load(); const refreshed = await openOverview(draft.project.id); status(refreshed ? (reply.replayed ? 'Status confirmed. The original update is retained once in history.' : 'Status posted. Project notes and tasks are unchanged.') : 'Status saved. The overview could not refresh; reopen it to review history.');
  };
  addEventListener('beforeunload', event => { if (projectStatusDirty()) { event.preventDefault(); event.returnValue = ''; } });
  // Page-memory only. No draft text is stored in history, web storage or a shared cache.
  const coreFields = { title: 'title', description: 'description', assignee: 'assignee_id', start: 'start_on', due: 'due_on', priority: 'priority' };
  let coreSelectDefaults = [], planningInitial = null;
  const planningIds = ['repeat-frequency', 'repeat-interval', 'repeat-zone', 'repeat-overlap', 'repeat-until', 'repeat-limit'];
  function planningValues() { return Object.fromEntries(planningIds.map(id => [id, $(id).value])); }
  function extraControl(item) { return [...$('fields').querySelectorAll('[data-draft-field]')].find(control => control.dataset.draftProject === item.project && control.dataset.draftField === item.field); }
  const filterIds = ['search', 'mode', 'project-filter', 'layout', 'completion', 'priority-filter', 'month', 'date-filter', 'sort'];
  let acceptedFilters = {}, coreStash = null, coreSave = null, coreSaving = false, coreRecovering = false, corePrompting = false, coreRefreshing = false, coreClosed = null, coreAccessBlocked = false, coreEpoch = 0, coreConflict = false, coreReview = false, createInitial = null;
  const coreControls = ['title', 'description', 'assignee', 'start', 'due', 'priority', 'save', 'complete', 'subtask', 'comment-body', 'comment-mention', 'comment-edit-body', 'create-title', 'create-project', 'create-section', 'create-assignee', 'create-due'];
  function createValues() { return Object.fromEntries(['title', 'project', 'section', 'assignee', 'due'].map(id => [id, $('create-' + id).value])); }
  function captureCore() {
    if (coreAccessBlocked) return coreStash;
    const draft = {};
    if (selected) {
      const fields = Object.fromEntries(Object.entries(coreFields).filter(([id, field]) => $(id).value !== (selected[field] || '')).map(([id]) => [id, $(id).value]));
      const comment = $('comment-body').value !== '' ? { body: $('comment-body').value, mention: $('comment-mention').value } : null;
      const edited = editingCommentId && $('comment-edit-body').value !== (selected.comments[editingCommentId]?.body || '') ? { id: editingCommentId, body: $('comment-edit-body').value } : null;
      const extras = [...$('fields').querySelectorAll('[data-draft-field]')].filter(control => control.value !== control.dataset.savedValue).map(control => ({ project: control.dataset.draftProject, field: control.dataset.draftField, value: control.value }));
      const planning = planningInitial && JSON.stringify(planningValues()) !== planningInitial ? planningValues() : null;
      if (Object.keys(fields).length || comment || edited || extras.length || planning) draft.task = { id: selected.id, version: selected.version, fields, comment, edited, extras, planning };
    }
    if (createInitial && JSON.stringify(createValues()) !== createInitial) draft.create = { values: createValues(), id: createTaskId, key: createTaskKey, parent: parentId };
    return Object.keys(draft).length ? draft : null;
  }
  function coreDirty() { return !!(coreSave || coreStash || captureCore()); }
  function coreNotice() {
    $('draft-bar').hidden = !coreDirty();
    $('draft-notice').textContent = coreAccessBlocked ? 'Draft retained privately in this page. Verify the original account and current work access to recover it.' : coreSaving ? 'Saving. Stay here until the result is known.' : coreSave ? 'Save unconfirmed. Retry the same save; do not create another copy.' : coreReview ? 'Compare the current saved text below with your restored edits, then acknowledge the review.' : 'Unsaved work stays in this page. Resume, save or explicitly discard it before navigating.';
    $('draft-resume').disabled = coreSaving || coreRecovering; $('draft-resume').textContent = coreAccessBlocked ? 'Verify and recover draft' : coreConflict ? 'Review current saved work' : 'Resume unsaved work';
    $('draft-discard').disabled = !!coreSave || coreAccessBlocked || coreSaving;
  }
  function coreNavigationReady() {
    if (coreRecovering) { status('Wait for draft access verification.'); return false; }
    if (coreAccessBlocked) { coreNotice(); status('Verify the original account and work access first. Retained draft text is hidden.'); return false; }
    if (coreDirty()) { coreNotice(); if (!coreSave && selected && Object.keys(captureCore()?.task?.fields || {}).length) { status('Save your task edits before changing planning or opening related work. Your input remains in the editor.'); return false; } status(coreSave ? 'Confirm the pending save by retrying the same request. Your input is retained.' : 'Save or explicitly discard your unsaved task/comment draft before navigating. Your input stays here.'); return false; }
    if (pendingMutations) { status('Wait for the current save before navigating.'); return false; }
    return true;
  }
  function freezeCore(frozen) {
    for (const id of coreControls) $(id).disabled = frozen || (['title', 'description', 'assignee', 'start', 'due', 'priority', 'save', 'complete', 'subtask'].includes(id) && !selected?.can_edit);
    for (const control of [...$('fields').querySelectorAll('input,select,button'), ...$('repeat-form').querySelectorAll('input,select,button')]) control.disabled = frozen || control.dataset.draftWritable === 'false';
    for (const form of ['comment-form', 'comment-edit-form', 'create-form']) $(form).querySelector('button:not([type=button])').disabled = frozen;
    if (coreSave && !coreSaving && !coreAccessBlocked) {
      const button = coreSave.channel === 'fields' ? [...$('fields').querySelectorAll('form')].find(form => form.dataset.draftProjectForm === coreSave.payload.project_id)?.querySelector('button') : coreSave.channel === 'recurrence' ? $('repeat-form').querySelector('button') : coreSave.channel === 'edit' ? $('save') : $(coreSave.channel === 'create' ? 'create-form' : coreSave.channel === 'comment' ? 'comment-form' : 'comment-edit-form').querySelector('button:not([type=button])');
      if (button) button.disabled = false;
    }
  }
  function clearCoreOriginals() {
    generation++; detailGeneration++; workspaceGeneration++; statusGeneration++;
    $('home-counts').replaceChildren(); $('home-context').textContent = ''; $('home').hidden = true;
    clearDetail(); clearOverview(); clearProjectResults(); projects = []; members = []; editingProject = null; templateSelection = null; templateCapture = null; templateCatalog = []; templateRequest++; $('list').replaceChildren(); $('count').textContent = '';
    for (const [select, defaults] of coreSelectDefaults) select.replaceChildren(...defaults.map(option => option.cloneNode(true)));
    $('workspace-list').replaceChildren(); $('workspace-status').textContent = ''; $('follow-source').textContent = ''; $('template-meta').textContent = ''; $('project-heading').textContent = 'Project';
    for (const id of ['project-filter', 'assignee', 'create-project', 'create-assignee', 'membership-project', 'member-user', 'comment-mention']) options($(id), [], 'Verify access to reload');
    for (const field of root.querySelectorAll('input,textarea')) { if (field.type === 'checkbox') field.checked = false; else field.value = ''; }
    for (const dialog of root.querySelectorAll('dialog[open]')) dialog.close();
    $('detail').hidden = false; $('draft-current').textContent = ''; $('draft-review').hidden = true;
    $('new-task').disabled = true; $('new-project').disabled = true; $('templates').disabled = true; $('edit-project').hidden = true;
  }
  function sealCore(retained = null) {
    premium?.reset();
    if (!coreAccessBlocked) coreStash = retained || (coreRefreshing ? coreStash : captureCore()) || coreStash;
    coreAccessBlocked = true; coreEpoch++; coreClosed = null; clearCoreOriginals(); freezeCore(true); coreNotice();
  }
  function restoreCoreValue(control, value) {
    if (control.tagName === 'SELECT' && value && ![...control.options].some(option => option.value === value)) { const unavailable = new Option('Previously selected value unavailable; choose a current value', value); unavailable.disabled = true; control.add(unavailable); }
    control.value = value;
  }
  function restoreCore(draft) {
    if (draft?.task && selected?.id === draft.task.id) {
      for (const [id, value] of Object.entries(draft.task.fields)) restoreCoreValue($(id), value);
      for (const item of draft.task.extras || []) { const control = extraControl(item); if (control) restoreCoreValue(control, item.value); }
      for (const [id, value] of Object.entries(draft.task.planning || {})) $(id).value = value;
      $('comment-body').value = draft.task.comment?.body || ''; restoreCoreValue($('comment-mention'), draft.task.comment?.mention || '');
      if (draft.task.edited) { editingCommentId = draft.task.edited.id; $('comment-edit-body').value = draft.task.edited.body; $('comment-dialog').showModal(); }
    }
    if (draft?.create) {
      const old = draft.create; createTaskId = old.id; createTaskKey = old.key; parentId = old.parent;
      options($('create-project'), projects.filter(p => p.can_add_task), old.parent ? 'Inherit parent access' : 'Private personal capture'); options($('create-assignee'), members);
      $('create-project').value = old.values.project; sections($('create-project'), $('create-section'));
      for (const [id, value] of Object.entries(old.values)) restoreCoreValue($('create-' + id), value);
      $('task-dialog').showModal();
    }
    coreStash = null; coreClosed = null; freezeCore(!!coreSave); coreNotice();
  }
  async function recoverCore() {
    if (coreSaving || coreRecovering) return;
    coreRecovering = true; $('draft-bar').setAttribute('aria-busy', 'true'); $('draft-resume').disabled = true;
    const retained = coreAccessBlocked ? coreStash : captureCore() || coreStash, epoch = ++coreEpoch;
    const probe = new AbortController(), timer = setTimeout(() => probe.abort(), 5000);
    try {
      const response = await fetch(url(root.dataset.workspacesUrl), { credentials: 'same-origin', cache: 'no-store', signal: probe.signal, headers: { Accept: 'application/json' } });
      const identity = response.ok ? await response.json() : null;
      if (epoch !== coreEpoch) return;
      if (identity?.viewer_id !== root.dataset.user || identity?.workspace?.id !== root.dataset.workspaceId) { if (!coreAccessBlocked) sealCore(); status('Original account/workspace could not be verified. Draft stays hidden in this page.'); return; }
      if (typeof identity.csrf_token !== 'string' || !identity.csrf_token) throw new Error('Refresh session verification before recovering the draft.');
      document.querySelector('meta[name="csrf-token"]').content = identity.csrf_token;
      const headers = { Accept: 'application/json', 'X-Team-Viewer': root.dataset.user, 'X-Team-Workspace': root.dataset.workspaceId };
      const get = async path => { const r = await fetch(url(path), { credentials: 'same-origin', cache: 'no-store', headers }); if (!r.ok || r.headers.get('X-Team-Viewer') !== root.dataset.user) throw new Error('Current work access is required before restoring the draft.'); return r.json(); };
      let current;
      if (retained?.task) { current = await get(root.dataset.taskUrl + '/' + retained.task.id); if (!current.can_edit) throw new Error('Current edit access is required before restoring this draft.'); }
      const creation = retained?.create;
      if (creation?.parent) { const parent = await get(root.dataset.taskUrl + '/' + creation.parent); if (!parent.can_edit) throw new Error('Current parent edit access is required.'); }
      if (creation?.values.project) { const p = await get(root.dataset.projectUrl + '/' + creation.values.project); if (p.archived) throw new Error('Reopen the original project before recovering this creation draft.'); }
      if (epoch !== coreEpoch) return;
      coreAccessBlocked = false; $('detail').hidden = false; await load();
      if (creation?.values.project && !projects.some(p => p.id === creation.values.project && p.can_add_task)) throw new Error('Current project edit access is required.');
      if (retained?.task) {
        await openTask(retained.task.id, null, true);
        if (epoch !== coreEpoch || !selected) return;
        if ((retained.task.extras || []).some(item => !extraControl(item) || extraControl(item).dataset.draftWritable !== 'true')) throw new Error('Original field edit access is required before recovering this draft.');
        const changed = coreConflict || (current.version !== retained.task.version && !coreSave);
        coreReview = changed; coreConflict = false;
        if (changed) { $('draft-current').textContent = 'Current saved title: ' + current.title + '\nCurrent saved description:\n' + current.description + '\nCurrent comment:\n' + (current.comments[retained.task.edited?.id]?.body || '') + '\nCurrent structured values: ' + JSON.stringify(current.custom_fields || {}) + '\nCurrent recurrence: ' + JSON.stringify(current.recurrence) + '\nCurrent version: ' + current.version; $('draft-review').hidden = false; $('draft-ack').checked = false; }
      }
      restoreCore(retained); if (coreSave) status('Original account/access verified. Retry the same request to confirm its result.');
    } catch (error) { if (epoch !== coreEpoch) return; coreStash = retained; sealCore(retained); status(error.message || 'Verification failed. Draft remains hidden.'); }
    finally { clearTimeout(timer); coreRecovering = false; $('draft-bar').setAttribute('aria-busy', 'false'); coreNotice(); }
  }
  async function saveCore(channel, input) {
    if (coreSaving || coreRecovering || coreAccessBlocked) return false;
    if (coreReview && !$('draft-ack').checked) { status('Compare your edits with the current saved text and acknowledge the review first.'); return false; }
    if (coreSave && coreSave.channel !== channel) { status('Retry the same pending save first.'); return false; }
    if (!coreSave) coreSave = { channel, payload: structuredClone({ ...input, idempotency_key: input.idempotency_key || crypto.randomUUID() }) };
    const saved = coreSave, retained = captureCore(), epoch = coreEpoch; coreSaving = true; freezeCore(true); coreNotice();
    try {
      const reply = await command(saved.payload);
      if (epoch !== coreEpoch || coreAccessBlocked) return false;
      coreSave = null; coreReview = false; $('draft-review').hidden = true; $('draft-current').textContent = '';
      if (retained?.task) {
        if (channel === 'edit') retained.task.fields = {};
        if (channel === 'comment') retained.task.comment = null;
        if (channel === 'comment-edit') retained.task.edited = null;
        if (channel === 'fields') retained.task.extras = (retained.task.extras || []).filter(item => item.project !== saved.payload.project_id);
        if (channel === 'recurrence') retained.task.planning = null;
        if (retained.task.id === reply.result.id) retained.task.version = reply.result.version;
      }
      if (channel === 'create') { if (retained) delete retained.create; createInitial = null; $('create-form').reset(); $('task-dialog').close(); }
      if (channel === 'comment-edit') { editingCommentId = null; $('comment-edit-body').value = ''; $('comment-dialog').close(); }
      if (channel === 'comment') $('comment-body').value = '';
      coreStash = retained; coreRefreshing = true; await load();
      if (epoch !== coreEpoch || coreAccessBlocked) { status('Save confirmed. Access changed while refreshing; other unsaved work remains sealed.'); return true; }
      await openTask(reply.result.id, null, true);
      if (epoch !== coreEpoch || coreAccessBlocked) { status('Save confirmed. Access changed while refreshing; other unsaved work remains sealed.'); return true; }
      restoreCore(retained);
      if (reply.replayed && retained?.task && (Object.keys(retained.task.fields).length || retained.task.extras?.length || retained.task.planning) && reply.result.version > (saved.payload.expected_version || 0) + 1) { coreReview = true; $('draft-current').textContent = 'Current saved title: ' + reply.result.title + '\nCurrent saved description:\n' + reply.result.description + '\nCurrent structured values: ' + JSON.stringify(reply.result.custom_fields || {}) + '\nCurrent recurrence: ' + JSON.stringify(reply.result.recurrence); $('draft-review').hidden = false; $('draft-ack').checked = false; }
      status(reply.replayed ? 'Save confirmed. The original request was applied once.' : 'Saved.'); return true;
    } catch (error) {
      if (!coreAccessBlocked && error.saved === false && error.reason !== 'idempotency_mismatch') { coreSave = null; coreConflict = error.status === 409; status(error.message); }
      else status('Save unconfirmed or access changed. Your draft and exact request are retained; verify access and retry the same save.');
      return false;
    } finally { coreRefreshing = false; coreSaving = false; freezeCore(!!coreSave || coreAccessBlocked); coreNotice(); }
  }
  function installCoreGuard() {
    coreSelectDefaults = [...root.querySelectorAll('select')].map(select => [select, [...select.children].map(option => option.cloneNode(true))]);
    acceptedFilters = Object.fromEntries(filterIds.map(id => [id, $(id).value]));
    root.addEventListener('input', () => { if (!coreAccessBlocked) coreNotice(); }); root.addEventListener('change', () => { if (!coreAccessBlocked) coreNotice(); });
    $('draft-resume').onclick = recoverCore;
    $('draft-discard').onclick = async () => {
      if (coreSave || coreAccessBlocked || coreSaving || coreRecovering) return;
      corePrompting = true; let confirmed; try { confirmed = confirm('Discard only these unsent task/comment edits? Saved work and history stay unchanged.'); } finally { corePrompting = false; }
      if (!confirmed) return; const epoch = coreEpoch; coreRecovering = true; freezeCore(true);
      try {
        if (await verifyStatusOwner() !== 'owner' || epoch !== coreEpoch || coreAccessBlocked) { sealCore(); status('Original account could not be verified. Draft retained privately.'); return; }
        coreStash = null; coreClosed = null; coreConflict = false; coreReview = false; createInitial = null; editingCommentId = null;
        for (const control of $('fields').querySelectorAll('[data-draft-field]')) control.value = control.dataset.savedValue;
        for (const [id, value] of Object.entries(planningInitial ? JSON.parse(planningInitial) : {})) $(id).value = value;
        $('create-form').reset(); $('comment-body').value = ''; $('comment-edit-body').value = ''; $('draft-review').hidden = true; $('draft-current').textContent = '';
        for (const dialog of [$('task-dialog'), $('comment-dialog')]) dialog.close();
        if (selected) { for (const [id, field] of Object.entries(coreFields)) $(id).value = selected[field] || ''; $('detail').hidden = false; }
        status('Unsent edits discarded. Saved work is unchanged.');
      } finally { coreRecovering = false; freezeCore(!!coreSave || coreAccessBlocked); coreNotice(); }
    };
    $('draft-close').onclick = () => { if (!coreDirty()) { clearDetail(); return; } if (coreSave || coreSaving) { status('Confirm the save before closing.'); return; } coreClosed = 'task'; $('detail').hidden = true; coreNotice(); };
    for (const id of ['task-dialog', 'comment-dialog']) {
      const dialog = $(id), close = () => { const retained = captureCore(); coreClosed = id === 'task-dialog' ? (retained?.create ? 'create' : null) : (retained?.task?.edited ? 'comment' : null); coreNotice(); };
      dialog.addEventListener('close', close); dialog.addEventListener('cancel', () => { if (coreSave || coreSaving) { /* Closing retains the same frozen fields and request. */ } close(); });
    }
    addEventListener('beforeunload', event => { if (coreDirty() || coreSaving || pendingMutations) { event.preventDefault(); event.returnValue = ''; } });
    addEventListener('pageshow', event => { if (event.persisted) { if (coreDirty()) { sealCore(); status('Restored page is locked. Verify the original account and access to recover retained input.'); } else location.reload(); } });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden' && coreDirty()) sealCore(); });
    addEventListener('focus', () => { if (!corePrompting && coreDirty() && !coreAccessBlocked) { sealCore(); status('Verify the original account and access before resuming retained input.'); } else if (coreAccessBlocked) coreNotice(); });
    document.addEventListener('livewire:navigate', event => { if (coreDirty() || pendingMutations) { event.preventDefault(); status('Save or explicitly discard retained work before leaving this page.'); coreNotice(); } });
    document.addEventListener('click', event => { if (!event.target.closest('a[href]') || event.defaultPrevented || event.button !== 0) return; if (coreDirty() || pendingMutations) { event.preventDefault(); event.stopImmediatePropagation(); status('Save or explicitly discard retained work before following this link.'); coreNotice(); } }, true);
  }

  for (const button of document.querySelectorAll('[data-team-mode]')) button.onclick = () => {
    if (!coreNavigationReady() || !workspaceReady()) return;
    premium?.navigate();
    $('mode').value = button.dataset.teamMode; $('project-filter').value = ''; $('search').value = ''; $('date-filter').value = 'all'; $('priority-filter').value = ''; $('completion').value = premium ? 'incomplete' : 'all'; $('layout').value = 'list';
    page = 1; projectSearchPage = 1; clearDetail(); load();
  };
  const appCreate = document.getElementById('teams-create'); if (appCreate) appCreate.onclick = () => { if (workspaceReady()) createTask(); };
  installCoreGuard();
  (async () => {
    const initialTask = root.dataset.initialTask, initialComment = root.dataset.initialComment, initialProject = root.dataset.initialProject;
    if (initialTask || initialProject) $('mode').value = 'all';
    await load(); if (initialTask) await openTask(initialTask, initialComment || null);
    if (initialProject) { $('project-filter').value = initialProject; await load(); await openOverview(initialProject); }
  })();
})();
