import './fleet.css';
import {createDemo, demoRequest} from './demo.js';
import {operationsView, bindOperations, jobContext, drawFallbackMap, panFallback} from './operations.js';

const config = JSON.parse(document.getElementById('fleet-config').textContent);
const root = document.getElementById('fleet-app');
let demo = Boolean(config.demo), demoData=null;
let token = null, expiresAt = 0, parentOrigin = null, data = null, page = 'fleet', selected = null, search = '', devices = null, busy = false;
const labels = {fleet:'Fleet', health:'Connection health', jobs:'Jobs & dispatch', trips:'Trips & routes', maintenance:'Maintenance', alerts:'Health alerts', connection:'Connection', settings:'Settings'};
let mapPromise = null, map = null, markers = [], popup = null, authPromise = null;
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const vehicleLimit = () => Number(data.settings.vehicle_limit);
const when = (iso) => iso ? new Date(iso).toLocaleString() : 'No location reported';
function notice(message, error = false) {
    const target = document.getElementById('notice');
    if (target) { target.textContent = message; target.className = error ? 'banner error' : 'banner'; }
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
        const challengeResponse = await fetch('/crm/fleet/session/challenge', {credentials:'omit', headers:{Accept:'application/json'}});
        if (!challengeResponse.ok) throw new Error('Everbranch Fleet is awaiting activation.');
        const {challenge} = await challengeResponse.json();
        const user = await context();
        const response = await fetch('/crm/fleet/session/exchange', {method:'POST',credentials:'omit',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({encryptedData:user.payload, parentOrigin:user.origin, challenge})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Administrator access could not be verified.');
        token = result.token; expiresAt = Date.parse(result.expires_at); parentOrigin = user.origin;
    })();
    try { await authPromise; } finally { authPromise = null; }
}
async function api(path, method = 'GET', body = null) {
    if(demo)return demoRequest(demoData,path,method,body);
    if (!token || expiresAt < Date.now() + 60000) await authorize();
    const response = await fetch(`/crm/fleet/api/${path}`, {method,credentials:'omit',headers:{Accept:'application/json','Content-Type':'application/json',Authorization:`Bearer ${token}`,'X-Everbranch-Parent-Origin':parentOrigin},...(body !== null ? {body:JSON.stringify(body)} : {})});
    const result = await response.json();
    if (!response.ok) {
        if (response.status === 401 || response.status === 403) { token = null; expiresAt = 0; }
        throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'The request could not be completed.');
    }
    return result;
}
function statusBanner() {
    if (!data.subscription.has_access && !data.subscription.required) return '<div class="banner error"><strong>Fleet access unavailable</strong><p>An agency administrator must reinstall the app to restore access.</p></div>';
    if (!data.subscription.has_access) return '<div class="banner error"><strong>Subscription requires attention</strong><p>An agency administrator can review the app subscription in your CRM. Fleet access resumes after CRM confirms payment.</p></div>';
    if (!data.subscription.collection_active) return '<div class="banner"><strong>Activation pending</strong><p>Your installation is connected. Location collection is awaiting pilot activation. You can connect Bouncie, select vehicles, and save your settings now.</p></div>';
    if (data.subscription.required && data.subscription.status === 'FAILED') return `<div class="banner"><strong>Payment requires attention</strong><p>Fleet remains available during the payment grace period, ending ${escape(when(data.subscription.grace_ends_at))}. Review billing with your agency administrator.</p></div>`;
    if (!data.settings.policy_approved || !data.settings.tracking_enabled) return '<div class="banner"><strong>Setup required</strong><p>Approve your company vehicle policy and enable collection in Settings.</p></div>';
    if (data.connection.status !== 'connected') return '<div class="banner"><strong>Bouncie disconnected</strong><p>Connect your Bouncie account to select and track company vehicles. Bouncie subscriptions are billed separately.</p></div>';
    if (data.connection.health.status === 'unavailable') return '<div class="banner error"><strong>Bouncie could not be refreshed</strong><p>Saved positions remain visible. Check the reported timestamps and try reconnecting if the problem continues.</p></div>';
    return '';
}
function render() {
    if (!data) return;
    root.innerHTML = `<main class="shell"><header class="topbar"><div class="brand">Everbranch <span>Fleet</span></div><div class="account">${escape(data.workspace.name)} <button id="toggle-demo">${demo?'Exit demo':'Explore demo'}</button></div></header>
        <div class="heading"><div><h1>${page === 'fleet' ? 'Your fleet, in view' : page === 'connection' ? 'Bouncie connection' : labels[page]}</h1><p>${page === 'fleet' ? 'The latest reported locations of your company vehicles.' : page === 'connection' ? 'One Bouncie account. A separate workspace for this client.' : page==='settings' ? 'Control company vehicle collection and location retention.' : 'Manage your fleet using CRM work and provider readings.'}</p></div><span id="vehicle-total" class="pill">${data.vehicles.length} / ${vehicleLimit()} vehicles</span></div>
        <nav class="tabs" aria-label="Fleet navigation">${Object.keys(labels).map(tab => `<button data-page="${tab}" class="${page===tab?'active':''}" ${page===tab?'aria-current="page"':''}>${labels[tab]}</button>`).join('')}</nav>
        ${demo?'<div class="banner demo-banner"><strong>DEMO · Fictional fleet data</strong><p>Explore every feature. Changes stay in this browser and do not affect a client account or connect Bouncie.</p></div>':''}<div id="status-banner">${demo?'':statusBanner()}</div><div id="notice" role="status"></div><section id="content">${page === 'fleet' ? fleetView() : page === 'connection' ? connectionView() : page === 'settings' ? settingsView() : operationsView(page,data)}</section>
        <footer class="footer"><span>Only company vehicles · Up to 30 days of location history</span><a href="mailto:${escape(data.support_email)}">Contact support</a></footer></main>`;
    bind();
    if (page === 'fleet' && data.vehicles.length) { updateList(); drawMap().catch(() => { const el=document.getElementById('map'); if(el) el.innerHTML='<p>The map could not load. Reported locations remain available in vehicle details.</p>'; }); }
}
function fleetView() {
    if (!data.vehicles.length) return `<div class="card empty"><span class="pill">No vehicles selected</span><h2>Your fleet starts here</h2><p>Connect Bouncie, then choose up to ${vehicleLimit()} vehicles for this workspace.</p><button data-page="connection" class="primary">Set up connection</button></div>`;
    return '<div class="fleet-layout"><aside class="card fleet-list"><label class="sr-only" for="search">Search vehicles</label><input id="search" type="search" placeholder="Search vehicles" autocomplete="off"><div id="vehicle-list"></div></aside><div class="card map-card"><div class="map-toolbar"><h2>Fleet map</h2><small>Refreshes every 60 seconds</small></div><div class="map-legend"><span class="legend-dot green"></span> Vehicles <span class="legend-dot amber"></span> Older reading <span class="legend-dot blue"></span> CRM job stops</div><div id="map" class="map"><p>Loading map…</p></div><div id="vehicle-details" class="details"></div></div></div>';
}
function updateList() {
    const vehicle = data.vehicles.find(v => v.id === selected) || data.vehicles[0];
    selected = vehicle.id;
    const ctx=jobContext(data,vehicle.id);
    const visible = data.vehicles.filter(v => `${v.name} ${v.device_id}`.toLowerCase().includes(search.toLowerCase()));
    document.getElementById('vehicle-list').innerHTML = visible.map(v => `<button class="vehicle-row ${selected===v.id?'selected':''}" data-vehicle="${v.id}"><strong>${escape(v.name)}</strong><small>${v.location ? (v.location.older_reading?'Older location reading':'Latest reported location') : 'Awaiting first location'}</small><small>${escape(when(v.location?.reported_at))}</small><small>${escape(jobContext(data,v.id).crew)}</small></button>`).join('') || '<p>No matching vehicles.</p>';
    document.getElementById('vehicle-details').innerHTML = `<div><h3>${escape(vehicle.name)}</h3><p>${vehicle.location ? `${vehicle.location.latitude.toFixed(5)}, ${vehicle.location.longitude.toFixed(5)}` : 'No valid location received yet.'}</p></div><div><strong>${escape(ctx.crew)}</strong><p>Current: ${escape(ctx.current?.details.title || 'No scheduled job')}</p><p>Next: ${escape(ctx.next?.details.title || 'No scheduled stop')}</p></div><div><strong>Provider last reported</strong><p>${escape(when(vehicle.location?.reported_at))}</p>${vehicle.location?.older_reading ? '<p>An older reading can mean the vehicle is parked.</p>' : ''}</div>`;
}
function connectionView() {
    if(demo)return '<div class="card"><h2>Demo Bouncie connection</h2><p>Six fictional vehicles show what the fleet will look like after activation. Connection controls are available in your real workspace.</p></div>';
    return `<div class="card"><h2>${data.connection.status==='connected' ? 'Bouncie connected' : 'Connect your Bouncie account'}</h2><p>${escape(data.connection.label || 'Authorize the Bouncie account that owns this client’s devices.')}</p><span class="pill ${data.connection.health.status==='unavailable'?'error':''}">${escape(data.connection.health.status)}</span><div class="actions"><button id="connect" class="primary" ${!data.subscription.setup_allowed?'disabled':''}>${data.connection.status==='connected'?'Reconnect Bouncie':'Connect Bouncie'}</button>${data.connection.status==='connected'?'<button id="load-devices">Choose vehicles</button><button id="disconnect" class="danger">Disconnect Bouncie</button>':''}</div><p class="notice">Disconnecting or uninstalling Everbranch does not cancel your Bouncie subscription.</p></div>
    ${devices ? `<div class="card"><h2>Choose up to ${vehicleLimit()} vehicles</h2><p>Each selected device belongs only to this workspace.</p><form id="device-form"><div class="device-grid">${devices.map(v => `<label class="device"><input type="checkbox" name="devices" value="${escape(v.id)}" ${v.selected?'checked':''}><span>${escape(v.name)}<small>${escape(v.id)}</small></span></label>`).join('')}</div><div class="actions"><button class="primary" type="submit">Save vehicle selection</button><span id="selected-count"></span></div></form></div>` : ''}`;
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
    <div class="actions"><button class="primary" type="submit" ${!data.subscription.setup_allowed?'disabled':''}>Save settings</button></div></form></div>${data.subscription.required ? '<div class="card"><h2>Subscription</h2><p>Managed by your agency through the CRM app subscription. Bouncie is billed separately. Removing the app ends Everbranch access; your agency administrator manages app billing and uninstall in the CRM.</p></div>' : '<div class="card"><h2>App access</h2><p>Everbranch Fleet is free for now. No Everbranch app subscription is required. Bouncie is billed separately. Uninstalling the app ends Everbranch access.</p></div>'}`;
}
async function run(action) { if(busy)return; busy=true; try{await action();}catch(error){notice(error.message,true);}finally{busy=false;} }
function bind() {
    const toggle=document.getElementById('toggle-demo');if(toggle)toggle.onclick=()=>{if(config.demo){window.location.href='/crm/fleet/guide';return;}window.open('/crm/fleet/demo','_blank','noopener');};
    bindOperations(root,data,api,run,refresh,notice);
    root.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{page=b.dataset.page; render();});
    const searchInput=document.getElementById('search');
    if(searchInput){searchInput.value=search;searchInput.oninput=()=>{search=searchInput.value;updateList();};document.getElementById('vehicle-list').onclick=e=>{const b=e.target.closest('[data-vehicle]');if(!b)return;selected=Number(b.dataset.vehicle);updateList();const v=data.vehicles.find(v=>v.id===selected);if(map&&v?.location){map.panTo({lat:v.location.latitude,lng:v.location.longitude});}else if(v?.location){panFallback(v.location);}};}
    const connect=document.getElementById('connect');
    if(connect)connect.onclick=()=>{
        popup=window.open('about:blank','everbranch-bouncie','width=600,height=760');
        if(!popup){notice('Allow pop-up windows for Everbranch to connect Bouncie.',true);return;}
        run(async()=>{try{const result=await api('bouncie/connect','POST',{});popup.location.href=result.url;}catch(e){popup.close();throw e;}});
    };
    const load=document.getElementById('load-devices');if(load)load.onclick=()=>run(async()=>{devices=(await api('devices')).devices;render();});
    const disconnect=document.getElementById('disconnect');if(disconnect)disconnect.onclick=()=>run(async()=>{if(!window.confirm('Disconnect Bouncie and stop collection? Your Bouncie subscription stays active.'))return;const result=await api('bouncie/disconnect','POST',{});devices=null;await refresh();notice(result.message);});
    const deviceForm=document.getElementById('device-form');
    if(deviceForm){const count=()=>{const n=deviceForm.querySelectorAll('input:checked').length;document.getElementById('selected-count').textContent=`${n} / ${vehicleLimit()} selected`;deviceForm.querySelector('button').disabled=n>vehicleLimit();};deviceForm.onchange=count;count();deviceForm.onsubmit=e=>{e.preventDefault();run(async()=>{await api('devices','PUT',{devices:[...deviceForm.querySelectorAll('input:checked')].map(i=>i.value)});devices=null;page='fleet';await refresh();notice('Vehicle selection saved.');});};}
    const form=document.getElementById('settings-form');if(form)form.onsubmit=e=>{e.preventDefault();run(async()=>{const f=new FormData(form),text=String(f.get('policy_text')||'').trim();let hash=data.settings.policy_sha256;if(text){hash=[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(text)))].map(b=>b.toString(16).padStart(2,'0')).join('');}await api('settings','PUT',{retention_days:Number(f.get('retention_days')),policy_version:f.get('policy_version'),policy_sha256:hash,approval_reference:f.get('approval_reference'),approval_confirmed:f.has('approval_confirmed'),tracking_enabled:f.has('tracking_enabled')});await refresh();notice('Policy and retention settings saved.');});};
}
async function drawMap() {
    const target=document.getElementById('map');if(!target)return;
    if(!data.map_key){drawFallbackMap(target,data,id=>{selected=id;updateList();});return;}
    if(!mapPromise)mapPromise=new Promise((resolve,reject)=>{window.everbranchFleetMapReady=resolve;const s=document.createElement('script');s.src=`https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(data.map_key)}&callback=everbranchFleetMapReady&loading=async&libraries=marker`;s.async=true;s.referrerPolicy='origin';s.onerror=reject;document.head.appendChild(s);});
    await mapPromise;if(page!=='fleet'||!target.isConnected)return;
    let newMap=false;
    if(!map||map.getDiv()!==target){newMap=true;map=new window.google.maps.Map(target,{center:{lat:39,lng:-98},zoom:4,mapTypeControl:false,streetViewControl:false,...(demo?{mapId:'DEMO_MAP_ID'}:{})});markers=[];}
    markers.forEach(m=>{if(demo)m.map=null;else m.setMap(null);});markers=[];
    const bounds=new window.google.maps.LatLngBounds();
    data.vehicles.filter(v=>v.location).forEach(v=>{const position={lat:v.location.latitude,lng:v.location.longitude};bounds.extend(position);const pin=demo?new window.google.maps.marker.PinElement({background:v.location.older_reading?'#b98538':'#386b52',borderColor:'#fff',glyphColor:'#fff',glyphText:String(v.id)}):null;const marker=demo?new window.google.maps.marker.AdvancedMarkerElement({map,position,title:v.name,content:pin}):new window.google.maps.Marker({map,position,title:v.name});marker.addListener('click',()=>{selected=v.id;updateList();});markers.push(marker);});
    const vehicleMarkerCount=markers.length;
    (data.operations?.jobs||[]).forEach(j=>{const p=j.details;if(p.latitude==null||p.longitude==null)return;const position={lat:Number(p.latitude),lng:Number(p.longitude)},title=`CRM stop: ${p.title}`;markers.push(demo?new window.google.maps.marker.AdvancedMarkerElement({map,position,title,content:new window.google.maps.marker.PinElement({background:'#5278a8',borderColor:'#fff',glyphColor:'#fff',glyphText:'J',scale:.8})}):new window.google.maps.Marker({map,position,title,icon:{path:window.google.maps.SymbolPath.CIRCLE,scale:5,fillColor:'#5278a8',fillOpacity:1,strokeWeight:1}}));});
    if(newMap&&vehicleMarkerCount===1){map.setCenter(bounds.getCenter());map.setZoom(14);}else if(newMap&&vehicleMarkerCount>1){map.fitBounds(bounds,45);}
}
async function refresh() {
    data=await api('bootstrap');
    if(page==='fleet' && document.getElementById('map') && data.vehicles.length){document.getElementById('status-banner').innerHTML=demo?'':statusBanner();document.getElementById('vehicle-total').textContent=`${data.vehicles.length} / ${vehicleLimit()} vehicles`;updateList();await drawMap();}else{render();}
}
window.addEventListener('message',event=>{if(event.origin===window.location.origin&&event.source===popup&&event.data?.message==='EVERBRANCH_BOUNCIE_CONNECTED'){popup.close();devices=null;run(async()=>{await refresh();notice('Bouncie connected. Choose your vehicles.');});}});
setInterval(()=>{if(!document.hidden&&!busy&&data&&!['settings','jobs','trips','maintenance','alerts'].includes(page)&&(page!=='connection'||!devices))run(refresh);},60000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&data&&!busy&&!['settings','jobs','trips','maintenance','alerts'].includes(page)&&(page!=='connection'||!devices))run(refresh);});
(demo ? (demoData=createDemo(),demoData.map_key=config.demoMapKey||null,Promise.resolve()) : authorize()).then(refresh).catch(error=>{root.innerHTML=`<div class="launch-card"><div class="brand">Everbranch <span>Fleet</span></div><h1>Unable to open Fleet</h1><p>${escape(error.message)}</p><button id="retry">Try again</button></div>`;document.getElementById('retry').onclick=()=>window.location.reload();});
