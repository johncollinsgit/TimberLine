export function pcmWave(samples, sampleRate=24000){
  const out=new ArrayBuffer(44+samples.length*2),v=new DataView(out);
  const text=(at,value)=>[...value].forEach((c,i)=>v.setUint8(at+i,c.charCodeAt(0)));
  text(0,'RIFF');v.setUint32(4,out.byteLength-8,true);text(8,'WAVE');text(12,'fmt ');v.setUint32(16,16,true);v.setUint16(20,1,true);v.setUint16(22,1,true);v.setUint32(24,sampleRate,true);v.setUint32(28,sampleRate*2,true);v.setUint16(32,2,true);v.setUint16(34,16,true);text(36,'data');v.setUint32(40,samples.length*2,true);
  samples.forEach((n,i)=>v.setInt16(44+i*2,Math.round(Math.max(-1,Math.min(1,n))*(n<0?32768:32767)),true));
  return out;
}

export function createGeraldVoice({record,transcribe,ask,speak,confirm,getProposal,onState,onTranscript,onError}){
  let epoch=0,active=false,controller=null;
  const stop=()=>{epoch++;active=false;controller?.abort();controller=null;onState('idle');};
  const run=async turn=>{
    while(active&&epoch===turn){
      controller=new AbortController();
      try{
        onState('listening');const audio=await record(controller.signal);
        if(!active||epoch!==turn)return;
        onState('transcribing');const proposal=getProposal();const heard=await transcribe(audio,proposal?.id);
        if(!active||epoch!==turn)return;
        onTranscript(heard.transcript);
        if(proposal&&/^gerald[,\.]?\s+save this plan[.!]?$/i.test(heard.transcript.trim())){
          onState('saving');await confirm(proposal.id,heard.receipt);
          if(active&&epoch===turn)stop();
          return;
        }
        onState('thinking');const answer=await ask(heard.transcript);
        if(!active||epoch!==turn)return;
        onState('speaking');await speak(answer,controller.signal);
        if(!active||epoch!==turn)return;
      }catch(error){if(active&&epoch===turn){stop();onError(error.message||'Voice stopped. You can continue by typing.');}return;}
    }
  };
  return {start(){stop();active=true;void run(epoch);},stop,isActive:()=>active};
}

export async function recordMicrophone(signal){
  if(!navigator.mediaDevices?.getUserMedia||!globalThis.MediaRecorder)throw new Error('Microphone recording is unavailable here. You can type to Gerald.');
  const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true}});
  if(signal.aborted){stream.getTracks().forEach(t=>t.stop());throw new Error('Recording stopped.');}
  const AudioContext=globalThis.AudioContext||globalThis.webkitAudioContext;
  const context=new AudioContext(),analyser=context.createAnalyser(),source=context.createMediaStreamSource(stream);source.connect(analyser);analyser.fftSize=512;
  const recorder=new MediaRecorder(stream),chunks=[];let timer,frame,heard=false,lastSound=Date.now();
  try{
    const blob=await new Promise((resolve,reject)=>{
      const finish=()=>{if(recorder.state==='recording')recorder.stop();};
      signal.addEventListener('abort',()=>{finish();reject(new Error('Recording stopped.'));},{once:true});
      recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data);};recorder.onerror=()=>reject(new Error('Microphone recording failed. You can type instead.'));
      recorder.onstop=()=>resolve(new Blob(chunks,{type:recorder.mimeType}));recorder.start();timer=setTimeout(finish,29500);
      const samples=new Float32Array(analyser.fftSize),started=Date.now();
      const watch=()=>{if(recorder.state!=='recording')return;analyser.getFloatTimeDomainData(samples);const rms=Math.sqrt(samples.reduce((sum,x)=>sum+x*x,0)/samples.length);if(rms>.015){heard=true;lastSound=Date.now();}if((heard&&Date.now()-lastSound>1100)||(!heard&&Date.now()-started>12000)){finish();return;}frame=requestAnimationFrame(watch);};watch();
    });
    if(signal.aborted)throw new Error('Recording stopped.');
    if(!heard)throw new Error('No speech was captured. Start again or type to Gerald.');
    const decoded=await context.decodeAudioData(await blob.arrayBuffer());
    const frames=Math.min(720000,Math.ceil(decoded.duration*24000));
    const offline=new OfflineAudioContext(1,frames,24000),input=offline.createBufferSource();input.buffer=decoded;input.connect(offline.destination);input.start();const rendered=await offline.startRendering();
    return new Blob([pcmWave(rendered.getChannelData(0))],{type:'audio/wav'});
  }finally{clearTimeout(timer);cancelAnimationFrame(frame);stream.getTracks().forEach(t=>t.stop());source.disconnect();await context.close();}
}
