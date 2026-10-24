  // Shared project dialog: every action still uses current native/sample transport policies.
  let projectDetailGeneration=0;
  function renderProjectDetail(project) {
    const scopeArea=area,scopeId=selected,host=$('detail'),renderGeneration=++projectDetailGeneration;
    const current=()=>renderGeneration===projectDetailGeneration&&area===scopeArea&&section==='projects'&&selected===scopeId&&host.isConnected&&$('work-drawer').open;
    const row=EverbranchRollProjects.projectRows([{areaId:scopeArea,areaName:(data.areas||[]).find(item=>String(item.id)===String(scopeArea))?.name||'',data:{...data,projects:[project]}}])[0];
    const chips=people=>people.map(person=>`<span>${esc(person.name)}</span>`).join('');
    const taskRows=row.tasks.map(task=>`<tr><td>${esc(task.title)}</td><td>${esc(task.assignee?.name||data.members.find(person=>String(person.id)===String(task.assignee_id))?.name||'Unassigned')}</td><td>${task.completed_at?'Complete':'Open'}</td><td>${esc(task.due_on||'Unscheduled')}</td></tr>`).join('');
    host.innerHTML=`<section class="project-overview"><div class="project-detail-actions" id="project-detail-links"></div><div><h2>${esc(row.name)}</h2><span class="badge">${esc(row.status)}</span></div>${row.description?`<p class="project-detail-description">${esc(row.description)}</p>`:''}<dl class="project-detail-facts"><div><dt>Workspace</dt><dd>${esc(row.areaName)}</dd></div><div><dt>Project owner</dt><dd>${esc(row.owner?.name||'Not recorded')}</dd></div><div><dt>Visible tasks</dt><dd>${row.tasks.length?`${row.completed} of ${row.tasks.length} complete`:'No visible task detail'}</dd></div><div><dt>Location</dt><dd>${row.locations.map(esc).join('<br>')||'Not recorded'}</dd></div></dl><section aria-label="People on this project"><h3>In-house team</h3><div class="project-detail-roster">${chips(row.people)||'<span>No recorded task assignments</span>'}</div><div class="project-summary-extra" id="project-contractor-summary"></div></section>${taskRows?`<section aria-label="Project tasks"><h3>Tasks</h3><div class="roll-project-table-wrap"><table class="project-detail-tasks"><thead><tr><th scope="col">Task</th><th scope="col">Assigned to</th><th scope="col">Status</th><th scope="col">Due</th></tr></thead><tbody>${taskRows}</tbody></table></div></section>`:''}<details class="project-detail-help"><summary>Project information</summary><p>Project version ${esc(project.version)}. ${esc(row.coverage)} Locations are saved work locations, not live people tracking.${transport.mode==='sample'?' This demonstration stores changes in this browser; it does not grant Blaine a real account.':''}</p></details></section>`;
    if(typeof renderProjectWorkControls==='function')renderProjectWorkControls(project);
    if(data.features?.workflow) {
      const link=document.createElement('a');link.className='button';link.textContent='Open workflow workspace';
      link.href=transport.href(urls.workflowWorkspace+'?'+new URLSearchParams({tenant:tenantSlug,area_id:scopeArea,project_id:project.id}));$('project-detail-links').append(link);
    }
    mountDiscussion('project',project.id);
    if(data.features?.operations)mountProjectOperations(project.id);
    if(data.features?.planning) {
      const summary=$('project-contractor-summary');summary.textContent='Checking recorded contractors…';
      api(rollEndpoint('/planning',{area_id:scopeArea})).then(result=>{
        if(!current()||!summary.isConnected)return;
        const projected=EverbranchRollProjects.projectRows([{areaId:scopeArea,data:{...data,projects:[project]},planning:result}])[0];
        summary.innerHTML=`<h3>Contractors</h3><div class="project-detail-roster">${chips(projected.contractors)||'<span>None recorded for this project</span>'}</div>`;
      }).catch(()=>{if(current()&&summary.isConnected)summary.textContent='Contractor records are unavailable for this check.';});
    }
  }
