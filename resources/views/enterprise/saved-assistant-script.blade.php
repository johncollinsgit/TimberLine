  let savedAssistantGeneration=0,savedAssistantScope=null,savedAssistantEditing=null,savedAssistantPending=null,savedAssistantBusy=false;
  const savedAssistantEnabled=()=>conversationMode()&&data.features?.saved_assistants===true;
  const savedAssistantList=()=>data.saved_assistants?.items||[];
  const selectedSavedAssistant=()=>savedAssistantEnabled()?savedAssistantList().find(value=>value.id===$('saved-assistant-choice').value)||null:null;
  const savedAssistantCanSend=()=>!savedAssistantEnabled()||!savedAssistantBusy&&!savedAssistantPending&&$('saved-assistant-form').hidden;
  const savedAssistantAllows=intent=>!selectedSavedAssistant()||selectedSavedAssistant().available_checks.includes(intent);
  function resetSavedAssistants(){
    savedAssistantGeneration++;savedAssistantScope=null;savedAssistantEditing=null;savedAssistantPending=null;savedAssistantBusy=false;
    $('saved-assistants').hidden=true;$('saved-assistant-form').hidden=true;$('saved-assistant-form').reset();$('saved-assistant-delete-confirm').hidden=true;$('saved-assistant-status').textContent='';$('saved-assistant-provenance').textContent='';$('saved-assistant-choice').replaceChildren(new Option('Default current-work reader',''));
  }
  function savedAssistantFields(){
    const manage=data.saved_assistants?.can_manage===true,locked=savedAssistantBusy||!!savedAssistantPending,conversationLocked=conversationBusy||!!conversationState;
    $('saved-assistant-choice').disabled=conversationLocked||locked;$('saved-assistant-new').disabled=!manage||conversationLocked||locked;
    $('saved-assistant-edit').hidden=!selectedSavedAssistant();$('saved-assistant-edit').disabled=conversationLocked||locked;
    $('saved-assistant-refresh').disabled=savedAssistantBusy;
    for(const id of ['saved-assistant-name','saved-assistant-purpose','saved-assistant-instructions'])$(id).readOnly=locked||!manage;
    for(const node of document.querySelectorAll('[data-saved-assistant-check]'))node.disabled=locked||!manage||data.saved_assistants?.checks?.[node.value]!==true;
    $('saved-assistant-confirm').disabled=locked||!manage;
    $('saved-assistant-save').disabled=savedAssistantBusy||conversationLocked||!manage||savedAssistantPending?.action==='delete'||!savedAssistantPending&&!$('saved-assistant-confirm').checked;
    $('saved-assistant-save').textContent=savedAssistantPending?.action==='save'?'Retry this exact configuration':'Save private assistant';
    $('saved-assistant-cancel').disabled=locked;$('saved-assistant-delete').hidden=!savedAssistantEditing||!manage;$('saved-assistant-delete').disabled=locked;
    $('saved-assistant-delete-yes').disabled=savedAssistantBusy;$('saved-assistant-delete-no').disabled=locked;
    $('saved-assistant-delete-yes').textContent=savedAssistantPending?.action==='delete'?'Retry this exact deletion':'Delete this private assistant';
  }
  function renderSavedAssistants(){
    if(!savedAssistantEnabled()){if(savedAssistantScope!==null)resetSavedAssistants();return;}
    if(savedAssistantScope!==conversationScope){resetSavedAssistants();savedAssistantScope=conversationScope;}
    $('saved-assistants').hidden=false;
    const selected=$('saved-assistant-choice').value,choices=savedAssistantList();
    $('saved-assistant-choice').replaceChildren(new Option('Default current-work reader',''),...choices.map(value=>new Option(value.name,value.id)));
    if(choices.some(value=>value.id===selected))$('saved-assistant-choice').value=selected;
    const profile=selectedSavedAssistant();$('saved-assistant-summary').textContent=profile?`${profile.purpose} · Available read checks: ${profile.available_checks.map(key=>({overview:'current work',building:'saved building work',costs:'recorded costs and estimates'})[key]).join(', ')||'none under current access'}. Instructions are preferences; current permissions still apply.`:'The default reader uses currently permitted records. A saved purpose and instructions never add permissions or tools.';
    savedAssistantFields();
  }
  function openSavedAssistant(profile){
    if(savedAssistantBusy||savedAssistantPending||conversationBusy||conversationState)return;
    savedAssistantEditing=profile||null;$('saved-assistant-form').reset();$('saved-assistant-form').hidden=false;$('saved-assistant-delete-confirm').hidden=true;
    $('saved-assistant-edit-title').textContent=profile?'Inspect or edit your assistant':'Make an Everbranch Assistant';
    $('saved-assistant-name').value=profile?.name||'';$('saved-assistant-purpose').value=profile?.purpose||'';$('saved-assistant-instructions').value=profile?.instructions||'';
    for(const node of document.querySelectorAll('[data-saved-assistant-check]'))node.checked=profile?profile.checks.includes(node.value):node.value==='overview';
    $('saved-assistant-provenance').textContent=profile?`Explicitly saved by account ${profile.provenance.created_by} on ${profile.provenance.created_at}. ${profile.retention}`:'This configuration is explicitly saved by you; it is never captured from chat automatically.';
    $('saved-assistant-status').textContent='Review this private configuration before saving. Do not enter secrets.';savedAssistantFields();conversationFields();$('saved-assistant-name').focus();
  }
  const savedAssistantCurrent=(generation,scope)=>generation===savedAssistantGeneration&&scope===savedAssistantScope&&scope===conversationScope&&scope===conversationScopeValue()&&savedAssistantEnabled()&&section==='ask';
  async function savedAssistantRefresh(generation,scope){
    const result=await api(`${nextBase}/conversation/assistants${nextQuery()}&area_id=${encodeURIComponent(area)}`);
    if(!savedAssistantCurrent(generation,scope))return false;
    if(!conversationScoped(result)||result.provider_dispatch!==false||!Array.isArray(result.items))throw new Error('Saved assistant scope changed.');
    data.saved_assistants=result;renderSavedAssistants();conversationFields();return true;
  }
  async function mutateSavedAssistant(){
    if(savedAssistantBusy||!savedAssistantPending)return;
    const exact=savedAssistantPending,generation=++savedAssistantGeneration,scope=savedAssistantScope;savedAssistantBusy=true;savedAssistantFields();
    try{
      const suffix=exact.action==='delete'?`/${encodeURIComponent(exact.id)}/delete`:'';
      const result=await api(`${nextBase}/conversation/assistants${suffix}${nextQuery()}`,exact.body);
      if(!savedAssistantCurrent(generation,scope))return;
      if(result.id!==exact.id||result.area_id&&result.area_id!==area)throw new Error('Saved assistant outcome does not match this configuration.');
      savedAssistantPending=null;savedAssistantEditing=null;$('saved-assistant-form').hidden=true;$('saved-assistant-form').reset();$('saved-assistant-delete-confirm').hidden=true;
      if(await savedAssistantRefresh(generation,scope)){
        if(exact.action==='save')$('saved-assistant-choice').value=exact.id;
        renderSavedAssistants();conversationFields();$('saved-assistant-status').textContent=exact.action==='save'?'Private assistant saved. It can use only currently permitted read checks.':'Assistant configuration deleted. Work records and spend receipts are unchanged.';$('saved-assistant-choice').focus();
      }
    }catch(error){
      if(!savedAssistantCurrent(generation,scope))return;
      if([403,404].includes(error.status)){resetConversation();data.saved_assistants=null;data.conversation={...data.conversation,ready:false};renderConversation();$('conversation-status').textContent='The saved assistant is unavailable under current access. Check availability before continuing.';return;}
      if(error.status&&error.status<500)savedAssistantPending=null;
      $('saved-assistant-status').textContent=savedAssistantPending?'The outcome is unconfirmed. Retry this exact configuration; it will not be saved again automatically.':error.status===409?'Close configuration, refresh saved assistants, then inspect the current saved details before editing.':error.message+' Review the fields and confirm again.';
    }finally{if(savedAssistantCurrent(generation,scope)){savedAssistantBusy=false;savedAssistantFields();conversationFields();}}
  }
  $('saved-assistant-choice').onchange=()=>{if(conversationState||conversationBusy)return;$('chat-log').replaceChildren();$('ask-question').value='';renderSavedAssistants();conversationFields();};
  $('saved-assistant-new').onclick=()=>openSavedAssistant(null);$('saved-assistant-edit').onclick=()=>openSavedAssistant(selectedSavedAssistant());
  $('saved-assistant-cancel').onclick=()=>{if(savedAssistantPending||savedAssistantBusy)return;$('saved-assistant-form').hidden=true;$('saved-assistant-form').reset();savedAssistantEditing=null;conversationFields();$('saved-assistant-choice').focus();};
  $('saved-assistant-form').oninput=event=>{if(savedAssistantPending||savedAssistantBusy)return;if(event.target!==$('saved-assistant-confirm'))$('saved-assistant-confirm').checked=false;savedAssistantFields();};
  $('saved-assistant-form').onsubmit=event=>{
    event.preventDefault();if(savedAssistantBusy||conversationBusy||conversationState)return;if(savedAssistantPending)return savedAssistantPending.action==='save'?mutateSavedAssistant():undefined;
    if(!$('saved-assistant-confirm').checked||data.saved_assistants?.can_manage!==true)return;
    const id=savedAssistantEditing?.id||crypto.randomUUID();savedAssistantPending={action:'save',id,body:{id,request_id:crypto.randomUUID(),area_id:area,expected_version:savedAssistantEditing?.version||0,confirmed:true,name:$('saved-assistant-name').value.trim(),purpose:$('saved-assistant-purpose').value.trim(),instructions:$('saved-assistant-instructions').value.trim(),checks:[...document.querySelectorAll('[data-saved-assistant-check]:checked:not(:disabled)')].map(node=>node.value)}};
    return mutateSavedAssistant();
  };
  $('saved-assistant-refresh').onclick=async()=>{if(savedAssistantBusy)return;const generation=++savedAssistantGeneration,scope=savedAssistantScope;savedAssistantBusy=true;savedAssistantFields();try{await savedAssistantRefresh(generation,scope);}catch(error){if(savedAssistantCurrent(generation,scope)){$('saved-assistant-status').textContent='Saved assistants are unavailable under current access.';data.saved_assistants=null;$('saved-assistant-form').reset();$('saved-assistant-form').hidden=true;}}finally{if(savedAssistantCurrent(generation,scope)){savedAssistantBusy=false;savedAssistantFields();conversationFields();}}};
  $('saved-assistant-delete').onclick=()=>{if(!savedAssistantEditing||savedAssistantPending)return;$('saved-assistant-delete-confirm').hidden=false;$('saved-assistant-delete-yes').focus();};
  $('saved-assistant-delete-no').onclick=()=>{$('saved-assistant-delete-confirm').hidden=true;$('saved-assistant-delete').focus();};
  $('saved-assistant-delete-yes').onclick=()=>{if(savedAssistantBusy)return;if(!savedAssistantPending){if(!savedAssistantEditing)return;savedAssistantPending={action:'delete',id:savedAssistantEditing.id,body:{area_id:area,expected_version:savedAssistantEditing.version,request_id:crypto.randomUUID(),confirmed:true}};}return mutateSavedAssistant();};
