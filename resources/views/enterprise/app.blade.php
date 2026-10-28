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
@include('enterprise.campus-shell-styles')
<style>.shell{min-height:100vh}.sidebar{height:100vh}.detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}.work-detail{white-space:pre-wrap;overflow-wrap:anywhere}.map-panel{padding:0;overflow:hidden;margin-bottom:22px}.map-head{padding:18px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.map-head h2{font:26px Georgia,serif;margin:5px 0}.location-map{display:block;width:100%;height:330px;background:#edf2e7}.location-map button{cursor:pointer}.request-row{width:100%;text-align:left}.stack{display:grid;gap:12px}.stack label{display:grid;gap:6px;font-size:12px}.ent-skip{position:absolute;top:-80px;background:white;padding:15px;z-index:10}.ent-skip:focus{top:0}[hidden]{display:none!important}.notice-error{color:#913c31}.calendar-day{padding:15px;border-bottom:1px solid var(--line)}.calendar-day h3{font-size:12px}.field-pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}#detail h2{font:25px Georgia,serif}#detail h3{font-size:12px}#map-holder{position:relative}#map-markers{position:absolute;inset:30px 20px 42px}.map-pin{position:absolute;transform:translate(-50%,-50%);padding:5px 8px;border:2px solid white;background:#315b43;color:white;border-radius:14px;font-size:11px;box-shadow:0 2px 5px #2343}#map-holder p{margin:8px 20px 16px}.sidebar-project.active{background:#ffffff12;border-radius:5px}.panel{min-width:0}pre{white-space:pre-wrap;overflow-wrap:anywhere}#request-list{min-width:0}.nav-item{min-height:44px}@media(max-width:1000px){.detail-layout{grid-template-columns:1fr}.sidebar{height:auto;min-height:0;position:static}.shell{grid-template-columns:190px 1fr}.content{padding:25px 18px}.topbar{padding:15px 18px}}@media(max-width:650px){.shell{grid-template-columns:1fr}.sidebar{padding:15px}.brand{padding-bottom:12px}.workspace-label{margin-top:12px}.sidebar nav{display:flex;flex-wrap:wrap}.nav-item{width:auto}.sidebar-bottom{display:none}.topbar{height:auto}.field-pair{grid-template-columns:1fr}.page-heading{align-items:flex-start;flex-direction:column}.page-heading h1{font-size:30px}.toolbar{flex-wrap:wrap}.sidebar-project{width:auto;display:inline-flex}}#work{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}#work>.toolbar,#work>#coverage{grid-column:1/-1}#work>#map{grid-column:1}.detail-layout{display:contents}#request-list{grid-column:1}#detail{grid-column:2;grid-row:2 / span 2;align-self:start}@media(max-width:1000px){#work{display:block}.detail-layout{display:grid;grid-template-columns:1fr}#request-list,#detail{grid-column:auto;grid-row:auto}#work>.toolbar{margin-bottom:20px}}@media(max-width:800px){.sidebar .workspace-label{display:block}.sidebar .sidebar-project{display:inline-flex;width:auto}}
/* Scoped to the four structured request controls; native choice/keyboard semantics remain intact. */
.request-select{display:block;position:relative;min-width:0;color-scheme:light;color:var(--ink)}
.request-select select{-webkit-appearance:none;appearance:none;display:block;width:100%;min-height:44px;padding:10px 36px 10px 11px;color-scheme:light;background:#fff;color:inherit}
.request-select::after{content:"";position:absolute;pointer-events:none;right:15px;top:50%;width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-70%) rotate(45deg)}
@media(forced-colors:active){.request-select{color-scheme:light dark;color:CanvasText}.request-select select{color-scheme:light dark;background:Canvas;color:CanvasText;border-color:ButtonText}.request-select:has(select:disabled){color:GrayText}.request-select select:disabled{color:GrayText;border-color:GrayText}}
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
<button class="enterprise-navigation-toggle" id="enterprise-navigation-toggle" type="button" aria-controls="enterprise-mobile-navigation" aria-expanded="false"><span aria-hidden="true">☰</span> Menu</button><div id="enterprise-mobile-navigation" class="enterprise-nav-links">
<p class="workspace-label">Work</p><nav aria-label="Workspace sections"><button class="nav-item" data-section="home" id="home-nav" hidden>Campus overview</button><button class="nav-item active" data-section="requests">Requests</button><button class="nav-item" data-section="projects">Projects</button><button class="nav-item" data-section="contractors">Contractors</button><button class="nav-item" data-section="ask" id="ask-nav" hidden>Ask Everbranch</button>@includeIf('enterprise.operations-nav')<button class="nav-item" data-section="usage" id="usage-nav" hidden>AI &amp; voice usage</button></nav>
<p class="workspace-label">Your workspaces</p><div id="areas"></div><div class="sidebar-bottom"><strong>{{ $enterprisePresentation["identity"]["name"] }}</strong><br>{{ $enterprisePresentation["identity"]["userName"] }}<br>Connected work. Clear ownership.@unless($enterpriseSample)<form id="enterprise-logout" method="post" action="{{ $enterprisePresentation['routes']['logout'] }}"><input type="hidden" name="_token" value="{{ $enterprisePresentation['csrf'] ?? '' }}"><button class="nav-item" type="submit">Sign out</button></form>@endunless</div><div class="enterprise-mobile-actions" data-mobile-nav-actions></div></div></aside>
<main class="main" id="enterprise-main"><header class="topbar"><div class="breadcrumb">{{ $enterprisePresentation["identity"]["name"] }} <span>/</span> <strong id="breadcrumb">Requests</strong></div><section class="campus-search campus-header-search" id="campus-search" aria-label="Campus search"><form id="campus-search-form"><label for="campus-search-query">Search campus<input type="search" id="campus-search-query" maxlength="150" required placeholder="Buildings, work and people" autocomplete="off" aria-controls="campus-search-results-panel" aria-expanded="false"></label><button class="button" type="submit">Search campus</button></form><section id="campus-search-results-panel" hidden aria-label="Campus search results"><div class="campus-search-result-header"><h2>Search results</h2><button class="button" type="button" id="campus-search-close">Close search</button></div><p class="caption" id="campus-search-coverage" role="status"></p><div class="campus-results" id="campus-search-results"></div></section></section><span class="badge" id="visibility">Loading workspace</span></header>
<div class="content"><div class="page-heading work-context-heading"><h1 id="heading">Incoming tickets</h1><div class="actions"><button class="button" id="create-workspace" hidden>New workspace</button><button class="button primary" id="create-request" hidden>New ticket</button><button class="button primary" id="create-project" hidden>New job</button><button class="button" id="workspace-settings-open" type="button" hidden>Settings</button></div></div>
<div id="work-feedback-home"><p id="status" role="status" aria-live="polite"></p><button class="button" id="retry" hidden>Retry the same action</button></div>
@include('enterprise.work-management-panels')
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
<section class="panel map-panel" id="map" hidden><div class="map-head"><div><h2>Campus map</h2></div><span class="caption">Choose a building to see jobs</span></div><div id="map-holder"><svg class="location-map" viewBox="0 0 1000 330" preserveAspectRatio="none" aria-hidden="true"><defs><pattern id="grid" width="100" height="66" patternUnits="userSpaceOnUse"><path d="M100 0H0V66" fill="none" stroke="#d9e2ce"/></pattern></defs><rect width="1000" height="330" fill="url(#grid)"/></svg><div id="map-markers"></div><p class="caption">Saved longitude/latitude positions on a coordinate grid. No campus basemap, GPS feed or external map provider is connected.</p></div><p class="caption" id="map-empty"></p></section>
<div id="work-filters" class="work-filters"><label id="work-department-field">Department<span class="request-select"><select id="work-department"></select></span></label><label>Assigned person<span class="request-select"><select id="work-person"></select></span></label><label>Status<span class="request-select"><select id="work-status"></select></span></label><button class="button" id="create-project-only" type="button" hidden>Create project</button></div>
<section id="request-list" aria-label="Visible workspace work"></section><p class="caption" id="coverage"></p></div>
@include('enterprise.work-detail-panel')
@include('enterprise.work-views-styles')
@include('enterprise.campus-shell-panels')
</div></main></div>
@if($enterpriseSample)@includeIf('enterprise.reviewer-tour')@elseif(config('enterprise.runtime') === true && config('enterprise.tour_enabled', false) && config('enterprise.sample_data', false))@include('enterprise.workspace-tour')@endif
<dialog id="create-dialog"><form id="create-form" class="stack"><h2 id="dialog-title">New request</h2><label>Title<input name="title" maxlength="100" required></label><label id="visibility-field" hidden>Visibility<select name="visibility"><option value="department_only">Department only</option><option value="shared">Shared across organization</option></select></label><div id="request-fields" class="stack"><label>Ticket department<span class="request-select"><select id="request-department"></select></span></label><div id="request-classification-fields" class="field-pair"><label>Request type<span class="request-select"><select name="request_type" id="create-request-type"><option value="general">General request</option><option value="it_support">IT support</option><option value="event_setup">Event setup</option><option value="plumbing">Plumbing</option><option value="lighting">Lighting</option><option value="maintenance">Maintenance</option></select></span></label><label>Intake priority<span class="request-select"><select name="priority" id="create-request-priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></span></label></div><label>Work description<textarea name="description" maxlength="5000" rows="4"></textarea></label><label>Due date<input name="due_on" type="date"></label><label>Saved building (optional)<select name="location_id" id="request-building" aria-label="Saved building (optional)"><option value="">No saved building link</option></select></label><p class="caption" id="request-building-coverage"></p><label>Location label<input name="location" maxlength="200"></label><div class="field-pair"><label>Latitude (optional)<input name="latitude" type="number" min="-90" max="90" step="any"></label><label>Longitude (optional)<input name="longitude" type="number" min="-180" max="180" step="any"></label></div></div><p class="caption" id="create-context"></p><div class="actions"><button class="button primary" type="submit" id="create-submit">Save</button><button class="button" type="button" id="cancel-create">Cancel</button></div></form></dialog>
@unless($enterpriseSample)<script>{!! file_get_contents(resource_path('js/enterprise/transport.js')) !!}</script>@endunless
<script>{!! file_get_contents(resource_path('js/enterprise/usage.js')) !!}</script>
@if($enterpriseSample)
@includeIf('enterprise.reviewer-adapter')
@endif
@include('enterprise.proposal-ai-assets')
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
  const reviewerMode = ['sample','shared'].includes(transport.mode);
  const allowedSections = presentation.sections || ['home', 'projects', 'contractors', 'ask', 'requests', 'roll_call', 'buildings', 'calendar', 'people', 'assets', 'workflows', 'requester', 'finance', 'usage'];
  const navigation = new URLSearchParams(reviewerMode ? location.hash.slice(1) : location.search);
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
  let proposalAiUi = null;
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
    createSharedPin = null,
    busy = false;
  const workSortState = new Map(), workFeedbackHome = new Map();
  let workDetailReturn = null, rollCallReturn = null, catalogReturn = null;
  let projectCatalog=null,projectCatalogGeneration=0;
  let queuedPlan=null;
  const catalogFilters={department:'all',person:'all',statuses:{projects:'active',requests:'incoming'}};
  let navigationPushPending = false, navigationSearchBatch = false,
    navigationViewOverride = navigation.has('view') ? {view, map} : null;
  $('search').value = (navigation.get('q') || '').slice(0, 200);
  function message(text, error = false) {
    $('status').textContent = text;
    if($('job-wizard')?.open)$('job-wizard-status').textContent=text;
    $('status').classList.toggle('notice-error', error);
  }
  const api = (url, body, options) => transport.request(url, body, options);
  let workspaceLoadGeneration = 0, workspaceLoaded = false;
  function clearWorkspaceContext(failed = false) {
    if(typeof resetCampusShell==='function')resetCampusShell();
    if(transport.mode==='shared')EverbranchTransport.reviewerAdapter(presentation).invalidate();
    closeWorkDetail({clearSelection:false, restoreFocus:false});
    stashComposer();
    data = {areas: [], requests: [], projects: [], members: []};
    projectCatalog=null;projectCatalogGeneration++;
    document.dispatchEvent(new CustomEvent('enterprise:workspace-state', {detail: {loaded: false, area: null}}));
    // Clear the tour synchronously, independent of optional panel reset hooks.
    document.dispatchEvent(new CustomEvent('enterprise:tour-context', {detail: null}));
    $('request-list').replaceChildren();
    $('detail').replaceChildren();
    $('map-markers').replaceChildren();
    $('campus-map-tickets')?.replaceChildren();workMapBuilding='';
    if (failed) $('areas').replaceChildren();
    for (const id of ['create-workspace', 'create-request', 'create-project', 'campus-home', 'resource-workspace']) if ($(id)) $(id).hidden = true;
    homeGeneration++;
    homeArea = null;
    $('briefing-body').replaceChildren();
    resetNext();
  }
  async function load() {
    proposalAiUi?.invalidate();
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
      message(data.demo_storage_warning?'This browser could not retain the demo upgrade. Changes in this page may be lost on reload.':'');
      render();
      if(['projects','requests'].includes(section))loadProjectCatalog();
      if(queuedPlan&&queuedPlan.area===area&&queuedPlan.id===selected){const record=data.requests.find(row=>row.id===selected);queuedPlan=null;if(record)openJobWizard('request.plan',record);}
    } catch (error) {
      if (generation !== workspaceLoadGeneration || (!accepted && area !== requestedArea)) return;
      clearWorkspaceContext(true);
      message(error.message, true);
    }
  }
  async function command(body, sharedContext = null) {
    if (transport.mode === 'shared') return sharedCommand(body, sharedContext);
    if (busy) return;
    busy = true;
    pending = body;
    globalThis.EverbranchTransport.setRefreshBlocker('workspace-command', true);
    $('retry').hidden = true;
    message('Saving…');
    try {
      const result = await api(urls.commands, body);
      if((body.note!==undefined || body.action==='request.classify') && body.id){
        const key='work-action:'+body.area_id+':requests:'+body.id, draft={...readDraft(key)};
        if(body.note!==undefined && draft.body===body.note)delete draft.body;
        if(body.action==='request.classify')for(const key of ['request_type','priority'])if(draft[key]===(body[key]??''))delete draft[key];
        writeDraft(key,draft);
      }
      pending = null;
      pendingUncertain = false;
      globalThis.EverbranchTransport.setRefreshBlocker('workspace-command', false);
      $('create-dialog').close();
      if($('job-wizard')?.open)$('job-wizard').close();
      if(typeof jobWizard!=='undefined')jobWizard=null;
      if(result.area_id&&['job.create','request.plan'].includes(body.action)){area=result.area_id;section='projects';selected=result.project_id;}
      if (body.action === 'workspace.create') area = result.id;
      if(body.action==='request.create'){area=body.area_id;section='requests';selected=null;catalogFilters.department=body.area_id;catalogFilters.person='all';catalogFilters.statuses.requests='incoming';$('search').value='';}
      await load();
      message('Saved.');
      document.dispatchEvent(new CustomEvent('enterprise:work-saved',{detail:{area_id:result.area_id||body.area_id,project_id:result.project_id||body.project_id,task_id:result.task_id,ticket_id:result.ticket_id}}));
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
  const requestTypeLabels={general:'General request',it_support:'IT support',event_setup:'Event setup',plumbing:'Plumbing',lighting:'Lighting',maintenance:'Maintenance'};
  const requestPriority=row=>['urgent','high','normal','low'].includes('priority' in row ? row.priority : row.task?.priority) ? ('priority' in row ? row.priority : row.task.priority) : null;
  const requestTypeLabel=row=>{const labels=data.request_fields?.types||requestTypeLabels;return typeof row.request_type==='string'&&Object.hasOwn(labels,row.request_type)?labels[row.request_type]:null;};
  const requestPrioritySource=row=>row.priority_source||(row.task&&requestPriority(row)!==null?'task':'not_recorded');
  const canClassifyRequest=()=>data.request_fields?.version===1;
  function workColumns() {
    const source = workSource();
    if(section==='projects')return [['title','Project'],['department','Department'],['assignee','People assigned'],['status','Status'],['updated','Updated'],['due','Due']];
    return [
      ['title', section === 'projects' ? 'Project' : 'Request / task'],
      ...(section==='requests' ? [['type','Type'],['priority','Priority']] : []),
      ['status','Status'],
      ...(section==='requests'?[['department','Department'],['action','Assign']]:[]),
      ...(source.some(r => r.task && 'assignee_id' in r.task) ? [['assignee','Assignee']] : []),
      ...(source.some(r => r.data?.location) ? [['location','Building / location']] : []),
      ...(source.some(r => r.due_on || r.task?.due_on) ? [['due','Due']] : []),
    ];
  }
  function workValue(row, key) {
    if(key==='title')return row.title || row.name || '';
    if(key==='department')return row.department_name||data.areas.find(item=>item.id===(row._area_id||area))?.name||'Current department';
    if(key==='updated')return row.last_visible_work_at||row.updated_at||null;
    if(key==='status')return row.status || (row.archived ? 'Archived' : (row.tasks?.length&&row.tasks.every(task=>task.status==='completed')?'Completed':'Active'));
    if(key==='assignee'&&section==='projects')return [...new Set((row.tasks||[]).map(task=>task.assignee?.name).filter(Boolean))].join(', ')||null;
    if(key==='type')return requestTypeLabel(row);
    if(key==='priority')return requestPriority(row);
    if(key==='assignee')return row.task?.assignee_id ? (row._assignee?.name || data.members.find(m => String(m.id)===String(row.task.assignee_id))?.name || 'Assigned member · name unavailable') : null;
    if(key==='location')return row.data?.location || null;
    if(key==='due')return row.due_on || row.task?.due_on || (row.tasks||[]).map(task=>task.due_on).filter(Boolean).sort()[0] || null;
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
    if(key==='action')return row.can_coordinate&&['submitted','triaged'].includes(row.status)?`<button class="button" type="button" data-plan="${esc(row.id)}">Move & assign</button>`:'—';
    if(key==='title')return `<button class="link" data-open="${esc(row.id)}" data-open-area="${esc(row._area_id||area)}" aria-haspopup="dialog" aria-controls="work-drawer" aria-expanded="${row.id===selected}">${esc(value)}</button>`;
    if(key==='updated')return esc(value?String(value).slice(0,10):'Not recorded');
    if(key==='status')return `<span class="badge">${esc(String(value).replaceAll('_',' '))}</span>`;
    if(key==='priority')return value ? `<span class="work-priority" data-priority="${esc(value)}" title="${row.priority_source==='request'?'Saved intake priority':'Current linked task priority'}">${esc(value)}</span>` : 'Not recorded';
    if(key==='assignee' && !value)return section==='projects'?'Unassigned':row.task ? 'Unassigned' : row.data?.task_id ? 'Task unavailable' : 'No linked task';
    return esc(value || (key==='due'?'Unscheduled':'Not recorded'));
  }
  function syncWorkSelection() {
    $('request-list').querySelectorAll('[data-work-row]').forEach(row=>row.dataset.selected=String(row.dataset.workRow===selected));
    $('request-list').querySelectorAll('[data-open]').forEach(button=>button.setAttribute('aria-expanded',String(button.dataset.open===selected)));
  }
  function openWorkRow(id, trigger) {
    const targetArea=trigger?.dataset.openArea;
    if(targetArea&&targetArea!==area){
      const table=$('work-table-scroll');catalogReturn={trigger,id,top:scrollY,left:scrollX,tableTop:table?.scrollTop||0,tableLeft:table?.scrollLeft||0};
      document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section,area:targetArea,selected:id}}));return;
    }
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
      workDetailReturn ||= catalogReturn || {trigger:document.activeElement,id:selected,top:scrollY,left:scrollX,tableTop:table?.scrollTop||0,tableLeft:table?.scrollLeft||0};
      catalogReturn=null;
      moveWorkFeedback(true);document.documentElement.classList.add('work-detail-open');drawer.showModal();
    }
  }
  function closeWorkDetail({clearSelection=true,restoreFocus=true}={}) {
    const drawer=$('work-drawer'),wasOpen=drawer.open,back=workDetailReturn;
    clearAttachmentGallery();
    stashComposer();invalidateSharedDiscussion();discussionGeneration++;activeDiscussionKey=null;
    moveWorkFeedback(false);if(wasOpen)drawer.close();
    $('detail').replaceChildren();
    document.documentElement.classList.remove('work-detail-open');workDetailReturn=null;
    if(clearSelection){selected=null;rememberSelection();
      if(rollCallReturn){const origin=rollCallReturn,changed=area!==origin.area;rollCallReturn=null;section='roll_call';area=origin.area;rememberUrl();const restore=()=>{if(restoreFocus)requestAnimationFrame(()=>{document.querySelector('[data-section=roll_call]')?.focus({preventScroll:true});scrollTo({top:origin.top,left:0,behavior:'instant'});});};if(changed){resetNext();load().then(restore);}else{render();restore();}return;}
      rememberUrl();syncWorkSelection();
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
  function workSource(){return section==='projects'?(projectCatalog?.items||data.projects):(projectCatalog?.requests||data.requests);}
  async function loadProjectCatalog(){
    const generation=++projectCatalogGeneration,scope=workspaceLoadGeneration,sourceData=data,allowed=data.areas.map(row=>({...row}));
    const items=[],requests=[],scopes=[],failed=[],partial=[];
    for(let index=0;index<allowed.length;index+=3){
      await Promise.all(allowed.slice(index,index+3).map(async department=>{
        try{const result=department.id===sourceData.selected_area_id?sourceData:await api(urls.data+'&area_id='+encodeURIComponent(department.id));
          if(result.selected_area_id!==department.id)throw new Error('Scope changed');
          for(const project of result.projects)items.push({...project,_area_id:department.id,department_name:department.name});
          for(const request of result.requests)requests.push({...request,_area_id:department.id,department_name:department.name,_assignee:result.members.find(member=>String(member.id)===String(request.task?.assignee_id))});
          scopes.push({areaId:result.selected_area_id,data:result});if(result.has_more||result.scan_partial||result.buildings?.has_more)partial.push(department.id);
        }catch{failed.push(department.id);}
      }));
      if(scope!==workspaceLoadGeneration||generation!==projectCatalogGeneration)return;
    }
    if(scope!==workspaceLoadGeneration||generation!==projectCatalogGeneration)return;
    projectCatalog={items,requests,scopes,failed,partial,departments:allowed};if(['projects','requests'].includes(section))render({preserveDetail:true});
  }
  function renderWorkFilters(){
    const project=section==='projects',people=new Map();
    for(const row of workSource())for(const task of project?(row.tasks||[]):[row.task])if(task?.assignee)people.set(String(task.assignee.id),task.assignee.name);
    if(!project)for(const member of data.members)people.set(String(member.id),member.name);
    $('work-filters').hidden=!['projects','requests'].includes(section);
    $('work-department-field').hidden=false;
    const selectedDepartment=catalogFilters.department,selectedPerson=catalogFilters.person;
    $('work-department').innerHTML='<option value="all">All permitted departments</option>'+data.areas.map(row=>`<option value="${esc(row.id)}">${esc(row.name)}</option>`).join('');
    $('work-department').value=[...$('work-department').options].some(row=>row.value===selectedDepartment)?selectedDepartment:'all';
    $('work-person').innerHTML='<option value="all">Everyone</option><option value="unassigned">Unassigned</option>'+[...people].map(([id,name])=>`<option value="${esc(id)}">${esc(name)}</option>`).join('');
    $('work-person').value=[...$('work-person').options].some(row=>row.value===selectedPerson)?selectedPerson:'all';
    $('work-status').innerHTML=(project?[['active','Active projects'],['all','All projects'],['completed','Completed'],['archived','Archived']]:[['incoming','Incoming tickets'],['active','Assigned work'],['all','All returned tickets'],['completed','Completed']]).map(([value,label])=>`<option value="${value}">${label}</option>`).join('');
    $('work-status').value=(project?['active','all','completed','archived']:['incoming','active','all','completed']).includes(catalogFilters.statuses[section])?catalogFilters.statuses[section]:project?'active':'incoming';
  }
  function rows() {
    const query=$('search').value.trim().toLowerCase(),project=section==='projects';
    const status=$('work-status').value|| (project?'active':'incoming'),person=$('work-person').value||'all',department=$('work-department').value||'all';
    return workSource().filter(row=>{
      if(!String(row.title||row.name).toLowerCase().includes(query))return false;
      if(department!=='all'&&(row._area_id||area)!==department)return false;
      const tasks=project?(row.tasks||[]):[row.task].filter(Boolean),ids=tasks.map(task=>String(task.assignee_id||''));
      if(person==='unassigned'&&ids.some(Boolean)||person!=='all'&&person!=='unassigned'&&!ids.includes(person))return false;
      const complete=project?tasks.length>0&&tasks.every(task=>task.status==='completed'):row.status==='completed';
      if(typeof preferencesState!=='undefined'&&preferencesState&&preferencesArea===area){const filter=preferencesState.effective.filter||'all',currentViewer=String(data.viewer_id||viewer);if(filter==='active'&&(complete||row.archived)||filter==='completed'&&!complete||filter==='mine'&&!(ids.includes(currentViewer)||String(row.data?.created_by)===currentViewer||String(row.owner_id)===currentViewer))return false;}
      if(project){if(status==='active'&&(row.archived||complete)||status==='archived'&&!row.archived||status==='completed'&&!complete)return false;}
      else if(status==='incoming'&&!['submitted','triaged'].includes(row.status)||status==='active'&&!['assigned','in_progress','awaiting_verification'].includes(row.status)||status==='completed'&&!complete)return false;
      return true;
    });
  }
  function render({preserveDetail=false}={}) {
    if(!['requests','projects'].includes(section))closeWorkDetail({clearSelection:false,restoreFocus:false});
    if(reviewerMode)$('enterprise-main').dataset.demoPage='enterprise-'+section;
    if(reviewerMode)document.dispatchEvent(new CustomEvent('enterprise:section-rendered',{detail:{section}}));
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
    $('create-project-only').hidden=!data.can_coordinate||section!=='projects';
    $('contractors').hidden = section !== 'contractors';
    $('work').hidden = section === 'contractors';
    $('heading').textContent = section === 'projects' ? 'Work with clear ownership.' : section === 'contractors' ? 'Trusted partners, connected work.' : 'Turn requests into owned work.';
    $('breadcrumb').textContent = section === 'projects' ? 'Projects' : section === 'contractors' ? 'Contractors' : 'Incoming tickets';
    renderNext();
    renderHome();
    const contextLabels={home:data.demo_persona==='executive'?'Campus overview':'Campus overview',projects:'Projects',requests:'Incoming tickets',contractors:'Contractors',ask:reviewerMode?(presentation.reviewerAi?.enabled?'AI tools':'AI tools · Coming soon'):'Ask Everbranch',roll_call:'Roll Call',buildings:'Buildings',calendar:'Calendar',people:'People & contractors',assets:'Assets',workflows:'Workflows',requester:'My service requests',finance:'Invoice & fund packets',usage:'AI & voice usage'};
    $('heading').textContent=contextLabels[section]||'Workspace';
    $('map').hidden = !map;
    $('request-list').hidden=view==='map';
    if(view==='map')$('work-filters').after($('map'));else $('coverage').before($('map'));
    $('map-toggle').textContent = map ? 'Hide Map' : 'View Map';
    document.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === view)));
    renderWorkFilters();
    const work = sortedWork(rows());
    if (view === 'calendar') {
      renderWorkCalendar(work,projectCatalog||data);
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
    $('request-list').querySelectorAll('[data-plan]').forEach(button=>button.onclick=()=>{const record=workSource().find(row=>row.id===button.dataset.plan);if(!record)return;if((record._area_id||area)!==area){queuedPlan={id:record.id,area:record._area_id};document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:'requests',area:record._area_id,selected:record.id}}));}else openJobWizard('request.plan',record);});
    renderCampusWorkMap(work,projectCatalog||data);
    $('coverage').textContent = section==='projects'?(projectCatalog?'Readable projects across permitted departments.'+(projectCatalog.failed.length?' Some department reads failed; those rows are excluded.':''):'Checking permitted departments; currently showing this department.'):(projectCatalog?'Returned tickets across permitted departments. Up to100perdepartment; counts describe this returned view. ':'Showing up to100current-department tickets. ')+ 'Priority uses saved intake until assignment, then the current linked task. Assignee comes from the linked task. Saved building links are explicit; other location labels are entered text.' + (data.has_more ? ' More visible requests exist.' : '') + (data.scan_partial ? ' The scan limit was reached; results are partial.' : '');
    if(!preserveDetail)detail();
    document.dispatchEvent(new CustomEvent('enterprise:render', {detail: {data, area, section, selected, transport}}));
    document.dispatchEvent(new CustomEvent('enterprise:workspace-state', {detail: {area, section, features: data.features || {}, loaded: workspaceLoaded, viewer_id: data.viewer_id}}));
  }
  function detail() {
    const detailSharedContext = transport.mode === 'shared' && data.shared ? {pin:captureSharedPin(),target:data,child_ids:{task_id:crypto.randomUUID()}} : null;
    clearAttachmentGallery();
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
      $('work-drawer').dataset.entityType='project';
      renderProjectDetail(r);
      return;
    }
    const classification=canClassifyRequest()&&r.can_coordinate&&['submitted','triaged'].includes(r.status)?`<section aria-labelledby="request-classify-heading"><h3 id="request-classify-heading">Request details</h3><div class="field-pair"><label>Request type<span class="request-select"><select id="request-type"><option value="">Not recorded</option>${Object.entries(data.request_fields.types).map(([value,label])=>`<option value="${esc(value)}"${value===r.request_type?' selected':''}>${esc(label)}</option>`).join('')}</select></span></label><label>Intake priority<span class="request-select"><select id="request-priority"><option value="">Not recorded</option>${data.request_fields.priorities.map(value=>`<option value="${esc(value)}"${value===r.requested_priority?' selected':''}>${esc(value)}</option>`).join('')}</select></span></label></div><p class="caption">Save these details before assignment. After assignment, task controls set priority.</p><button class="button" data-action="request.classify">Save request details</button></section>`:'';
    $('work-drawer').dataset.entityType='request';
    $('detail').innerHTML = `<section class="work-detail-summary"><span class="badge">${esc(r.status)}</span><h2>${esc(r.title)}</h2><p class="caption">Type: ${esc(requestTypeLabel(r)||'Not recorded')} · Priority: ${esc(requestPriority(r)||'Not recorded')} (${requestPrioritySource(r)==='task'?'linked task':requestPrioritySource(r)==='request'?'intake':'not recorded'})${requestPrioritySource(r)==='task'?` · Intake priority: ${esc(r.requested_priority||'Not recorded')}`:''}</p><p class="work-detail">${esc(r.data.description)}</p><h3>Location</h3><p class="subtle">${esc(r.data.location || 'Not provided')}</p><h3>Progress</h3><p class="work-detail">${esc(r.data.progress_note || r.data.triage_note || 'No update yet.')}</p>${r.data.task_id ? `<p class="caption">Linked task: ${esc(r.task?.title || r.data.task_id)}<br>Assignee: ${esc(data.members.find(m => m.id === r.task?.assignee_id)?.name || 'Existing workspace member')}</p>` : ''}<div class="stack">${classification}${r.can_coordinate && r.status === 'submitted' ? (transport.mode==='shared'?'<button class="button primary" data-action="request.triage">Triage ticket</button>':'<label>Triage note<textarea id="action-note" maxlength="2000"></textarea></label><button class="button primary" data-action="request.triage">Triage request</button>') : ''}${r.can_coordinate && r.status === 'triaged' ? `<label>Project<select id="assign-project">${data.projects.filter(p => !p.archived).map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('')}</select></label><label>Existing editor<select id="assign-member">${data.members.map(m => `<option value="${esc(m.id)}">${esc(m.name)}</option>`).join('')}</select></label><button class="button primary" data-action="request.assign">Assign task</button>` : ''}${r.can_progress && ['assigned', 'in_progress'].includes(r.status) ? (transport.mode==='shared'?'<button class="button" data-action="request.progress">Start work</button><button class="button primary" data-action="request.complete">Complete work</button>':'<label>Progress note<textarea id="action-note" maxlength="2000"></textarea></label><button class="button" data-action="request.progress">Save progress</button><button class="button primary" data-action="request.complete">Complete work</button>') : ''}<button class="button" id="show-history">Audit history</button><div id="history"></div></div></section>`;
    if(r.can_coordinate&&['submitted','triaged'].includes(r.status)){const move=document.createElement('button');move.type='button';move.className='button primary';move.textContent='Move & assign';move.onclick=()=>openJobWizard('request.plan',r);$('detail').querySelector('.work-detail-summary').prepend(move);}
    $('detail').querySelectorAll('[data-action]').forEach(b => b.onclick = () => {
      if (pending) return message('Retry the retained action before making another change.', true);
      const body = {
        action: b.dataset.action,
        id: r.id,
        area_id: area,
        request_id: crypto.randomUUID(),
        expected_version: r.version
      };
      if (body.action==='request.classify') {body.request_type=$('request-type').value||null;body.priority=$('request-priority').value||null;}
      else if ($('action-note')) body.note = $('action-note').value;
      if (body.action === 'request.assign') {
        if (!$('assign-project').value || !$('assign-member').value) return message('Create a project and select an existing authorized editor first.', true);
        body.project_id = $('assign-project').value;
        body.assignee_id = $('assign-member').value;
      }
      command(body, detailSharedContext ? {...detailSharedContext,record:r} : null);
    });
    const actionNote=$('action-note'),actionDraftKey='work-action:'+area+':'+section+':'+r.id;
    if(actionNote){actionNote.value=readDraft(actionDraftKey).body||'';actionNote.oninput=()=>writeDraft(actionDraftKey,{...readDraft(actionDraftKey),body:actionNote.value});}
    for(const [id,key] of [['request-type','request_type'],['request-priority','priority']]){const input=$(id);if(input){const saved=readDraft(actionDraftKey);if(key in saved && [...input.options].some(option=>option.value===saved[key]))input.value=saved[key];input.onchange=()=>writeDraft(actionDraftKey,{...readDraft(actionDraftKey),[key]:input.value});}}

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
  let requestBuildingChoices=[],requestCreateArea=null,requestCreateGeneration=0,requestCreateReady=false;
  let requestCreateData = null;
  function fillRequestBuildings(source){
    requestCreateData = source;
    requestBuildingChoices=(source.buildings?.items||[]).map(row=>({...row}));
    $('request-building').innerHTML='<option value="">No saved building link</option>'+requestBuildingChoices.map(row=>`<option value="${esc(row.id)}">${esc(row.title)}</option>`).join('');
    $('request-building').disabled=requestBuildingChoices.length===0;
    $('request-building-coverage').textContent=transport.mode==='shared'?'Saved fictional building links are stored in the shared workspace.':reviewerMode?'Saved fictional building links stay in this browser demo.':(source.buildings?.coverage||'No current saved buildings are available.')+(source.buildings?.has_more?' More buildings are outside this result.':'')+' Choose a building to link this request; a typed label alone stays unverified.';
    syncRequestBuilding();
  }
  async function chooseRequestDepartment(id){
    const generation=++requestCreateGeneration;requestCreateReady=false;$('create-submit').disabled=true;requestCreateArea=id;
    try{const source=await api(urls.data+'&area_id='+encodeURIComponent(id));
      if(generation!==requestCreateGeneration||createAction!=='request.create'||!$('create-dialog').open||$('request-department').value!==id)return;
      if(source.selected_area_id!==id||!source.can_submit)throw new Error('Ticket intake is unavailable in this department.');
      fillRequestBuildings(source);requestCreateReady=true;$('create-submit').disabled=false;$('create-context').textContent='New incoming ticket in '+(source.areas.find(row=>row.id===id)?.name||'this department')+'. Assignment can happen later.';
    }catch(error){if(generation===requestCreateGeneration)message(error.message,true);}
  }
  $('request-department').onchange=()=>chooseRequestDepartment($('request-department').value);

  function create(action, buildingIntake = null) {
    if (pending) { message('Retry the retained action before starting another.', true); return false; }
    if(buildingIntake && (transport.mode!=='shared'||action!=='request.create'||buildingIntake.source!==data
      ||data.selected_area_id!==area||!data.can_submit
      ||!data.buildings?.items.some(row=>row.id===buildingIntake.building?.id&&row.version===buildingIntake.building.version))){
      message('Refresh this building before opening a new ticket.',true);return false;
    }
    createAction = action;
    createSharedPin = captureSharedPin();
    draft = {
      id: crypto.randomUUID(),
      request_id: crypto.randomUUID()
    };
    $('create-form').reset();
    if(transport.mode==='shared'){
      for(const name of ['location','latitude','longitude']){
        const input=$('create-form').elements[name];input.disabled=true;input.closest('label').hidden=true;
      }
    }
    requestCreateArea=area;requestCreateReady=true;requestCreateGeneration++;$('create-submit').disabled=false;
    $('request-department').innerHTML=data.areas.map(row=>`<option value="${esc(row.id)}">${esc(row.name)}</option>`).join('');$('request-department').value=area;
    fillRequestBuildings(data);
    $('dialog-title').textContent = action === 'workspace.create' ? 'New workspace' : action === 'project.create' ? 'New project' : 'New ticket';
    $('visibility-field').hidden = action !== 'workspace.create';
    $('request-fields').hidden = action !== 'request.create';
    $('request-classification-fields').hidden=!canClassifyRequest();
    $('create-context').textContent = action === 'workspace.create' ? 'Creating a workspace adds no users or access grants. Department access must already be authorized.' : 'Saved within the selected workspace and its visibility boundary.';
    $('create-dialog').showModal();
    if(buildingIntake){$('request-building').value=buildingIntake.building.id;syncRequestBuilding();}
    else if(action==='request.create'&&catalogFilters.department!=='all'&&catalogFilters.department!==area&&data.areas.some(row=>row.id===catalogFilters.department)){$('request-department').value=catalogFilters.department;chooseRequestDepartment(catalogFilters.department);}
    return true;
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
    if (createAction === 'workspace.create') body.visibility = fields.get('visibility');else body.area_id = createAction==='request.create'?requestCreateArea:area;
    if (createAction === 'request.create') {
      if(!requestCreateReady)return message('Wait for the current department intake check.',true);
      body.expected_version = 0;
      if(canClassifyRequest()){body.request_type=fields.get('request_type');body.priority=fields.get('priority');}
      for (const key of ['description', 'location', 'due_on', 'latitude', 'longitude']) if (fields.get(key) !== '') body[key] = fields.get(key);
      const building=requestBuildingChoices.find(row=>row.id===fields.get('location_id'));
      if(fields.get('location_id')&&!building)return message('That building is unavailable. Close the form and refresh before saving.',true);
      if(building){body.location_id=building.id;body.location_version=building.version;delete body.latitude;delete body.longitude;}
    }
    command(body, {pin:createSharedPin,target:requestCreateData || data});
  };
  $('create-workspace').onclick = () => create('workspace.create');
  $('create-project').onclick = () => openJobWizard('job.create');
  $('create-request').onclick = () => create('request.create');
  $('cancel-create').onclick = () => {
    requestCreateGeneration++;$('create-dialog').close();
  };
  $('retry').onclick = () => pending && command(pending);
  // Workspace history stores navigation only. Record drafts keep their existing scoped storage.
  function beginNavigation(kind = 'change') {
    proposalAiUi?.invalidate();
    navigationPushPending = kind !== 'search' || !navigationSearchBatch;
    navigationSearchBatch = kind === 'search';
  }
  function rememberNavigation(value) {
    let target;
    try {
      // Retain the sample adapter's canonical same-origin destination validation.
      const requested = new URL(String(value), location.href);
      if(transport.mode==='shared'){
        const state=new URLSearchParams(requested.hash.slice(1));
        if(state.has('month')&&!EverbranchWorkViews.monthKey(state.get('month')))return;
        state.delete('month');requested.hash=state.toString();
      }
      if(reviewerMode && selected===null){
        const state=new URLSearchParams(requested.hash.slice(1));state.delete('selected');requested.hash=state.toString();
      }
      target = new URL(baseTransport.href(String(requested)), location.href);
    } catch { return; }
    if (target.origin !== location.origin || target.pathname !== location.pathname || target.username || target.password) return;
    const parameters = new URLSearchParams(reviewerMode ? target.hash.slice(1) : target.search);
    // These fixed presentation fields are intentionally not adapter authority or record data.
    parameters.set('view', ['list', 'calendar', 'map'].includes(view) ? view : 'list');
    parameters.set('map', map ? '1' : '0');
    parameters.set('month',workMonth);
    const query = $('search').value.slice(0, 200);
    query ? parameters.set('q', query) : parameters.delete('q');
    if (reviewerMode) { target.search = ''; target.hash = parameters.toString(); }
    else { parameters.set('tenant', tenantSlug); target.search = parameters.toString(); }
    if (target.href !== location.href) {
      const method = navigationPushPending ? 'pushState' : 'replaceState';
      history[method](history.state, '', target.href);
    }
    navigationPushPending = false;
  }
  function restoreNavigation() {
    const incoming = new URLSearchParams(reviewerMode ? location.hash.slice(1) : location.search);
    rememberSelection();
    navigationPushPending = false;
    navigationSearchBatch = false;
    workMonth=EverbranchWorkViews.monthKey(incoming.get('month'))||campusToday().slice(0,7);
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
  // Stop departing read batches before a replacement document commits. A canceled navigation keeps fresh loads available.
  globalThis.addEventListener('beforeunload',()=>{workspaceLoadGeneration++;projectCatalogGeneration++;});
  // A restored browser document must not revive cached operational facts or active voice.
  globalThis.addEventListener('pagehide',()=>clearWorkspaceContext(true));
  globalThis.addEventListener('pageshow',event=>{if(event.persisted)restoreNavigation();});
  $('search').oninput = () => {
    $('search').value = $('search').value.slice(0, 200);
    beginNavigation('search');
    render();
  };
  for(const [id,key] of [['work-department','department'],['work-person','person'],['work-status','status']])$(id).onchange=()=>{if(key==='status')catalogFilters.statuses[section]=$(id).value;else catalogFilters[key]=$(id).value;render({preserveDetail:true});};
  $('create-project-only').onclick=()=>openJobWizard('project.create');
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
    if(['projects','requests'].includes(section)&&!projectCatalog)loadProjectCatalog();
  });
  @include('enterprise.next-script')
  @include('enterprise.work-detail-script')
  @include('enterprise.work-views-script')
  @include('enterprise.work-management-script')
  @include('enterprise.shared-work-script')
  @include('enterprise.shared-comments-script')
  @include('enterprise.shared-comments-bridge')
  @include('enterprise.shared-files-bridge')
  @includeIf('enterprise.operations-script')
  @include('enterprise.project-detail-script')
  @includeIf('enterprise.procedures-script')
  @includeIf('enterprise.finance-script')
  @include('enterprise.voice-script')
  @include('enterprise.usage-script')
  @include('enterprise.shared-directory-script')
  @include('enterprise.campus-shell-script')
  document.addEventListener('enterprise:navigate', event => {
    const next = event.detail || {};
    if (next.section && !allowedSections.includes(next.section)) return;
    rememberSelection();
    beginNavigation();
    const previousArea = area, previousSection = section, previousSelected = selected;
    if(next.returnSection==='roll_call'&&section==='roll_call'&&next.section==='projects')rollCallReturn={area,top:scrollY};else if(next.section&&next.section!=='projects')rollCallReturn=null;
    if (next.area !== undefined) area = next.area;
    if (next.section) section = next.section;
    if(['list','calendar','map'].includes(next.view)){view=next.view;map=next.map===true||view==='map';navigationViewOverride={view,map};}
    selected = next.selected !== undefined ? next.selected : selectionMemory.get(area + ':' + section) || (area === previousArea && section === previousSection ? previousSelected : null);
    rememberUrl();
    if (next.area !== undefined) {resetNext(); load();} else render();
  });
  @include('enterprise.proposal-ai-script')
  load();
})();
</script>
@include('enterprise.app-install')
@if($enterpriseSample)
@if(!empty($voiceAssets))<script type="module">{!! $voiceAssets !!}</script>@endif
@elseif(config('enterprise.voice_jobs_enabled') && config('enterprise.ask_enabled'))
@vite('resources/js/enterprise/voice-jobs-entry.js')
@endif
@include('enterprise.mobile-navigation')
</body></html>
