import {readFile} from 'node:fs/promises';
import {chromium,webkit,expect} from '@playwright/test';

const script=(await readFile(new URL('../../resources/js/trajectory/bills-home.js',import.meta.url),'utf8')).replace("import './bills-home.css';",'').replace('export function createBillsHome','window.createBillsHome = function');
const css=await readFile(new URL('../../resources/js/trajectory/bills-home.css',import.meta.url),'utf8');
for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
 const browser=await engine.launch();
 try{
  for(const width of [390,1440]){
   const page=await browser.newPage({viewport:{width,height:950},hasTouch:true}),errors=[];page.on('pageerror',error=>errors.push(error.message));
   await page.route('**/*',route=>route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:'<html><meta name="viewport" content="width=device-width, initial-scale=1"><body style="margin:0;font-family:sans-serif"><main id="home" style="max-width:1000px;margin:auto"></main><section id="detail" hidden><h1>Native saved details</h1></section></body></html>'}));
   await page.goto('https://trajectory.test/trajectory/personal/');await page.addStyleTag({content:css});await page.addScriptTag({content:script});
   await page.evaluate(()=>{
    window.report=null;window.calls=[];window.activeSpace=7;
    const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const precise=cents=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(cents/100);
    const detail=(action,row)=>{window.calls.push({action,key:row.key,version:row.version,evidence_ids:row.evidence_ids});history.pushState({opaque:crypto.randomUUID()},'',location.href);document.querySelector('#home').hidden=true;document.querySelector('#detail').hidden=false;};
    window.ui=window.createBillsHome({getReport:()=>window.report,isCurrent:report=>report.space_id===window.activeSpace,esc,precise,onEdit:row=>detail('edit',row),onEvidence:row=>detail('evidence',row),onManage:()=>window.calls.push({action:'manage'})});
    window.render=()=>{document.querySelector('#home').innerHTML=window.ui.render();};
    document.querySelector('#home').addEventListener('click',async event=>{const target=event.target.closest('[data-action]');if(target)await window.ui.handleClick(target.dataset.action,target);});
    window.addEventListener('popstate',()=>{document.querySelector('#home').hidden=false;document.querySelector('#detail').hidden=true;});
    window.render();
   });
   await expect(page.getByText('Bill details are loading in the background. Your charts are ready to explore.')).toBeVisible();
   await expect(page.getByText('$0.00',{exact:true})).toHaveCount(0);
   await page.evaluate(()=>{
    window.report={space_id:7,space_kind:'household',month:'2028-11',as_of:'2028-11-18',summary:{known_total_cents:165000,still_due_cents:20000,processing_cents:5000,paid_cents:140000,bank_cash_paid_cents:100000,estimated_cents:null,payment_review_cents:null,unknown_amount_count:1},coverage:{complete:false,reasons:['One current bill amount needs review.','Other obligations may not be entered.']},notes:['Giving commitments are part of the Giving budget, not an additional allowance. Savings goals are not bills.'],rows:[
     {key:'utility',name:'Example utility',kind:'utilities',record_id:1,record_kind:'recurring',version:3,amount_cents:20000,due_on:'2028-11-28',obligation_month:'2028-11',status:'due',source:'Fictional confirmed utility schedule',evidence_ids:[],reasons:[]},
     {key:'pending',name:'Example pending bill',kind:'subscriptions',record_id:2,record_kind:'recurring',version:1,amount_cents:5000,due_on:'2028-11-18',obligation_month:'2028-11',status:'processing',source:'Fictional exact bank-pending match',evidence_ids:[9],reasons:[]},
     {key:'sharing',name:'Example sharing contribution',kind:'health',record_id:3,record_kind:'bill_observation',version:2,amount_cents:60000,due_on:null,paid_on:'2028-11-17',obligation_month:'2028-11',service_month:'2028-11',status:'paid',source:'Fictional paid source',evidence_ids:[10],reasons:['Exact due date is not recorded.']},
     {key:'advance',name:'Power invoice paid in advance',kind:'utilities',record_id:4,record_kind:'bill_observation',version:1,amount_cents:80000,due_on:'2028-11-13',paid_on:'2028-10-27',obligation_month:'2028-11',service_month:null,status:'paid',source:'Fictional November-due invoice; source <img src=x onerror=window.injected=true>',evidence_ids:[11],reasons:[]},
     {key:'older-share',name:'Earlier-month sharing',kind:'health',record_id:5,record_kind:'bill_observation',version:1,amount_cents:40000,due_on:null,paid_on:'2028-11-17',obligation_month:'2028-10',service_month:'2028-10',carried_over:true,status:'paid',source:'Fictional October share paid in November',evidence_ids:[12],reasons:[]},
     {key:'annual',name:'Annual renewal to confirm',kind:'subscriptions',record_id:6,record_kind:'subscription',version:1,amount_cents:null,due_on:null,obligation_month:'2028-11',status:'unknown',source:'Fictional old annual receipt',evidence_ids:[],reasons:['Current renewal date and amount are not verified; no monthly annualization.']},
    ]};window.render();
   });
   await expect(page.locator('#home')).toContainText('Monthly cash total · known bills');await expect(page.locator('#home')).toContainText('$1,650.00');
   await expect(page.locator('#home')).toContainText('Partial · recorded obligations only');await expect(page.locator('#home')).toContainText('$1,000.00 in tracked bill payments actually posted');
   expect(await page.locator('body').evaluate(node=>node.scrollWidth)).toBeLessThanOrEqual(width);
   await page.locator('[data-key="utility"]').tap();const popup=page.getByRole('dialog');await expect(popup).toBeVisible();
   await expect(popup).toContainText('2028-11-28');await expect(popup).toContainText('Fictional confirmed utility schedule');
   await expect(page.getByRole('button',{name:'View recorded payment',exact:true})).toHaveCount(0);
   await page.getByRole('button',{name:'Review saved details',exact:true}).click();await expect(page.getByRole('heading',{name:'Native saved details'})).toBeVisible();
   expect((await page.evaluate(()=>window.calls)).at(-1)).toMatchObject({action:'edit',key:'utility',version:3});
   await page.goBack();await expect(page.locator('#home')).toBeVisible();await expect(popup).toHaveCount(0);
   await page.locator('.tr-home-bills-more > summary').filter({hasText:'Paid obligations'}).click();
   await page.locator('[data-key="advance"]').tap();await expect(popup).toContainText('2028-10-27');await expect(popup).toContainText('November 2028');
   await expect(popup.locator('img')).toHaveCount(0);expect(await page.evaluate(()=>window.injected)).toBeUndefined();
   await page.getByRole('button',{name:'View recorded payment',exact:true}).click();expect((await page.evaluate(()=>window.calls)).at(-1)).toMatchObject({action:'evidence',key:'advance',evidence_ids:[11]});
   await page.goBack();await page.locator('[data-key="annual"]').tap();await expect(popup).toContainText('Amount to confirm');await expect(popup).toContainText('no monthly annualization');
   await page.getByRole('button',{name:'Back to bills',exact:true}).click();await expect(popup).toHaveCount(0);
   const count=await page.evaluate(()=>window.calls.length);await page.locator('[data-key="pending"]').tap();await expect(popup).toBeVisible();
   await expect(popup).toContainText('Processing');await expect(page.getByRole('button',{name:'View recorded payment',exact:true})).toHaveCount(0);
   await page.evaluate(()=>{window.activeSpace=9;window.report={...window.report,space_id:9,space_kind:'business'};});
   await page.getByRole('button',{name:'Review saved details',exact:true}).click();await expect(popup).toHaveCount(0);expect(await page.evaluate(()=>window.calls.length)).toBe(count);
   await page.evaluate(()=>window.render());await page.locator('[data-key="utility"]').tap();await page.keyboard.press('Escape');await expect(popup).toHaveCount(0);
   await page.evaluate(()=>{window.activeSpace=11;});await page.locator('[data-key="utility"]').tap();await expect(popup).toHaveCount(0);
   await page.evaluate(()=>window.render());await expect(page.locator('#home')).not.toContainText('Example utility');
   await expect(page.locator('#home')).toContainText('Bill details are loading in the background.');
   expect(await page.locator('#home').innerText()).not.toContain('Unavailable');expect(errors).toEqual([]);await page.close();
   console.log(name+' '+width+': loading, calendar-month/paid-cash distinctions, partial and unknown reasons, native edit/evidence Back, stale scope and close passed');
  }
 }finally{await browser.close();}
}
