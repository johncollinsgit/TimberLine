  let memoryGeneration=0,memoryScope=null,memoryEditing=null,memoryPending=null,memoryBusy=false,memorySource=null,memoryPreview=null,memoryDeleting=null;
  const memoryEnabled=()=>conversationMode()&&data.features?.private_memory===true;
  const memoryList=()=>data.private_memory?.items||[];
  const memoryCanSend=()=>!memoryEnabled()||!memoryBusy&&!memoryPending&&$('memory-form').hidden&&$('memory-delete-confirm').hidden;
  const memoryFieldsByKind={project:['status'],building:['status'],request:['status','priority','due_on'],task:['status','priority','due_on'],cost:['status','amount_minor','currency','basis']};
  const memoryLabels={status:'Status',priority:'Priority',due_on:'Due date',amount_minor:'Amount in minor units',currency:'Currency',basis:'Basis',reply_length:'Reply length',date_style:'Date style',brief:'Brief',standard:'Standard',iso:'YYYY-MM-DD',long:'Written date'};
  const memoryValue=value=>value===null?'Not recorded':String(value);
  function resetPrivateMemory(){
    memoryGeneration++;memoryScope=null;memoryEditing=null;memoryPending=null;memoryBusy=false;memorySource=null;memoryPreview=null;memoryDeleting=null;
    $('private-memory').hidden=true;$('memory-use').checked=false;$('memory-manager').open=false;$('memory-list').replaceChildren();$('memory-form').hidden=true;$('memory-form').reset();$('memory-delete-confirm').hidden=true;$('memory-status').textContent='';$('memory-preview').textContent='';$('memory-source-title').textContent='';
  }
  const memoryCurrent=(generation,scope)=>generation===memoryGeneration&&scope===memoryScope&&scope===conversationScope&&scope===conversationScopeValue()&&memoryEnabled()&&(section==='ask'||typeof campusAssistantActive==='function'&&campusAssistantActive());
  function memoryFields(){
    const manage=data.private_memory?.can_manage===true,locked=memoryBusy||!!memoryPending,conversationLocked=conversationBusy||!!conversationState;
    $('memory-use').disabled=conversationLocked||locked||!$('memory-form').hidden||!$('memory-delete-confirm').hidden;
    $('memory-new').disabled=!manage||conversationLocked||locked;$('memory-refresh').disabled=locked;
    for(const id of ['memory-key','memory-value','memory-field','memory-retention','memory-confirm'])$(id).disabled=locked||!manage;
    $('memory-save').disabled=memoryBusy||conversationLocked||!manage||memoryPending?.action==='delete'||!memoryPending&&(!$('memory-confirm').checked||memorySource&&!memoryPreview);
    $('memory-save').textContent=memoryPending?.action==='save'?'Retry this exact memory':'Save private memory';$('memory-cancel').disabled=locked;
    $('memory-delete-yes').disabled=memoryBusy||conversationLocked;$('memory-delete-no').disabled=locked;
    $('memory-delete-yes').textContent=memoryPending?.action==='delete'?'Retry this exact deletion':'Delete this private memory';
    for(const node of $('memory-list').querySelectorAll('button'))node.disabled=locked||conversationLocked||(node.dataset.memoryAction==='edit'?!manage:data.private_memory?.can_delete!==true);
    for(const node of document.querySelectorAll('[data-memory-source-action]'))node.disabled=locked||conversationLocked||!manage;
  }
  function memoryPreferenceChoices(value){
    const choices=data.private_memory?.preferences?.[$('memory-key').value]||[];
    $('memory-value').replaceChildren(...choices.map(key=>new Option(memoryLabels[key]||key,key)));
    if(choices.includes(value))$('memory-value').value=value;
  }
  function renderPrivateMemory(){
    if(!memoryEnabled()){if(memoryScope!==null)resetPrivateMemory();return;}
    if(memoryScope!==conversationScope){resetPrivateMemory();memoryScope=conversationScope;}
    $('private-memory').hidden=false;$('memory-list').replaceChildren();
    for(const item of memoryList()){
      const card=document.createElement('article');card.className='planning-record';
      conversationText(card,'h5',item.type==='preference'?(memoryLabels[item.key]||'Formatting preference'):item.source_title||'Private source observation');
      conversationText(card,'p',item.state==='available'?`${memoryLabels[item.field||item.key]||'Typed value'}: ${memoryLabels[item.value]||memoryValue(item.value)}`:item.notice);
      conversationText(card,'p',`Explicitly confirmed by account ${item.captured_by} on ${item.captured_at}. Expires ${item.expires_at}.`,'caption');
      if(item.source_ref&&conversationSource({ref:item.source_ref,url:item.source_url})){const link=conversationText(card,'a','Inspect current source');link.href=transport.href(item.source_url);}
      const actions=document.createElement('div');actions.className='actions';
      if(item.type==='preference'||item.source_ref){const edit=conversationText(actions,'button',item.type==='preference'?'Edit preference':'Review current observation','button');edit.type='button';edit.dataset.memoryAction='edit';edit.onclick=()=>openMemory(item);}
      const remove=conversationText(actions,'button','Delete memory','button');remove.type='button';remove.dataset.memoryAction='delete';remove.onclick=()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryBusy||memoryPending||conversationBusy||conversationState||data.private_memory?.can_delete!==true)return;memoryDeleting=item;$('memory-delete-confirm').hidden=false;memoryFields();conversationFields();$('memory-delete-yes').focus();};card.append(actions);$('memory-list').append(card);
    }
    if(!memoryList().length)conversationText($('memory-list'),'p','No private memory is saved in this department.','caption');
    memoryFields();
  }
  function openMemory(item=null,source=null){
    if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;
    if(memoryBusy||memoryPending||conversationBusy||conversationState||data.private_memory?.can_manage!==true)return;
    memoryEditing=item;memorySource=source||(item?.source_ref?{ref:item.source_ref,title:item.source_title,url:item.source_url}:null);memoryPreview=null;memoryDeleting=null;
    $('memory-manager').open=true;$('memory-form').reset();$('memory-form').hidden=false;$('memory-delete-confirm').hidden=true;$('memory-preview').textContent='';
    $('memory-edit-title').textContent=memorySource?'Review a current source observation':'Save a formatting preference';$('memory-preference-fields').hidden=!!memorySource;$('memory-source-fields').hidden=!memorySource;
    $('memory-status').textContent='Review the typed value and retention period, then confirm. No model request is made.';
    if(memorySource){
      $('memory-source-title').textContent=memorySource.title||'Current authorized source';
      $('memory-field').replaceChildren(...(memoryFieldsByKind[memorySource.ref.split(':')[0]]||[]).map(key=>new Option(memoryLabels[key],key)));
      if(item?.field)$('memory-field').value=item.field;
      $('memory-status').focus();previewMemory();
    }else{$('memory-key').value=item?.key||'reply_length';memoryPreferenceChoices(item?.value);}
    memoryFields();conversationFields();if(!memorySource)$('memory-key').focus();
  }
  function memorySourceAction(host,source){
    if(!memoryEnabled()||data.private_memory?.can_manage!==true||!conversationSource(source))return;
    const button=conversationText(host,'button','Save observation','button');button.type='button';button.dataset.memorySourceAction='true';button.disabled=memoryBusy||!!memoryPending||conversationBusy||!!conversationState;button.onclick=()=>openMemory(null,source);
  }
  async function previewMemory(){
    if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;
    if(!memorySource||memoryPending)return;
    if(document.activeElement===$('memory-field'))$('memory-status').focus();
    const generation=++memoryGeneration,scope=memoryScope,ref=memorySource.ref,field=$('memory-field').value;memoryBusy=true;memoryPreview=null;$('memory-confirm').checked=false;memoryFields();conversationFields();
    try{
      const result=await api(`${nextBase}/conversation/memories/preview${nextQuery()}&area_id=${encodeURIComponent(area)}&source_ref=${encodeURIComponent(ref)}&field=${encodeURIComponent(field)}`);
      if(!memoryCurrent(generation,scope))return;
      if(!conversationScoped(result)||result.provider_dispatch!==false||result.source_ref!==ref||result.field!==field||!/^[a-f0-9]{64}$/.test(result.source_sha256)||!conversationSource({ref:result.source_ref,url:result.source_url}))throw new Error('Source preview does not match current scope.');
      memoryPreview=result;$('memory-source-title').textContent=result.source_title;$('memory-preview').textContent=`${memoryLabels[field]}: ${memoryValue(result.value)} · Source version ${result.source_version}. This is a source observation; an estimate is not an approved budget.`;
    }catch(error){if(memoryCurrent(generation,scope)){$('memory-preview').textContent='The current source is unavailable or changed. No observation can be saved.';}}
    finally{if(memoryCurrent(generation,scope)){memoryBusy=false;memoryFields();conversationFields();if(document.activeElement===$('memory-status'))$('memory-field').focus();}}
  }
  async function refreshMemory(generation,scope){
    if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;
    const result=await api(`${nextBase}/conversation/memories${nextQuery()}&area_id=${encodeURIComponent(area)}`);
    if(!memoryCurrent(generation,scope))return false;
    if(!conversationScoped(result)||result.provider_dispatch!==false||!Array.isArray(result.items))throw new Error('Memory scope changed.');
    data.private_memory=result;renderPrivateMemory();conversationFields();return true;
  }
  async function mutateMemory(){
    if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;
    if(memoryBusy||!memoryPending||conversationBusy||conversationState)return;
    const exact=memoryPending,generation=++memoryGeneration,scope=memoryScope;memoryBusy=true;memoryFields();conversationFields();
    try{
      const suffix=exact.action==='delete'?`/${encodeURIComponent(exact.id)}/delete`:'';
      const result=await api(`${nextBase}/conversation/memories${suffix}${nextQuery()}`,exact.body);
      if(!memoryCurrent(generation,scope))return;if(result.id!==exact.id||result.area_id&&result.area_id!==area)throw new Error('Memory outcome does not match this entry.');
      memoryPending=null;memoryEditing=null;memorySource=null;memoryPreview=null;memoryDeleting=null;$('memory-form').hidden=true;$('memory-form').reset();$('memory-delete-confirm').hidden=true;
      if(await refreshMemory(generation,scope))$('memory-status').textContent=exact.action==='save'?'Private typed memory saved. It will be used only after you opt in.':'Private memory value deleted. Work and spend receipts are unchanged; a prior provider copy cannot be recalled.';
    }catch(error){
      if(!memoryCurrent(generation,scope))return;
      if([403,404].includes(error.status)){resetConversation();data.private_memory=null;data.conversation={...data.conversation,ready:false};renderConversation();$('memory-status').textContent='Memory is unavailable under current access. Check availability before continuing.';return;}
      if(error.status&&error.status<500)memoryPending=null;
      $('memory-status').textContent=memoryPending?'The outcome is unconfirmed. Retry this exact operation; it is never repeated automatically.':'The memory or source changed. Close the editor and refresh before reviewing again.';
    }finally{if(memoryCurrent(generation,scope)){memoryBusy=false;memoryFields();conversationFields();$(memoryPending?(memoryPending.action==='delete'?'memory-delete-yes':'memory-save'):$('memory-form').hidden?'memory-refresh':'memory-status').focus();}}
  }
  $('memory-new').onclick=()=>openMemory();$('memory-field').onchange=previewMemory;
  $('memory-key').onchange=()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;memoryPreferenceChoices();$('memory-confirm').checked=false;memoryFields();};
  $('memory-use').onchange=()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(conversationState||conversationBusy)return;conversationGeneration++;$('chat-log').replaceChildren();$('ask-question').value='';conversationFields();};
  $('memory-cancel').onclick=()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryPending||memoryBusy)return;$('memory-form').hidden=true;$('memory-form').reset();memoryEditing=null;memorySource=null;memoryPreview=null;memoryFields();conversationFields();$('memory-new').focus();};
  $('memory-form').oninput=event=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryPending||memoryBusy)return;if(event.target!==$('memory-confirm'))$('memory-confirm').checked=false;memoryFields();};
  $('memory-form').onsubmit=event=>{
    event.preventDefault();if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryBusy||conversationBusy||conversationState)return;if(memoryPending)return memoryPending.action==='save'?mutateMemory():undefined;
    if(!$('memory-confirm').checked||data.private_memory?.can_manage!==true||memorySource&&!memoryPreview)return;
    const id=memoryEditing?.id||crypto.randomUUID(),body={id,request_id:crypto.randomUUID(),area_id:area,expected_version:memoryEditing?.version||0,confirmed:true,retention_days:Number($('memory-retention').value)};
    if(memorySource)Object.assign(body,{type:'source_observation',source_ref:memoryPreview.source_ref,field:memoryPreview.field,source_sha256:memoryPreview.source_sha256});
    else Object.assign(body,{type:'preference',key:$('memory-key').value,value:$('memory-value').value});
    memoryPending={action:'save',id,body};return mutateMemory();
  };
  $('memory-refresh').onclick=async()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryBusy||memoryPending)return;const generation=++memoryGeneration,scope=memoryScope;memoryBusy=true;memoryFields();try{await refreshMemory(generation,scope);}catch(error){if(memoryCurrent(generation,scope)){$('memory-list').replaceChildren();$('memory-status').textContent='Private memory could not be read under current access.';data.private_memory=null;$('memory-form').reset();$('memory-form').hidden=true;}}finally{if(memoryCurrent(generation,scope)){memoryBusy=false;memoryFields();conversationFields();$('memory-refresh').focus();}}};
  $('memory-delete-no').onclick=()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryPending||memoryBusy)return;memoryDeleting=null;$('memory-delete-confirm').hidden=true;memoryFields();conversationFields();$('memory-refresh').focus();};
  $('memory-delete-yes').onclick=()=>{if(typeof campusAskActionAllowed==='function'&&!campusAskActionAllowed())return;if(memoryBusy||conversationBusy||conversationState)return;if(!memoryPending){if(!memoryDeleting||data.private_memory?.can_delete!==true)return;memoryPending={action:'delete',id:memoryDeleting.id,body:{area_id:area,expected_version:memoryDeleting.version,request_id:crypto.randomUUID(),confirmed:true}};}return mutateMemory();};
