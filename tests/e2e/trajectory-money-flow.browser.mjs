import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit} from 'playwright';
import {expect} from '@playwright/test';
import {verifyProfitLoss} from './trajectory-profit-loss.browser-helpers.mjs';
import {openReportControls,closeReportControls,selectReportRange,selectReportAccount,selectReportScope} from './trajectory-controls.browser-helpers.mjs';
const exec=promisify(execFile),base=resolve(),probe=createServer();
await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;await new Promise(done=>probe.close(done));
const dir=await mkdtemp(join(tmpdir(),'codex-money-flow-')),origin=`http://127.0.0.1:${port}`,evidence=resolve(process.env.TRAJECTORY_FLOW_EVIDENCE||'test-results/trajectory-money-flow');
await mkdir(evidence,{recursive:true});await writeFile(join(dir,'db.sqlite'),'');
const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:origin,APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',APP_CONFIG_CACHE:join(dir,'config.php'),DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'db.sqlite'),DB_URL:'',DATABASE_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',SESSION_SECURE_COOKIE:'false',SESSION_DOMAIN:'',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',LOG_CHANNEL:'null',BCRYPT_ROUNDS:'4',TRAJECTORY_ENABLED:'true',TRAJECTORY_AI_ENABLED:'false',NIGHTWATCH_ENABLED:'false',TELESCOPE_ENABLED:'false',PULSE_ENABLED:'false'};
let server,browser;const checks=[];
try{
 await exec('php',['artisan','migrate','--force','--no-interaction'],{cwd:base,env,maxBuffer:2e6});
 const fixture=JSON.parse((await exec('php',['scripts/trajectory/profit-loss-browser-fixture.php'],{cwd:base,env})).stdout);
 server=spawn('php',['artisan','serve','--host=127.0.0.1',`--port=${port}`,'--no-reload'],{cwd:base,env,stdio:'ignore'});
 let ready=false;for(let i=0;i<100;i++){try{if((await fetch(origin+'/up')).ok){ready=true;break;}}catch{}await new Promise(done=>setTimeout(done,100));}assert.ok(ready);
 for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
  browser=await engine.launch();const context=await browser.newContext({reducedMotion:'reduce'});
  await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const csrf=(await(await context.request.get(origin+'/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];assert.ok(csrf);
  assert.ok((await context.request.post(origin+'/login',{form:{email:'flow@fixture.invalid',password:'Fictional-flow-only-2028!',_token:csrf},headers:{Accept:'application/json'}})).ok());
  const page=await context.newPage(),errors=[],analysisReads=[],dashboardReads=[],budgetReads=[];page.on('pageerror',error=>errors.push(error.message));page.setDefaultTimeout(12000);
  page.on('request',request=>{if(request.method()!=='GET')return;const path=new URL(request.url()).pathname;if(path.endsWith('/analysis'))analysisReads.push(request.url());if(path.endsWith('/dashboard'))dashboardReads.push(request.url());if(path.endsWith('/budget-envelopes'))budgetReads.push(request.url());});
  // Exercise the actual app's minute callback without sleeping or depending on
  // wall-clock timing. Other timers and all authenticated reads remain real.
  await page.addInitScript(()=>{window.trajectoryMinuteReads=[];const original=window.setInterval.bind(window);window.setInterval=(callback,delay,...args)=>{if(delay===60000){window.trajectoryMinuteReads.push(()=>callback(...args));return original(()=>{},delay);}return original(callback,delay,...args);};});
  for(const width of [390,1440]){
   await page.setViewportSize({width,height:950});await page.goto(origin+'/fictional-prior-404');await page.goto(origin+'/trajectory');
   if(await page.locator('#tr-splash-skip').isVisible())await page.locator('#tr-splash-skip').click();
   await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
   await expect(page.locator('#tr-add')).toBeEnabled(); // Native-data tests begin after progressive visuals have hydrated.
   const minuteRead=()=>page.evaluate(()=>window.trajectoryMinuteReads.forEach(callback=>callback()));
   const initialReads=analysisReads.length;
   await openReportControls(page);await page.locator('#tr-account-filter').focus();await minuteRead();
   await page.waitForTimeout(60);assert.equal(analysisReads.length,initialReads,'Focused account control suppresses the app background read');
   await closeReportControls(page);await page.locator('.tr-assistant-launch').click();await minuteRead();
   await page.waitForTimeout(60);assert.equal(analysisReads.length,initialReads,'Open context-aware Ask suppresses the app background read');
   await page.locator('[data-assistant-close]').click();await page.evaluate(()=>document.activeElement.blur());
   const oldHome=await page.locator('#tr-content > .vf-finance').elementHandle(),historyBefore=await page.evaluate(()=>({state:history.state,length:history.length,scroll:scrollY}));
   await minuteRead();await expect.poll(()=>analysisReads.length).toBe(initialReads+1);
   await expect.poll(()=>oldHome.evaluate(node=>node.isConnected)).toBe(false);
   assert.deepEqual(await page.evaluate(()=>({state:history.state,length:history.length,scroll:scrollY})),historyBefore,'An actual foreground refresh preserves browser Back metadata and position');
   if(width<700){assert.ok(await page.locator('#tr-content').evaluate(node=>node.getBoundingClientRect().top<140),'Saved figures appear immediately below the compact phone header');await page.screenshot({path:join(evidence,`${name}-${width}-compact-home.png`)});}
   const navigateSection=async label=>{
    if(width<700&&await page.locator('#tr-mobile-menu').getAttribute('aria-expanded')!=='true')await page.locator('#tr-mobile-menu').click();
    const drawer=page.locator('#tr-mobile-drawer'),button=drawer.locator('[data-tab]').filter({hasText:label});
    if(await button.evaluate(node=>Boolean(node.closest('.tr-nav-tools:not([open])'))))await drawer.locator('.tr-nav-tools > summary').click();
    await button.click();
   };
   // Real visuals finish before the small editing context. Primary navigation
   // at that boundary must reuse the in-flight metadata, without a Dashboard.
   let held=false,releaseProjection;const projectionGate=new Promise(resolve=>{releaseProjection=resolve;});
   const holdProjection=async route=>{if(!held&&new URL(route.request().url()).searchParams.get('account_id')===String(fixture.other_cash_id)){held=true;await projectionGate;}await route.continue();};
   await page.route('**/visual-context*',holdProjection);
   const dashboardBeforePending=dashboardReads.length;
   await selectReportAccount(page,fixture.other_cash_id);await expect.poll(()=>held).toBe(true);
   await expect(page.locator('.vf-finance')).toBeVisible();await expect(page.locator('#tr-add')).toBeDisabled();
   await navigateSection('Analysis');await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
   await expect(page.locator('#tr-page-title')).toHaveText('Analysis');
   assert.equal(dashboardReads.length,dashboardBeforePending,'A primary account change never starts the legacy Dashboard');
   const delayedResponse=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/visual-context')&&new URL(response.url()).searchParams.get('account_id')===String(fixture.other_cash_id));
   releaseProjection();await delayedResponse;await page.unroute('**/visual-context*',holdProjection);await expect(page.locator('#tr-add')).toBeEnabled();
   assert.equal(await page.locator('#tr-account-filter').inputValue(),String(fixture.other_cash_id));
   await selectReportAccount(page,'');await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
   await navigateSection('Home');await expect(page.locator('#tr-page-title')).toHaveText('Home');
   const readCounts=()=>[dashboardReads.length,analysisReads.length,budgetReads.length],loadedCounts=readCounts();
   for(const [section,title] of [['Analysis','Analysis'],['Budget','Your budget'],['Home','Home']]){await navigateSection(section);await expect(page.locator('#tr-page-title')).toHaveText(title);}
   assert.deepEqual(readCounts(),loadedCounts,'Home → Analysis → Budget → Home reuses one authorized snapshot without backend reads');
   await page.goBack();await expect(page.locator('#tr-page-title')).toHaveText('Your budget');
   await page.goBack();await expect(page.locator('#tr-page-title')).toHaveText('Analysis');
   assert.deepEqual(readCounts(),loadedCounts,'Back between equivalent primary views reuses the same projection');
   // Save the same confirmed purpose through the real editor. The amounts and
   // fixture categories stay unchanged, but its new source revision must reload.
   await navigateSection('Review transactions');
   if(width<700)await page.getByRole('row').filter({hasText:'Fictional employer'}).locator('.tr-list-merchant-mobile').click();
   await (width<700?page.locator('.tr-transaction-detail-dialog'):page.getByRole('row').filter({hasText:'Fictional employer'})).getByRole('button',{name:/^(Edit category|Categorize)$/}).click();
   const beforeMutation=dashboardReads.length;
   const mutationDashboard=page.waitForRequest(request=>request.method()==='GET'&&new URL(request.url()).pathname.endsWith('/dashboard'));
   await page.locator('#tr-save').click();await mutationDashboard;
   await expect(page.locator('#tr-dialog')).not.toBeVisible();await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
   assert.equal(dashboardReads.length,beforeMutation+1,'A successful legacy transaction edit forces a new dashboard revision');
   const beforeVisualOnly=readCounts(),freshProjection=page.waitForRequest(request=>new URL(request.url()).pathname.endsWith('/analysis'));
   await navigateSection('Analysis');await freshProjection;await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
   assert.equal(dashboardReads.length,beforeVisualOnly[0],'A missing visual revision fetches only Analysis/Budget, not the large dashboard');
   assert.equal(analysisReads.length,beforeVisualOnly[1]+1);assert.equal(budgetReads.length,beforeVisualOnly[2]+1);
   await page.locator('#vf-period-chart').waitFor();
   if(width<700){
    await expect(page.locator('#tr-content .vf-controls')).toHaveCount(0);
    await openReportControls(page);await expect(page.locator('#tr-analysis-controls .vf-controls')).toBeVisible();
    await page.locator('[data-action="vf-grain"][data-grain="week"]').click();
    await expect(page.locator('[data-action="vf-grain"][data-grain="week"]')).toHaveAttribute('aria-pressed','true');
    await expect(page.locator('#tr-content>.vf-finance')).toHaveAttribute('aria-busy','false');
    await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
    await page.screenshot({path:join(evidence,`${name}-${width}-date-popover.png`)});
    await closeReportControls(page);await page.screenshot({path:join(evidence,`${name}-${width}-compact-analysis.png`)});
    const beforeBack=readCounts();await page.locator('#tr-back').click();
    await expect(page.locator('[data-action="vf-grain"][data-grain="month"]')).toHaveAttribute('aria-pressed','true');
    await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
    assert.equal(dashboardReads.length,beforeBack[0],'Restoring another visual period/grain never reloads the dashboard');
    assert.equal(analysisReads.length,beforeBack[1]+1,'A different restored projection is fetched, rather than relabeling stale data');
   }else await expect(page.locator('#tr-content .vf-controls')).toBeVisible();
   // The compact Home is covered by the visual-finance browser. Preserve this
   // older forecast's lazy-chart and details coverage under its new tool entry.
   await navigateSection('Detailed forecast');
   await expect(page.locator('#tr-overview-explore')).not.toHaveAttribute('open','');
   await expect(page.getByRole('heading',{name:'What does your income need to cover?'})).toBeVisible();
   await page.locator('#tr-overview-explore > summary').click();
   await expect(page.locator('#tr-overview-explore')).toHaveAttribute('open','');
   await expect(page.locator('#tr-cashflow')).toBeVisible();
   await page.locator('#tr-overview-explore > summary').click();
   await expect(page.locator('#tr-overview-explore')).not.toHaveAttribute('open','');
   await page.evaluate(()=>scrollTo(0,0));
   await page.screenshot({path:join(evidence,`${name}-${width}-overview.png`),fullPage:true});
   await navigateSection('Money flow');
   await page.getByRole('heading',{name:'Follow your money'}).waitFor();
   await page.getByRole('heading',{name:'Last year and the monthly trend'}).waitFor();
   await expect(page.locator('#tr-flow-trend')).not.toBeAttached(); // Unknown tax cost prevents a fabricated monthly economic total.
   await expect(page.getByRole('button',{name:'Spending',exact:true})).toHaveAttribute('aria-pressed','true');
   await expect(page.locator('.tr-tax-economic-warning')).toContainText('Economic totals unavailable');
   await expect(page.locator('.tr-money-flow > .tr-grid')).toContainText('Unavailable');
   await page.getByText('All flow amounts and transactions',{exact:true}).click();
   const taxInterest=page.getByRole('row').filter({hasText:'Outgoing · Tax interest'});
   const taxRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await taxInterest.getByRole('button',{name:'View transactions',exact:true}).click();
   const taxSent=(await taxRequest).postDataJSON();assert.equal(taxSent.economic_costs,true);assert.equal(taxSent.economic_category,'interest');
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
   const taxEvidence=page.getByRole('row').filter({hasText:'Fictional known sales-tax cash'});
   await expect(taxEvidence).toContainText('-$2.00');await expect(taxEvidence).toContainText('Bank amount -$100.00');
   await expect(taxEvidence).toContainText('Shown: Interest');await expect(taxEvidence).toContainText('Sales-tax principal $90.00');
   await page.locator('#tr-back').click();
   assert.equal(await page.getByText('All flow amounts and transactions',{exact:true}).evaluate(node=>node.parentElement.open),false);
   await page.evaluate(()=>scrollTo(0,0));
   await page.screenshot({path:join(evidence,`${name}-${width}-tax-spending.png`),fullPage:true});

   assert.ok(await page.getByText('Medical sharing · bill reimbursements',{exact:false}).count());
   assert.ok(await page.getByText('Needs review · incoming',{exact:false}).count());
   const diagram=page.locator(width<700?'.tr-flow-phone':'.tr-flow-desktop');
   await expect(diagram).toBeVisible();
   await expect(page.locator('.tr-flow-payroll-note')).toContainText('not total employer cost');
   for(const label of ['Owner payroll','Studio Payroll','Event Payroll','Payroll'])await expect(diagram.locator(`[data-label="${label}"]`)).toBeVisible();
   if(width<700){assert.ok(await diagram.evaluate(node=>node.scrollWidth<=node.clientWidth+1));await expect(diagram.locator('text').filter({hasText:'Where it went'})).toBeVisible();}
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await page.getByRole('button',{name:'Cash movement',exact:true}).click();
   await expect(page.getByRole('button',{name:'Cash movement',exact:true})).toHaveAttribute('aria-pressed','true');
   await expect(page.locator('#tr-flow-trend')).toBeAttached();
   if(width<700){const cards=await page.locator('.tr-money-flow > .tr-grid > .tr-metric').evaluateAll(nodes=>nodes.map(node=>({top:node.getBoundingClientRect().top,height:node.getBoundingClientRect().height})));assert.equal(cards.length,3);assert.ok(cards.every(card=>Math.abs(card.top-cards[0].top)<1&&card.height<100),'Phone totals stay in one compact row');}
   const openMerchant=async(label,category,keyboard=false)=>{
    const categoryNode=diagram.locator(`[data-action="money-flow-category"][data-label="${category}"]`);
    let node=width<700?(await categoryNode.count()?categoryNode:diagram.locator('[data-action="money-flow-category"][data-label="Other outgoing categories"]')):diagram.locator('.tr-flow-action').filter({has:page.locator('title').filter({hasText:label})});
    if(width>=700&&!await node.count())node=diagram.locator('[data-action="money-flow-evidence"][data-label="Other outgoing categories"]'); // Aggregated categories still open every underlying source.
    if(keyboard){await node.first().focus();await page.keyboard.press('Enter');}else await node.first().click();
    if(width<700){await expect(page.locator('#tr-dialog')).toBeVisible();await page.locator('#tr-dialog .tr-row').filter({hasText:label}).getByRole('button',{name:'View transactions',exact:true}).click();}
   };
   const request=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await openMerchant('Fictional shared cost','Shopping',true);const sent=await request;
   assert.equal(sent.postDataJSON().cash_movement,true);assert.deepEqual(sent.postDataJSON().ids,[fixture.zero_id]);
   const zeroRow=page.getByRole('row').filter({hasText:'Fictional shared cost'});
   await expect(zeroRow).toContainText('$770.00');await expect(zeroRow).toContainText('Economic allocation $0.00');
   await page.locator('#tr-back').click();await page.getByRole('heading',{name:'Follow your money'}).waitFor();if(width<700){await expect(page.locator('#tr-dialog')).toBeVisible();await page.locator('#tr-close').click();await expect(page.locator('#tr-dialog')).not.toBeVisible();}
   await openMerchant('Fictional partial cost','Transport');
   const partial=page.getByRole('row').filter({hasText:'Fictional partial cost'});
   await expect(partial).toContainText('$100.00');await expect(partial).toContainText('Economic allocation -$25.00');
   await page.locator('#tr-back').click();await page.getByRole('heading',{name:'Follow your money'}).waitFor();if(width<700){await expect(page.locator('#tr-dialog')).toBeVisible();await page.locator('#tr-close').click();await expect(page.locator('#tr-dialog')).not.toBeVisible();}
   await page.getByText(/^Excluded and zero-amount records/).click();
   await page.getByRole('row').filter({hasText:'Internal transfers'}).getByRole('button',{name:'View transactions',exact:true}).click();
   await expect(page.getByRole('row').filter({hasText:'Fictional internal transfer'})).toContainText('-$123.45');
   await expect(page.getByRole('row').filter({hasText:'Fictional internal transfer'})).toContainText('Economic allocation $0.00');
   await page.locator('#tr-back').click();
   const trend=page.locator('#tr-flow-trend');await trend.scrollIntoViewIfNeeded();let point;
   await expect.poll(async()=>{point=await trend.evaluate(node=>{const ctx=node.getContext('2d'),data=ctx.getImageData(0,0,node.width,node.height).data,box=node.getBoundingClientRect();let left=node.width,right=0,top=node.height,bottom=0,count=0;for(let y=0;y<node.height;y++)for(let x=0;x<node.width;x++){const i=(y*node.width+x)*4;if(Math.abs(data[i]-167)<3&&Math.abs(data[i+1]-131)<3&&Math.abs(data[i+2]-75)<3&&data[i+3]>240){left=Math.min(left,x);right=Math.max(right,x);top=Math.min(top,y);bottom=Math.max(bottom,y);count++;}}return count>=4?{x:box.x+(left+right)/2*box.width/node.width,y:box.y+(top+bottom)/2*box.height/node.height}:null;});return Boolean(point);}).toBe(true);
   const pointRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await page.mouse.click(point.x,point.y);const pointSent=(await pointRequest).postDataJSON();
   assert.equal(pointSent.cash_movement,true);assert.deepEqual(pointSent.ids.toSorted((a,b)=>a-b),fixture.cash_outflow_ids.toSorted((a,b)=>a-b),'Outflow point opens only outflows, excluding income/borrowing and internal transfers');
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();await page.locator('#tr-back').click();
   assert.equal(await page.getByText('All flow amounts and transactions',{exact:true}).evaluate(node=>node.parentElement.open),false);
   await page.getByText('All flow amounts and transactions',{exact:true}).click();
   await expect(page.getByRole('table',{name:'Recorded incoming sources, outgoing categories and merchants'})).toBeVisible();
   await page.getByText('All flow amounts and transactions',{exact:true}).click();
   await page.evaluate(()=>scrollTo(0,0));
   await page.screenshot({path:join(evidence,`${name}-${width}.png`),fullPage:true});
   await diagram.screenshot({path:join(evidence,`${name}-${width}-diagram.png`)});
   // Browser Back from an internal menu must never leave for the preceding 404 document.
   await page.getByRole('button',{name:'Inflows',exact:true}).click();
   await expect(page.locator('.tr-family-inflows')).toBeVisible();
   await expect(page.locator('.tr-family-inflows > .tr-grid')).toContainText('Recorded receipts');
   await expect(page.getByRole('heading',{name:'Monthly recorded receipts',exact:true})).toBeVisible();
   const receiptDiagram=page.locator(width<700?'.tr-flow-phone':'.tr-flow-desktop');
   for(const label of ['Loans','Metals','Odd jobs','Fictional Candle Studio'])await expect(receiptDiagram.locator(`[data-action][data-label="${label}"]`).first()).toBeVisible();
   const sourceNode=receiptDiagram.locator('[data-action="money-flow-evidence"][data-label="Fictional Candle Studio"]');
   await sourceNode.click();await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
   await expect(page.getByRole('row').filter({hasText:'Fictional net payroll'})).toContainText('$110.01');
   await expect(page.getByRole('row').filter({hasText:'Fictional owner draw'})).toContainText('$220.02');
   await expect(page.getByRole('row').filter({hasText:'Fictional other support'})).toContainText('$30.03');
   await page.locator('#tr-back').click();await expect(page.getByRole('button',{name:'Inflows',exact:true})).toHaveAttribute('aria-pressed','true');
   await expect(sourceNode).toBeFocused();assert.equal(page.url(),origin+'/trajectory');
   if(width<700){
    const type=receiptDiagram.locator('[data-action="money-flow-category"][data-label="Fictional Candle Studio · Net payroll"]');
    await type.click();await expect(page.locator('#tr-dialog')).toBeVisible();await page.goBack();
    await expect(page.locator('#tr-dialog')).not.toBeVisible();await expect(type).toBeFocused();assert.equal(page.url(),origin+'/trajectory');
    await type.click();await page.locator('#tr-dialog').getByRole('button',{name:'View all receipts',exact:true}).click();
    await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
    await page.goBack();await expect(page.locator('#tr-dialog')).toBeVisible();
    await expect(page.locator('#tr-dialog-title')).toHaveText('Fictional Candle Studio · Net payroll');
    // A notification from the earlier programmatic close can arrive after this
    // restored dialog opens. Only an explicit dismissal owns a history step.
    assert.equal(await page.locator('#tr-dialog').evaluate(dialog => {
      let steps = 0;
      const back = history.back;
      history.back = () => {steps++;};
      try {dialog.dispatchEvent(new Event('close'));} finally {history.back = back;}
      return steps;
    }), 0);
    await page.goBack();await expect(page.locator('#tr-dialog')).not.toBeVisible();await expect(type).toBeFocused();
    await type.click();await page.keyboard.press('Escape');
    await expect(page.locator('#tr-dialog')).not.toBeVisible();await expect(type).toBeFocused();
   }
   await page.screenshot({path:join(evidence,`${name}-${width}-family-inflows.png`),fullPage:true});
   const receiptTrend=page.locator('#tr-flow-trend');await receiptTrend.scrollIntoViewIfNeeded();
   let receiptPoint;await expect.poll(async()=>{receiptPoint=await receiptTrend.evaluate(node=>{const ctx=node.getContext('2d'),data=ctx.getImageData(0,0,node.width,node.height).data,box=node.getBoundingClientRect();let left=node.width,right=0,top=node.height,bottom=0,count=0;for(let y=0;y<node.height;y++)for(let x=0;x<node.width;x++){const i=(y*node.width+x)*4;if(Math.abs(data[i]-167)<3&&Math.abs(data[i+1]-131)<3&&Math.abs(data[i+2]-75)<3&&data[i+3]>240){left=Math.min(left,x);right=Math.max(right,x);top=Math.min(top,y);bottom=Math.max(bottom,y);count++;}}return count>=4?{x:box.x+(left+right)/2*box.width/node.width,y:box.y+(top+bottom)/2*box.height/node.height}:null;});return Boolean(receiptPoint);}).toBe(true);
   const receiptRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await page.mouse.click(receiptPoint.x,receiptPoint.y);const receiptSent=(await receiptRequest).postDataJSON();
   assert.equal(receiptSent.cash_movement,true);assert.deepEqual(receiptSent.ids.toSorted((a,b)=>a-b),fixture.cash_inflow_ids.toSorted((a,b)=>a-b));
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();await page.locator('#tr-back').click();
   await expect(page.getByRole('button',{name:'Inflows',exact:true})).toHaveAttribute('aria-pressed','true');
   // Account/date/scope changes invalidate private historical evidence and normalize unsupported modes.
   const beforeAccountChange=dashboardReads.length;await selectReportAccount(page,fixture.other_cash_id);
   await page.locator('#tr-content:not([aria-busy="true"])').waitFor();assert.equal(dashboardReads.length,beforeAccountChange+1,'An account change fetches a fresh scoped dashboard');
   await expect(page.locator('.tr-family-inflows')).toContainText('No included receipts');
   await page.goBack();await expect(page.locator('#tr-content:not([aria-busy="true"])')).toBeVisible();
   await expect(page.getByRole('heading',{name:'Review transactions',level:1,exact:true})).not.toBeVisible();
   assert.equal(await page.locator('#tr-account-filter').inputValue(),String(fixture.other_cash_id));
   await selectReportAccount(page,'');await selectReportRange(page,'month');
   await expect(page.locator('#tr-content:not([aria-busy="true"])')).toBeVisible();
   await selectReportScope(page,'business');await expect(page.locator('#tr-content:not([aria-busy="true"])')).toBeVisible();
   if(width<700)await expect(page.locator('#tr-mobile-period-label')).toContainText((await page.locator('#tr-company option:checked').textContent()).trim());
   await navigateSection('Money flow');
   await expect(page.getByRole('button',{name:'Inflows',exact:true})).not.toBeAttached();
   await expect(page.locator('.tr-family-inflows')).not.toBeAttached();
   await expect(page.getByRole('button',{name:'Spending',exact:true})).toHaveAttribute('aria-pressed','true');
   await selectReportScope(page,'personal');await expect(page.locator('#tr-content:not([aria-busy="true"])')).toBeVisible();
   await selectReportRange(page,'year');await expect(page.locator('#tr-content:not([aria-busy="true"])')).toBeVisible();
   // Account activity retains its original selector through fresh, scoped history restoration.
   await navigateSection('Accounts');
   await page.locator('.tr-row').filter({has:page.getByText('Fictional checking',{exact:true})}).getByRole('button',{name:'Activity',exact:true}).click();
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
   await expect(page.getByRole('row').filter({hasText:'Fictional employer'})).toBeVisible();
   await expect(page.getByRole('row').filter({hasText:'Fictional card purchase'})).not.toBeAttached();
   await navigateSection('Money flow');
   const accountRestore=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await page.locator('#tr-back').click();assert.equal((await accountRestore).postDataJSON().account_id,fixture.cash_id);
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
   await expect(page.getByRole('row').filter({hasText:'Fictional employer'})).toBeVisible();
   await expect(page.getByRole('row').filter({hasText:'Fictional card purchase'})).not.toBeAttached();
   // Per-card source activity uses full credit amounts and a separate economic allocation.
   await navigateSection('Wealth & debt');
   await page.getByRole('heading',{name:'Credit card activity',exact:true}).waitFor();
   const annualPanel=page.locator('.tr-lender-ytd');
   await expect(annualPanel).toContainText(`Report cutoff: ${fixture.today}.`);
   await expect(annualPanel).toContainText('$69.12');await expect(annualPanel).toContainText('2 of 2');await expect(annualPanel).toContainText('different statement cutoffs');
   assert.ok(await annualPanel.locator('.tr-table-wrap').evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Annual lender table fits enhanced phone/desktop without horizontal panning');
   await annualPanel.screenshot({path:join(evidence,`${name}-${width}-lender-interest-ytd.png`)});
   await annualPanel.locator('.tr-lender-source > summary').first().click();await expect(annualPanel).toContainText('Fictional statement, calendar-year interest table, page3.');
   await expect(annualPanel).not.toContainText('a'.repeat(64));await expect(annualPanel).not.toContainText('fictional-annual:');
   const annualYear=Number(fixture.today.slice(0,4));await page.locator('#tr-lender-ytd-year').selectOption(String(annualYear-1));
   await expect(annualPanel).toContainText('$0.00');await expect(annualPanel).not.toContainText('$69.12');
   await page.locator('#tr-lender-ytd-year').selectOption(String(annualYear));await expect(annualPanel).toContainText('$69.12');
   await selectReportAccount(page,fixture.detail_card_id);
   await expect(annualPanel).toContainText('1 of 1');await expect(annualPanel).toContainText('$12.34');await expect(annualPanel).not.toContainText('$56.78');
   const cardPanel=page.locator('.tr-card-activity'),cardDetail=cardPanel.locator('.tr-card-account');
   await expect(cardDetail).toHaveCount(1);await expect(cardDetail).toContainText('Fictional detail card');
   await expect(cardDetail.locator('.tr-card-figures')).toContainText('$223.46');await expect(cardDetail.locator('.tr-card-figures')).toContainText('$200.00');await expect(cardDetail.locator('.tr-card-figures')).toContainText('$130.00');
   await expect(cardDetail.locator('.tr-card-share')).toContainText('Household share');await expect(cardDetail.locator('.tr-card-share')).toContainText('$100.01');await expect(cardDetail.locator('.tr-card-share')).toContainText('$123.45');
   await expect(cardDetail.locator('.tr-card-latest')).toContainText('$1,319.02');await expect(cardPanel).toContainText('Pending activity may be missing');
   await expect(cardDetail.locator('.tr-card-detail')).not.toHaveAttribute('open','');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:join(evidence,`${name}-${width}-card-activity.png`),fullPage:true});await cardPanel.screenshot({path:join(evidence,`${name}-${width}-card-activity-panel.png`)});
   await cardDetail.locator('.tr-card-detail > summary').click();
   await expect(cardDetail.locator('.tr-card-change')).toContainText('$319.02 higher since January 1');await expect(cardDetail.locator('.tr-card-equation')).toContainText('$1,319.02');
   await expect(cardDetail).toContainText('Fictional reconstructed opening');await expect(cardDetail).not.toContainText('Fictional pending card purchase');
   const cardRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await cardDetail.getByRole('button',{name:'View other card purchases, net',exact:true}).click();
   const cardSent=(await cardRequest).postDataJSON();assert.equal(cardSent.credit_movement,true);assert.equal(cardSent.account_id,fixture.detail_card_id);
   assert.deepEqual(cardSent.ids.toSorted((a,b)=>a-b),[fixture.detail_card_ids.purchase,fixture.detail_card_ids['client-equipment']].toSorted((a,b)=>a-b));
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
   const clientEquipment=page.getByRole('row').filter({hasText:'Fictional detail client-equipment'});
   await expect(clientEquipment).toContainText('-$123.45');await expect(clientEquipment).toContainText('Economic allocation $0.00');await expect(clientEquipment).toContainText('Full card amount');
   await expect(page.locator('.tr-table caption').first()).toContainText('2 transactions');
   await page.locator('#tr-back').click();await expect(page.getByRole('heading',{name:'Credit card activity',exact:true})).toBeVisible();
   await selectReportRange(page,'all');
   await expect(cardDetail.locator('.tr-card-figures')).toContainText('$298.46');await expect(cardDetail.locator('.tr-card-share')).toContainText('Unavailable');
   const allCardRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await cardDetail.getByRole('button',{name:'View other card purchases, net',exact:true}).click();
   assert.ok((await allCardRequest).postDataJSON().ids.includes(fixture.detail_card_ids['older-unallocated']));
   const olderUnallocated=page.getByRole('row').filter({hasText:'Fictional older unallocated card purchase'});
   await expect(olderUnallocated).toContainText('-$75.00');await expect(olderUnallocated).toContainText('Economic allocation Unavailable');
   await page.locator('#tr-back').click();await selectReportRange(page,'year');
   await expect(cardDetail.locator('.tr-card-figures')).toContainText('$223.46');
   await selectReportAccount(page,fixture.cash_id);await expect(page.locator('.tr-card-activity')).toContainText('No owned credit cards');
   await selectReportAccount(page,'');
   await verifyProfitLoss({page,fixture,width,name,evidence});
   checks.push(`${name} ${width}: native/browser Back from prior 404, category menu/detail restoration, scoped family receipt sources, incoming-only trends, keyboard/touch evidence, allocation privacy and horizontal containment`);
  }
  assert.deepEqual(errors,[]);await context.close();await browser.close();browser=null;
 }
 console.log(JSON.stringify({checks,evidence}));
}finally{await browser?.close();server?.kill('SIGTERM');await rm(dir,{recursive:true,force:true});}
