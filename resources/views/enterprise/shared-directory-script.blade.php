  const sharedDirectory = transport.mode==='shared' ? document.createElement('section') : null;
  let sharedBuildingId = null, sharedDirectoryEpoch = 0;
  if(sharedDirectory){sharedDirectory.id='shared-campus-directory';sharedDirectory.className='panel stack';sharedDirectory.hidden=true;$('enterprise-main').append(sharedDirectory);}
  function renderSharedDirectory(){
    if(!sharedDirectory)return;
    const epoch=++sharedDirectoryEpoch,source=data,currentSection=section;
    const active=['buildings','people','calendar'].includes(currentSection)&&!!source.shared;
    sharedDirectory.hidden=!active;
    for(const name of ['buildings','people','calendar'])$(name+'-nav').hidden=!source.shared;
    if(!active){sharedDirectory.replaceChildren();return;}
    $('work').hidden=true;$('campus-home').hidden=true;$('ask').hidden=true;$('contractors').hidden=true;
    const heading=document.createElement('h2');heading.textContent=currentSection==='calendar'?'Work calendar':currentSection==='buildings'?'Buildings':'People & contractors';sharedDirectory.replaceChildren(heading);
    const scopeLabel=document.createElement('p');scopeLabel.className='caption';scopeLabel.textContent=(source.areas.find(row=>row.id===source.selected_area_id)?.name||'Selected department')+' · saved shared fictional work';sharedDirectory.append(scopeLabel);
    const open=(selectedSection,id)=>document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:selectedSection,area:source.selected_area_id,selected:id}}));
    const button=(label,handler,parent=sharedDirectory)=>{const b=document.createElement('button');b.type='button';b.className='button';b.textContent=label;b.onclick=handler;parent.append(b);return b;};
    if(currentSection==='calendar'){
      const events=EverbranchWorkViews.workEvents(source,'projects',source.projects);
      const host=document.createElement('div');host.innerHTML=monthToolbar('shared-work-month','Saved work')+monthCells(events,event=>`<button type="button" class="month-event" data-shared-project="${esc(event.openId)}"><strong>${esc(event.title)}</strong><small>${esc(event.assignee)} · ${esc(event.status)}</small></button>`);
      sharedDirectory.append(host);bindMonthControls(host,renderSharedDirectory);
      host.querySelectorAll('[data-shared-project]').forEach(b=>b.onclick=()=>open('projects',b.dataset.sharedProject));
      const unscheduled=document.createElement('details');const label=document.createElement('summary');label.textContent='Unscheduled work ('+events.filter(row=>!row.dueOn).length+')';unscheduled.append(label);
      for(const event of events.filter(row=>!row.dueOn))button(event.title,()=>open('projects',event.openId),unscheduled);
      sharedDirectory.append(unscheduled);return;
    }
    if(currentSection==='buildings'){
      const buildings=source.buildings?.items||[];
      if(sharedBuildingId&&!buildings.some(row=>row.id===sharedBuildingId))sharedBuildingId=null;
      const directory=document.createElement('div');directory.className='actions';sharedDirectory.append(directory);
      for(const building of buildings){const b=button(building.title,()=>{sharedBuildingId=building.id;renderSharedDirectory();},directory);b.setAttribute('aria-pressed',String(sharedBuildingId===building.id));}
      const selectedBuilding=buildings.find(row=>row.id===sharedBuildingId);
      if(!selectedBuilding)return;
      const title=document.createElement('h3');title.textContent=selectedBuilding.title;sharedDirectory.append(title);
      const tickets=source.requests.filter(row=>row.data?.location_id===selectedBuilding.id&&row.data.location_version===selectedBuilding.version);
      const count=document.createElement('p');count.textContent='Linked tickets ('+tickets.length+')';sharedDirectory.append(count);
      for(const ticket of tickets)button(ticket.title+' · '+ticket.status,()=>open('requests',ticket.id));
      const tasks=source.projects.flatMap(project=>(project.tasks||[]).filter(task=>task.building_id===selectedBuilding.id).map(task=>({task,project})));
      const taskLabel=document.createElement('h3');taskLabel.textContent='Project work ('+tasks.length+')';sharedDirectory.append(taskLabel);
      for(const {task,project} of tasks)button(project.name+' · '+task.title,()=>open('projects',project.id));
      if(source.can_submit)button('New ticket in this building',()=>create('request.create',{source,building:selectedBuilding}));
      return;
    }
    const projects=source.projects||[];
    for(const person of source.members){
      const article=document.createElement('article'),name=document.createElement('h3');name.textContent=person.name;article.append(name);
      const assigned=projects.filter(project=>(project.tasks||[]).some(task=>String(task.assignee_id)===String(person.id)));
      for(const project of assigned)button(project.name,()=>open('projects',project.id),article);
      if(!assigned.length){const empty=document.createElement('p');empty.className='caption';empty.textContent='No project task assigned in this department.';article.append(empty);}
      sharedDirectory.append(article);
    }
    const contractors=document.createElement('section'),title=document.createElement('h3');title.textContent='Contractors';contractors.append(title);sharedDirectory.append(contractors);
    api(`${nextBase}/planning${nextQuery()}&area_id=${encodeURIComponent(source.selected_area_id)}`).then(result=>{
      if(epoch!==sharedDirectoryEpoch||data!==source||section!==currentSection)return;
      for(const contractor of result.items.filter(row=>row.kind==='contractor')){
        const article=document.createElement('article'),name=document.createElement('h4');name.textContent=contractor.title;article.append(name);
        const plans=result.items.filter(row=>row.kind==='schedule'&&row.status==='planned'&&row.data?.contractor_id===contractor.id);
        for(const plan of plans){const project=projects.find(row=>row.id===plan.data.project_id);if(project)button('Planned: '+project.name+' · '+plan.starts_on+'–'+plan.ends_on,()=>open('projects',project.id),article);}
        contractors.append(article);
      }
    }).catch(()=>{if(epoch===sharedDirectoryEpoch&&data===source&&section===currentSection)contractors.replaceChildren(document.createTextNode('Current contractor access could not be verified. Refresh this department.'));});
  }
  document.addEventListener('enterprise:render',renderSharedDirectory);
  document.addEventListener('enterprise:workspace-state',event=>{if(sharedDirectory&&!event.detail?.loaded){sharedDirectoryEpoch++;sharedDirectory.replaceChildren();sharedDirectory.hidden=true;sharedBuildingId=null;}});
