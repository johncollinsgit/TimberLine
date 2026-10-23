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
  function project(input){
    const {persona,revision,departments}=input;
    if(!personas.has(persona)||!Number.isSafeInteger(revision)||revision<0||!Array.isArray(departments)||!departments.length||departments.length>limits.departments)fail('Choose a current fictional perspective and bounded department scope.');
    const seenAreas=new Set(),refs=new Set(),sources=[];let taskCount=0;
    const add=source=>{if(refs.has(source.ref))fail('Duplicate fictional source reference.');refs.add(source.ref);sources.push(source);if(sources.length>limits.sources)fail('Narrow the departments before sharing this snapshot.');};
    const areaIds=[];
    for(const data of departments){
      const area=id(data.selected_area_id);if(seenAreas.has(area)||!data.areas?.some(row=>row.id===area))fail('The current fictional department projection changed.');seenAreas.add(area);areaIds.push(area);
      const people=new Map((data.members||[]).map(person=>[String(person.id),text(person.name,100)]));
      const person=value=>value==null?null:people.get(String(value))||'Not recorded in this fictional perspective';
      for(const row of data.projects||[]){
        if(persona==='requester')fail('Requester snapshots cannot include internal projects.');
        if(row.enterprise_area_id!==area)fail('A project belongs to a different fictional department.');
        const tasks=(row.tasks||[]).filter(task=>!task.deleted);
        if(tasks.length>limits.tasksPerProject||(taskCount+=tasks.length)>limits.totalTasks)fail('Narrow the selected work before sharing its task details.');
        add({ref:'project:'+id(row.id),kind:'project',area_id:area,title:text(row.name,100),version:version(row.version),status:row.archived?'archived':status(row.current_status?.code),tasks:tasks.map(task=>{if(task.enterprise_area_id&&task.enterprise_area_id!==area||task.project_id&&task.project_id!==row.id)fail('A task belongs to a different fictional project or department.');return {id:id(task.id),title:text(task.title,100),version:version(task.version),status:task.completed_at?'completed':status(task.status),assigned_person:person(task.assignee_id),priority:priority(task.priority),due_on:date(task.due_on)};}),buildings:(row.locations||[]).slice(0,8).map(location=>text(location.name,200))});
      }
      for(const row of data.requests||[]){
        if(row.area_id!==area)fail('A ticket belongs to a different fictional department.');
        // Assigned internal tickets are already represented by their project task.
        if(row.task&&persona!=='requester')continue;
        add({ref:'request:'+id(row.id),kind:'request',area_id:area,title:text(row.title,100),version:version(row.version),status:status(row.status),priority:priority(row.priority||row.requested_priority),due_on:date(row.due_on),building:text(row.data?.location||'',200)});
      }
    }
    sources.sort((a,b)=>a.ref.localeCompare(b.ref));areaIds.sort();
    const result={schema_version:1,persona,local_revision:revision,area_ids:areaIds,sources,coverage:'Reviewer-supplied fictional browser snapshot. Selected project tasks and tickets only; no native campus records, notes, files, review comments, contact details, mailbox or financial records. Missing/bounded fields do not prove absence.'};
    if(new TextEncoder().encode(canonical(result)).byteLength>limits.bytes)fail('Narrow the shared fields or departments; this snapshot exceeds the reviewed size limit.');
    return result;
  }
  async function capture(adapter,areaId,allDepartments=false){
    const before=adapter.snapshot();id(areaId);
    const selected=await adapter.request('/enterprise/data?area_id='+encodeURIComponent(areaId));
    const areas=allDepartments?selected.areas.map(row=>id(row.id)):[areaId];
    if(areas.length>limits.departments)fail('Choose fewer fictional departments.');
    const departments=[];
    for(const area of areas)departments.push(area===areaId?selected:await adapter.request('/enterprise/data?area_id='+encodeURIComponent(area)));
    const after=adapter.snapshot();if(before.persona!==after.persona||before.revision!==after.revision)fail('The fictional perspective or records changed. Review a fresh snapshot.');
    return project({persona:after.persona,revision:after.revision,departments});
  }
  function stamp(snapshot){return canonical([snapshot.persona,snapshot.local_revision,snapshot.area_ids,snapshot.sources]);}
  function fence(read){let generation=0;return Object.freeze({capture:()=>({generation,value:canonical(read())}),current:token=>token?.generation===generation&&token.value===canonical(read()),invalidate:()=>{generation++;}});}
  function preview(document,snapshot){
    const root=document.createElement('section');root.setAttribute('aria-label','Fields to share with OpenAI');
    const lead=document.createElement('p');lead.textContent='Only the fields shown below and your question will be sent to OpenAI after you confirm. Check for real or sensitive information before sending. These are fictional browser records, not verified campus facts.';root.append(lead);
    const context=document.createElement('p');context.textContent=`Perspective: ${snapshot.persona.replaceAll('_',' ')} · Local revision ${snapshot.local_revision} · ${snapshot.area_ids.length} selected department${snapshot.area_ids.length===1?'':'s'}`;root.append(context);
    const metadata=document.createElement('details'),metadataLabel=document.createElement('summary'),metadataValues=document.createElement('pre');metadataLabel.textContent='Exact snapshot context';metadataValues.textContent=JSON.stringify({schema_version:snapshot.schema_version,persona:snapshot.persona,local_revision:snapshot.local_revision,area_ids:snapshot.area_ids,coverage:snapshot.coverage},null,2);metadata.append(metadataLabel,metadataValues);root.append(metadata);
    for(const source of snapshot.sources){
      const article=document.createElement('article'),heading=document.createElement('h3'),summary=document.createElement('p');heading.textContent=source.title;summary.textContent=`${source.kind==='project'?'Project':'Ticket'} · ${source.status.replaceAll('_',' ')}${source.due_on?' · Due '+source.due_on:''}${source.priority?' · '+source.priority+' priority':''}${source.building?' · '+source.building:''}`;article.append(heading,summary);
      if(source.buildings?.length){const place=document.createElement('p');place.textContent='Buildings: '+source.buildings.join('; ');article.append(place);}
      for(const task of source.tasks||[]){const item=document.createElement('p');item.textContent=`${task.title} · ${task.assigned_person||'No assignee recorded'} · ${task.status.replaceAll('_',' ')}${task.due_on?' · Due '+task.due_on:''}${task.priority?' · '+task.priority+' priority':''}`;article.append(item);}
      const details=document.createElement('details'),label=document.createElement('summary'),values=document.createElement('pre');label.textContent='Exact shared fields and source references';values.textContent=JSON.stringify(source,null,2);details.append(label,values);article.append(details);root.append(article);
    }
    const coverage=document.createElement('p');coverage.textContent=snapshot.coverage;root.append(coverage);return root;
  }
  globalThis.EverbranchProposalAi=Object.freeze({limits,project,capture,fence,preview,canonical,stamp});
})();
