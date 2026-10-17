import {chromium,expect} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const base='http://127.0.0.1:8797', version='everbranch-retail-v6';
const photos=JSON.parse(fs.readFileSync('public/retail/photos.json','utf8'));
const files=fs.readdirSync('public/retail/assets').filter(name=>name.endsWith('.webp')).map(name=>'/retail/assets/'+name).sort();
assert.deepEqual(photos,files,'The offline manifest must cover every public demo photo');
const browser=await chromium.launch(process.env.RETAIL_QA_BROWSER_EXECUTABLE?{executablePath:process.env.RETAIL_QA_BROWSER_EXECUTABLE}:{});
const context=await browser.newContext({viewport:{width:600,height:960}});
let page=await context.newPage();
const errors=[];
const observe=p=>p.on('pageerror',error=>errors.push(error.message));observe(page);
try {
 const creds=JSON.parse(fs.readFileSync('storage/app/private/retail-local-access.json','utf8'));
 await page.goto(base+'/login');
 await page.locator('input[name="email"]').fill(creds.email);
 await page.locator('input[name="password"]').fill(creds.password);
 await page.locator('button[type="submit"]').click();
 await page.waitForURL(url=>!url.pathname.endsWith('/login'));
 await page.goto(base+'/retail/showroom');await page.waitForSelector('.vendor-grid');
 await page.locator('[data-action="offline"]').first().click();
 await page.waitForFunction(()=>!!localStorage.getItem('retail-offline-pack'),null,{timeout:60000});
 await expect.poll(()=>page.evaluate(async()=>{const registration=await navigator.serviceWorker.getRegistration('/retail/');return registration?.active?.state==='activated'&&!!navigator.serviceWorker.controller;}),{timeout:60000}).toBe(true);
 const pack=await page.evaluate(()=>JSON.parse(localStorage.getItem('retail-offline-pack')));
 assert.equal(pack.vendors.length,21);
 await expect.poll(()=>page.evaluate(async name=>{const cache=await caches.open(name);return !!await cache.match('/retail/photos.json');},version),{timeout:60000}).toBe(true);
 // Model an installed v4 pack. Close its final controlled client, then upgrade
 // without pressing Refresh: an app update itself must retain all offline photos.
 await page.evaluate(async name=>{
  await (await navigator.serviceWorker.getRegistration('/retail/'))?.unregister();
  const current=await caches.open(name),old=await caches.open('everbranch-retail-v4');
  for(const request of await current.keys())await old.put(request,await current.match(request));
  await old.put('/retail/showroom/api/research',new Response('PRIVATE_MUST_NOT_MIGRATE'));
  await caches.delete(name);
 },version);
 await page.close();await context.route('**/retail/assets/*.webp',route=>route.abort());page=await context.newPage();observe(page);
 await page.goto(base+'/retail/showroom');await page.waitForSelector('.vendor-grid');
 await expect.poll(()=>page.evaluate(async name=>{const cache=await caches.open(name);const keys=await caches.keys();return keys.includes(name)&&!keys.includes('everbranch-retail-v4')&&!!await cache.match('/retail/photos.json');},version),{timeout:60000}).toBe(true);
 await expect.poll(()=>page.evaluate(async()=>{const registration=await navigator.serviceWorker.getRegistration('/retail/');return registration?.active?.state==='activated'&&!!navigator.serviceWorker.controller;}),{timeout:60000}).toBe(true);
 const cacheState=await page.evaluate(async({name,photos})=>{
  const cache=await caches.open(name),requests=await cache.keys();
  return {missing:await Promise.all(photos.map(async path=>await cache.match(path)?null:path)).then(values=>values.filter(Boolean)),paths:requests.map(request=>new URL(request.url).pathname)};
 },{name:version,photos});
 assert.deepEqual(cacheState.missing,[],JSON.stringify(cacheState));
 assert.ok(!cacheState.paths.some(path=>path.includes('/api/')||path==='/retail/showroom'));
 await context.route('**/retail/showroom/api/bootstrap',()=>{});
 const stalledAt=Date.now();await page.reload();await page.waitForSelector('.offline-disclosure',{timeout:12000});
 assert.equal(await page.evaluate(()=>navigator.onLine),true);assert.ok(Date.now()-stalledAt<12000);
 await context.unroute('**/retail/showroom/api/bootstrap');
 await context.setOffline(true);
 await page.goto(base+'/retail/showroom');await page.waitForSelector('.vendor-grid');
 await page.locator('.vendor-photo img').evaluateAll(images=>images.forEach(image=>image.loading='eager'));
 await page.waitForFunction(()=>[...document.querySelectorAll('.vendor-photo img')].every(image=>image.complete&&image.naturalWidth>0));
 assert.equal(await page.locator('.vendor-photo img').count(),20);
 await page.screenshot({path:'output/retail/photo-repair/offline-home-portrait.jpg',type:'jpeg',quality:90});
 const checked=[];
 for(const vendor of pack.vendors){
  await page.goto(base+'/retail/showroom/concepts/'+vendor.slug);await page.waitForSelector('.product-card');
  await page.locator('.hero img,.products img,.store-story img').evaluateAll(images=>images.forEach(image=>image.loading='eager'));
  await page.waitForFunction(()=>[...document.querySelectorAll('.hero img,.products img,.store-story img')].every(image=>image.complete&&image.naturalWidth>0));
  await page.locator('.product-photo').first().click();await page.waitForSelector('.detail-grid img');
  await page.waitForFunction(()=>document.querySelector('.detail-grid img')?.naturalWidth>0);
  checked.push({slug:vendor.slug,productPhotos:vendor.products.length,detailPhoto:true});
  if(vendor.slug==='joy-fudge-company'){
   await page.screenshot({path:'output/retail/photo-repair/offline-product-portrait.jpg',type:'jpeg',quality:90});
   await page.setViewportSize({width:960,height:600});
   await page.screenshot({path:'output/retail/photo-repair/offline-product-landscape.jpg',type:'jpeg',quality:90});
   await page.setViewportSize({width:600,height:960});
  }
 }
 assert.deepEqual(errors,[]);
 const report={passed:true,cache:version,publicPhotos:photos.length,upgradeWithoutRefresh:true,stalledConnectionFallsBack:true,privateCacheExcluded:true,networkDisabled:true,homePhotos:20,storefronts:checked,errors};
 fs.writeFileSync('output/retail/photo-repair/results.json',JSON.stringify(report,null,2));
 console.log(JSON.stringify({passed:true,publicPhotos:photos.length,offlineStorefronts:checked.length,offlineProductPhotos:checked.reduce((n,v)=>n+v.productPhotos,0),upgradeWithoutRefresh:true,errors}));
} finally {await browser.close();}
