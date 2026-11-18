import assert from 'node:assert/strict';
import {mkdtemp,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit,expect} from '@playwright/test';
import {selectReportScope,selectReportRange,closeReportControls,openReportControls} from './trajectory-controls.browser-helpers.mjs';
const exec=promisify(execFile),base=resolve(),probe=createServer();
await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;await new Promise(done=>probe.close(done));
const dir=await mkdtemp(join(tmpdir(),'codex-money-flow-bootstrap-')),origin=`http://127.0.0.1:${port}`;
await writeFile(join(dir,'db.sqlite'),'');
const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:origin,APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',APP_CONFIG_CACHE:join(dir,'config.php'),DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'db.sqlite'),DB_URL:'',DATABASE_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',SESSION_SECURE_COOKIE:'false',SESSION_DOMAIN:'',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',LOG_CHANNEL:'null',BCRYPT_ROUNDS:'4',TRAJECTORY_ENABLED:'true',TRAJECTORY_AI_ENABLED:'false',GOLDAPI_KEY:'',NIGHTWATCH_ENABLED:'false',TELESCOPE_ENABLED:'false',PULSE_ENABLED:'false'};
let server,browser;
const checks=[];
try{
 await exec('php',['artisan','migrate','--force','--no-interaction'],{cwd:base,env,maxBuffer:2e6});
 const fixture=JSON.parse((await exec('php',['scripts/trajectory/profit-loss-browser-fixture.php'],{cwd:base,env})).stdout);
 const seed=join(dir,'budget-plan.php');
 await writeFile(seed,`<?php
 require ${JSON.stringify(join(base,'vendor/autoload.php'))};
 $app=require ${JSON.stringify(join(base,'bootstrap/app.php'))};$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
 if(!app()->environment('testing')||config('database.default')!=='sqlite'||realpath(config('database.connections.sqlite.database'))!==realpath(${JSON.stringify(join(dir,'db.sqlite'))}))throw new RuntimeException('Disposable fixture only');
 $space=App\\Models\\Trajectory\\Space::findOrFail(${Number(fixture.space_id)});$owner=App\\Models\\User::where('email','flow@fixture.invalid')->firstOrFail();
 app(App\\Services\\Trajectory\\RecordService::class)->save($owner,$space,'budget','Fictional planned debt payments',['category'=>'transport','monthly_cents'=>10000,'purpose'=>'debt_payment','effective_on'=>now($space->timezone)->startOfMonth()->toDateString(),'reviewed'=>true]);
 $service=app(App\\Services\\Trajectory\\RecordService::class);$start=now($space->timezone)->startOfMonth()->toDateString();$end=now($space->timezone)->endOfMonth()->toDateString();$parts=[];
 foreach([1000,2000] as $amount){$parts[]=$service->save($owner,$space,'budget','Fictional utility source',['category'=>'utilities','purpose'=>'spending','monthly_cents'=>$amount,'effective_on'=>$start,'reviewed'=>true])->id;}
 $merged=$service->save($owner,$space,'budget','Fictional combined utilities',['category'=>'utilities','purpose'=>'spending','monthly_cents'=>3000,'effective_on'=>$start,'ends_on'=>$end,'reviewed'=>true,'month_only'=>true,'supersedes_record_ids'=>$parts]);
 echo json_encode(['id'=>$merged->id,'sources'=>$parts]);
 `);const combinedBudget=JSON.parse((await exec('php',[seed],{cwd:base,env})).stdout);
 server=spawn('php',['artisan','serve','--host=127.0.0.1',`--port=${port}`,'--no-reload'],{cwd:base,env,stdio:'ignore'});
 await expect.poll(async()=>{try{return(await fetch(origin+'/up')).ok;}catch{return false;}}).toBe(true);
 for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
  browser=await engine.launch();const context=await browser.newContext({reducedMotion:'reduce'});
  await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const csrf=(await(await context.request.get(origin+'/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];assert.ok(csrf);
  assert.ok((await context.request.post(origin+'/login',{form:{email:'flow@fixture.invalid',password:'Fictional-flow-only-2028!',_token:csrf},headers:{Accept:'application/json'}})).ok());
  for(const width of [390,1440]){
   const page=await context.newPage(),errors=[],reads=[];page.setDefaultTimeout(12000);await page.setViewportSize({width,height:950});
   page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.method()==='GET')reads.push(new URL(request.url()).pathname);});
   let releaseDashboard,delivered=false;const dashboardGate=new Promise(done=>{releaseDashboard=done;});
   await page.route('**/dashboard?*',async route=>{const response=await route.fetch();await dashboardGate;delivered=true;try{await route.fulfill({response});}catch{/* A later scope can abort the original request. */}});
   const projection=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/analysis'));
   await page.goto(origin+'/trajectory');const initial=await(await projection).json();
   await expect(page.locator('#vf-income-chart')).toBeVisible();assert.equal(delivered,false,'Real visual data paints before the blocked legacy Dashboard');
   await expect(page.locator('#tr-content')).toHaveAttribute('aria-busy','false');await expect(page.locator('#tr-add')).toBeDisabled();
   const expected=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(initial.summary.inflow_cents/100);
   await expect(page.locator('.vf-stat-row')).toContainText(expected);
   const income=page.locator('#vf-income-chart'),box=await income.boundingBox();
   await income.click({position:{x:box.width/2+Math.min(box.width,box.height)*.4,y:box.height/2}});
   await expect(page.locator('dialog.tr-donut-explanation')).toBeVisible();
   await expect(page.locator('#tr-page-title')).toHaveText('Home');
   await page.locator('dialog.tr-donut-explanation').getByRole('button',{name:'Close explanation',exact:true}).click();
   await expect(page.locator('dialog.tr-donut-explanation')).toHaveCount(0);
   const primary=async key=>{const phone=page.locator(`.vf-mobile-nav [data-tab="${key}"]`);await(await phone.isVisible()?phone:page.locator(`.tr-primary-navigation [data-tab="${key}"]`)).click();};
   await primary('analysis');await page.locator('[data-action="vf-ledger-all"]').click();
   const categories=page.locator('[data-vf-category]');assert.ok(await categories.count());
   assert.equal(await categories.evaluateAll(nodes=>nodes.every(node=>node.disabled)),true,'Editing waits for native Dashboard metadata');
   await primary('accounts');await expect(page.getByText('Loading account and planning details…',{exact:true})).toBeVisible();
   await primary('budget');await expect(page.getByRole('heading',{name:'What’s left to spend',exact:true})).toBeVisible();
   await primary('overview');await expect(page.locator('#vf-income-chart')).toBeVisible();
   assert.equal(reads.filter(path=>path.endsWith('/dashboard')).length,1);assert.equal(reads.filter(path=>path.endsWith('/analysis')).length,1);assert.equal(reads.filter(path=>path.endsWith('/budget-envelopes')).length,1);
   releaseDashboard();await expect(page.locator('#tr-add')).toBeEnabled();
   await primary('analysis');assert.equal(await categories.evaluateAll(nodes=>nodes.every(node=>!node.disabled)),true);
   assert.equal(reads.filter(path=>path.endsWith('/analysis')).length,1,'The matching late Dashboard adopts the exact visual revision without a second projection');
   let releaseReload,reloadDelivered=false;const reloadGate=new Promise(done=>{releaseReload=done;});
   await page.route('**/dashboard?*',async route=>{const response=await route.fetch();await reloadGate;reloadDelivered=true;await route.fulfill({response});});
   await page.reload();await expect(page.locator('#vf-income-chart')).toBeVisible();assert.equal(reloadDelivered,false,'A browser/pull reload also paints before legacy details');
   await expect(page.locator('#tr-add')).toBeDisabled();releaseReload();await expect(page.locator('#tr-add')).toBeEnabled();
   // Advanced import/setup stays on desktop; phone users adjust category budgets.
   await primary('budget');await openReportControls(page);
   try{await page.getByRole('button',{name:'Manage targets',exact:true}).click();}
   catch(error){console.error(JSON.stringify({engine:name,width,budgetNavigation:await page.locator('#tr-content').innerText(),errors,recentReads:reads.slice(-12)}));throw error;}
   await closeReportControls(page);
   const setup=page.locator('.tr-budget-desktop-setup'),phoneBudget=page.getByRole('button',{name:'View category budgets',exact:true});
   if(width===390){
    await expect(setup).toBeHidden();await expect(page.getByRole('button',{name:'Import budget CSV',exact:true})).toBeHidden();await expect(phoneBudget).toBeVisible();
    assert.ok(await phoneBudget.evaluate(node=>node.getBoundingClientRect().right<=innerWidth),'Phone budget action fits the viewport');
    await phoneBudget.click();await expect(page.getByRole('heading',{name:'What’s left to spend',exact:true})).toBeVisible();
   }else{
    await expect(setup).toBeVisible();await expect(page.getByRole('button',{name:'Import budget CSV',exact:true})).toBeVisible();await expect(phoneBudget).toBeHidden();
    await expect(setup).toContainText('Planned debt payments');assert.doesNotMatch(await setup.innerText(),/Debt cash reserve/);
    await page.locator(`[data-action="edit"][data-id="${combinedBudget.id}"]`).click();
    await expect(page.locator('#tr-dialog')).toBeVisible();assert.equal(await page.locator('#tr-dialog [name="supersedes_record_ids"],#tr-dialog [name="month_only"]').count(),0);
    await page.locator('#tr-dialog [name="name"]').fill('Fictional combined utilities edited');
    const nativeEdit=page.waitForRequest(request=>request.method()==='PATCH'&&new URL(request.url()).pathname.endsWith(`/records/${combinedBudget.id}`));
    await page.locator('#tr-save').click();const savedPayload=(await nativeEdit).postDataJSON();assert.deepEqual(savedPayload.data.supersedes_record_ids,combinedBudget.sources);assert.equal(savedPayload.data.month_only,true);assert.equal(savedPayload.data.carry_forward,true);
    await expect(page.locator('#tr-dialog')).toBeHidden();await expect(page.locator('#tr-add')).toBeEnabled();
   }
   await primary('overview');
   if(width===390){
    const navigation=page.locator('.vf-mobile-nav'),before=await navigation.boundingBox();
    assert.equal(await navigation.evaluate(node=>node.parentElement===document.body),true,'Phone tabs are independent of the scrolling app content');
    await page.evaluate(()=>window.scrollTo(0,600));await expect.poll(()=>page.evaluate(()=>window.scrollY)).toBeGreaterThan(0);
    const after=await navigation.boundingBox();assert.ok(Math.abs(before.y-after.y)<1,'The bottom tabs stay pinned while content scrolls');
    await primary('budget');await expect(navigation.locator('[data-tab="budget"]')).toHaveAttribute('aria-current','page');
    await expect(page.getByRole('heading',{name:'What’s left to spend',exact:true})).toBeVisible();
    await primary('overview');
   }
   await expect(page.locator('#vf-income-chart')).toBeVisible();await expect(page.locator('#tr-content')).toHaveAttribute('aria-busy','false');
   // Period changes render the exact canonical in-memory subset before fresh
   // requests finish; no larger/older range is fabricated from a smaller one.
   let releasePeriod,periodDelivered=false;const periodGate=new Promise(done=>{releasePeriod=done;});
   await page.route('**/analysis?*',async route=>{const key=new URL(route.request().url()).searchParams.get('range');if(!['day','week'].includes(key)){await route.fallback();return;}const response=await route.fetch();await periodGate;periodDelivered=true;try{await route.fulfill({response});}catch{}});
   await page.route('**/dashboard?*',async route=>{const key=new URL(route.request().url()).searchParams.get('range');if(!['day','week'].includes(key)){await route.fallback();return;}const response=await route.fetch();await periodGate;try{await route.fulfill({response});}catch{}});
   await selectReportRange(page,'day');await closeReportControls(page);
   await expect(page.locator('.vf-finance')).toBeVisible();assert.equal(periodDelivered,false,'Day paints from the authorized month before fresh reads');
   const shortDate=value=>new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z'));
   const day=initial.calendar_ranges.day;await expect(page.locator('#tr-context')).toContainText(shortDate(day.from)+' – '+shortDate(day.through));
   const week=initial.calendar_ranges.week,contained=week.from>=initial.range.from&&week.through<=initial.range.through;
   await selectReportRange(page,'week');await closeReportControls(page);
   if(contained){await expect(page.locator('.vf-finance')).toBeVisible();assert.equal(periodDelivered,false,'A contained Week uses exact month entry keys');await expect(page.locator('#tr-context')).toContainText(shortDate(week.from)+' – '+shortDate(week.through));}
   else await expect(page.locator('.vf-finance')).toHaveCount(0);
   releasePeriod();await expect(page.locator('#tr-add')).toBeEnabled();await expect(page.locator('#tr-context')).toContainText(shortDate(week.from)+' – '+shortDate(week.through));
   const freshDay=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/analysis')&&new URL(response.url()).searchParams.get('range')==='day');
   await selectReportRange(page,'day');await closeReportControls(page);
   const freshDayReport=await(await freshDay).json();await expect(page.locator('#tr-add')).toBeEnabled();await expect(page.locator('#tr-content')).toHaveAttribute('aria-busy','false');
   if(freshDayReport.coverage.history_available&&freshDayReport.coverage.selected_range_has_evidence===false){
    await expect(page.locator('.vf-pie-heading strong')).toHaveText(['None recorded','None recorded']);
    await expect(page.locator('.vf-stat-row')).toContainText('None recorded');
    assert.doesNotMatch(await page.locator('.vf-stat-row').innerText(),/Unavailable|No receipts recorded today|No purchases recorded today/);
   }
   // A refresh invalidates the dated memory cohort instead of presenting it as fresh.
   let releaseExplicit;const explicitGate=new Promise(done=>{releaseExplicit=done;});
   await page.route('**/analysis?*',async route=>{const response=await route.fetch();await explicitGate;try{await route.fulfill({response});}catch{}});
   await openReportControls(page);await page.locator('#tr-refresh').click();await closeReportControls(page);await expect(page.locator('.vf-finance')).toHaveCount(0);releaseExplicit();await expect(page.locator('.vf-finance')).toBeVisible();await expect(page.locator('#tr-add')).toBeEnabled();
   assert.deepEqual(errors,[]);await page.close();

   const failed=await context.newPage();await failed.setViewportSize({width,height:950});
   let releaseFailure;const failureGate=new Promise(done=>{releaseFailure=done;});
   await failed.route('**/dashboard?*',async route=>{const response=await route.fetch();await failureGate;await route.fulfill({response});});
   await failed.route('**/analysis?*',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Fictional projection is unavailable.'})}));
   await failed.goto(origin+'/trajectory');await expect(failed.locator('#tr-content [role="alert"]')).toContainText('Fictional projection is unavailable.');
   await expect(failed.locator('.vf-stat-row')).toHaveCount(0);assert.doesNotMatch(await failed.locator('#tr-content').innerText(),/\$0(?:\.00)?/);
   releaseFailure();await expect(failed.locator('#tr-add')).toBeEnabled();await expect(failed.locator('#tr-content [role="alert"]')).toContainText('Fictional projection is unavailable.');await failed.close();

   const denied=await context.newPage();await denied.setViewportSize({width,height:950});
   let releaseDenied;const deniedGate=new Promise(done=>{releaseDenied=done;});
   await denied.route('**/dashboard?*',async route=>{try{const response=await route.fetch();await deniedGate;await route.fulfill({response});}catch{}});
   await denied.route('**/analysis?*',route=>route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({message:'Fictional financial access was revoked.'})}));
   await denied.goto(origin+'/trajectory');await expect(denied.locator('#tr-content [role="alert"]')).toContainText('revoked');releaseDenied();await denied.waitForTimeout(80);
   await expect(denied.locator('.vf-finance')).toHaveCount(0);await expect(denied.locator('#tr-content [role="alert"]')).toContainText('revoked');await denied.close();

   const switched=await context.newPage();await switched.setViewportSize({width,height:950});
   let releaseOld,held=false;const oldGate=new Promise(done=>{releaseOld=done;});
   await switched.route('**/dashboard?*',async route=>{if(!new URL(route.request().url()).pathname.includes(`/spaces/${fixture.space_id}/`)){await route.continue();return;}const response=await route.fetch();held=true;await oldGate;try{await route.fulfill({response});}catch{/* The superseded fetch is aborted. */}});
   await switched.goto(origin+'/trajectory');await expect(switched.locator('#vf-income-chart')).toBeVisible();await expect.poll(()=>held).toBe(true);
   await selectReportScope(switched,'business');await expect(switched.locator('#tr-space-tabs [data-scope="business"]')).toHaveAttribute('aria-selected','true');
   await expect(switched.locator('#tr-add')).toBeEnabled();const before=await switched.locator('#tr-content').innerText();
   releaseOld();await switched.waitForTimeout(80);
   assert.equal(await switched.locator('#tr-content').innerText(),before,'A late/aborted household response cannot overwrite the selected business');
   await switched.close();checks.push(`${name} ${width}: early real visual paint, pending navigation, edit gating, failed projection and late-scope isolation`);
   if(width===390){
    const business=await context.newPage();await business.setViewportSize({width,height:950});
    let releaseBusiness,finished=false;const businessGate=new Promise(done=>{releaseBusiness=done;});
    await business.route('**/dashboard?*',async route=>{const response=await route.fetch();await businessGate;finished=true;await route.fulfill({response});});
    const businessProjection=business.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/analysis'));
    await business.goto(origin+'/trajectory/business/');const actual=await(await businessProjection).json();assert.equal(actual.scope.space_id,fixture.company_id);
    await expect(business.locator('.vf-finance')).toBeVisible();assert.equal(finished,false);await expect(business.locator('#tr-add')).toBeDisabled();
    await expect(business.locator('#tr-mobile-period-label')).toContainText((await business.locator('#tr-company option:checked').textContent()).trim());
    releaseBusiness();await expect(business.locator('#tr-add')).toBeEnabled();await business.close();
   }
  }
  await context.close();await browser.close();browser=null;
 }
 console.log(JSON.stringify({checks}));
}finally{await browser?.close();server?.kill('SIGTERM');await rm(dir,{recursive:true,force:true});}
