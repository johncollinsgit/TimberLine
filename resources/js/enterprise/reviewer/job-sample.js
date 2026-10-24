/* A versioned fictional dataset upgrade. No HTTP, accounts, grants or native database access. */
(() => {
  'use strict';
  const revision='campus-jobs-20-v1';
  const workers=[['2','Alex Morgan'],['4','Casey Rivera'],['5','Jordan Lee'],['6','Sam Carter'],['7','Taylor Brooks'],['8','Jamie Reed'],['9','Avery Quinn'],['10','Riley Parker'],['11','Cameron Ellis'],['12','Morgan Hayes']].map(([id,name])=>({id,name,can_approve:false,field_worker:true}));
  const members=()=>[{id:'1',name:'Blaine · demo manager',can_approve:true},...workers,{id:'3',name:'Student requester',can_approve:false}];
  const uuid=(prefix,n)=>`${prefix}000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
  const sourceUrl='https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf';
  const jobs=['Classroom lighting checks','Network cabinet inspection','Visitor event setup','Residence door adjustment','Public-space plumbing checks','Lecture-room AV inspection','Athletic event safety checks','Grounds drainage review','Library workstation preparation','Campus room signage refresh','Accessible route inspection','Department equipment checks','Meeting-room setup','Weather readiness inspection','Walkway lighting review','Laboratory fixture inspection','Office move preparation','Annual safety walkthrough'];
  function upgrade(state,boot,ids) {
    state.sample_migrations??={};
    if(state.sample_migrations[revision])return false;
    const entries=Array.isArray(boot.locations)?boot.locations:(boot.locations?.entries||[]),byNumber=new Map(entries.map(row=>[String(row.map_number),row]));
    const areas=[ids.area,ids.it,ids.events,ids.projects],timestamp='2026-10-23T08:00:00.000Z';
    const contractorDefs=[['Appalachian Facility Services',areas[0]],['Blue Ridge Mechanical',areas[0]],['Summit Network Services',areas[1]],['High Country Event Crew',areas[2]],['Valley Building Services',areas[3]]];
    const contractors=contractorDefs.map(([title,area_id],i)=>({id:i===0?ids.contractor:uuid('51',i+1),kind:'contractor',area_id,title,status:'active',version:1,data:{contact_name:`Fictional contact ${i+1}`,fictional:true},updated_at:timestamp}));
    state.planning??=[];state.resources??=[];state.requests??=[];state.tasks??=[];state.projects??=[];
    for(const contractor of contractors)if(!state.planning.some(row=>row.id===contractor.id))state.planning.push(contractor);
    const existingContractor=state.planning.find(row=>row.id===ids.contractor);if(existingContractor?.version===1&&existingContractor.title==='Sample campus contractor')existingContractor.title=contractors[0].title;
    const location=(areaId,mapNumber)=>{
      const entry=byNumber.get(String(mapNumber));if(!entry)return null;
      const id=areaId===ids.area&&String(mapNumber)==='52'?ids.building:uuid('61',Number(areaId.slice(-1))*1000+Number(mapNumber));
      let saved=state.resources.find(row=>row.id===id);
      if(!saved){saved={id,kind:'location',area_id:areaId,title:entry.name,version:1,status:'active',data:{...JSON.parse(JSON.stringify(entry)),level:'facility',map_number:String(mapNumber),catalog_map_number:String(mapNumber),source_url:sourceUrl,source_page:2,placement_verified:false,latitude:null,longitude:null,fixture:true}};state.resources.push(saved);}
      else if(saved.kind==='location'&&saved.area_id===areaId&&saved.version===1&&saved.data?.catalog_map_number===String(mapNumber)){saved.data.level='facility';saved.data.map_number=String(mapNumber);saved.data.source_url=sourceUrl;}
      return saved;
    };
    const attach=(request,building)=>{
      if(!request||!building)return;
      request.data??={};
      // Enrich the original untouched fixture only; never overwrite a user's edited location.
      if(request.version!==1&&request.data.location_id!==building.id)return;
      if(request.data.location_id&&request.data.location_id!==building.id)return;
      request.data.location_id=building.id;request.data.location_version=building.version;request.data.location=building.title;
      request.data.catalog_map_number=String(building.data.map_number||building.data.catalog_map_number);
      request.data.location_reference={id:building.id,version:building.version,map_number:request.data.catalog_map_number,source_url:sourceUrl};
      request.data.location_source=sourceUrl;
    };
    attach(state.requests.find(row=>row.id===ids.request),location(ids.area,'52'));
    attach(state.requests.find(row=>row.id===ids.facultyRequest),location(ids.area,'2'));
    // Seed exactly18 additional project identities; existing2project identities and all user-created rows remain.
    jobs.forEach((name,i)=>{
      const n=i+1,id=uuid('22',n),taskId=uuid('42',n),ticketId=uuid('32',n),areaId=areas[i%areas.length],entry=entries[(i*3)%Math.max(1,entries.length)],building=entry?location(areaId,entry.map_number):null;
      const due=new Date(Date.UTC(2026,9,20+i)).toISOString().slice(0,10),assignee=workers[i%workers.length].id,completed=i===5||i===13,status=completed?'completed':i===7?'interrupted':i===11?'awaiting_verification':'active';
      if(!state.projects.some(row=>row.id===id))state.projects.push({id,name,description:'Fictional campus work for this demonstration.',enterprise_area_id:areaId,owner_id:'1',version:1,archived:false,tasks:[taskId],current_status:{code:i===7?'on_hold':i===11?'at_risk':'on_track'},updated_at:timestamp});
      if(!state.tasks.some(row=>row.id===taskId))state.tasks.push({id:taskId,title:name,description:'Review the saved location, carry out the assigned check, and record the result.',version:1,assignee_id:assignee,completed_at:completed?timestamp:null,due_on:due,enterprise_area_id:areaId,project_id:id,status,priority:i%6===0?'high':'normal',accountable_owner_id:'1',owner_user_id:'1',ball_user_id:assignee,next_action:completed?'Review the retained completion record.':'Carry out the assigned check and add a work note.',blockers:status==='interrupted'?['Waiting for safe access']:[],checkpoint:'',checklist:[{id:'inspect',key:'inspect',label:'Check the assigned work',weight:1,completed}],approved:completed,created_at:timestamp,updated_at:timestamp});
      if(!state.requests.some(row=>row.id===ticketId)){const request={id:ticketId,area_id:areaId,title:name,version:1,status:completed?'completed':'assigned',due_on:due,task_id:taskId,data:{description:'Fictional assignment created for the campus demonstration.',created_by:'1',task_id:taskId,project_id:id,request_type:['maintenance','it_support','event_setup','general'][i%4],priority:i%6===0?'high':'normal',progress_note:''}};attach(request,building);state.requests.push(request);}
      const contractor=contractors.find(row=>row.area_id===areaId);
      const scheduleId=uuid('53',n);
      if(contractor&&!state.planning.some(row=>row.id===scheduleId))state.planning.push({id:scheduleId,kind:'schedule',area_id:areaId,title:`${name} · contractor support`,status:'planned',version:1,starts_on:due,ends_on:due,data:{project_id:id,contractor_id:contractor.id},updated_at:timestamp});
    });
    // Incoming tickets are not jobs or assigned tasks and remain separate in the queue.
    for(let i=0;i<5;i++){const id=uuid('33',i+1),areaId=areas[i%4],entry=entries[(i*5+2)%Math.max(1,entries.length)];if(state.requests.some(row=>row.id===id))continue;const request={id,area_id:areaId,title:['Corridor light needs review','Printer connection check','Meeting setup request','Door closer adjustment','Water fixture inspection'][i],version:1,status:i%2?'triaged':'submitted',due_on:`2026-10-${String(24+i).padStart(2,'0')}`,task_id:null,data:{description:'Fictional incoming ticket awaiting routing and assignment.',created_by:'3',request_type:['lighting','it_support','event_setup','maintenance','plumbing'][i],priority:i===4?'high':'normal',location:'',progress_note:''}};attach(request,entry?location(areaId,entry.map_number):null);state.requests.push(request);}
    for(const project of state.projects){project.owner_id??='1';project.updated_at??=timestamp;}
    state.sample_migrations[revision]={applied_at:timestamp,seeded_project_ids:jobs.map((_,i)=>uuid('22',i+1))};
    return true;
  }
  function projectProjection(state,project,people,canManage) {
    const person=id=>people.find(row=>String(row.id)===String(id))||null;
    const tasks=state.tasks.filter(row=>!row.deleted&&(row.project_id===project.id||(project.tasks||[]).includes(row.id))).map(row=>{
      const request=state.requests.find(item=>item.data?.task_id===row.id&&item.status!=='removed');
      return {...JSON.parse(JSON.stringify(row)),assignee:person(row.assignee_id),can_edit:canManage&&!row.completed_at,
        linked_request:request?{id:request.id,version:request.version,status:request.status}:null};
    });
    const requests=state.requests.filter(row=>row.data?.project_id===project.id&&row.status!=='removed');
    const locations=[...new Map(requests.map(row=>{
      const saved=state.resources.find(item=>item.id===row.data.location_id&&item.area_id===project.enterprise_area_id);
      return saved?[saved.id,{id:saved.id,version:saved.version,name:saved.title,map_number:String(saved.data.map_number||saved.data.catalog_map_number||''),source_url:saved.data.source_url||saved.data.source}]:null;
    }).filter(Boolean)).values()];
    return {...JSON.parse(JSON.stringify(project)),tasks,locations,owner:person(project.owner_id||'1'),can_edit:canManage,can_add_task:canManage&&!project.archived,
      last_visible_work_at:[project.updated_at,...tasks.map(row=>row.updated_at)].filter(Boolean).sort().at(-1)||null,tasks_has_more:false,tasks_coverage:'Returned fictional tasks in this browser.'};
  }
  globalThis.EverbranchJobSample=Object.freeze({upgrade,members,revision,projectProjection});
})();
