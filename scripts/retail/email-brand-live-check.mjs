import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
if(process.env.RETAIL_QA_LIVE_EMAIL!=='1'||!process.env.RETAIL_QA_EMAIL)throw Error('Explicit live-email opt-in and authorized test address required.');
const out='output/retail/email';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch(),context=await browser.newContext({serviceWorkers:'block',viewport:{width:1280,height:800}}),page=await context.newPage();let result;
await page.route('**/retail/showroom/api/demo/delivery',async route=>{const body=route.request().postDataJSON();assert.equal(body.channel,'email');body.idempotency_key='d4d4d4d4-4444-4444-8444-444444444444';await route.continue({postData:JSON.stringify(body)});});
page.on('dialog',dialog=>dialog.accept());page.on('response',async r=>{if(r.url().endsWith('/demo/delivery'))result={http:r.status(),...await r.json()};});
try{
 const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json'));
 await page.goto('http://127.0.0.1:8797/login');await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>u.pathname!=='/login');
 await page.goto('http://127.0.0.1:8797/retail/showroom/concepts/avery-knifeworks');await page.locator('.avery-launch [data-avery-action=workspace]').click();await page.locator('[data-demo-action=view][data-view=marketing]').first().click();await page.locator('[data-demo-action=market-customer][data-id=ak-demo-02]').click();
 await expect(page.locator('.demo-email-preview')).toContainText('Maple Burl');await page.locator('#demo-live-message [name=recipient]').fill(process.env.RETAIL_QA_EMAIL);await page.locator('#demo-live-message [name=consent]').check();await page.locator('#demo-live-message button[type=submit]').click();await expect(page.locator('#demo-delivery-status')).toContainText(/accepted|unconfirmed/,{timeout:30000});
 assert.equal(result?.status,'accepted','SendGrid must confirm acceptance, not just an attempted send.');fs.writeFileSync(out+'/live-branded-email.json',JSON.stringify({emailAccepted:true,recipientStored:false,reference:result.reference,reused:result.reused,source:'reviewed_tablet_flow',smsSent:false,paymentSubmitted:false},null,2));console.log(JSON.stringify({emailAccepted:true,reference:result.reference,reused:result.reused}));
}finally{await browser.close();}
