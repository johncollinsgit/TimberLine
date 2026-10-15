import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './fleet-map.css';

let activeRoot = null, dispose = null;
function mount() {
    const root = document.querySelector('#selected-fleet');
    if (!root || root === activeRoot) return;
    dispose?.(); activeRoot = root;
    dispose = startFleetMap(root, JSON.parse(document.querySelector('#selected-fleet-data').textContent));
}
mount();
document.addEventListener('livewire:navigated', mount);
document.addEventListener('livewire:navigating', () => {dispose?.(); dispose=null; activeRoot=null;});

export function startFleetMap(root, initial) {
    const $ = selector => root.querySelector(selector);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let data = initial, period = 'today', range = {...initial.range}, selected = new Set(), histories = new Map();
    let focus = null, generation = 0, initiallyFit = false, userMoved = false, drawing = false, expired = false;
    let requests = new Map(), refreshing = false, initialHistoryFit = true, closed = false;
    const snapshotController = new AbortController();
    const listeners = new AbortController();
    try {
        const saved = JSON.parse(localStorage.getItem(data.selection_key));
        selected = new Set(Array.isArray(saved) ? saved.filter(Number.isInteger) : data.vehicles.map(v => v.id));
    } catch { selected = new Set(data.vehicles.map(v => v.id)); }
    const save = () => { try { localStorage.setItem(data.selection_key, JSON.stringify([...selected])); } catch { /* Selection still works without storage. */ } };
    const statusText = v => ({unmapped:'Tracker missing', disconnected:'Tracking disconnected', disabled:'Tracking paused', unavailable:'Location unavailable', stale:'Location stale', available:v.motion}[v.status] || 'Status unknown');
    const date = value => value ? new Intl.DateTimeFormat('en-US', {timeZone:range.timezone, month:'short', day:'numeric', hour:'numeric', minute:'2-digit', timeZoneName:'short'}).format(new Date(value)) : 'Not reported';
    const updated = v => v.location ? `${v.location.source === 'provider_snapshot' ? 'Provider record updated' : 'GPS updated'} ${v.location.age_seconds<60?`${v.location.age_seconds}s ago`:`${Math.floor(v.location.age_seconds/60)}m ago`} · ${date(v.location.recorded_at)}` : 'No retained location';
    const chosen = () => data.vehicles.filter(v => selected.has(v.id));
    const notice = message => { $('[data-fleet-notice]').textContent = message; };
    const map = L.map($('[data-fleet-map]'), {scrollWheelZoom:false}).setView([39,-98], 4);
    const layers = L.layerGroup().addTo(map);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, referrerPolicy:'origin', attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'})
        .on('tileerror', () => { $('[data-fleet-tiles]').hidden = false; }).addTo(map);
    map.on('movestart zoomstart', () => { if (!drawing) userMoved = true; });
    const observer = new ResizeObserver(() => map.invalidateSize({pan:false}));
    observer.observe($('[data-fleet-map]'));
    let bounds = [];
    function fit() {
        if (!bounds.length) return;
        drawing = true;
        map.fitBounds(bounds, {padding:[42,42], maxZoom:15, animate:false});
        drawing = false;
    }
    function draw() {
        layers.clearLayers(); bounds = [];
        for (const v of chosen()) {
            if (v.location) {
                const point = [v.location.latitude, v.location.longitude]; bounds.push(point);
                const icon = L.divIcon({className:'fleet-van-marker', iconSize:[36,36], iconAnchor:[18,18],
                    html:`<span style="--van-color:${escape(v.color)}" class="${v.location.stale?'is-stale':''}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h12l5 6v6h-2a3 3 0 0 1-6 0H9a3 3 0 0 1-6 0H2V8a2 2 0 0 1 1-2Zm12 2v4h3l-3-4ZM6 16a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm9 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg></span>`});
                L.marker(point, {icon, alt:v.name, title:v.name}).addTo(layers).bindTooltip(escape(v.name), {direction:'top',permanent:true,offset:[0,-16]})
                    .bindPopup(`<strong>${escape(v.name)}</strong><br>${escape(statusText(v))}<br>${escape(updated(v))}${v.location.source==='provider_snapshot'?'<br>Last-known position; GPS fix time unavailable.':''}`)
                    .on('click', () => highlight(v.id));
            }
            const h = histories.get(v.id);
            for (const trip of h?.trips || []) {
                const active = !focus || (focus.vehicle === v.id && (!focus.trip || focus.trip === trip.id));
                for (const [index,segment] of trip.segments.entries()) {
                    bounds.push(...segment);
                    // Two points alone are insufficient evidence for a road-level path.
                    if (trip.trace_segments?.[index] ?? segment.length > 2) L.polyline(segment, {color:v.color, weight:active?5:3, opacity:active?.9:.2}).addTo(layers).on('click', () => highlight(v.id, trip.id));
                    else for (const point of segment) L.circleMarker(point, {radius:5,color:v.color,fillOpacity:active?.8:.2,opacity:active?1:.2}).addTo(layers).on('click', () => highlight(v.id, trip.id));
                }
            }
            if (!h?.trips?.length || h.trips.some(t => t.geometry_status === 'missing')) for (const sample of h?.samples || []) {
                const point = [sample.latitude, sample.longitude]; bounds.push(point);
                L.circleMarker(point, {radius:3, color:v.color, weight:1, fillOpacity:.45}).addTo(layers)
                    .bindTooltip(`${escape(v.name)} · retained sample · ${escape(date(sample.recorded_at))}`);
            }
        }
        const empty = $('[data-fleet-empty]');
        empty.hidden = bounds.length > 0;
        empty.textContent = !chosen().length ? 'No vans selected. Select a van to show its location and history.' : 'No locations or route geometry are available for these vans. Check the vehicle status and history below.';
        if (!initiallyFit && bounds.length) { if (!userMoved) fit(); initiallyFit = true; }
    }
    function renderList() {
        const activeId = document.activeElement?.dataset?.vanCheck;
        const allowed = new Set(data.vehicles.map(v => v.id));
        selected = new Set([...selected].filter(id => allowed.has(id)));
        $('[data-fleet-count]').textContent = `${chosen().length} / ${data.vehicles.length} selected`;
        const counts={}; for(const v of chosen())counts[v.status]=(counts[v.status]||0)+1;
        $('[data-fleet-health]').textContent=Object.entries(counts).map(([key,count])=>`${count} ${{available:'recent reports',stale:'stale',unmapped:'missing tracker',unavailable:'location unavailable',disconnected:'disconnected',disabled:'paused'}[key]||key}`).join(' · ');
        $('[data-fleet-list]').innerHTML = data.vehicles.length ? data.vehicles.map(v => `<article class="fleet-vehicle ${selected.has(v.id)?'is-selected':''}" style="--van-color:${escape(v.color)}">
            <label><input type="checkbox" data-van-check="${v.id}" ${selected.has(v.id)?'checked':''}><span class="fleet-swatch"></span><strong>${escape(v.name)}</strong></label>
            <p class="fleet-status ${v.status!=='available'?'needs-attention':''}">${escape(statusText(v))}</p><p>${escape(updated(v))}</p>
            ${v.status==='disconnected'&&v.device_signal_at?`<p>Tracker signal ${escape(date(v.device_signal_at))}</p>`:''}
            ${v.location?.source==='provider_snapshot'?'<p>Last-known position · fix time unavailable</p>':''}
            <button type="button" data-van-highlight="${v.id}" ${selected.has(v.id)?'':'disabled'} aria-pressed="${focus?.vehicle===v.id&&!focus.trip}">Highlight van</button></article>`).join('') : '<p class="fleet-no-data">No company vans are configured. An owner or administrator can review vehicle setup below.</p>';
        if (activeId) $(`[data-van-check="${activeId}"]`)?.focus();
    }
    function renderHistory() {
        $('[data-fleet-history]').innerHTML = chosen().map(v => {
            const h = histories.get(v.id);
            return `<article class="fleet-vehicle-history" style="--van-color:${escape(v.color)}"><h4><span class="fleet-swatch"></span>${escape(v.name)}</h4>
                <p class="fleet-history-message">${escape(h?.message || 'Loading Bouncie trip history…')}</p>
                ${h?.boundary_notice?`<p class="fleet-sample-note">${escape(h.boundary_notice)}</p>`:''}
                ${h?.range?.partial_first_day?'<p class="fleet-sample-note">The first day is incomplete because older locations are outside this workspace’s retention.</p>':''}
                ${h?.trips?.map((t,i) => `<button type="button" class="fleet-trip ${focus?.vehicle===v.id&&focus.trip===t.id?'is-highlighted':''}" data-trip-van="${v.id}" data-trip-id="${escape(t.id)}" aria-pressed="${focus?.vehicle===v.id&&focus.trip===t.id}">
                    <strong>Trip ${i+1} <span>${t.distance_miles==null?'Distance unavailable':`${t.distance_miles.toFixed(1)} mi`} · ${Math.round(t.duration_seconds/60)} min</span></strong>
                    <span>${escape(date(t.started_at))} → ${escape(date(t.ended_at))}</span>
                    <small>${t.geometry_status==='missing'?'GPS path unavailable':t.geometry_status==='sparse'?'Sparse GPS samples · no road route inferred':'Highlight GPS trace'}${t.geometry_limited?' · Path incomplete; display limit reached':''}${t.crosses_range?' · Crosses date boundary; full trip shown':''}</small></button>`).join('') || ''}
                ${h?.samples?.length?`<p class="fleet-sample-note">${h.samples.length} representative retained samples${h.samples_truncated?' (latest 100 time buckets shown)':''}. Samples do not establish the traveled road route.</p>`:''}
                ${h && ['partial','authorization_expired','unavailable'].includes(h.status)?`<button class="fleet-button" type="button" data-retry-van="${v.id}">Retry ${escape(v.name)}</button>`:''}</article>`;
        }).join('') || '<p class="fleet-no-data">Select vans above to compare their traveled routes.</p>';
    }
    function highlight(vehicle, trip = null) {
        focus = focus?.vehicle === vehicle && focus.trip === trip ? null : {vehicle,trip};
        renderList(); renderHistory(); draw();
    }
    async function json(url, signal) {
        const response = await fetch(url, {headers:{Accept:'application/json'}, cache:'no-store', credentials:'same-origin', signal});
        if(closed)throw new DOMException('Map closed','AbortError');
        if ([401,403,419].includes(response.status) || response.redirected) {
            expired = true; data.vehicles = []; histories.clear(); renderList(); renderHistory(); draw();
            throw new Error('Your session or workspace access needs attention. Sign in again to continue.');
        }
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('The server could not complete this request. Try again.');
        const body = await response.json();
        if (!response.ok) throw new Error(Object.values(body.errors||{}).flat()[0] || body.message || 'The request could not be completed.');
        return body;
    }
    async function loadHistory(v, force = false) {
        if (expired || requests.has(v.id) || (!force && histories.has(v.id))) return;
        const currentGeneration = generation;
        const controller = new AbortController(); requests.set(v.id, controller);
        try {
            const url = new URL(v.history_url, location.origin); url.searchParams.set('start',range.start); url.searchParams.set('end',range.end);
            const h = await json(url, controller.signal);
            if (currentGeneration === generation && !expired) histories.set(v.id,h);
        } catch (e) {
            if (e.name !== 'AbortError' && currentGeneration === generation && !expired) {
                const previous = histories.get(v.id);
                histories.set(v.id,{...previous,status:'unavailable',message:previous?.trips?.length?'History refresh failed. Previously loaded trips for this period remain visible; retry this van.':'History could not be loaded for this van. Other vans remain available. Retry this van.'});
            }
            if (expired) notice(e.message);
        } finally {
            if (requests.get(v.id) === controller) requests.delete(v.id);
            if (currentGeneration === generation && !closed) { renderHistory(); draw(); }
        }
    }
    async function loadSelected(force = false) {
        const currentGeneration = generation;
        const pending = chosen().filter(v => force || (!histories.has(v.id) && !requests.has(v.id)));
        const queue = [...pending];
        // Independent bounded requests keep one failed/slow vehicle from blocking the others.
        await Promise.all(Array.from({length:Math.min(3,queue.length)}, async () => { while(queue.length && currentGeneration===generation) { const v=queue.shift(); if(selected.has(v.id))await loadHistory(v,force); } }));
        if(currentGeneration!==generation)return;
        if (initialHistoryFit) { initialHistoryFit = false; if (!userMoved && !expired) fit(); }
    }
    function renderPeriod() {
        $('[data-fleet-period-label]').textContent = `${range.start}${range.end!==range.start?' – '+range.end:''} · ${range.timezone}`;
        $('[data-fleet-zone]').textContent = range.timezone;
        for (const input of $('[data-fleet-range]').querySelectorAll('input')) { input.min = range.earliest; input.max = range.today; input.value = range[input.name]; }
        root.querySelectorAll('[data-period]').forEach(b => b.setAttribute('aria-pressed',String(b.dataset.period===period)));
    }
    function changeRange(start, end) {
        generation++; requests.forEach(c=>c.abort()); requests.clear(); histories.clear(); focus = null;
        range = {...range,start,end}; renderPeriod(); renderList(); renderHistory(); draw();
        notice(`Loading history for ${start}${start===end?'':' through '+end}…`);
        loadSelected().then(()=>{ if(!expired) notice('History updated. Review each van below for missing trips or provider failures.'); });
    }
    async function refresh(manual = false) {
        if (expired || refreshing) return;
        refreshing = true;
        if(manual) notice('Refreshing locations and selected van history…');
        try {
            const next = await json(root.dataset.mapUrl, snapshotController.signal);
            if(closed)return;
            // This endpoint has the server's new local day/retention boundary.
            data = next;
            if (period !== 'custom') {
                const day = next.range[period];
                if (range.start !== day) { range = {...next.range}; changeRange(day,day); }
            }
            range = {...range, today:next.range.today, yesterday:next.range.yesterday, earliest:next.range.earliest};
            renderPeriod(); renderList(); renderHistory(); draw();
            const feed = data.feed.status;
            notice(({unavailable:'Bouncie locations could not be refreshed. Retained positions remain visible with their timestamps.', disconnected:'Bouncie is disconnected. Review connection settings below.', disabled:'Company-van tracking is paused or policy approval is required.', refreshing:'Another location refresh is in progress.'})[feed] || `Locations checked ${date(data.checked_at)}. Location timestamps appear on each van.`);
            if(manual) await loadSelected(true);
        } catch(e) { if(!closed)notice(expired?e.message:'Location refresh failed. Last-known positions remain visible with their timestamps. Try Refresh again.'); }
        finally { refreshing = false; }
    }
    $('[data-fleet-list]').addEventListener('change', e => {
        if(!e.target.matches('[data-van-check]')) return;
        const id = Number(e.target.dataset.vanCheck);
        e.target.checked ? selected.add(id) : selected.delete(id);
        if(focus&&!selected.has(focus.vehicle))focus=null;
        save(); renderList(); renderHistory(); draw(); loadSelected();
    }, {signal:listeners.signal});
    $('[data-fleet-list]').addEventListener('click', e => { const b = e.target.closest('[data-van-highlight]'); if(b)highlight(Number(b.dataset.vanHighlight)); }, {signal:listeners.signal});
    $('[data-fleet-all]').onclick = () => {selected = new Set(data.vehicles.map(v=>v.id)); save(); renderList(); renderHistory(); draw(); loadSelected();};
    $('[data-fleet-clear]').onclick = () => {selected.clear(); focus=null; save(); renderList(); renderHistory(); draw();};
    $('[data-fleet-fit]').onclick = fit;
    $('[data-fleet-refresh]').onclick = () => refresh(true);
    root.querySelectorAll('[data-period]').forEach(b => b.onclick = () => {
        period = b.dataset.period; $('[data-fleet-range]').hidden = period!=='custom';
        if(period==='custom') renderPeriod(); else changeRange(range[period],range[period]);
    });
    $('[data-fleet-range]').onsubmit = e => {
        e.preventDefault(); const form = e.currentTarget;
        form.elements.end.setCustomValidity(form.elements.end.value<form.elements.start.value?'End date must be on or after the start date.':'');
        if(form.reportValidity()) changeRange(form.elements.start.value, form.elements.end.value);
    };
    $('[data-fleet-range]').oninput = e => e.currentTarget.elements.end.setCustomValidity('');
    $('[data-fleet-history]').onclick = e => {
        const trip = e.target.closest('[data-trip-id]'), retry = e.target.closest('[data-retry-van]');
        if(trip) highlight(Number(trip.dataset.tripVan),trip.dataset.tripId);
        if(retry) { const v = chosen().find(v=>v.id===Number(retry.dataset.retryVan)); if(v) loadHistory(v,true); }
    };
    renderPeriod(); renderList(); renderHistory(); draw();
    loadSelected().then(()=>{ if(!expired) notice('Selected vans and available history are shown. Review timestamps and each van’s tracking status.'); });
    refresh();
    const locationTimer=setInterval(()=>{if(!document.hidden)refresh();},30000);
    const historyTimer=setInterval(()=>{if(!document.hidden&&!expired)loadSelected(true);},120000);
    return () => {
        closed=true; expired=true; snapshotController.abort(); listeners.abort(); requests.forEach(c=>c.abort());
        clearInterval(locationTimer); clearInterval(historyTimer); observer.disconnect(); map.remove();
    };
}
