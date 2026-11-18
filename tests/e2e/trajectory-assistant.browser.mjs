import {readFile} from 'node:fs/promises';
import {chromium,webkit} from 'playwright';
import {expect} from '@playwright/test';

const script=(await readFile(new URL('../../resources/js/trajectory/assistant-ui.js',import.meta.url),'utf8')).replace("import './assistant-ui.css';",'').replace('export function initTrajectoryAssistant','window.initTrajectoryAssistant = function');
const css=await readFile(new URL('../../resources/js/trajectory/assistant-ui.css',import.meta.url),'utf8');
for(const engine of [chromium,webkit]){
 const browser=await engine.launch({headless:true});
 for(const width of [390,1440]){
  const page=await browser.newPage({viewport:{width,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>route.fulfill({status:200,contentType:'text/html',body:'<html><body><main id="trajectory"></main></body></html>'}));
  await page.goto('https://trajectory.test/trajectory/');await page.addStyleTag({content:css});await page.addScriptTag({content:script});
  await page.evaluate(()=>{
   window.requests=[];window.changed=0;window.ctx={space_id:7,from:'2027-02-01',through:'2027-02-12',level:'transactions',basis:'spending',transaction_ids:[31],transaction_versions:{31:2},focused_transaction:true};
   window.api=async(path,method='GET',payload)=>{window.requests.push({path,method,payload});if(path.endsWith('/notifications'))return{rows:[{id:8,payload:{transaction_id:31,version:2,date:'2027-02-12',merchant:'Example purchase',amount_cents:1234,category:'shopping',category_label:'Shopping',pending:true,remaining_cents:null,needs_purpose:true}}],unread_count:1,preferences:{enabled:true,include_pending:true,show_details:true},push:{ready:false}};if(path.endsWith('/assistant')&&method==='GET')return{rows:[]};if(path.endsWith('/assistant')&&method==='POST')return{id:11,reply:'Saved this transaction to Groceries.',applied:[{transaction_id:31,version:3}],undo:{available:true}};if(path.endsWith('/assistant/11/undo'))return{id:11,reply:'Undid the categorization.',undo:{available:false}};return{ok:true};};
   window.assistant=window.initTrajectoryAssistant({root:document.querySelector('#trajectory'),getContext:()=>window.ctx,api:window.api,endpoint:(path,space)=>`/trajectory/spaces/${space}${path}`,onChanged:()=>{window.changed++;}});
  });
  await page.getByRole('button',{name:/Ask Everbranch/}).click();await expect(page.getByRole('dialog')).toBeVisible();
  await page.locator('#tr-assistant-question').fill('Move this to groceries');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.getByText('Saved this transaction to Groceries.')).toBeVisible();await expect(page.getByRole('button',{name:'Undo',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Undo',exact:true}).click();await expect(page.getByText('Undid the categorization.')).toBeVisible();
  await page.getByRole('button',{name:'Expenses',exact:true}).click();await expect(page.getByRole('button',{name:/Example purchase/})).toBeVisible();
  await page.getByRole('button',{name:/Example purchase/}).click();await expect(page.getByText(/What was this purchase for/)).toBeVisible();
  await page.locator('#tr-assistant-question').fill('groceries');await page.getByRole('button',{name:'Send',exact:true}).click();
  const captured=await page.evaluate(()=>({changed:window.changed,requests:window.requests.filter(r=>r.method==='POST'&&r.path.endsWith('/assistant')),width:document.querySelector('dialog').getBoundingClientRect().width,viewport:window.innerWidth}));
  expect(captured.changed).toBe(3);expect(captured.requests[0].payload.context.focused_transaction).toBe(true);expect(captured.requests[1].payload.context.transaction_versions['31']).toBe(2);expect(captured.width).toBeLessThanOrEqual(captured.viewport);
  expect(errors).toEqual([]);await page.close();
 }
 await browser.close();
}
console.log('Ask Everbranch: explicit category save, Undo, inbox reply and responsive dialog passed Chromium/WebKit at 390/1440px.');
