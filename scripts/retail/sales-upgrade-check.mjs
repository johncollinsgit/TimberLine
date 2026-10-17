import {chromium} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=process.cwd(), base='http://127.0.0.1:8797';
const output=root+'/output/retail/sales-upgrade';fs.mkdirSync(output,{recursive:true});
const creds=JSON.parse(fs.readFileSync(root+'/storage/app/private/retail-local-access.json','utf8'));
const live=process.env.RETAIL_QA_LIVE_AI==='1', device=process.env.RETAIL_QA_DEVICE==='1';
const browser=device?await chromium.connectOverCDP('http://127.0.0.1:9230'):await chromium.launch(process.env.RETAIL_QA_BROWSER_EXECUTABLE?{executablePath:process.env.RETAIL_QA_BROWSER_EXECUTABLE}:{});
const context=device?browser.contexts()[0]:await browser.newContext({viewport:{width:1280,height:800}});
const page=device?context.pages().find(p=>p.url().startsWith(base+'/')):await context.newPage();
assert.ok(page,'The existing Retail tab must be open.');
const errors=[],checks=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto(base+'/login');
if(new URL(page.url()).pathname==='/login'){
 await page.locator('input[name="email"]').fill(creds.email);
 await page.locator('input[name="password"]').fill(creds.password);
 await page.locator('button[type="submit"]').click();
 await page.waitForURL(u=>!u.pathname.endsWith('/login'));
}
await page.goto(base+'/retail/showroom');await page.waitForSelector('.vendor-grid');
assert.equal(await page.locator('.vendor-card').count(),20);checks.push('All 20 existing storefronts remain available');
const bootstrap=await page.evaluate(async()=>fetch('/retail/showroom/api/bootstrap').then(r=>r.json()));
assert.equal(bootstrap.offer.introductory_monthly_cents,8900);assert.equal(bootstrap.offer.ongoing_monthly_cents,14900);
assert.ok(!JSON.stringify(bootstrap).includes('api_key'));checks.push('Canonical pricing and no credential in bootstrap');
await page.locator('[data-action="pricing"]').first().click();
await page.waitForSelector('.launch-price-grid');
assert.match(await page.locator('.launch-price-grid').first().innerText(),/89\.00[\s\S]*149\.00/);
assert.match(await page.locator('.intro-expiry').first().innerText(),/six months/);
assert.match(await page.locator('.launch-eligibility').first().innerText(),/10 qualifying businesses/);
assert.match(await page.locator('.offer-setup').first().innerText(),/499\.00/);
assert.match(await page.locator('.partner-band').innerText(),/One-hour video call every week/);
checks.push('Both monthly rates together, explicit expiry, setup, eligibility and retained partnership');
await page.screenshot({path:output+(device?'/galaxy-pricing.png':'/pricing-landscape.png'),fullPage:true});
if(!device){await page.setViewportSize({width:800,height:1280});await page.screenshot({path:output+'/pricing-portrait.png',fullPage:true});}
await page.locator('[data-sales-action="signup"]').first().click();await page.waitForSelector('#retail-signup-details');
for(const [key,value] of Object.entries({business_name:'QA Demo Business',contact_name:'QA Sample Contact',email:'qa-retail@sample.invalid',phone:'+1 555 010 2345'}))await page.locator(`#retail-signup-details [name="${key}"]`).fill(value);
// Finish editing before touching the review action on an actual Android keyboard.
await page.locator('#retail-signup-details [name="phone"]').blur();
await page.locator('#retail-signup-details button[type="submit"]').click();await page.waitForSelector('#retail-signup-review');
assert.match(await page.locator('#modal').innerText(),/89\.00[\s\S]*149\.00/);
assert.ok(!await page.evaluate(()=>JSON.stringify(localStorage).includes('qa-retail@sample.invalid')));
checks.push('Two-step signup review and no contact data in tablet local storage');
await page.screenshot({path:output+(device?'/galaxy-signup-review.png':'/signup-review.png')});
if(!device){
 let submits=0;
 await page.route('**/retail/showroom/api/signup',async route=>{submits++;const data=route.request().postDataJSON();assert.equal(data.pricing_accepted,true);assert.equal(data.subscription,'retail_launch_partner');assert.ok(data.quote_version);await route.fulfill({json:{status:'pending_review',reference:'QA-UI-FIXTURE',subscription_active:false,slot_reserved:false,confirmation_status:'pending'}});});
 await page.locator('[name="pricing_accepted"]').check();await page.locator('#retail-signup-review button[type="submit"]').click();await page.getByText('Your next chapter starts with a conversation.').waitFor();assert.equal(submits,1);checks.push('Mocked UI submit honestly shows pending activation and pending confirmation');
}
await page.locator('[data-action="close"]').first().click();
assert.equal(await page.locator('#retail-signup-review').count(),0);
await page.locator('[data-action="assistant"]').first().click();await page.waitForSelector('.assistant-grid');
assert.equal(await page.locator('.assistant-card').count(),6);assert.match(await page.locator('.assistant-card').last().innerText(),/Coming Soon/);
checks.push('Six assistant capabilities with Social Media explicitly Coming Soon');
await page.screenshot({path:output+(device?'/galaxy-assistant.png':'/assistant-portrait.png'),fullPage:true});
await page.locator('[data-sales-action="assistant"][data-mode="email"]').click();await page.waitForSelector('#retail-assistant-form');
if(live){assert.equal(bootstrap.assistant.available,true);await page.locator('#assistant-query').fill('Draft a brief email announcing a weekend collection. Ask me to confirm the date and offer; do not invent a discount.');await page.locator('#retail-assistant-form button[type="submit"]').click();await page.waitForSelector('#assistant-result h3',{timeout:50000});assert.match(await page.locator('#assistant-result h3').innerText(),/OpenAI/);assert.ok((await page.locator('#assistant-result pre').innerText()).length>20);checks.push('Real OpenAI request returned a contextual draft; no campaign sent');}
else {if(await page.locator('[data-sales-action="guided"]').count())await page.locator('[data-sales-action="guided"]').click();await page.locator('#assistant-query').fill('A weekend collection');await page.locator('#retail-assistant-form button[type="submit"]').click();await page.waitForSelector('#assistant-result pre');assert.match(await page.locator('#assistant-result h3').innerText(),/Sample draft/);checks.push('Guided fallback clearly labeled as a sample');}
await page.screenshot({path:output+(device?'/galaxy-ai-draft.png':'/ai-draft.png')});
await page.locator('[data-action="close"]').first().click();
await page.locator('[data-sales-action="assistant"][data-mode="voice"]').click();await page.locator('#assistant-query').fill('Open pricing');await page.locator('#retail-assistant-form button[type="submit"]').click();await page.waitForSelector('.launch-price-grid');checks.push('Voice-assistant typed fallback opens the requested pricing page');
assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal overflow');
if(!device){
 await page.locator('[data-action="home"]').first().click();
 const previous=await page.evaluate(()=>JSON.parse(localStorage.getItem('retail-offline-pack')||'null')?.expires??0);
 await page.locator('[data-action="offline"]').first().click();
 await page.waitForFunction(old=>JSON.parse(localStorage.getItem('retail-offline-pack')||'null')?.expires>old,previous,{timeout:60000});
 await page.evaluate(()=>navigator.serviceWorker.ready);
 const privateCached=await page.evaluate(async()=>{const c=await caches.open('everbranch-retail-v5');return (await c.keys()).some(r=>new URL(r.url).pathname.includes('/api/'));});assert.equal(privateCached,false);
 await context.setOffline(true);await page.goto(base+'/retail/showroom/assistant');await page.waitForSelector('.assistant-grid');assert.match(await page.locator('.assistant-hero').innerText(),/live AI unavailable/);
 await page.locator('[data-action="pricing"]').first().click();await page.locator('[data-sales-action="signup"]').first().click();assert.equal(await page.locator('#retail-signup-details').count(),0);
 await context.setOffline(false);await page.reload();await page.waitForSelector('.assistant-grid');checks.push('Cache v4 reopens assistant offline, excludes private APIs and blocks offline signup');
}
assert.deepEqual(errors,[]);
fs.writeFileSync(output+(device?'/galaxy-results.json':'/browser-results.json'),JSON.stringify({checks,errors,liveOpenAiVerified:live,device,signupSubmission:device?'reviewed only; never submitted':'intercepted fixture; no real registration'},null,2));
console.log(JSON.stringify({checks,errors,liveOpenAiVerified:live,device},null,2));
if(!device)await browser.close();
process.exit(0);
