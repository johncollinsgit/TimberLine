  let conversationGeneration=0, conversationScope=null, conversationState=null, conversationBusy=false, conversationAvailability=null, conversationOperation=null, conversationRenderedReady=false;
  const conversationMode=()=>transport.mode==='private'&&data.features?.conversation_ui===true;
  const conversationKey=()=>`everbranch-conversation-v1:${new URL(nextBase,location.href).pathname}:${workspace}:${viewer}:${area}`;
  const conversationScopeValue=()=>JSON.stringify([transport.mode,String(workspace),String(viewer),String(data.viewer_id||viewer),area,data.features?.read_only_costs===true,data.features?.read_only_assistant===true,data.features?.resources===true,data.features?.saved_assistants===true,data.features?.private_memory===true]);
  function conversationReceipt(value) {
    try {if(value===null)sessionStorage.removeItem(conversationKey());else sessionStorage.setItem(conversationKey(),JSON.stringify({session_id:value.session_id,request_id:value.request_id}));return true;}catch{return false;}
  }
  function conversationSaved() {
    try {const value=JSON.parse(sessionStorage.getItem(conversationKey())||'null'),uuid=/^[a-f0-9-]{36}$/i;return value&&uuid.test(value.session_id)&&uuid.test(value.request_id)?value:null;}catch{return null;}
  }
  function conversationScoped(value) {
    return value?.scope?.tenant_id===String(workspace)&&value.scope.user_id===String(viewer)&&value.scope.area_id===area;
  }
  function conversationText(host,tag,text,className='') {const node=document.createElement(tag);node.textContent=text;node.className=className;host.append(node);return node;}
  function conversationCurrent(generation,scope) {return generation===conversationGeneration&&scope===conversationScope&&scope===conversationScopeValue()&&conversationMode()&&section==='ask';}
  function resetConversation() {
    conversationGeneration++;conversationScope=null;conversationState=null;conversationBusy=false;conversationAvailability=null;conversationOperation=null;conversationRenderedReady=false;
    if(typeof resetSavedAssistants==='function')resetSavedAssistants();
    if(typeof resetPrivateMemory==='function')resetPrivateMemory();
    $('conversation-controls').hidden=true;$('conversation-clear-confirm').hidden=true;$('conversation-forget').hidden=true;$('conversation-status').textContent='';
    if($('generated-ask-panel').dataset.conversation==='true'){$('chat-log').replaceChildren();$('ask-question').value='';$('ask-question').readOnly=false;}
    delete $('generated-ask-panel').dataset.conversation;
  }
  function conversationFields() {
    const enabled=conversationAvailability?.ready===true&&conversationScoped(conversationAvailability),intent=$('conversation-intent').value;
    const locked=conversationBusy||!!conversationState;
    for(const option of $('conversation-intent').options)option.disabled=!enabled||conversationAvailability?.intents?.[option.value]!==true||(typeof savedAssistantAllows==='function'&&!savedAssistantAllows(option.value));
    $('conversation-intent').disabled=locked||!enabled;$('conversation-building').disabled=locked||!enabled;
    $('conversation-building-field').hidden=intent!=='building';
    const pending=conversationState&&conversationState.phase!=='active';
    $('ask-question').readOnly=!enabled||!!pending||conversationBusy;
    $('ask-submit').disabled=!enabled||!!pending||conversationBusy||conversationAvailability?.intents?.[intent]!==true||(intent==='building'&&!$('conversation-building').value)||(typeof savedAssistantCanSend==='function'&&!savedAssistantCanSend())||(typeof savedAssistantAllows==='function'&&!savedAssistantAllows(intent))||(typeof memoryCanSend==='function'&&!memoryCanSend());
    $('ask-submit').textContent='Send to OpenAI';$('ask-question-label').textContent='Ask about this workspace';
    $('conversation-stop').hidden=!conversationBusy||conversationOperation!=='turn';$('conversation-check').disabled=conversationBusy;
    $('conversation-check').textContent=conversationState?'Check this conversation':'Check availability';
    $('conversation-clear').hidden=!conversationState;$('conversation-clear').disabled=conversationBusy;$('conversation-clear-yes').disabled=conversationBusy;$('conversation-clear-no').disabled=conversationBusy;$('conversation-forget').disabled=conversationBusy;
    if(typeof savedAssistantFields==='function')savedAssistantFields();
    if(typeof memoryFields==='function')memoryFields();
  }
  function renderConversation() {
    if(!conversationMode()){if(conversationScope!==null)resetConversation();return false;}
    if(section!=='ask'){resetConversation();return false;}
    if(conversationScope!==conversationScopeValue()||conversationRenderedReady&&data.conversation?.ready!==true){
      resetConversation();conversationScope=conversationScopeValue();
      const saved=conversationSaved();if(saved)conversationState={...saved,phase:'restore'};
      $('chat-log').replaceChildren();$('ask-question').value='';
    }
    $('generated-ask-panel').dataset.conversation='true';$('conversation-controls').hidden=false;
    const availability=data.conversation;
    conversationAvailability=conversationScoped(availability)?availability:null;conversationRenderedReady=conversationAvailability?.ready===true;
    const name=data.areas?.find(value=>value.id===area)?.name||'Choose a current department';
    $('conversation-context').textContent=`${data.workspace?.name||'Current workspace'} · ${name} · Your current permissions`;
    $('ask-model-caption').textContent='Deterministic checks below use no AI model. Text conversation is separately labeled and requires approved setup.';
    $('conversation-status').textContent=conversationState?.phase==='restore'?'A short conversation may be available. Check it before sending another question.':conversationState?.phase==='unconfirmed'?'This turn is unconfirmed. Check this conversation before continuing.':conversationBusy?'Waiting for the current request.':availability?.message||'Choose an authorized workspace to check text conversation availability.';
    const selected=$('conversation-building').value,choices=data.buildings?.items||[];
    if($('conversation-building').dataset.choices!==JSON.stringify(choices)){
      $('conversation-building').replaceChildren(new Option('Choose a saved building',''),...choices.map(value=>new Option(value.title,value.id)));
      if(choices.some(value=>value.id===selected))$('conversation-building').value=selected;
      $('conversation-building').dataset.choices=JSON.stringify(choices);
    }
    const amount=value=>Number.isSafeInteger(value)&&value>=0?'$'+(value/1000000).toFixed(6):'unavailable';
    $('conversation-allowance').textContent=conversationAvailability?.ready?`Checked remaining lifetime allowance: ${amount(availability.remaining_microusd)}. Maximum held before one reply: ${amount(availability.maximum_turn_microusd)}. Final access and allowance are checked again on Send.`:'The shared $5 lifetime trial is unavailable until its connection and cost bounds are approved.';
    if(typeof renderSavedAssistants==='function')renderSavedAssistants();
    if(typeof renderPrivateMemory==='function')renderPrivateMemory();
    conversationFields();return true;
  }
  function conversationSource(source) {
    try {
      const parts=source.ref.split(':'),url=new URL(source.url,location.href),expected=new URL(`${nextBase}/assistant-sources/${encodeURIComponent(parts[0])}/${encodeURIComponent(parts[1])}`,location.href);
      return parts.length===2&&['project','request','building','cost','task'].includes(parts[0])&&url.origin===location.origin&&!url.username&&!url.password&&!url.hash&&url.pathname===expected.pathname&&url.searchParams.get('tenant')===tenantSlug&&url.searchParams.get('area_id')===area;
    }catch{return false;}
  }
  function conversationAnswer(result,requestId) {
    if(typeof result.reply==='string'&&result.mode==='deterministic_policy'&&result.provider_dispatch===false&&result.session_id===conversationState?.session_id&&result.request_id===requestId){
      const answer=chatMessage(result.reply);conversationText(answer,'p','Read-only policy · no model request','caption');return false;
    }
    if(typeof result.reply!=='string'||!conversationScoped(result)||result.mode!=='openai_generated'||result.provider_dispatch!==true||result.session_id!==conversationState?.session_id||result.request_id!==requestId||!Array.isArray(result.sources)||!result.sources.every(conversationSource))throw new Error('This reply does not match the current workspace and source records. Check this conversation before continuing.');
    const refs=result.sources.map(source=>source.ref),amount=value=>value===null||Number.isSafeInteger(value);
    if(!Array.isArray(result.calculations)||!result.calculations.every(value=>typeof value.project==='string'&&typeof value.currency==='string'&&['estimate_minor','recorded_cost_minor','difference_minor'].every(key=>amount(value[key]))&&Array.isArray(value.input_refs)&&value.input_refs.every(ref=>refs.includes(ref)))||!Array.isArray(result.cited_refs)||!result.cited_refs.every(ref=>refs.includes(ref)))throw new Error('The reply evidence cannot be inspected.');
    if(result.memory_opted_in===true&&(!Array.isArray(result.memory_used)||result.memory_used.length>8||!result.memory_used.every(item=>typeof item.id==='string'&&Number.isInteger(item.version)&&typeof item.captured_at==='string'&&typeof item.expires_at==='string'&&(item.type==='preference'&&({reply_length:['brief','standard'],date_style:['iso','long']})[item.key]?.includes(item.value)||item.type==='source_observation'&&refs.includes(item.source_ref)&&['status','priority','due_on','amount_minor','currency','basis'].includes(item.field)&&(item.value===null||typeof item.value==='string'||Number.isSafeInteger(item.value))))))throw new Error('Typed memory evidence cannot be inspected.');
    const answer=chatMessage(result.reply);answer.classList.add('conversation-answer');
    conversationText(answer,'p',result.provider_simulated?'Simulated OpenAI reply · local testing · no paid API call':'AI-generated text · check current sources','caption');
    if(result.sample_data)conversationText(answer,'p','Sample records · no live campus feed','caption');
    for(const calculation of result.calculations||[]){
      const card=document.createElement('section');card.className='planning-record';
      const amount=value=>value===null?'Not recorded':Number.isSafeInteger(value)?(['USD','CAD','EUR','GBP'].includes(calculation.currency)?`${calculation.currency} ${(value/100).toFixed(2)}`:`${value} ${calculation.currency} minor units`):'Unverified';
      conversationText(card,'h4',calculation.project);
      conversationText(card,'p',`Checked estimate: ${amount(calculation.estimate_minor)}. Recorded cost: ${amount(calculation.recorded_cost_minor)}. Difference: ${amount(calculation.difference_minor)}.`);
      conversationText(card,'p','Server-computed; an estimate is not an approved budget.','caption');
      const inputs=document.createElement('details');conversationText(inputs,'summary','Inspect calculation inputs');
      for(const ref of calculation.input_refs||[]){const source=result.sources.find(value=>value.ref===ref);if(!source)throw new Error('A calculation input cannot be inspected.');const link=conversationText(inputs,'a',source.title);link.href=transport.href(source.url);}
      card.append(inputs);answer.append(card);
    }
    const evidence=document.createElement('details');conversationText(evidence,'summary',`Inspect ${result.sources.length} current source records`);
    for(const source of result.sources){
      const card=document.createElement('section');card.className='planning-record';
      const link=conversationText(card,'a',`View source: ${source.title}`);link.href=transport.href(source.url);
      const facts=Object.entries(source.facts||{}).filter(([key])=>key!=='version').map(([key,value])=>`${key.replaceAll('_',' ')}: ${value??'not recorded'}`).join(' · ');
      conversationText(card,'p',facts,'caption');
      if(result.cited_refs?.includes(source.ref))conversationText(card,'p','Cited in this reply','caption');evidence.append(card);
      if(typeof memorySourceAction==='function')memorySourceAction(card,source);
    }
    answer.append(evidence);
    if(result.memory_opted_in){
      const used=document.createElement('details');conversationText(used,'summary',`Inspect ${result.memory_used?.length||0} typed memory entries used`);
      for(const item of result.memory_used||[]){
        conversationText(used,'p',`${item.type==='preference'?'Formatting preference':'Current scoped observation'} · ${item.key||item.field}: ${item.value??'not recorded'} · Confirmed ${item.captured_at}; expires ${item.expires_at}.`,'caption');
        if(item.source_ref){const source=result.sources.find(value=>value.ref===item.source_ref),link=conversationText(used,'a',`Observation source: ${source.title}`);link.href=transport.href(source.url);}
      }
      answer.append(used);
    }
    conversationText(answer,'p',`As of ${result.as_of}. Current linked records remain the evidence; generated text can be wrong.`,'caption');
    answer.scrollIntoView({block:'nearest'});return true;
  }
  async function conversationSend(event) {
    event.preventDefault();if(!conversationMode()||conversationBusy||conversationAvailability?.ready!==true||!conversationScoped(conversationAvailability)||conversationState&&conversationState.phase!=='active')return;
    if(typeof savedAssistantCanSend==='function'&&!savedAssistantCanSend())return;
    if(typeof memoryCanSend==='function'&&!memoryCanSend())return;
    const question=$('ask-question').value.trim(),intent=$('conversation-intent').value;if(!question||conversationAvailability.intents?.[intent]!==true||(typeof savedAssistantAllows==='function'&&!savedAssistantAllows(intent)))return;
    const previousState=conversationState?{...conversationState}:null;
    const session=conversationState?.session_id||crypto.randomUUID(),requestId=crypto.randomUUID(),body={area_id:area,intent,session_id:session,request_id:requestId,question};
    if(typeof selectedSavedAssistant==='function'&&selectedSavedAssistant())body.assistant_id=selectedSavedAssistant().id;
    if(typeof memoryEnabled==='function'&&memoryEnabled()&&$('memory-use').checked)body.use_memory=true;
    if(intent==='building'){body.building_id=$('conversation-building').value;if(!body.building_id)return;}
    if(!conversationReceipt({session_id:session,request_id:requestId})){$('conversation-status').textContent='This browser cannot retain receipt IDs for recovery. No question was sent.';return;}
    conversationState={session_id:session,request_id:requestId,phase:'waiting'};conversationBusy=true;conversationOperation='turn';
    const generation=++conversationGeneration,scope=conversationScope;chatMessage(question,'question');conversationFields();
    $('conversation-status').textContent='Waiting for a bounded OpenAI reply. Stop waiting hides a late reply; its reserved cost stays held.';
    try{
      const result=await api(`${nextBase}/conversation/turns${nextQuery()}`,body);
      if(!conversationCurrent(generation,scope))return;
      const generated=conversationAnswer(result,requestId);
      conversationState=generated?{session_id:session,request_id:requestId,phase:'active'}:previousState;
      if(!generated)conversationReceipt(previousState);
      $('ask-question').value='';$('conversation-status').textContent=generated?'Reply received. Open its sources or ask a follow-up in this same focus.':'Nothing was changed or sent.';
    }catch(error){
      if(!conversationCurrent(generation,scope))return;
      conversationState.phase='unconfirmed';
      if([403,404].includes(error.status)){$('chat-log').replaceChildren();$('ask-question').value='';}
      $('conversation-status').textContent='This turn is unconfirmed or unavailable. Check this conversation; it will not be sent again automatically.';
    }finally{if(conversationCurrent(generation,scope)){conversationBusy=false;conversationOperation=null;conversationFields();}}
  }
  $('conversation-stop').onclick=()=>{
    if(!conversationBusy)return;conversationGeneration++;conversationBusy=false;if(conversationState)conversationState.phase='unconfirmed';
    $('ask-question').value='';$('conversation-status').textContent='Stopped waiting. A reply may still incur its reserved cost. Check this conversation before continuing.';conversationFields();$('conversation-check').focus();
  };
  $('conversation-check').onclick=async()=>{
    if(!conversationMode()||conversationBusy)return;
    const generation=++conversationGeneration,scope=conversationScope;conversationBusy=true;conversationOperation='check';conversationFields();
    try{
      const availability=await api(`${nextBase}/conversation/status${nextQuery()}&area_id=${encodeURIComponent(area)}`);
      if(!conversationCurrent(generation,scope))return;if(!conversationScoped(availability))throw new Error('Availability scope changed.');
      data.conversation=availability;conversationAvailability=availability;
      if(!conversationState){$('conversation-status').textContent=availability.message;return;}
      if(availability.state==='unavailable'){$('chat-log').replaceChildren();$('ask-question').value='';$('conversation-status').textContent=availability.message;return;}
      const id=conversationState.session_id,result=await api(`${nextBase}/conversation/sessions/${encodeURIComponent(id)}${nextQuery()}&area_id=${encodeURIComponent(area)}`);
      if(!conversationCurrent(generation,scope))return;
      if(!conversationScoped(result)||result.session_id!==id||result.provider_dispatch!==false)throw new Error('Recovery scope changed.');
      $('chat-log').replaceChildren();$('ask-question').value='';
      if(result.assistant_id&&(!(typeof selectedSavedAssistant==='function')||!savedAssistantEnabled()||!savedAssistantList().some(value=>value.id===result.assistant_id)))throw new Error('Saved assistant is unavailable.');
      if(typeof selectedSavedAssistant==='function')$('saved-assistant-choice').value=result.assistant_id||'';
      if(result.use_memory&&(!(typeof memoryEnabled==='function')||!memoryEnabled()))throw new Error('Private memory is unavailable.');
      if(typeof memoryEnabled==='function'&&memoryEnabled())$('memory-use').checked=result.use_memory===true;
      $('conversation-intent').value=result.intent;$('conversation-building').value=result.building_id||'';
      if(!Array.isArray(result.history)||!result.history.every(item=>['user','assistant'].includes(item.role)&&typeof item.text==='string'))throw new Error('Conversation history is invalid.');
      const history=result.history,previous=result.last_result?history.slice(0,-1):history;
      for(const item of previous){const node=chatMessage(item.text,item.role==='user'?'question':'');if(item.role==='assistant')conversationText(node,'p','Earlier generated reply · conversation context','caption');}
      if(result.state==='active'&&result.last_result){conversationState={session_id:id,request_id:result.last_result.request_id,phase:'active'};conversationAnswer(result.last_result,result.last_result.request_id);conversationReceipt(conversationState);}
      else conversationState={session_id:id,request_id:result.pending_request_id||conversationState.request_id,phase:'unconfirmed'};
      $('conversation-status').textContent=result.message+' No provider request was repeated.';
    }catch(error){if(conversationCurrent(generation,scope)){$('chat-log').replaceChildren();$('ask-question').value='';if(conversationState)conversationState.phase='unconfirmed';$('conversation-status').textContent='This conversation cannot be restored under current access or source records. Clear it to continue; any existing cost exposure remains.';}}
    finally{if(conversationCurrent(generation,scope)){conversationBusy=false;conversationOperation=null;conversationFields();}}
  };
  $('conversation-clear').onclick=()=>{$('conversation-clear-confirm').hidden=false;$('conversation-clear-yes').focus();};
  $('conversation-clear-no').onclick=()=>{$('conversation-clear-confirm').hidden=true;$('conversation-clear').focus();};
  $('conversation-clear-yes').onclick=async()=>{
    if(!conversationMode()||conversationBusy||!conversationState)return;
    const generation=++conversationGeneration,scope=conversationScope,id=conversationState.session_id;conversationBusy=true;conversationOperation='clear';conversationFields();
    try{
      const result=await api(`${nextBase}/conversation/sessions/${encodeURIComponent(id)}/end${nextQuery()}`,{area_id:area,confirm_end:true});
      if(!conversationCurrent(generation,scope))return;if(result.session_id!==id||result.status!=='ended')throw new Error('Clear outcome unconfirmed.');
      conversationReceipt(null);conversationState=null;$('chat-log').replaceChildren();$('ask-question').value='';$('conversation-clear-confirm').hidden=true;
      if(typeof memoryEnabled==='function'&&memoryEnabled())$('memory-use').checked=false;
      $('conversation-status').textContent='Conversation content cleared. Existing reservations and metering evidence remain.';
    }catch(error){if(conversationCurrent(generation,scope)){
      $('conversation-forget').hidden=error.status!==404;
      $('conversation-status').textContent=error.status===404?'Server content could not be cleared under current access. You may forget this browser receipt only; server content and cost exposure remain until expiry or review.':'Clearing could not be confirmed. The receipt ID is retained; no new question was sent.';
    }}
    finally{if(conversationCurrent(generation,scope)){conversationBusy=false;conversationOperation=null;conversationFields();$('conversation-check').focus();}}
  };
  $('conversation-forget').onclick=()=>{
    if(conversationBusy||$('conversation-forget').hidden||!conversationReceipt(null))return;
    conversationGeneration++;conversationState=null;conversationAvailability=null;data.conversation={...data.conversation,ready:false};
    if(typeof memoryEnabled==='function'&&memoryEnabled())$('memory-use').checked=false;
    $('chat-log').replaceChildren();$('ask-question').value='';$('conversation-clear-confirm').hidden=true;$('conversation-forget').hidden=true;
    $('conversation-status').textContent='Receipt removed from this browser only. Server content and existing cost exposure remain. Check current availability before starting a new conversation.';conversationFields();$('conversation-check').focus();
  };
  for(const id of ['conversation-intent','conversation-building'])$(id).onchange=()=>{if(conversationState)return;conversationGeneration++;$('chat-log').replaceChildren();conversationFields();};
