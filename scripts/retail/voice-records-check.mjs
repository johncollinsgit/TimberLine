import {chromium,expect} from '@playwright/test';import fs from 'node:fs';import assert from 'node:assert/strict';
const base='http://127.0.0.1:8797',out='output/retail/voice';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch(),context=await browser.newContext({viewport:{width:960,height:600},serviceWorkers:'block'}),page=await context.newPage();const sessions=[],errors=[];
page.on('pageerror',e=>errors.push(e.message));
await page.addInitScript(()=>{
 navigator.mediaDevices.getUserMedia=async()=>{const t={enabled:true,stop(){}};return{getTracks:()=>[t],getAudioTracks:()=>[t]};};
 window.RTCPeerConnection=class {addTrack(){}createDataChannel(){return{send(){},close(){}};}async createOffer(){return{sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n'};}async setLocalDescription(){}async setRemoteDescription(){this.connectionState='connected';this.onconnectionstatechange();}close(){}};
});
await page.route('**/retail/showroom/api/voice/status',r=>r.fulfill({json:{available:true}}));
await page.route('**/retail/showroom/api/voice/session',async r=>{sessions.push(r.request().postDataJSON());await r.fulfill({json:{sdp:'v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n',context_summary:{source:'tablet_sample',customers:2,orders:1,focused_customer:'Avery Morgan'}}});});
try {
 const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json'));
 await page.goto(base+'/login');await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>!u.pathname.endsWith('/login'));
 await page.goto(base+'/retail/showroom/concepts/joy-fudge-company');await page.waitForSelector('.product-card');
 await page.evaluate(()=>{
  localStorage.setItem('retail-sample-joy-fudge-company',JSON.stringify({customers:[{id:'sample-1',name:'Avery Morgan',email:'DO_NOT_SEND@example.invalid',points:162,notes:[{body:'Prefers chocolate gifts.',by:'DO_NOT_SEND_STAFF_IDENTITY'}],reward_entries:[{order_id:'DEMO-ABC12345',earned:12,redeemed:0,is_demo:true}]},{id:'sample-2',name:'Jordan Lee',points:75,notes:[],reward_entries:[]}],orders:[{id:'DEMO-ABC12345',is_demo:true,status:'simulated',customer_id:'sample-1',lines:[{name:'Chocolate fudge',variant:'Standard',quantity:1,line_cents:1200}],subtotal_cents:1200,shipping_cents:0,reward_credit_cents:0,total_cents:1200,service:'Sample pickup',created_at:'2026-10-17T12:00:00Z',actor_id:'DO_NOT_SEND_ACTOR'}],stock:{}}));
  localStorage.setItem('retail-sample-leather-link-studio',JSON.stringify({customers:[{id:'sample-1',name:'OTHER_BUSINESS_SECRET',points:999,notes:[{body:'OTHER_BUSINESS_NOTE'}]}],orders:[]}));
  window.dispatchEvent(new Event('offline'));window.dispatchEvent(new Event('online'));
 });
 await page.locator('[data-action=staff]').click();await page.locator('[data-action=staff-tab][data-tab=customers]').click();await page.locator('[data-action=customer][data-customer=sample-1]').click();
 await page.locator('[data-sales-action=voice][data-voice-customer=sample-1]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');await page.locator('[data-voice-action=start]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','listening');
 const request=sessions[0];assert.equal(request.focus_customer,'sample-1');assert.equal(request.page,'customers');assert.equal(request.sample_activity.merchant,'joy-fudge-company');assert.equal(request.sample_activity.customers[0].points,162);assert.equal(request.sample_activity.orders[0].total_cents,1200);assert.deepEqual(request.sample_activity.customers[0].notes,['Prefers chocolate gifts.']);assert.ok(!JSON.stringify(request).includes('DO_NOT_SEND'));assert.ok(!JSON.stringify(request).includes('OTHER_BUSINESS'));
 await expect(page.locator('#voice-context-summary')).toContainText('2 customers · 1 recent order · Discussing Avery Morgan');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:out+'/records-context-landscape.png'});
 await page.locator('[data-voice-action=end]').click();await page.locator('#voice-merchant').selectOption('leather-link-studio');await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','ready');await page.locator('[data-voice-action=start]').click();await expect(page.locator('#retail-voice')).toHaveAttribute('data-state','listening');assert.ok(!sessions[1].focus_customer);assert.equal(sessions[1].sample_activity.merchant,'leather-link-studio');assert.equal(sessions[1].sample_activity.customers[0].points,999);assert.ok(!JSON.stringify(sessions[1]).includes('Prefers chocolate gifts'));
 assert.deepEqual(errors,[]);const result={passed:true,profileEntry:true,selectedCustomerFocus:true,tabletOrdersAndRewards:true,notesIncluded:true,contactsAndStaffIdentitiesExcluded:true,merchantSwitchIsolated:true,errors};fs.writeFileSync(out+'/records-browser-results.json',JSON.stringify(result,null,2));console.log(result);
}finally{await browser.close();}
