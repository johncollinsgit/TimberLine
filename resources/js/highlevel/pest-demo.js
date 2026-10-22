import './fleet.css';
import './pest-demo.css';
import { BRANCHES, TERRITORIES, STORAGE_KEY, createScenario, loadScenario, filteredVehicles, attentionItems, dispatchCandidates, isStale } from './pest-demo-data.js';

const root = document.getElementById('pest-demo');
let storage;
try { storage = window.sessionStorage; } catch { storage = { getItem: () => null, setItem: () => { throw new Error('Unavailable'); }, removeItem: () => {} }; }
const restored = loadScenario(storage);
let data = restored.data;
const state = { page: 'overview', branch: data.settings.defaultBranch, territory: '', crew: '', search: '', listPage: 1,
    selected: 1, selectedJob: 1001, jobPage: 1, attention: '', dispatch: false, tab: '', toast: restored.recovered ? 'Saved demo data could not be read. Fresh fictional examples loaded.' : '' };
let settingsDraft = { ...data.settings }, mapMode = 'groups', modalReturn = null;
const pages = { overview: 'Overview', fleet: 'Fleet map', jobs: 'Jobs & dispatch', trips: 'Trips & routes', maintenance: 'Maintenance', alerts: 'Health alerts', health: 'Connection health', setup: 'Setup', settings: 'Settings' };
const descriptions = {
    overview: 'See what needs attention, then open the truck or job to act.', fleet: 'Find a truck, see its crew, and review its next stop.',
    jobs: 'Match an unassigned visit to a crew with confirmed availability, skills and stock.', trips: 'Review reported miles against an approved baseline.',
    maintenance: 'Keep vehicles ready with service reminders and a clear maintenance history.', alerts: 'Assign attention to battery and check-engine readings.',
    health: 'Separate an older location from a provider-reported disconnect.', setup: 'Rehearse the setup steps before connecting a real client.',
    settings: 'Adjust this demo for your presentation. Preferences stay in this browser tab.',
};
const e = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const button = (text, attributes = '', cls = '') => `<button type="button" ${attributes} class="${cls}">${text}</button>`;
const pill = (text, cls = '') => `<span class="pill ${cls}">${e(text)}</span>`;
const vehicle = id => data.vehicles.find(v => v.id === Number(id));
const job = id => data.jobs.find(j => j.id === Number(id));
const time = minutes => `${Math.floor(minutes / 60) % 12 || 12}:${String(minutes % 60).padStart(2, '0')} ${minutes < 720 ? 'AM' : 'PM'}`;
const miles = n => `${(data.settings.units === 'km' ? n * 1.60934 : n).toLocaleString('en-US', { maximumFractionDigits: 1 })} ${data.settings.units}`;
const visible = () => filteredVehicles(data, state);
const filteredJobs = () => data.jobs.filter(j => (!state.branch || j.branch === state.branch) && (!state.territory || j.territory === state.territory)
    && (!state.crew || vehicle(j.vehicleId)?.crew === state.crew) && (!state.search || `${j.title} ${j.customer} ${vehicle(j.vehicleId)?.name || ''}`.toLowerCase().includes(state.search.toLowerCase())));
const options = (values, selected, all = 'All') => `<option value="">${all}</option>${values.map(v => `<option ${v === selected ? 'selected' : ''} value="${e(v)}">${e(v)}</option>`).join('')}`;
const empty = (text, action = '') => `<div class="empty"><h2>${text}</h2><p>Try another branch, territory, team or search.</p>${action || button('Clear filters', 'data-action="clear"')}</div>`;
function persist(message) {
    try { storage.setItem(STORAGE_KEY, JSON.stringify(data)); state.toast = message; }
    catch { state.toast = `${message} Changes are in memory only; browser storage is unavailable.`; }
}
function navigate(page) {
    state.page = page; state.toast = ''; render();
    document.getElementById('page-title').focus({ preventScroll: true });
    document.getElementById('main-content').scrollIntoView({ block: 'start', behavior: 'instant' });
}
function filters() {
    return `<section class="filters card" aria-label="Fleet filters">
        <label>Branch<select id="filter-branch" aria-label="Branch">${options(BRANCHES, state.branch, 'All branches')}</select></label>
        <label>Territory<select id="filter-territory" aria-label="Territory">${options(TERRITORIES, state.territory, 'All territories')}</select></label>
        <label>Team<select id="filter-crew" aria-label="Team">${options([...new Set(data.vehicles.filter(v => !state.branch || v.branch === state.branch).map(v => v.crew))], state.crew, 'All teams')}</select></label>
        <label class="search-field">Search<input id="filter-search" type="search" placeholder="${state.page === 'jobs' ? 'Visit, customer or truck' : 'Truck, technician or team'}" value="${e(state.search)}"></label>
        ${button('Clear', 'data-action="clear"')}<small class="filter-count">${visible().length} of 250 fictional trucks</small>
    </section>`;
}
function metrics(items) {
    return `<div class="demo-metrics">${items.map(([label, value, action]) => `<button type="button" class="card metric" ${action || ''}><span>${e(label)}</span><strong>${value}</strong><small>View details →</small></button>`).join('')}</div>`;
}
function overview() {
    const vs = visible(), rows = attentionItems(data, vs), due = data.maintenance.filter(m => !m.completed && vs.some(v => v.id === m.vehicleId));
    const shown = state.attention ? rows.filter(r => r.kind === state.attention) : rows;
    return metrics([
        ['Trucks in view', vs.length, 'data-page="fleet"'],
        ['Unassigned visits', rows.filter(r => r.kind === 'Unassigned').length, 'data-attention="Unassigned"'],
        ['Start time passed', rows.filter(r => r.kind === 'Start time passed').length, 'data-attention="Start time passed"'],
        ['Maintenance due', due.length, 'data-page="maintenance"'],
    ]) + `<div class="overview-grid"><section class="card"><div class="section-heading"><div><h2>Needs attention</h2><p>${rows.length} items in the current fleet filters · one truck or job can need several actions.</p></div><label>Show<select id="attention-kind">${options(['Unassigned', 'Start time passed', 'Out of service', 'Device disconnected', 'Older location'], state.attention, 'All attention')}</select></label></div>
        ${shown.length ? `<div class="attention-list">${shown.slice(0, 20).map(r => `<button type="button" class="attention-row" ${r.jobId ? `data-job="${r.jobId}"` : `data-vehicle="${r.vehicleId}"`}><span>${pill(r.kind, r.kind === 'Older location' ? 'warn' : 'error')}<strong>${e(r.title)}</strong><small>${e(r.detail)}</small></span><span aria-hidden="true">→</span></button>`).join('')}</div>${shown.length > 20 ? `<p>Showing the first 20 of ${shown.length}. Choose an attention type or narrow the fleet filters.</p>` : ''}` : '<div class="empty"><h3>No attention items in this view</h3><p>Other branches may still have items to review.</p></div>'}</section>
        <aside><section class="card story-card"><span class="eyebrow">Try the dispatcher workflow</span><h2>A callback just came in</h2><p>Find a termite-capable crew with the right stock and a free service window. Review the reasons, make a demo assignment, then preview the customer message.</p>${button('Open urgent callback', 'data-job="1001"', 'primary')}</section><section class="card"><h2>Read the signals correctly</h2><p>Start time passed means completion is unconfirmed. An older location can mean a parked truck. Neither is proof of a missed visit or misuse.</p>${button('Review connection health', 'data-page="health"')}</section></aside></div>`;
}
function illustration(vs) {
    const useGroups = mapMode === 'groups' && vs.length > 25;
    const groups = state.branch ? TERRITORIES.map((name, i) => ({ name, vs: vs.filter(v => v.territory === name), x: [26, 72, 64, 30][i], y: [24, 35, 70, 68][i], type: 'territory' }))
        : BRANCHES.map((name, i) => ({ name, vs: vs.filter(v => v.branch === name), x: [26, 72, 64, 30][i], y: [24, 35, 70, 68][i], type: 'branch' }));
    const selected = vehicle(state.selected), pins = useGroups ? groups.filter(g => g.vs.length).map(g => `<button type="button" class="map-cluster" data-${g.type}="${g.name}" style="left:${g.x}%;top:${g.y}%" aria-label="Open ${g.name}, ${g.vs.length} trucks"><strong>${g.vs.length}</strong><span>${e(g.name)}</span></button>`).join('')
        : vs.slice((state.listPage - 1) * 25, state.listPage * 25).map(v => `<button type="button" class="map-pin ${v.id === state.selected ? 'selected' : ''} ${v.outOfService || v.disconnected ? 'problem' : isStale(v, data.settings) ? 'older' : ''}" style="left:${v.x}%;top:${v.y}%" data-vehicle="${v.id}" aria-label="Open ${v.name}">${v.id}</button>`).join('');
    return `<div class="illustrative-map" role="group" aria-label="Illustrative fictional fleet map"><svg viewBox="0 0 1000 510" preserveAspectRatio="none" aria-hidden="true"><rect width="1000" height="510" fill="#edf2e9"/><path d="M0 200 Q200 50 400 260 T1000 220" stroke="#d2e4e3" stroke-width="44" fill="none"/><g stroke="#fff" stroke-width="12" fill="none"><path d="M-20 390 L1030 100 M150 -20 L700 540 M-20 90 L1030 430 M500 -20 L350 540"/><path d="M0 310 L1000 310 M190 0 L190 510 M820 0 L820 510" stroke-width="5"/></g><g fill="#dce7d6"><rect x="35" y="40" width="95" height="65" rx="15"/><rect x="850" y="355" width="110" height="95" rx="15"/><rect x="475" y="365" width="90" height="65" rx="15"/></g></svg>${pins}
        ${useGroups && selected && vs.some(v => v.id === selected.id) ? `<div class="map-selection">Selected: ${e(selected.name)} · ${e(selected.branch)} / ${e(selected.territory)}</div>` : ''}
        <span class="map-caption">Illustrative map · fictional positions · no road routing</span></div>`;
}
function fleet() {
    const vs = visible(), total = Math.ceil(vs.length / 25); state.listPage = Math.min(state.listPage, Math.max(total, 1));
    const current = vs.slice((state.listPage - 1) * 25, state.listPage * 25);
    const v = current.find(v => v.id === state.selected) || current[0]; if (v) state.selected = v.id;
    if (!vs.length) return `<section class="card">${empty('No trucks match these filters')}</section>`;
    const next = data.jobs.find(j => j.vehicleId === v.id && j.status !== 'completed');
    return `<div class="fleet-layout"><section class="card fleet-list"><h2>Trucks <span class="subtle">${vs.length}</span></h2><div class="scroll-list">${current.map(v => `<button type="button" class="vehicle-row ${v.id === state.selected ? 'selected' : ''}" data-vehicle="${v.id}" aria-pressed="${v.id === state.selected}"><strong>${e(v.name)} · ${e(v.crew)}</strong><small>${e(v.branch)} / ${e(v.territory)}</small><small>${v.outOfService ? 'Out of service' : v.disconnected ? 'Device disconnected' : isStale(v, data.settings) ? 'Older location reading' : 'Recent example reading'}</small></button>`).join('')}</div>${pagination(state.listPage, total, 'truck')}</section>
        <section class="card map-card"><div class="map-toolbar"><h2>Fleet by ${state.branch ? 'territory' : 'branch'}</h2>${button(mapMode === 'groups' ? 'Show page pins' : 'Group trucks', 'data-action="map-mode"')}</div>${illustration(vs)}<div class="vehicle-panel"><div class="section-heading"><div><span class="eyebrow">Selected truck</span><h2>${e(v.name)}</h2><p>${e(v.branch)} / ${e(v.territory)} · ${e(v.crew)} · ${e(v.technician)}</p></div>${pill(v.outOfService ? 'Out of service' : v.disconnected ? 'Device disconnected' : 'Demo vehicle', v.outOfService || v.disconnected ? 'error' : '')}</div>
        <div class="detail-grid"><div><strong>Last example location</strong><p>${v.ageMinutes} minutes before the scenario snapshot. ${isStale(v, data.settings) ? 'An older reading can mean a parked truck.' : ''}</p></div><div><strong>Next scheduled stop</strong><p>${next ? `${e(next.title)} · ${time(next.start)}–${time(next.end)}` : 'No remaining example stop'}</p>${next ? button('Open job', `data-job="${next.id}"`) : ''}</div><div><strong>Crew readiness</strong><p>${e(v.skills.join(', '))}<br>${v.stock.traps} traps · ${v.stock['bait stations']} bait stations<br>${v.capacity} confirmed spare stop slots</p><small>Manager-maintained examples, not live availability.</small></div></div><div class="actions">${button('View maintenance', `data-maintenance="${v.id}"`)}${next ? button('Preview customer update', `data-message="${next.id}"`) : ''}</div></div></section></div>`;
}
function pagination(page, total, kind) {
    return `<div class="pagination" aria-label="${kind} pages">${button('Previous', `data-pager="${kind}" data-delta="-1" ${page <= 1 ? 'disabled' : ''}`)}<span>Page ${page} of ${Math.max(total, 1)}</span>${button('Next', `data-pager="${kind}" data-delta="1" ${page >= total ? 'disabled' : ''}`)}</div>`;
}
function jobs() {
    const rows = filteredJobs(), total = Math.ceil(rows.length / 10); state.jobPage = Math.min(state.jobPage, Math.max(total, 1));
    const current = rows.slice((state.jobPage - 1) * 10, state.jobPage * 10), selected = current.find(j => j.id === state.selectedJob) || current[0];
    if (!selected) return `<section class="card">${empty('No jobs match these filters')}</section>`;
    state.selectedJob = selected.id;
    return `<div class="jobs-layout"><section class="card job-list"><div class="section-heading"><h2>Example visits</h2>${pill(`${rows.length} jobs`)}</div><div class="scroll-list">${current.map(j => `<button type="button" class="vehicle-row ${j.id === selected.id ? 'selected' : ''}" data-job="${j.id}"><strong>${e(j.title)}</strong><small>${e(j.customer)} · ${e(j.branch)}</small><small>${time(j.start)}–${time(j.end)} · ${j.vehicleId ? e(vehicle(j.vehicleId).name) : 'Unassigned'}${j.status === 'completed' ? ' · Completed example' : ''}</small></button>`).join('')}</div>${pagination(state.jobPage, total, 'job')}</section><section class="card job-detail"><span class="eyebrow">Fictional CRM work reference DEMO-${selected.id}</span><h2>${e(selected.title)}</h2><p>${e(selected.customer)} · ${e(selected.branch)} / ${e(selected.territory)}</p><div class="detail-grid"><div><strong>Service window</strong><p>${time(selected.start)}–${time(selected.end)} Eastern<br>${selected.capacity} stop slot required</p></div><div><strong>Required skill & stock</strong><p>${e(selected.skills.join(', '))}<br>${e(Object.entries(selected.stock).map(([k, n]) => `${n} ${k}`).join(', '))}</p></div></div><div class="context-note"><strong>Customer & visit context</strong><p>${e(selected.previousVisit)}<br>${e(selected.note)}</p><small>Fictional customer and service context; no real contact details.</small></div><p><strong>Assigned truck:</strong> ${selected.vehicleId ? e(vehicle(selected.vehicleId).name) : 'Unassigned'}</p><div class="actions">${button('Suggest crews', 'data-action="dispatch"', 'primary')}${button('Preview customer message', `data-message="${selected.id}"`)}</div>${state.dispatch ? dispatchView(selected) : '<p class="notice">Suggestions explain availability, skills, stock, time window and stop capacity. You choose the crew.</p>'}</section></div>`;
}
function dispatchView(j) {
    const all = dispatchCandidates(data, j), eligible = all.filter(c => !c.reasons.length), reasons = {};
    all.flatMap(c => c.reasons).forEach(r => reasons[r] = (reasons[r] || 0) + 1);
    return `<section class="dispatch-results" aria-label="Dispatch suggestions"><h3>${eligible.length} eligible crews in ${e(j.branch)}</h3><p>Illustrative straight-line distance; no road ETA or route optimization. Availability, skills, stock and spare stop slots are manager-maintained. Confirm before real dispatch.</p>${eligible.length ? eligible.slice(0, 3).map(c => `<div class="candidate"><div><strong>${e(c.vehicle.name)} · ${e(c.vehicle.crew)}</strong><p>${miles(c.miles)} straight-line · ${c.vehicle.capacity} spare stop slots<br>✓ Skills & stock match · ✓ No overlapping example visit</p></div>${button('Assign in demo', `data-assign="${c.vehicle.id}"`, 'primary')}</div>`).join('') : '<p>No crew meets all confirmed requirements. Keep the job unassigned until availability is confirmed.</p>'}<details><summary>Why other crews were excluded</summary><ul>${Object.entries(reasons).map(([r, n]) => `<li>${e(r)}: ${n}</li>`).join('')}</ul><small>A crew can have more than one exclusion.</small></details></section>`;
}
function maintenance() {
    const ids = new Set(visible().map(v => v.id)), due = data.maintenance.filter(m => !m.completed && ids.has(m.vehicleId));
    return `<section class="card"><div class="section-heading"><div><h2>Service due</h2><p>Vehicle maintenance, separate from pest-treatment records.</p></div>${pill(`${due.length} due`, 'warn')}</div>${due.length ? due.map(m => `<div class="record-row"><div><strong>${e(vehicle(m.vehicleId).name)} · ${e(m.title)}</strong><p>${e(m.due)} · Example odometer ${miles(vehicle(m.vehicleId).odometer)}</p></div>${button('Record demo service', `data-service="${m.id}"`)}</div>`).join('') : '<p>No due service in this view.</p>'}</section><section class="card"><h2>Completed service history</h2>${data.serviceHistory.filter(m => ids.has(m.vehicleId)).map(m => `<div class="record-row"><div><strong>${e(vehicle(m.vehicleId).name)} · ${e(m.title)}</strong><p>${e(m.notes)}</p></div>${pill('Recorded in demo')}</div>`).join('') || '<p>No new service recorded in this demo. Example previous service: Truck 002 · oil and filter · 5,000 miles ago.</p>'}<p class="notice">Recording service here does not return an out-of-service truck to operation or clear a diagnostic fault.</p></section>`;
}
function alerts() {
    const ids = new Set(visible().map(v => v.id)), rows = data.alerts.filter(a => ids.has(a.vehicleId));
    return `<section class="card"><h2>Vehicle health alerts</h2><p>Example provider readings. Resolution records a manager’s action; it does not clear a hardware fault.</p>${rows.length ? rows.map(a => `<div class="record-row"><div>${pill(a.status, a.status === 'resolved' ? '' : 'warn')}<strong>${e(vehicle(a.vehicleId).name)} · ${e(a.title)}</strong><p>${a.status === 'resolved' ? e(a.notes) : 'Assigned to Demo fleet manager · action needed'}</p></div>${a.status !== 'resolved' ? button('Review demo alert', `data-alert="${a.id}"`) : ''}</div>`).join('') : '<p>No example alerts in this view.</p>'}</section>`;
}
function health() {
    const vs = visible();
    return `<section class="card"><div class="section-heading"><div><h2>Connection health</h2><p>${data.settings.providerUnavailable ? 'Simulated provider outage: saved examples remain visible.' : 'Demo readings only. No Bouncie account is connected.'}</p></div>${button('See connection steps', 'data-page="setup"')}</div><div class="table-wrap health-table"><table><caption class="sr-only">Fictional connection readings for filtered vehicles</caption><thead><tr><th>Truck</th><th>Location age</th><th>Device signal</th><th>Action</th></tr></thead><tbody>${vs.slice(0, 50).map(v => `<tr><td>${e(v.name)}</td><td>${v.ageMinutes} minutes · ${isStale(v, data.settings) ? 'Older reading' : 'Recent example'}</td><td>${v.disconnected ? 'Disconnected example' : 'No disconnect reported'}</td><td>${button('Open truck', `data-vehicle="${v.id}"`)}</td></tr>`).join('')}</tbody></table></div><p>Showing ${Math.min(50, vs.length)} of ${vs.length} filtered trucks. Narrow by branch or territory to review the rest. Older readings alone do not mean a device is offline.</p></section>`;
}
function trips() {
    const rows = visible().slice(0, 12);
    return `<section class="card"><h2>Trip history & route review</h2><p>Fictional trips, with an example approved road-distance baseline. A review flag requests a human check; it does not establish misuse.</p>${rows.map((v, i) => `<details class="ops-item"><summary>${e(v.name)} · ${miles(i % 3 === 0 ? 22 + i : 13 + i)} · ${pill(i % 3 === 0 ? 'Review needed' : 'Within example allowance', i % 3 === 0 ? 'warn' : '')}</summary><p>Approved example baseline: ${miles(12 + i)} · allowance: greater of ${miles(5)} or 30%.<br>Recorded example drive time: ${32 + i} minutes · idle: ${4 + i} minutes. No arrival ETA.</p><svg class="example-path" viewBox="0 0 600 100" role="img" aria-label="Illustrative fictional trip path, not a road trace"><path d="M20 75 L100 30 L210 65 L320 25 L430 65 L570 20" stroke="#216347" stroke-width="5" fill="none" stroke-dasharray="9 4"/><circle cx="20" cy="75" r="8" fill="#426e9c"/><circle cx="570" cy="20" r="8" fill="#216347"/></svg><p>${data.reviews[v.id] ? `Demo review: ${e(data.reviews[v.id])}` : 'No review decision recorded.'}</p>${button('Record demo review', `data-trip="${v.id}"`)}</details>`).join('') || '<p>No trucks match the current filters.</p>'}<p class="notice">Showing up to 12 example trips in this view. This is an illustrative path, not received GPS or a verified road route.</p></section>`;
}
const setupSteps = [
    ['Install for the right client', 'In a real setup, the agency owner/admin installs the private app to the intended client location. Open Everbranch from that client’s sidebar. Developer-portal access alone does not install it.', 'Rehearse installation'],
    ['Connect the client’s Bouncie account', 'A real administrator authorizes Bouncie in a top-level popup. Bouncie has its own subscription. This rehearsal opens no provider, requests no credentials and grants no access.', 'Simulate connection'],
    ['Choose company vehicles', 'Select devices from the authorized account. A device belongs to one active workspace. The live app currently allows 100 selected vehicles per client; these 250 examples are a UI rehearsal only.', 'Rehearse vehicle selection'],
    ['Review policy and retention', 'The real client records the approved company-vehicle policy, owner approval reference and 1–30-day retention. No personal or employee-phone tracking is part of this demo.', 'Rehearse policy review'],
    ['Verify activation and CRM work', 'Real collection requires operator pilot activation plus the existing tracking, entitlement and policy gates. Jobs additionally need calendar, calendar-event and opportunity read permissions and the correct work source.', 'Rehearse readiness check'],
];
function setup() {
    return `<div class="setup-grid"><section class="card"><h2>Five clear steps</h2><p>${data.setup.filter(Boolean).length} of 5 rehearsal steps complete. Exploring the demo never requires setup.</p>${setupSteps.map(([title, text, action], i) => `<div class="setup-step"><span class="step-number">${data.setup[i] ? '✓' : i + 1}</span><div><h3>${title}</h3><p>${text}</p>${button(data.setup[i] ? 'Rehearsed in this tab' : action, `data-setup="${i}" ${i > 0 && !data.setup[i - 1] ? 'disabled' : ''}`, data.setup[i] ? '' : 'primary')}</div></div>`).join('')}</section><aside><section class="card"><span class="eyebrow">Current state</span><h2>Demo, not connected</h2><p>250 fictional trucks, example jobs and illustrative positions. No live CRM installation, provider authorization, billing or tracking change happens here.</p>${pill(data.setup.every(Boolean) ? 'Rehearsal complete' : 'Rehearsal available')}</section><section class="card"><h2>If real setup is blocked</h2><p><strong>App missing?</strong> Check the selected client location and its installation.</p><p><strong>No CRM work?</strong> Verify v2 read permissions, then select a calendar or pipeline.</p><p><strong>No locations?</strong> Check Bouncie, device selection, approved policy and pilot activation.</p><p><strong>Popup blocked?</strong> Allow the Bouncie authorization popup for the app.</p></section></aside></div>`;
}
function settings() {
    return `<div class="setup-grid"><section class="card"><h2>Presentation preferences</h2><p>Saved only in this browser tab. They do not change any client settings.</p><form id="demo-settings" class="form-grid"><label>Distance units<select name="units" id="settings-units" aria-label="Distance units"><option value="mi" ${settingsDraft.units === 'mi' ? 'selected' : ''}>Miles</option><option value="km" ${settingsDraft.units === 'km' ? 'selected' : ''}>Kilometers</option></select></label><label>Older-reading threshold<select name="staleMinutes" id="settings-stale" aria-label="Older-reading threshold"><option value="15" ${settingsDraft.staleMinutes === 15 ? 'selected' : ''}>15 minutes</option><option value="30" ${settingsDraft.staleMinutes === 30 ? 'selected' : ''}>30 minutes</option><option value="60" ${settingsDraft.staleMinutes === 60 ? 'selected' : ''}>60 minutes</option></select></label><label>Default branch on reload<select name="defaultBranch" id="settings-branch" aria-label="Default branch on reload">${options(BRANCHES, settingsDraft.defaultBranch, 'All branches')}</select></label><label class="checkbox"><input type="checkbox" name="providerUnavailable" id="settings-outage" ${settingsDraft.providerUnavailable ? 'checked' : ''}> Simulate a provider outage while keeping saved examples visible</label><div class="wide actions"><button type="submit" class="primary">Save demo preferences</button><span>Unsaved choices stay available while you explore.</span></div></form></section><aside><section class="card"><h2>What stays fixed</h2><p>Scenario snapshot: 10:10 AM Eastern.<br>250 synthetic trucks in four fictional branches.<br>Capacity, skills and stock are example manager entries.</p><p>The live 100-vehicle limit, permissions, billing and activation gates are unchanged. This demonstration does not establish 250-vehicle production capacity.</p></section><section class="card"><h2>Start over safely</h2><p>Reset preferences, assignments, reviews and setup progress in this demo tab. No client records are affected.</p>${button('Reset demo', 'data-action="reset"', 'danger')}</section></aside></div>`;
}
function render() {
    const focusId = document.activeElement?.id, start = document.activeElement?.selectionStart;
    const focusedButton = document.activeElement?.tagName === 'BUTTON' ? { ...document.activeElement.dataset } : null;
    const navScroll = root.querySelector('.tabs')?.scrollLeft || 0;
    const views = { overview, fleet, jobs, trips, maintenance, alerts, health, setup, settings };
    root.innerHTML = `<a class="skip-link" href="#main-content">Skip to content</a><main class="shell pest-shell"><header class="topbar"><div class="brand">Everbranch <span>Fleet</span></div><div class="account"><strong>Green Shield Pest · fictional company</strong><span>Dispatcher preview · 250 synthetic trucks</span></div>${button('Setup guide', 'data-page="setup"')}</header><div class="demo-boundary"><strong>DEMO · Fictional fleet data</strong><span>Changes stay in this browser tab. No messages, provider calls or customer updates.</span></div><nav class="tabs" aria-label="Fleet navigation">${Object.entries(pages).map(([key, label]) => `<button type="button" data-page="${key}" class="${state.page === key ? 'active' : ''}" ${state.page === key ? 'aria-current="page"' : ''}>${label}</button>`).join('')}</nav><section id="main-content" tabindex="-1"><div class="heading"><div><span class="eyebrow">${state.branch ? e(state.branch) : 'All branches'} · Scenario snapshot 10:10 AM Eastern</span><h1 id="page-title" tabindex="-1">${pages[state.page]}</h1><p>${descriptions[state.page]}</p></div>${pill('250-truck UI rehearsal')}</div>${data.settings.providerUnavailable ? '<div class="banner error"><strong>Simulated provider outage</strong><p>Saved fictional readings remain visible. No provider request was attempted.</p></div>' : ''}${!['setup', 'settings'].includes(state.page) ? filters() : ''}<div id="demo-view">${views[state.page]()}</div></section><footer class="footer"><span>Illustrative scenarios · no live GPS, road ETA or automated dispatch</span><span>Live app: 100 selected vehicles per client · scaling needs separate validation</span></footer></main><div id="demo-status" class="toast ${state.toast ? 'shown' : ''}" role="status">${e(state.toast)}</div><dialog id="demo-dialog" aria-labelledby="dialog-title"></dialog>`;
    const tabs = root.querySelector('.tabs'); tabs.scrollLeft = navScroll;
    const activeTab = tabs.querySelector('[aria-current="page"]');
    const tabRect = activeTab.getBoundingClientRect(), navRect = tabs.getBoundingClientRect();
    if (tabRect.right > navRect.right) tabs.scrollLeft += tabRect.right - navRect.right;
    if (tabRect.left < navRect.left) tabs.scrollLeft -= navRect.left - tabRect.left;
    const focus = focusId && document.getElementById(focusId);
    if (focus) { focus.focus({ preventScroll: true }); if (typeof start === 'number' && focus.type === 'search') focus.setSelectionRange(start, start); }
    else if (focusedButton && Object.keys(focusedButton).length) {
        [...root.querySelectorAll('button')].find(b => Object.entries(focusedButton).every(([key, value]) => b.dataset[key] === value))?.focus({ preventScroll: true });
    }
}
function openDialog(title, body) {
    modalReturn = document.activeElement;
    const dialog = document.getElementById('demo-dialog');
    dialog.innerHTML = `<div class="section-heading"><h2 id="dialog-title">${title}</h2>${button('Close', 'data-action="close-dialog"')}</div>${body}`;
    dialog.addEventListener('close', () => modalReturn?.isConnected && modalReturn.focus(), { once: true });
    dialog.showModal();
}
function reviewDialog(kind, id) {
    const title = { service: 'Record completed demo service', alert: 'Resolve demo alert', trip: 'Record route review' }[kind];
    openDialog(title, `<p>This changes fictional examples in this browser tab only.</p><form id="review-form" data-kind="${kind}" data-id="${id}"><label for="review-note">${kind === 'service' ? 'Service notes / example invoice reference' : 'Action taken / review reason'}</label><textarea id="review-note" name="note" required maxlength="500" placeholder="Use fictional details only"></textarea><p>Use fictional details only. No provider, customer or hardware state will change.</p><button type="submit" class="primary">Save demo ${kind === 'service' ? 'service' : 'review'}</button></form>`);
}
root.addEventListener('click', event => {
    const b = event.target.closest('button'); if (!b || b.disabled) return;
    if (b.dataset.page) return navigate(b.dataset.page);
    if (b.dataset.action === 'clear') { Object.assign(state, { branch: '', territory: '', crew: '', search: '', listPage: 1, jobPage: 1 }); return render(); }
    if (b.dataset.attention) { state.attention = b.dataset.attention; render(); document.getElementById('attention-kind').focus(); return; }
    if (b.dataset.branch || b.dataset.territory) { state[b.dataset.branch ? 'branch' : 'territory'] = b.dataset.branch || b.dataset.territory; state.listPage = 1; return render(); }
    if (b.dataset.vehicle) { state.selected = Number(b.dataset.vehicle); state.listPage = Math.floor(visible().findIndex(v => v.id === state.selected) / 25) + 1; if (state.page !== 'fleet') return navigate('fleet'); return render(); }
    if (b.dataset.job) {
        state.selectedJob = Number(b.dataset.job); state.dispatch = false;
        const j = job(state.selectedJob);
        // Attention links must remain reachable even when a prior filter hides their target.
        if (!filteredJobs().some(row => row.id === j.id)) Object.assign(state, { branch: j.branch, territory: '', crew: '', search: '' });
        state.jobPage = Math.floor(filteredJobs().findIndex(row => row.id === j.id) / 10) + 1;
        if (state.page !== 'jobs') return navigate('jobs'); return render();
    }
    if (b.dataset.maintenance) { state.search = vehicle(b.dataset.maintenance).name; return navigate('maintenance'); }
    if (b.dataset.pager) { state[b.dataset.pager === 'truck' ? 'listPage' : 'jobPage'] += Number(b.dataset.delta); return render(); }
    if (b.dataset.action === 'map-mode') { mapMode = mapMode === 'groups' ? 'pins' : 'groups'; return render(); }
    if (b.dataset.action === 'dispatch') { state.dispatch = true; return render(); }
    if (b.dataset.assign) {
        const j = job(state.selectedJob), v = vehicle(b.dataset.assign);
        if (!dispatchCandidates(data, j).some(c => c.vehicle.id === v.id && !c.reasons.length)) { state.toast = 'Availability changed. Review the suggestions again.'; return render(); }
        j.vehicleId = v.id; persist(`Demo assignment saved: ${v.name}. No CRM update or message sent.`); return render();
    }
    if (b.dataset.message) {
        const j = job(b.dataset.message), v = vehicle(j.vehicleId);
        return openDialog('Customer message preview', `${pill('Preview only · not sent', 'warn')}<p>Fictional recipient: ${e(j.customer)}. No phone number or delivery destination.</p><blockquote>Hello ${e(j.customer)}, your ${e(j.title.toLowerCase())} is scheduled for ${time(j.start)}–${time(j.end)} Eastern.${v ? ` Your example service team is ${e(v.crew)}.` : ' Your service team will be confirmed by the office.'} Please contact the office with access instructions.</blockquote><p>This is a scheduled window, not a calculated arrival ETA. This demo has no send action.</p>`);
    }
    if (b.dataset.service || b.dataset.alert || b.dataset.trip) return reviewDialog(b.dataset.service ? 'service' : b.dataset.alert ? 'alert' : 'trip', Number(b.dataset.service || b.dataset.alert || b.dataset.trip));
    if (b.dataset.setup !== undefined) {
        const i = Number(b.dataset.setup); if (i > 0 && !data.setup[i - 1]) return;
        data.setup[i] = true; persist('Setup step rehearsed. No real installation, connection or permission changed.'); return render();
    }
    if (b.dataset.action === 'close-dialog') return document.getElementById('demo-dialog').close();
    if (b.dataset.action === 'reset') return openDialog('Reset this demo?', `<p>This clears this tab’s fictional assignments, reviews, preferences and rehearsal steps. Client records are unaffected.</p><div class="actions">${button('Cancel', 'data-action="close-dialog"')}${button('Reset fictional demo', 'data-action="confirm-reset"', 'danger')}</div>`);
    if (b.dataset.action === 'confirm-reset') {
        data = createScenario(); settingsDraft = { ...data.settings }; Object.assign(state, { page: 'overview', branch: '', territory: '', crew: '', search: '', listPage: 1, jobPage: 1, attention: '', dispatch: false, selectedJob: 1001 });
        persist('Demo reset. Original fictional examples restored.'); render(); document.getElementById('page-title').focus();
    }
});
root.addEventListener('change', event => {
    const el = event.target;
    if (el.id.startsWith('filter-')) { state[el.id.replace('filter-', '')] = el.value; if (el.id === 'filter-branch') state.crew = ''; state.listPage = 1; state.jobPage = 1; render(); }
    if (el.id === 'attention-kind') { state.attention = el.value; render(); }
    if (el.closest('#demo-settings')) settingsDraft = Object.fromEntries(new FormData(document.getElementById('demo-settings')));
    if (el.closest('#demo-settings')) { settingsDraft.staleMinutes = Number(settingsDraft.staleMinutes); settingsDraft.providerUnavailable = el.form.elements.providerUnavailable.checked; }
});
root.addEventListener('input', event => {
    if (event.target.id === 'filter-search') { state.search = event.target.value; state.listPage = 1; state.jobPage = 1; render(); }
});
root.addEventListener('submit', event => {
    event.preventDefault(); const form = event.target, fields = new FormData(form);
    if (form.id === 'demo-settings') {
        data.settings = { units: fields.get('units'), staleMinutes: Number(fields.get('staleMinutes')), defaultBranch: fields.get('defaultBranch'), providerUnavailable: fields.has('providerUnavailable') };
        settingsDraft = { ...data.settings }; persist('Demo preferences saved for this browser tab.'); render();
    }
    if (form.id === 'review-form') {
        const note = String(fields.get('note') || '').trim(); if (!note) { form.elements.note.setCustomValidity('Enter a fictional action or review reason.'); form.elements.note.reportValidity(); return; }
        const id = Number(form.dataset.id), kind = form.dataset.kind;
        if (kind === 'service') { const m = data.maintenance.find(m => m.id === id); if (!m.completed) { m.completed = true; m.notes = note; data.serviceHistory.push({ vehicleId: m.vehicleId, title: m.title, notes: note }); } }
        if (kind === 'alert') { const a = data.alerts.find(a => a.id === id); a.status = 'resolved'; a.notes = note; }
        if (kind === 'trip') data.reviews[id] = note;
        persist('Demo review saved. No live record or provider state changed.'); render(); document.getElementById('page-title').focus({ preventScroll: true });
    }
});
root.addEventListener('input', event => { if (event.target.id === 'review-note') event.target.setCustomValidity(''); });
try { render(); } catch { root.innerHTML = '<div class="launch-card"><h1>Unable to open the demo</h1><p>Reload this preview to try again. No client connection or provider action was attempted.</p><button type="button" onclick="location.reload()">Reload preview</button></div>'; }
