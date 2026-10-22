  let voiceSession=null, voiceScope=null, voiceJob=null, voicePreviewOpener=null;
  function clearVoiceJob(){voiceJob=null;$('ask-job-detail').replaceChildren();$('ask-message-preview-actions').hidden=true;$('ask-message-preview').close();$('ask-message-preview-body').textContent='';}
  function clearVoiceEvidence(){clearVoiceJob();$('chat-log').replaceChildren();}
  function resetVoice(){if(voiceSession){$('chat-log').replaceChildren();askBusy=false;$('ask-submit').disabled=false;}voiceSession?.dispose();voiceSession=null;voiceScope=null;clearVoiceJob();}
  function voiceSource(value,label,host){
    try{
      const url=new URL(value,location.href),back=new URL(urls.back,location.href),path=value=>value.replace(/\/$/,'')||'/';
      const sourcePath=transport.mode==='sample'?url.pathname==='/':path(url.pathname)===path(back.pathname);
      if(url.origin!==location.origin||url.username||url.password||!sourcePath||!['projects','requests'].includes(url.searchParams.get('section'))||url.searchParams.get('area_id')!==String(area)||!url.searchParams.get('selected'))return;
      if(transport.mode==='private'&&url.searchParams.get('tenant')!==tenantSlug)return;
      if(label==='Open recorded request / map'){url.searchParams.set('view','map');url.searchParams.set('map','1');}
      const link=document.createElement('a');link.className='button';link.href=transport.href(url.href);link.textContent=label;
      link.onclick=event=>{if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:url.searchParams.get('section'),area:url.searchParams.get('area_id'),selected:url.searchParams.get('selected'),...(url.searchParams.get('view')==='map'?{view:'map',map:true}:{})}}));};host.append(link);
    }catch{}
  }
  async function openVoiceJob(job,{signal}){
    const scope=voiceScope,scopeArea=area;
    const current=await transport.request(`${nextBase}/operations/tasks/${encodeURIComponent(job.id)}${nextQuery()}&area_id=${encodeURIComponent(scopeArea)}`,undefined,{signal});
    if(signal.aborted||voiceScope!==scope||section!=='ask'||area!==scopeArea)return false;
    const task=current.task||current;
    if(String(task.id)!==String(job.id)||String(task.project_id)!==String(job.project_id)||String(task.area_id)!==String(scopeArea)||task.version!==job.version||task.task_version!==job.task_version)throw new Error('The job changed or current detail could not be verified. Ask again to refresh its facts.');
    voiceJob=job;const host=$('ask-job-detail');host.replaceChildren();
    const title=document.createElement('h3');title.textContent=job.title;host.append(title);
    const facts=document.createElement('dl');
    const fields=[['Status',job.status],['Accountable owner',job.owner],['Current handler',job.handler],['Due date',job.due_on],['Next action',job.next_action],['Blockers',(job.blockers||[]).join('; ')||'None recorded'],['Recorded location',job.location?.label],['Location evidence',job.location?.provenance]];
    for(const[label,value]of fields){const cell=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=typeof value==='string'&&value?value:'Not recorded';cell.append(dt,dd);facts.append(cell);}host.append(facts);
    const position=document.createElement('p');position.className='caption';position.textContent=job.location?.latitude!=null&&job.location?.longitude!=null?`Recorded position: ${job.location.latitude}, ${job.location.longitude}. Not GPS or a verified campus placement.`:'No recorded coordinates. A map pin cannot be placed accurately.';host.append(position);
    const actions=document.createElement('div');actions.className='actions';voiceSource(job.source_url,'Open full project',actions);if(job.location?.source_url)voiceSource(job.location.source_url,'Open recorded request / map',actions);host.append(actions);
    $('ask-message-preview-actions').hidden=false;return true;
  }
  function voiceReply(result){
    if(result.outcome!=='matched')clearVoiceJob();
    const answer=chatMessage(result.reply);
    const session=voiceSession,scope=voiceScope;
    for(const row of result.candidates||[]){const button=document.createElement('button');button.type='button';button.className='button';button.textContent=row.title;button.onclick=()=>{if(session!==voiceSession||scope!==voiceScope)return;session?.choose(row.id).catch(error=>{if(session===voiceSession&&scope===voiceScope){clearVoiceEvidence();message(error.message,true);}});};answer.append(button);}
    if(result.job){voiceSource(result.job.source_url,'Source job',answer);if(result.job.location?.source_url)voiceSource(result.job.location.source_url,'Source location',answer);}
    const note=document.createElement('p');note.className='caption';note.textContent=`${result.sample_data?'Fictional demonstration records. ':''}Record-backed lookup · no model/provider dispatch. As of ${result.as_of}. ${typeof result.coverage==='string'?result.coverage:JSON.stringify(result.coverage)} ${result.opened===false?'Detail was not opened.':''}`;answer.append(note);
    while($('chat-log').children.length>20)$('chat-log').firstElementChild.remove();
  }
  function renderVoice(){
    const enabled=section==='ask'&&!!data.features?.voice_jobs&&!!data.features?.ask&&!!area;
    $('ask-voice-pane').hidden=!enabled;
    const stamp=enabled?JSON.stringify([transport.mode,workspace,data.viewer_id||data.viewer||viewer,area,section]):null;
    if(stamp!==voiceScope){resetVoice();voiceScope=stamp;}
    if(!enabled){if(!askBusy)$('ask-submit').disabled=false;return;}if(voiceSession)return;
    if(!window.EverbranchVoiceJobs){$('ask-voice-controls').textContent='Record-backed job lookup is unavailable until reviewed application assets finish loading. Reload if this persists; manual work remains available. No model request is substituted.';$('ask-submit').disabled=true;return;}
    if(!askBusy)$('ask-submit').disabled=false;
    $('ask-voice-controls').replaceChildren();
    voiceSession=EverbranchVoiceJobs.mount($('ask-voice-controls'),{
      context:()=>({runtime:transport.mode,authenticated:transport.mode==='private',tenantId:workspace,userId:data.viewer_id||data.viewer||viewer,areaId:area}),
      lookup:(body,options)=>transport.request(`${nextBase}/voice/jobs${nextQuery()}`,body,options),openJob:openVoiceJob,onReply:voiceReply,
      onTranscript:text=>{$('ask-question').value=text;chatMessage(text,'question');},onError:text=>{clearVoiceEvidence();message(text,true);},
      Recognition:window.SpeechRecognition||window.webkitSpeechRecognition,Utterance:window.SpeechSynthesisUtterance,synthesis:window.speechSynthesis,
    });
  }
  window.addEventListener('everbranch:voice-jobs-ready',()=>{if(section==='ask')renderVoice();});
  const originalAskSubmit=$('ask-form').onsubmit;
  $('ask-form').onsubmit=async event=>{
    if(!data.features?.voice_jobs)return originalAskSubmit(event);
    event.preventDefault();renderVoice();if(!voiceSession){message('The reviewed job lookup assets are unavailable. No model request was made. Reload before continuing.',true);return;}if(askBusy)return;
    const question=$('ask-question').value.trim();if(!question)return;askBusy=true;$('ask-submit').disabled=true;chatMessage(question,'question');
    const session=voiceSession,scope=voiceScope,current=()=>voiceSession===session&&voiceScope===scope;
    try{await session.ask(question);}catch(error){if(current()&&error.name!=='AbortError'){clearVoiceEvidence();message(error.message,true);}}finally{if(current()){askBusy=false;$('ask-submit').disabled=false;}}
  };
  for(const button of document.querySelectorAll('[data-ask-preview]'))button.onclick=()=>{
    if(!voiceJob||section!=='ask')return;voicePreviewOpener=button;
    const kind=button.dataset.askPreview,job=voiceJob;$('ask-message-preview-title').textContent=kind==='email'?'Email preview':'Text message preview';
    $('ask-message-preview-body').textContent=`${kind==='email'?'Subject: Job update — '+job.title+'\n\n':''}${job.title}\nStatus: ${job.status}\nCurrent handler: ${job.handler||'Not recorded'}\nNext action: ${job.next_action||'Not recorded'}\nLocation: ${job.location?.label||'Not recorded'}\n\nSource: ${job.source_url}\nPrepared from the currently displayed record. Preview only — not sent.`;
    $('ask-message-preview').showModal();$('ask-message-preview-close').focus();
  };
  $('ask-message-preview-close').onclick=()=>{$('ask-message-preview').close();};
  $('ask-message-preview').addEventListener('close',()=>{if(section==='ask'&&voicePreviewOpener?.isConnected)voicePreviewOpener.focus();});
