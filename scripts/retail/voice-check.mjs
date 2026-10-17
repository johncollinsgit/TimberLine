import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const base='http://127.0.0.1:8797',out='output/retail/voice';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch();
const context=await browser.newContext({viewport:{width:1280,height:800},serviceWorkers:'block'});
const page=await context.newPage(),errors=[],checks=[],sessions=[];
page.on('pageerror',e=>errors.push(e.message));
await page.addInitScript(()=>{
 const qa=window.__voiceQA={tracks:[],peers:[],channels:[],permission:'allow',defer:false,expiry:false};
 const set=window.setTimeout.bind(window);window.setTimeout=(fn,delay,...args)=>set(fn,qa.expiry&&delay===300000?100:delay,...args);
 navigator.mediaDevices.getUserMedia=async()=>{
  if(qa.permission==='deny')throw Object.assign(Error('Permission denied'),{name:'NotAllowedError'});
  const track={enabled:true,stopped:false,stop(){this.stopped=true;}};qa.tracks.push(track);
  const stream={getTracks:()=>[track],getAudioTracks:()=>[track]};
  if(qa.defer)return new Promise(resolve=>qa.resolveMic=()=>resolve(stream));
  return stream;
 };
 window.RTCPeerConnection=class {
  constructor(){this.connectionState='new';qa.peers.push(this);}
  addTrack(){}
  createDataChannel(){const channel={sent:[],send(value){this.sent.push(JSON.parse(value));},close(){this.closed=true;}};qa.channels.push(channel);return channel;}
  async createOffer(){return {type:'offer',sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n'};}
  async setLocalDescription(){}
  async setRemoteDescription(){this.connectionState='connected';this.onconnectionstatechange?.();qa.channels.at(-1).onopen?.();}
  close(){this.connectionState='closed';this.closed=true;}
 };
});
let status={available:true,message:'Ready',internet:true,model_ready:true},statusCode=200;
await page.route('**/retail/showroom/api/voice/status',r=>r.fulfill({status:statusCode,json:status}));
await page.route('**/retail/showroom/api/voice/session',async r=>{sessions.push(r.request().postDataJSON());await r.fulfill({json:{sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n',max_duration_seconds:300}});});
const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json','utf8'));
try {
 await page.goto(base+'/login');await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>!u.pathname.endsWith('/login'));
 await page.goto(base+'/retail/showroom/concepts/joy-fudge-company');await page.waitForSelector('.product-card');
 const open=async()=>{await page.locator('[data-sales-action=voice]').first().click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');};
 const start=async()=>{await page.locator('[data-voice-action=start]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','listening');};
 const emit=async data=>page.evaluate(data=>window.__voiceQA.channels.at(-1).onmessage({data:JSON.stringify(data)}),data);
 const stopped=async()=>assert.ok(await page.evaluate(()=>window.__voiceQA.tracks.every(t=>t.stopped)&&window.__voiceQA.peers.every(p=>p.closed)));
 await open();assert.equal(await page.locator('#voice-merchant').inputValue(),'joy-fudge-company');assert.equal(await page.evaluate(()=>window.__voiceQA.tracks.length),0);
 checks.push('Opening voice checks readiness without opening the microphone; selected merchant is retained');
 await start();assert.deepEqual(sessions[0],{sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n',merchant:'joy-fudge-company',page:'store'});
 assert.equal(await page.locator('#voice-merchant').isDisabled(),true);
 await emit({type:'input_audio_buffer.committed',item_id:'one'});
 await emit({type:'conversation.item.input_audio_transcription.completed',item_id:'one',transcript:'Which flavors are available?'});
 await emit({type:'response.output_audio_transcript.delta',item_id:'reply',delta:'Try the sample '});
 await emit({type:'response.output_audio_transcript.delta',item_id:'reply',delta:'fudge assortment.'});
 await emit({type:'output_audio_buffer.started'});await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','speaking');
 await emit({type:'input_audio_buffer.speech_started'});await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','listening');
 await emit({type:'response.output_audio_transcript.done',item_id:'reply',transcript:'Try the sample fudge assortment.'});
 await emit({type:'input_audio_buffer.committed',item_id:'two'});
 await emit({type:'conversation.item.input_audio_transcription.completed',item_id:'two',transcript:'<img src=x onerror=alert(1)> Follow-up question'});
 assert.equal(await page.locator('#voice-transcript li').count(),3);assert.equal(await page.locator('#voice-transcript img').count(),0);
 assert.match(await page.locator('#voice-transcript').innerText(),/sample fudge assortment/);
 await page.locator('[data-voice-action=mute]').click();assert.equal(await page.evaluate(()=>window.__voiceQA.tracks.at(-1).enabled),false);
 await page.locator('[data-voice-action=mute]').click();assert.equal(await page.evaluate(()=>window.__voiceQA.tracks.at(-1).enabled),true);
 checks.push('Audio SDP uses the selected context; turns, interruption and escaped transcripts render; mute disables the audio track');
 await page.screenshot({path:out+'/conversation-landscape.png'});
 await page.setViewportSize({width:800,height:1280});await page.screenshot({path:out+'/conversation-portrait.png'});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await page.locator('[data-voice-action=end]').click();await stopped();assert.equal(await page.locator('#voice-transcript li').count(),3);
 await page.locator('#voice-merchant').selectOption('mcneills-pottery');await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');assert.equal(await page.locator('#voice-transcript li').count(),0);await start();assert.equal(sessions.at(-1).merchant,'mcneills-pottery');
 await context.setOffline(true);await expect(page.locator('#retail-voice')).toHaveCount(0);await stopped();await page.locator('[data-sales-action=voice]').first().click();await expect(page.locator('#voice-status')).toContainText('Offline.');
 await context.setOffline(false);await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');assert.equal(await page.evaluate(()=>window.__voiceQA.tracks.filter(t=>!t.stopped).length),0);
 checks.push('End and business switching clean up audio; network loss ends the call and reconnection never restarts the microphone');
 await page.locator('[data-action=close]').first().click();
 status={available:false,internet:false,message:'Voice cannot reach the internet.'};statusCode=503;
 await page.locator('[data-sales-action=voice]').first().click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','unavailable');assert.equal(await page.locator('[data-voice-action=start]').isDisabled(),true);
 checks.push('Server/provider connection failure disables Start with an honest unavailable reason');
 status={available:true,internet:true,message:'Ready'};statusCode=200;await page.locator('[data-voice-action=check]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');
 await page.evaluate(()=>window.__voiceQA.permission='deny');await page.locator('[data-voice-action=start]').click();await expect(page.locator('#voice-status')).toContainText('Microphone permission is needed');await stopped();
 await page.evaluate(()=>{window.__voiceQA.permission='allow';window.__voiceQA.defer=true;});await page.locator('[data-voice-action=check]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');await page.locator('[data-voice-action=start]').click();await expect.poll(()=>page.evaluate(()=>!!window.__voiceQA.resolveMic)).toBe(true);
 await page.locator('[data-action=close]').first().click();await page.evaluate(()=>window.__voiceQA.resolveMic());await stopped();
 checks.push('Denied microphone access is recoverable; permission resolving after close cannot leave a microphone open');
 await page.evaluate(()=>window.__voiceQA.defer=false);await open();await start();await page.locator('[data-action=close]').first().click();await stopped();
 await open();await start();await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});await stopped();assert.equal(await page.locator('#voice-transcript li').count(),0);await page.evaluate(()=>delete document.hidden);
 checks.push('Closing an active call or backgrounding the app stops audio and clears the conversation');
 await page.locator('[data-voice-action=check]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');await start();await page.evaluate(()=>{const peer=window.__voiceQA.peers.at(-1);peer.connectionState='failed';peer.onconnectionstatechange();});await expect(page.locator('#voice-status')).toContainText('Connection lost');await stopped();
 checks.push('A failed WebRTC transport stops the microphone and requires an explicit restart');
 await page.locator('[data-action=close]').first().click();await page.evaluate(()=>window.__voiceQA.expiry=true);await open();await page.locator('[data-voice-action=start]').click();await expect(page.locator('#voice-status')).toContainText('five-minute demonstration has ended');await stopped();
 checks.push('Five-minute session limit ends all audio tracks and peer connections');
 await page.locator('[data-action=close]').first().click();statusCode=401;await page.locator('[data-sales-action=voice]').first().click();await expect(page.locator('#voice-status')).toContainText('Sign in as the showroom owner');assert.equal(await page.locator('[data-voice-action=start]').isDisabled(),true);
 statusCode=200;await page.locator('[data-action=close]').first().click();await open();await page.locator('[data-sales-action=typed-voice]').click();await page.locator('#assistant-query').fill('Open pricing');await page.locator('#retail-assistant-form button[type=submit]').click();await page.waitForSelector('.launch-price-grid');
 assert.ok(await page.evaluate(()=>!JSON.stringify(localStorage).includes('Follow-up question')));assert.deepEqual(errors,[]);
 checks.push('Expired owner authentication fails closed; typed navigation remains available; transcripts never enter local storage');
 fs.writeFileSync(out+'/browser-results.json',JSON.stringify({passed:true,checks,errors,provider:'mocked; lifecycle regression only'},null,2));console.log(JSON.stringify({passed:true,checks,errors},null,2));
} finally {await browser.close();}
