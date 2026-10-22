  const rollNavigation = () => new URLSearchParams(transport.mode === 'sample' ? location.hash.slice(1) : location.search);
  const rollPrefix = `everbranch-enterprise-draft:${transport.mode === 'sample' ? 'sample:' : ''}${workspace}:${viewer}:meeting:`;
  let rollGeneration = 0, rollContext = null, rollSnapshot = null, rollModel = null, rollTasks = [], rollSaved = [], rollPreview = null;
  let rollPendingSave = null, rollPendingConfirm = null, rollBusy = false, rollDirty = false, rollDefinitive = false, rollDraftBlocked = false;
  let rollScope = rollNavigation().get('scope') || 'workspace', rollProject = rollNavigation().get('project_id') || '', rollPerson = rollNavigation().get('team_user_id') || '';
  let rollSelectedMeeting = rollNavigation().get('meeting') || '';
  const rollEndpoint = (path, query = {}) => `${urls.enterprise}${path}?${new URLSearchParams({tenant:tenantSlug,...query})}`;
  const rollScopeInput = () => ({type:rollScope, ...(rollScope !== 'campus' ? {area_id:area} : {}), ...(rollScope === 'project' ? {project_id:rollProject} : {}), ...(rollScope === 'team' ? {team_user_id:rollPerson} : {})});
  const rollKey = () => `${area}:${rollScope}:${rollScope==='project'?rollProject:''}:${rollScope==='team'?rollPerson:''}`;
  const rollCurrent = token => token.generation === rollGeneration && token.key === rollContext && section === 'roll_call';
  const rollToken = () => ({generation:rollGeneration,key:rollContext});
  function rollNotice(value, error = false) { $('roll-status').textContent=value; $('roll-status').classList.toggle('notice-error',error); }
  function rollAccessRefused(error) {if(![403,404].includes(error.status))return false;rollStore();document.dispatchEvent(new CustomEvent('enterprise:reset'));rollNotice(error.message+' Current access must be checked before retained meeting notes are displayed.',true);return true;}
  function rollRead(key, id = '') { try {return JSON.parse(sessionStorage.getItem(rollPrefix+key+(id?':'+id:'')) || 'null');} catch {return null;} }
  function rollWrite(key, value) { try {const json=JSON.stringify(value);sessionStorage.setItem(rollPrefix+key,json);if(value?.model?.id)sessionStorage.setItem(rollPrefix+key+':'+value.model.id,json);} catch {} }
  function rollStore() {
    if (!rollContext || !rollModel || rollDraftBlocked) return;
    rollWrite(rollContext,{model:rollModel,preview:rollPreview,pendingSave:rollPendingSave,pendingConfirm:rollPendingConfirm,dirty:rollDirty,definitive:rollDefinitive});
  }
  function rollRememberUrl() {
    const url = new URL(location.href), query = rollNavigation();
    for (const [key,value] of Object.entries({section:'roll_call',area_id:area,scope:rollScope,project_id:rollScope==='project'?rollProject:'',team_user_id:rollScope==='team'?rollPerson:'',meeting:rollSelectedMeeting})) value ? query.set(key,value) : query.delete(key);
    if (transport.mode === 'sample') {url.hash=query.toString();url.search='';} else url.search=query.toString();
    transport.replace(url);
  }
  const rollOwners = () => (data.members || []).filter(row => row.id && row.name);
  const rollProjects = () => (data.projects || []).filter(row => !row.archived);
  function rollOptions(rows, value, placeholder) {return `<option value="">${esc(placeholder)}</option>`+rows.map(row=>`<option value="${esc(row.id)}" ${String(row.id)===String(value)?'selected':''}>${esc(row.name || row.title)}</option>`).join('');}
  function rollNewModel() {
    return {id:crypto.randomUUID(),area_id:area,title:'Daily Roll Call',version:0,scope:rollScopeInput(),decisions:[],blockers:[],commitments:[],applied:{}};
  }
  function rollValidDraft(model) {
    const owners = new Set(rollOwners().map(row=>String(row.id))), projects = new Set(rollProjects().map(row=>row.id));
    const wanted=rollScopeInput(), scopeMatches=model && ['type','area_id','project_id','team_user_id'].every(key=>String(model.scope?.[key] || '')===String(wanted[key] || ''));
    return model && model.area_id===area && scopeMatches && typeof model.title==='string' && model.title.length<=100
      && Array.isArray(model.decisions) && Array.isArray(model.blockers) && Array.isArray(model.commitments)
      && [model.decisions,model.blockers,model.commitments].every(rows=>rows.length<=20 && rows.every(row=>typeof row.text==='string' && row.text.length<=500))
      && model.commitments.every(row=>(!row.project_id || projects.has(row.project_id)) && (!row.owner_id || owners.has(String(row.owner_id))) && (!row.task_id || rollTasks.some(task=>task.task_id===row.task_id)))
      && model.blockers.every(row=>!row.owner_id || owners.has(String(row.owner_id)));
  }
  function rollPaintPriorities() {
    $('roll-method').textContent=`${rollSnapshot.scope?.label || 'Current checked scope'} · ${rollSnapshot.label} · ${rollSnapshot.method_label || rollSnapshot.method} · As of ${rollSnapshot.as_of}`;
    $('roll-priorities').innerHTML=rollSnapshot.items.map(row=>`<article class="roll-priority"><span class="badge">${esc(String(row.kind).replaceAll('_',' '))}</span><h3>${esc(row.title)}</h3><p>${esc(row.reason)}</p><dl><dt>Owner</dt><dd>${esc(row.owner?.name || 'Unassigned')}</dd><dt>With the ball</dt><dd>${esc(row.ball?.name || 'Not recorded')}</dd><dt>Next action</dt><dd>${esc(row.next_action || 'Not recorded')}</dd><dt>Due</dt><dd>${esc(row.due_on || 'Unknown')}</dd><dt>Last recorded</dt><dd>${esc(row.source_updated_at || 'Unknown')}</dd></dl><a class="button" href="${esc(transport.href(row.source_url))}">Open source record</a></article>`).join('') || '<p class="caption">No priorities are present in this checked sample. This does not establish that no campus work exists.</p>';
    $('roll-coverage').textContent=`${rollSnapshot.coverage} ${rollSnapshot.overflow_count ? `${rollSnapshot.overflow_count} additional priorities exceed this display bound.` : ''}`;
  }
  function rollPaintEditor() {
    $('roll-new').hidden=!rollSnapshot?.can_lead;
    $('roll-meetings').innerHTML=rollSaved.map(row=>`<button class="button" type="button" data-roll-meeting="${esc(row.id)}">${esc(row.title)} · v${esc(row.version)}</button>`).join('');
    $('roll-meetings').querySelectorAll('[data-roll-meeting]').forEach(button=>button.onclick=()=>rollOpen(button.dataset.rollMeeting));
    $('roll-meeting-form').hidden=!rollModel || !rollSnapshot?.can_lead || rollDraftBlocked;
    if (!rollModel || !rollSnapshot?.can_lead || rollDraftBlocked) { $('roll-preview-panel').hidden=true; return; }
    $('roll-meeting-title').value=rollModel.title;
    const owners=rollOwners(), projects=rollProjects();
    for (const kind of ['decisions','blockers','commitments']) {
      $(`roll-${kind}`).innerHTML=rollModel[kind].map((row,index)=>{
        const applied=kind==='commitments' && rollModel.applied?.[row.id], disabled=applied?'disabled':'';
        let fields=`<label>${kind==='decisions'?'Decision':kind==='blockers'?'Blocker':'Commitment'} ${index+1}<textarea data-roll-field="text" aria-label="${kind==='decisions'?'Decision':kind==='blockers'?'Blocker':'Commitment'} ${index+1}" maxlength="500" required ${disabled}>${esc(row.text)}</textarea></label>`;
        if (kind!=='decisions') fields+=`<div class="roll-note-grid"><label>Owner<select data-roll-field="owner_id" aria-label="${kind==='blockers'?'Blocker':'Commitment'} ${index+1} owner" ${kind==='commitments'?'required':''} ${applied || (kind==='commitments' && row.task_id)?'disabled':''}>${rollOptions(owners,row.owner_id,kind==='blockers'?'Unassigned':'Select an owner')}</select></label><label>Due date<input type="date" data-roll-field="due_on" aria-label="${kind==='blockers'?'Blocker':'Commitment'} ${index+1} due date" value="${esc(row.due_on || '')}" ${kind==='commitments'?'required':''} ${disabled}></label></div>`;
        if (kind==='commitments') {
          const tasks=rollTasks.filter(task=>task.project_id===row.project_id && !['completed','closed'].includes(task.status) && task.can_manage);
          const unavailable=row.task_id && !tasks.some(task=>task.task_id===row.task_id);
          fields+=`<div class="roll-note-grid"><label>Project<select data-roll-field="project_id" aria-label="Commitment ${index+1} project" required ${disabled}>${rollOptions(projects,row.project_id,'Select a project')}</select></label><label>Task change<select data-roll-field="task_id" aria-label="Commitment ${index+1} task change" ${disabled}><option value="">Create a new task</option>${unavailable?`<option value="${esc(row.task_id)}" selected disabled>Existing task unavailable for updates</option>`:''}${tasks.map(task=>`<option value="${esc(task.task_id)}" ${task.task_id===row.task_id?'selected':''}>Update: ${esc(task.title)}</option>`).join('')}</select></label></div><p class="caption">${applied?'Confirmed and immutable. Add another commitment to propose a further change.':unavailable?'This existing task is no longer eligible for an update. Review its current Operations state; a new task is never substituted automatically.':row.task_id?'Updates keep the current assignee and accountable owner.':'A new task uses existing project permissions; assignment grants no access.'}</p>`;
        }
        return `<div class="roll-note stack" data-roll-kind="${kind}" data-roll-row="${esc(row.id)}">${fields}${!applied?'<button class="button" type="button" data-roll-remove>Remove this entry</button>':''}</div>`;
      }).join('') || '<p class="caption">Nothing recorded yet.</p>';
    }
    $('roll-meeting-form').querySelectorAll('[data-roll-remove]').forEach(button=>button.onclick=()=>{
      const row=button.closest('[data-roll-row]'); rollCapture(); rollModel[row.dataset.rollKind]=rollModel[row.dataset.rollKind].filter(item=>item.id!==row.dataset.rollRow); rollChanged();rollPaintEditor();
    });
    $('roll-meeting-form').querySelectorAll('[data-roll-field]').forEach(input=>input.addEventListener('change',()=>{
      const row=input.closest('[data-roll-row]'), model=rollModel[row.dataset.rollKind].find(item=>item.id===row.dataset.rollRow);
      if (input.dataset.rollField==='project_id') {rollCapture();model.project_id=input.value;delete model.task_id;delete model.expected_task_version;rollChanged();rollPaintEditor();return;}
      if (input.dataset.rollField==='task_id') {
        const task=rollTasks.find(item=>item.task_id===input.value);
        if(task) {model.task_id=task.task_id;model.expected_task_version=task.task_version;model.owner_id=String(task.owner_user_id);model.due_on=task.due_on || model.due_on;}
        else {delete model.task_id;delete model.expected_task_version;}
        rollChanged();rollPaintEditor();return;
      }
      rollCapture();rollChanged();
    }));
    rollPaintActions();
  }
  function rollCapture() {
    if (!rollModel || $('roll-meeting-form').hidden || rollPendingSave || rollPendingConfirm) return;
    rollModel.title=$('roll-meeting-title').value;
    for(const kind of ['decisions','blockers','commitments']) {
      for(const row of $(`roll-${kind}`).querySelectorAll('[data-roll-row]')) {
        const model=rollModel[kind].find(item=>item.id===row.dataset.rollRow);
        if(kind==='commitments' && rollModel.applied?.[model.id]) continue;
        for(const input of row.querySelectorAll('[data-roll-field]')) {
          const field=input.dataset.rollField;
          if(input.value==='' && field!=='text') delete model[field]; else model[field]=input.value;
        }
        if(model.task_id) {const task=rollTasks.find(item=>item.task_id===model.task_id);if(task) model.expected_task_version=task.task_version;}
        else delete model.expected_task_version;
      }
    }
  }
  function rollChanged() {rollDirty=true;rollPreview=null;$('roll-confirm-checkbox').checked=false;rollStore();rollPaintActions();}
  function rollPaintActions() {
    const pending=Boolean(rollPendingSave || rollPendingConfirm);
    $('roll-meeting-fields').disabled=rollBusy || pending;
    $('roll-save').disabled=rollBusy || pending;
    $('roll-preview').disabled=rollBusy || pending || !rollModel?.version || rollDirty || !rollModel.commitments.some(row=>!rollModel.applied?.[row.id]);
    $('roll-pdf').disabled=rollBusy || !rollModel?.version;
    $('roll-retry').hidden=!pending || rollDefinitive;
    $('roll-retry').disabled=rollBusy;
    $('roll-reload').hidden=!rollDefinitive;
    $('roll-preview-panel').hidden=!rollPreview || rollDirty;
    $('roll-confirm').disabled=rollBusy || pending || !$('roll-confirm-checkbox').checked || (rollPreview && Date.parse(rollPreview.expires_at)<=Date.now());
    if(rollPreview) {
      $('roll-preview-actions').innerHTML=rollPreview.actions.map(action=>{
        const command=action.command, changes=command.changes || command;
        return `<article class="roll-preview-action"><strong>${command.kind==='task.create'?'Create task':'Update existing task'}</strong><p>${esc(changes.title)}</p><dl><dt>Owner</dt><dd>${esc(action.owner_name || rollOwners().find(row=>String(row.id)===String(action.owner_id))?.name || action.owner_id)}</dd><dt>Project</dt><dd>${esc(action.project_title || rollProjects().find(row=>row.id===action.project_id)?.name || action.project_id)}</dd><dt>Due</dt><dd>${esc(changes.due_on || 'Unknown')}</dd></dl>${command.kind==='task.update'?`<p class="caption">Existing task ${esc(command.id)} · reviewed version ${esc(command.expected_version)}. Its owner is preserved.</p>`:''}</article>`;
      }).join('');
      $('roll-preview-meta').textContent=`Meeting v${rollPreview.version} · Expires ${rollPreview.expires_at} · Preview ${rollPreview.preview_id}`;
    }
    $('roll-editor-message').textContent=rollPendingConfirm?'A confirmation is awaiting a verified response. Editing is locked; retry the same request.':rollPendingSave?'A save is awaiting a verified response. Editing is locked; retry the same request.':rollModel?.version?`Saved meeting v${rollModel.version}.${rollDirty?' You have unsaved notes; save them before previewing.':''}`:'This is an unsaved meeting draft.';
  }
  async function rollLoad() {
    if(data.features?.roll_call!==true)return;
    const token=rollToken(); rollNotice('Checking current permissions and records.');
    $('roll-meeting-form').hidden=true;$('roll-meeting-form').reset();for(const kind of ['decisions','blockers','commitments'])$(`roll-${kind}`).replaceChildren();$('roll-preview-panel').hidden=true;$('roll-priorities').replaceChildren();
    try {
      const query=rollScopeInput(), [snapshot,saved,tasks]=await Promise.all([
        api(rollEndpoint('/roll-call',query)),api(rollEndpoint('/meetings',query)),
        data.features?.operations?api(rollEndpoint('/operations',{area_id:area})):Promise.resolve({items:[]})
      ]);
      if(!rollCurrent(token)) return;
      rollSnapshot=snapshot;rollSaved=saved.items || [];rollTasks=tasks.items || [];rollPaintPriorities();
      const retained=rollRead(rollContext,rollSelectedMeeting);
      let current=null;
      if(rollSelectedMeeting) current=await api(rollEndpoint(`/meetings/${encodeURIComponent(rollSelectedMeeting)}`));
      else if(retained?.model?.version) current=await api(rollEndpoint(`/meetings/${encodeURIComponent(retained.model.id)}`));
      if(!rollCurrent(token)) return;
      rollDraftBlocked=false;
      if(!snapshot.can_lead) {rollModel=null;$('roll-editor-message').textContent='Only an authorized current meeting leader can save or export private meeting notes.';}
      else if(retained && (!rollSelectedMeeting || retained.model.id===rollSelectedMeeting) && rollValidDraft(retained.model)) {
        rollModel=retained.model;rollPreview=retained.preview;rollPendingSave=retained.pendingSave;rollPendingConfirm=retained.pendingConfirm;rollDirty=Boolean(retained.dirty);rollDefinitive=Boolean(retained.definitive);
        if(current && !rollDirty && !rollPendingSave && !rollPendingConfirm) rollModel=current;
      } else if(retained && !rollSelectedMeeting && !rollValidDraft(retained.model)) {
        rollModel=null;rollDraftBlocked=true;$('roll-editor-message').textContent='Your retained draft references records that are unavailable in this scope. It remains in this browser but is not displayed. Choose its original authorized scope or start a new meeting.';
      } else {rollModel=current || rollNewModel();rollPreview=null;rollPendingSave=null;rollPendingConfirm=null;rollDirty=!current;rollDefinitive=false;}
      if(rollModel?.version) rollSelectedMeeting=rollModel.id;
      rollPaintEditor();rollRememberUrl();rollNotice(`${snapshot.total_visible ?? snapshot.items.length} priorities in the checked scope. ${transport.mode==='sample'?'All actions here are isolated browser sample execution.':''}`);
    } catch(error) {
      if(!rollCurrent(token)) return;
      rollSnapshot=null;rollModel=null;rollTasks=[];rollSaved=[];rollPreview=null;$('roll-priorities').replaceChildren();$('roll-meetings').replaceChildren();$('roll-meeting-form').hidden=true;$('roll-preview-panel').hidden=true;$('roll-new').hidden=true;rollNotice(error.message,true);
    }
  }
  async function rollOpen(id) {
    if(rollBusy || rollPendingSave || rollPendingConfirm) {rollNotice('Resolve the pending request before switching meetings.',true);return;}
    rollCapture();rollStore();rollSelectedMeeting=id;rollGeneration++;rollDirty=false;rollPreview=null;
    const token=rollToken();
    try {const current=await api(rollEndpoint(`/meetings/${encodeURIComponent(id)}`));if(!rollCurrent(token)) return;const retained=rollRead(rollContext,id);if(retained && rollValidDraft(retained.model) && (retained.dirty || retained.pendingSave || retained.pendingConfirm)){rollModel=retained.model;rollDirty=Boolean(retained.dirty);rollPendingSave=retained.pendingSave;rollPendingConfirm=retained.pendingConfirm;rollPreview=retained.preview;rollDefinitive=Boolean(retained.definitive);}else{rollModel=current;rollDirty=false;rollPendingSave=null;rollPendingConfirm=null;rollDefinitive=false;}rollDraftBlocked=false;rollStore();rollPaintEditor();rollRememberUrl();}
    catch(error){if(rollCurrent(token)){if(rollAccessRefused(error))return;rollModel=null;$('roll-meeting-form').hidden=true;$('roll-preview-panel').hidden=true;rollNotice(error.message,true);}}
  }
  function rollSavePayload() {
    return {id:rollModel.id,request_id:crypto.randomUUID(),expected_version:rollModel.version || 0,area_id:area,title:rollModel.title,scope:rollScopeInput(),decisions:rollModel.decisions,blockers:rollModel.blockers,commitments:rollModel.commitments};
  }
  async function rollSaveMeeting() {
    if(rollBusy || !rollModel || rollPendingConfirm) return;
    if(!rollPendingSave) {rollCapture();if(!$('roll-meeting-form').reportValidity())return;rollPendingSave=rollSavePayload();}
    const token=rollToken(), pending=rollPendingSave, storageKey=rollContext;
    rollBusy=true;rollDefinitive=false;rollStore();rollPaintActions();rollNotice('Saving these meeting notes. No task changes are being made.');
    try {
      const saved=await api(rollEndpoint('/meetings'),pending);
      rollWrite(storageKey,{model:saved,dirty:false,pendingSave:null,pendingConfirm:null,preview:null});
      if(!rollCurrent(token))return;
      rollModel=saved;rollSelectedMeeting=saved.id;rollDirty=false;rollPendingSave=null;rollPreview=null;
      rollSaved=[saved,...rollSaved.filter(row=>row.id!==saved.id)];rollNotice(saved.replayed?'The same save was already accepted; your current notes are confirmed.':'Meeting notes saved. Tasks remain unchanged until you review and confirm.');rollRememberUrl();
    } catch(error) {if(rollCurrent(token)){rollDefinitive=[403,404,409,422].includes(error.status);if(rollAccessRefused(error))return;rollNotice(`${error.message} ${rollDefinitive?'Review the current saved version before changing the draft.':'The same save key is retained for a safe retry.'}`,true);}}
    finally {if(rollCurrent(token)){rollBusy=false;rollStore();rollPaintEditor();}}
  }
  async function rollPreviewChanges() {
    if(rollBusy || rollDirty || rollPendingSave || rollPendingConfirm || !rollModel?.version)return;
    const token=rollToken();rollBusy=true;rollPaintActions();
    try {const preview=await api(rollEndpoint(`/meetings/${encodeURIComponent(rollModel.id)}/preview`),{});if(!rollCurrent(token))return;rollPreview=preview;$('roll-confirm-checkbox').checked=false;rollStore();rollNotice('Review the exact task changes below. No task has changed.');}
    catch(error){if(rollCurrent(token)){if(rollAccessRefused(error))return;rollNotice(error.message,true);}}
    finally{if(rollCurrent(token)){rollBusy=false;rollPaintActions();}}
  }
  async function rollConfirmChanges() {
    if(rollBusy || !rollModel || (!rollPendingConfirm && (!rollPreview || !$('roll-confirm-checkbox').checked)))return;
    if(!rollPendingConfirm) rollPendingConfirm={request_id:crypto.randomUUID(),preview_id:rollPreview.preview_id,digest:rollPreview.digest,expected_version:rollPreview.version,confirmed:true};
    const token=rollToken(), storageKey=rollContext, id=rollModel.id, pending=rollPendingConfirm;
    rollBusy=true;rollDefinitive=false;rollStore();rollPaintActions();rollNotice('Confirming the exact reviewed task changes.');
    try {
      const saved=await api(rollEndpoint(`/meetings/${encodeURIComponent(id)}/confirm`),pending);
      rollWrite(storageKey,{model:saved,dirty:false,pendingSave:null,pendingConfirm:null,preview:null});
      if(!rollCurrent(token))return;
      rollModel=saved;rollPendingConfirm=null;rollPreview=null;rollDirty=false;rollSaved=[saved,...rollSaved.filter(row=>row.id!==saved.id)];rollNotice(saved.replayed?'The same task changes were already accepted. No duplicate tasks were created.':'Reviewed task changes confirmed and recorded.');
      try {const fresh=await api(rollEndpoint('/roll-call',rollScopeInput()));if(rollCurrent(token)){rollSnapshot=fresh;rollPaintPriorities();}}
      catch(error){if(rollCurrent(token))rollNotice('Task changes are confirmed. The agenda refresh is unavailable; refresh current work when ready.',true);}
    } catch(error){if(rollCurrent(token)){rollDefinitive=[403,404,409,422].includes(error.status);if(rollAccessRefused(error))return;rollNotice(`${error.message} ${rollDefinitive?'Review the current saved version; no replacement is submitted automatically.':'The same confirmation key is retained. Retry it to reconcile the outcome.'}`,true);}}
    finally{if(rollCurrent(token)){rollBusy=false;rollStore();rollPaintEditor();}}
  }
  function renderRollCall() {
    const active=section==='roll_call'&&data.features?.roll_call===true;$('roll-call-panel').hidden=!active;
    if(!active){rollCapture();rollStore();if(rollContext){rollGeneration++;rollContext=null;}return;}
    $('work').hidden=true;$('heading').textContent='Make the next move clear.';$('breadcrumb').textContent='Roll Call';
    $('roll-work-mode').textContent=data.can_coordinate?'Dispatch review: check current handlers, unresolved work and exact task changes before confirmation.':'Technician view: open your assigned work, job details and permitted status, note or photo actions. Manager decisions remain separately authorized.';
    $('roll-my-work').hidden=!rollOwners().some(row=>String(row.id)===String(data.viewer_id||viewer));
    if(!['campus','workspace','project','team'].includes(rollScope))rollScope='workspace';
    if(!rollProjects().some(row=>row.id===rollProject))rollProject=rollProjects()[0]?.id || '';
    if(!rollOwners().some(row=>String(row.id)===rollPerson))rollPerson=String(rollOwners()[0]?.id || '');
    $('roll-scope').value=rollScope;$('roll-project').innerHTML=rollOptions(rollProjects(),rollProject,'Choose a project');$('roll-person').innerHTML=rollOptions(rollOwners(),rollPerson,'Choose a person');
    $('roll-project-label').hidden=rollScope!=='project';$('roll-person-label').hidden=rollScope!=='team';
    const key=rollKey();if(key===rollContext)return;if(rollContext){rollCapture();rollStore();}
    rollContext=key;rollGeneration++;rollBusy=false;rollModel=null;rollSnapshot=null;rollPreview=null;rollPendingSave=null;rollPendingConfirm=null;rollLoad();
  }
  $('roll-scope-form').onsubmit=event=>{event.preventDefault();if(rollBusy)return;rollCapture();rollStore();rollScope=$('roll-scope').value;rollProject=$('roll-project').value;rollPerson=$('roll-person').value;rollSelectedMeeting='';rollContext=null;renderRollCall();};
  $('roll-my-work').onclick=()=>{if(rollBusy||!rollOwners().some(row=>String(row.id)===String(data.viewer_id||viewer)))return;rollCapture();rollStore();rollScope='team';rollPerson=String(data.viewer_id||viewer);rollSelectedMeeting='';rollContext=null;renderRollCall();};
  $('roll-scope').onchange=()=>{$('roll-project-label').hidden=$('roll-scope').value!=='project';$('roll-person-label').hidden=$('roll-scope').value!=='team';};
  $('roll-new').onclick=()=>{if(rollBusy || rollPendingSave || rollPendingConfirm){rollNotice('Resolve the pending request before starting another meeting.',true);return;}rollCapture();rollStore();rollModel=rollNewModel();rollSelectedMeeting='';rollPreview=null;rollDirty=true;rollDraftBlocked=false;rollStore();rollPaintEditor();rollRememberUrl();};
  document.querySelectorAll('[data-roll-add]').forEach(button=>button.onclick=()=>{
    if(!rollModel || rollBusy || rollPendingSave || rollPendingConfirm)return;rollCapture();const kind=button.dataset.rollAdd;if(rollModel[kind].length>=20){rollNotice('Keep each meeting section within 20 entries.',true);return;}
    const entry={id:crypto.randomUUID(),text:''};if(kind==='commitments')Object.assign(entry,{owner_id:rollScope==='team'?rollPerson:'',due_on:'',project_id:rollScope==='project'?rollProject:rollProjects()[0]?.id || ''});
    rollModel[kind].push(entry);rollChanged();rollPaintEditor();$(`roll-${kind}`).lastElementChild?.querySelector('textarea')?.focus();
  });
  $('roll-meeting-form').addEventListener('input',()=>{if(!rollPendingSave && !rollPendingConfirm){rollCapture();rollChanged();}});
  $('roll-meeting-form').onsubmit=event=>{event.preventDefault();rollSaveMeeting();};
  $('roll-preview').onclick=rollPreviewChanges;
  $('roll-confirm-checkbox').onchange=rollPaintActions;
  $('roll-confirm').onclick=rollConfirmChanges;
  $('roll-retry').onclick=()=>rollPendingConfirm?rollConfirmChanges():rollSaveMeeting();
  $('roll-reload').onclick=async()=>{if(rollBusy || !rollModel)return;const id=rollModel.id;rollPendingSave=null;rollPendingConfirm=null;rollPreview=null;rollDefinitive=false;rollDirty=false;if(rollModel.version)await rollOpen(id);else{rollModel=rollNewModel();rollDirty=true;rollStore();rollPaintEditor();rollNotice('Started a new draft after the refused request.');}};
  $('roll-pdf').onclick=async()=>{
    if(rollBusy || !rollModel?.version)return;
    const token=rollToken();let objectUrl=null;
    rollBusy=true;rollPaintActions();rollNotice('Generating the current authorized dated snapshot.');
    try {
      let href=transport.href(rollEndpoint(`/meetings/${encodeURIComponent(rollModel.id)}/pdf`));
      if(transport.mode!=='sample') {
        const target=new URL(href,location.href);
        if(target.origin!==location.origin || target.username || target.password)throw new Error('Meeting downloads must use the current application.');
        const response=await fetch(target.href,{credentials:'same-origin',headers:{Accept:'application/pdf','X-Team-Viewer':String(viewer),'X-Team-Workspace':String(workspace)}});
        if(!response.ok){const result=await response.json().catch(()=>({}));const error=new Error(result.message || 'The current meeting PDF is unavailable.');error.status=response.status;throw error;}
        const bytes=await response.arrayBuffer();
        if(!response.headers.get('Content-Type')?.startsWith('application/pdf') || new TextDecoder().decode(bytes.slice(0,8))!=='%PDF-1.4')throw new Error('The server did not return the expected meeting PDF.');
        if(!rollCurrent(token))return;
        objectUrl=URL.createObjectURL(new Blob([bytes],{type:'application/pdf'}));href=objectUrl;
      }
      if(!rollCurrent(token))return;
      const anchor=document.createElement('a');anchor.href=href;anchor.download='everbranch-meeting-roll-call.pdf';document.body.append(anchor);anchor.click();anchor.remove();
      rollNotice('A fresh dated snapshot was generated for download. Unsaved notes are not included.');
    } catch(error){if(rollCurrent(token)){if(rollAccessRefused(error))return;rollNotice(error.message,true);}}
    finally{if(objectUrl){const release=objectUrl;setTimeout(()=>URL.revokeObjectURL(release),30000);}if(rollCurrent(token)){rollBusy=false;rollPaintActions();}}
  };
  window.addEventListener('pagehide',()=>{rollCapture();rollStore();});
  window.visualViewport?.addEventListener('resize',()=>{if(document.activeElement?.closest('#roll-call-panel'))document.activeElement.scrollIntoView({block:'nearest'});});
  document.addEventListener('enterprise:reset',()=>{
    rollCapture();rollStore();rollGeneration++;rollContext=null;rollModel=null;rollSnapshot=null;rollTasks=[];rollSaved=[];rollPreview=null;rollPendingSave=null;rollPendingConfirm=null;rollBusy=false;
    $('roll-meeting-form').reset();$('roll-meeting-form').hidden=true;$('roll-preview-panel').hidden=true;
    for(const id of ['roll-priorities','roll-meetings','roll-decisions','roll-blockers','roll-commitments','roll-preview-actions'])$(id).replaceChildren();
    $('roll-new').hidden=true;rollNotice('Current access must be checked again before Roll Call or retained meeting notes can be displayed.',true);
  });
  document.addEventListener('enterprise:render',renderRollCall);
