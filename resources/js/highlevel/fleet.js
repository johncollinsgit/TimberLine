import './fleet.css';
import {RouteHistory, routeColor} from './route-history.js';
import {setupStep, setupCopy} from './onboarding.js';
import {createDemo, demoRequest} from './demo.js';
import {operationsView, bindOperations, jobContext, drawFallbackMap, panFallback} from './operations.js';

const config = JSON.parse(document.getElementById('fleet-config').textContent);
const root = document.getElementById('fleet-app');
const history = new RouteHistory();
let leaflet = null;
let demo = Boolean(config.demo), demoData=null;
let sessionScope = null, sessionEpoch = 0;
let token = null, expiresAt = 0, parentOrigin = null, data = null, page = 'fleet', selected = null, search = '', devices = null, busy = false;
const labels = {fleet:'Fleet', health:'Connection health', jobs:'Jobs & dispatch', trips:'Trips & routes', maintenance:'Maintenance', alerts:'Health alerts', connection:'Connection', settings:'Settings'};
let tourStep = 0;
const tour = [
    ['fleet','See the whole fleet','Every vehicle and route here is fictional. Compare the colored recorded routes, uncheck vehicles, or search and check a truck to add it to the map.'],
    ['health','Check connection health','Compare the latest reading and explicit device signals. An older parked-vehicle reading does not prove a tracker is disconnected.'],
    ['jobs','Review upcoming work','Calendars and open pipeline work connect automatically in the live app. Review the sample appointments, crew readiness and dispatch suggestions.'],
    ['trips','Review a full recorded trip','Expand a trip to review provider miles, drive and idle time, and a reported path. A route-review flag calls for a manager’s review.'],
    ['maintenance','Plan maintenance and costs','Review overdue work, several upcoming services, recently completed work and illustrative USD service costs. Expand a plan to rehearse recording completed service.'],
    ['alerts','Follow up on vehicle issues','Assign a fictional battery or check-engine alert and record the action taken. A completed review does not clear a real vehicle fault.'],
    ['fleet','Try the map yourself','Use the date range, search, and vehicle checkboxes to compare routes. Vehicle details include illustrative monthly operating budgets. Demo changes stay in this browser; no real account is connected.'],
];
let pendingCompletion = null;
let connecting = false, popupTimer = null, signingIn = false, crmSyncing = false, crmCheckedAt = 0, crmError = '', formDirty = false;
let mapPromise = null, map = null, markers = [], popup = null, authPromise = null;
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const vehicleLimit = () => Number(data.settings.vehicle_limit);
const when = (iso) => iso ? new Date(iso).toLocaleString() : 'No location reported';
function notice(message, error = false) {
    const target = document.getElementById('notice');
    if (target) { target.textContent = message; target.className = error ? 'banner error' : 'banner'; }
}
function signInView(message = 'Checking your CRM account…', failed = false) {
    root.innerHTML = `<main class="launch-card"><div class="brand">Everbranch <span>Fleet</span></div><h1>${failed ? 'Open Fleet from your CRM' : 'Signing in to Fleet'}</h1><p role="status">${escape(message)}</p><ol class="signin-steps"><li>Open the client account where Fleet is installed.</li><li>Select <strong>Everbranch</strong> in that account’s sidebar.</li><li>Your CRM signs you in automatically. No separate Fleet password is needed.</li></ol>${failed ? '<button class="primary" id="retry">Try CRM sign-in again</button><p>If access is denied, ask the agency administrator to check your access to this client account.</p>' : '<p>Keep this page open while your administrator access is verified.</p>'}<a href="/crm/fleet/guide" target="_blank" rel="noopener">Fleet setup guide</a></main>`;
    const retry = document.getElementById('retry');
    if (retry) retry.onclick = start;
}
async function readResponse(response, fallback) {
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || fallback);
    return result;
}
async function context() {
    if (window.parent === window) throw new Error('Open Everbranch from the navigation inside your CRM client account.');
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => { window.removeEventListener('message', listener); reject(new Error('Your CRM did not provide account context. Refresh the CRM and open Everbranch again.')); }, 12000);
        function listener(event) {
            if (event.source !== window.parent || !config.parentOrigins.includes(event.origin) || event.data?.message !== 'REQUEST_USER_DATA_RESPONSE' || typeof event.data.payload !== 'string') return;
            clearTimeout(timeout); window.removeEventListener('message', listener);
            resolve({payload:event.data.payload, origin:event.origin});
        }
        window.addEventListener('message', listener);
        for (const origin of config.parentOrigins) window.parent.postMessage({message:'REQUEST_USER_DATA'}, origin);
    });
}
async function authorize() {
    if (authPromise) return authPromise;
    authPromise = (async () => {
        const challengeResponse = await fetch('/crm/fleet/session/challenge', {credentials:'omit', headers:{Accept:'application/json'}, signal:AbortSignal.timeout(20000)});
        const {challenge} = await readResponse(challengeResponse, 'Fleet sign-in is temporarily unavailable. Try again shortly.');
        const user = await context();
        const response = await fetch('/crm/fleet/session/exchange', {method:'POST',credentials:'omit',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({encryptedData:user.payload, parentOrigin:user.origin, challenge}), signal:AbortSignal.timeout(20000)});
        const result = await readResponse(response, 'Administrator access could not be verified. Reopen Everbranch from the client account.');
        if(sessionScope && sessionScope !== result.session_scope){
            sessionScope=null;sessionEpoch++;token=null;expiresAt=0;data=null;history.reset();
            const message='Your CRM account changed. Start CRM sign-in again to load the selected client safely.';
            signInView(message,true);throw new Error(message);
        }
        sessionScope=result.session_scope;
        token = result.token; expiresAt = Date.parse(result.expires_at); parentOrigin = user.origin;
    })();
    try { await authPromise; } finally { authPromise = null; }
}
async function api(path, method = 'GET', body = null) {
    if(demo)return demoRequest(demoData,path,method,body);
    if (!token || expiresAt < Date.now() + 60000) await authorize();
    const observedEpoch=sessionEpoch;
    const response = await fetch(`/crm/fleet/api/${path}`, {method,credentials:'omit',headers:{Accept:'application/json','Content-Type':'application/json',Authorization:`Bearer ${token}`,'X-Everbranch-Parent-Origin':parentOrigin},...(body !== null ? {body:JSON.stringify(body)} : {})});
    const result = await response.json().catch(()=>({message:'The service did not return a valid response. Try again shortly.'}));
    if(observedEpoch!==sessionEpoch)throw new Error('CRM account changed. Reopen Fleet from the selected client.');
    if (!response.ok) {
        if (response.status === 401 || response.status === 403) { token = null; expiresAt = 0; }
        throw new Error(path === 'operations/sync' && response.status === 403 ? 'CRM work access could not be verified. Reopen Fleet; if it continues, ask your agency administrator to check calendar, appointment and opportunity read permissions.' : Object.values(result.errors || {}).flat()[0] || result.message || 'The request could not be completed.');
    }
    return result;
}
function statusBanner() {
    if (!data.subscription.has_access && !data.subscription.required) return '<div class="banner error"><strong>Fleet access unavailable</strong><p>An agency administrator must reinstall the app to restore access.</p></div>';
    if (!data.subscription.has_access) return '<div class="banner error"><strong>Subscription requires attention</strong><p>An agency administrator can review the app subscription in your CRM. Fleet access resumes after CRM confirms payment.</p></div>';
    if (!data.subscription.collection_active) return ''; // The setup checklist distinguishes saved setup from pilot activation.
    if (data.subscription.required && data.subscription.status === 'FAILED') return `<div class="banner"><strong>Payment requires attention</strong><p>Fleet remains available during the payment grace period, ending ${escape(when(data.subscription.grace_ends_at))}. Review billing with your agency administrator.</p></div>`;
    if (!data.settings.policy_approved || !data.settings.tracking_enabled) return '<div class="banner"><strong>Setup required</strong><p>Approve your company vehicle policy and enable collection in Settings.</p></div>';
    if (data.connection.status !== 'connected') return '<div class="banner"><strong>Bouncie disconnected</strong><p>Connect your Bouncie account to select and track company vehicles. Bouncie subscriptions are billed separately.</p></div>';
    if (data.connection.health.status === 'unavailable') return '<div class="banner error"><strong>Bouncie could not be refreshed</strong><p>Saved positions remain visible. Check the reported timestamps and try reconnecting if the problem continues.</p></div>';
    return '';
}
function tourView(){
    if(!demo || tourStep<0)return '';
    const step=tour[tourStep];
    return `<section class="card demo-tour" id="demo-tour" aria-labelledby="tour-title"><span class="eyebrow">Click-through demo · ${tourStep+1} of ${tour.length}</span><h2 id="tour-title">${step[1]}</h2><p>${step[2]}</p><div class="actions"><button id="tour-back" ${tourStep===0?'disabled':''}>Back</button><button id="tour-next" class="primary">${tourStep===tour.length-1?'Finish tour':'Next: '+tour[tourStep+1][1]}</button><button id="tour-close">Explore on my own</button></div></section>`;
}
function setupView() {
    if (demo) return '';
    const step = setupStep(data), [title, description, action] = setupCopy(step, vehicleLimit());
    const complete = [data.connection.status === 'connected', data.vehicles.length > 0, data.settings.policy_approved && data.settings.tracking_enabled];
    if (step === 'ready') return '';
    return `<section class="card setup-card" aria-labelledby="setup-title"><div class="setup-heading"><div><span class="eyebrow">Fleet setup</span><h2 id="setup-title">${title}</h2><p>${description}</p></div><span class="pill">${complete.filter(Boolean).length} of 3 steps saved</span></div><ol class="setup-steps">${['Connect Bouncie', 'Choose vehicles', 'Review tracking settings'].map((label, i) => `<li class="${complete[i] ? 'complete' : ''}" ${['connection','vehicles','settings'][i] === step ? 'aria-current="step"' : ''}><span>${complete[i] ? '✓' : i + 1}</span>${label}<small>${complete[i] ? 'Saved' : 'Not complete'}</small></li>`).join('')}</ol>${step === 'activation' ? `<a class="button primary" href="mailto:${escape(data.support_email)}?subject=Fleet%20pilot%20activation">${action}</a>` : `<button id="setup-next" class="primary" ${step === 'access' ? 'disabled' : ''}>${action}</button>`}<small class="setup-note">CRM calendars and open opportunities are discovered automatically from this client account. No CRM source ID is needed.</small></section>`;
}
function render() {
    if (!data) return;
    history.configure(data);
    root.innerHTML = `<main class="shell"><header class="topbar"><div class="brand">Everbranch <span>Fleet</span></div><div class="account">${escape(data.workspace.name)} <button id="toggle-demo">${demo?'Exit demo':'Explore demo'}</button>${demo?'<button id="start-tour">Restart guided tour</button>':''}</div></header>
        <div class="heading"><div><h1>${page === 'fleet' ? 'Your fleet, in view' : page === 'connection' ? 'Bouncie connection' : labels[page]}</h1><p>${page === 'fleet' ? 'Compare company vehicle locations and recorded Bouncie routes.' : page === 'connection' ? 'One Bouncie account. A separate workspace for this client.' : page==='settings' ? 'Control company vehicle collection and location retention.' : 'Manage your fleet using CRM work and provider readings.'}</p></div><span id="vehicle-total" class="pill">${data.vehicles.length} / ${vehicleLimit()} vehicles</span></div>
        <nav class="tabs" aria-label="Fleet navigation">${Object.keys(labels).map(tab => `<button data-page="${tab}" class="${page===tab?'active':''}" ${page===tab?'aria-current="page"':''}>${labels[tab]}</button>`).join('')}</nav>
        ${tourView()}${demo?'<div class="banner demo-banner"><strong>DEMO · Fictional fleet data</strong><p>Explore every feature. Changes stay in this browser and do not affect a client account or connect Bouncie.</p></div>':''}<div id="status-banner">${demo?'':statusBanner()}</div><div id="setup-panel">${setupView()}</div><div id="notice" role="status"></div><section id="content">${page === 'fleet' ? fleetView() : page === 'connection' ? connectionView() : page === 'settings' ? settingsView() : operationsView(page,data)}</section>
        <footer class="footer"><span>Only company vehicles · Up to 30 days of location history</span><a href="mailto:${escape(data.support_email)}">Contact support</a></footer></main>`;
    formDirty = false;
    bind();
    if (page === 'fleet' && data.vehicles.length) { updateList(); void history.load(data,api,updateHistory); drawMap().catch(() => { const el=document.getElementById('map'); if(el) el.innerHTML='<p>The map could not load. Reported locations remain available in vehicle details.</p>'; }); }
}
function fleetView() {
    if (!data.vehicles.length) return `<div class="card empty"><h2>No vehicles selected yet</h2><p>${data.connection.status === 'connected' ? 'Your Bouncie account is connected. Choose the company vehicles you want to see here.' : 'Connect Bouncie to choose the company vehicles for this workspace.'}</p><button data-page="connection">${data.connection.status === 'connected' ? 'Choose company vehicles' : 'Open connection'}</button></div>`;
    return `<div class="fleet-layout"><aside class="card fleet-list"><label class="sr-only" for="search">Search vehicles</label><input id="search" type="search" placeholder="Search vehicles" autocomplete="off"><div class="map-selection-actions"><button id="show-results">Show search results on map</button><button id="hide-vehicles">Uncheck all vehicles</button></div><p id="map-count" role="status"></p><div id="vehicle-list"></div></aside><div class="card map-card"><div class="map-toolbar"><h2>Fleet map</h2><small>Refreshes every 60 seconds</small></div><form id="route-range" class="route-controls"><label class="checkbox"><input id="show-routes" type="checkbox" ${history.show?'checked':''}> Show recorded routes</label><label>From<input name="start" type="date" value="${escape(history.start)}" min="${escape(data.route_range?.earliest||'')}" max="${escape(data.route_range?.today||history.end)}" required></label><label>Through<input name="end" type="date" value="${escape(history.end)}" min="${escape(history.start)}" max="${escape(data.route_range?.today||history.end)}" required></label><button type="submit">Load routes</button><button id="fit-vehicles" type="button">Fit selected vehicles</button></form><p class="route-explanation">${demo?'Fictional example routes for this demonstration.':'Recorded Bouncie trip traces for the selected dates.'} Each vehicle has its own color. Gaps and sparse samples are not joined into roads. Times use ${escape(data.route_range?.timezone||'America/New_York')}.</p><div class="map-legend"><span class="legend-dot green"></span> Vehicles <span class="legend-dot amber"></span> Older reading <span class="legend-dot blue"></span> CRM job stops</div><div id="map" class="map"><p>Loading map…</p></div><div id="vehicle-details" class="details"></div></div></div>`;
}
function updateList() {
    const vehicle = data.vehicles.find(v => v.id === selected) || data.vehicles[0];
    selected = vehicle.id;
    const ctx=jobContext(data,vehicle.id);
    const visible = data.vehicles.filter(v => `${v.name} ${v.device_id}`.toLowerCase().includes(search.toLowerCase()));
    document.getElementById('vehicle-list').innerHTML = visible.map(v => `<div class="vehicle-row ${selected===v.id?'selected':''}" data-vehicle="${v.id}"><label class="map-vehicle-check"><input type="checkbox" data-map-vehicle="${v.id}" aria-label="Show ${escape(v.name)} on map" ${history.checked.has(v.id)?'checked':''}><span class="route-color" style="background:${routeColor(v.id)}"></span><strong>${escape(v.name)}</strong></label><small>${v.location ? (v.location.older_reading?'Older location reading':'Latest reported location') : 'Awaiting first location'}</small><small>${escape(when(v.location?.reported_at))}</small><small>${escape(jobContext(data,v.id).crew)}</small><small id="route-status-${v.id}">${escape(history.status(v.id))}</small><button type="button" data-detail="${v.id}">Vehicle details</button></div>`).join('') || '<p>No matching vehicles. Search by vehicle name or device ID.</p>';
    document.getElementById('map-count').textContent=`${history.checked.size} of ${data.vehicles.length} vehicles on map. Search filters this list; check a vehicle to add it to the map.`;
    document.getElementById('vehicle-details').innerHTML = `<div><h3>${escape(vehicle.name)}</h3><p>${vehicle.location ? `${vehicle.location.latitude.toFixed(5)}, ${vehicle.location.longitude.toFixed(5)}` : 'No valid location received yet.'}</p></div><div><strong>${escape(ctx.crew)}</strong><p>Current: ${escape(ctx.current?.details.title || 'No scheduled job')}</p><p>Next: ${escape(ctx.next?.details.title || 'No scheduled stop')}</p></div>${demo && vehicle.costs ? `<div class="vehicle-costs"><strong>Illustrative monthly operating budget</strong><p>Fuel: $${vehicle.costs.fuel.toLocaleString()} · Maintenance reserve: $${vehicle.costs.maintenance.toLocaleString()} · Insurance: $${vehicle.costs.insurance.toLocaleString()}</p><p>Example total: $${(vehicle.costs.fuel+vehicle.costs.maintenance+vehicle.costs.insurance).toLocaleString()} USD / month</p><small>Fictional planning amounts, not actual spending or a Bouncie reading.</small></div>` : ''}<div><strong>Provider last reported</strong><p>${escape(when(vehicle.location?.reported_at))}</p>${vehicle.location?.older_reading ? '<p>An older reading can mean the vehicle is parked.</p>' : ''}</div>`;
}
function connectionView() {
    if(demo)return '<div class="card"><h2>Demo Bouncie connection</h2><p>Six fictional vehicles show what the fleet will look like after activation. Connection controls are available in your real workspace.</p></div>';
    const connected = data.connection.status === 'connected';
    return `<div class="card"><h2>${connected ? 'Bouncie connected' : 'Connect your Bouncie account'}</h2><p>${escape(data.connection.label || 'Sign in to the Bouncie account that owns this client’s devices.')}</p>${connecting ? '<div class="banner" role="status"><strong>Finish signing in to Bouncie</strong><p>Complete authorization in the Bouncie window. This page will open vehicle selection when you return.</p><button id="show-bouncie">Return to Bouncie sign-in</button></div>' : ''}
    ${connected ? `<p class="connection-success">✓ Account connected. ${data.vehicles.length ? `${data.vehicles.length} vehicles saved.` : 'Next, choose and save your vehicles below.'}</p><button id="load-devices" class="primary" ${connecting ? 'disabled' : ''}>${devices ? 'Refresh vehicle list' : 'Choose vehicles'}</button><details class="connection-management"><summary>Manage Bouncie connection</summary><div class="actions"><button id="connect" ${connecting ? 'disabled' : ''}>Reconnect Bouncie</button><button id="disconnect" class="danger">Disconnect Bouncie</button></div></details>` : `<p>A Bouncie sign-in window will open. Allow popups for Everbranch, finish signing in, then return here.</p><button id="connect" class="primary" ${!data.subscription.setup_allowed || connecting ? 'disabled' : ''}>${connecting ? 'Waiting for Bouncie…' : 'Connect Bouncie'}</button>`}<p class="notice">Disconnecting or uninstalling Everbranch does not cancel your Bouncie subscription.</p></div>
    ${devices !== null ? `<div class="card" id="vehicle-selection"><h2>Choose up to ${vehicleLimit()} vehicles</h2><p>Select this client’s company vehicles. Your selection is saved only when you continue.</p>${devices.length ? `<form id="device-form"><div class="device-grid">${devices.map(v => `<label class="device"><input type="checkbox" name="devices" value="${escape(v.id)}" ${v.selected?'checked':''}><span>${escape(v.name)}<small>${escape(v.id)}</small></span></label>`).join('')}</div><div class="actions"><button class="primary" type="submit">Save vehicle selection</button><span id="selected-count" role="status"></span></div></form>` : '<h3>No vehicles returned by Bouncie</h3><p>Check that the connected Bouncie account owns your active devices. Then refresh the vehicle list above, or reconnect using the correct account.</p>'}</div>` : ''}`;
}
function settingsView() {
    if(demo)return '<div class="card"><h2>Demo fleet settings</h2><p>100 vehicles per client · 30-day example retention · Free app access. Real policy approval and Bouncie authorization happen in the client workspace.</p></div>';
    return `<div class="card"><h2>Company vehicle policy</h2><p>Confirm your approved tracking policy before collection starts. Employee phone location sharing is excluded from this product.</p><form id="settings-form"><div class="form-grid">
    <label>Retention period<select name="retention_days">${[1,7,14,30].map(n=>`<option value="${n}" ${n===data.settings.retention_days?'selected':''}>${n} days</option>`).join('')}</select></label>
    <label>Policy version<input name="policy_version" required maxlength="80" value="${escape(data.settings.policy_version || new Date().toISOString().slice(0,10))}"></label>
    <label class="wide">Approved policy text<textarea name="policy_text" placeholder="Paste the approved company vehicle tracking policy. A SHA-256 fingerprint will be stored; policy text is not retained here." ${!data.settings.policy_approved?'required':''}></textarea><small>${data.settings.policy_approved?'Leave empty to keep the currently approved fingerprint.':''}</small></label>
    <label class="wide">Approval reference<input name="approval_reference" required maxlength="255" placeholder="Owner authorization reference or policy approval record"></label>
    <label class="checkbox wide"><input type="checkbox" name="approval_confirmed" required><span>I am authorized to approve this company vehicle tracking policy and confirm the required notices and permissions are in place.</span></label>
    <label class="checkbox wide"><input type="checkbox" name="tracking_enabled" ${data.settings.tracking_enabled?'checked':''}><span>Enable company vehicle location collection</span></label></div>
    <div class="actions"><button class="primary" type="submit" ${!data.subscription.setup_allowed?'disabled':''}>Save settings</button><small>After saving, Fleet will show whether collection is active or pilot activation is still needed.</small></div></form></div>${data.subscription.required ? '<div class="card"><h2>Subscription</h2><p>Managed by your agency through the CRM app subscription. Bouncie is billed separately. Removing the app ends Everbranch access; your agency administrator manages app billing and uninstall in the CRM.</p></div>' : '<div class="card"><h2>App access</h2><p>Everbranch Fleet is free for now. No Everbranch app subscription is required. Bouncie is billed separately. Uninstalling the app ends Everbranch access.</p></div>'}`;
}
async function loadDevices() {
    page = 'connection';
    devices = (await api('devices')).devices;
    render();
    document.getElementById('vehicle-selection')?.scrollIntoView({block:'nearest', behavior:'smooth'});
}
async function syncCrm(force = false) {
    if (demo || crmSyncing || !data?.subscription.setup_allowed || (!force && Date.now() - crmCheckedAt < 300000)) return;
    crmSyncing = true;
    data.operations ||= {};
    data.operations.crm_sync = {status:'syncing'};
    updateCrmStatus();
    try {
        const result = await api('operations/sync', 'POST', {type:'automatic', force});
        const fresh = await api('bootstrap');
        if(!data)return;
        data.operations = fresh.operations;
        data.operations.crm_sync = {status:'connected', count:result.count};
        crmError = '';
    } catch (error) {
        crmError = error.message;
        if(data)data.operations.crm_sync = {status:'error', message:crmError};
    } finally {
        crmCheckedAt = Date.now();
        crmSyncing = false;
        if (data && page === 'jobs' && !formDirty) render();
        updateCrmStatus();
    }
}
function updateCrmStatus() {
    const target = document.getElementById('crm-sync-status');
    if (target && data) target.textContent = crmSyncing ? 'Checking this account’s calendars and open opportunities…' : crmError ? `CRM work could not refresh. ${crmError} Saved work is preserved.` : `${data.operations?.jobs?.length || 0} CRM appointments and open opportunities available. ${formDirty ? 'Reopen Jobs & dispatch to see refreshed work.' : ''}`;
}
async function finishBouncie(message) {
    clearInterval(popupTimer);
    connecting = false;
    popup?.close(); popup = null;
    await refresh();
    if (data.connection.status === 'connected') {
        page = 'connection';
        await loadDevices();
        notice('Bouncie connected. Select your vehicles, then save your selection.');
    } else { render(); notice(message || 'Bouncie sign-in was not completed. Your saved setup has not changed.', true); }
}
async function run(action) { if(busy)return; busy=true; try{await action();}catch(error){notice(error.message,true);}finally{busy=false;if(pendingCompletion)completePopup();} }
function completePopup(){
    if(busy||!pendingCompletion)return;
    const message=pendingCompletion;pendingCompletion=null;
    if(message==='EVERBRANCH_BOUNCIE_CONNECTED'){void run(()=>finishBouncie());return;}
    clearInterval(popupTimer);connecting=false;popup?.close();popup=null;render();
    notice('Bouncie sign-in was not completed. Your saved setup has not changed. Choose Connect or Reconnect to try again.',true);
}
function bind() {
    root.querySelector('#content')?.addEventListener('input', event => { if(event.target.closest('form'))formDirty = true; });
    const next = document.getElementById('setup-next');
    if (next) next.onclick = () => run(async () => {
        const step = setupStep(data);
        if (step === 'vehicles') { await loadDevices(); return; }
        page = step === 'settings' ? 'settings' : 'connection'; render();
    });
    const advance=delta=>{tourStep+=delta;if(tourStep>=tour.length){tourStep=-1;}else{page=tour[tourStep][0];}render();document.getElementById('demo-tour')?.scrollIntoView({block:'start'});};
    const tourNext=document.getElementById('tour-next');if(tourNext)tourNext.onclick=()=>advance(1);
    const tourBack=document.getElementById('tour-back');if(tourBack)tourBack.onclick=()=>advance(-1);
    const tourClose=document.getElementById('tour-close');if(tourClose)tourClose.onclick=()=>{tourStep=-1;render();};
    const startTour=document.getElementById('start-tour');if(startTour)startTour.onclick=()=>{tourStep=0;page='fleet';render();};
    const sync = document.getElementById('sync-crm'); if (sync) sync.onclick = () => demo ? notice('Fictional CRM work is already loaded. The live app refreshes this account’s work automatically.') : syncCrm(true);
    updateCrmStatus();
    const toggle=document.getElementById('toggle-demo');if(toggle)toggle.onclick=()=>{if(config.demo){window.location.href='/crm/fleet/guide';return;}window.open('/crm/fleet/demo','_blank','noopener');};
    bindOperations(root,data,api,run,refresh,notice);
    root.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{page=b.dataset.page; if(demo)tourStep=-1; render();});
    const searchInput=document.getElementById('search');
    if(searchInput){searchInput.value=search;searchInput.oninput=()=>{search=searchInput.value;updateList();};document.getElementById('vehicle-list').onclick=e=>{if(e.target.closest('.map-vehicle-check'))return;const b=e.target.closest('[data-vehicle]');if(!b)return;selected=Number(b.dataset.vehicle);updateList();const v=data.vehicles.find(v=>v.id===selected);if(map&&v?.location){map.panTo({lat:v.location.latitude,lng:v.location.longitude});}else if(v?.location){panFallback(v.location);}};}
    const list=document.getElementById('vehicle-list');
    if(list)list.onchange=event=>{const box=event.target.closest('[data-map-vehicle]');if(!box)return;const id=Number(box.dataset.mapVehicle);if(box.checked)history.checked.add(id);else history.checked.delete(id);updateHistory();void history.load(data,api,updateHistory);};
    const results=document.getElementById('show-results');if(results)results.onclick=()=>{data.vehicles.filter(v=>`${v.name} ${v.device_id}`.toLowerCase().includes(search.toLowerCase())).forEach(v=>history.checked.add(v.id));updateList();updateHistory();void history.load(data,api,updateHistory);};
    const hide=document.getElementById('hide-vehicles');if(hide)hide.onclick=()=>{history.checked.clear();updateList();updateHistory();};
    const showRoutes=document.getElementById('show-routes');if(showRoutes)showRoutes.onchange=()=>{history.show=showRoutes.checked;updateHistory();void history.load(data,api,updateHistory);};
    const range=document.getElementById('route-range');if(range)range.onsubmit=event=>{event.preventDefault();const f=new FormData(range);if(f.get('end')<f.get('start')){notice('The end date must follow the start date.',true);return;}formDirty=false;history.range(f.get('start'),f.get('end'));updateHistory();void history.load(data,api,updateHistory);};
    const fit=document.getElementById('fit-vehicles');if(fit)fit.onclick=()=>history.fit(data.map_key?map:leaflet,data,Boolean(data.map_key));
    const connect=document.getElementById('connect');
    if(connect)connect.onclick=()=>{
        if(busy){notice('Please wait for the current action to finish.');return;}
        if (connecting) { popup?.focus(); return; }
        popup=window.open('about:blank','everbranch-bouncie','width=600,height=760');
        if(!popup){notice('Allow pop-up windows for Everbranch to connect Bouncie.',true);return;}
        popup.document.title = 'Connect Bouncie'; popup.document.body.textContent = 'Preparing secure Bouncie sign-in… Keep your Fleet page open.';
        run(async()=>{try{const result=await api('bouncie/connect','POST',{});popup.location.href=result.url;connecting=true;render();popupTimer=setInterval(()=>{if(popup?.closed)run(()=>finishBouncie());},500);}catch(e){popup?.close();popup=null;connecting=false;throw e;}});
    };
    const show = document.getElementById('show-bouncie'); if(show)show.onclick=()=>popup?.focus();
    const load=document.getElementById('load-devices');if(load)load.onclick=()=>run(loadDevices);
    const disconnect=document.getElementById('disconnect');if(disconnect)disconnect.onclick=()=>run(async()=>{if(!window.confirm('Disconnect Bouncie and stop collection? Your Bouncie subscription stays active.'))return;const result=await api('bouncie/disconnect','POST',{});devices=null;await refresh();notice(result.message);});
    const deviceForm=document.getElementById('device-form');
    if(deviceForm){const count=()=>{const n=deviceForm.querySelectorAll('input:checked').length;document.getElementById('selected-count').textContent=`${n} / ${vehicleLimit()} selected`;deviceForm.querySelector('button').disabled=n>vehicleLimit() || (n===0 && data.vehicles.length===0);};deviceForm.onchange=count;count();deviceForm.onsubmit=e=>{e.preventDefault();run(async()=>{await api('devices','PUT',{devices:[...deviceForm.querySelectorAll('input:checked')].map(i=>i.value)});devices=null;await refresh();page=setupStep(data)==='settings'?'settings':'fleet';render();notice(page==='settings'?'Vehicles saved. Next, review your company vehicle policy and collection settings.':'Vehicle selection saved.');});};}
    const form=document.getElementById('settings-form');if(form)form.onsubmit=e=>{e.preventDefault();run(async()=>{const f=new FormData(form),text=String(f.get('policy_text')||'').trim();let hash=data.settings.policy_sha256;if(text){hash=[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(text)))].map(b=>b.toString(16).padStart(2,'0')).join('');}await api('settings','PUT',{retention_days:Number(f.get('retention_days')),policy_version:f.get('policy_version'),policy_sha256:hash,approval_reference:f.get('approval_reference'),approval_confirmed:f.has('approval_confirmed'),tracking_enabled:f.has('tracking_enabled')});page='fleet';await refresh();notice(data.subscription.collection_active?'Settings saved. Fleet is ready for provider readings.':'Settings saved. Contact support for pilot activation; you do not need to reconnect Bouncie.');});};
}
function updateHistory(){
    if(!data || page!=='fleet'||!document.getElementById('map'))return;
    for(const v of data.vehicles){const el=document.getElementById(`route-status-${v.id}`);if(el)el.textContent=history.status(v.id);const box=root.querySelector(`[data-map-vehicle="${v.id}"]`);if(box)box.checked=history.checked.has(v.id);}
    const count=document.getElementById('map-count');if(count)count.textContent=`${history.checked.size} of ${data.vehicles.length} vehicles on map. Search filters this list; check a vehicle to add it to the map.`;
    void drawMap().then(()=>{if(data&&!history.loading&&!history.fitted){history.fit(data.map_key?map:leaflet,data,Boolean(data.map_key));history.fitted=true;}}).catch(()=>{const target=document.getElementById('map');if(target)target.innerHTML='<p>The map could not load. Reported locations remain available in vehicle details.</p>';});
}
async function drawMap() {
    const target=document.getElementById('map');if(!target || !data)return;
    const visible={...data,vehicles:data.vehicles.filter(v=>history.checked.has(v.id))};
    if(!data.map_key){leaflet=drawFallbackMap(target,visible,id=>{selected=id;updateList();});history.drawLeaflet(leaflet);return;}
    if(!mapPromise)mapPromise=new Promise((resolve,reject)=>{window.everbranchFleetMapReady=resolve;const s=document.createElement('script');s.src=`https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(data.map_key)}&callback=everbranchFleetMapReady&loading=async&libraries=marker`;s.async=true;s.referrerPolicy='origin';s.onerror=reject;document.head.appendChild(s);});
    await mapPromise;if(page!=='fleet'||!target.isConnected)return;
    let newMap=false;
    if(!map||map.getDiv()!==target){newMap=true;map=new window.google.maps.Map(target,{center:{lat:39,lng:-98},zoom:4,mapTypeControl:false,streetViewControl:false,...(demo?{mapId:'DEMO_MAP_ID'}:{})});markers=[];}
    markers.forEach(m=>{if(demo)m.map=null;else m.setMap(null);});markers=[];
    const bounds=new window.google.maps.LatLngBounds();
    visible.vehicles.filter(v=>v.location).forEach(v=>{const position={lat:v.location.latitude,lng:v.location.longitude};bounds.extend(position);const pin=demo?new window.google.maps.marker.PinElement({background:v.location.older_reading?'#b98538':'#386b52',borderColor:'#fff',glyphColor:'#fff',glyphText:String(v.id)}):null;const marker=demo?new window.google.maps.marker.AdvancedMarkerElement({map,position,title:v.name,content:pin}):new window.google.maps.Marker({map,position,title:v.name});marker.addListener('click',()=>{selected=v.id;updateList();});markers.push(marker);});
    const vehicleMarkerCount=markers.length;
    (data.operations?.jobs||[]).forEach(j=>{const p=j.details;if(p.latitude==null||p.longitude==null)return;const position={lat:Number(p.latitude),lng:Number(p.longitude)},title=`CRM stop: ${p.title}`;markers.push(demo?new window.google.maps.marker.AdvancedMarkerElement({map,position,title,content:new window.google.maps.marker.PinElement({background:'#5278a8',borderColor:'#fff',glyphColor:'#fff',glyphText:'J',scale:.8})}):new window.google.maps.Marker({map,position,title,icon:{path:window.google.maps.SymbolPath.CIRCLE,scale:5,fillColor:'#5278a8',fillOpacity:1,strokeWeight:1}}));});
    history.drawGoogle(map);
    if(newMap&&vehicleMarkerCount===1){map.setCenter(bounds.getCenter());map.setZoom(14);}else if(newMap&&vehicleMarkerCount>1){map.fitBounds(bounds,45);}
}
async function refresh() {
    data=await api('bootstrap');
    if(page==='fleet' && document.getElementById('map') && data.vehicles.length && (demo || setupStep(data)==='ready')){document.getElementById('status-banner').innerHTML=demo?'':statusBanner();document.getElementById('setup-panel').innerHTML=setupView();document.getElementById('vehicle-total').textContent=`${data.vehicles.length} / ${vehicleLimit()} vehicles`;updateList();await drawMap();}else{render();}
}
window.addEventListener('message',event=>{
    if (event.origin !== window.location.origin || event.source !== popup || !popup) return;
    if(['EVERBRANCH_BOUNCIE_CONNECTED','EVERBRANCH_BOUNCIE_CANCELLED','EVERBRANCH_BOUNCIE_FAILED'].includes(event.data?.message)){pendingCompletion=event.data.message;completePopup();}
});
setInterval(()=>{if(!document.hidden&&!busy&&!connecting&&!formDirty&&data&&!['settings','jobs','trips','maintenance','alerts'].includes(page)&&(page!=='connection'||devices===null))run(refresh);},60000);
setInterval(()=>{if(!document.hidden && data && !busy)void syncCrm();},300000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&data&&!busy&&!connecting&&!formDirty&&(page!=='connection'||devices===null))run(refresh);});
async function start() {
    if (signingIn) return;
    signingIn=true;
    if (!demo) signInView();
    try {
        if(demo){demoData=createDemo();demoData.map_key=config.demoMapKey||null;}else{await authorize();}
        data=await api('bootstrap');
        const step=setupStep(data);
        page=!demo && ['connection','vehicles'].includes(step)?'connection':'fleet';
        render();
        if(!demo){
            if(step==='vehicles')await run(loadDevices);
            void syncCrm();
        }
    } catch(error) {
        token=null;expiresAt=0;
        signInView(error.name==='TimeoutError'?'CRM sign-in took too long. Check your connection and try again.':error.message,true);
    } finally {signingIn=false;}
}
void start();
