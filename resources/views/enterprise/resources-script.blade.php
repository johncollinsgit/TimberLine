@php($campusResourceCatalog = $enterprisePresentation['locations'] ?? app(\App\Enterprise\LocationCatalog::class)->all())
  const resourceCatalog = @json($campusResourceCatalog);
  const resourceSections = ['buildings', 'assets', 'calendar'];
  const resourceDraftPrefix = `everbranch-resource:${transport.mode}:${workspace}:${viewer}:`;
  let resourceGeneration=0, resourceContext='', resourceResult=null, resourceItems=[], resourceDirectory='catalog', resourceSelection='', resourceForm=null, resourceBusy=false, resourceTicketSelection='';
  const resourceEndpoint=(path='',query={})=>`${nextBase}/resources${path}?${new URLSearchParams({tenant:tenantSlug,...query})}`;
  const resourceActive=()=>resourceSections.includes(section);
  const resourceCurrent=token=>token.generation===resourceGeneration&&token.context===resourceContext&&resourceActive();
  const resourceToken=()=>({generation:resourceGeneration,context:resourceContext});
  const resourceDatum=row=>row?.data||{};
  const resourceName=id=>resourceItems.find(row=>row.id===id)?.title||'Linked record outside this result';
  const resourceStamp=value=>value&&Number.isFinite(Date.parse(value))?`${new Intl.DateTimeFormat('en-US',{timeZone:'America/New_York',dateStyle:'medium',timeStyle:'short'}).format(new Date(value))} Eastern · ${new Date(value).toISOString().replace('.000Z','Z')}`:'Not recorded';
  const resourceMapNumber=row=>String(resourceDatum(row).map_number||resourceDatum(row).catalog_map_number||'');
  const resourceNotice=(text,error=false)=>{$('res-status').textContent=text;$('res-status').classList.toggle('notice-error',error);};
  const resourceFact=(label,value)=>`<dt>${esc(label)}</dt><dd>${esc(value??'Not recorded')}</dd>`;
  const resourceButton=(action,label,extra='')=>`<button type="button" class="button" data-res-action="${esc(action)}" ${extra}>${esc(label)}</button>`;
  function resourceProvenance(entry) {
    const label=entry.source_label||resourceCatalog.source_label||'Source document';
    const page=entry.source_page??resourceCatalog.source_page;
    let link=null;
    try {const url=new URL(entry.source_url||resourceCatalog.source_url);if(['https:','http:'].includes(url.protocol))link=url.href;}catch{}
    return `<p class="caption">Source: ${esc(label)}${page!==null&&page!==undefined&&page!==''?`, page ${esc(page)}`:''}.${link?` <a href="${esc(link)}" target="_blank" rel="noopener noreferrer">Open source</a>.`:''} This is a source reference, not a live inventory assertion.</p>`;
  }
  function resourceSafeLink(value) {
    try {const resolved=transport.href(value);const url=new URL(resolved,location.href);return url.origin===location.origin&&['http:','https:'].includes(url.protocol)?url.href:null;}catch{return null;}
  }
  function resourceRemember() {selected=resourceSelection||null;selectionMemory.set(area+':'+section,selected);rememberUrl();}
  function resourceSelect(id) {resourceSelection=id;resourceTicketSelection='';resourceRemember();resourceRenderList();resourceRenderDetail();buildingStep('tickets');}
  function resourceSearch() {return $('res-query').value.trim().toLocaleLowerCase();}
  function resourceMatches(row) {return [row.title,row.name,row.map_number,...Object.values(row.data||row)].filter(value=>typeof value==='string').join(' ').toLocaleLowerCase().includes(resourceSearch());}
  const buildingPriority = row => ['urgent','high','normal','low'].includes('priority' in row ? row.priority : row.task?.priority) ? ('priority' in row ? row.priority : row.task.priority) : null;
  function resourceClearMap() {
    const plan=$('res-plan');if(plan){plan.hidden=true;plan.open=false;}
    if($('res-plan-selection'))$('res-plan-selection').textContent='Choose a building to keep its map reference here.';
  }
  const buildingOpen = row => !['completed','closed','cancelled','rejected'].includes(row.status);
  const buildingText = value => String(value||'').trim().toLocaleLowerCase();
  function buildingContext(id=resourceSelection) {
    if(id.startsWith('catalog:')) {
      const source=resourceCatalog.entries.find(row=>row.map_number===id.slice(8));
      if(!source)return null;
      const saved=resourceItems.filter(row=>row.kind==='location'&&resourceMapNumber(row)===String(source.map_number));
      return {name:source.name,mapNumber:String(source.map_number),ids:saved.map(row=>row.id),names:[source.name,...saved.map(row=>row.title)]};
    }
    const row=resourceItems.find(row=>row.id===id&&row.kind==='location');
    return row?{name:row.title,mapNumber:resourceMapNumber(row),ids:[row.id],names:[row.title]}:null;
  }
  function buildingTickets(id=resourceSelection) {
    const building=buildingContext(id);
    if(!building||!resourceResult)return [];
    const linked=row=>[row.location_id,row.resource_id,row.data?.location_id,row.data?.resource_id].some(value=>value&&building.ids.includes(value));
    const named=row=>building.names.some(name=>buildingText(name)&&buildingText(name)===buildingText(row.data?.location));
    // Text is a useful lead, never a verified resource relationship. Do not fuzzy-match it.
    return (data.requests||[]).filter(row=>row.area_id===area&&(linked(row)||named(row))).map(row=>({row,linked:linked(row)})).sort((a,b)=>
      Number(buildingOpen(b.row))-Number(buildingOpen(a.row)) ||
      (['urgent','high','normal','low',null].indexOf(buildingPriority(a.row))-['urgent','high','normal','low',null].indexOf(buildingPriority(b.row))) ||
      String(a.row.task?.due_on||a.row.due_on||'9999').localeCompare(String(b.row.task?.due_on||b.row.due_on||'9999')) || String(a.row.title).localeCompare(String(b.row.title)));
  }
  function buildingCounts(id) {
    if(!resourceResult)return '<small>Ticket result not confirmed</small>';
    const linked=buildingTickets(id).filter(ticket=>ticket.linked);
    const summary=(items,kind)=>`${items.filter(ticket=>buildingOpen(ticket.row)).length} open ${kind} · ${items.filter(ticket=>buildingOpen(ticket.row)&&buildingPriority(ticket.row)==='urgent').length} urgent ${kind}`;
    return `<small class="building-count">${linked.length?esc(summary(linked,'linked')):'No tickets in this view'}</small>`;
  }
  function buildingAssignee(row) {
    if(!row.task)return 'Not assigned';
    if(!row.task.assignee_id)return 'Unassigned';
    return data.members.find(member=>String(member.id)===String(row.task.assignee_id))?.name||'Assigned member · name unavailable';
  }
  function buildingStep(step,focus=true) {
    if(section!=='buildings')return;
    $('res-columns').dataset.buildingStep=step;
    if(focus&&matchMedia('(max-width:1100px)').matches) {
      const target=step==='buildings'?$('res-query'):step==='tickets'?$('res-ticket-heading'):$('res-detail-heading');
      target?.focus({preventScroll:true});const panel=step==='buildings'?$('res-master-heading').parentElement:step==='tickets'?$('res-tickets'):$('res-detail');panel?.scrollIntoView({block:'start'});
    }
  }
  function buildingRenderTickets() {
    const host=$('res-tickets'),building=buildingContext();
    host.hidden=section!=='buildings';
    if(section!=='buildings')return;
    const tickets=buildingTickets(),linked=tickets.filter(ticket=>ticket.linked),mentions=tickets.filter(ticket=>!ticket.linked);
    const card=({row,linked})=>`<button type="button" class="building-ticket" data-building-ticket="${esc(row.id)}" aria-pressed="${resourceTicketSelection===row.id}"><span class="building-priority" data-priority="${esc(buildingPriority(row)||'unset')}">${esc(buildingPriority(row)||'Priority not set')}</span><strong>${esc(row.title)}</strong><small>Status: ${esc(String(row.status).replaceAll('_',' '))}</small><small>Assignee: ${esc(buildingAssignee(row))}</small><small>Due: ${esc(row.task?.due_on||row.due_on||'Unscheduled')}</small><small>${linked?'Explicit building link':'Location text match · unverified'}</small></button>`;
    host.innerHTML=`<button class="button building-back" type="button" data-building-back="buildings">← Buildings</button><span class="eyebrow">2. Tickets</span><h2 id="res-ticket-heading" tabindex="-1">${building?esc(building.name):'Choose a building'}</h2><p class="caption">Linked tickets: open first, highest priority first.</p>${building?buildingCounts(resourceSelection):''}<div class="building-ticket-list" data-building-linked>${!resourceResult?'<p class="caption">Checking access.</p>':!building?'<p class="caption">Search buildings, then choose a location.</p>':linked.map(card).join('')||`<p class="caption building-linkage">No tickets in this view.</p>`}</div>${mentions.length?`<section data-building-mentions><details><summary>Possible text matches (${mentions.length})</summary><p class="caption">Location text only; not linked to this building.</p><div class="building-ticket-list">${mentions.map(card).join('')}</div></details></section>`:''}<p class="caption">${data.has_more?'More tickets exist outside this result.':''}${data.scan_partial?'Partial source scan.':''}</p>`;
    if(building&&resourceResult){const controls=document.createElement('div');controls.className='actions';controls.innerHTML='<button type="button" class="button" data-building-details>Building details</button>'+($('res-plan')?'<button type="button" class="button" data-building-map>View source map</button>':'');host.insertBefore(controls,host.querySelector('.building-ticket-list'));controls.querySelector('[data-building-details]').onclick=()=>{resourceTicketSelection='';resourceRenderDetail();buildingStep('detail');};controls.querySelector('[data-building-map]')?.addEventListener('click',()=>{$('res-plan').open=true;$('res-plan').scrollIntoView({block:'start'});$('res-plan').querySelector('summary').focus();});}
    host.querySelector('[data-building-back]').onclick=()=>buildingStep('buildings');
    host.querySelectorAll('[data-building-ticket]').forEach(button=>button.onclick=()=>{resourceTicketSelection=button.dataset.buildingTicket;buildingRenderTickets();resourceRenderDetail();buildingStep('detail');});
    if(resourceResult&&data.features?.resources){if($('res-plan'))$('res-plan').hidden=false;if($('res-plan-selection'))$('res-plan-selection').textContent=building?`${building.name}${building.mapNumber?' · official map #'+building.mapNumber:' · no official map reference recorded'}. The plan is a source image; no position is inferred.`:'Choose a building to keep its map reference here.';}else resourceClearMap();
  }
  function buildingRenderDetail() {
    if(section!=='buildings'||!resourceTicketSelection)return false;
    const ticket=buildingTickets().find(ticket=>ticket.row.id===resourceTicketSelection);
    if(!ticket){resourceTicketSelection='';return false;}
    const {row,linked}=ticket;
    $('res-detail').innerHTML=`<button class="button building-back" type="button" data-building-back="tickets">← Tickets</button><span class="eyebrow">3. Ticket details</span><h2 id="res-detail-heading" tabindex="-1">${esc(row.title)}</h2><dl class="resource-facts">${resourceFact('Type',requestTypeLabel(row)||'Not recorded')}${resourceFact('Priority',buildingPriority(row)||'Not set')}${resourceFact('Priority source',requestPrioritySource(row)==='request'?'Saved intake':requestPrioritySource(row)==='task'?'Linked task':'Not recorded')}${resourceFact('Status',String(row.status).replaceAll('_',' '))}${resourceFact('Assignee',buildingAssignee(row))}${resourceFact('Due',row.task?.due_on||row.due_on||'Unscheduled')}${resourceFact('Location text',row.data?.location||'Not provided')}</dl><p class="caption building-linkage">${linked?'This request has an explicit reference to the selected saved building.':'The location text matches this building name. The relationship is unverified; no saved building link is established.'}</p><h3>Description</h3><p class="work-detail">${esc(row.data?.description||'No description recorded.')}</p><h3>Latest progress</h3><p class="work-detail">${esc(row.data?.progress_note||row.data?.triage_note||'No update yet.')}</p><div class="actions"><button type="button" class="button primary" data-building-open-request>Open request &amp; available actions</button><button type="button" class="button" data-building-location>Building details</button></div><p class="caption">Actions recheck current request and workspace permissions. This panel changes no work.</p>`;
    $('res-detail').querySelector('[data-building-back]').onclick=()=>buildingStep('tickets');
    $('res-detail').querySelector('[data-building-location]').onclick=()=>{resourceTicketSelection='';buildingRenderTickets();resourceRenderDetail();};
    $('res-detail').querySelector('[data-building-open-request]').onclick=()=>document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:'requests',selected:row.id}}));
    return true;
  }
  function resourceRenderList() {
    if(!resourceActive())return;
    const catalog=section==='buildings'&&resourceDirectory==='catalog';
    const rows=catalog?resourceCatalog.entries.filter(resourceMatches):resourceItems.filter(row=>(section==='buildings'?row.kind==='location':section==='assets'?row.kind==='asset':['reservation','conflict'].includes(row.kind))&&resourceMatches(row));
    $('res-show-catalog').setAttribute('aria-pressed',String(catalog));$('res-show-saved').setAttribute('aria-pressed',String(!catalog));
    const card=row=>{const id=catalog?'catalog:'+row.map_number:row.id;return `<button class="resource-card" type="button" aria-pressed="${id===resourceSelection}" data-res-open="${esc(id)}"><span class="resource-number">${esc(catalog?row.map_number:row.kind==='conflict'?'!':row.kind==='asset'?'A':resourceMapNumber(row)||'•')}</span><span><strong>${esc(row.name||row.title)}</strong><small>${esc(catalog?[row.aliases,row.official_function_details].filter(Boolean).join(' · ')||'Catalog directory':`${row.kind} · ${row.status} · version ${row.version}`)}</small>${section==='buildings'?buildingCounts(id):''}${!catalog&&row.kind==='reservation'?`<small>${esc(resourceStamp(resourceDatum(row).starts_at))}</small>`:''}</span></button>`;};
    if(section==='calendar') {
      $('res-list').innerHTML=renderResourceMonth(rows);
      bindMonthControls($('res-list'),()=>{resourceRenderList();resourceRenderDetail();});
    } else $('res-list').innerHTML=rows.map(card).join('');
    if(!rows.length&&section!=='calendar')$('res-list').innerHTML='<p class="resource-empty">No matching records in the current authorized result.</p>';
    $('res-list').querySelectorAll('[data-res-open]').forEach(button=>button.onclick=()=>resourceSelect(button.dataset.resOpen));
    $('res-counts').innerHTML=`<span><strong>${rows.length}</strong> ${catalog?'catalog matches':'matching returned records'}</span>${!catalog?`<span><strong>${resourceItems.filter(row=>row.kind==='conflict'&&row.status==='open').length}</strong> open conflicts in this result</span><span><strong>${resourceItems.filter(row=>row.kind==='asset'&&row.status==='checked_out').length}</strong> checked out in this result</span>`:''}`;
    buildingRenderTickets();
    $('res-coverage').textContent=catalog?`All ${resourceCatalog.entries.length} catalog entries available to this workspace. Directory entries do not create operational records.`:resourceResult?.coverage||'Current workspace records have not been confirmed.';
  }
  function resourceRelated(row) {
    const explicit=record=>[record.location_id,record.resource_id,record.data?.location_id,record.data?.resource_id].includes(row.id);
    const requests=(data.requests||[]).filter(explicit),projects=(data.projects||[]).filter(explicit);
    return `<h3>Explicitly linked work in the loaded authorized result</h3>${requests.length||projects.length?`<ul class="resource-related">${requests.map(record=>`<li><button class="link" data-res-work="requests" data-id="${esc(record.id)}">${esc(record.title)}</button></li>`).join('')}${projects.map(record=>`<li><button class="link" data-res-work="projects" data-id="${esc(record.id)}">${esc(record.name||record.title)}</button></li>`).join('')}</ul>`:'<p class="caption">No explicit resource links in the loaded requests or projects. A typed location name does not establish a verified link.</p>'}`;
  }
  function resourceRenderDetail() {
    const target=$('res-detail');if(!resourceActive())return;if(buildingRenderDetail())return;
    if(resourceSelection.startsWith('catalog:')) {
      const source=resourceCatalog.entries.find(row=>row.map_number===resourceSelection.slice(8));if(!source){target.innerHTML='<h2>Choose a location</h2>';return;}
      const saved=resourceItems.filter(row=>row.kind==='location'&&resourceMapNumber(row)===source.map_number);
      target.innerHTML=`<span class="badge">Catalog reference ${esc(source.map_number)}</span><h2>${esc(source.name)}</h2><dl class="resource-facts">${resourceFact('Alias',source.aliases||'None listed')}${resourceFact('Recorded function',source.official_function_details||'Not specified in this source')}${resourceFact('Related spaces',source.related_spaces||'Not specified')}${source.historic_commons?resourceFact('Historic commons',source.historic_commons==='True'?'Marked in the source':'Not marked'):''}${resourceFact('Coordinates / ownership','Unverified; no placement or ownership inferred')}</dl>${resourceProvenance(source)}<h3>Saved references in this workspace</h3>${saved.map(row=>resourceButton('open','Open '+row.title,`data-id="${esc(row.id)}"`)).join('')||'<p class="caption">No saved reference in the returned result.</p>'}${resourceResult?.can_coordinate?`<div class="actions">${resourceButton('catalog.create','Add verified workspace reference')}</div>`:''}`;
      buildingDetailNavigation();resourceWireDetail(null);return;
    }
    const row=resourceItems.find(item=>item.id===resourceSelection);
    if(!row){target.innerHTML='<h2>Choose a record</h2><p class="subtle">Select a location, asset, reservation or conflict. Detail access is checked again when refreshed.</p>';return;}
    const d=resourceDatum(row);let facts=resourceFact('Status',row.status)+resourceFact('Version',row.version),actions='';
    if(row.kind==='location') {facts+=resourceFact('Hierarchy level',d.level)+resourceFact('Parent',d.parent_id?resourceName(d.parent_id):'No verified parent')+resourceFact('Description',d.description||'Not recorded')+resourceFact('Official map reference',resourceMapNumber(row)?'#'+resourceMapNumber(row):'None')+resourceFact('Ownership / placement','Unverified');if(row.can_coordinate)actions+=resourceButton('location.save','Edit location metadata');}
    if(row.kind==='asset') {facts+=resourceFact('Current / last location',resourceName(d.current_location_id||d.location_id))+resourceFact('Holder',d.holder_name||(d.holder_id?'Current authorized member #'+d.holder_id:'Available'))+resourceFact('Checked out',resourceStamp(d.checked_out_at))+resourceFact('Expected return',resourceStamp(d.expected_return_at))+resourceFact('Last returned',resourceStamp(d.returned_at))+resourceFact('Condition',d.condition||'Not recorded');if(row.can_checkout)actions+=resourceButton('asset.checkout','Check out asset');if(row.can_return)actions+=resourceButton('asset.return','Return asset');if(row.can_coordinate&&row.status==='available')actions+=resourceButton('asset.save','Edit asset metadata');}
    if(row.kind==='reservation'){facts+=resourceFact('Exclusive resource',resourceName(d.resource_id))+resourceFact('Starts',resourceStamp(d.starts_at))+resourceFact('Ends',resourceStamp(d.ends_at))+resourceFact('Activity',d.category)+resourceFact('Purpose',d.purpose||'Not recorded')+resourceFact('Cancellation reason',d.cancellation_note||'None');if(row.can_coordinate&&['scheduled','conflicted'].includes(row.status))actions+=resourceButton('reservation.cancel','Cancel this reservation');}
    if(row.kind==='conflict'){facts+=resourceFact('Resource',resourceName(d.resource_id))+resourceFact('Reason',d.reason)+resourceFact('Overlap starts',resourceStamp(d.starts_at))+resourceFact('Overlap ends',resourceStamp(d.ends_at))+resourceFact('Resolution reason',d.resolution_note||'Unresolved');actions+=(d.reservation_ids||[]).map(id=>resourceItems.some(item=>item.id===id)?resourceButton('open','Review '+resourceName(id),`data-id="${esc(id)}"`):'').join('');if(row.can_coordinate&&row.status==='open')actions+=resourceButton('conflict.resolve','Record resolution');}
    const url=resourceSafeLink(row.qr_url||row.url);
    target.innerHTML=`<span class="badge">${esc(row.kind)}</span><h2>${esc(row.title)}</h2><dl class="resource-facts">${facts}</dl><div class="actions">${actions}</div>${row.kind==='conflict'&&row.status==='open'?'<p class="resource-warning">Review both reservations. Explicitly cancel a booking before recording a resolution reason. No activity is moved automatically.</p>':''}${url?`<h3>First-party record link</h3><a class="resource-link" href="${esc(url)}">Open this record</a><p class="caption resource-link">${esc(url)}</p><p class="caption">Readable link fallback; no QR image is generated.</p>`:''}${resourceRelated(row)}<h3>Custody and revision history</h3><div id="res-history" class="resource-history"><p class="caption">Checking current authorized history.</p></div>`;
    buildingDetailNavigation();resourceWireDetail(row);resourceLoadHistory(row);
  }
  function buildingDetailNavigation(){if(section!=='buildings')return;const button=document.createElement('button');button.type='button';button.className='button building-back';button.textContent='← Tickets';button.onclick=()=>buildingStep('tickets');$('res-detail').prepend(button);const heading=$('res-detail').querySelector('h2');if(heading){heading.id='res-detail-heading';heading.tabIndex=-1;}}
  function resourceWireDetail(row) {
    $('res-detail').querySelectorAll('[data-res-action]').forEach(button=>button.onclick=()=>{const action=button.dataset.resAction;if(action==='open')resourceSelect(button.dataset.id);else resourceOpen(action,row);});
    $('res-detail').querySelectorAll('[data-res-work]').forEach(button=>button.onclick=()=>document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:button.dataset.resWork,selected:button.dataset.id}})));
  }
  async function resourceLoadHistory(row) {
    const token=resourceToken(),id=row.id;
    try {const result=await api(resourceEndpoint('/'+encodeURIComponent(id)+'/history'));if(!resourceCurrent(token)||resourceSelection!==id)return;const container=$('res-history');if(!container)return;container.innerHTML=(result.items||[]).map(revision=>`<article><strong>Version ${esc(revision.version)} · ${esc(revision.event?.action||revision.event?.event||'Resource revision')}</strong><p class="caption">${esc(resourceStamp(revision.at))} · actor ID ${esc(revision.actor_id)}</p><pre class="work-detail">${esc(JSON.stringify(revision.event,null,2))}</pre></article>`).join('')||'<p class="caption">No revisions returned.</p>';const coverage=document.createElement('p');coverage.className='caption';coverage.textContent=result.coverage||'Latest authorized revisions.';container.append(coverage);}catch(error){if(resourceCurrent(token)&&resourceSelection===id&&$('res-history'))$('res-history').textContent=error.message;}
  }
  async function resourceLoad() {
    const token=resourceToken();resourceClearMap();resourceResult=null;resourceItems=[];resourceRenderList();resourceRenderDetail();$('res-new').hidden=true;resourceNotice('Checking current workspace permissions and saved resources.');
    if(!area){resourceNotice('Choose an authorized workspace to load operational resources.');return;}
    try {const result=await api(resourceEndpoint('',{area_id:area}));if(!resourceCurrent(token))return;resourceResult=result;resourceItems=(result.items||[]).filter(row=>row.area_id===area);const requested=resourceSelection&&!resourceSelection.startsWith('catalog:')&&!resourceItems.some(row=>row.id===resourceSelection)?resourceSelection:null;if(requested){try{const extra=await api(resourceEndpoint('/'+encodeURIComponent(requested)));if(!resourceCurrent(token))return;if(extra.area_id===area)resourceItems.push(extra);}catch{if(resourceCurrent(token))resourceSelection='';}}if(!resourceCurrent(token))return;resourceNotice(transport.mode==='sample'?'Fictional isolated reviewer records. Real organizations and access are unchanged.':'Current workspace resource permissions confirmed.');$('res-new').hidden=!result.can_coordinate;$('res-new').textContent=section==='buildings'?'Add location':section==='assets'?'Add asset':'New reservation';resourceRenderList();resourceRenderDetail();}catch(error){if(!resourceCurrent(token))return;resourceItems=[];resourceResult=null;resourceNotice(error.message,true);resourceRenderList();resourceRenderDetail();}
  }
  function resourceEnter() {
    resourceClearMap();const active=resourceActive();$('resource-workspace').hidden=!active;
    if(!active){if(resourceContext){resourceStoreDraft();resourceGeneration++;resourceContext='';resourceItems=[];resourceResult=null;if($('res-dialog').open)$('res-dialog').close();}return;}
    for(const id of ['work','campus-home','ask','contractors','roll-workspace','operations-workspace'])if($(id))$(id).hidden=true;
    $('res-columns').dataset.buildings=String(section==='buildings');$('res-columns').dataset.calendar=String(section==='calendar');$('res-master-heading').hidden=section!=='buildings';$('res-building-steps').hidden=section!=='buildings';$('res-tickets').hidden=section!=='buildings';$('res-search-text').textContent=section==='buildings'?'Search buildings':'Find a location, asset or reservation';$('res-query').placeholder=section==='buildings'?'Building name, map number or alias':'Name, map number, alias or function';$('res-search-label').classList.toggle('building-search',section==='buildings');if(section==='buildings')$('res-building-search').append($('res-search-label'));else $('resource-workspace').querySelector('.resource-toolbar').prepend($('res-search-label'));
    $('res-building-tools').hidden=section!=='buildings';$('heading').textContent=section==='buildings'?'Buildings. Tickets. Clear priorities.':section==='assets'?'Know where shared equipment stands.':'Reserve time. Resolve conflicts deliberately.';$('breadcrumb').textContent=section==='buildings'?'Buildings & locations':section==='assets'?'Assets & custody':'Resource calendar';
    if(!data.features?.resources){resourceStoreDraft();resourceGeneration++;resourceContext='';resourceItems=[];resourceResult=null;resourceForm=null;if($('res-dialog').open)$('res-dialog').close();$('res-building-tools').hidden=true;$('res-new').hidden=true;$('res-list').replaceChildren();$('res-detail').innerHTML='<h2>Resources are unavailable</h2><p class="caption">This feature is not enabled for the current application.</p>';$('res-counts').replaceChildren();$('res-tickets').replaceChildren();resourceTicketSelection='';$('res-coverage').textContent='';resourceNotice('The resource rollout flag is disabled for this application.');return;}
    if($('res-plan'))$('resource-workspace').append($('res-plan'));
    const context=area+':'+section;resourceSelection=selected||'';if(resourceSelection&&!resourceSelection.startsWith('catalog:'))resourceDirectory='saved';
    if(context!==resourceContext){resourceStoreDraft();if($('res-dialog').open)$('res-dialog').close();resourceForm=null;resourceContext=context;resourceTicketSelection='';buildingStep('buildings',false);$('res-query').value='';}
    resourceGeneration++;resourceLoad();
  }
  function resourceLocal(value) {
    const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/New_York',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(value));const get=type=>parts.find(p=>p.type===type)?.value;return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
  }
  function resourceUTC(local,override) {
    if(override){if(!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(override)||!Number.isFinite(Date.parse(override))||new Date(override).toISOString().replace('.000Z','Z')!==override)throw new Error('Use a valid exact UTC timestamp ending in Z.');if(local&&resourceLocal(override)!==local)throw new Error('The explicit UTC choice must match the entered Eastern wall time.');return override;}
    if(!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local))throw new Error('Enter an Eastern date and time.');
    const wall=Date.parse(local+':00Z'),matches=[4,5].map(hours=>new Date(wall+hours*3600000).toISOString().replace('.000Z','Z')).filter(value=>resourceLocal(value)===local);
    if(matches.length!==1)throw new Error(matches.length?'That Eastern hour occurs twice. Enter an explicit UTC choice.':'That Eastern time does not exist. Choose a valid time.');return matches[0];
  }
  function resourceDraftKey(form) {return form.area_id+':'+form.action+':'+(form.existing_id||form.catalog_number||'new');}
  function resourceReadDraft(key){try{return JSON.parse(sessionStorage.getItem(resourceDraftPrefix+key)||'null');}catch{return null;}}
  function resourceWriteDraft(key,value){try{sessionStorage.setItem(resourceDraftPrefix+key,JSON.stringify(value));}catch{}}
  function resourceFields(){return Object.fromEntries([...$('res-form').elements].filter(input=>input.name&&!input.disabled).map(input=>[input.name,input.value]));}
  function resourceStoreDraft(){if(!resourceForm)return;if(!resourceForm.pending)resourceForm.values=resourceFields();resourceWriteDraft(resourceDraftKey(resourceForm),resourceForm);}
  function resourceSetFields(values){for(const [name,value]of Object.entries(values||{})){const field=$('res-form').elements.namedItem(name);if(field&&'value'in field)field.value=value??'';}}
  function resourceEnable(id,enabled){$(id).hidden=!enabled;$(id).disabled=!enabled;}
  function resourceMemberOptions(row){const holder=String(resourceDatum(row).holder_id||viewer);const members=(data.members||[]).filter(member=>row?.can_coordinate||String(member.id)===String(viewer));if(!members.some(member=>String(member.id)===String(viewer)))members.push({id:viewer,name:'Me'});$('res-form-holder').innerHTML=members.map(member=>`<option value="${esc(member.id)}">${esc(member.name)}</option>`).join('');$('res-form-holder').value=holder;}
  function resourceOpen(action,row=null) {
    if(resourceBusy||!resourceResult)return;
    const catalogNumber=action==='catalog.create'?resourceSelection.slice(8):'';if(catalogNumber)action='location.save';
    const identity={action,area_id:area,existing_id:row?.id||'',catalog_number:catalogNumber};const saved=resourceReadDraft(resourceDraftKey(identity));
    resourceForm={...identity,id:row?.id||crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:row?.version||0,values:{},pending:null,uncertain:false};
    $('res-form').reset();for(const id of ['res-title-fields','res-location-fields','res-asset-fields','res-checkout-fields','res-reservation-fields','res-reason-fields'])resourceEnable(id,false);
    const edit=['location.save','asset.save','reservation.save'].includes(action);resourceEnable('res-title-fields',edit);resourceEnable('res-location-fields',action==='location.save');resourceEnable('res-asset-fields',['asset.save','asset.checkout','asset.return'].includes(action));resourceEnable('res-checkout-fields',action==='asset.checkout');resourceEnable('res-reservation-fields',action==='reservation.save');resourceEnable('res-reason-fields',['reservation.cancel','conflict.resolve'].includes(action));
    const locations=resourceItems.filter(item=>item.kind==='location'&&!['withdrawn','inactive'].includes(item.status)&&item.id!==row?.id),options=locations.map(item=>`<option value="${esc(item.id)}">${esc(item.title)} · ${esc(item.data.level)}</option>`).join('');$('res-form-parent').innerHTML='<option value="">No verified parent assignment</option>'+options;$('res-form-location').innerHTML=options;$('res-form-resource').innerHTML=resourceItems.filter(item=>(item.kind==='asset'||item.kind==='location'&&item.data.level==='room')&&!['withdrawn','inactive','retired'].includes(item.status)).map(item=>`<option value="${esc(item.id)}">${esc(item.title)} · ${esc(item.kind)}</option>`).join('');resourceMemberOptions(row);
    const d=resourceDatum(row);resourceSetFields({title:row?.title||'',level:d.level==='building'?'facility':d.level||'facility',parent_id:d.parent_id||'',description:d.description||'',catalog_map_number:resourceMapNumber(row)||catalogNumber,location_id:d.current_location_id||d.location_id||'',condition:d.condition||'',resource_id:d.resource_id||'',category:d.category||'event'});
    if(catalogNumber)$('res-form-title').value=resourceCatalog.entries.find(item=>item.map_number===catalogNumber)?.name||'';
    if(saved&&saved.action===action&&saved.area_id===area&&saved.existing_id===identity.existing_id){resourceForm=saved;resourceSetFields(saved.values);}
    $('res-form-level').disabled=!!row&&action==='location.save';$('res-form-catalog').disabled=!!resourceMapNumber(row);$('res-form-title').readOnly=!!($('res-form-catalog').value&&action==='location.save');
    $('res-dialog-title').textContent={'location.save':row?'Edit location metadata':'Add workspace location','asset.save':row?'Edit asset metadata':'Add asset','asset.checkout':'Check out asset','asset.return':'Return asset','reservation.save':'Create exclusive reservation','reservation.cancel':'Cancel this reservation','conflict.resolve':'Record conflict resolution'}[action];$('res-dialog-context').textContent=(row?.title||'Current workspace')+' · exact version '+resourceForm.expected_version;$('res-form-warning').hidden=!saved;$('res-form-warning').textContent=saved?'An unsent or unconfirmed draft was restored. Its original record version and action IDs are retained. Refresh and discard it to start a new action if the record changed.':'';$('res-form-status').textContent='';$('res-save').textContent=resourceForm.pending?'Retry exact saved action':'Save exact action';resourceLockForm();resourceTimePreview();$('res-dialog').showModal();
  }
  function resourceLockForm(){const locked=resourceBusy||!!resourceForm?.pending;for(const element of $('res-form').querySelectorAll('input,select,textarea')){if(element.dataset.locked==='1'&&!locked){element.disabled=false;delete element.dataset.locked;}if(locked&&!element.disabled){element.disabled=true;element.dataset.locked='1';}}$('res-save').disabled=resourceBusy;$('res-discard-draft').disabled=resourceBusy||!!resourceForm?.uncertain;}
  function resourceTimePreview(){try{const action=resourceForm?.action;if(action==='asset.checkout')$('res-exact-time').textContent='Exact UTC return: '+resourceUTC($('res-form-return').value,$('res-form-return-utc').value.trim());else if(action==='reservation.save')$('res-exact-time').textContent='Exact UTC interval: '+resourceUTC($('res-form-start').value,$('res-form-start-utc').value.trim())+' → '+resourceUTC($('res-form-end').value,$('res-form-end-utc').value.trim());else $('res-exact-time').textContent='';}catch(error){$('res-exact-time').textContent=error.message;}}
  function resourceBuildCommand(){const f=resourceForm,v=resourceFields(),body={action:f.action,id:f.id,area_id:f.area_id,request_id:f.request_id,expected_version:f.expected_version};if(['location.save','asset.save','reservation.save'].includes(f.action))body.title=v.title;if(f.action==='location.save')Object.assign(body,{level:v.level||resourceItems.find(row=>row.id===f.id)?.data.level||'facility',parent_id:v.parent_id||null,description:v.description||'',catalog_map_number:v.catalog_map_number||f.catalog_number||resourceMapNumber(resourceItems.find(row=>row.id===f.id))||null});if(['asset.save','asset.checkout','asset.return'].includes(f.action))Object.assign(body,{location_id:v.location_id,condition:v.condition||''});if(f.action==='asset.checkout')Object.assign(body,{holder_id:Number(v.holder_id),expected_return_at:resourceUTC(v.expected_return_local,v.expected_return_utc?.trim())});if(f.action==='reservation.save')Object.assign(body,{resource_id:v.resource_id,starts_at:resourceUTC(v.starts_local,v.starts_utc?.trim()),ends_at:resourceUTC(v.ends_local,v.ends_utc?.trim()),purpose:v.purpose||'',category:v.category});if(['reservation.cancel','conflict.resolve'].includes(f.action))body.note=v.note;return body;}
  $('res-form').onsubmit=async event=>{event.preventDefault();if(resourceBusy||!resourceForm)return;try{if(!resourceForm.pending){resourceForm.values=resourceFields();resourceForm.pending=resourceBuildCommand();}}catch(error){$('res-form-status').textContent=error.message;return;}const form=resourceForm,exact=form.pending,token=resourceToken(),key=resourceDraftKey(form);form.uncertain=true;resourceBusy=true;resourceStoreDraft();resourceLockForm();$('res-form-status').textContent='Saving this exact action.';try{const result=await api(resourceEndpoint('/commands'),exact);resourceWriteDraft(key,null);if(!resourceCurrent(token))return;resourceForm=null;$('res-dialog').close();resourceSelection=result.id||resourceSelection;resourceRemember();await resourceLoad();message('Resource action saved.');}catch(error){form.uncertain=!error.status||error.status>=500;const definitive=error.status&&error.status<500;if(definitive){form.pending=null;form.uncertain=false;}resourceWriteDraft(key,form);if(!resourceCurrent(token))return;$('res-form-status').textContent=error.message+(form.uncertain?' The outcome is unconfirmed. Retry this exact action; do not create a duplicate.':' The unsent draft and reviewed version are retained.');}finally{resourceBusy=false;if(resourceForm&&resourceCurrent(token)){resourceLockForm();$('res-save').textContent=resourceForm.pending?'Retry exact saved action':'Save exact action';}}};
  $('res-form').addEventListener('input',()=>{resourceStoreDraft();resourceTimePreview();});
  $('res-form-catalog').onchange=()=>{const source=resourceCatalog.entries.find(row=>row.map_number===$('res-form-catalog').value);$('res-form-title').readOnly=!!source;if(source){$('res-form-title').value=source.name;$('res-form-level').value='facility';}resourceStoreDraft();};
  $('res-close').onclick=()=>{resourceStoreDraft();$('res-dialog').close();};$('res-dialog').addEventListener('cancel',()=>resourceStoreDraft());
  $('res-discard-draft').onclick=()=>{if(resourceBusy||resourceForm?.uncertain)return;resourceWriteDraft(resourceDraftKey(resourceForm),null);resourceForm=null;$('res-dialog').close();};
  $('res-query').oninput=resourceRenderList;$('res-refresh').onclick=()=>{resourceGeneration++;resourceLoad();};$('res-new').onclick=()=>resourceOpen(section==='buildings'?'location.save':section==='assets'?'asset.save':'reservation.save');
  $('res-show-catalog').onclick=()=>{resourceDirectory='catalog';resourceRenderList();};$('res-show-saved').onclick=()=>{resourceDirectory='saved';resourceRenderList();};
  function resourceZoom(value){if(!$('res-zoom')||!$('res-zoom-value')||!$('res-plan-image'))return;const zoom=Math.max(100,Math.min(400,Number(value)));$('res-zoom').value=zoom;$('res-zoom-value').textContent=zoom+'%';$('res-plan-image').style.width=zoom+'%';}
  if($('res-zoom'))$('res-zoom').oninput=()=>resourceZoom($('res-zoom').value);
  if($('res-zoom-out'))$('res-zoom-out').onclick=()=>resourceZoom(Number($('res-zoom')?.value||100)-25);
  if($('res-zoom-in'))$('res-zoom-in').onclick=()=>resourceZoom(Number($('res-zoom')?.value||100)+25);
  if($('res-plan-fit'))$('res-plan-fit').onclick=()=>{resourceZoom(100);$('res-plan-viewport')?.scrollTo({top:0,left:0});};
  if($('res-plan-top'))$('res-plan-top').onclick=()=>$('res-plan-viewport')?.scrollTo({top:0,behavior:'smooth'});
  if($('res-plan-bottom'))$('res-plan-bottom').onclick=()=>$('res-plan-viewport')?.scrollTo({top:$('res-plan-viewport').scrollHeight,behavior:'smooth'});
  document.addEventListener('enterprise:render',resourceEnter);

  document.addEventListener('enterprise:reset',()=>{resourceClearMap();resourceStoreDraft();resourceGeneration++;resourceContext='';resourceResult=null;resourceItems=[];resourceSelection='';resourceTicketSelection='';resourceForm=null;if($('res-dialog').open)$('res-dialog').close();$('resource-workspace').hidden=true;$('res-new').hidden=true;$('res-list').replaceChildren();$('res-detail').replaceChildren();$('res-counts').replaceChildren();$('res-tickets').replaceChildren();});
