import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';import assert from 'node:assert/strict';import crypto from 'node:crypto';
if(process.env.RETAIL_QA_GALAXY_VOICE!=='1')throw Error('Set RETAIL_QA_GALAXY_VOICE=1 to connect the Galaxy and start one paid session.');
const root=process.cwd(),out=root+'/output/retail/voice',base='http://127.0.0.1:8797';fs.mkdirSync(out,{recursive:true});
const b=await chromium.connectOverCDP('http://127.0.0.1:9224'),c=b.contexts()[0],p=c.pages().find(p=>p.url().includes('/retail/'));
let passed=false;
try {
 const dataBefore=await p.evaluate(()=>Object.fromEntries(Object.keys(localStorage).filter(k=>/^retail-(cart|sample|box)-/.test(k)).map(k=>[k,localStorage.getItem(k)])));const before={hash:crypto.createHash('sha256').update(JSON.stringify(dataBefore)).digest('hex')};
 await p.evaluate(async()=>{for(const path of ['/retail/app.css','/retail/voice.mjs'])await fetch(path,{cache:'reload'});});await p.reload();await p.waitForSelector('[data-sales-action=voice]');
 const cdp=await c.newCDPSession(p);await cdp.send('Browser.grantPermissions',{permissions:['audioCapture'],origin:base});
 await p.evaluate(()=>{
  const qa=window.__galaxyVoice={events:[]},native=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices),NativePeer=RTCPeerConnection;
  navigator.mediaDevices.getUserMedia=async constraints=>{const stream=await native(constraints);stream.getAudioTracks().forEach(track=>track.enabled=false);qa.stream=stream;return stream;};
  window.RTCPeerConnection=class extends NativePeer {constructor(...args){super(...args);qa.peer=this;}createDataChannel(...args){const channel=super.createDataChannel(...args);channel.addEventListener('message',e=>{try{qa.events.push(JSON.parse(e.data).type);}catch{}});return channel;}};
 });
 await p.locator('[data-sales-action=voice]').first().click();await expect(p.locator('#retail-voice')).toHaveAttribute('data-state','ready',{timeout:15000});
 await p.screenshot({path:out+'/galaxy-ready.png'});
 await p.locator('[data-voice-action=start]').click();await expect.poll(()=>p.evaluate(()=>window.__galaxyVoice.peer?.connectionState),{timeout:35000}).toBe('connected');
 await p.locator('[data-voice-action=mute]').click();await expect.poll(()=>p.evaluate(()=>window.__galaxyVoice.events.includes('output_audio_buffer.stopped')),{timeout:30000}).toBe(true);
 const result=await p.evaluate(async()=>{const qa=window.__galaxyVoice,stats=await qa.peer.getStats();return {connection:qa.peer.connectionState,microphoneEnabled:qa.stream.getAudioTracks().some(t=>t.enabled),audioPackets:[...stats.values()].filter(s=>s.type==='inbound-rtp'&&s.kind==='audio').reduce((n,s)=>n+s.packetsReceived,0),audioPlaying:!document.querySelector('#voice-audio').paused,transcript:document.querySelector('#voice-transcript').innerText,standalone:matchMedia('(display-mode: standalone)').matches,viewport:{width:innerWidth,height:innerHeight},overflows:document.documentElement.scrollWidth>innerWidth};});
 assert.equal(result.microphoneEnabled,false);assert.ok(result.audioPackets>0);assert.equal(result.audioPlaying,true);assert.equal(result.standalone,true);assert.equal(result.overflows,false);
 await p.screenshot({path:out+'/galaxy-live-muted.png'});await p.locator('[data-voice-action=end]').click();assert.ok(await p.evaluate(()=>window.__galaxyVoice.stream.getTracks().every(t=>t.readyState==='ended')&&window.__galaxyVoice.peer.connectionState==='closed'));
 const saved=await p.evaluate(()=>Object.fromEntries(Object.keys(localStorage).filter(k=>/^retail-(cart|sample|box)-/.test(k)).map(k=>[k,localStorage.getItem(k)])));
 const hash=crypto.createHash('sha256').update(JSON.stringify(saved)).digest('hex');assert.equal(hash,before.hash);
 fs.writeFileSync(out+'/galaxy-results.json',JSON.stringify({passed:true,device:'Samsung SM-T577U',input:'real microphone track created immediately muted before transport; no ambient voice transmitted',microphoneStopped:true,cartAndActivityRetained:true,...result},null,2));passed=true;console.log(JSON.stringify({passed:true,audioPackets:result.audioPackets,audioPlaying:result.audioPlaying,standalone:true,microphoneStopped:true,cartAndActivityRetained:true}));
} catch(error) {console.log(await p.evaluate(()=>({status:document.querySelector('#voice-status')?.textContent,connection:window.__galaxyVoice?.peer?.connectionState,events:window.__galaxyVoice?.events})));throw error;}
finally {await p.locator('[data-voice-action=end]').click({timeout:1000}).catch(()=>{});await p.reload().catch(()=>{});process.exit(passed?0:1);}
