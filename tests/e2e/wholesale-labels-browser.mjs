import {chromium} from 'playwright';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const theme=new URL('../../resources/shopify/wholesale-labels/theme/',import.meta.url);
const localeText=await fs.readFile(new URL('locales/en.default.json',theme),'utf8');const copy=JSON.parse(localeText.slice(localeText.indexOf('{'))).wholesale.labels;
const browser=await chromium.launch({headless:true});
for(const width of [1280,390]){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let counter=0,fail=true,prepared=0,savedInstructions='';
 let cart={attributes:{},items:[{variant_id:101,key:'101-a',quantity:3,product_title:'Wholesale Fictional candle',variant_title:'8oz Cotton Wick',properties:{'Gift note':'Keep this A'}},{variant_id:101,key:'101-b',quantity:3,product_title:'Wholesale Fictional candle',variant_title:'8oz Cotton Wick',properties:{'Gift note':'Keep this B'}},{variant_id:102,key:'102',quantity:6,product_title:'Wholesale Other candle',variant_title:'16oz Cotton Wick',properties:{'Delivery preference':'Keep this too'}}]};
 const label={id:'87654321-4321-4321-8321-cba987654321',name:'Fictional approved logo',preview:'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB1sAAAAASUVORK5CYII=',status:'approved'};
 const receipt='12345678-1234-4234-8234-123456789abc';
 await page.route('https://label-test.invalid/**',async route=>{
  const request=route.request(),url=new URL(request.url()),body=request.postDataJSON?.();
  const reply=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:'<html><body></body></html>'});
  if(url.pathname==='/cart.js')return reply(cart);
  if(url.pathname==='/apps/forestry/wholesale/labels')return reply({labels:[label]});
  if(url.pathname==='/apps/forestry/wholesale/labels/requests/'+receipt)return reply({mode:'reuse',label,bound:false,instructions:savedInstructions});
  if(url.pathname==='/apps/forestry/wholesale/labels/requests'){prepared++;savedInstructions=body.instructions;assert.equal(savedInstructions,'Fixture: preserve these label instructions.');assert.equal(body.mode,'reuse');assert.equal(body.groups.length,2);return reply({receipt,revision_id:label.id,variant_id:'42044189966417'});}
  if(url.pathname==='/cart/change.js'){
   const item=cart.items.find(i=>i.key===body.id);assert.ok(item,'Only fresh line keys can be mutated');
   if(fail&&item.variant_id===102){fail=false;return reply({message:'Recoverable fixture failure'},503);}
   if(body.quantity===0)cart.items=cart.items.filter(i=>i!==item);else {item.quantity=body.quantity;if(body.properties)item.properties=body.properties;}
   cart.items.forEach(i=>i.key=`${i.variant_id}:${++counter}`);return reply(cart);
  }
  if(url.pathname==='/cart/add.js'){assert.equal(body.items[0].quantity,1);cart.items.push({...body.items[0],variant_id:body.items[0].id,key:'fee:'+ ++counter,product_title:'Use My Custom Label',variant_title:'Standard'});return reply(cart);}
  if(url.pathname==='/cart/update.js'){cart.attributes={...cart.attributes,...body.attributes};return reply(cart);}
  throw new Error('Unexpected route '+url.pathname);
 });
 await page.goto('https://label-test.invalid/');
 await page.setContent(`<main id="MainContent"><button data-mf-label-open="reuse">Choose my label</button><div><button id="checkout" name="checkout">Checkout</button></div></main><script type="application/json" id="mf-label-config">${JSON.stringify({copy,setupVariant:'42044203761745',reuseVariant:'42044189966417',placeholder:label.preview,setupPhoto:label.preview})}</script>`);
 await page.addStyleTag({path:new URL('assets/mf-custom-labels.css',theme).pathname});
 await page.addScriptTag({path:new URL('assets/mf-custom-labels.js',theme).pathname});
 await page.locator('[data-mf-label-open]').click();await page.locator('[data-continue]').click();
 await page.locator('details summary').first().click();await page.locator('[data-group="1"]').uncheck();await page.locator('details summary').nth(1).click();await page.locator('[name=instructions]').fill('Fixture: preserve these label instructions.');await page.locator('[data-confirm]').click();
 await page.locator('[role=alert]').filter({hasText:'Recoverable fixture failure'}).waitFor();
 await page.locator('[data-confirm]').click();await page.waitForFunction(()=>!document.querySelector('dialog').open);
 assert.equal(prepared,2);assert.equal(cart.items.find(i=>i.properties['Gift note']==='Keep this A').properties['_MF label request'],receipt);
 assert.equal(cart.items.find(i=>i.properties['Gift note']==='Keep this B').properties['_MF label request'],undefined);
 assert.equal(cart.items.find(i=>i.variant_id===102).properties['Delivery preference'],'Keep this too');assert.equal(cart.items.find(i=>i.variant_id===42044189966417).quantity,1);
 await page.locator('[data-mf-label-checkout-summary] button').filter({hasText:copy.edit}).click();await page.locator('[data-continue]').click();assert.equal(await page.locator('[name=instructions]').inputValue(),'Fixture: preserve these label instructions.');assert.equal(await page.locator('[data-group="1"]').isChecked(),false);await page.locator('[data-close]').click();
 await page.locator('[data-mf-label-checkout-summary] button').filter({hasText:copy.remove}).click();await page.waitForFunction(()=>document.querySelector('[data-mf-label-checkout-summary]')&&!document.querySelector('[data-mf-label-checkout-summary]').textContent.includes('Fictional approved logo'));
 assert.equal(cart.items.length,3);assert.equal(cart.items.find(i=>i.properties['Gift note']==='Keep this A').properties['_MF label request'],'');assert.equal(cart.attributes['Custom labels'],'No');assert.deepEqual(errors,[]);await page.close();
}
await browser.close();console.log('PASS desktop/mobile: default saved label, chosen versus standard duplicate variants, changed line keys, recoverable partial retry, one-time fee, preserved unrelated properties, removal restores standard labels.');
