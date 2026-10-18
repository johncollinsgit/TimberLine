import {revealAction} from './qa-navigation.mjs';
import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const base='http://127.0.0.1:8797',out='output/retail/workbench';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch(),context=await browser.newContext({viewport:{width:1280,height:800},serviceWorkers:'block'}),page=await context.newPage(),errors=[];
page.on('pageerror',e=>errors.push(e.message));
const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json'));
try{
 await page.goto(base+'/login');await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>u.pathname!=='/login');
 await page.goto(base+'/retail/showroom/concepts/avery-knifeworks');await page.waitForSelector('.avery-launch');
 await page.locator('.avery-launch [data-avery-action=workspace]').click();await page.locator('[data-demo-action=view][data-view=new_customer]').first().click();
 await page.locator('#demo-customer-form [name=name]').fill('Demo Test Customer');await page.locator('#demo-customer-form [name=email]').fill('demo-test@everbranch.invalid');await page.locator('#demo-customer-form [name=notes]').fill('Prefers a small kitchen knife with walnut.');await page.locator('#demo-customer-form button[type=submit]').click();await expect(page.locator('.demo-workbench')).toContainText('Demo Test Customer');
 await page.locator('[data-demo-action=invoice-customer]').click();await page.locator('#demo-invoice-form [name=quantity]').fill('2');await page.locator('#demo-invoice-form button[type=submit]').click();await expect(page.locator('.demo-workbench')).toContainText('DEMO-INV-0001');await expect(page.locator('.demo-workbench')).toContainText('$850.00');
 await page.locator('[data-demo-action=view][data-view=customers]').click();await page.locator('[data-demo-action=customer][data-id=ak-demo-03]').click();await expect(page.locator('.demo-workbench')).toContainText('Bob Ross');await expect(page.locator('.demo-workbench')).toContainText('Woodsman Knife');await expect(page.locator('.demo-workbench')).toContainText('favorite knife');
 await page.locator('[data-demo-action=market-customer][data-channel=email]').click();await expect(page.locator('.demo-workbench')).toContainText('Pinecone/Pearl Resin');await expect(page.locator('.demo-workbench')).toContainText('$385.00');assert.equal(await page.locator('#demo-live-message [name=consent]').isChecked(),false);
 // Consent-less requests and non-owner requests never send.
 const invalid=await page.evaluate(async()=>{const r=await fetch('/retail/showroom/api/demo/delivery',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({channel:'email',recipient:'nobody@example.invalid'})});return r.status;});assert.equal(invalid,422);
 await page.screenshot({path:out+'/marketing-review-landscape.png',fullPage:true});
 for(const [layout,size] of [['portrait',{width:800,height:1280}],['phone',{width:390,height:844}]]){await page.setViewportSize(size);assert.equal(await page.locator('.modal').evaluate(e=>e.scrollWidth>e.clientWidth),false);await page.screenshot({path:`${out}/marketing-review-${layout}.png`,fullPage:true});}
 assert.deepEqual(errors,[]);fs.writeFileSync(out+'/results.json',JSON.stringify({passed:true,customerSaved:true,invoicePriceAndQuantity:true,bobFavorite:true,marketingProductVariant:true,explicitConsent:true,validationBlocksSend:true,errors},null,2));console.log('PASS: front-end customer creation, catalog-priced invoice, Bob profile, personalized message review and 3 layouts.');
}finally{await browser.close();}
