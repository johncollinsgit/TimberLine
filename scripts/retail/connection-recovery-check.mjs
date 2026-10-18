import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const base=process.env.RETAIL_QA_BASE??'http://127.0.0.1:8797';
const b=await chromium.launch(),c=await b.newContext(),p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
await p.addInitScript(()=>{window.__recovery={tracks:[],calls:0};navigator.mediaDevices.getUserMedia=async()=>{const t={enabled:true,stop(){this.stopped=true;}};window.__recovery.tracks.push(t);return{getTracks:()=>[t],getAudioTracks:()=>[t]};};window.RTCPeerConnection=class {addTrack(){}createDataChannel(){return{send(){},close(){}};}async createOffer(){return{sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n'};}async setLocalDescription(){}async setRemoteDescription(){this.connectionState='connected';this.onconnectionstatechange();}close(){this.connectionState='closed';}};});
const sessions=[];await p.route('**/retail/showroom/api/voice/session',async r=>{sessions.push({headers:r.request().headers(),body:r.request().postDataJSON()});await r.fulfill({json:{sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n',max_duration_seconds:300}});});
const creds=JSON.parse(fs.readFileSync(process.env.RETAIL_QA_ACCESS??'storage/app/private/retail-local-access.json','utf8'));
try {
 await p.goto(base+'/login');await p.locator('[name=email]').fill(creds.email);await p.locator('[name=password]').fill(creds.password);await p.locator('button[type=submit]').click();await p.waitForURL(u=>!u.pathname.endsWith('/login'));
 await p.goto(base+'/retail/showroom');await p.locator('[data-action=offline]').click();await expect(p.locator('#toast')).toContainText('Offline pack ready',{timeout:120000});
 await p.goto(base+'/retail/showroom/concepts/avery-knifeworks');await p.waitForSelector('.avery-store-logo');await p.evaluate(()=>navigator.serviceWorker.ready);
 await c.setOffline(true);await p.reload();await p.waitForSelector('.avery-store-logo');assert.equal(await p.locator('meta[name=csrf-token]').count(),0);
 await p.locator('[data-sales-action=voice]').first().click();await expect(p.locator('#retail-voice')).toHaveAttribute('data-state','offline',{timeout:15000});
 await c.setOffline(false);await p.locator('[data-voice-action=check]').click();await expect(p.locator('#retail-voice')).toHaveAttribute('data-state','ready',{timeout:20000});
 assert.equal(await p.evaluate(()=>window.__recovery.tracks.length),0,'Network recovery never opens microphone');
 const token=await p.locator('meta[name=csrf-token]').getAttribute('content');assert.match(token,/^[A-Za-z0-9]{40}$/);
 await p.locator('[data-voice-action=start]').click();await expect(p.locator('#retail-voice')).toHaveAttribute('data-state','listening');assert.equal(sessions.at(-1).headers['x-csrf-token'],token);assert.equal(sessions.at(-1).body.merchant,'avery-knifeworks');
 await p.locator('[data-voice-action=end]').click();await p.locator('meta[name=csrf-token]').evaluate(e=>e.content='stale-token');
 await p.locator('[data-voice-action=check]').click();await expect(p.locator('#retail-voice')).toHaveAttribute('data-state','ready');assert.equal(await p.locator('meta[name=csrf-token]').getAttribute('content'),token);
 await p.locator('[data-voice-action=start]').click();await expect(p.locator('#retail-voice')).toHaveAttribute('data-state','listening');assert.equal(sessions.length,2);await p.locator('[data-voice-action=end]').click();assert.ok(await p.evaluate(()=>window.__recovery.tracks.every(t=>t.stopped)));
 await c.clearCookies();await p.locator('[data-voice-action=check]').click();await expect(p.locator('#voice-status')).toContainText('Sign in as the showroom owner');assert.equal(await p.locator('[data-voice-action=start]').isDisabled(),true);assert.equal(sessions.length,2);assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,offlineColdLaunch:true,reconnectFreshToken:true,staleTokenRecovered:true,twoExplicitStarts:true,noAutomaticMicrophone:true,expiredAuthenticationBlocked:true,provider:'mock transport; real session+CSRF endpoint',errors},null,2));
}finally{await b.close();}
