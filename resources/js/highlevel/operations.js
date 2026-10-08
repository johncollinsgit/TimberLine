import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
const e = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const date = value => value ? new Date(value).toLocaleString() : 'Not supplied';
const number = value => value == null ? 'Not reported' : Number(value).toLocaleString(undefined,{maximumFractionDigits:1});
const field = (label,name,value='',type='text',extra='') => `<label>${label}<input name="${name}" type="${type}" value="${e(value)}" ${extra}></label>`;
const skills = list => (list || []).join(', ');
const materials = obj => Object.entries(obj || {}).map(([k,v])=>`${k}=${v}`).join(', ');
const parseSkills = text => String(text || '').split(',').map(x=>x.trim()).filter(Boolean);
const parseMaterials = text => {
    const result={};
    for(const item of String(text||'').split(',').filter(x=>x.trim())){
        const [key,...value]=item.split('=');const qty=Number(value.join('='));
        if(!key.trim()||!value.length||!Number.isFinite(qty)||qty<0)throw new Error('Enter materials as name=quantity, separated by commas.');
        result[key.trim()]=qty;
    }
    return result;
};
const empty = text => `<div class="card"><p>${text}</p></div>`;
let sourceOptions=null;
const profile = (d,id) => d.operations?.profiles?.find(p=>p.device_id===id)?.details || {};
export function jobContext(d,id){
    const jobs=(d.operations?.jobs||[]).filter(j=>Number(j.details.device_id)===id).map(j=>({...j,start:j.details.scheduled_start||j.details.crm_start,end:j.details.scheduled_end||j.details.crm_end})).filter(j=>j.start&&j.end).sort((a,b)=>Date.parse(a.start)-Date.parse(b.start));
    const now=Date.now();
    return {crew:profile(d,id).crew || 'Crew not assigned',current:jobs.find(j=>Date.parse(j.start)<=now&&Date.parse(j.end)>now),next:jobs.find(j=>Date.parse(j.start)>now)};
}
const deviceName=(d,id)=>d.vehicles.find(v=>v.id===id)?.name||'Vehicle unavailable';
const optionDevices=(d,id)=>`<option value="">Unassigned</option>${d.vehicles.map(v=>`<option value="${v.id}" ${Number(id)===v.id?'selected':''}>${e(v.name)}</option>`).join('')}`;
function jobSource(d){
    const current=d.operations?.job_source;
    return `<div class="card"><h2>CRM job source</h2><p>Choose the calendar or pipeline they use for work. Everbranch keeps fleet planning attached to those references. No Everbranch jobs are required.</p><p>${current?`Current source: ${e(current.type)} · Last synced ${e(date(current.synced_at))}`:'Source not selected yet. Fleet tracking, maintenance and health alerts work independently.'}</p><button id="load-sources">Find calendars and pipelines</button>${sourceOptions?`<form id="source-form" class="actions"><label>Source<select name="source">${['calendars','pipelines'].flatMap(type=>(sourceOptions[type]||[]).map(s=>`<option value="${type==='calendars'?'calendar':'pipeline'}:${e(s.id)}">${type==='calendars'?'Calendar':'Pipeline'} · ${e(s.name)}</option>`)).join('')}</select></label><button class="primary">Sync CRM work</button></form>`:''}<small class="notice">The agency may need to approve the app’s read permissions before sources appear. Sync reads CRM; dispatch planning stays in Everbranch.</small></div>`;
}
function health(d){
    const telemetry=d.operations?.telemetry||[];
    return `<div class="card"><h2>Connection health</h2><p>Provider: ${e(d.connection.health.status)} · Last checked ${e(date(d.connection.health.checked_at))} · ${d.connection.health.unmapped_devices==null?'Unmapped count has not been checked':`${e(d.connection.health.unmapped_devices)} devices not selected`}</p><p>Older locations can mean a parked vehicle. A disconnected device is shown only when the provider reports a disconnect.</p><div class="table-wrap"><table><thead><tr><th>Vehicle</th><th>Location</th><th>Device signal</th><th>Last telemetry</th></tr></thead><tbody>${d.vehicles.map(v=>{const t=telemetry.find(t=>t.device_id===v.id);return `<tr><td>${e(v.name)}</td><td>${v.location?(v.location.older_reading?'Older than 15 minutes':'Recent'):'Awaiting location'}<small>${e(date(v.location?.reported_at))}</small></td><td>${e(t?.details.device_connection||'Not reported')}</td><td>${e(date(t?.event_at))}</td></tr>`;}).join('')}</tbody></table></div><button data-page="connection">Manage devices</button></div>`;
}
function crews(d){
    return `<div class="card"><h2>Crews and vehicle readiness</h2><p>Maintain the crew, availability, skills and stocked quantities used in dispatch suggestions.</p>${d.vehicles.map(v=>{const p=profile(d,v.id);return `<details class="ops-item"><summary>${e(v.name)} · ${e(p.crew||'No crew')} · ${p.available?'Available':'Availability not confirmed'}</summary><form data-profile="${v.id}" class="form-grid">${field('Crew / assigned person','crew',p.crew||'')}${field('Skills (comma separated)','skills',skills(p.skills))}${field('Stock (name=quantity)','materials',materials(p.materials))}<label class="checkbox"><input name="available" type="checkbox" ${p.available?'checked':''}> Crew available for dispatch</label><button class="primary">Save crew readiness</button></form></details>`;}).join('')}</div>`;
}
function jobsView(d){
    return jobSource(d)+crews(d)+(d.operations?.jobs?.length?`<div class="card"><h2>CRM work and fleet context</h2><p>Confirm the stop coordinates and schedule used for fleet planning. Missing schedules and skills remain unknown until supplied.</p>${d.operations.jobs.map(j=>{const p=j.details;return `<details class="ops-item"><summary>${e(p.title)} <span class="pill">${e(p.source)}</span></summary><p>CRM reference ${e(p.reference)} · ${e(p.crm_status)} · CRM assignee ${e(p.assigned_user||'Not supplied')}</p><p>CRM schedule ${e(date(p.crm_start))} → ${e(date(p.crm_end))}</p><form data-job="${j.id}" class="form-grid"><label>Vehicle / crew<select name="device_id">${optionDevices(d,p.device_id)}</select></label>${field('Stop latitude','latitude',p.latitude??'','number','step="any" min="-90" max="90"')}${field('Stop longitude','longitude',p.longitude??'','number','step="any" min="-180" max="180"')}${field('Planning start (optional override)','scheduled_start',localDate(p.scheduled_start),'datetime-local')}${field('Planning end','scheduled_end',localDate(p.scheduled_end),'datetime-local')}${field('Required skills (comma separated)','skills',skills(p.skills))}${field('Required stock (name=quantity)','materials',materials(p.materials))}<button class="primary">Save fleet context</button></form><div class="actions"><button data-dispatch="${j.id}">Suggest crews</button></div><div id="dispatch-${j.id}"></div></details>`;}).join('')}</div>`:empty('No CRM work has been synced. Choose a source when you know where they keep their jobs.'));
}
function localDate(iso){if(!iso)return '';const dt=new Date(iso);if(!Number.isFinite(dt.getTime()))return '';return new Date(dt.getTime()-dt.getTimezoneOffset()*60000).toISOString().slice(0,16);}
function maintenance(d){
    const o=d.operations||{},plans=o.service_plans||[],logs=o.service_logs||[],tasks=o.maintenance_tasks||[];
    return `<div class="metrics">${d.vehicles.map(v=>{const t=(o.telemetry||[]).find(t=>t.device_id===v.id);return `<div class="card"><h3>${e(v.name)}</h3><strong>${number(t?.details.odometer)} miles</strong><small>Provider odometer · ${e(date(t?.details.timestamps?.odometer))}</small></div>`;}).join('')}</div><div class="card"><h2>Maintenance tasks</h2>${tasks.length?tasks.map(t=>`<p><span class="pill warn">Due</span> ${e(deviceName(d,t.device_id))} · ${e(t.details.title)} · ${e(t.details.reason)} · ${e(t.details.assignee||'Unassigned')}</p>`).join(''):'<p>No due tasks. A task is created automatically when a plan’s mileage or date threshold is reached.</p>'}</div><div class="card"><h2>Add service plan</h2><form id="plan-form" class="form-grid"><label>Vehicle<select name="device_id" required>${optionDevices(d,null)}</select></label>${field('Service (oil change, inspection, etc.)','title','','text','required maxlength="180"')}${field('Assigned person','assignee')}${field('Due odometer (miles)','due_miles','','number','min="0" step="any"')}${field('Due date','due_date','','date')}${field('Repeat every miles (optional)','interval_miles','','number','min="1"')}${field('Repeat every days (optional)','interval_days','','number','min="1"')}<button class="primary">Add plan</button></form></div><div class="card"><h2>Upcoming service and history</h2>${plans.filter(p=>p.status==='open').map(p=>`<details class="ops-item"><summary>${e(deviceName(d,p.device_id))} · ${e(p.details.title)} · ${p.details.due_miles==null?'':`${number(p.details.due_miles)} miles`} ${e(p.details.due_date||'')}</summary><form data-service="${p.id}" class="form-grid">${field('Service date','serviced_at',new Date().toISOString().slice(0,10),'date','required')}${field('Odometer at service','odometer','','number','required min="0" step="any"')}${field('Service notes / invoice reference','notes')}<button class="primary">Record completed service</button></form></details>`).join('')||'<p>No upcoming plans.</p>'}${logs.length?`<h3>Completed service</h3>${logs.map(l=>`<p>${e(deviceName(d,l.device_id))} · ${e(l.details.title)} · ${e(l.details.serviced_at)} · ${number(l.details.odometer)} miles<small>${e(l.details.notes||'')}</small></p>`).join('')}`:'<p>No completed service recorded.</p>'}</div>`;
}
function alerts(d){
    const rows=d.operations?.alerts||[];
    return `<div class="card"><h2>Vehicle health alerts</h2><p>Check-engine and low-battery events come from provider readings. Assign an owner and record the resolution. “Resolved” records a manager’s action; it does not clear the vehicle’s fault code.</p>${rows.length?rows.map(a=>`<details class="ops-item"><summary><span class="pill ${a.status==='resolved'?'':'warn'}">${e(a.status)}</span> ${e(deviceName(d,a.device_id))} · ${e(a.details.type==='battery'?'Low battery':'Check engine')}</summary><p>Provider value: ${e(a.details.value)} · Reported ${e(date(a.event_at))}</p><form data-alert="${a.id}" class="form-grid">${field('Assigned person','assignee',a.details.assignee||'')}<label>Status<select name="status">${['open','in_progress','resolved'].map(s=>`<option ${a.status===s?'selected':''}>${s}</option>`).join('')}</select></label>${field('Resolution / action taken','resolution',a.details.resolution||'')}<button class="primary">Save alert review</button></form></details>`).join(''):'<p>No vehicle health events have been received.</p>'}</div>`;
}
function trips(d){
    const rows=d.operations?.trips||[],jobs=d.operations?.jobs||[];
    return `<div class="card"><h2>Trip history and route review</h2><p>Distance and drive time use Bouncie trip metrics. Supply an approved road-distance baseline or a comparable approved trip. The system flags distance beyond that baseline plus the larger of your mile and percentage allowances. A flag needs review; it does not establish misuse.</p>${rows.length?rows.map(t=>{const p=t.details;const flagged=t.route_review==='review';return `<details class="ops-item"><summary>${e(deviceName(d,t.device_id))} · ${number(p.distance_miles)} miles · <span class="pill ${flagged?'warn':''}">${e({baseline_needed:'Baseline needed',distance_pending:'Distance pending',review:'Route review',within_tolerance:'Within tolerance'}[t.route_review])}</span>${p.review_status==='approved_detour'?'<span class="pill">Approved detour</span>':''}</summary><p>${e(date(p.started_at))} → ${e(date(p.ended_at))} · Drive time ${t.drive_seconds==null?'Pending':`${number(t.drive_seconds/60)} minutes`} · Idle ${p.idle_seconds==null?'Pending':`${number(p.idle_seconds/60)} minutes`}</p><p>${t.candidates.length>1?'Several scheduled jobs could match. Confirm the correct job below.':t.candidates.length===1?`Possible scheduled match: ${e(t.candidates[0].title)}. Confirm below.`:'No scheduled match found. You can confirm a CRM reference manually.'}</p><button data-path="${t.id}">View reported path</button><div id="path-${t.id}"></div><form data-trip="${t.id}" class="form-grid"><label>Confirmed CRM job<select name="job_id"><option value="">Unconfirmed / no job</option>${jobs.map(j=>`<option value="${j.id}" ${Number(p.job_id)===j.id?'selected':''}>${e(j.details.title)}</option>`).join('')}</select></label>${field('Approved road distance (miles)','expected_miles',p.expected_miles??'','number','min="0.1" step="any"')}${field('Baseline reference / reason','baseline_reference',p.baseline_reference||'')}${field('Extra mile allowance','extra_miles',p.extra_miles??5,'number','required min="0" step="any"')}${field('Extra percent allowance','extra_percent',p.extra_percent??30,'number','required min="0" step="any"')}<label>Review decision<select name="review_status">${['pending','approved_detour','investigate'].map(s=>`<option ${p.review_status===s?'selected':''}>${s}</option>`).join('')}</select></label>${field('Review notes (required for approved detours)','review_note',p.review_note||'')}<button class="primary">Save trip confirmation and review</button></form></details>`;}).join(''):'<p>No trips have been received yet. Trips appear after approved collection receives Bouncie trip events.</p>'}</div>`;
}
export function operationsView(page,d){return page==='health'?health(d):page==='jobs'?jobsView(d):page==='maintenance'?maintenance(d):page==='alerts'?alerts(d):trips(d);}
export function bindOperations(root,d,api,run,refresh,notice){
    const save=(form,path,convert,method='PUT')=>{if(!form)return;form.onsubmit=event=>{event.preventDefault();run(async()=>{const p=convert(new FormData(form));await api(`operations/${path}`,method,p);await refresh();notice('Saved.');});};};
    const common=f=>({skills:parseSkills(f.get('skills')),materials:parseMaterials(f.get('materials'))});
    const val=(f,key)=>String(f.get(key)||'').trim()||null;
    const num=(f,key)=>val(f,key)===null?null:Number(f.get(key));
    root.querySelectorAll('[data-profile]').forEach(form=>save(form,`profiles/${form.dataset.profile}`,f=>({...common(f),crew:String(f.get('crew')||''),available:f.has('available')})));
    root.querySelectorAll('[data-job]').forEach(form=>save(form,`jobs/${form.dataset.job}`,f=>({...common(f),device_id:num(f,'device_id'),latitude:num(f,'latitude'),longitude:num(f,'longitude'),scheduled_start:val(f,'scheduled_start')?new Date(f.get('scheduled_start')).toISOString():null,scheduled_end:val(f,'scheduled_end')?new Date(f.get('scheduled_end')).toISOString():null})));
    save(root.querySelector('#plan-form'),'plans',f=>({device_id:num(f,'device_id'),title:f.get('title'),assignee:val(f,'assignee'),due_miles:num(f,'due_miles'),due_date:val(f,'due_date'),interval_miles:num(f,'interval_miles'),interval_days:num(f,'interval_days')}),'POST');
    root.querySelectorAll('[data-service]').forEach(form=>save(form,'service',f=>({plan_id:Number(form.dataset.service),odometer:num(f,'odometer'),serviced_at:f.get('serviced_at'),notes:val(f,'notes')}),'POST'));
    root.querySelectorAll('[data-alert]').forEach(form=>save(form,`alerts/${form.dataset.alert}`,f=>({assignee:String(f.get('assignee')||''),status:f.get('status'),resolution:val(f,'resolution')})));
    root.querySelectorAll('[data-trip]').forEach(form=>save(form,`trips/${form.dataset.trip}`,f=>({job_id:num(f,'job_id'),expected_miles:num(f,'expected_miles'),baseline_reference:val(f,'baseline_reference'),extra_miles:num(f,'extra_miles'),extra_percent:num(f,'extra_percent'),review_status:f.get('review_status'),review_note:val(f,'review_note')})));
    const load=root.querySelector('#load-sources');if(load)load.onclick=()=>run(async()=>{sourceOptions=await api('operations/sources');await refresh();});
    save(root.querySelector('#source-form'),'sync',f=>{const [type,source_id]=String(f.get('source')||'').split(':');return {type,source_id};},'POST');
    root.querySelectorAll('[data-dispatch]').forEach(button=>button.onclick=()=>run(async()=>{
        const result=await api(`operations/jobs/${button.dataset.dispatch}/dispatch`);const target=root.querySelector(`#dispatch-${button.dataset.dispatch}`);
        target.innerHTML=`<p>${e(result.basis)}</p>${result.suggestions.length?result.suggestions.map(s=>`<p>${e(s.name)} · ${e(s.crew)} · ${number(s.straight_line_miles)} miles straight-line <button data-choose="${s.device_id}">Choose this crew</button></p>`).join(''):'<p>No crew meets the confirmed availability, location, schedule, skills and stock requirements.</p>'}`;
        target.querySelectorAll('[data-choose]').forEach(b=>b.onclick=()=>{const form=root.querySelector(`[data-job="${button.dataset.dispatch}"]`);form.elements.device_id.value=b.dataset.choose;notice('Crew selected in the planning form. Review and save fleet context to confirm.');});
    }));
    root.querySelectorAll('[data-path]').forEach(button=>button.onclick=()=>run(async()=>{
        const result=await api(`operations/trips/${button.dataset.path}/path`);const target=root.querySelector(`#path-${button.dataset.path}`);
        target.innerHTML=`<p>${e(result.message)} ${result.truncated?'Showing the first 2,000 samples.':''}</p>${result.points.length?'<div class="trip-map map"></div>':'<p>No retained GPS samples are available for this trip.</p>'}`;
        if(result.points.length){const m=baseMap(target.querySelector('.trip-map'),d);const layer=L.polyline(result.points.map(p=>[p.lat,p.lng]),{color:'#216347',weight:4}).addTo(m);m.fitBounds(layer.getBounds(),{padding:[30,30],maxZoom:16});}
    }));
}
export function baseMap(target,d){
    const map=L.map(target,{scrollWheelZoom:false}).setView([39,-98],4);
    const tile=d.map_tiles?.url||'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    L.tileLayer(tile,{maxZoom:19,referrerPolicy:'origin',attribution:d.map_tiles?.attribution||'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(map);
    return map;
}
let fallbackMap=null, fallbackLayers=[];
export function drawFallbackMap(target,d,onSelect){
    let created=false;
    if(!fallbackMap||fallbackMap.getContainer()!==target){if(fallbackMap)fallbackMap.remove();target.innerHTML='';fallbackMap=baseMap(target,d);created=true;fallbackLayers=[];}
    fallbackLayers.forEach(l=>l.remove());fallbackLayers=[];const bounds=[];
    for(const v of d.vehicles.filter(v=>v.location)){
        const ctx=jobContext(d,v.id),pos=[v.location.latitude,v.location.longitude];bounds.push(pos);
        fallbackLayers.push(L.marker(pos,{alt:v.name,icon:L.divIcon({className:'vehicle-pin',html:`<span class="${v.location.older_reading?'stale':''}">${d.vehicles.indexOf(v)+1}</span>`,iconSize:[30,36],iconAnchor:[15,36]})}).addTo(fallbackMap).bindTooltip(e(v.name)).bindPopup(`<strong>${e(v.name)}</strong><br>${e(ctx.crew)}<br>Current: ${e(ctx.current?.details.title||'No scheduled job')}<br>Next: ${e(ctx.next?.details.title||'No scheduled stop')}`).on('click',()=>onSelect(v.id)));
    }
    for(const j of d.operations?.jobs||[]){const p=j.details;if(p.latitude==null||p.longitude==null)continue;fallbackLayers.push(L.marker([p.latitude,p.longitude],{alt:`Job: ${p.title}`,icon:L.divIcon({className:'job-pin',html:'<span>J</span>',iconSize:[22,22]})}).addTo(fallbackMap).bindPopup(`CRM stop: ${e(p.title)}`));}
    if(created&&bounds.length)fallbackMap.fitBounds(bounds,{padding:[40,40],maxZoom:14});
}
export function panFallback(location){if(fallbackMap&&location)fallbackMap.panTo([location.latitude,location.longitude]);}
