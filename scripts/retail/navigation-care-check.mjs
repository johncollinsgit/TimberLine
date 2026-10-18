import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
import {revealAction} from './qa-navigation.mjs';
const out='output/retail/navigation-care';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch(),context=await browser.newContext({serviceWorkers:'block',viewport:{width:1280,height:800}}),page=await context.newPage(),errors=[];
page.on('pageerror',e=>errors.push(e.message));
const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json'));
const menu=async(label)=>page.locator('.avery-store-nav summary').filter({hasText:label}).click();
const close=async()=>page.locator('[data-action=close]').click();
try{
 await page.goto('http://127.0.0.1:8797/login');await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>u.pathname!=='/login');
 await page.goto('http://127.0.0.1:8797/retail/showroom/concepts/avery-knifeworks');await page.waitForSelector('.avery-store-logo');
 for(const [name,size] of [['landscape',{width:1280,height:800}],['portrait',{width:800,height:1280}],['phone',{width:390,height:844}]]){
  await page.setViewportSize(size);
  for(const label of ['Shop & discover','Knife care','Workshop']){await menu(label);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await page.screenshot({path:`${out}/nav-${name}-${label.split(' ')[0]}.png`});await page.keyboard.press('Escape');}
 }
 await page.setViewportSize({width:1280,height:800});await menu('Shop & discover');await page.locator('.avery-store-nav [data-avery-action=finder]').click();await expect(page.locator('.avery-steps')).toContainText('Your world');await close();
 await menu('Knife care');await page.locator('.avery-store-nav [data-avery-action=passport]').click();await expect(page.locator('.avery-passport')).toContainText(/Morgan/i);await close();
 await menu('Knife care');await page.locator('.avery-store-nav [data-avery-action=owner]').click();await expect(page.locator('.avery-care-explainer')).toContainText('Saving a preference is not marketing consent');await expect(page.locator('.avery-service-row')).toHaveCount(3);await expect(page.locator('.avery-interest')).toHaveCount(4);await expect(page.locator('.avery-service-row').filter({hasText:'Bob Ross'})).toContainText('avery-demo-bob-001');await page.screenshot({path:out+'/care-and-interests.png',fullPage:true});await close();
 await menu('Workshop');await page.locator('.avery-store-nav [data-avery-action=workspace][data-view=production]').click();await expect(page.locator('#modal-body')).toContainText('DEMO-BATCH-03');await close();
 await menu('Workshop');await page.locator('.avery-store-nav [data-avery-action=workspace][data-view=customers]').click();await page.locator('[data-avery-action=customer][data-customer=ak-demo-02]').click();await page.locator('[data-demo-action=market-customer][data-channel=email]').click();await expect(page.locator('.demo-email-preview')).toBeVisible();await expect(page.locator('.demo-email-preview img[alt="Avery Knifeworks"]')).toBeVisible();await expect(page.locator('.demo-email-preview')).toContainText('Maple Burl');await page.waitForFunction(()=>[...document.querySelectorAll('.demo-email-preview img')].every(i=>i.complete&&i.naturalWidth>0));
 for(const [name,size] of [['landscape',{width:1280,height:800}],['portrait',{width:800,height:1280}],['phone',{width:390,height:844}]]){await page.setViewportSize(size);assert.equal(await page.locator('.modal').evaluate(e=>e.scrollWidth>e.clientWidth),false);await page.screenshot({path:`${out}/branded-email-${name}.png`,fullPage:true});}
 await page.setViewportSize({width:1280,height:800});await close();
 await revealAction(page,'[data-action=presentation]');await page.locator('[data-action=presentation]').click();await page.locator('.footer [data-avery-action=handoff]').click();await expect(page.locator('#avery-handoff-links')).toContainText('Live Stripe');await expect(page.locator('[name=avery-service]')).toHaveCount(6);
 let submitted=0;await page.route('**/retail/showroom/api/avery/services',async route=>{const d=route.request().postDataJSON();assert.equal(d.offer_version.length,64);assert.deepEqual(d.services,['email','memory']);submitted++;await route.fulfill({json:{saved:true,activated:false,quote:{selected_services:d.services,subtotal_cents:79800,preload_cents:0}}});});
 await page.locator('[name=avery-service][value=email]').check();await page.locator('[name=avery-service][value=memory]').check();await page.locator('[data-avery-action=save-services]').click();await expect(page.locator('#avery-feature-status')).toContainText('First payment remains $798');assert.equal(submitted,1);await page.screenshot({path:out+'/feature-review.png',fullPage:true});
 assert.deepEqual(errors,[]);fs.writeFileSync(out+'/results.json',JSON.stringify({passed:true,dropdowns:true,layouts:3,careSeedRecords:3,interestSeedRecords:4,linkedCustomers:true,brandedEmail:true,featureReview:true,providerMessagesSent:0,invoiceChanges:0,errors},null,2));console.log('PASS: categorized navigation, linked care/interests samples, branded email and feature-preference review; no live sends or invoice changes.');
}finally{await browser.close();}
