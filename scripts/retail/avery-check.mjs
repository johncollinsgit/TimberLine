import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
import {rankKnives} from '../../public/retail/avery.mjs';
import {products} from '../../public/retail/avery-data.mjs';

const out='output/retail/avery';fs.mkdirSync(out,{recursive:true});
for(const category of ['Kitchen','Hunting','Fishing','Everyday']){
 const rows=rankKnives({category,use:'General field work',budget:50000,material:'Any handle'});
 assert.ok(rows.length>=2&&rows.length<=3);
 assert.ok(rows.every(r=>r.p.categories.includes(category)&&r.options.every(v=>v.price_cents<=50000)));
}
assert.equal(rankKnives({category:'Fishing',use:'Smaller fish',budget:20000,material:'Any handle'}).length,0);
assert.equal(products.find(p=>p.handle==='sheepsfoot-chefs-knife').variants[0].price_cents,42500);
assert.equal(products.find(p=>p.handle==='woodsman-knife').variants[0].price_cents,38500);
const browser=await chromium.launch(process.env.RETAIL_QA_BROWSER_EXECUTABLE?{executablePath:process.env.RETAIL_QA_BROWSER_EXECUTABLE}:{});
const context=await browser.newContext({viewport:{width:1280,height:800}}),page=await context.newPage();
const errors=[],external=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(!r.url().startsWith('http://127.0.0.1:8797/')&&!r.url().startsWith('data:')&&!r.url().startsWith('https://fonts.bunny.net/'))external.push(r.url());});
const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json'));
const a=s=>page.locator(`#modal [data-avery-action="${s}"]`);
try{
 await page.goto('http://127.0.0.1:8797/login');
 if(new URL(page.url()).pathname==='/login'){
  await page.locator('[name=email]').fill(creds.email);await page.locator('[name=password]').fill(creds.password);await page.locator('button[type=submit]').click();await page.waitForURL(u=>u.pathname!=='/login');
 }
 await page.goto('http://127.0.0.1:8797/retail/showroom');await page.waitForSelector('.vendor-grid');
 await page.locator('[data-action=offline]').first().click();
 await page.waitForFunction(()=>!!localStorage.getItem('retail-offline-pack'),null,{timeout:60000});
 await expect.poll(()=>page.evaluate(async()=>{const c=await caches.open('everbranch-retail-v12');return !!await c.match('/retail/avery-data.mjs');}),{timeout:60000}).toBe(true);
 await page.goto('http://127.0.0.1:8797/retail/showroom/concepts/avery-knifeworks');await page.waitForSelector('.avery-launch');
 await page.locator('.avery-launch [data-avery-action=finder]').click();await a('category').filter({hasText:'Kitchen'}).click();await a('use').filter({hasText:'Everyday meal prep'}).click();await a('budget').filter({hasText:'Up to $500'}).click();await a('material').filter({hasText:'Any handle'}).click();
 await expect(page.locator('.avery-result')).toHaveCount(3);
 await expect(page.locator('.avery-result').first()).toContainText('Sheepsfoot Chef');
 await page.locator('.avery-result').first().locator('[data-avery-action=knife]').click();
 await a('variant').filter({hasText:'Walnut'}).click();
 await expect(page.locator('.avery-detail')).toContainText('Walnut');
 await a('save').click();await a('save').click();
 await a('qr').click();await expect(page.locator('.avery-qr svg')).toBeVisible();
 const exact=await page.locator('.avery-qr a').getAttribute('href');assert.ok(exact.includes('sheepsfoot-chefs-knife?variant='));
 await page.screenshot({path:out+'/exact-handle-qr.png',fullPage:true});
 await a('owner').click();await expect(page.locator('.avery-interest')).toHaveCount(1);await expect(page.locator('.avery-interest')).toContainText('No permission to contact');
 await a('finder').click();await a('category').filter({hasText:'Hunting'}).click();await a('use').filter({hasText:'Camp & woodcraft'}).click();await a('budget').filter({hasText:'Up to $400'}).click();await a('material').filter({hasText:'Any handle'}).click();
 await page.locator('.avery-result').first().locator('[data-avery-action=knife]').click();await a('restock').click();
 assert.equal(await page.locator('[name=permission]').isChecked(),false);await page.locator('[name=permission]').check();await page.locator('#avery-restock button[type=submit]').click();
 await expect(page.locator('.avery-interest')).toHaveCount(2);await expect(page.locator('.avery-interest').last()).toContainText('Product-specific demonstration permission');
 await a('passport').first().click();await expect(page.locator('.avery-care')).toContainText('registration is optional');
 await a('service').click();await page.locator('#avery-service button[type=submit]').click();
 await expect(page.locator('.avery-passport')).toContainText('Received');await a('owner').first().click();
 await expect(page.locator('.avery-service-row')).toContainText('Morgan Ellis');await expect(page.locator('.avery-service-row')).toContainText('avery-demo-knife-001');
 for(const [name,size] of [['landscape',{width:1280,height:800}],['portrait',{width:800,height:1280}],['phone',{width:390,height:844}]]){
  await page.setViewportSize(size);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  assert.equal(await page.locator('.modal').evaluate(e=>e.scrollWidth>e.clientWidth),false);
  await page.screenshot({path:`${out}/owner-${name}.png`,fullPage:true});
 }
 await a('advance').click();await expect(page.locator('.avery-service-row .pill')).toHaveText('Being serviced');await a('advance').click();await expect(page.locator('.avery-service-row .pill')).toHaveText('Ready');
 await page.locator('[data-action=close]').click();
 // Model an old saved pack without touching accepted server requests.
 await page.evaluate(()=>{const d=JSON.parse(localStorage.getItem('retail-offline-pack'));d.offer={key:'retail_launch_partner',introductory_monthly_cents:8900,ongoing_monthly_cents:14900};localStorage.setItem('retail-offline-pack',JSON.stringify(d));});
 await context.setOffline(true);await page.reload();await page.waitForSelector('.avery-launch');
 await page.locator('.avery-launch [data-avery-action=passport]').click();await expect(page.locator('.avery-passport')).toContainText('Ready');
 await a('owner').first().click();await expect(page.locator('.avery-interest')).toHaveCount(2);await a('advance').click();await expect(page.locator('.avery-service-row .pill')).toHaveText('Returned');
 await a('passport').first().click();await expect(page.locator('#modal-body')).toContainText('Returned to customer');
 await page.locator('[data-action=close]').click();await page.locator('[data-action=pricing]').first().click();await expect(page.locator('.offer-card')).toContainText('$299.00');await expect(page.locator('.offer-setup')).toContainText('$499.00');assert.ok(!/\$89|\$149|first 10|introductory/i.test(await page.locator('.pricing-page').innerText()));
 await page.screenshot({path:out+'/offline-standard-pricing.png',fullPage:true});
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);
 fs.writeFileSync(out+'/results.json',JSON.stringify({passed:true,models:products.length,variants:products.reduce((n,p)=>n+p.variants.length,0),exactVariantLink:exact,categoryBudgetFilters:true,duplicatePreferenceGuard:true,permissionExplicit:true,passportAndOwnerLinked:true,serviceHistory:true,offline:true,oldPackPriceUpgraded:true,layouts:['landscape','portrait','phone'],errors,externalRequests:external},null,2));
 console.log('PASS: Avery selection, variant QR, consent, linked service queue/history, reload/offline, old-price pack upgrade, and 3 responsive layouts; zero external requests.');
}finally{await browser.close();}
