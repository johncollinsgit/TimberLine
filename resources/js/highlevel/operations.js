import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {picker, suggestions, tags, materials as materialEditor, readRequirements, bindInputs} from './inputs';
const e = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const date = value => value ? new Date(value).toLocaleString() : 'Not supplied';
const number = value => value == null ? 'Not reported' : Number(value).toLocaleString(undefined,{maximumFractionDigits:1});
const field = (label,name,value='',type='text',extra='') => `<label>${label}<input name="${name}" type="${type}" value="${e(value)}" ${extra}></label>`;
const empty = text => `<div class="card"><p>${text}</p></div>`;
const profile = (d,id) => d.operations?.profiles?.find(p=>p.device_id===id)?.details || {};
export function jobContext(d,id){
    const jobs=(d.operations?.jobs||[]).filter(j=>Number(j.details.device_id)===id).map(j=>({...j,start:j.details.scheduled_start||j.details.crm_start,end:j.details.scheduled_end||j.details.crm_end})).filter(j=>j.start&&j.end).sort((a,b)=>Date.parse(a.start)-Date.parse(b.start));
    const now=Date.now();
    return {crew:profile(d,id).crew || 'Crew not assigned',current:jobs.find(j=>Date.parse(j.start)<=now&&Date.parse(j.end)>now),next:jobs.find(j=>Date.parse(j.start)>now)};
}
const deviceName=(d,id)=>d.vehicles.find(v=>v.id===id)?.name||'Vehicle unavailable';
const address=p=>p.address_override||p.crm_address||'';
const known=(d,key)=>[...new Set([...(d.operations?.profiles||[]),...(d.operations?.jobs||[])].flatMap(r=>key==='materials'?Object.keys(r.details.materials||{}):r.details[key]||[]))];
const people=d=>(d.operations?.profiles||[]).map(r=>r.details.crew).filter(Boolean);
const vanOptions=d=>d.vehicles.map(v=>{const ctx=jobContext(d,v.id),stop=ctx.current||ctx.next;return {id:v.id,label:v.name,detail:[ctx.crew,stop?`Scheduled: ${stop.details.title}${address(stop.details)?' · '+address(stop.details):''}`:'No scheduled stop',v.location?`Location updated ${date(v.location.reported_at)}`:'Location not available'].join(' · ')};});
const vanPicker=(d,id,required=false)=>picker('Van','device_id',id,vanOptions(d),'Search vans, crews or scheduled locations',required);
const requirements=(d,p,stockLabel)=>tags('Job types',p.skills||[],[...known(d,'skills'),'service','inspection','installation','repair'])+materialEditor(stockLabel,p.materials||{},known(d,'materials'));
const searchBar=(kind,placeholder,filters='')=>`<div class="list-tools"><label class="sr-only" for="${kind}-search">${placeholder}</label><input id="${kind}-search" type="search" data-list-search="${kind}" placeholder="${placeholder}">${filters}<span data-list-count="${kind}" role="status"></span></div>`;
function jobSource(d){
    return `<div class="work-sync"><span id="crm-sync-status" role="status">${d.operations?.job_source?`Updated ${e(date(d.operations.job_source.synced_at))}`:'Loading jobs from HighLevel…'}</span><button id="sync-crm">Refresh jobs</button></div>`;
}
function health(d){
    return searchBar('vans','Search vans, crews or materials')+`<div class="card">${d.vehicles.length?d.vehicles.map(v=>{const p=profile(d,v.id),t=d.operations?.telemetry?.find(t=>t.device_id===v.id);return `<details class="ops-item" data-list-item="vans" data-search="${e([v.name,p.crew,...(p.skills||[]),...Object.keys(p.materials||{})].join(' ').toLowerCase())}"><summary>${e(v.name)} <span class="summary-detail">${e(p.crew||'Assign a crew')} · ${p.available?'Available':'Unavailable'}</span></summary><form data-profile="${v.id}" class="form-grid">${suggestions('Crew or driver','crew',p.crew||'',people(d),'maxlength="180" placeholder="Search or enter a name"')}${requirements(d,p,'Materials in this van')}<label class="checkbox wide"><input name="available" type="checkbox" ${p.available?'checked':''}> Available for jobs</label><button class="primary">Save van</button></form><details class="connection-management"><summary>Tracker status</summary><p>${v.location?`Location updated ${e(date(v.location.reported_at))}`:'Waiting for a location'} · ${e(t?.details.device_connection||'Signal not reported')}</p></details></details>`;}).join(''):'<p>Choose your Bouncie vehicles to add vans here.</p><button data-page="connection">Choose vehicles</button>'}<p data-list-empty="vans" hidden>No vans match your search.</p></div>`;
}
function materialCheck(d,j){
    const p=j.details,stock=profile(d,Number(p.device_id)).materials||{},rows=Object.entries(p.materials||{});
    if(!rows.length)return '';
    return `<div class="job-material-check"><strong>Materials for this job</strong><div class="table-wrap"><table><thead><tr><th>Material</th><th>Needed</th><th>On van</th><th>Status</th></tr></thead><tbody>${rows.map(([name,qty])=>{const known=Object.hasOwn(stock,name),available=known?stock[name]:null,ready=known&&Number(available)>=Number(qty);return `<tr><td>${e(name)}</td><td>${number(qty)} ${e(p.material_units?.[name]||'')}</td><td>${p.device_id?(known?number(available):'Not recorded'):'—'}</td><td><span class="pill ${!p.device_id||!ready?'warn':''}">${!p.device_id?'Assign a van':!known?'Check van stock':ready?'Ready':`Need ${number(Number(qty)-available)} more`}</span></td></tr>`;}).join('')}</tbody></table></div>${d.demo?'<small>Demo stock only. Saving an assignment does not reserve or use materials.</small>':''}</div>`;
}
function calendarView(d){
    const events=d.operations?.calendar_events||[];
    const time=value=>value?new Intl.DateTimeFormat('en-US',{timeZone:'America/New_York',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(value)):'Not scheduled';
    return `<div class="card"><h2>Demo calendar</h2><p>Synthetic examples in this browser. These are not real HighLevel Calendar events. No invitations or notifications are sent.</p><small>Times: Eastern</small></div>${searchBar('calendar','Search demo events, customers or addresses')}${events.map(event=>{
        const j=d.operations.jobs.find(j=>j.id===event.job_id);if(!j)return '';const p=j.details,crew=profile(d,Number(p.device_id)).crew;
        return `<article class="card demo-event" data-list-item="calendar" data-search="${e([p.title,p.customer_name,address(p)].join(' ').toLowerCase())}" data-calendar-event="${e(event.id)}"><span class="pill">DEMO event</span><h2>${e(p.title)}</h2><p><strong>${e(time(p.scheduled_start||p.crm_start))} – ${e(time(p.scheduled_end||p.crm_end))}</strong><br>${e(p.customer_name)}<br>${e(address(p))}</p><p>${e(deviceName(d,Number(p.device_id)))} · ${e(crew||'Choose a team')}</p>${materialCheck(d,j)}<div class="actions"><button class="primary" data-open-job="${j.id}">Open linked job</button></div></article>`;
    }).join('')}<div class="card" data-list-empty="calendar" hidden>No demo events match your search.</div>`;
}
function jobsView(d){
    const rows=d.operations?.jobs||[];
    return jobSource(d)+searchBar('jobs','Search jobs, customers, addresses or vans','<label class="sr-only" for="job-filter">Show jobs</label><select id="job-filter"><option value="all">All jobs</option><option value="today">Today</option><option value="upcoming">Upcoming</option><option value="unassigned">Unassigned</option></select>')+(rows.length?`<div class="card">${rows.map(j=>{const p=j.details,location=address(p),van=d.vehicles.find(v=>v.id===Number(p.device_id));return `<details class="ops-item" data-list-item="jobs" data-job-id="${j.id}" data-search="${e([p.title,p.customer_name,location,van?.name,profile(d,Number(p.device_id)).crew].filter(Boolean).join(' ').toLowerCase())}"><summary>${e(p.title)} <span class="summary-detail">${e(p.customer_name||'')} ${van?`· ${e(van.name)}`:'· Unassigned'}</span></summary>${d.demo&&p.calendar_event_id?`<button class="calendar-back" data-page="calendar" data-event-id="${e(p.calendar_event_id)}">Back to calendar event</button>`:''}<p class="job-overview">${e(location||'Add an address below')}<br>${p.scheduled_start||p.crm_start?e(date(p.scheduled_start||p.crm_start)):'Choose a start time'}${p.scheduled_end||p.crm_end?' – '+e(date(p.scheduled_end||p.crm_end)):''}</p><form data-job="${j.id}" class="form-grid">${picker('Job address','address',location,rows.filter(r=>address(r.details)).map(r=>({id:address(r.details),label:address(r.details),detail:[r.details.title,r.details.customer_name].filter(Boolean).join(' · ')})),'Search job names or addresses, or enter an address',false,true)}<input type="hidden" name="location_job_id" value="">${vanPicker(d,p.device_id)}${field('Start','scheduled_start',localDate(p.scheduled_start||p.crm_start),'datetime-local')}${field('Finish','scheduled_end',localDate(p.scheduled_end||p.crm_end),'datetime-local')}${requirements(d,p,'Materials needed')}<div class="actions wide"><button class="primary">Save assignment</button>${!p.synthetic_location?`<button type="button" data-dispatch="${j.id}">Save & find nearby vans</button>`:''}${p.crm_start?'<button type="button" data-reset-time>Use HighLevel times</button>':''}</div><small class="wide">Saved in Fleet. Your HighLevel job stays unchanged.</small></form>${p.location_status==='check_address'?'<p class="notice">Check the full street address before finding nearby vans. You can still assign a van.</p>':p.location_status==='unavailable'?'<p class="notice">Address lookup is unavailable. Your address and assignment are saved; try nearby vans again later.</p>':''}${materialCheck(d,j)}<div id="dispatch-${j.id}" aria-live="polite"></div></details>`;}).join('')}<p data-list-empty="jobs" hidden>No jobs match this search.</p></div>`:empty('No jobs yet. Add an appointment or open opportunity in HighLevel, then choose Refresh jobs.'));
}
function localDate(iso){if(!iso)return '';const dt=new Date(iso);if(!Number.isFinite(dt.getTime()))return '';return new Date(dt.getTime()-dt.getTimezoneOffset()*60000).toISOString().slice(0,16);}
const money=value=>value==null?'Not recorded':new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(value));
function maintenance(d){
    const o=d.operations||{},plans=(o.service_plans||[]).filter(p=>p.status==='open'),logs=o.service_logs||[],tasks=o.maintenance_tasks||[];
    const today=d.route_range?.today||new Date().toISOString().slice(0,10);
    const due=p=>tasks.some(t=>t.details.plan_id===p.id)||(p.details.due_date && p.details.due_date<=today)||(p.details.due_miles!=null && (o.telemetry||[]).find(t=>t.device_id===p.device_id)?.details.odometer>=p.details.due_miles);
    const overdue=plans.filter(due),upcoming=plans.filter(p=>!due(p));
    const estimated=plans.filter(p=>p.details.estimated_cost!=null);
    const recent=logs.filter(l=>l.details.serviced_at>=new Date(Date.parse(today)-30*86400000).toISOString().slice(0,10));
    const costLogs=recent.filter(l=>l.details.actual_cost!=null);
    const planView=p=>`<details class="ops-item"><summary><span class="pill ${due(p)?'warn':''}">${due(p)?'Due now':'Upcoming'}</span> ${e(deviceName(d,p.device_id))} · ${e(p.details.title)}</summary><p>Due ${p.details.due_miles==null?'':`${number(p.details.due_miles)} miles `}${e(p.details.due_date||'')} · Estimated service cost ${money(p.details.estimated_cost)}</p>${tasks.filter(t=>t.details.plan_id===p.id).map(t=>`<p>${e(t.details.reason)} · ${e(t.details.assignee||'Unassigned')}</p>`).join('')}<form data-service="${p.id}" class="form-grid">${field('Service date','serviced_at',today,'date','required')}${field('Mileage at service','odometer','','number','required min="0" step="any"')}${field('Cost ($)','actual_cost','','number','min="0" max="1000000" step="0.01"')}${field('Notes or invoice number','notes')}<button class="primary">Mark completed</button></form></details>`;
    return `<div class="maintenance-summary"><div class="card"><small>Due now</small><h2>${overdue.length} service needs</h2></div><div class="card"><small>Upcoming</small><h2>${upcoming.length} planned services</h2></div><div class="card"><small>Open service estimates</small><h2>${estimated.length?money(estimated.reduce((sum,p)=>sum+Number(p.details.estimated_cost),0)):'Not recorded'}</h2><small>${estimated.length} of ${plans.length} plans have a cost estimate</small></div><div class="card"><small>Recorded costs · past 30 days</small><h2>${costLogs.length?money(costLogs.reduce((sum,l)=>sum+Number(l.details.actual_cost),0)):'Not recorded'}</h2><small>${costLogs.length} completed services with recorded costs</small></div></div>
    <div class="card"><h2>Due now</h2>${overdue.map(planView).join('')||'<p>Nothing due now.</p>'}</div>
    <div class="card"><h2>Upcoming maintenance</h2>${upcoming.map(planView).join('')||'<p>No upcoming service.</p>'}</div>
    <div class="card"><h2>Recently completed service</h2>${logs.length?logs.map(l=>`<div class="service-history"><strong>${e(deviceName(d,l.device_id))} · ${e(l.details.title)}</strong><p>${e(l.details.serviced_at)} · ${number(l.details.odometer)} miles · Actual cost ${money(l.details.actual_cost)}<small>${e(l.details.notes||'')}</small></p></div>`).join(''):'<p>No completed service recorded.</p>'}</div>
    <details class="card"><summary>Add a service plan</summary><form id="plan-form" class="form-grid">${vanPicker(d,null,true)}${field('Service (oil change, inspection, etc.)','title','','text','required maxlength="180"')}${suggestions('Assigned to','assignee','',people(d),'maxlength="180"')}${field('Due odometer (miles)','due_miles','','number','min="0" step="any"')}${field('Due date','due_date','','date')}${field('Estimated cost ($)','estimated_cost','','number','min="0" max="1000000" step="0.01"')}<details class="wide"><summary>Repeat this service</summary><div class="form-grid">${field('Every how many miles?','interval_miles','','number','min="1"')}${field('Every how many days?','interval_days','','number','min="1"')}</div></details><button class="primary">Add plan</button></form></details>
    <div class="metrics">${d.vehicles.map(v=>{const t=(o.telemetry||[]).find(t=>t.device_id===v.id);return `<div class="card"><h3>${e(v.name)}</h3><strong>${number(t?.details.odometer)} miles</strong><small>Updated ${e(date(t?.details.timestamps?.odometer))}</small></div>`;}).join('')}</div>`;
}
function alerts(d){
    const rows=d.operations?.alerts||[];
    return `<div class="card"><p>Assign someone to check the vehicle. Marking an alert resolved does not clear its engine code.</p>${rows.length?rows.map(a=>`<details class="ops-item"><summary><span class="pill ${a.status==='resolved'?'':'warn'}">${e({open:"Open",in_progress:"In progress",resolved:"Resolved"}[a.status])}</span> ${e(deviceName(d,a.device_id))} · ${e(a.details.type==='battery'?'Low battery':'Check engine')}</summary><p>Reading: ${e(a.details.value)} · Reported ${e(date(a.event_at))}</p><form data-alert="${a.id}" class="form-grid">${suggestions('Assigned to','assignee',a.details.assignee||'',people(d),'maxlength="180"')}<label>Status<select name="status">${['open','in_progress','resolved'].map(s=>`<option value="${s}" ${a.status===s?'selected':''}>${{open:"Open",in_progress:"In progress",resolved:"Resolved"}[s]}</option>`).join('')}</select></label>${field('What was done?','resolution',a.details.resolution||'')}<button class="primary">Save alert</button></form></details>`).join(''):'<p>No vehicle alerts.</p>'}</div>`;
}
function trips(d){
    const rows=d.operations?.trips||[],jobs=d.operations?.jobs||[];
    return `<div class="card">${rows.length?rows.map(t=>{const p=t.details;const flagged=t.route_review==='review';return `<details class="ops-item"><summary>${e(deviceName(d,t.device_id))} · ${number(p.distance_miles)} miles · <span class="pill ${flagged?'warn':''}">${e({baseline_needed:'Not checked',distance_pending:'Distance pending',review:'Needs review',within_tolerance:'Within expected distance'}[t.route_review])}</span>${p.review_status==='approved_detour'?'<span class="pill">Approved detour</span>':''}</summary><p>${e(date(p.started_at))} → ${e(date(p.ended_at))} · Drive time ${t.drive_seconds==null?'Pending':`${number(t.drive_seconds/60)} minutes`} · Idle ${p.idle_seconds==null?'Pending':`${number(p.idle_seconds/60)} minutes`}</p><p>${t.candidates.length>1?'Several scheduled jobs could match. Confirm the correct job below.':t.candidates.length===1?`Possible scheduled match: ${e(t.candidates[0].title)}. Confirm below.`:'Choose the job for this trip below.'}</p><button data-path="${t.id}">View route</button><div id="path-${t.id}"></div><form data-trip="${t.id}" class="form-grid">${picker('Job','job_id',p.job_id,jobs.map(j=>({id:j.id,label:j.details.title,detail:address(j.details)})),'Search jobs or addresses')}<details class="wide"><summary>Distance checks</summary><p>Compare this trip with an agreed road distance. Longer trips need a person to review them.</p><div class="form-grid">${field('Expected driving miles','expected_miles',p.expected_miles??'','number','min="0.1" step="any"')}${field('Where did this distance come from?','baseline_reference',p.baseline_reference||'')}${field('Allow extra miles','extra_miles',p.extra_miles??5,'number','required min="0" step="any"')}${field('Allow extra percent','extra_percent',p.extra_percent??30,'number','required min="0" step="any"')}</div></details><label>Review<select name="review_status">${['pending','approved_detour','investigate'].map(s=>`<option value="${s}" ${p.review_status===s?'selected':''}>${{pending:"Not reviewed",approved_detour:"Detour approved",investigate:"Needs follow-up"}[s]}</option>`).join('')}</select></label>${field('Notes (required for a detour)','review_note',p.review_note||'')}<button class="primary">Save trip</button></form></details>`;}).join(''):'<p>Trips will appear after tracking is active and a vehicle has completed a trip.</p>'}</div>`;
}
export function operationsView(page,d){return page==='calendar'&&d.demo?calendarView(d):page==='health'?health(d):page==='jobs'?jobsView(d):page==='maintenance'?maintenance(d):page==='alerts'?alerts(d):trips(d);}
export function bindOperations(root,d,api,run,refresh,notice,openJob){
    const save=(form,path,convert,method='PUT')=>{if(!form)return;form.onsubmit=event=>{event.preventDefault();run(async()=>{const p=convert(new FormData(form));notice('Saving…');const drafts=[...root.querySelectorAll('form[data-edited]')].filter(other=>other!==form).map(other=>({form:other,key:['profile','job','trip','alert','service'].find(key=>other.dataset[key]),id:other.id}));await api(`operations/${path}`,method,p);await refresh();for(const draft of drafts){const selector=draft.key?`form[data-${draft.key}="${draft.form.dataset[draft.key]}"]`:`#${draft.id}`;const replacement=root.querySelector(selector);if(replacement){replacement.replaceWith(draft.form);draft.form.closest('details')?.setAttribute('open','');draft.form.dispatchEvent(new Event('change',{bubbles:true}));}}notice('Saved.');});};};
    bindInputs(root);
    root.querySelectorAll('[data-open-job]').forEach(button=>button.onclick=()=>openJob?.(Number(button.dataset.openJob)));
    root.querySelectorAll('form').forEach(form=>{for(const event of ['input','change'])form.addEventListener(event,()=>{form.dataset.edited='true';});});
    const common=form=>readRequirements(form);
    const val=(f,key)=>String(f.get(key)||'').trim()||null;
    const num=(f,key)=>val(f,key)===null?null:Number(f.get(key));
    const jobBody=form=>{
        const f=new FormData(form),j=d.operations.jobs.find(j=>j.id===Number(form.dataset.job)),p=j.details;
        const start=val(f,'scheduled_start'),end=val(f,'scheduled_end');
        if(Boolean(start)!==Boolean(end))throw new Error('Choose both a start and finish time.');
        if(start&&new Date(end)<=new Date(start))throw new Error('Finish must be after the start time.');
        const selectedAddress=val(f,'address');
        const source=d.operations.jobs.find(other=>address(other.details)===selectedAddress);
        return {...common(form),device_id:num(f,'device_id'),address:selectedAddress,location_job_id:source?.id||null,
            scheduled_start:start&&(localDate(p.crm_start)!==start||localDate(p.crm_end)!==end)?new Date(start).toISOString():null,
            scheduled_end:end&&(localDate(p.crm_start)!==start||localDate(p.crm_end)!==end)?new Date(end).toISOString():null};
    };
    root.querySelectorAll('[data-profile]').forEach(form=>save(form,`profiles/${form.dataset.profile}`,f=>({...common(form),crew:String(f.get('crew')||''),available:f.has('available')})));
    root.querySelectorAll('[data-job]').forEach(form=>{
        save(form,`jobs/${form.dataset.job}`,()=>jobBody(form));
        const reset=form.querySelector('[data-reset-time]');if(reset)reset.onclick=()=>{const p=d.operations.jobs.find(j=>j.id===Number(form.dataset.job)).details;form.elements.scheduled_start.value=localDate(p.crm_start);form.elements.scheduled_end.value=localDate(p.crm_end);form.dispatchEvent(new Event('change',{bubbles:true}));};
    });
    root.querySelectorAll('[data-list-search]').forEach(input=>{
        const kind=input.dataset.listSearch,filter=root.querySelector('#job-filter');
        const apply=()=>{let count=0;root.querySelectorAll(`[data-list-item="${kind}"]`).forEach(el=>{
            const j=d.operations.jobs?.find(j=>j.id===Number(el.dataset.jobId)),p=j?.details,start=p&&(p.scheduled_start||p.crm_start),mode=filter?.value||'all';
            const matches=!j||mode==='all'||(mode==='unassigned'&&!p.device_id)||(mode==='today'&&start&&localDate(start).slice(0,10)===localDate(new Date().toISOString()).slice(0,10))||(mode==='upcoming'&&start&&Date.parse(start)>Date.now());
            el.hidden=!matches||!el.dataset.search.includes(input.value.toLowerCase());if(!el.hidden)count++;
        });root.querySelector(`[data-list-count="${kind}"]`).textContent=`${count} ${kind}`;const empty=root.querySelector(`[data-list-empty="${kind}"]`);if(empty)empty.hidden=count>0;};input.oninput=apply;if(filter)filter.onchange=apply;apply();
    });
    save(root.querySelector('#plan-form'),'plans',f=>({device_id:num(f,'device_id'),title:f.get('title'),assignee:val(f,'assignee'),due_miles:num(f,'due_miles'),due_date:val(f,'due_date'),interval_miles:num(f,'interval_miles'),interval_days:num(f,'interval_days'),estimated_cost:num(f,'estimated_cost')}),'POST');
    root.querySelectorAll('[data-service]').forEach(form=>save(form,'service',f=>({plan_id:Number(form.dataset.service),odometer:num(f,'odometer'),serviced_at:f.get('serviced_at'),notes:val(f,'notes'),actual_cost:num(f,'actual_cost')}),'POST'));
    root.querySelectorAll('[data-alert]').forEach(form=>save(form,`alerts/${form.dataset.alert}`,f=>({assignee:String(f.get('assignee')||''),status:f.get('status'),resolution:val(f,'resolution')})));
    root.querySelectorAll('[data-trip]').forEach(form=>save(form,`trips/${form.dataset.trip}`,f=>({job_id:num(f,'job_id'),expected_miles:num(f,'expected_miles'),baseline_reference:val(f,'baseline_reference'),extra_miles:num(f,'extra_miles'),extra_percent:num(f,'extra_percent'),review_status:f.get('review_status'),review_note:val(f,'review_note')})));
    root.querySelectorAll('[data-dispatch]').forEach(button=>button.onclick=()=>run(async()=>{
        const id=button.dataset.dispatch,form=root.querySelector(`[data-job="${id}"]`);
        if(!form.reportValidity())return;
        await api(`operations/jobs/${id}`,'PUT',jobBody(form));
        notice('Assignment saved. Checking available vans…');
        const result=await api(`operations/jobs/${id}/dispatch`);const target=root.querySelector(`#dispatch-${id}`);
        target.innerHTML=`<p>Available vans with the job types and materials needed. Distances are approximate, not driving miles.</p>${result.location_provider==='google'?'<small class="google-attribution" translate="no">Google Maps</small>':''}${result.suggestions.length?result.suggestions.map(s=>`<div class="dispatch-result"><span><strong>${e(s.name)}</strong><small>${e(s.crew)} · about ${number(s.straight_line_miles)} miles away</small></span><button data-choose="${s.device_id}">Assign van</button></div>`).join(''):'<p>No matching vans. Check crew availability, job times and materials in Vans.</p>'}`;
        target.querySelectorAll('[data-choose]').forEach(b=>b.onclick=()=>run(async()=>{await api(`operations/jobs/${id}`,'PUT',{...jobBody(form),device_id:Number(b.dataset.choose)});await refresh();notice('Van assigned.');}));
        notice('Assignment saved. Choose a van below.');
    }));
    root.querySelectorAll('[data-path]').forEach(button=>button.onclick=()=>run(async()=>{
        const result=await api(`operations/trips/${button.dataset.path}/path`);const target=root.querySelector(`#path-${button.dataset.path}`);
        const segments=(result.trips||[]).flatMap(t=>(t.segments||[]).map((points,i)=>({points,line:t.trace_segments?.[i]===true&&points.length>=2})));
        const samples=result.points||[],bounds=[...segments.flatMap(s=>s.points),...samples.map(p=>[p.lat,p.lng])];
        target.innerHTML=`<p>${d.demo?'Example driving route along real roads.':segments.some(s=>s.line)?'Recorded driving route.':'Only a few locations were recorded. Dots show those locations; a full route is unavailable.'} ${result.truncated?'Showing the first 2,000 samples.':''}</p>${bounds.length?'<div class="trip-map map"></div>':'<p>No retained GPS trace is available for this trip.</p>'}`;
        if(bounds.length){const m=baseMap(target.querySelector('.trip-map'),d);for(const segment of segments){if(segment.line)L.polyline(segment.points,{color:'#216347',weight:4}).addTo(m);else segment.points.forEach(p=>L.circleMarker(p,{color:'#216347',radius:4}).addTo(m));}samples.forEach(p=>L.circleMarker([p.lat,p.lng],{color:'#216347',radius:4}).addTo(m));m.fitBounds(bounds,{padding:[30,30],maxZoom:16});}
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
    for(const j of d.operations?.jobs||[]){const p=j.details;if(p.latitude==null||p.longitude==null||p.location_provider==='google')continue;fallbackLayers.push(L.marker([p.latitude,p.longitude],{alt:`Job: ${p.title}`,icon:L.divIcon({className:'job-pin',html:'<span>J</span>',iconSize:[22,22]})}).addTo(fallbackMap).bindPopup(`Job: ${e(p.title)}`));}
    if(created&&bounds.length)fallbackMap.fitBounds(bounds,{padding:[40,40],maxZoom:14});
    return fallbackMap;
}
export function panFallback(location){if(fallbackMap&&location)fallbackMap.panTo([location.latitude,location.longitude]);}
