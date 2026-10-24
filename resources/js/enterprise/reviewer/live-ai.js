/* Private proposal demo projection only. No native records, provider, key or authorization resolution. */
(() => {
  'use strict';
  const personas=new Set(['demo_manager','executive','manager','worker','requester']);
  const uuid=/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
  const states=new Set(['submitted','triaged','assigned','in_progress','active','interrupted','awaiting_verification','completed','closed','on_track','on_hold','at_risk','archived']);
  const limits=Object.freeze({departments:8,sources:32,tasksPerProject:16,totalTasks:64,bytes:24000});
  function fail(message){throw new Error(message);}
  function id(value){if(typeof value!=='string'||!uuid.test(value))fail('A current fictional record reference is required.');return value;}
  function text(value,maximum){if(typeof value!=='string'||value.length>maximum||/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/u.test(value))fail('A fictional field is unsupported or too long.');return value;}
  function version(value){if(!Number.isSafeInteger(value)||value<1)fail('A current fictional record version is required.');return value;}
  function status(value){return states.has(value)?value:'not_recorded';}
  function date(value){if(value==null||value==='')return null;if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/u.test(value)||Number.isNaN(Date.parse(value+'T00:00:00Z'))||new Date(value+'T00:00:00Z').toISOString().slice(0,10)!==value)fail('A saved fictional due date is invalid.');return value;}
  function priority(value){return ['low','normal','high','urgent'].includes(value)?value:null;}
  const canonical=value=>JSON.stringify(value);
  const coverageV2='Reviewer-supplied fictional browser snapshot. Selected projects, tasks, tickets, saved location IDs and project-level contractor plans and cost inputs only; not verified college facts or native authority. Costs are fictional estimates or recorded inputs, never a building allocation, approved budget, invoice or payment. Planned dates do not establish on-site presence. No notes, files, review comments, contacts or mailbox. Missing/bounded fields do not prove absence.';
  function comparisons(costs){
    const groups=new Map();
    for(const row of costs){if(!groups.has(row.currency))groups.set(row.currency,{currency:row.currency,estimate_minor:null,recorded_cost_minor:null,difference_minor:null,estimate_ids:[],recorded_cost_ids:[]});const group=groups.get(row.currency);group[row.basis+'_minor']=(group[row.basis+'_minor']??0)+row.amount_minor;group[row.basis+'_ids'].push(row.id);}
    return [...groups.values()].sort((a,b)=>a.currency.localeCompare(b.currency)).map(group=>{group.estimate_ids.sort();group.recorded_cost_ids.sort();if(group.estimate_minor!==null&&group.recorded_cost_minor!==null)group.difference_minor=group.recorded_cost_minor-group.estimate_minor;return group;});
  }
  function project(input){
    const {persona,revision,departments}=input;
    if(!personas.has(persona)||!Number.isSafeInteger(revision)||revision<0||!Array.isArray(departments)||!departments.length||departments.length>limits.departments)fail('Choose a current fictional perspective and bounded department scope.');
    const seenAreas=new Set(),refs=new Set(),planIds=new Set(),costIds=new Set(),sources=[];let taskCount=0,planCount=0,costCount=0;
    const add=source=>{if(refs.has(source.ref))fail('Duplicate fictional source reference.');refs.add(source.ref);sources.push(source);if(sources.length>limits.sources)fail('Narrow the departments before sharing this snapshot.');};
    const areaIds=[];
    for(const data of departments){
      const area=id(data.selected_area_id);if(seenAreas.has(area)||!data.areas?.some(row=>row.id===area)||data.demo_persona!==persona)fail('The current fictional department projection changed.');seenAreas.add(area);areaIds.push(area);
      const people=new Map((data.members||[]).map(person=>[String(person.id),text(person.name,100)]));
      const buildings=new Map((data.buildings?.items||[]).map(row=>[id(row.id),row]));
      const projects=new Map((data.projects||[]).map(row=>[id(row.id),row]));
      const plans=data.features?.planning===true&&persona!=='requester'?(data.ai_planning?.items||[]):[];
      const financial=['demo_manager','executive','manager'].includes(persona)&&data.features?.finance===true&&data.ai_planning?.can_finance===true;
      const validBuilding=(value,recordedVersion=null)=>{if(value==null)return null;const row=buildings.get(value);return row&&(recordedVersion==null||row.version===recordedVersion)?row:null;};
      const visibleRequest=(row,projectId,taskId)=>row.area_id===area&&row.data?.project_id===projectId&&(row.data?.task_id||row.task_id||row.task?.id)===taskId;
      for(const row of data.projects||[]){
        if(persona==='requester')fail('Requester snapshots cannot include internal projects.');
        if(row.enterprise_area_id!==area)fail('A project belongs to a different fictional department.');
        const tasks=(row.tasks||[]).filter(task=>!task.deleted);
        if(tasks.length>limits.tasksPerProject||(taskCount+=tasks.length)>limits.totalTasks)fail('Narrow the selected work before sharing its task details.');
        const locations=(row.locations||[]).map(location=>validBuilding(location.id,location.version)).filter(Boolean);
        const selectedBuildings=[...new Map(locations.map(location=>[location.id,{id:id(location.id),title:text(location.title,200),version:version(location.version)}])).values()].sort((a,b)=>a.id.localeCompare(b.id));
        if(selectedBuildings.length>8)fail('Narrow the saved fictional locations before sharing.');
        const contractorPlans=[],costs=[];
        for(const plan of plans){
          if(plan.area_id!==area||plan.data?.project_id!==row.id)continue;
          if(plan.kind==='schedule'&&plan.status==='planned'){
            const matching=plans.filter(candidate=>candidate.kind==='contractor'&&candidate.id===plan.data.contractor_id&&candidate.area_id===area);
            const contractor=matching.length===1&&matching[0].status==='active'?matching[0]:null;
            if(!contractor)continue;
            const starts=date(plan.starts_on),ends=date(plan.ends_on);if(starts===null||ends===null||starts>ends)fail('A saved fictional contractor plan has invalid dates.');
            if(planIds.has(plan.id))fail('Duplicate fictional contractor plan.');planIds.add(plan.id);
            contractorPlans.push({id:id(plan.id),version:version(plan.version),contractor_id:id(contractor.id),contractor_version:version(contractor.version),contractor_title:text(contractor.title,100),starts_on:starts,ends_on:ends});
          }
          if(plan.kind==='cost'&&plan.status==='recorded'&&financial){
            if(!Number.isSafeInteger(plan.data.amount_minor)||plan.data.amount_minor<0||plan.data.amount_minor>1000000000||typeof plan.data.currency!=='string'||!/^[A-Z]{3}$/u.test(plan.data.currency)||!['estimate','recorded_cost'].includes(plan.data.basis))fail('A saved fictional project cost is invalid.');
            if(costIds.has(plan.id))fail('Duplicate fictional cost input.');costIds.add(plan.id);
            costs.push({id:id(plan.id),version:version(plan.version),amount_minor:plan.data.amount_minor,currency:plan.data.currency,basis:plan.data.basis});
          }
        }
        if(contractorPlans.length>8||(planCount+=contractorPlans.length)>32||costs.length>16||(costCount+=costs.length)>64)fail('Narrow the fictional plans or cost inputs before sharing.');
        contractorPlans.sort((a,b)=>a.id.localeCompare(b.id));costs.sort((a,b)=>a.id.localeCompare(b.id));
        add({ref:'project:'+id(row.id),kind:'project',area_id:area,title:text(row.name,100),version:version(row.version),status:row.archived?'archived':status(row.current_status?.code),tasks:tasks.map(task=>{
          if(task.enterprise_area_id&&task.enterprise_area_id!==area||task.project_id&&task.project_id!==row.id)fail('A task belongs to a different fictional project or department.');
          const assigned=task.assignee_id==null?null:String(task.assignee_id),name=assigned===null?null:people.get(assigned)||null;
          const linked=(data.requests||[]).filter(request=>visibleRequest(request,row.id,task.id));
          const request=linked.length===1?linked[0]:null,place=request?validBuilding(request.data?.location_id,request.data?.location_version):null;
          const buildingId=place&&selectedBuildings.some(building=>building.id===place.id)?place.id:null;
          return {id:id(task.id),title:text(task.title,100),version:version(task.version),status:task.completed_at?'completed':status(task.status),assigned_person:name,priority:priority(task.priority),due_on:date(task.due_on),assigned_person_id:name===null?null:text(assigned,32),building_id:buildingId,request:request?{id:id(request.id),version:version(request.version),status:status(request.status)}:null};
        }),buildings:selectedBuildings,contractor_plans:contractorPlans,costs,comparisons:comparisons(costs)});
      }
      for(const row of data.requests||[]){
        if(row.area_id!==area)fail('A ticket belongs to a different fictional department.');
        const projectId=row.data?.project_id,taskId=row.data?.task_id||row.task_id||row.task?.id;
        const parent=projects.get(projectId),linked=parent&&parent.enterprise_area_id===area&&(parent.tasks||[]).some(task=>!task.deleted&&task.id===taskId&&(!task.project_id||task.project_id===projectId));
        // Only a current exact visible project/task association replaces an assigned queue item.
        if(linked&&persona!=='requester'&&(data.requests||[]).filter(candidate=>visibleRequest(candidate,projectId,taskId)).length===1)continue;
        const place=validBuilding(row.data?.location_id,row.data?.location_version),requesterPlace=persona==='requester'&&row.data?.location_id&&Number.isSafeInteger(row.data?.location_version)&&row.data.location_version>0;
        add({ref:'request:'+id(row.id),kind:'request',area_id:area,title:text(row.title,100),version:version(row.version),status:status(row.status),priority:priority(row.priority||row.requested_priority),due_on:date(row.due_on),building:text(row.data?.location||'',200),building_id:place?id(place.id):requesterPlace?id(row.data.location_id):null,building_version:place?version(place.version):requesterPlace?version(row.data.location_version):null,project_id:null,task_id:null});
      }
    }
    sources.sort((a,b)=>a.ref.localeCompare(b.ref));areaIds.sort();
    const result={schema_version:2,persona,local_revision:revision,area_ids:areaIds,sources,coverage:coverageV2};
    if(new TextEncoder().encode(canonical(result)).byteLength>limits.bytes)fail('Narrow the shared fields or departments; this snapshot exceeds the reviewed size limit.');
    return result;
  }
  async function capture(adapter,areaId,allDepartments=false){
    const before=adapter.snapshot();id(areaId);
    const selected=await adapter.request('/enterprise/data?area_id='+encodeURIComponent(areaId));
    const areas=allDepartments?selected.areas.map(row=>id(row.id)):[areaId];
    if(areas.length>limits.departments)fail('Choose fewer fictional departments.');
    const departments=[];
    for(const area of areas){const data=area===areaId?selected:await adapter.request('/enterprise/data?area_id='+encodeURIComponent(area));const planning=data.features?.planning===true&&before.persona!=='requester'?await adapter.request('/enterprise/planning?area_id='+encodeURIComponent(area)):null;departments.push({...data,ai_planning:planning});}
    const after=adapter.snapshot();if(before.persona!==after.persona||before.revision!==after.revision)fail('The fictional perspective or records changed. Review a fresh snapshot.');
    return project({persona:after.persona,revision:after.revision,departments});
  }
  function stamp(snapshot){return canonical([snapshot.persona,snapshot.local_revision,snapshot.area_ids,snapshot.sources]);}
  function fence(read){let generation=0;return Object.freeze({capture:()=>({generation,value:canonical(read())}),current:token=>token?.generation===generation&&token.value===canonical(read()),invalidate:()=>{generation++;}});}
  function preview(document,snapshot){
    const root=document.createElement('section');root.setAttribute('aria-label','Fields to share with OpenAI');
    const server=snapshot.coverage?.startsWith('Server-held shared fictional workspace.');
    const lead=document.createElement('p');lead.textContent='Only the fields shown below and your question will be sent to OpenAI after you confirm. Check for real or sensitive information before sending. '+(server?'These are saved shared fictional records, not verified campus facts.':'These are fictional browser records, not verified campus facts.');root.append(lead);
    const context=document.createElement('p');context.textContent=`Perspective: ${snapshot.persona.replaceAll('_',' ')} · ${server?'Server':'Local'} revision ${snapshot.local_revision} · ${snapshot.area_ids.length} selected department${snapshot.area_ids.length===1?'':'s'}`;root.append(context);
    const metadata=document.createElement('details'),metadataLabel=document.createElement('summary'),metadataValues=document.createElement('pre');metadataLabel.textContent='Exact snapshot context';metadataValues.textContent=JSON.stringify({schema_version:snapshot.schema_version,persona:snapshot.persona,local_revision:snapshot.local_revision,area_ids:snapshot.area_ids,coverage:snapshot.coverage},null,2);metadata.append(metadataLabel,metadataValues);root.append(metadata);
    for(const source of snapshot.sources){
      const article=document.createElement('article'),heading=document.createElement('h3'),summary=document.createElement('p');heading.textContent=source.title;summary.textContent=`${source.kind==='project'?'Project':'Ticket'} · ${source.status.replaceAll('_',' ')}${source.due_on?' · Due '+source.due_on:''}${source.priority?' · '+source.priority+' priority':''}${source.building?' · '+source.building:''}`;article.append(heading,summary);
      if(source.buildings?.length){const place=document.createElement('p');place.textContent='Buildings: '+source.buildings.map(building=>typeof building==='string'?building:building.title).join('; ');article.append(place);}
      for(const task of source.tasks||[]){const item=document.createElement('p');item.textContent=`${task.title} · ${task.assigned_person||'No assignee recorded'} · ${task.status.replaceAll('_',' ')}${task.due_on?' · Due '+task.due_on:''}${task.priority?' · '+task.priority+' priority':''}`;article.append(item);}
      const details=document.createElement('details'),label=document.createElement('summary'),values=document.createElement('pre');label.textContent='Exact shared fields and source references';values.textContent=JSON.stringify(source,null,2);details.append(label,values);article.append(details);root.append(article);
    }
    const coverage=document.createElement('p');coverage.textContent=snapshot.coverage;root.append(coverage);return root;
  }
  globalThis.EverbranchProposalAi=Object.freeze({limits,project,capture,fence,preview,canonical,stamp});
})();
