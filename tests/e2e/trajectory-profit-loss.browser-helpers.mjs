import assert from 'node:assert/strict';
import {expect} from '@playwright/test';
import {join} from 'node:path';
import {readFile} from 'node:fs/promises';

export async function verifyProfitLoss({page,fixture,width,name,evidence}){
  const origin=new URL(page.url()).origin;
  await page.goto(`${origin}/trajectory?space=${fixture.company_id}&tab=profit`);
  if(await page.locator('#tr-splash-skip').isVisible())await page.locator('#tr-splash-skip').click();
  await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
  const report=page.locator('.tr-pnl'),statement=report.locator('.tr-pnl-statement');
  await expect(report.locator('.tr-pnl-kpi')).toHaveCount(4);
  await expect(report.locator('.tr-pnl-kpi').last()).toContainText(fixture.pnl_net);
  await expect(statement).toContainText('Gross profit');await expect(statement).toContainText('Operating profit');
  await expect(statement.locator('#tr-pnl-net')).toContainText(fixture.pnl_net);
  await expect(report.locator('.tr-pnl-support')).not.toHaveAttribute('open','');
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'P&L fits the enhanced phone and desktop');
  await expect(report.locator('#tr-pnl-trend-chart')).toBeAttached();
  // Optional local-only baseline uses the prior renderer against the same fictional report.
  if(process.env.TRAJECTORY_PNL_BASELINE_SOURCE){
    const source=await readFile(process.env.TRAJECTORY_PNL_BASELINE_SOURCE,'utf8');
    const data=await(await page.request.get(`${origin}/trajectory/spaces/${fixture.company_id}/dashboard?range=year`)).json();
    const original=await page.locator('#tr-content').innerHTML();
    await page.evaluate(({source,data})=>{
      const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const amount=value=>value==null?'Unavailable':new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(value/100);
      const components=({report})=>`<details><summary>QuickBooks report components</summary>${[['cogs_cents','Product costs (COGS)'],['expenses_cents','Expenses component'],['other_expenses_cents','Other expenses'],['other_income_cents','Other income']].map(([key,label])=>`<div class="tr-row">${label}<strong>${amount(report[key])}</strong></div>`).join('')}</details>`;
      const factory=new Function('accountingComponentsHtml',source.replace(/^import .*\n/,'').replace('export function planningUI','function planningUI')+';return planningUI;')(components);
      document.querySelector('#tr-content').innerHTML=factory({getData:()=>data,esc,money:amount,precise:amount,panel:(heading,body)=>`<section class="tr-panel"><h2>${heading}</h2>${body}</section>`,btn:label=>`<button>${esc(label)}</button>`}).profit();
    },{source,data});
    await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:join(evidence,`${name}-${width}-profit-loss-before.png`),fullPage:true});
    await page.locator('#tr-content').evaluate((node,original)=>node.innerHTML=original,original);
    // Restore the real component and Chart.js event state after baseline capture.
    await page.reload();await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
  }
  await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:join(evidence,`${name}-${width}-profit-loss.png`),fullPage:true});
  await report.locator('.tr-pnl-kpis').screenshot({path:join(evidence,`${name}-${width}-profit-loss-kpis.png`),style:'.tr-toolbar{visibility:hidden!important}'});
  await statement.screenshot({path:join(evidence,`${name}-${width}-profit-loss-statement.png`),style:'.tr-toolbar{visibility:hidden!important}'});
  await verifyProfitAccountLedger({page,fixture,width,name,evidence,navigate:false});
  await page.locator('#tr-pnl-comparison').selectOption('previous_period');
  await expect(statement.locator('.tr-pnl-column-head')).toContainText('Previous period');
  await report.getByRole('button',{name:'Quarterly',exact:true}).click();await expect(report.getByRole('button',{name:'Quarterly',exact:true})).toHaveAttribute('aria-pressed','true');
  await report.getByRole('button',{name:'Annual',exact:true}).click();await expect(report.getByRole('button',{name:'Annual',exact:true})).toHaveAttribute('aria-pressed','true');
  await report.getByRole('button',{name:'Monthly',exact:true}).click();
  const legend=report.locator('.tr-chart-legend button').filter({hasText:'Revenue'}).first();
  await legend.click();await expect(legend).toHaveAttribute('aria-pressed','false');await legend.click();
  await report.locator('.tr-pnl-support > summary').click();
  for(const role of ['Owner payroll','Studio payroll','Event payroll'])await expect(report.locator('.tr-pnl-support')).toContainText(role);
  const studio=report.locator('.tr-pnl-support tr').filter({hasText:'Studio payroll'}),request=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
  await studio.getByRole('button',{name:'View records'}).click();
  assert.equal((await request).postDataJSON().economic_costs,true);
  await expect(page.getByRole('row').filter({hasText:'Fictional studio_payroll net pay'})).toContainText('-$234.56');
  await page.locator('#tr-back').click();await expect(report).toBeVisible();
  const basis=page.waitForRequest(request=>new URL(request.url()).pathname.endsWith('/dashboard')&&new URL(request.url()).searchParams.get('accounting_basis')==='cash');
  await report.getByRole('button',{name:'Cash',exact:true}).click();await basis;
  await expect(report.locator('.tr-pnl-kpi').first()).toContainText('$45,000.00');
  await expect(report.locator('.tr-pnl-kpi').last()).toContainText('$12,900.00');
  await report.getByRole('button',{name:'Accrual',exact:true}).click();await expect(report.locator('.tr-pnl-kpi').last()).toContainText(fixture.pnl_net);
  const answer=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).pathname.endsWith('/bud'));
  await report.getByRole('button',{name:'Why did our profitability change this period?',exact:true}).click();
  const body=await(await answer).json();assert.equal(body.local_financial_insight,true);assert.equal(body.ai.status,'local_source_calculation');
  await expect(report.locator('#tr-bud-thread')).toContainText('QuickBooks');
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  const exportBase=`${origin}/trajectory/spaces/${fixture.company_id}/profit-loss/export/`;
  const query='?range=year&accounting_basis=accrual&comparison=previous_period';
  const csv=await page.request.get(exportBase+'csv'+query);assert.equal(csv.status(),200);assert.match(await csv.text(),/"Net profit",total,0,14900\.00,/);assert.match(csv.headers()['cache-control'],/no-store/);
  const pdf=await page.request.get(exportBase+'pdf'+query);assert.equal(pdf.status(),200);assert.equal((await pdf.body()).subarray(0,5).toString(),'%PDF-');
  const print=await page.context().newPage();await print.setViewportSize({width,height:950});await print.goto(exportBase+'print'+query);
  await expect(print.getByText('Net profit',{exact:true})).toBeVisible();assert.ok(await print.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Printable statement fits phone/desktop');
  await print.screenshot({path:join(evidence,`${name}-${width}-profit-loss-print.png`),fullPage:true});await print.close();
  await verifyProfitReportRefresh({page,fixture});
}


export async function verifyProfitAccountLedger({page,fixture,width,name,evidence,navigate=true}){
  if(navigate){await page.goto(new URL(`/trajectory?space=${fixture.company_id}&tab=profit`,page.url()).href);if(await page.locator('#tr-splash-skip').isVisible())await page.locator('#tr-splash-skip').click();await page.locator('#tr-content:not([aria-busy="true"])').waitFor();}
  const report=page.locator('.tr-pnl'),modal=page.locator('#tr-dialog'),pattern='**/profit-loss/accounts/transactions?*';
  let mode='complete',release=null,requests=0;
  const base={snapshot_id:1,account_key:'sales',account:{id:'300',label:'Candle sales'},period:{start:fixture.pnl_year_start,end:fixture.today},basis:'accrual',currency:'USD',source:'QuickBooks General Ledger',statement_amount_cents:5050000,activity_cents:5050000,difference_cents:0,reconciles:true,status:'complete',notes:[],rows:[{date:fixture.today,transaction_type:'Invoice',document_number:'GL-1',name:'Fictional ledger customer',memo:'Candle sale <source>',amount_cents:6000000},{date:fixture.today,transaction_type:'Credit Memo',document_number:'GL-2',name:'Fictional ledger customer',memo:'Returned candles',amount_cents:-950000}]};
  await page.route(pattern,async route=>{
    requests++;assert.equal(route.request().method(),'GET');const query=new URL(route.request().url()).searchParams;assert.equal(query.get('account_key'),'sales');assert.ok(Number(query.get('snapshot_id'))>0);assert.equal(query.has('amount_cents'),false);
    if(mode==='delay')await new Promise(done=>{release=done;});
    if(mode==='error'){await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Accounting detail is temporarily unavailable.'})});return;}
    const result=mode==='partial'?{...base,status:'partial',reconciles:false,activity_cents:5049900,difference_cents:100,notes:['The live ledger differs from the saved statement. No adjustment has been created.']}:base;
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({...result,snapshot_id:Number(query.get('snapshot_id'))})});
  });
  try{
    mode='delay';await report.getByRole('button',{name:'Candle sales',exact:true}).click();await expect(modal).toContainText('Loading accounting transactions');
    await expect.poll(()=>Boolean(release)).toBe(true);mode='complete';release();
    await expect(modal).toContainText('Matches saved statement');await expect(modal).toContainText('$50,500.00');await expect(modal).toContainText('-$9,500.00');await expect(modal).toContainText('Fictional ledger customer');assert.equal(await modal.locator('source').count(),0);
    assert.ok(await modal.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Accounting detail fits dialog without horizontal overflow');
    await modal.screenshot({path:join(evidence,`${name}-${width}-profit-loss-account-ledger.png`)});
    await page.locator('#tr-close').click();
    mode='partial';await report.getByRole('button',{name:'Candle sales',exact:true}).click();await expect(modal).toContainText('Does not match saved statement');await expect(modal).toContainText('$1.00');await page.locator('#tr-close').click();
    mode='error';await report.getByRole('button',{name:'Candle sales',exact:true}).click();await expect(modal.getByRole('alert')).toContainText('temporarily unavailable');
    mode='complete';await modal.getByRole('button',{name:'Try again',exact:true}).click();await expect(modal).toContainText('Matches saved statement');await page.locator('#tr-close').click();
    mode='delay';release=null;await report.getByRole('button',{name:'Candle sales',exact:true}).click();await expect.poll(()=>Boolean(release)).toBe(true);await page.locator('#tr-close').click();mode='complete';release();await page.waitForTimeout(50);await expect(modal).not.toBeVisible();
    assert.equal(requests,5);
  }finally{await page.unroute(pattern);}
}

export async function verifyProfitReportRefresh({page,fixture}){
  const report=page.locator('.tr-pnl'),pattern='**/profit-loss/refresh';
  let mode='error',calls=0;
  await page.route(pattern,async route=>{
    calls++;assert.equal(route.request().method(),'POST');const body=route.request().postDataJSON();assert.equal(body.from,fixture.pnl_year_start);assert.equal(body.through,fixture.today);assert.equal(body.accounting_basis,'accrual');assert.ok(Number(body.snapshot_id)>0);assert.deepEqual(Object.keys(body).sort(),['accounting_basis','from','snapshot_id','through']);
    await route.fulfill({status:mode==='error'?503:200,contentType:'application/json',body:JSON.stringify(mode==='error'?{message:'The source report is temporarily unavailable.'}:{ok:true,period:{start:body.from,end:body.through},basis:body.accounting_basis,snapshot_id:body.snapshot_id})});
  });
  try{
    assert.equal(calls,0);await expect(report.getByRole('button',{name:'Refresh report',exact:true})).toBeVisible();
    await report.getByRole('button',{name:'Refresh report',exact:true}).click();await expect(report.locator('#tr-pnl-refresh-status')).toContainText('temporarily unavailable');await expect(report.locator('.tr-pnl-kpi').last()).toContainText(fixture.pnl_net);
    mode='success';const reload=page.waitForRequest(request=>request.method()==='GET'&&new URL(request.url()).pathname.endsWith('/dashboard'));
    await report.getByRole('button',{name:'Refresh report',exact:true}).click();await reload;await expect(report.locator('#tr-pnl-refresh-status')).toContainText('accounting report has been loaded');assert.equal(calls,2);
    await page.route('**/dashboard?*',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'The refreshed screen is temporarily unavailable.'})}),{times:1});
    await report.getByRole('button',{name:'Refresh report',exact:true}).click();await expect(report.locator('#tr-pnl-refresh-status')).toContainText('screen could not reload');await expect(report.locator('.tr-pnl-kpi').last()).toContainText(fixture.pnl_net);
    await report.getByRole('button',{name:'Refresh report',exact:true}).click();await expect(report.locator('#tr-pnl-refresh-status')).toContainText('accounting report has been loaded');assert.equal(calls,4);
  }finally{await page.unroute(pattern);}
}
