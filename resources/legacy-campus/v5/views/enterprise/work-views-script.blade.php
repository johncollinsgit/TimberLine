  {!! $legacyAssets->bytes('resources/js/enterprise/work-views.js') !!}
@php($campusWorkMapPoints = json_decode($legacyAssets->bytes('resources/data/enterprise/campus-map-points.json'), true, 32, JSON_THROW_ON_ERROR))
  const campusMapPoints=@json($campusWorkMapPoints);
  const campusToday=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'America/New_York',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  let workMonth=EverbranchWorkViews.monthKey(navigation.get('month'))||campusToday().slice(0,7),workMapBuilding='';
  const monthLabel=month=>new Intl.DateTimeFormat('en-US',{timeZone:'UTC',year:'numeric',month:'long'}).format(new Date(month+'-01T12:00:00Z'));
  function monthToolbar(id,label) {
    return `<header class="month-toolbar"><h2 id="${id}-title">${esc(monthLabel(workMonth))}</h2><div class="actions" role="group" aria-label="${esc(label)} month controls"><button type="button" class="button" data-month-step="-1" aria-label="Previous month">←</button><button type="button" class="button" data-month-today>Today</button><button type="button" class="button" data-month-step="1" aria-label="Next month">→</button><label><span class="sr-only">Choose month</span><input type="month" data-month-picker aria-label="Choose month" value="${esc(workMonth)}" min="1900-01" max="2199-12"></label></div></header>`;
  }
  function monthCells(events,card) {
    const today=campusToday();
    return `<div class="month-scroll" role="region" tabindex="0" aria-label="Month calendar; scroll horizontally on small screens"><div class="month-grid">${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(day=>`<div class="month-weekday">${day}</div>`).join('')}${EverbranchWorkViews.monthDays(workMonth).map(day=>`<section class="month-cell" data-date="${day.date}" data-outside="${!day.inMonth}" data-today="${day.date===today}" aria-label="${day.date}"><time datetime="${day.date}">${day.day}</time>${events.filter(event=>event.dueOn===day.date).map(card).join('')}</section>`).join('')}</div></div>`;
  }
  function bindMonthControls(host,refresh) {
    const change=(next,focus)=>{if(!EverbranchWorkViews.monthKey(next))return;beginNavigation();workMonth=next;rememberUrl();refresh();host.querySelector(focus)?.focus({preventScroll:true});};
    host.querySelectorAll('[data-month-step]').forEach(button=>button.onclick=()=>change(EverbranchWorkViews.shiftMonth(workMonth,Number(button.dataset.monthStep)),`[data-month-step="${button.dataset.monthStep}"]`));
    host.querySelector('[data-month-today]').onclick=()=>change(campusToday().slice(0,7),'[data-month-today]');
    host.querySelector('[data-month-picker]').onchange=event=>change(event.target.value,'[data-month-picker]');
  }
  function scopedWorkEvents(work,context=data){
    const scopes=context.scopes||[{areaId:data.selected_area_id||area,data}];
    const events=scopes.flatMap(scope=>{
      if(scope.data?.selected_area_id!==scope.areaId)return [];
      const current=work.filter(row=>String(row._area_id||row.enterprise_area_id||row.area_id||area)===String(scope.areaId));
      return EverbranchWorkViews.workEvents(scope.data,section,current).map(event=>({...event,openArea:scope.areaId}));
    });
    const bounded=events.slice(0,500);
    bounded.partial=events.length>500||Boolean(context.failed?.length)||scopes.some(scope=>scope.data?.has_more||scope.data?.scan_partial||scope.data?.tasks_has_more||scope.data?.buildings?.has_more);
    return bounded;
  }
  function workEventCard(event) {
    return `<button type="button" class="month-event" data-open="${esc(event.openId)}" data-open-area="${esc(event.openArea||area)}" aria-label="Open ${esc(event.title)}" aria-haspopup="dialog" aria-controls="work-drawer" data-complete="${['complete','completed'].includes(event.status.toLowerCase())}"><strong>${esc(event.title)}</strong><small>${esc(event.assignee)}${event.projectName?' · '+esc(event.projectName):''}</small></button>`;
  }
  function renderWorkCalendar(work,context=data) {
    const host=$('request-list'),events=scopedWorkEvents(work,context),unscheduled=events.filter(event=>!event.dueOn);
    host.innerHTML=`<section class="work-month" aria-labelledby="work-month-title">${monthToolbar('work-month','Work due dates')}${monthCells(events,workEventCard)}<details class="month-unscheduled" ${!events.some(event=>event.dueOn)?'open':''}><summary>Unscheduled (${unscheduled.length})</summary><div class="month-unscheduled-list">${unscheduled.map(workEventCard).join('')||'<p class="caption">All returned work has a due date.</p>'}</div></details><details class="month-help"><summary>Calendar information</summary><p>Work due dates, separate from resource reservations. Up to 500 current authorized tasks or requests. ${esc(section==='projects'?'Tasks are grouped under their current visible projects. Projects with no returned tasks use a recorded project due date or appear under Unscheduled.':'Request due dates use their current linked task when available.')}${data.has_more?' More requests are outside this result.':''}${data.scan_partial?' The source scan is partial.':''}${events.partial?' Some authorized work or location metadata is outside this bounded result.':''}</p></details></section>`;
    bindMonthControls(host,()=>render());
  }
  function renderCampusWorkMap(work,context=data) {
    const host=$('map-holder'),events=scopedWorkEvents(work,context),groups=new Map();
    for(const event of events){const point=campusMapPoints.points[event.mapNumber];if(!point||!Number.isFinite(point.x)||!Number.isFinite(point.y)||point.x<0||point.x>100||point.y<0||point.y>100)continue;if(!groups.has(event.mapNumber))groups.set(event.mapNumber,[]);groups.get(event.mapNumber).push(event);}
    if(!groups.has(workMapBuilding))workMapBuilding='';
    host.innerHTML='<div class="campus-work-map-layout"><div class="campus-work-map-scroll" role="region" tabindex="0" aria-label="Official campus plan with saved work markers"><div class="campus-work-map-stage"><img id="campus-work-plan" alt="Official Lees-McRae campus plan. Work markers identify saved building links, not live people locations."><div class="campus-work-markers" id="map-markers"></div></div></div><section class="campus-work-map-tickets" id="campus-map-tickets" aria-live="polite"></section></div><p class="caption campus-map-source">Official campus plan · saved building links. <a href="'+esc(campusMapPoints.source_url)+'" target="_blank" rel="noopener noreferrer">Source PDF</a>. Markers are printed map positions, not GPS.</p>';
    const plan=$('res-plan-image');if(plan)$('campus-work-plan').src=plan.src;
    $('map-markers').innerHTML=[...groups].map(([number,rows])=>{const point=campusMapPoints.points[number];return `<button type="button" class="campus-work-marker" data-map-building="${number}" style="left:${point.x}%;top:${point.y}%" aria-pressed="${workMapBuilding===number}" aria-label="Show ${rows.length} work items at map building ${number}">${number} · ${rows.length}</button>`;}).join('');
    const renderTickets=()=>{const visible=workMapBuilding?groups.get(workMapBuilding):[...groups.values()].flat();$('campus-map-tickets').innerHTML=`<h3>${workMapBuilding?'Building #'+esc(workMapBuilding):'Work on this map'} (${visible.length})</h3>${visible.map(workEventCard).join('')||'<p class="caption">No saved building links match this view.</p>'}${events.length>visible.length&&!workMapBuilding?`<details ${!groups.size?'open':''}><summary>Not mapped (${events.length-visible.length})</summary><p class="caption">No current saved building reference on this source plan.</p>${events.filter(event=>!groups.has(event.mapNumber)).map(workEventCard).join('')}</details>`:''}`;$('campus-map-tickets').querySelectorAll('[data-open]').forEach(button=>button.onclick=()=>openWorkRow(button.dataset.open,button));};
    $('map-markers').querySelectorAll('[data-map-building]').forEach(button=>button.onclick=()=>{workMapBuilding=button.dataset.mapBuilding;$('map-markers').querySelectorAll('button').forEach(pin=>pin.setAttribute('aria-pressed',String(pin===button)));renderTickets();});renderTickets();
    $('map-empty').textContent=events.partial?'Showing up to 500 current work items. Additional authorized work or location metadata may be outside this result.':'';
  }

  function renderResourceMonth(rows) {
    const days=EverbranchWorkViews.monthDays(workMonth).map(day=>day.date),events=[];
    const conflicts=rows.filter(row=>row.kind==='conflict'),bookings=rows.filter(row=>row.kind==='reservation');
    for(const row of bookings)for(const date of EverbranchWorkViews.reservationDates(row,days))events.push({row,dueOn:date});
    const card=event=>{const row=event.row;return `<button type="button" class="month-event" data-res-open="${esc(row.id)}" data-complete="${row.status==='cancelled'}"><strong>${esc(row.title)}</strong><small>${esc(resourceName(row.data?.resource_id))} · ${esc(row.status)}</small><small>${esc(resourceStamp(row.data?.starts_at))}</small></button>`;};
    return `<section class="work-month resource-month-calendar" aria-labelledby="resource-month-title">${monthToolbar('resource-month','Resource bookings')}${monthCells(events,card)}${conflicts.length?`<details class="month-unscheduled" ${conflicts.some(row=>row.status==='open')?'open':''}><summary>Reservation conflicts (${conflicts.length})</summary>${conflicts.map(row=>card({row})).join('')}</details>`:''}<details class="month-help"><summary>Calendar information</summary><p>Resource bookings in Eastern time. Work due dates are in Projects or Requests → Calendar. A booking ends at its saved UTC end instant; a midnight ending does not fill the following day. ${esc(resourceResult?.coverage||'Current authorized records only.')}</p></details></section>`;
  }
