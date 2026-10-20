import '@google/model-viewer';
import './viewer.css';
import {validConfig} from './pose.js';

const runtime=new URL('runtime/',new URL('.',import.meta.url)).href;
const escapeError=error=>error?.name==='NotAllowedError'?'Camera access was declined. Allow it in browser or app settings, then retry.':error?.name==='NotFoundError'?'No camera was found on this device.':error?.message||'The preview could not start. Try the 3D view instead.';

export class ProductViewer extends HTMLElement{
  connectedCallback(){
    if(this.initialized){document.addEventListener('visibilitychange',this.visibility);window.addEventListener('pagehide',this.pagehide);return;}
    let config;try{config=JSON.parse(this.getAttribute('data-config'));}catch{}
    if(!validConfig(config)){this.textContent='Product preview is not configured.';return;}
    this.initialized=true;this.config=config;this.session=0;this.modelGeneration=0;this.urls=[];this.recording=false;
    this.innerHTML=`<button class="pv-launch" type="button">Explore product</button>
      <dialog class="pv-dialog" aria-label="Product visualization"><header class="pv-header"><strong></strong><button type="button" data-action="close" aria-label="Close product preview">Close</button></header>
      <section class="pv-body"><div class="pv-choices"><label>Option <select aria-label="Product option"></select></label></div>
      <div class="pv-stage"><model-viewer camera-controls touch-action="pan-y" shadow-intensity="1" ar ar-modes="webxr scene-viewer quick-look" ar-placement="floor" ar-scale="fixed" exposure="1" loading="eager"><button slot="ar-button" class="pv-room-button pv-primary" type="button">Place in your room</button></model-viewer><video muted playsinline hidden></video><canvas hidden aria-label="Live in-hand preview"></canvas></div>
      <p class="pv-status" role="status" aria-live="polite"></p><p class="pv-help">Drag to rotate. Pinch or scroll to zoom. Use two fingers to move the 3D view.</p>
      <div class="pv-actions"><button type="button" data-action="camera" class="pv-primary">Try it in your hand</button><button type="button" data-action="stop" hidden>Return to 3D</button><button type="button" data-action="switch" hidden>Switch camera</button><button type="button" data-action="photo" hidden>Save image</button><button type="button" data-action="record" hidden>Record clip</button><button type="button" data-action="room-native" hidden>Open room placement</button></div>
      <div class="pv-controls" hidden><label>Palm width: <output data-output="palm">80</output> mm<input aria-label="Palm width in millimeters" data-setting="palm" type="range" min="40" max="140" value="80"></label><label>Preview size<input aria-label="Preview size" data-setting="scale" type="range" min="50" max="150" value="100"></label><label>Tilt<input aria-label="Tilt" data-setting="pitch" type="range" min="-180" max="180" value="0"></label><label>Turn<input aria-label="Turn" data-setting="yaw" type="range" min="-180" max="180" value="0"></label><label>Roll<input aria-label="Roll" data-setting="roll" type="range" min="-180" max="180" value="0"></label><label><input data-setting="mask" type="checkbox" checked> Approximate finger masking</label><label><button type="button" data-action="reset">Reset adjustments</button></label></div>
      <p class="pv-note"></p><div class="pv-files"></div></section></dialog>`;
    this.dialog=this.querySelector('dialog');this.stage=this.querySelector('.pv-stage');this.mv=this.querySelector('model-viewer');this.video=this.querySelector('video');this.canvas=this.querySelector('canvas');this.status=this.querySelector('.pv-status');
    this.querySelector('.pv-header strong').textContent=config.title;
    this.mv.alt=config.title;
    this.querySelector('.pv-launch').textContent=config.placement==='hand'?'Try it in your hand':'View in 3D / your room';
    this.querySelector('.pv-note').textContent=config.placement==='hand'?'Camera frames stay on this device. Hand scale and finger masking are approximate; this preview cannot assess grip comfort. Measure palm width across the knuckles for a closer visual scale.':'Room placement uses the saved product dimensions. Confirm measurements before ordering; AR accuracy varies by device and surface.';
    const select=this.querySelector('select');
    const variants=config.variants?.length?config.variants:[{id:'default',label:'Default',modelUrl:config.modelUrl}];
    for(const v of variants){const option=document.createElement('option');option.value=String(v.id);option.textContent=v.label;select.append(option);}
    select.addEventListener('change',()=>{const v=variants.find(v=>String(v.id)===select.value);if(v)this.loadModel(v.modelUrl);});
    this.currentUrl=variants[0].modelUrl;
    this.querySelector('.pv-launch').addEventListener('click',()=>this.open());
    this.querySelector('[data-action=close]').addEventListener('click',()=>this.dialog.close());
    this.dialog.addEventListener('close',()=>{this.stop();this.querySelector('.pv-launch').focus();});
    this.dialog.addEventListener('cancel',()=>this.stop());
    this.querySelector('[data-action=camera]').hidden=config.placement!=='hand';
    if(config.placement!=='room'||this.hasAttribute('native'))this.mv.removeAttribute('ar');
    this.querySelector('[data-action=camera]').addEventListener('click',()=>this.start());
    this.querySelector('[data-action=stop]').addEventListener('click',()=>this.stop());
    this.querySelector('[data-action=switch]').addEventListener('click',()=>{this.front=!this.front;this.start();});
    this.querySelector('[data-action=photo]').addEventListener('click',()=>this.photo());
    this.querySelector('[data-action=record]').addEventListener('click',()=>this.record());
    this.querySelector('[data-action=reset]').addEventListener('click',()=>this.reset());
    this.querySelectorAll('[data-setting]').forEach(el=>el.addEventListener('input',()=>this.settings()));
    this.querySelector('[data-action=room-native]').hidden=config.placement!=='room'||!this.hasAttribute('native');
    this.querySelector('[data-action=room-native]').addEventListener('click',()=>Promise.resolve(this.openRoomAR?.()).catch(()=>{this.status.textContent='Room placement could not open. Try the public product page in your phone browser.';}));
    this.mv.addEventListener('load',()=>{
      this.modelReady=true;const dim=this.mv.getDimensions();const max=Math.max(dim.x,dim.y,dim.z);
      if(Number.isFinite(max)&&max>0){const scale=this.config.lengthMm/1000/max;this.mv.scale=`${scale} ${scale} ${scale}`;}
      this.status.textContent='Drag to explore your product.';
    });
    this.mv.addEventListener('error',()=>{this.modelReady=false;this.status.textContent='The product model could not load. Please try again later.';this.stop(false);});
    this.mv.addEventListener('ar-status',event=>{if(event.detail.status==='failed')this.status.textContent='Room AR is unavailable here. Try Safari on iPhone or Chrome on a compatible Android phone. The 3D viewer is still available.';});
    this.visibility=()=>{if(document.hidden)this.stop();};document.addEventListener('visibilitychange',this.visibility);
    this.pagehide=()=>this.stop();window.addEventListener('pagehide',this.pagehide);
    this.stage.addEventListener('pointerdown',e=>{if(!this.hand)return;this.drag={id:e.pointerId,x:e.clientX,y:e.clientY};this.stage.setPointerCapture(e.pointerId);});
    this.stage.addEventListener('pointermove',e=>{if(!this.drag||e.pointerId!==this.drag.id||!this.hand)return;const mirror=this.front?-1:1;this.hand.offset.x+=(e.clientX-this.drag.x)*mirror;this.hand.offset.y-=e.clientY-this.drag.y;this.drag.x=e.clientX;this.drag.y=e.clientY;});
    for(const name of ['pointerup','pointercancel','lostpointercapture'])this.stage.addEventListener(name,()=>{this.drag=null;});
  }
  disconnectedCallback(){this.stop();document.removeEventListener('visibilitychange',this.visibility);window.removeEventListener('pagehide',this.pagehide);this.urls?.forEach(u=>URL.revokeObjectURL(u));this.urls=[];}
  open(){if(!this.dialog.open)this.dialog.showModal();this.loadModel(this.currentUrl);}
  loadModel(url){
    this.currentUrl=url;this.modelReady=false;this.mv.src=url;this.mv.scale='1 1 1';
    this.status.textContent='Loading product…';
    if(this.hand){const generation=++this.modelGeneration;this.hand.load(url).then(()=>{if(this.hand&&generation===this.modelGeneration)this.status.textContent='Show one hand clearly to preview the product.';}).catch(error=>{if(generation===this.modelGeneration){this.status.textContent=escapeError(error);this.stop(false);}});}
  }
  settings(){if(!this.hand)return;const get=s=>Number(this.querySelector(`[data-setting=${s}]`).value);this.hand.palmMm=get('palm');this.querySelector('[data-output=palm]').textContent=String(get('palm'));this.hand.zoom=get('scale')/100;this.hand.userRotation=[get('pitch'),get('yaw'),get('roll')];this.hand.masking=this.querySelector('[data-setting=mask]').checked;}
  reset(){for(const [key,value]of Object.entries({palm:80,scale:100,pitch:0,yaw:0,roll:0}))this.querySelector(`[data-setting=${key}]`).value=String(value);if(this.hand)this.hand.offset={x:0,y:0};this.settings();}
  async start(){
    this.stop(false);const session=this.session;this.status.textContent='Starting camera and hand tracking…';
    const button=this.querySelector('[data-action=camera]');button.disabled=true;
    try{
      if(!this.modelReady)throw new Error('Wait for the complete product model to load before opening the camera.');
      await this.beforeCamera?.();
      if(session!==this.session)return;
      if(!isSecureContext||!navigator.mediaDevices?.getUserMedia)throw new Error('Camera preview needs HTTPS and a browser or app with camera permission.');
      const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:this.front?'user':'environment'},width:{ideal:1280},height:{ideal:720}},audio:false});
      if(session!==this.session){stream.getTracks().forEach(t=>t.stop());return;}
      this.stream=stream;this.video.srcObject=stream;await this.video.play();
      if(session!==this.session)return;
      const {HandScene}=await import('./hand-scene.js');if(session!==this.session)return;
      this.hand=new HandScene(this.canvas,this.config);await this.hand.load(this.currentUrl);if(session!==this.session)return;
      const worker=new Worker(new URL('./hand-worker.js',import.meta.url));this.worker=worker;
      await new Promise((resolve,reject)=>{
        this.workerTimer=setTimeout(()=>reject(new Error('Hand tracking took too long to load. Check your connection and retry.')),20000);
        worker.onerror=()=>reject(new Error('The hand tracker could not load on this device.'));
        worker.onmessage=({data})=>{if(data.type==='ready'){clearTimeout(this.workerTimer);resolve();}else if(data.type==='error'){clearTimeout(this.workerTimer);reject(new Error(data.message));}};
        worker.postMessage({type:'init',wasm:runtime+'wasm/',model:runtime+'hand_landmarker.task'});
      });
      if(session!==this.session)return;
      worker.onerror=()=>{if(session===this.session){this.status.textContent='The hand tracker stopped. You can retry or use the 3D view.';this.stop(false);}};
      this.canvas.hidden=false;this.video.hidden=false;this.mv.hidden=true;this.stage.classList.toggle('pv-mirrored',!!this.front);
      this.querySelector('.pv-controls').hidden=false;
      for(const name of ['stop','switch','photo','record'])this.querySelector(`[data-action=${name}]`).hidden=false;
      button.hidden=true;this.settings();
      this.querySelector('.pv-help').textContent='Show one hand clearly. Drag to adjust placement; use the controls to adjust scale and orientation.';
      this.status.textContent='Show one hand clearly to preview the product.';
      worker.onmessage=({data})=>{
        if(session!==this.session||!this.hand)return;
        this.inFlight=false;
        if(data.type==='error'){this.status.textContent=data.message;this.stop(false);return;}
        this.hand.update(data.landmarks,this.video);
        const tracked=!!data.landmarks;if(this.tracked!==tracked){this.status.textContent=tracked?'Hand found · visual scale is approximate.':'Hand lost · show one hand clearly.';this.tracked=tracked;}
      };
      this.lastFrame=-1;this.lastInference=0;this.inFlight=false;
      this.renderLoop(session);
    }catch(error){if(session===this.session){this.status.textContent=escapeError(error);this.stop(false);}}
    finally{if(session===this.session)button.disabled=false;}
  }
  renderLoop(session){
    const run=async timestamp=>{
      if(session!==this.session||!this.hand)return;
      const box=this.stage.getBoundingClientRect();
      if(box.width!==this.hand.width||box.height!==this.hand.height)this.hand.resize(box.width,box.height);
      this.hand.render();if(this.recording)this.compose();
      if(!this.inFlight&&timestamp-this.lastInference>65&&this.video.currentTime!==this.lastFrame&&this.video.readyState>=2){
        this.inFlight=true;this.lastFrame=this.video.currentTime;this.lastInference=timestamp;
        try{const bitmap=await createImageBitmap(this.video);if(session!==this.session){bitmap.close();return;}this.worker.postMessage({type:'frame',bitmap,timestamp},[bitmap]);}
        catch{this.inFlight=false;}
      }
      if(session===this.session)this.raf=requestAnimationFrame(run);
    };this.raf=requestAnimationFrame(run);
  }
  stop(message=true){
    ++this.session;++this.modelGeneration;cancelAnimationFrame(this.raf);clearTimeout(this.workerTimer);
    this.worker?.terminate();this.worker=null;this.stream?.getTracks().forEach(t=>t.stop());this.stream=null;
    this.video?.pause();if(this.video)this.video.srcObject=null;
    this.hand?.close();this.hand=null;this.drag=null;this.tracked=null;
    if(this.recorder?.state==='recording')this.recorder.stop();this.recording=false;clearTimeout(this.recordTimer);
    if(!this.initialized)return;
    this.video.hidden=true;this.canvas.hidden=true;this.mv.hidden=false;this.stage.classList.remove('pv-mirrored');this.querySelector('.pv-controls').hidden=true;
    for(const name of ['stop','switch','photo','record'])this.querySelector(`[data-action=${name}]`).hidden=true;
    const button=this.querySelector('[data-action=camera]');button.hidden=this.config.placement!=='hand';button.disabled=false;
    this.querySelector('[data-action=record]').textContent='Record clip';this.querySelector('.pv-help').textContent='Drag to rotate. Pinch or scroll to zoom. Use two fingers to move the 3D view.';
    if(message)this.status.textContent='Camera stopped. You can continue exploring in 3D.';
  }
  compose(){
    if(!this.hand)return null;
    this.capture??=document.createElement('canvas');const w=Math.round(this.hand.width),h=Math.round(this.hand.height);
    if(this.capture.width!==w)this.capture.width=w;if(this.capture.height!==h)this.capture.height=h;
    const ctx=this.capture.getContext('2d');ctx.save();ctx.clearRect(0,0,w,h);if(this.front){ctx.translate(w,0);ctx.scale(-1,1);}
    const scale=Math.max(w/this.video.videoWidth,h/this.video.videoHeight),vw=this.video.videoWidth*scale,vh=this.video.videoHeight*scale;
    ctx.drawImage(this.video,(w-vw)/2,(h-vh)/2,vw,vh);ctx.drawImage(this.canvas,0,0,w,h);ctx.restore();
    ctx.fillStyle='rgba(24,47,35,.85)';ctx.fillRect(0,h-36,w,36);ctx.fillStyle='white';ctx.font='14px system-ui';ctx.fillText('Everbranch · visual preview · approximate scale',12,h-13);
    return this.capture;
  }
  file(blob,name){if(!blob||!this.isConnected)return;if(this.savePreview){Promise.resolve(this.savePreview(blob,name)).catch(()=>{this.status.textContent='The phone could not share this file. Use a screen recording instead.';});return;}const url=URL.createObjectURL(blob);this.urls.push(url);const a=document.createElement('a');a.href=url;a.download=name;a.textContent='Save '+name;a.className='pv-download';this.querySelector('.pv-files').replaceChildren(a);this.status.textContent='Your preview is ready to save. It has not been uploaded.';}
  photo(){try{this.compose()?.toBlob(blob=>this.file(blob,'everbranch-product-preview.png'),'image/png');}catch{this.status.textContent='This browser could not save an image.';}}
  record(){
    if(this.recorder?.state==='recording'){this.recorder.stop();return;}
    const canvas=this.compose();if(!canvas?.captureStream||!window.MediaRecorder){this.status.textContent='Video export is unavailable here. Use Save image or your phone’s screen recording.';return;}
    const type=['video/mp4;codecs=avc1.42E01E','video/mp4','video/webm;codecs=vp8','video/webm'].find(t=>MediaRecorder.isTypeSupported(t));
    if(!type){this.status.textContent='This browser has no supported video encoder. Use your phone’s screen recording.';return;}
    try{
      const stream=canvas.captureStream(24);this.recorder=new MediaRecorder(stream,{mimeType:type});const parts=[];
      this.recorder.ondataavailable=e=>{if(e.data.size)parts.push(e.data);};
      this.recorder.onstop=()=>{stream.getTracks().forEach(t=>t.stop());this.recording=false;clearTimeout(this.recordTimer);this.querySelector('[data-action=record]').textContent='Record clip';this.file(new Blob(parts,{type}),`everbranch-product-preview.${type.startsWith('video/mp4')?'mp4':'webm'}`);};
      this.recorder.onerror=()=>{stream.getTracks().forEach(t=>t.stop());this.recording=false;this.status.textContent='Video export failed. Use Save image or your phone’s screen recording.';};
      this.recorder.start();this.recording=true;this.querySelector('[data-action=record]').textContent='Stop recording';this.status.textContent='Recording this preview locally (up to 20 seconds).';this.recordTimer=setTimeout(()=>{if(this.recorder?.state==='recording')this.recorder.stop();},20000);
    }catch{this.status.textContent='Video export could not start on this device.';}
  }
}
if(!customElements.get('everbranch-product-viewer'))customElements.define('everbranch-product-viewer',ProductViewer);
