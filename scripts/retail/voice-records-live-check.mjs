import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';

// Explicit opt-in: starts one paid voice session with synthetic sample speech.
if(process.env.RETAIL_QA_LIVE_VOICE!=='1')throw Error('Set RETAIL_QA_LIVE_VOICE=1 to run the real provider check.');
const base='http://127.0.0.1:8797',out='output/retail/voice';fs.mkdirSync(out,{recursive:true});
for(const file of ['qa-records-question.wav','qa-records-followup.wav'])assert.ok(fs.existsSync(out+'/'+file),'Generate sample WAVs using the documented QA commands first.');
const browser=await chromium.launch({args:['--autoplay-policy=no-user-gesture-required']});
const context=await browser.newContext({viewport:{width:1280,height:800},serviceWorkers:'block'}),page=await context.newPage();
const errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));page.on('response',async r=>{if(r.url().includes('/api/voice/')){requests.push({path:new URL(r.url()).pathname,status:r.status(),message:r.status()===200?null:(await r.json().catch(()=>({}))).message});}});
await page.route('**/retail/qa-voice-*.wav',r=>r.fulfill({contentType:'audio/wav',body:fs.readFileSync(out+'/'+(r.request().url().includes('followup')?'qa-records-followup.wav':'qa-records-question.wav'))}));
await page.addInitScript(()=>{
 const qa=window.__voiceLive={events:[]};const Native=window.RTCPeerConnection;
 window.RTCPeerConnection=class extends Native {
  constructor(...args){super(...args);qa.peer=this;}
  createDataChannel(...args){const ch=super.createDataChannel(...args);qa.channel=ch;ch.addEventListener('message',e=>{try{const v=JSON.parse(e.data);qa.events.push(v.type);if(v.type==='error')qa.error=v.error?.code??'provider_error';}catch{}});return ch;}
 };
 navigator.mediaDevices.getUserMedia=async()=>{
  qa.audioContext=new AudioContext();await qa.audioContext.resume();qa.destination=qa.audioContext.createMediaStreamDestination();const noise=qa.audioContext.createBuffer(1,48000,48000);const samples=noise.getChannelData(0);for(let i=0;i<samples.length;i++)samples[i]=(Math.random()-.5)*.0002;qa.noise=qa.audioContext.createBufferSource();qa.noise.buffer=noise;qa.noise.loop=true;qa.noise.connect(qa.destination);qa.noise.start();return qa.destination.stream;
 };
 qa.speak=async file=>{const data=await fetch('/retail/qa-voice-'+file+'.wav').then(r=>r.arrayBuffer());const buffer=await qa.audioContext.decodeAudioData(data);const source=qa.audioContext.createBufferSource();source.buffer=buffer;source.connect(qa.destination);source.start();};
});
try {
 const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json','utf8'));
 await page.goto(base+'/login');await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>!u.pathname.endsWith('/login'));
 await page.goto(base+'/retail/showroom/concepts/joy-fudge-company');await page.waitForSelector('.product-card');
 await page.evaluate(async()=>{const pack=await fetch('/retail/showroom/api/bootstrap?merchant=joy-fudge-company').then(r=>r.json()),merchant=pack.vendors.find(v=>v.slug==='joy-fudge-company'),product=merchant.products[0],variant=product.variants[0];localStorage.setItem('retail-sample-joy-fudge-company',JSON.stringify({customers:[{id:'sample-1',name:'Avery Morgan',email:'avery@example.invalid',points:162,notes:[{body:'Prefers chocolate gifts.'}],reward_entries:[{order_id:'DEMO-ABC12345',earned:12,redeemed:0,is_demo:true}]}],orders:[{id:'DEMO-ABC12345',is_demo:true,status:'simulated',customer_id:'sample-1',lines:[{name:product.name,variant:variant.title,quantity:1,line_cents:1200}],subtotal_cents:1200,shipping_cents:0,reward_credit_cents:0,total_cents:1200,service:'Sample pickup',created_at:'2026-10-17T12:00:00Z'}],stock:{}}));window.dispatchEvent(new Event('offline'));window.dispatchEvent(new Event('online'));});
 await page.locator('[data-action=staff]').click();await page.locator('[data-action=staff-tab][data-tab=customers]').click();await page.locator('[data-action=customer][data-customer=sample-1]').click();await page.locator('[data-sales-action=voice][data-voice-customer=sample-1]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready',{timeout:15000});
 await page.locator('[data-voice-action=start]').click();await expect.poll(()=>page.evaluate(()=>window.__voiceLive.peer?.connectionState),{timeout:40000}).toBe('connected');
 await expect.poll(()=>page.evaluate(()=>window.__voiceLive.events.filter(v=>v==='output_audio_buffer.stopped').length),{timeout:30000}).toBeGreaterThanOrEqual(1);
 await page.evaluate(()=>window.__voiceLive.speak('question'));
 await expect.poll(()=>page.locator('#voice-transcript .user').count(),{timeout:30000}).toBeGreaterThanOrEqual(1);
 await expect.poll(()=>page.evaluate(()=>window.__voiceLive.events.filter(v=>v==='output_audio_buffer.stopped').length),{timeout:30000}).toBeGreaterThanOrEqual(2);
 await page.evaluate(()=>window.__voiceLive.speak('followup'));
 await expect.poll(()=>page.locator('#voice-transcript .user').count(),{timeout:30000}).toBeGreaterThanOrEqual(2);
 await expect.poll(()=>page.evaluate(()=>window.__voiceLive.events.filter(v=>v==='output_audio_buffer.stopped').length),{timeout:30000}).toBeGreaterThanOrEqual(3);
 const proof=await page.evaluate(async()=>{const qa=window.__voiceLive,stats=await qa.peer.getStats();return {connection:qa.peer.connectionState,audioPackets:[...stats.values()].filter(s=>s.type==='inbound-rtp'&&s.kind==='audio').reduce((n,s)=>n+s.packetsReceived,0),transcript:document.querySelector('#voice-transcript').innerText,playing:!document.querySelector('#voice-audio').paused,events:qa.events,error:qa.error??null};});
 assert.ok(proof.audioPackets>0);assert.equal(proof.error,null);assert.equal(proof.playing,true);const replies=await page.locator('#voice-transcript .assistant').allTextContents();const answer=replies.join(' ');assert.match(answer,/Chocolate fudge/i);assert.match(answer,/\$12\b|12(?:\.00)? dollars|twelve dollars/i);assert.match(answer,/162|one hundred (?:and )?sixty.two/i);assert.match(answer,/chocolate.*gift|gift.*chocolate/i);await expect(page.locator('#voice-context-summary')).toContainText('Discussing Avery Morgan');assert.match(proof.transcript,/Joy Fudge Company/);assert.deepEqual(await page.locator('#voice-transcript li').evaluateAll(rows=>rows.map(r=>r.className)),['voice-line assistant','voice-line user','voice-line assistant','voice-line user','voice-line assistant']);
 await page.screenshot({path:out+'/records-live-conversation.png'});await page.locator('[data-voice-action=end]').click();
 assert.ok(await page.evaluate(()=>window.__voiceLive.destination.stream.getTracks().every(t=>t.readyState==='ended')&&window.__voiceLive.peer.connectionState==='closed'));
 assert.deepEqual(errors,[]);fs.writeFileSync(out+'/records-live-results.json',JSON.stringify({passed:true,provider:'OpenAI Realtime',input:'two synthetic spoken questions about a demo customer, purchase, rewards and notes; no ambient microphone capture',microphoneStopped:true,...proof,errors},null,2));console.log(JSON.stringify({passed:true,input:'two synthetic spoken questions about a demo customer, purchase, rewards and notes',audioPackets:proof.audioPackets,playing:proof.playing,microphoneStopped:true,errors}));
} catch(error) {const diagnostic=await page.evaluate(()=>({status:document.querySelector('#voice-status')?.textContent,events:window.__voiceLive.events,error:window.__voiceLive.error,connection:window.__voiceLive.peer?.connectionState}));fs.writeFileSync(out+'/records-live-failure.json',JSON.stringify({requests,diagnostic,error:error.message},null,2));console.log(JSON.stringify({requests,diagnostic}));throw error;} finally {await page.locator('[data-voice-action=end]').click({timeout:1000}).catch(()=>{});await browser.close();}
