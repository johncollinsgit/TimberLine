/* Maps explicit existing forms to the closed shared-workspace command contract. No request or local record mutation. */
(() => {
 'use strict';
 function map(body,{record=null,project=null,target=null,owner_id=null,child_ids=null,planning=null}={}){
  const fail=()=>{throw Error('This field or action is unavailable in the shared workspace. Your unsent form is retained.');};
  const person=value=>value==null||value===''?null:String(value);
  const request=body.action.startsWith('request.'),action=body.action==='task.delete'?'task.archive':request?body.action.replace('request.','ticket.'):body.action;
  let output={request_id:body.request_id,action,record_id:body.id,expected_record_version:body.expected_version??0,payload:{}};
  const building=(fields=body)=>{
   if(fields.latitude!=null&&fields.latitude!==''||fields.longitude!=null&&fields.longitude!=='')fail();
   const row=fields.location_id?(target?.buildings?.items||[]).find(row=>row.id===fields.location_id&&row.version===fields.location_version):null;
   if(fields.location_id&&!row||fields.location&&fields.location!==(row?.title||''))fail();
   return{building_id:row?.id??null,building_version:row?.version??null};
  };
  if(body.note!==undefined&&body.note!=='')fail();
  if(['job.create','project.create','project.update','task.create','task.update','request.create'].includes(body.action)){
   output.payload={title:body.title,description:body.description??'',due_on:body.due_on??null};
   if(body.action==='job.create'||body.action.startsWith('project.'))output.payload.owner_id=person(owner_id);
   if(body.action.endsWith('.create'))output.payload.area_id=body.area_id;
   if(body.action==='job.create'){
    if(!child_ids?.task_id||!child_ids?.ticket_id)fail();
    Object.assign(output.payload,{task_title:body.title,...child_ids,assignee_id:person(body.assignee_id),priority:body.priority??'normal',type:body.request_type??'general',...building()});
   }else if(body.action.startsWith('task.')){
    const row=record;
    Object.assign(output.payload,{assignee_id:person(body.assignee_id),priority:body.priority??'normal',building_id:row?.building_id??null,building_version:row?.building_id?(target?.buildings?.items||[]).find(b=>b.id===row.building_id)?.version??null:null});
    if(body.action==='task.create')output.payload.project_id=body.project_id;
    if(body.related_request_id&&(row?.linked_request?.id!==body.related_request_id||row?.linked_request?.version!==body.request_version))fail();
   }else if(body.action==='request.create')Object.assign(output.payload,{priority:body.priority??'normal',type:body.request_type??'general',...building()});
  }else if(body.action==='project.archive')output.payload={archived:body.archived};
  else if(body.action==='task.delete')output.payload={acknowledge_removal:body.acknowledge_removal};
  else if(body.action==='request.classify')output.payload={type:body.request_type,priority:body.priority};
  else if(['request.plan','request.assign'].includes(body.action)){
   const parent=(target?.projects||[]).find(row=>row.id===body.project_id&&!row.archived);
   if(!parent||!child_ids?.task_id)fail();
   const location = body.action==='request.assign'&&!Object.hasOwn(body,'location_id')
    ? building({...body,location_id:record?.data?.location_id,location_version:record?.data?.location_version,location:record?.data?.location}) : building();
   output.payload={target_area_id:body.target_area_id??body.area_id,project_id:parent.id,project_version:parent.version,task_id:child_ids.task_id,assignee_id:person(body.assignee_id),...location,acknowledge_routing:body.action==='request.plan'?body.acknowledge_routing:true};
  }else if(body.action==='request.triage') {output.action='ticket.status';output.payload={status:'triaged'};}
  else if(['request.progress','request.complete'].includes(body.action)){
   const current=record?.shared_status_target;if(!current)fail();
   Object.assign(output,{action:current.action,record_id:current.record_id,expected_record_version:current.version,payload:{status:body.action==='request.complete'?'completed':'in_progress'}});
  }else if(body.action==='request.reorder')output.payload={position:body.position};
  else if(['plan.create','plan.update','plan.cancel'].includes(body.action)){
   if(body.kind!=='schedule')fail();
   if(body.action==='plan.cancel'){
    const row=planning?.items?.find(row=>row.id===body.id&&row.version===body.expected_version&&row.kind==='schedule');
    if(!row||body.title&&body.title!==row.title)fail();output.payload={};
   }else{
    if(body.title)fail();
    const parent=target?.projects?.find(row=>row.id===body.project_id&&!row.archived),contractor=planning?.items?.find(row=>row.id===body.contractor_id&&row.kind==='contractor'&&row.status==='active');
    if(!parent||!contractor)fail();
    output.payload={area_id:body.area_id,project_id:parent.id,project_version:parent.version,contractor_id:contractor.id,contractor_version:contractor.version,starts_on:body.starts_on,ends_on:body.ends_on};
   }
  }
  else fail();
  return output;
 }
 globalThis.EverbranchSharedWorkCommandMap=Object.freeze({map});
})();
