import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {salesTaxHtml} from '../../resources/js/trajectory/sales-tax.js';
const esc=x=>String(x??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
const precise=x=>x===null?'Unavailable':`$${(x/100).toFixed(2)}`;
const btn=(name,action,attrs='')=>`<button data-action="${action}" ${attrs}>${esc(name)}</button>`;
const report={confirmed_payment_cents:45123,returned_payment_cents:10000,refund_cents:0,net_remittance_cents:35123,notes:['Net cash does not establish tax liability.'],rows:[{transaction_id:42,date:'2028-07-20',account:'Example operating bank',agency:'Example Revenue',jurisdiction:'Sample state',tax_type:'sales_tax',movement:'payment',amount_cents:-45123,filing_start:'2028-06-01',filing_end:'2028-06-30',reviewed:true,bank_reviewed:true,source:'Example filing receipt',components:{tax_principal_cents:45000,interest_cents:123,penalty_cents:0,other_fees_cents:0}},{transaction_id:43,date:'2028-07-21',account:'Example operating bank',merchant:'Unknown agency <script>',tax_type:'unknown',movement:'unverified_credit',amount_cents:10000,reviewed:false,bank_reviewed:false}]};
const html=()=>salesTaxHtml({report,esc,precise,btn});
test('sales tax table preserves cash components unknown types periods and bank evidence IDs',()=>{
 const rendered=html();
 for(const text of ['Sales tax payments','Confirmed sales-tax remittance cash','$451.23','$100.00','$351.23','Tax $450.00','Interest $1.23','Unknown split','Unknown tax type','2028-06-01','data-ids="[42]"','tax-annotate'])assert.ok(rendered.includes(text),text);
 assert.ok(rendered.includes('&lt;script&gt;'));assert.ok(!rendered.includes('<script>'));
 const absent=salesTaxHtml({report:{rows:[],notes:[]},esc,precise,btn});assert.equal((absent.match(/Unavailable/g)||[]).length,4);assert.ok(!absent.includes('$0.00'));
});
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
