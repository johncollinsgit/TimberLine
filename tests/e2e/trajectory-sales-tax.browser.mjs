import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {html} from './trajectory-sales-tax-fixture.mjs';
test('sales tax view is legible and keyboard usable at desktop and mobile widths',async()=>{
 const {chromium}=await import('playwright');
 const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
 const browser=await chromium.launch({headless:true,...(executablePath?{executablePath}:{})});
 try{
  const css=await fs.readFile(new URL('../../resources/js/trajectory/app.css',import.meta.url),'utf8');
  for(const width of [390,1440]){
   const page=await browser.newPage({viewport:{width,height:1000}});
   await page.setContent(`<style>${css}</style><main class="tr-app">${html()}</main>`);
   assert.equal(await page.getByRole('table').count(),1);
   assert.equal(await page.getByRole('columnheader').count(),9);
   const whole=await page.evaluate(()=>({doc:document.documentElement.scrollWidth,width:innerWidth}));assert.ok(whole.doc<=whole.width+1,JSON.stringify(whole));
   await page.keyboard.press('Tab');assert.equal(await page.evaluate(()=>document.activeElement.textContent),'Add tax payment details');
   await page.getByRole('button',{name:'View bank transaction'}).first().click();assert.equal(await page.locator('button:focus').getAttribute('data-ids'),'[42]');
   await page.locator('.tr-table-wrap').evaluate(element=>element.scrollLeft=0);
   if(process.env.TRAJECTORY_VISUAL_DIR)await page.screenshot({path:`${process.env.TRAJECTORY_VISUAL_DIR}/sales-tax-${width}.png`,fullPage:true});
   await page.close();
  }
 }finally{await browser.close();}
});
