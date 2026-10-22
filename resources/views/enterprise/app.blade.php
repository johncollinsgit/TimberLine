@php
    // Controllers may supply a complete presentation for the isolated sample UI.
    // Its identity is presentation only; authorization remains in the private backend.
    $enterprisePresentation = $presentation ?? [
        'runtime' => 'private',
        'identity' => ['tenantSlug' => $tenant->slug, 'viewer' => (string) auth()->id(), 'workspace' => (string) $tenant->id, 'name' => $tenant->name, 'userName' => auth()->user()->name],
        'csrf' => csrf_token(),
        'routes' => [
            'data' => route('enterprise.data', ['tenant' => $tenant->slug]),
            'commands' => route('enterprise.commands', ['tenant' => $tenant->slug]),
            'history' => url('/enterprise/requests'), 'enterprise' => url('/enterprise'), 'team' => url('/team'),
            'workflows' => url('/enterprise/workflows'), 'workflowWorkspace' => url('/projects/workspace'),
            'briefing' => url('/enterprise/briefing'), 'briefingPdf' => url('/enterprise/briefing.pdf'),
            'back' => route('enterprise.index', ['tenant' => $tenant->slug]), 'logout' => route('logout'),
        ],
    ];
    $enterpriseSample = ($enterprisePresentation['runtime'] ?? 'private') === 'sample';
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ $enterpriseSample ? "" : ($enterprisePresentation["csrf"] ?? "") }}"><title>Everbranch Enterprise · {{ $enterprisePresentation["identity"]["name"] }}</title>
@include('enterprise.campus-styles')
<style>.shell{min-height:100vh}.sidebar{height:100vh}.detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}.work-detail{white-space:pre-wrap;overflow-wrap:anywhere}.map-panel{padding:0;overflow:hidden;margin-bottom:22px}.map-head{padding:18px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.map-head h2{font:26px Georgia,serif;margin:5px 0}.location-map{display:block;width:100%;height:330px;background:#edf2e7}.location-map button{cursor:pointer}.request-row{width:100%;text-align:left}.stack{display:grid;gap:12px}.stack label{display:grid;gap:6px;font-size:12px}.ent-skip{position:absolute;top:-80px;background:white;padding:15px;z-index:10}.ent-skip:focus{top:0}[hidden]{display:none!important}.notice-error{color:#913c31}.calendar-day{padding:15px;border-bottom:1px solid var(--line)}.calendar-day h3{font-size:12px}.field-pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}#detail h2{font:25px Georgia,serif}#detail h3{font-size:12px}#map-holder{position:relative}#map-markers{position:absolute;inset:30px 20px 42px}.map-pin{position:absolute;transform:translate(-50%,-50%);padding:5px 8px;border:2px solid white;background:#315b43;color:white;border-radius:14px;font-size:11px;box-shadow:0 2px 5px #2343}#map-holder p{margin:8px 20px 16px}.sidebar-project.active{background:#ffffff12;border-radius:5px}.panel{min-width:0}pre{white-space:pre-wrap;overflow-wrap:anywhere}#request-list{min-width:0}.nav-item{min-height:44px}@media(max-width:1000px){.detail-layout{grid-template-columns:1fr}.sidebar{height:auto;min-height:0;position:static}.shell{grid-template-columns:190px 1fr}.content{padding:25px 18px}.topbar{padding:15px 18px}}@media(max-width:650px){.shell{grid-template-columns:1fr}.sidebar{padding:15px}.brand{padding-bottom:12px}.workspace-label{margin-top:12px}.sidebar nav{display:flex;flex-wrap:wrap}.nav-item{width:auto}.sidebar-bottom{display:none}.topbar{height:auto}.field-pair{grid-template-columns:1fr}.page-heading{align-items:flex-start;flex-direction:column}.page-heading h1{font-size:30px}.toolbar{flex-wrap:wrap}.sidebar-project{width:auto;display:inline-flex}}#work{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}#work>.toolbar,#work>#coverage{grid-column:1/-1}#work>#map{grid-column:1}.detail-layout{display:contents}#request-list{grid-column:1}#detail{grid-column:2;grid-row:2 / span 2;align-self:start}@media(max-width:1000px){#work{display:block}.detail-layout{display:grid;grid-template-columns:1fr}#request-list,#detail{grid-column:auto;grid-row:auto}#work>.toolbar{margin-bottom:20px}}@media(max-width:800px){.sidebar .workspace-label{display:block}.sidebar .sidebar-project{display:inline-flex;width:auto}}
@include('enterprise.next-styles')
</style></head>
<body><a class="ent-skip" href="#enterprise-main">Skip to workspace</a><div class="shell">
<aside class="sidebar" aria-label="Everbranch Enterprise"><div class="brand"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 20c1-9 7-14 13-17 2 9-2 16-9 15M5 22l9-12"/></svg></span><div><strong>Everbranch</strong><small>Enterprise · Teams</small></div></div>
<p class="workspace-label">Organization workspace</p><nav aria-label="Workspace sections"><button class="nav-item" data-section="home" id="home-nav" hidden>Campus overview</button><button class="nav-item active" data-section="requests">Requests</button><button class="nav-item" data-section="projects">Operations &amp; projects</button><button class="nav-item" data-section="contractors">Contractors</button><button class="nav-item" data-section="ask" id="ask-nav" hidden>Ask Everbranch</button>@includeIf('enterprise.operations-nav')</nav>
<p class="workspace-label">Your workspaces</p><div id="areas"></div><div class="sidebar-bottom"><strong>{{ $enterprisePresentation["identity"]["name"] }}</strong><br>{{ $enterprisePresentation["identity"]["userName"] }}<br>Connected work. Clear ownership.@unless($enterpriseSample)<form id="enterprise-logout" method="post" action="{{ $enterprisePresentation['routes']['logout'] }}"><input type="hidden" name="_token" value="{{ $enterprisePresentation['csrf'] ?? '' }}"><button class="nav-item" type="submit">Sign out</button></form>@endunless</div></aside>
<main class="main" id="enterprise-main"><header class="topbar"><div class="breadcrumb">{{ $enterprisePresentation["identity"]["name"] }} <span>/</span> <strong id="breadcrumb">Requests</strong></div><span class="badge" id="visibility">Loading workspace</span></header>
<div class="content"><div class="eyebrow">Everbranch Enterprise</div><div class="page-heading"><div><h1 id="heading">Turn requests into owned work.</h1><p class="subtle">One place for intake, assignment and visible progress.</p></div><div class="actions"><button class="button" id="create-workspace" hidden>New workspace</button><button class="button primary" id="create-request" hidden>New request</button><button class="button primary" id="create-project" hidden>New project</button></div></div>
<p id="status" role="status" aria-live="polite"></p><button class="button" id="retry" hidden>Retry the same action</button>
@include('enterprise.home')
@include('enterprise.next-panels')
@includeIf('enterprise.operations-panels')
<div id="work"><div class="toolbar"><div class="actions" role="group" aria-label="Work view"><button class="button" data-view="list" aria-pressed="true">List</button><button class="button" data-view="calendar" aria-pressed="false">Calendar</button><button class="button" data-view="map" aria-pressed="false">Map</button></div><label>Find work <input id="search" type="search" placeholder="Search visible work"></label><button class="button" id="map-toggle">View Map</button></div>
<section class="panel map-panel" id="map" hidden><div class="map-head"><div><div class="eyebrow">Workspace locations</div><h2>See where the work happens.</h2></div><span class="caption">Select a marker for request details</span></div><div id="map-holder"><svg class="location-map" viewBox="0 0 1000 330" preserveAspectRatio="none" aria-hidden="true"><defs><pattern id="grid" width="100" height="66" patternUnits="userSpaceOnUse"><path d="M100 0H0V66" fill="none" stroke="#d9e2ce"/></pattern></defs><rect width="1000" height="330" fill="url(#grid)"/></svg><div id="map-markers"></div><p class="caption">Saved longitude/latitude positions on a coordinate grid. No campus basemap, GPS feed or external map provider is connected.</p></div><p class="caption" id="map-empty"></p></section>
<div class="detail-layout"><section id="request-list" aria-label="Visible workspace work"></section><aside class="panel" id="detail" aria-label="Selected work details"><h2>Choose a request</h2><p class="subtle">Select a row or map marker to see its details and permitted actions.</p></aside></div><p class="caption" id="coverage"></p></div>
</div></main></div>
@if($enterpriseSample)@includeIf('enterprise.reviewer-tour')@endif
<dialog id="create-dialog"><form id="create-form" class="stack"><h2 id="dialog-title">New request</h2><label>Title<input name="title" maxlength="100" required></label><label id="visibility-field" hidden>Visibility<select name="visibility"><option value="department_only">Department only</option><option value="shared">Shared across organization</option></select></label><div id="request-fields" class="stack"><label>Work description<textarea name="description" maxlength="5000" rows="4"></textarea></label><label>Due date<input name="due_on" type="date"></label><label>Location label<input name="location" maxlength="200"></label><div class="field-pair"><label>Latitude (optional)<input name="latitude" type="number" min="-90" max="90" step="any"></label><label>Longitude (optional)<input name="longitude" type="number" min="-180" max="180" step="any"></label></div></div><p class="caption" id="create-context"></p><div class="actions"><button class="button primary" type="submit">Save</button><button class="button" type="button" id="cancel-create">Cancel</button></div></form></dialog>
<script>{!! file_get_contents(resource_path('js/enterprise/transport.js')) !!}</script>
@if($enterpriseSample)
@includeIf('enterprise.reviewer-adapter')
@endif
<script>
(() => {
  'use strict';

  const presentation = @json($enterprisePresentation),
    transport = EverbranchTransport.create(presentation),
    urls = presentation.routes,
    tenantSlug = presentation.identity.tenantSlug,
    viewer = presentation.identity.viewer,
    workspace = presentation.identity.workspace;
  const allowedSections = presentation.sections || ['home', 'projects', 'contractors', 'ask', 'requests', 'roll_call', 'buildings', 'calendar', 'people', 'assets', 'workflows', 'requester'];
  const navigation = new URLSearchParams(transport.mode === 'sample' ? location.hash.slice(1) : location.search);
  for (const key of ['area_id', 'selected', 'section']) {
    if (!navigation.has(key) && presentation.navigation?.[key]) navigation.set(key, presentation.navigation[key]);
  }
  const $ = id => document.getElementById(id),
    esc = x => String(x ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[c]);
  let data = {
      areas: [],
      requests: [],
      projects: [],
      members: []
    },
    area = navigation.get('area_id'),
    selected = navigation.get('selected'),
    section = allowedSections.includes(navigation.get('section')) ? navigation.get('section') : 'requests',
    view = 'list',
    map = false,
    pending = null,
    createAction = '',
    draft = null,
    busy = false;
  function message(text, error = false) {
    $('status').textContent = text;
    $('status').classList.toggle('notice-error', error);
  }
  const api = (url, body, options) => transport.request(url, body, options);
  async function load() {
    try {
      const result = await api(urls.data + (area ? '&area_id=' + encodeURIComponent(area) : ''));
      if (!data.features && !navigation.has('section') && result.features?.briefing) section = 'home';
      data = result;
      area = data.selected_area_id;
      render();
    } catch (error) {
      data = {
        areas: [],
        requests: [],
        projects: [],
        members: []
      };
      $('request-list').replaceChildren();
      $('detail').replaceChildren();
      resetNext();
      message(error.message, true);
    }
  }
  async function command(body) {
    if (busy) return;
    busy = true;
    pending = body;
    $('retry').hidden = true;
    message('Saving…');
    try {
      const result = await api(urls.commands, body);
      pending = null;
      $('create-dialog').close();
      if (body.action === 'workspace.create') area = result.id;
      await load();
      message('Saved.');
    } catch (error) {
      message(error.message + ' Your action is retained in this page for retry.', true);
      $('retry').hidden = false;
    } finally {
      busy = false;
    }
  }
  function rows() {
    const query = $('search').value.trim().toLowerCase();
    return (section === 'projects' ? data.projects : data.requests).filter(r => (r.title || r.name).toLowerCase().includes(query)).filter(r => {
      if (typeof preferencesState === 'undefined' || !preferencesState || preferencesArea !== area) return true;
      const filter=preferencesState.effective.filter||'all', complete=r.archived||r.status==='completed';
      const currentViewer=data.viewer_id||viewer;
      return filter==='all'||filter==='active'&&!complete||filter==='completed'&&complete||filter==='mine'&&(r.task?.assignee_id===currentViewer||r.data?.created_by===currentViewer);
    });
  }
  function render({preserveDetail=false}={}) {
    $('areas').innerHTML = data.areas.map(a => `<button class="sidebar-project ${a.id === area ? 'active' : ''}" data-area="${esc(a.id)}"><span class="dot"></span>${esc(a.name)}</button>`).join('');
    $('areas').querySelectorAll('[data-area]').forEach(b => b.onclick = () => {
      rememberSelection();
      area = b.dataset.area;
      selected = selectionMemory.get(area + ':' + section) || null;
      resetNext();
      load();
    });
    const a = data.areas.find(a => a.id === area);
    $('visibility').textContent = a ? a.visibility === 'shared' ? 'Shared across organization' : 'Department only' : 'No workspaces yet';
    $('create-workspace').hidden = !data.can_manage;
    $('create-request').hidden = !data.can_submit || !area || section !== 'requests';
    $('create-project').hidden = !data.can_coordinate || section !== 'projects';
    $('contractors').hidden = section !== 'contractors';
    $('work').hidden = section === 'contractors';
    $('heading').textContent = section === 'projects' ? 'Work with clear ownership.' : section === 'contractors' ? 'Trusted partners, connected work.' : 'Turn requests into owned work.';
    $('breadcrumb').textContent = section === 'projects' ? 'Operations & projects' : section === 'contractors' ? 'Contractors' : 'Requests';
    renderNext();
    renderHome();
    $('map').hidden = !map;
    $('map-toggle').textContent = map ? 'Hide Map' : 'View Map';
    document.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === view)));
    const work = rows();
    if (view === 'calendar') {
      const days = [...new Set(work.map(r => r.due_on || 'Unscheduled'))].sort();
      $('request-list').innerHTML = `<div class="panel">${days.map(day => `<section class="calendar-day"><h3>${esc(day)}</h3>${work.filter(r => (r.due_on || 'Unscheduled') === day).map(r => `<button class="button request-row" data-open="${esc(r.id)}">${esc(r.title || r.name)}</button>`).join('')}</section>`).join('') || '<p class="empty">No scheduled work in this workspace.</p>'}</div>`;
    } else {
      $('request-list').innerHTML = `<div class="table-wrap"><table><thead><tr><th>Work</th><th>Status</th><th>Due</th></tr></thead><tbody>${work.map(r => `<tr><td><button class="link" data-open="${esc(r.id)}">${esc(r.title || r.name)}</button></td><td><span class="badge">${esc(r.status || (r.archived ? 'Archived' : 'Active'))}</span></td><td>${esc(r.due_on || 'Unscheduled')}</td></tr>`).join('')}</tbody></table>${work.length ? '' : '<p class="empty">No work yet. Create a request to begin.</p>'}</div>`;
    }
    $('request-list').querySelectorAll('[data-open]').forEach(b => b.onclick = () => {
      selected = b.dataset.open;
      detail();
    });
    const located = data.requests.filter(r => r.data.latitude !== null && r.data.longitude !== null && Number.isFinite(Number(r.data.latitude)) && Number.isFinite(Number(r.data.longitude)));
    const lats = located.map(r => Number(r.data.latitude)),
      lons = located.map(r => Number(r.data.longitude));
    const minX = Math.min(...lons),
      maxX = Math.max(...lons),
      minY = Math.min(...lats),
      maxY = Math.max(...lats);
    $('map-markers').innerHTML = located.map((r, i) => `<button class="map-pin" style="left:${maxX === minX ? 50 : 10 + 80 * (Number(r.data.longitude) - minX) / (maxX - minX)}%;top:${maxY === minY ? 50 : 10 + 80 * (maxY - Number(r.data.latitude)) / (maxY - minY)}%" data-map="${esc(r.id)}" aria-label="Open ${esc(r.title)}">${i + 1}</button>`).join('');
    $('map-markers').querySelectorAll('[data-map]').forEach(b => b.onclick = () => {
      section = 'requests';
      selected = b.dataset.map;
      render();
    });
    $('map-empty').textContent = located.length ? '' : 'No saved coordinates in the loaded requests.';
    $('coverage').textContent = 'Showing up to 100 currently visible requests in this workspace.' + (data.has_more ? ' More visible requests exist.' : '') + (data.scan_partial ? ' The scan limit was reached; results are partial.' : '');
    if(!preserveDetail)detail();
    document.dispatchEvent(new CustomEvent('enterprise:render', {detail: {data, area, section, selected, transport}}));
  }
  function detail() {
    stashComposer();
    discussionGeneration++;
    activeDiscussionKey = null;
    rememberUrl();
    if (['home', 'ask', 'contractors'].includes(section)) return;
    const r = (section === 'projects' ? data.projects : data.requests).find(r => r.id === selected);
    if (!r) {
      $('detail').innerHTML = '<h2>Choose work</h2><p class="subtle">Select a row or map marker for details.</p>';
      return;
    }
    if (section === 'projects') {
      $('detail').innerHTML = `<h2>${esc(r.name)}</h2><span class="badge">${esc($('visibility').textContent)}</span><p class="work-detail">${esc(r.description)}</p><p class="caption">Project version ${r.version}. Requests can be assigned into this project during triage.</p>`;
      if (data.features?.workflow) { const launch = document.createElement('a'); launch.className = 'button primary'; launch.textContent = 'Open workflow workspace'; launch.href = transport.href(urls.workflowWorkspace + '?tenant=' + encodeURIComponent(tenantSlug) + '&area_id=' + encodeURIComponent(area) + '&project_id=' + encodeURIComponent(r.id)); $('detail').append(launch); }
      mountDiscussion('project', r.id);
      if (data.features?.operations) mountProjectOperations(r.id);
      return;
    }
    $('detail').innerHTML = `<span class="badge">${esc(r.status)}</span><h2>${esc(r.title)}</h2><p class="work-detail">${esc(r.data.description)}</p><h3>Location</h3><p class="subtle">${esc(r.data.location || 'Not provided')}</p><h3>Progress</h3><p class="work-detail">${esc(r.data.progress_note || r.data.triage_note || 'No update yet.')}</p>${r.data.task_id ? `<p class="caption">Linked task: ${esc(r.task?.title || r.data.task_id)}<br>Assignee: ${esc(data.members.find(m => m.id === r.task?.assignee_id)?.name || 'Existing workspace member')}</p>` : ''}<div class="stack">${r.can_coordinate && r.status === 'submitted' ? '<label>Triage note<textarea id="action-note" maxlength="2000"></textarea></label><button class="button primary" data-action="request.triage">Triage request</button>' : ''}${r.can_coordinate && r.status === 'triaged' ? `<label>Project<select id="assign-project">${data.projects.filter(p => !p.archived).map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('')}</select></label><label>Existing editor<select id="assign-member">${data.members.map(m => `<option value="${esc(m.id)}">${esc(m.name)}</option>`).join('')}</select></label><button class="button primary" data-action="request.assign">Assign task</button>` : ''}${r.can_progress && ['assigned', 'in_progress'].includes(r.status) ? '<label>Progress note<textarea id="action-note" maxlength="2000"></textarea></label><button class="button" data-action="request.progress">Save progress</button><button class="button primary" data-action="request.complete">Complete work</button>' : ''}<button class="button" id="show-history">Audit history</button><div id="history"></div></div>`;
    $('detail').querySelectorAll('[data-action]').forEach(b => b.onclick = () => {
      if (pending) return message('Retry the retained action before making another change.', true);
      const body = {
        action: b.dataset.action,
        id: r.id,
        area_id: area,
        request_id: crypto.randomUUID(),
        expected_version: r.version
      };
      if ($('action-note')) body.note = $('action-note').value;
      if (body.action === 'request.assign') {
        if (!$('assign-project').value || !$('assign-member').value) return message('Create a project and select an existing authorized editor first.', true);
        body.project_id = $('assign-project').value;
        body.assignee_id = $('assign-member').value;
      }
      command(body);
    });
    $('show-history').onclick = async () => {
      try {
        const result = await api(urls.history + '/' + encodeURIComponent(r.id) + '/history?tenant=' + encodeURIComponent(tenantSlug));
        $('history').innerHTML = result.items.map(x => `<p class="caption">Version ${x.version} · ${esc(x.event.action)}<br>${esc(x.at)} · actor ${esc(x.actor_id)}</p>`).join('');
      } catch (e) {
        message(e.message, true);
      }
    };
    mountDiscussion('request', r.id);
    if (data.features?.operations) mountRequestOperations(r);
  }
  @include('enterprise.home-script')
  function create(action) {
    if (pending) return message('Retry the retained action before starting another.', true);
    createAction = action;
    draft = {
      id: crypto.randomUUID(),
      request_id: crypto.randomUUID()
    };
    $('create-form').reset();
    $('dialog-title').textContent = action === 'workspace.create' ? 'New workspace' : action === 'project.create' ? 'New project' : 'New request';
    $('visibility-field').hidden = action !== 'workspace.create';
    $('request-fields').hidden = action !== 'request.create';
    $('create-context').textContent = action === 'workspace.create' ? 'Creating a workspace adds no users or access grants. Department access must already be authorized.' : 'Saved within the selected workspace and its visibility boundary.';
    $('create-dialog').showModal();
  }
  $('create-form').onsubmit = e => {
    e.preventDefault();
    if (pending) return;
    const fields = new FormData(e.target),
      body = {
        ...draft,
        action: createAction,
        title: fields.get('title')
      };
    if (createAction === 'workspace.create') body.visibility = fields.get('visibility');else body.area_id = area;
    if (createAction === 'request.create') {
      body.expected_version = 0;
      for (const key of ['description', 'location', 'due_on', 'latitude', 'longitude']) if (fields.get(key) !== '') body[key] = fields.get(key);
    }
    command(body);
  };
  $('create-workspace').onclick = () => create('workspace.create');
  $('create-project').onclick = () => create('project.create');
  $('create-request').onclick = () => create('request.create');
  $('cancel-create').onclick = () => {
    $('create-dialog').close();
  };
  $('retry').onclick = () => pending && command(pending);
  $('search').oninput = render;
  $('map-toggle').onclick = () => {
    map = !map;
    render();
  };
  document.querySelectorAll('[data-view]').forEach(b => b.onclick = () => {
    view = b.dataset.view;
    if (view === 'map') map = true;
    render();
  });
  document.querySelectorAll('[data-section]').forEach(b => b.onclick = () => {
    rememberSelection();
    message('');
    section = b.dataset.section;
    selected = selectionMemory.get(area + ':' + section) || null;
    document.querySelectorAll('[data-section]').forEach(x => x.classList.toggle('active', x === b));
    render();
  });
  @include('enterprise.next-script')
  @includeIf('enterprise.operations-script')
  document.addEventListener('enterprise:navigate', event => {
    const next = event.detail || {};
    if (next.section && !allowedSections.includes(next.section)) return;
    rememberSelection();
    if (next.area !== undefined) area = next.area;
    if (next.section) section = next.section;
    selected = next.selected ?? null;
    if (next.area !== undefined) {resetNext(); load();} else render();
  });
  load();
})();
</script></body></html>
