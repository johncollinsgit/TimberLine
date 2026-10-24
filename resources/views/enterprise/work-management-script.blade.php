  let jobWizard=null,jobTargetGeneration=0,jobTarget=null;
  const jobDialog=$('job-wizard'),jobForm=$('job-wizard-form');
  const jobKinds={ 'job.create':'New job','project.create':'Create project','project.update':'Edit project','project.archive':'Archive project','task.create':'Add task','task.update':'Edit task','task.delete':'Remove task','request.plan':'Move & assign ticket'};
  function jobField(id,show){$(id).hidden=!show;}
  function jobStatus(text,error=false){$('job-wizard-status').textContent=text;$('job-wizard-status').classList.toggle('notice-error',error);}
  function jobLocked(locked){for(const input of jobForm.querySelectorAll('input,select,textarea'))input.disabled=locked;for(const id of ['job-next','job-back','job-save'])$(id).disabled=locked;$('job-retry').hidden=!pendingUncertain;if(!locked&&jobWizard)$('job-department').disabled=!['job.create','request.plan'].includes(jobWizard.action);}
  function jobStep(step){
    if(!jobWizard)return;jobWizard.step=step;
    jobForm.querySelectorAll('[data-job-step]').forEach(node=>node.hidden=Number(node.dataset.jobStep)!==step);
    $('job-step-label').textContent=['1 of 3 · Details','2 of 3 · Assignment & location','3 of 3 · Confirm'][step];
    $('job-back').hidden=step===0;$('job-next').hidden=step===2;$('job-save').hidden=step!==2;
    if(step===2){
      const label=id=>$(id).selectedOptions?.[0]?.textContent||'Not selected';
      const entries=[['Action',jobKinds[jobWizard.action]],['Name',$('job-title').value||jobWizard.record?.title||jobWizard.record?.name||''],['Department',label('job-department')]];
      if(!$('job-project-field').hidden)entries.push(['Project',label('job-project')]);
      if(!$('job-person-field').hidden)entries.push(['Assigned person',label('job-person')]);
      if(!$('job-building-field').hidden)entries.push(['Building',label('job-building')]);
      if(!$('job-due-field').hidden)entries.push(['Due',$('job-due').value||'Unscheduled']);
      $('job-review').innerHTML=entries.map(([key,value])=>`<dt>${esc(key)}</dt><dd>${esc(value)}</dd>`).join('');
    }
    if(jobDialog.open)requestAnimationFrame(()=>jobForm.querySelector(`[data-job-step="${step}"] :is(input,select,textarea):not([disabled])`)?.focus());
  }
  async function jobLoadTarget(id){
    const generation=++jobTargetGeneration,wizard=jobWizard;jobTarget=null;jobStatus('Checking this department’s current projects, people and buildings.');$('job-next').disabled=true;
    try{
      const result=await api(urls.data+'&area_id='+encodeURIComponent(id));
      if(jobWizard!==wizard||generation!==jobTargetGeneration||$('job-department').value!==id)return;
      if(result.selected_area_id!==id||!result.can_coordinate)throw new Error('You cannot coordinate this department.');
      jobTarget=result;
      if(transport.mode==='shared'){
        $('job-owner').innerHTML='<option value="">No project owner</option>'+result.members.map(row=>`<option value="${esc(row.id)}">${esc(row.name)}</option>`).join('');
        $('job-owner').value=wizard.record?.owner_id||wizard.project?.owner_id||'';
      }
      $('job-project').innerHTML='<option value="">Choose an active project</option>'+result.projects.filter(row=>!row.archived).map(row=>`<option value="${esc(row.id)}">${esc(row.name)}</option>`).join('');
      $('job-person').innerHTML='<option value="">Choose a person</option>'+result.members.map(row=>`<option value="${esc(row.id)}">${esc(row.name)}</option>`).join('');
      $('job-building').innerHTML='<option value="">No saved building link</option>'+(result.buildings?.items||[]).map(row=>`<option value="${esc(row.id)}">${esc(row.title)}</option>`).join('');
      const record=wizard.record;
      if(id===wizard.area){$('job-project').value=wizard.project?.id||record?.data?.project_id||'';$('job-person').value=record?.assignee_id||record?.task?.assignee_id||'';$('job-building').value=record?.data?.location_id||'';}
      $('job-target-coverage').textContent=(result.buildings?.coverage||'Current saved building choices.')+(result.buildings?.has_more?' Additional buildings are outside this result.':'');
      jobStatus('');$('job-next').disabled=false;syncJobBuilding();
    }catch(error){if(jobWizard===wizard&&generation===jobTargetGeneration)jobStatus(error.message,true);}
  }
  function syncJobBuilding(){const building=jobTarget?.buildings?.items?.find(row=>row.id===$('job-building').value);$('job-location').readOnly=!!building;if(building)$('job-location').value=building.title;}
  function openJobWizard(action,record=null,project=null){
    if(jobWizard&&jobWizard.action===action&&jobWizard.area===area&&jobWizard.record?.id===record?.id&&jobWizard.project?.id===project?.id){jobDialog.showModal();jobLocked(!!pending);return;}
    if(pending||busy)return message('Resolve the retained action before starting another.',true);
    if(!data.can_coordinate||!area)return message('A current coordinating department is required.',true);
    jobWizard={action,record,project,area,id:['job.create','project.create','task.create'].includes(action)?crypto.randomUUID():record.id,request_id:crypto.randomUUID(),sharedPin:captureSharedPin(),childIds:{task_id:crypto.randomUUID(),ticket_id:crypto.randomUUID()},step:0};jobTarget=null;jobForm.reset();jobLocked(false);
    $('job-wizard-title').textContent=action==='project.archive'&&record?.archived?'Restore project':jobKinds[action];
    $('job-title').value=record?.title||record?.name||'';$('job-description').value=record?.description||record?.data?.description||'';$('job-due').value=record?.due_on||'';$('job-priority').value=record?.priority||record?.requested_priority||'normal';$('job-type').value=record?.request_type||'general';$('job-location').value=record?.data?.location||'';
    jobField('job-ticket-summary',action==='request.plan');$('job-ticket-title').textContent=action==='request.plan'?(record?.title||'Ticket'):'';$('job-ticket-description').textContent=action==='request.plan'?(record?.data?.description||'No description provided.') : '';
    if(transport.mode==='shared')jobField('job-owner-field',['job.create','project.create','project.update'].includes(action));
    const edit=['job.create','project.create','project.update','task.create','task.update'].includes(action),task=['job.create','task.create','task.update','request.plan'].includes(action),location=['job.create','request.plan'].includes(action);
    jobField('job-title-field',edit);for(const id of ['job-description-field','job-due-field'])jobField(id,edit&&action!=='project.create');
    jobField('job-classification',['job.create','task.create','task.update'].includes(action));jobField('job-project-field',['task.create','request.plan'].includes(action));jobField('job-person-field',task);jobField('job-building-field',location);jobField('job-location-field',location);
    if(transport.mode==='shared'){
      $('job-type').closest('label').hidden=action!=='job.create';
      $('job-type').disabled=action!=='job.create';
    }
    if(transport.mode==='shared')jobField('job-location-field',false);
    const choices=data.routing_departments||data.areas.filter(row=>row.id===area);
    $('job-department').innerHTML=choices.map(row=>`<option value="${esc(row.id)}">${esc(row.name)}</option>`).join('');$('job-department').value=area;
    $('job-department').disabled=!['job.create','request.plan'].includes(action);
    $('job-operation-warning').textContent=action==='task.delete'?'Remove this task from active work. Its stored record, ticket and history are retained. This does not complete the job.':action==='project.archive'?(record.archived?'Restore this archived project to active work.':'Archive this project without deleting its tasks, files or history.'):'Assign existing members only. Completed work keeps its existing review controls.';
    jobField('job-confirm-field',['request.plan','task.delete'].includes(action));$('job-confirm-copy').textContent=action==='request.plan'?'I reviewed the destination department, project, person and building.':'Remove this task from active work and retain its history.';
    jobStatus('');jobStep(0);jobDialog.showModal();jobLoadTarget(area);
  }
  function closeJobWizard(){jobDialog.close();}
  $('job-wizard-close').onclick=closeJobWizard;jobDialog.addEventListener('cancel',event=>{event.preventDefault();closeJobWizard();});
  $('job-department').onchange=()=>jobLoadTarget($('job-department').value);$('job-building').onchange=syncJobBuilding;
  $('job-back').onclick=()=>jobStep(Math.max(0,jobWizard.step-1));
  $('job-next').onclick=()=>{
    if(!jobWizard||pending)return;
    const action=jobWizard.action;
    if(jobWizard.step===0&&!$('job-title-field').hidden&&!$('job-title').value.trim())return jobStatus('Enter a name before continuing.',true);
    if(jobWizard.step===1){
      if(!jobTarget)return jobStatus('Wait for the current department check.',true);
      if(['task.create','request.plan'].includes(action)&&!jobTarget.projects.some(row=>row.id===$('job-project').value&&!row.archived))return jobStatus('Choose a current active project.',true);
      if((['job.create','request.plan'].includes(action)||(action==='task.update'&&jobWizard.record?.assignee_id))&&!jobTarget.members.some(row=>String(row.id)===$('job-person').value))return jobStatus('Choose a current person in this department.',true);
    }
    jobStatus('');jobStep(Math.min(2,jobWizard.step+1));
  };
  function jobBody(){
    const state=jobWizard,action=state.action,body={action,id:state.id,request_id:state.request_id,area_id:action==='job.create'?jobTarget.selected_area_id:state.area};
    if(!['job.create','project.create','task.create'].includes(action))body.expected_version=state.record.version;
    if(action==='project.create')body.title=$('job-title').value.trim();
    if(['job.create','project.update','task.create','task.update'].includes(action))Object.assign(body,{title:$('job-title').value.trim(),description:$('job-description').value,due_on:$('job-due').value||null});
    if(['job.create','task.create','task.update'].includes(action))body.priority=$('job-priority').value;
    if(action==='job.create')body.request_type=$('job-type').value;
    if(['job.create','task.create','task.update','request.plan'].includes(action))body.assignee_id=$('job-person').value?(transport.mode==='shared'?$('job-person').value:Number($('job-person').value)):null;
    if(['task.create','request.plan'].includes(action))body.project_id=$('job-project').value;
    if(action==='request.plan'){body.target_area_id=jobTarget.selected_area_id;body.acknowledge_routing=$('job-confirm').checked;}
    if(action==='task.delete')body.acknowledge_removal=$('job-confirm').checked;
    if(action==='project.archive')body.archived=!state.record.archived;
    if(['job.create','request.plan'].includes(action)){
      const building=jobTarget.buildings?.items?.find(row=>row.id===$('job-building').value);body.location=transport.mode==='shared'?(building?.title||''):$('job-location').value;
      if(building){body.location_id=building.id;body.location_version=building.version;}
    }
    if(['task.update','task.delete'].includes(action)&&state.record.linked_request){body.related_request_id=state.record.linked_request.id;body.request_version=state.record.linked_request.version;}
    return body;
  }
  jobForm.onsubmit=async event=>{event.preventDefault();if(!jobWizard||busy||pending||!jobTarget)return;
    if(!$('job-confirm-field').hidden&&!$('job-confirm').checked)return jobStatus('Confirm the reviewed change before saving.',true);
    jobLocked(true);await command(jobBody(),{pin:jobWizard.sharedPin,record:jobWizard.record,project:jobWizard.project,target:jobTarget,owner_id:transport.mode==='shared'?$('job-owner').value:null,child_ids:jobWizard.childIds});if(jobDialog.open){jobLocked(!!pending);jobStatus($('status').textContent,true);}
  };
  $('job-retry').onclick=async()=>{if(pending&&!busy){await command(pending);if(jobDialog.open){jobLocked(!!pending);jobStatus($('status').textContent,true);}}};
  function renderProjectWorkControls(project){
    const target=$('project-detail-links')||$('detail'),controls=document.createElement('div');controls.className='actions project-work-actions';
    const add=(label,action,record=project)=>{const button=document.createElement('button');button.type='button';button.className='button';button.textContent=label;button.onclick=()=>openJobWizard(action,record,project);controls.append(button);};
    if(project.can_edit){add('Edit project','project.update');add(project.archived?'Restore project':'Archive project','project.archive');}
    if(project.can_add_task&&!project.archived)add('Add task','task.create',null);
    target.append(controls);
    for(const task of project.tasks||[]){if(!task.can_edit)continue;const row=document.createElement('div');row.className='project-task-controls';row.innerHTML=`<span>${esc(task.title)}</span>`;
      for(const [label,action] of [['Edit task','task.update'],['Remove task','task.delete']]){const button=document.createElement('button');button.type='button';button.className='button';button.textContent=label;button.setAttribute('aria-label',label+': '+task.title);button.onclick=()=>openJobWizard(action,task,project);row.append(button);}target.append(row);}
  }
  document.addEventListener('enterprise:create-project',event=>{if(event.detail?.area&&event.detail.area!==area)return;openJobWizard('project.create');});
