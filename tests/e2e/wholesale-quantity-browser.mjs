import {chromium} from 'playwright';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const theme=new URL('../../resources/shopify/wholesale-labels/theme/',import.meta.url),browser=await chromium.launch({headless:true});
const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.route('https://quantity-test.invalid/**',r=>r.fulfill({contentType:'text/html',body:'<html><body></body></html>'}));await page.goto('https://quantity-test.invalid/');
await page.setContent(`<div id="MainContent"><table><tbody><tr class="cart-item"><td class="cart-item__details"></td><td><quantity-input><button name="minus" class="quantity__button">−</button><input type="number" class="quantity__input" min="3" data-min="3" step="3" value="1"><button name="plus" class="quantity__button"><span>+</span></button></quantity-input></td></tr></tbody></table><button name="checkout" id="checkout">Checkout</button></div><script id="mf-quantity-config" type="application/json">{"message":"Order in multiples of 3."}</script>`);
await page.evaluate(()=>{window.PUB_SUB_EVENTS={quantityUpdate:'quantity'};window.subscribe=()=>()=>{};window.checkoutCalls=0;document.querySelector('#checkout').addEventListener('click',()=>window.checkoutCalls++);});
const global=await fs.readFile(new URL('assets/global.js',theme),'utf8');const a=global.indexOf('class QuantityInput extends HTMLElement'),b=global.indexOf("customElements.define('quantity-input', QuantityInput);");assert.ok(a>0&&b>a);await page.addScriptTag({content:global.slice(a,b)+"customElements.define('quantity-input',QuantityInput);"});
await page.addScriptTag({path:new URL('assets/mf-wholesale-quantities.js',theme).pathname});
await page.locator('#checkout').click();assert.equal(await page.evaluate(()=>window.checkoutCalls),0);assert.equal(await page.locator('input').inputValue(),'1');assert.equal(await page.locator('[data-mf-quantity-warning]').innerText(),'Order in multiples of 3.');
await page.locator('button[name=plus] span').click();assert.equal(await page.locator('input').inputValue(),'3');await page.locator('button[name=plus] span').click();assert.equal(await page.locator('input').inputValue(),'6');await page.locator('button[name=minus]').click();assert.equal(await page.locator('input').inputValue(),'3');
await page.locator('#checkout').click();assert.equal(await page.evaluate(()=>window.checkoutCalls),1);
await page.evaluate(()=>{window.routes={cart_add_url:'/cart/add.js',cart_url:'/cart'};window.fetchConfig=()=>({headers:{}});window.publish=()=>{};window.added=[];window.fetch=async(url,config)=>{window.added.push({id:config.body.get('id'),quantity:config.body.get('quantity')});return {json:async()=>({status:422,description:'Fixture: no real cart changed'})};};});
await page.addScriptTag({path:new URL('assets/product-form.js',theme).pathname});
for(const [step,value,expected] of [[3,'1',null],[3,'4',null],[3,'3','3'],[3,null,'3'],[1,null,'1']]){
 await page.evaluate(({step,value})=>{document.querySelector('product-form')?.remove();const node=document.createElement('div');node.innerHTML=`<product-form data-hide-errors="false"><div class="product-form__error-message-wrapper" hidden><span class="product-form__error-message"></span></div><form data-mf-order-increment="${step}"><input name="id" value="101" class="product-variant-id">${value===null?'':`<input name="quantity" value="${value}">`}<button type="submit"><span>Add</span><span class="loading__spinner hidden"></span></button></form></product-form>`;document.body.append(node.firstElementChild);}, {step,value});
 const before=await page.evaluate(()=>window.added.length);await page.locator('product-form button').click();await page.waitForTimeout(30);const added=await page.evaluate(()=>window.added);assert.equal(added.length,before+(expected===null?0:1));if(expected)assert.equal(added.at(-1).quantity,expected);
}
// Resolve current keys before bulk edits; duplicate variants need the cart's separate controls.
const bulkStart=global.indexOf('class BulkAdd extends HTMLElement');
await page.addScriptTag({content:global.slice(bulkStart)});
await page.evaluate(()=>{window.cartStrings={error:'Cart unavailable'};window.fixtureCart={items:[{variant_id:101,key:'101:fresh',properties:{gift:'Retain'}}]};window.fetch=async()=>({ok:true,json:async()=>window.fixtureCart});document.body.append(document.createElement('bulk-add'));});
assert.deepEqual(await page.locator('bulk-add').evaluate(node=>node.currentLineUpdates({'101':6,'102':3})),{'101:fresh':6,'102':3});
await page.evaluate(()=>window.fixtureCart.items.push({variant_id:101,key:'101:other',properties:{gift:'Other'}}));
assert.match(await page.locator('bulk-add').evaluate(async node=>{try{await node.currentLineUpdates({'101':6});return '';}catch(e){return e.message;}}),/preserve its separate selections/);
assert.deepEqual(errors,[]);await browser.close();console.log('PASS: existing quantity 1 stays untouched and blocks checkout; nested +/- buttons step 3; invalid additions rejected; regular additions 3; service additions 1.');
