import {chromium} from 'playwright';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const source=await fs.readFile(new URL('../../resources/shopify/wholesale-labels/theme/sections/account-reorder-picker.liquid',import.meta.url),'utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replaceAll('{{ section.id }}','fixture');
const browser=await chromium.launch({headless:true}),page=await browser.newPage();
const items=[{legacy_variant_id:101,quantity:1,increment:3,title:'Fictional Pine',scent:'Fictional Pine',variant_title:'Wax Melt',properties:{'Gift note':'Keep it','_MF label request':'old-receipt','_MF label revision':'old-revision','_MF label mode':'reuse','Custom label':'Old artwork'}},{legacy_variant_id:102,quantity:1,increment:1,title:'Fictional Photo',scent:'Fictional Photo',variant_title:'Standard',properties:[['Photo instructions','Keep these']]}];
let added,cleared=false;
await page.route('https://reorder-test.invalid/**',async route=>{
 const url=new URL(route.request().url());
 const reply=data=>route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
 if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:'<html><body></body></html>'});
 if(url.pathname==='/products/wholesale-fictional-pine.js')return reply({title:'Wholesale Fictional Pine',variants:[{id:101,title:'Wax Melt',available:true}]});
 if(url.pathname==='/products/wholesale-fictional-photo.js')return reply({title:'Wholesale Fictional Photo',variants:[{id:102,title:'Standard',available:true}]});
 if(url.pathname==='/cart/clear.js'){cleared=true;return reply({items:[]});}
 if(url.pathname==='/cart/add.js'){added=route.request().postDataJSON().items;return reply({items:added});}
 if(url.pathname==='/cart')return route.fulfill({contentType:'text/html',body:'Fixture cart'});
 throw new Error('Unexpected QA route '+url.pathname);
});
await page.goto('https://reorder-test.invalid/');
await page.setContent('<form id="MFReorderForm-fixture"><input type="radio" name="reorder_order" data-order-item-count="2" data-order-name="QA"><button class="mf-reorder-submit">Refill cart</button><p class="mf-reorder-status"></p></form>');
await page.locator('input').evaluate((node,items)=>node.dataset.orderItems=JSON.stringify(items),items);
await page.addScriptTag({content:script});
assert.equal(cleared,false);await page.locator('button').click();await page.waitForURL('**/cart');
assert.equal(cleared,true);assert.equal(added[0].quantity,3);assert.equal(added[1].quantity,1);assert.deepEqual(added[0].properties,{'Gift note':'Keep it'});assert.deepEqual(added[1].properties,{'Photo instructions':'Keep these'});
await browser.close();console.log('PASS reorder: only explicit refill changes the cart; old wax-melt quantity 1 becomes 3; single-unit photo remains 1; unrelated properties survive; stale artwork receipt removed.');
