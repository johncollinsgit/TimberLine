/* Date-only work planning and source-document placement. No provider, tracking or mutation transport. */
(() => {
  'use strict';
  const dateOnly = value => {if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value))return null;const date=new Date(value+'T12:00:00Z');return Number.isFinite(date.valueOf())&&date.toISOString().slice(0,10)===value?value:null;};
  const monthKey = value => typeof value==='string'&&/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/.test(value)?value:null;
  const iso = date => date.toISOString().slice(0,10);
  function monthDays(value) {
    if(!monthKey(value))throw new Error('Choose a valid calendar month.');
    const [year,month]=value.split('-').map(Number),first=new Date(Date.UTC(year,month-1,1,12)),start=new Date(first);
    start.setUTCDate(1-first.getUTCDay());
    return Array.from({length:42},(_,index)=>{const date=new Date(start);date.setUTCDate(start.getUTCDate()+index);return {date:iso(date),day:date.getUTCDate(),inMonth:date.getUTCMonth()===first.getUTCMonth()};});
  }
  function shiftMonth(value,delta) {if(!monthKey(value))return null;const [year,month]=value.split('-').map(Number),date=new Date(Date.UTC(year,month-1+delta,1,12));return monthKey(date.toISOString().slice(0,7));}
  const mapSource = 'https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf';
  function mapNumber(row,buildings=[]) {
    const explicitId=row.location_id||row.data?.location_id,version=row.location_version||row.data?.location_version;
    // Only a current, explicit saved-building relation can place work on the source plan.
    // Text, GPS, client-supplied reference labels and stale versions cannot create a marker.
    if(!explicitId||!Number.isSafeInteger(Number(version))||Number(version)<1)return null;
    const building=buildings.find(item=>String(item.id)===String(explicitId));
    if(!building||Number(building.version)!==Number(version))return null;
    const number=building.catalog_map_number||building.map_number||building.data?.catalog_map_number;
    const source=building.source_url||building.data?.source_url;
    return source===mapSource&&/^([1-9]|[1-5]\d|6[0-8])$/.test(String(number))?String(number):null;
  }
  function reservationDates(row,dates) {
    const start=Date.parse(row.data?.starts_at),end=Date.parse(row.data?.ends_at);
    if(!Number.isFinite(start)||!Number.isFinite(end)||end<=start)return [];
    const eastern=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/New_York',year:'numeric',month:'2-digit',day:'2-digit'});
    // Reservations are half-open UTC intervals. A midnight end does not occupy the next day.
    const first=eastern.format(new Date(start)),last=eastern.format(new Date(end-1));
    return dates.filter(date=>dateOnly(date)&&date>=first&&date<=last);
  }
  function workEvents(data,section,work=data.projects||[]) {
    const buildings=data.buildings?.items||[],member=id=>(data.members||[]).find(person=>String(person.id)===String(id));
    const result=[],seen=new Set(),projects=new Map((data.projects||[]).map(project=>[String(project.id),project]));
    const add=(row,project=null,kind='task')=>{
      const id=String(row.id),key=kind+':'+id;if(seen.has(key)||result.length>=500)return;seen.add(key);
      const locationRow=row.location_id||row.data?.location_id?row:(project?.location_id?project:{});
      const location=locationRow.location_reference||locationRow.data?.location_reference;
      result.push({id:key,sourceId:id,section:section==='requests'?'requests':'projects',openId:section==='requests'?id:String(project?.id||''),projectId:project?.id||row.project_id||null,title:row.title||row.name||'Untitled work',projectName:project?.name||project?.title||'',assignee:row.assignee?.name||member(row.assignee_id||row.task?.assignee_id)?.name||'Unassigned',dueOn:dateOnly(row.due_on||row.task?.due_on),status:row.status||row.task?.status||(row.completed_at?'Complete':'Open'),location:location?.title||location?.name||row.data?.location||'',mapNumber:mapNumber(locationRow,buildings)});
    };
    if(section==='requests')for(const row of work)add(row,null,'request');
    else {
      const allowed=new Set(work.map(project=>String(project.id)));
      for(const project of work)for(const task of (project.tasks||[]).filter(row=>row&&typeof row==='object'&&row.id)) {
        const linked=(data.requests||[]).find(request=>String(request.task?.id)===String(task.id)&&String(request.data?.project_id||request.task?.project_id)===String(project.id));
        add(linked?{...task,location_id:linked.data?.location_id,location_version:linked.data?.location_version,location_reference:linked.data?.location_reference,data:linked.data}:task,project);
      }
      for(const request of data.requests||[]) {const projectId=request.data?.project_id||request.task?.project_id,task=request.task;if(!task?.id||!allowed.has(String(projectId)))continue;add({...task,location_id:request.data?.location_id,location_version:request.data?.location_version,location_reference:request.data?.location_reference,data:request.data,due_on:task.due_on||request.due_on},projects.get(String(projectId)));}
      for(const project of work)if(!result.some(event=>String(event.projectId)===String(project.id)))add({...project,title:project.name||project.title,assignee_id:project.owner_id},project,'project');
    }
    return result;
  }
  globalThis.EverbranchWorkViews=Object.freeze({dateOnly,monthKey,monthDays,shiftMonth,mapNumber,workEvents,mapSource,reservationDates});
})();
