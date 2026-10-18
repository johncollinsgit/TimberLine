import {demoToolNames} from './workbench.mjs';
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

export function createRetailVoice({context,openModal}) {
 const completedCalls=new Set();
 let responseActive=false,pendingText=null;
 let peer=null,channel=null,microphone=null,audio=null,abort=null,sessionTimer=null,connectTimer=null,probeTimer=null;
 let generation=0,muted=false,speaking=false,busy=false,startPending=false,selected=null,focusedCustomer=null,pageAtOpen='home',rows=new Map(),sequence=0;
 const panel=()=>document.querySelector('#retail-voice');
 const update=(next,message)=>{const node=panel();if(!node)return;node.dataset.state=next;
  node.querySelector('#voice-status').textContent=message;
  const running=!!peer||busy;
  node.querySelector('[data-voice-action="start"]').disabled=running||next!=='ready';
  node.querySelector('[data-voice-action="end"]').disabled=!running;
  node.querySelector('[data-voice-action="mute"]').disabled=!peer;
  node.querySelector('#voice-merchant').disabled=running;
  node.querySelector('[data-voice-action="check"]').disabled=running||next==='checking';
 };
 async function request(path,data,timeout=5000) {
  const controller=new AbortController();abort=controller;
  const timer=setTimeout(()=>controller.abort(),timeout);
  try {
   const response=await fetch('/retail/showroom/api/voice/'+path,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,
    headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content??''},body:data?JSON.stringify(data):undefined});
   const result=await response.json().catch(()=>({}));
   if([401,403,419].includes(response.status))throw Error('Sign in as the showroom owner, then reopen voice.');
   if(!response.ok&&!(path==='status'&&typeof result.available==='boolean'))throw Error(result.message||'Live voice could not connect. Please try again.');
   return result;
  } finally {clearTimeout(timer);if(abort===controller)abort=null;}
 }
 function transcript(role,text,key,append=false) {
  const log=panel()?.querySelector('#voice-transcript');if(!log||!text)return;
  key=role+':'+(key??++sequence);
  let row=rows.get(key);
  if(!row){row=document.createElement('li');row.className='voice-line '+role;const name=document.createElement('strong');name.textContent=role==='user'?'You':'Everbranch';const body=document.createElement('p');row.append(name,body);log.append(row);rows.set(key,row);}
  const body=row.querySelector('p');body.textContent=((append?body.textContent:'')+text).slice(-4000);
  while(rows.size>24){const first=rows.keys().next().value;rows.get(first).remove();rows.delete(first);}
  panel().querySelector('#voice-transcript-hint').hidden=true;log.scrollTop=log.scrollHeight;
 }
 function stop(message='Conversation ended. Check the connection to start again.',clearTranscript=false) {
  generation++;busy=false;startPending=false;responseActive=false;pendingText=null;abort?.abort();abort=null;clearTimeout(sessionTimer);clearTimeout(connectTimer);clearTimeout(probeTimer);
  if(channel){channel.onopen=channel.onmessage=channel.onclose=channel.onerror=null;channel.close();channel=null;}
  if(peer){peer.ontrack=peer.onconnectionstatechange=peer.oniceconnectionstatechange=null;peer.close();peer=null;}
  microphone?.getTracks().forEach(track=>track.stop());microphone=null;
  if(audio){audio.pause();audio.srcObject=null;audio=null;}
  muted=false;speaking=false;
  if(clearTranscript){rows.clear();panel()?.querySelector('#voice-transcript')?.replaceChildren();}
  const mute=panel()?.querySelector('[data-voice-action="mute"]');if(mute){mute.textContent='Mute microphone';mute.setAttribute('aria-pressed','false');}
  update('ended',message);
 }
 async function check() {
  if(!panel()||peer||busy)return false;
  const id=generation;clearTimeout(probeTimer);
  if(!navigator.onLine){update('offline','Offline. Your saved storefronts and photos still work. Reconnect to use live voice.');return false;}
  if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia||!window.RTCPeerConnection){update('unavailable','Live voice needs a supported browser and a trusted connection. Your other demos remain available.');return false;}
  update('checking','Checking the demo server and live voice connection…');
  try {
   const result=await request('status',null,12000);if(id!==generation||!panel())return false;
   update(result.available?'ready':'unavailable',result.available?'Ready for a live conversation. Your microphone is off.':result.message);
   return !!result.available;
  } catch(error){if(id===generation&&panel())update('offline',error.name==='AbortError'?'The connection is too weak for live voice. Your saved demos still work.':error.message==='Failed to fetch'?'The live connection is unavailable. Your saved demos still work.':error.message);return false;}
  finally {if(id===generation&&panel()&&!peer)probeTimer=setTimeout(check,20000);}
 }
 async function start() {
  if(peer||busy||startPending||!panel())return;
  startPending=true;
  const checked=await check();startPending=false;if(!checked)return;
  const id=++generation;busy=true;clearTimeout(probeTimer);muted=false;speaking=false;
  rows.clear();completedCalls.clear();panel().querySelector('#voice-transcript').replaceChildren();panel().querySelector('#voice-transcript-hint').hidden=false;panel().querySelector('#voice-context-summary').textContent='Loading fictional business context for this business…';
  update('connecting','Connecting. Allow microphone access when your browser asks.');
  try {
   const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
   if(id!==generation||!panel()){stream.getTracks().forEach(track=>track.stop());return;}
   microphone=stream;peer=new RTCPeerConnection();audio=panel().querySelector('#voice-audio');audio.autoplay=true;
   const current=peer;
   current.ontrack=event=>{if(id!==generation)return;audio.srcObject=event.streams[0]??new MediaStream([event.track]);audio.play().catch(()=>{const play=panel()?.querySelector('[data-voice-action="play"]');if(play)play.hidden=false;});};
   current.onconnectionstatechange=()=>{if(id!==generation)return;
    if(current.connectionState==='connected'){busy=false;clearTimeout(connectTimer);update(muted?'muted':'listening',muted?'Microphone muted.':'Listening. Speak naturally; you can interrupt my reply.');}
    if(['disconnected','failed','closed'].includes(current.connectionState))stop('Connection lost. Your microphone is off. Recheck the connection to begin a new conversation.');
   };
   microphone.getTracks().forEach(track=>current.addTrack(track,microphone));
   channel=current.createDataChannel('oai-events');
   channel.onopen=()=>{if(id===generation){responseActive=true;channel.send(JSON.stringify({type:'response.create'}));}};
   channel.onmessage=event=>{if(id!==generation)return;let data;try{data=JSON.parse(event.data);}catch{return;}
    if(data.type==='input_audio_buffer.speech_started'){speaking=false;update(muted?'muted':'listening',muted?'Microphone muted.':'Listening…');}
    if(data.type==='input_audio_buffer.speech_stopped')update(muted?'muted':'thinking',muted?'Microphone muted.':'Thinking about your question…');
    if(data.type==='input_audio_buffer.committed')transcript('user','Transcribing…',data.item_id);
    if(data.type==='response.created'){responseActive=true;if(!speaking)update('thinking','Thinking…');}
    if(data.type==='response.done')responseActive=false;
    if(data.type==='output_audio_buffer.started'){speaking=true;update('speaking','Speaking. You can interrupt, or select Mute microphone.');}
    if(data.type==='output_audio_buffer.stopped'||data.type==='output_audio_buffer.cleared'){speaking=false;update(muted?'muted':'listening',muted?'Microphone muted.':'Listening. What would you like to explore next?');}
    if(['response.output_audio_transcript.delta','response.audio_transcript.delta'].includes(data.type))transcript('assistant',data.delta,data.item_id??data.response_id,true);
    if(['response.output_audio_transcript.done','response.audio_transcript.done'].includes(data.type))transcript('assistant',data.transcript,data.item_id??data.response_id);
    if(data.type==='conversation.item.input_audio_transcription.completed')transcript('user',data.transcript,data.item_id);
    if(data.type==='response.done'&&data.response?.status==='completed'){
     const calls=(data.response.output??[]).filter(item=>item.type==='function_call'&&item.status==='completed');
     for(const item of calls){
      if(completedCalls.has(item.call_id))continue;completedCalls.add(item.call_id);
      let result={ok:false,message:'This display command is unavailable.'};
      try{if(selected&&demoToolNames.includes(item.name)&&String(item.arguments).length<4000){const args=JSON.parse(item.arguments);if(args&&typeof args==='object'&&!Array.isArray(args))result=context().demoTool?.(item.name,args)??result;}}catch{result={ok:false,message:'The demo display request could not be read.'};}
      if(channel?.readyState==='open')channel.send(JSON.stringify({type:'conversation.item.create',item:{type:'function_call_output',call_id:item.call_id,output:JSON.stringify(result)}}));
     }
     if(pendingText){const question=pendingText;pendingText=null;sendText(question);}else if(calls.length&&channel?.readyState==='open'){responseActive=true;channel.send(JSON.stringify({type:'response.create'}));}
    }
    if(data.type==='response.done'&&data.response?.status==='failed'){stop('The voice service could not finish that reply. Your saved demo work is intact. Recheck to continue.');return;}
    if(data.type==='response.done'&&data.response?.status!=='completed'&&pendingText){const question=pendingText;pendingText=null;sendText(question);}
    if(data.type==='error'&&!['response_cancel_not_active','conversation_already_has_active_response'].includes(data.error?.code))stop('Live voice could not continue. Your microphone is off. Recheck the connection and try again.');
   };
   channel.onclose=()=>{if(id===generation)stop('The voice connection ended. Your microphone is off.');};
   channel.onerror=()=>{if(id===generation)stop('The voice connection was interrupted. Your microphone is off.');};
   const offer=await current.createOffer();await current.setLocalDescription(offer);
   const info=context(),input={sdp:offer.sdp,merchant:selected,page:pageAtOpen};
   if(focusedCustomer)input.focus_customer=focusedCustomer;
   const sample=selected?info.voiceSample?.(selected):null;if(sample)input.sample_activity=sample;
   const result=await request('session',input,30000);
   if(id!==generation||!panel())return;
   context().demoInitialize?.(selected,result.demo_records);
   const summary=result.context_summary,node=panel()?.querySelector('#voice-context-summary');if(node&&summary)node.textContent=summary.source==='none'?'Select a business to discuss its sample customers and orders.':`${summary.source==='tablet_sample'?'Tablet sample':'Connected demo'}: ${summary.customers} ${summary.customers===1?'customer':'customers'} · ${summary.orders} recent ${summary.orders===1?'order':'orders'}${summary.focused_customer?' · Discussing '+summary.focused_customer:''}. Ask for fresh screen context after edits.`;
   if(typeof result.sdp!=='string'||!result.sdp.startsWith('v=0'))throw Error('The live audio connection could not be negotiated.');
   await current.setRemoteDescription({type:'answer',sdp:result.sdp});
   sessionTimer=setTimeout(()=>stop('This five-minute demonstration has ended. Your microphone is off.'),Math.min(300,Math.max(1,result.max_duration_seconds??300))*1000);
   connectTimer=setTimeout(()=>{if(id===generation&&current.connectionState!=='connected')stop('Live audio could not connect on this network. Your microphone is off. Recheck the connection.');},20000);
  } catch(error){if(id!==generation)return;const permission=['NotAllowedError','PermissionDeniedError'].includes(error.name);stop(permission?'Microphone permission is needed. Allow it in the browser, then check the connection again.':error.name==='NotFoundError'?'No microphone was found on this device.':error.name==='AbortError'?'The connection timed out. Your microphone is off. Recheck and try again.':error.message||'Live voice could not connect.');}
 }
 function open({customer=null}={}) {
  const info=context(),vendors=info.pack?.vendors??[];selected=info.merchant?.slug??null;focusedCustomer=customer??(selected==='avery-knifeworks'?info.averyContext?.().focused_customer?.id:null);pageAtOpen=selected==='avery-knifeworks'?(info.averyContext?.().view??'workspace'):customer?'customers':info.modal==='staff'&&['customers','rewards','team','orders','inventory'].includes(info.staffTab)?info.staffTab:['pricing','assistant'].includes(info.view)?info.view:selected?'store':'home';
  openModal('Talk with Everbranch',`<div id="retail-voice" data-state="checking"><div class="voice-intro"><span class="eyebrow">LIVE VOICE CONVERSATION</span><p>Talk naturally about the business you’re exploring. Ask follow-up questions and interrupt a reply whenever you need.</p></div><div class="voice-layout"><section class="voice-controls"><label>Business context<select id="voice-merchant"><option value="">Everbranch showroom</option>${vendors.map(v=>`<option value="${esc(v.slug)}" ${v.slug===selected?'selected':''}>${esc(v.name)}</option>`).join('')}</select></label><div class="voice-orb" aria-hidden="true">✦</div><p id="voice-status" role="status" aria-live="polite">Checking the live connection…</p><div class="voice-buttons"><button class="button" data-voice-action="start" disabled>Start conversation</button><button class="button voice-end" data-voice-action="end" disabled>End conversation</button><button class="button light" data-voice-action="mute" aria-pressed="false" disabled>Mute microphone</button><button class="text-button" data-voice-action="check">Recheck connection</button></div><button class="text-button" data-sales-action="typed-voice">Type a question or open a page</button><p class="compact-info voice-disclosure">This is an AI-generated voice. While connected, microphone audio goes to OpenAI with this business’s sample products, orders, customers, rewards and staff notes. End, close, or leave this screen to turn the microphone off. Five-minute demonstration limit.</p><audio id="voice-audio" autoplay playsinline></audio><button class="button light" data-voice-action="play" hidden>Play voice reply</button></section><section class="voice-conversation" aria-label="Conversation transcript"><span class="eyebrow">YOUR CONVERSATION</span><p id="voice-context-summary" class="compact-info">Fictional records load when you start. Voice can open screens, fill forms, add demo customers and create draft demo invoices. Ask about products, customer purchases, rewards or sample staff notes.</p><p id="voice-transcript-hint" class="muted">The conversation appears here as you speak. It clears when this screen closes.</p><ol id="voice-transcript" role="log" aria-live="off"></ol><form id="voice-live-query"><label>Ask while connected<input name="question" maxlength="1000" placeholder="Open Bob Ross, add a customer, or create a demo invoice" autocomplete="off"></label><button class="button light" type="submit">Ask in this conversation</button></form><p class="compact-info">Uses fictional customer and order records for the selected business. Real customer records, private prospect notes and web search remain excluded.</p></section></div>${selected?'<section id="voice-demo-canvas" class="voice-demo-canvas" aria-label="Voice-controlled fictional workspace"></section>':''}</div>`,true);
  info.demoSelect?.(selected);
  if(selected)info.demoTool?.('show_demo_view',{view:pageAtOpen==='customers'?'customers':'workspace'});
  check();
 }
 document.addEventListener('click',event=>{const action=event.target.closest('[data-voice-action]')?.dataset.voiceAction;if(!action||!panel())return;event.preventDefault();
  if(action==='start')start();
  if(action==='end')stop();
  if(action==='check')check();
  if(action==='play')audio?.play().then(()=>{const button=panel()?.querySelector('[data-voice-action="play"]');if(button)button.hidden=true;}).catch(()=>{});
  if(action==='mute'&&microphone){muted=!muted;microphone.getAudioTracks().forEach(track=>track.enabled=!muted);const button=panel().querySelector('[data-voice-action="mute"]');button.textContent=muted?'Unmute microphone':'Mute microphone';button.setAttribute('aria-pressed',String(muted));update(muted?'muted':speaking?'speaking':'listening',muted?'Microphone muted. You can still hear replies.':speaking?'Speaking. You can interrupt my reply.':'Listening…');}
 });
 function sendText(question){
  if(channel?.readyState!=='open')return;
  if(speaking){channel.send(JSON.stringify({type:'output_audio_buffer.clear'}));speaking=false;}
  if(responseActive){pendingText=question;return;}
  channel.send(JSON.stringify({type:'conversation.item.create',item:{type:'message',role:'user',content:[{type:'input_text',text:question}]}}));
  responseActive=true;channel.send(JSON.stringify({type:'response.create'}));
 }
 document.addEventListener('submit',event=>{if(event.target.id!=='voice-live-query')return;event.preventDefault();const form=event.target,question=String(new FormData(form).get('question')??'').trim();if(!question||channel?.readyState!=='open'){const status=panel()?.querySelector('#voice-status');if(status)status.textContent='Start a live conversation first, then speak or type your question.';return;}transcript('user',question);form.reset();sendText(question.slice(0,1000));});
 document.addEventListener('change',event=>{if(event.target.id==='voice-merchant'){stop('Business context changed. Recheck to start a fresh conversation.',true);panel().querySelector('#voice-context-summary').textContent='Read-only records load when you start a new conversation.';selected=event.target.value||null;context().demoSelect?.(selected);if(selected)context().demoTool?.('show_demo_view',{view:'workspace'});focusedCustomer=null;pageAtOpen=selected?'store':'home';check();}});
 window.addEventListener('offline',()=>{if(panel())stop('Internet connection lost. Your microphone is off. Saved storefronts and photos remain available.');});
 window.addEventListener('online',()=>{if(panel()&&!peer)check();});
 window.addEventListener('pagehide',()=>stop('Conversation ended.',true));
 document.addEventListener('visibilitychange',()=>{if(document.hidden&&panel())stop('Conversation paused because the app left the screen. Your microphone is off.',true);});
 return {open,clear:()=>stop('Conversation ended.',true)};
}
