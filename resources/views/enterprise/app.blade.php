@php
    // Controllers may supply a complete presentation for the isolated sample UI.
    // Its identity is presentation only; authorization remains in the private backend.
    $enterprisePresentation = $presentation ?? [
        'runtime' => 'private',
        'branding' => $clientBranding ?? [],
        'identity' => ['tenantSlug' => $tenant->slug, 'viewer' => (string) auth()->id(), 'workspace' => (string) $tenant->id, 'name' => $clientBranding['organization_name'] ?? $tenant->name, 'userName' => auth()->user()->name],
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
    $enterpriseInstall = $enterpriseSample ? [] : \App\Enterprise\AppManifest::links(request());
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ $enterpriseSample ? "" : ($enterprisePresentation["csrf"] ?? "") }}"><title>Everbranch Enterprise · {{ $enterprisePresentation["identity"]["name"] }}</title>
@include('enterprise.campus-styles')
@include('enterprise.app-install-head')
<style>.shell{min-height:100vh}.sidebar{height:100vh}.detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}.work-detail{white-space:pre-wrap;overflow-wrap:anywhere}.map-panel{padding:0;overflow:hidden;margin-bottom:22px}.map-head{padding:18px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.map-head h2{font:26px Georgia,serif;margin:5px 0}.location-map{display:block;width:100%;height:330px;background:#edf2e7}.location-map button{cursor:pointer}.request-row{width:100%;text-align:left}.stack{display:grid;gap:12px}.stack label{display:grid;gap:6px;font-size:12px}.ent-skip{position:absolute;top:-80px;background:white;padding:15px;z-index:10}.ent-skip:focus{top:0}[hidden]{display:none!important}.notice-error{color:#913c31}.calendar-day{padding:15px;border-bottom:1px solid var(--line)}.calendar-day h3{font-size:12px}.field-pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}#detail h2{font:25px Georgia,serif}#detail h3{font-size:12px}#map-holder{position:relative}#map-markers{position:absolute;inset:30px 20px 42px}.map-pin{position:absolute;transform:translate(-50%,-50%);padding:5px 8px;border:2px solid white;background:#315b43;color:white;border-radius:14px;font-size:11px;box-shadow:0 2px 5px #2343}#map-holder p{margin:8px 20px 16px}.sidebar-project.active{background:#ffffff12;border-radius:5px}.panel{min-width:0}pre{white-space:pre-wrap;overflow-wrap:anywhere}#request-list{min-width:0}.nav-item{min-height:44px}@media(max-width:1000px){.detail-layout{grid-template-columns:1fr}.sidebar{height:auto;min-height:0;position:static}.shell{grid-template-columns:190px 1fr}.content{padding:25px 18px}.topbar{padding:15px 18px}}@media(max-width:650px){.shell{grid-template-columns:1fr}.sidebar{padding:15px}.brand{padding-bottom:12px}.workspace-label{margin-top:12px}.sidebar nav{display:flex;flex-wrap:wrap}.nav-item{width:auto}.sidebar-bottom{display:none}.topbar{height:auto}.field-pair{grid-template-columns:1fr}.page-heading{align-items:flex-start;flex-direction:column}.page-heading h1{font-size:30px}.toolbar{flex-wrap:wrap}.sidebar-project{width:auto;display:inline-flex}}#work{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}#work>.toolbar,#work>#coverage{grid-column:1/-1}#work>#map{grid-column:1}.detail-layout{display:contents}#request-list{grid-column:1}#detail{grid-column:2;grid-row:2 / span 2;align-self:start}@media(max-width:1000px){#work{display:block}.detail-layout{display:grid;grid-template-columns:1fr}#request-list,#detail{grid-column:auto;grid-row:auto}#work>.toolbar{margin-bottom:20px}}@media(max-width:800px){.sidebar .workspace-label{display:block}.sidebar .sidebar-project{display:inline-flex;width:auto}}
@include('enterprise.next-styles')
</style>@if(!$enterpriseSample && preg_match('/^#[0-9a-fA-F]{6}$/D', $enterprisePresentation['branding']['accent'] ?? ''))
<style>:root{--gold:{{ $enterprisePresentation['branding']['accent'] }};--ew-accent:{{ $enterprisePresentation['branding']['accent'] }}}</style>
@endif
@includeIf('enterprise.enterprise-campus-theme')
</head>
<body>
@includeIf('enterprise.runtime-designation')
<a class="ent-skip" href="#enterprise-main">Skip to workspace</a><div class="shell">
<aside class="sidebar" aria-label="Everbranch Enterprise"><div class="brand enterprise-brand"><picture><source type="image/svg+xml" srcset="data:image/svg+xml;base64,{{ base64_encode(file_get_contents(resource_path('images/enterprise/Everbranch-Rooted-Peak-Aligned.svg'))) }}"><img class="enterprise-brand-image" src="data:image/png;base64,{{ base64_encode(file_get_contents(resource_path('images/enterprise/Everbranch-Rooted-Peak-Aligned.png'))) }}" width="2800" height="747" alt="Everbranch Enterprise by Evergrove Software"></picture></div>
<p class="workspace-label">Work</p><nav aria-label="Workspace sections"><button class="nav-item" data-section="home" id="home-nav" hidden>Campus overview</button><button class="nav-item active" data-section="requests">Requests</button><button class="nav-item" data-section="projects">Operations &amp; projects</button><button class="nav-item" data-section="contractors">Contractors</button><button class="nav-item" data-section="ask" id="ask-nav" hidden>Ask Everbranch</button>@includeIf('enterprise.operations-nav')<button class="nav-item" data-section="usage" id="usage-nav" hidden>AI &amp; voice usage</button></nav>
<p class="workspace-label">Your workspaces</p><div id="areas"></div><div class="sidebar-bottom"><strong>{{ $enterprisePresentation["identity"]["name"] }}</strong><br>{{ $enterprisePresentation["identity"]["userName"] }}<br>Connected work. Clear ownership.@unless($enterpriseSample)<form id="enterprise-logout" method="post" action="{{ $enterprisePresentation['routes']['logout'] }}"><input type="hidden" name="_token" value="{{ $enterprisePresentation['csrf'] ?? '' }}"><button class="nav-item" type="submit">Sign out</button></form>@endunless</div></aside>
<main class="main" id="enterprise-main"><header class="topbar"><div class="breadcrumb">{{ $enterprisePresentation["identity"]["name"] }} <span>/</span> <strong id="breadcrumb">Requests</strong></div><span class="badge" id="visibility">Loading workspace</span></header>
<div class="content"><div class="eyebrow">Everbranch Enterprise</div><div class="page-heading"><div><h1 id="heading">Turn requests into owned work.</h1><p class="subtle">One place for intake, assignment and visible progress.</p></div><div class="actions"><button class="button" id="create-workspace" hidden>New workspace</button><button class="button primary" id="create-request" hidden>New request</button><button class="button primary" id="create-project" hidden>New project</button></div></div>
<div id="work-feedback-home"><p id="status" role="status" aria-live="polite"></p><button class="button" id="retry" hidden>Retry the same action</button></div>
@include('enterprise.home')
@includeIf('enterprise.executive-demo')
@include('enterprise.next-panels')
@includeIf('enterprise.operations-panels')
@includeIf('enterprise.procedures-workspace')
@includeIf('enterprise.finance-workspace')
@includeIf('enterprise.role-preview')
@include('enterprise.partials.usage-overview')
<div id="enterprise-usage-tools" class="actions" hidden><button class="button" type="button" id="usage-all" hidden>All authorized departments</button><button class="button" type="button" id="usage-refresh">Refresh usage records</button></div>
<div id="work"><div class="toolbar"><div class="actions" role="group" aria-label="Work view"><button class="button" data-view="list" aria-pressed="true">List</button><button class="button" data-view="calendar" aria-pressed="false">Calendar</button><button class="button" data-view="map" aria-pressed="false">Map</button></div><label>Find work <input id="search" type="search" placeholder="Search visible work"></label><button class="button" id="map-toggle">View Map</button></div>
<section class="panel map-panel" id="map" hidden><div class="map-head"><div><div class="eyebrow">Workspace locations</div><h2>See where the work happens.</h2></div><span class="caption">Select a marker for request details</span></div><div id="map-holder"><svg class="location-map" viewBox="0 0 1000 330" preserveAspectRatio="none" aria-hidden="true"><defs><pattern id="grid" width="100" height="66" patternUnits="userSpaceOnUse"><path d="M100 0H0V66" fill="none" stroke="#d9e2ce"/></pattern></defs><rect width="1000" height="330" fill="url(#grid)"/></svg><div id="map-markers"></div><p class="caption">Saved longitude/latitude positions on a coordinate grid. No campus basemap, GPS feed or external map provider is connected.</p></div><p class="caption" id="map-empty"></p></section>
<section id="request-list" aria-label="Visible workspace work"></section><p class="caption" id="coverage"></p></div>
<dialog class="work-drawer" id="work-drawer" aria-labelledby="work-detail-title"><header class="work-drawer-head"><h2 id="work-detail-title">Work details</h2><button class="button" id="close-work-detail" type="button" autofocus>Close</button></header><div id="work-drawer-feedback"></div><div id="detail" aria-label="Selected work details"></div></dialog>
</div></main></div>
@if($enterpriseSample)@includeIf('enterprise.reviewer-tour')@elseif(config('enterprise.runtime') === true && config('enterprise.tour_enabled', false) && config('enterprise.sample_data', false))@include('enterprise.workspace-tour')@endif
<dialog id="create-dialog"><form id="create-form" class="stack"><h2 id="dialog-title">New request</h2><label>Title<input name="title" maxlength="100" required></label><label id="visibility-field" hidden>Visibility<select name="visibility"><option value="department_only">Department only</option><option value="shared">Shared across organization</option></select></label><div id="request-fields" class="stack"><label>Work description<textarea name="description" maxlength="5000" rows="4"></textarea></label><label>Due date<input name="due_on" type="date"></label><label>Saved building (optional)<select name="location_id" id="request-building" aria-label="Saved building (optional)"><option value="">No saved building link</option></select></label><p class="caption" id="request-building-coverage"></p><label>Location label<input name="location" maxlength="200"></label><div class="field-pair"><label>Latitude (optional)<input name="latitude" type="number" min="-90" max="90" step="any"></label><label>Longitude (optional)<input name="longitude" type="number" min="-180" max="180" step="any"></label></div></div><p class="caption" id="create-context"></p><div class="actions"><button class="button primary" type="submit">Save</button><button class="button" type="button" id="cancel-create">Cancel</button></div></form></dialog>
<script>{!! file_get_contents(resource_path('js/enterprise/transport.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/usage.js')) !!}</script>
@if($enterpriseSample)
@includeIf('enterprise.reviewer-adapter')
@endif
<script>
(() => {
  'use strict';

  const presentation = @json($enterprisePresentation),
    baseTransport = EverbranchTransport.create(presentation),
    transport = Object.freeze({...baseTransport, replace: rememberNavigation}),
    urls = presentation.routes,
    tenantSlug = presentation.identity.tenantSlug,
    viewer = presentation.identity.viewer,
    workspace = presentation.identity.workspace;
  const allowedSections = presentation.sections || ['home', 'projects', 'contractors', 'ask', 'requests', 'roll_call', 'buildings', 'calendar', 'people', 'assets', 'workflows', 'requester', 'finance', 'usage'];
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
    view = ['list', 'calendar', 'map'].includes(navigation.get('view')) ? navigation.get('view') : 'list',
    map = navigation.get('map') === '1' || (!navigation.has('map') && view === 'map'),
    pending = null,
    pendingUncertain = false,
    createAction = '',
    draft = null,
    busy = false;
  const workSortState = new Map(), workFeedbackHome = new Map();
  let workDetailReturn = null;
  let navigationPushPending = false, navigationSearchBatch = false,
    navigationViewOverride = navigation.has('view') ? {view, map} : null;
  $('search').value = (navigation.get('q') || '').slice(0, 200);
  function message(text, error = false) {
    $('status').textContent = text;
    $('status').classList.toggle('notice-error', error);
  }
  const api = (url, body, options) => transport.request(url, body, options);
  let workspaceLoadGeneration = 0, workspaceLoaded = false;
  function clearWorkspaceContext(failed = false) {
    closeWorkDetail({clearSelection:false, restoreFocus:false});
    stashComposer();
    data = {areas: [], requests: [], projects: [], members: []};
    document.dispatchEvent(new CustomEvent('enterprise:workspace-state', {detail: {loaded: false, area: null}}));
    // Clear the tour synchronously, independent of optional panel reset hooks.
    document.dispatchEvent(new CustomEvent('enterprise:tour-context', {detail: null}));
    $('request-list').replaceChildren();
    $('detail').replaceChildren();
    $('map-markers').replaceChildren();
    if (failed) $('areas').replaceChildren();
    for (const id of ['create-workspace', 'create-request', 'create-project', 'campus-home', 'resource-workspace']) if ($(id)) $(id).hidden = true;
    homeGeneration++;
    homeArea = null;
    $('briefing-body').replaceChildren();
    resetNext();
  }
  async function load() {
    const generation = ++workspaceLoadGeneration, requestedArea = area;
    clearWorkspaceContext();
    message('Checking current workspace permissions.');
    const current = () => generation === workspaceLoadGeneration && area === requestedArea;
    let accepted = false;
    try {
      const result = await api(urls.data + (requestedArea ? '&area_id=' + encodeURIComponent(requestedArea) : ''));
      if (!current()) return;
      accepted = true;
      if (!workspaceLoaded && !navigation.has('section') && result.features?.briefing) section = 'home';
      data = result;
      area = data.selected_area_id;
      workspaceLoaded = true;
      message('');
      render();
    } catch (error) {
      if (generation !== workspaceLoadGeneration || (!accepted && area !== requestedArea)) return;
      clearWorkspaceContext(true);
      message(error.message, true);
    }
  }
  async function command(body) {
    if (busy) return;
    busy = true;
    pending = body;
    globalThis.EverbranchTransport.setRefreshBlocker('workspace-command', true);
    $('retry').hidden = true;
    message('Saving…');
    try {
      const result = await api(urls.commands, body);
      if(body.note!==undefined && body.id)writeDraft('work-action:'+body.area_id+':requests:'+body.id,{});
      pending = null;
      pendingUncertain = false;
      globalThis.EverbranchTransport.setRefreshBlocker('workspace-command', false);
      $('create-dialog').close();
      if (body.action === 'workspace.create') area = result.id;
      await load();
      message('Saved.');
    } catch (error) {
      if (!pendingUncertain && [403,404,409,422].includes(error.status)) {
        pending = null;
        globalThis.EverbranchTransport.setRefreshBlocker('workspace-command', false);
        message(error.message + ' The server rejected this action. Review current access and correct or reload the form before trying again.', true);
      } else {
        pendingUncertain = true;
        message(error.message + ' Your action is retained in this page for exact retry. Refresh is paused until its outcome is confirmed.', true);
        $('retry').hidden = false;
      }
    } finally {
      busy = false;
    }
  }
  function workColumns() {
    const source = section === 'projects' ? data.projects : data.requests;
    return [
      ['title', section === 'projects' ? 'Project' : 'Request / task'],
      ...(source.some(r => r.task && 'priority' in r.task) ? [['priority','Priority']] : []),
      ['status','Status'],
      ...(source.some(r => r.task && 'assignee_id' in r.task) ? [['assignee','Assignee']] : []),
      ...(source.some(r => r.data?.location) ? [['location','Building / location']] : []),
      ...(source.some(r => r.due_on || r.task?.due_on) ? [['due','Due']] : []),
    ];
  }
  function workValue(row, key) {
    if(key==='title')return row.title || row.name || '';
    if(key==='status')return row.status || (row.archived ? 'Archived' : 'Active');
    if(key==='priority')return ['urgent','high','normal','low'].includes(row.task?.priority) ? row.task.priority : null;
    if(key==='assignee')return row.task?.assignee_id ? (data.members.find(m => String(m.id)===String(row.task.assignee_id))?.name || 'Assigned member · name unavailable') : null;
    if(key==='location')return row.data?.location || null;
    if(key==='due')return row.due_on || row.task?.due_on || null;
    return null;
  }
  function workSort() {
    const stored=workSortState.get(area+':'+section);
    return stored && workColumns().some(([key])=>key===stored.key) ? stored : {key:'title',direction:'asc'};
  }
  function sortedWork(work) {
    const sort=workSort(), priority={urgent:0,high:1,normal:2,low:3};
    return [...work].sort((a,b)=>{
      const av=workValue(a,sort.key),bv=workValue(b,sort.key);
      if(av===null || bv===null)return av===bv ? 0 : av===null ? 1 : -1;
      const order=sort.key==='priority' ? priority[av]-priority[bv] : String(av).localeCompare(String(bv),undefined,{numeric:true,sensitivity:'base'});
      return order*(sort.direction==='asc'?1:-1) || String(workValue(a,'title')).localeCompare(String(workValue(b,'title')));
    });
  }
  function workCell(row,key) {
    const value=workValue(row,key);
    if(key==='title')return `<button class="link" data-open="${esc(row.id)}" aria-haspopup="dialog" aria-controls="work-drawer" aria-expanded="${row.id===selected}">${esc(value)}</button>`;
    if(key==='status')return `<span class="badge">${esc(String(value).replaceAll('_',' '))}</span>`;
    if(key==='priority')return value ? `<span class="work-priority" data-priority="${esc(value)}">${esc(value)}</span>` : 'Not recorded';
    if(key==='assignee' && !value)return row.task ? 'Unassigned' : row.data?.task_id ? 'Task unavailable' : 'No linked task';
    return esc(value || (key==='due'?'Unscheduled':'Not recorded'));
  }
  function syncWorkSelection() {
    $('request-list').querySelectorAll('[data-work-row]').forEach(row=>row.dataset.selected=String(row.dataset.workRow===selected));
    $('request-list').querySelectorAll('[data-open]').forEach(button=>button.setAttribute('aria-expanded',String(button.dataset.open===selected)));
  }
  function openWorkRow(id, trigger) {
    rememberSelection();beginNavigation();
    const table=$('work-table-scroll');
    workDetailReturn={trigger,id,top:scrollY,left:scrollX,tableTop:table?.scrollTop||0,tableLeft:table?.scrollLeft||0};
    selected=id;detail();syncWorkSelection();
  }
  function moveWorkFeedback(intoDrawer) {
    for(const id of ['status','retry','operations-retry']){
      const node=$(id);if(!node?.parentNode)continue;
      if(intoDrawer){
        if(!workFeedbackHome.has(id))workFeedbackHome.set(id,{parent:node.parentNode,next:node.nextSibling});
        $('work-drawer-feedback').append(node);
      }else{
        const home=workFeedbackHome.get(id);if(!home)continue;
        const parent=home.parent.isConnected?home.parent:$('work-feedback-home');
        if(home.next?.parentNode===parent)parent.insertBefore(node,home.next);else parent.append(node);
        workFeedbackHome.delete(id);
      }
    }
  }
  function showWorkDetail() {
    const drawer=$('work-drawer');
    $('work-detail-title').textContent=section==='projects'?'Project details':'Request details';
    if(!drawer.open){
      const table=$('work-table-scroll');
      workDetailReturn ||= {trigger:document.activeElement,id:selected,top:scrollY,left:scrollX,tableTop:table?.scrollTop||0,tableLeft:table?.scrollLeft||0};
      moveWorkFeedback(true);document.documentElement.classList.add('work-detail-open');drawer.showModal();
    }
  }
  function closeWorkDetail({clearSelection=true,restoreFocus=true}={}) {
    const drawer=$('work-drawer'),wasOpen=drawer.open,back=workDetailReturn;
    stashComposer();discussionGeneration++;activeDiscussionKey=null;
    moveWorkFeedback(false);if(wasOpen)drawer.close();
    $('detail').replaceChildren();
    document.documentElement.classList.remove('work-detail-open');workDetailReturn=null;
    if(clearSelection){selected=null;rememberSelection();rememberUrl();syncWorkSelection();
      document.dispatchEvent(new CustomEvent('enterprise:tour-context',{detail:{data,area,section,selected,transport}}));}
    if(wasOpen&&restoreFocus){
      const table=$('work-table-scroll');if(table&&back){table.scrollTop=back.tableTop;table.scrollLeft=back.tableLeft;}
      const target=back?.trigger?.isConnected?back.trigger:[...$('request-list').querySelectorAll('[data-open]')].find(button=>button.dataset.open===back?.id)||$('search');target.focus({preventScroll:true});
      if(back)scrollTo({top:back.top,left:back.left,behavior:'instant'});
    }
  }
  $('close-work-detail').onclick=()=>closeWorkDetail();
  $('work-drawer').addEventListener('cancel',event=>{event.preventDefault();closeWorkDetail();});
  $('work-drawer').addEventListener('click',event=>{
    if(event.target!==$('work-drawer'))return;const bounds=event.target.getBoundingClientRect();
    if(event.clientX<bounds.left||event.clientX>bounds.right||event.clientY<bounds.top||event.clientY>bounds.bottom)closeWorkDetail();
  });
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
    if(!['requests','projects'].includes(section))closeWorkDetail({clearSelection:false,restoreFocus:false});
    if(transport.mode==='sample')$('enterprise-main').dataset.demoPage='enterprise-'+section;
    if(transport.mode==='sample')document.dispatchEvent(new CustomEvent('enterprise:section-rendered',{detail:{section}}));
    // An explicit unsaved URL/history view takes precedence over an async saved default.
    if (preserveDetail && navigationViewOverride) ({view, map} = navigationViewOverride);
    $('areas').innerHTML = data.areas.map(a => `<button class="sidebar-project ${a.id === area ? 'active' : ''}" data-area="${esc(a.id)}"><span class="dot"></span>${esc(a.name)}</button>`).join('');
    $('areas').querySelectorAll('[data-area]').forEach(b => b.onclick = () => {
      rememberSelection();
      beginNavigation();
      area = b.dataset.area;
      selected = selectionMemory.get(area + ':' + section) || null;
      rememberUrl();
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
    const work = sortedWork(rows());
    if (view === 'calendar') {
      const days = [...new Set(work.map(r => r.due_on || 'Unscheduled'))].sort();
      $('request-list').innerHTML = `<div class="panel">${days.map(day => `<section class="calendar-day"><h3>${esc(day)}</h3>${work.filter(r => (r.due_on || 'Unscheduled') === day).map(r => `<button class="button request-row" data-open="${esc(r.id)}">${esc(r.title || r.name)}</button>`).join('')}</section>`).join('') || '<p class="empty">No scheduled work in this workspace.</p>'}</div>`;
    } else {
      const columns=workColumns(),sort=workSort();
      $('request-list').innerHTML = `<div class="table-wrap work-table-scroll" id="work-table-scroll" role="region" tabindex="0" aria-label="Work table; scroll for more columns"><table class="work-table"><caption>${section==='projects'?'Projects':'Requests and linked tasks'} · ${work.length} in this view</caption><thead><tr>${columns.map(([key,label])=>`<th scope="col" aria-sort="${sort.key===key?(sort.direction==='asc'?'ascending':'descending'):'none'}"><button type="button" data-work-sort="${key}" aria-label="Sort by ${label}">${label}<span aria-hidden="true">${sort.key===key?(sort.direction==='asc'?' ↑':' ↓'):' ↕'}</span></button></th>`).join('')}</tr></thead><tbody>${work.map(r=>`<tr data-work-row="${esc(r.id)}" data-selected="${r.id===selected}">${columns.map(([key])=>`<td data-column="${key}">${workCell(r,key)}</td>`).join('')}</tr>`).join('')}</tbody></table>${work.length ? '' : '<p class="empty">No matching work. Clear search or choose another workspace.</p>'}</div>`;
      const scrollRegion=$('work-table-scroll');
      scrollRegion.onkeydown=event=>{
        if(event.target!==scrollRegion||event.altKey||event.ctrlKey||event.metaKey)return;
        const delta={ArrowLeft:[-80,0],ArrowRight:[80,0],ArrowUp:[0,-64],ArrowDown:[0,64]}[event.key];
        if(delta){event.preventDefault();scrollRegion.scrollBy({left:delta[0],top:delta[1],behavior:'instant'});}
      };
      $('request-list').querySelectorAll('[data-work-sort]').forEach(button=>button.onclick=()=>{
        const key=button.dataset.workSort,current=workSort();
        workSortState.set(area+':'+section,{key,direction:current.key===key&&current.direction==='asc'?'desc':'asc'});
        render({preserveDetail:true});
        [...$('request-list').querySelectorAll('[data-work-sort]')].find(b=>b.dataset.workSort===key)?.focus({preventScroll:true});
      });
      $('request-list').querySelectorAll('[data-work-row]').forEach(row=>row.onclick=event=>{
        if(event.target.closest('button,a,input,select,textarea'))return;
        openWorkRow(row.dataset.workRow,row.querySelector('[data-open]'));
      });
    }
    $('request-list').querySelectorAll('[data-open]').forEach(b => b.onclick = () => {
      openWorkRow(b.dataset.open,b);
    });
    const located = work.filter(r => r.data?.latitude != null && r.data?.longitude != null && Number.isFinite(Number(r.data.latitude)) && Number.isFinite(Number(r.data.longitude)));
    const lats = located.map(r => Number(r.data.latitude)),
      lons = located.map(r => Number(r.data.longitude));
    const minX = Math.min(...lons),
      maxX = Math.max(...lons),
      minY = Math.min(...lats),
      maxY = Math.max(...lats);
    $('map-markers').innerHTML = located.map((r, i) => `<button class="map-pin" style="left:${maxX === minX ? 50 : 10 + 80 * (Number(r.data.longitude) - minX) / (maxX - minX)}%;top:${maxY === minY ? 50 : 10 + 80 * (maxY - Number(r.data.latitude)) / (maxY - minY)}%" data-map="${esc(r.id)}" aria-label="Open ${esc(r.title || r.name)}">${i + 1}</button>`).join('');
    $('map-markers').querySelectorAll('[data-map]').forEach(b => b.onclick = () => {
      openWorkRow(b.dataset.map,b);
    });
    $('map-empty').textContent = located.length ? '' : 'No saved coordinates match the current view and filters.';
    $('coverage').textContent = section==='projects'?'Showing readable projects returned for this workspace.':'Showing up to 100 currently visible requests. Priority and assignee come from their linked task. Saved building links are explicit; other location labels are entered text.' + (data.has_more ? ' More visible requests exist.' : '') + (data.scan_partial ? ' The scan limit was reached; results are partial.' : '');
    if(!preserveDetail)detail();
    document.dispatchEvent(new CustomEvent('enterprise:render', {detail: {data, area, section, selected, transport}}));
    document.dispatchEvent(new CustomEvent('enterprise:workspace-state', {detail: {area, section, features: data.features || {}, loaded: workspaceLoaded, viewer_id: data.viewer_id}}));
  }
  function detail() {
    stashComposer();
    discussionGeneration++;
    activeDiscussionKey = null;
    rememberUrl();
    document.dispatchEvent(new CustomEvent('enterprise:tour-context', {detail: {data, area, section, selected, transport}}));
    if (!['projects','requests'].includes(section)) return;
    const r = (section === 'projects' ? data.projects : data.requests).find(r => r.id === selected);
    if (!r) {
      closeWorkDetail({clearSelection:false,restoreFocus:false});
      $('detail').replaceChildren();
      return;
    }
    showWorkDetail();
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
    const actionNote=$('action-note'),actionDraftKey='work-action:'+area+':'+section+':'+r.id;
    if(actionNote){actionNote.value=readDraft(actionDraftKey).body||'';actionNote.oninput=()=>writeDraft(actionDraftKey,{body:actionNote.value});}
    $('show-history').onclick = async () => {
      const scope={area,section,selected,generation:discussionGeneration};
      const current=()=>area===scope.area&&section===scope.section&&selected===scope.selected&&discussionGeneration===scope.generation&&$('work-drawer').open;
      try {
        const result = await api(urls.history + '/' + encodeURIComponent(r.id) + '/history?tenant=' + encodeURIComponent(tenantSlug));
        if(!current())return;
        $('history').innerHTML = result.items.map(x => `<p class="caption">Version ${x.version} · ${esc(x.event.action)}<br>${esc(x.at)} · actor ${esc(x.actor_id)}</p>`).join('');
      } catch (e) {
        if(current())message(e.message, true);
      }
    };
    mountDiscussion('request', r.id);
    if (data.features?.operations) mountRequestOperations(r);
  }
  @include('enterprise.home-script')
  let requestBuildingChoices=[];
  function create(action) {
    if (pending) return message('Retry the retained action before starting another.', true);
    createAction = action;
    draft = {
      id: crypto.randomUUID(),
      request_id: crypto.randomUUID()
    };
    $('create-form').reset();
    const choices=requestBuildingChoices=transport.mode==='private'?(data.buildings?.items||[]).map(row=>({...row})):[];
    $('request-building').innerHTML='<option value="">No saved building link</option>'+choices.map(row=>`<option value="${esc(row.id)}">${esc(row.title)}</option>`).join('');
    $('request-building').disabled=action!=='request.create'||!choices.length;
    $('request-building-coverage').textContent=transport.mode==='sample'?'Fictional sample: requests keep location text in this tab. Saved building links use the signed-in workspace.':(data.buildings?.coverage||'No current saved buildings are available.')+(data.buildings?.has_more?' More buildings are outside this result.':'')+' Choose a building to link this request; a typed label alone stays unverified.';
    syncRequestBuilding();
    $('dialog-title').textContent = action === 'workspace.create' ? 'New workspace' : action === 'project.create' ? 'New project' : 'New request';
    $('visibility-field').hidden = action !== 'workspace.create';
    $('request-fields').hidden = action !== 'request.create';
    $('create-context').textContent = action === 'workspace.create' ? 'Creating a workspace adds no users or access grants. Department access must already be authorized.' : 'Saved within the selected workspace and its visibility boundary.';
    $('create-dialog').showModal();
  }
  function syncRequestBuilding(){
    const form=$('create-form'),building=requestBuildingChoices.find(row=>row.id===$('request-building').value);
    form.elements.location.readOnly=!!building;if(building)form.elements.location.value=building.title;
    for(const key of ['latitude','longitude']){form.elements[key].readOnly=!!building;if(building)form.elements[key].value='';}
  }
  $('request-building').onchange=syncRequestBuilding;
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
      const building=requestBuildingChoices.find(row=>row.id===fields.get('location_id'));
      if(fields.get('location_id')&&!building)return message('That building is unavailable. Close the form and refresh before saving.',true);
      if(building){body.location_id=building.id;body.location_version=building.version;delete body.latitude;delete body.longitude;}
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
  // Workspace history stores navigation only. Record drafts keep their existing scoped storage.
  function beginNavigation(kind = 'change') {
    navigationPushPending = kind !== 'search' || !navigationSearchBatch;
    navigationSearchBatch = kind === 'search';
  }
  function rememberNavigation(value) {
    let target;
    try {
      // Retain the sample adapter's canonical same-origin destination validation.
      const requested = new URL(String(value), location.href);
      if(transport.mode==='sample' && selected===null){
        const state=new URLSearchParams(requested.hash.slice(1));state.delete('selected');requested.hash=state.toString();
      }
      target = new URL(baseTransport.href(String(requested)), location.href);
    } catch { return; }
    if (target.origin !== location.origin || target.pathname !== location.pathname || target.username || target.password) return;
    const parameters = new URLSearchParams(transport.mode === 'sample' ? target.hash.slice(1) : target.search);
    // These fixed presentation fields are intentionally not adapter authority or record data.
    parameters.set('view', ['list', 'calendar', 'map'].includes(view) ? view : 'list');
    parameters.set('map', map ? '1' : '0');
    const query = $('search').value.slice(0, 200);
    query ? parameters.set('q', query) : parameters.delete('q');
    if (transport.mode === 'sample') { target.search = ''; target.hash = parameters.toString(); }
    else { parameters.set('tenant', tenantSlug); target.search = parameters.toString(); }
    if (target.href !== location.href) {
      const method = navigationPushPending ? 'pushState' : 'replaceState';
      history[method](history.state, '', target.href);
    }
    navigationPushPending = false;
  }
  function restoreNavigation() {
    const incoming = new URLSearchParams(transport.mode === 'sample' ? location.hash.slice(1) : location.search);
    rememberSelection();
    navigationPushPending = false;
    navigationSearchBatch = false;
    area = incoming.get('area_id');
    section = allowedSections.includes(incoming.get('section')) ? incoming.get('section') : 'requests';
    selected = incoming.get('selected');
    view = ['list', 'calendar', 'map'].includes(incoming.get('view')) ? incoming.get('view') : 'list';
    map = incoming.get('map') === '1' || (!incoming.has('map') && view === 'map');
    navigationViewOverride = {view, map};
    $('search').value = (incoming.get('q') || '').slice(0, 200);
    incoming.has('section') ? navigation.set('section', section) : navigation.delete('section');
    // The destination must pass a fresh authorized projection; never restore record payloads from history.
    resetNext();
    load();
  }
  globalThis.addEventListener('popstate', restoreNavigation);
  // A restored browser document must not revive cached operational facts or active voice.
  globalThis.addEventListener('pagehide',()=>clearWorkspaceContext(true));
  globalThis.addEventListener('pageshow',event=>{if(event.persisted)restoreNavigation();});
  $('search').oninput = () => {
    $('search').value = $('search').value.slice(0, 200);
    beginNavigation('search');
    render();
  };
  $('search').onblur = () => { navigationSearchBatch = false; };
  $('map-toggle').onclick = () => {
    beginNavigation();
    map = !map;
    navigationViewOverride = {view, map};
    render();
  };
  document.querySelectorAll('[data-view]').forEach(b => b.onclick = () => {
    beginNavigation();
    view = b.dataset.view;
    if (view === 'map') map = true;
    navigationViewOverride = {view, map};
    render();
  });
  document.querySelectorAll('[data-section]').forEach(b => b.onclick = () => {
    rememberSelection();
    beginNavigation();
    message('');
    section = b.dataset.section;
    selected = selectionMemory.get(area + ':' + section) || null;
    document.querySelectorAll('[data-section]').forEach(x => x.classList.toggle('active', x === b));
    render();
  });
  @include('enterprise.next-script')
  @includeIf('enterprise.operations-script')
  @includeIf('enterprise.procedures-script')
  @includeIf('enterprise.finance-script')
  @include('enterprise.voice-script')
  @include('enterprise.usage-script')
  document.addEventListener('enterprise:navigate', event => {
    const next = event.detail || {};
    if (next.section && !allowedSections.includes(next.section)) return;
    rememberSelection();
    beginNavigation();
    const previousArea = area, previousSection = section, previousSelected = selected;
    if (next.area !== undefined) area = next.area;
    if (next.section) section = next.section;
    if(['list','calendar','map'].includes(next.view)){view=next.view;map=next.map===true||view==='map';navigationViewOverride={view,map};}
    selected = next.selected !== undefined ? next.selected : selectionMemory.get(area + ':' + section) || (area === previousArea && section === previousSection ? previousSelected : null);
    rememberUrl();
    if (next.area !== undefined) {resetNext(); load();} else render();
  });
  load();
})();
</script>
@include('enterprise.app-install')
@if($enterpriseSample)
@if(!empty($voiceAssets))<script type="module">{!! $voiceAssets !!}</script>@endif
@elseif(config('enterprise.voice_jobs_enabled') && config('enterprise.ask_enabled'))
@vite('resources/js/enterprise/voice-jobs-entry.js')
@endif
</body></html>
