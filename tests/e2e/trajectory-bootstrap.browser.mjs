import assert from 'node:assert/strict';
import {mkdtemp,writeFile,rm,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit,expect} from '@playwright/test';
import {selectReportScope,selectReportRange,closeReportControls,openReportControls,openReportOptions} from './trajectory-controls.browser-helpers.mjs';
import {checkAnalysisHeader} from './trajectory-analysis-header.browser-helpers.mjs';
import {checkPlanTrajectory} from './trajectory-plan-trajectory.browser-helpers.mjs';
import {checkReviewedCategoryChanges} from './trajectory-reviewed-categories.browser-helpers.mjs';
const exec=promisify(execFile),base=resolve(),probe=createServer();
const onlyPlan=process.argv.includes('--plan-trajectory');
const fullSuite=!onlyPlan && !process.argv.includes('--category-edits') && !process.argv.includes('--report-header');
const includesPlan=fullSuite || onlyPlan;
await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;await new Promise(done=>probe.close(done));
const dir=await mkdtemp(join(tmpdir(),'codex-money-flow-bootstrap-')),origin=`http://127.0.0.1:${port}`;
await writeFile(join(dir,'db.sqlite'),'');
const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:origin,APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',APP_CONFIG_CACHE:join(dir,'config.php'),DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'db.sqlite'),DB_URL:'',DATABASE_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',SESSION_SECURE_COOKIE:'false',SESSION_DOMAIN:'',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',LOG_CHANNEL:'null',BCRYPT_ROUNDS:'4',TRAJECTORY_ENABLED:'true',TRAJECTORY_AI_ENABLED:'false',GOLDAPI_KEY:'',NIGHTWATCH_ENABLED:'false',TELESCOPE_ENABLED:'false',PULSE_ENABLED:'false'};
let server,browser;
const checks=[];await mkdir('/tmp/trajectory-clarity-qa',{recursive:true});
try{
 await exec('php',['artisan','migrate','--force','--no-interaction'],{cwd:base,env,maxBuffer:2e6});
 const fixture=JSON.parse((await exec('php',['scripts/trajectory/profit-loss-browser-fixture.php'],{cwd:base,env})).stdout);
 const seed=join(dir,'budget-plan.php');
 await writeFile(seed,`<?php
 require ${JSON.stringify(join(base,'vendor/autoload.php'))};
 $app=require ${JSON.stringify(join(base,'bootstrap/app.php'))};$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
 if(!app()->environment('testing')||config('database.default')!=='sqlite'||realpath(config('database.connections.sqlite.database'))!==realpath(${JSON.stringify(join(dir,'db.sqlite'))}))throw new RuntimeException('Disposable fixture only');
 $space=App\\Models\\Trajectory\\Space::findOrFail(${Number(fixture.space_id)});$owner=App\\Models\\User::where('email','flow@fixture.invalid')->firstOrFail();
 ${includesPlan ? "$space->update(['settings' => [...($space->settings ?? []), 'flexible_monthly_cents' => 173200]]);" : ''}
 app(App\\Services\\Trajectory\\RecordService::class)->save($owner,$space,'budget','Fictional planned debt payments',['category'=>'transport','monthly_cents'=>10000,'purpose'=>'debt_payment','effective_on'=>now($space->timezone)->startOfMonth()->toDateString(),'reviewed'=>true]);
 $service=app(App\\Services\\Trajectory\\RecordService::class);$start=now($space->timezone)->startOfMonth()->toDateString();$end=now($space->timezone)->endOfMonth()->toDateString();$parts=[];
 foreach([1000,2000] as $amount){$parts[]=$service->save($owner,$space,'budget','Fictional utility source',['category'=>'utilities','purpose'=>'spending','monthly_cents'=>$amount,'effective_on'=>$start,'reviewed'=>true])->id;}
 $merged=$service->save($owner,$space,'budget','Fictional combined utilities',['category'=>'utilities','purpose'=>'spending','monthly_cents'=>3000,'effective_on'=>$start,'ends_on'=>$end,'reviewed'=>true,'month_only'=>true,'supersedes_record_ids'=>$parts]);
 $attribution=app(App\\Services\\Trajectory\\AttributionService::class);
 $attribution->saveDefinition($owner,$space,'program','Fictional activity',['key'=>'fictional_activity']);
 $parent=$attribution->saveDefinition($owner,$space,'category_definition','Shopping',['key'=>'shopping','direction'=>'expense']);
 $child=$attribution->saveDefinition($owner,$space,'category_definition','Family reimbursements',['key'=>'family_reimbursements','direction'=>'expense','parent_record_id'=>$parent->id]);
 $attribution->saveDefinition($owner,$space,'category_definition','Christmas',['key'=>'christmas','direction'=>'expense']);
 $cash=App\\Models\\Trajectory\\Account::where('space_id',$space->id)->where('source_key','fictional:flow-cash')->sole();
 foreach ([['fictional:flow-card',1100,4500],['fictional:detail-card',2900,8000]] as [$key,$apr,$minimum]) {
   $credit=App\\Models\\Trajectory\\Account::where('space_id',$space->id)->where('source_key',$key)->sole();
   $service->save($owner,$space,'debt',$credit->name,['kind'=>'credit','account_id'=>$credit->id,'payment_account_id'=>$cash->id,
     'balance_cents'=>$credit->balance_cents+32040,'observed_on'=>now($space->timezone)->subMonthNoOverflow()->toDateString(),
     'apr_bps'=>$apr,'payment_cents'=>$minimum,'escrow_cents'=>0,'fees_cents'=>0,'confirmed'=>true,'interest_method'=>'monthly',
     'payment_cadence'=>'monthly','next_due_on'=>now($space->timezone)->addDays(3)->toDateString()]);
 }
 if (${includesPlan ? 'true' : 'false'}) {
   $chartDay=now($space->timezone)->subYearNoOverflow()->startOfMonth()->toDateString();
   $card=App\\Models\\Trajectory\\Account::where('space_id',$space->id)->where('source_key','fictional:detail-card')->sole();
   foreach ([[$cash,'groceries','expense',-39156],[$card,'interest','expense',-1234],[$cash,'loan','debt_payment',-7000],[$cash,'card','card_payment',-9500]] as [$account,$category,$flow,$amount]) {
     $key='fictional-plan-comparison-'.$category;
     app(App\\Services\\Trajectory\\LedgerService::class)->ingest($account,[['id'=>$key,'date'=>$chartDay,'merchant'=>'Fictional prior-year activity','amount_cents'=>$amount]]);
     App\\Models\\Trajectory\\Transaction::where('account_id',$account->id)->where('source_key',$account->source_key.':'.$key)->sole()->update(['flow'=>$flow,'category'=>$category,'reviewed'=>true]);
   }
 }
 foreach ([1,2,3] as $offset) {
   $key='fictional-prior-living-cost-'.$offset;
   app(App\\Services\\Trajectory\\LedgerService::class)->ingest($cash,[['id'=>$key,'date'=>now($space->timezone)->startOfMonth()->subMonthsNoOverflow($offset)->toDateString(),'merchant'=>'Fictional household supply','amount_cents'=>-43000]]);
   App\\Models\\Trajectory\\Transaction::where('account_id',$cash->id)->where('source_key',$cash->source_key.':'.$key)->sole()->update(['flow'=>'expense','category'=>'groceries','reviewed'=>true]);
 }
 foreach ([12345,16789] as $i=>$cents) {
   $key='fictional-plan-share-'.$i;
   app(App\\Services\\Trajectory\\LedgerService::class)->ingest($cash,[['id'=>$key,'date'=>$start,'merchant'=>'SAMARITAN MEMBER Transfer '.(991000+$i),'amount_cents'=>$cents]]);
   App\\Models\\Trajectory\\Transaction::where('account_id',$cash->id)->where('source_key',$cash->source_key.':'.$key)->sole()->update(['flow'=>'medical_share','category'=>'health','reviewed'=>true]);
 }
 $service->save($owner,$space,'asset','Fictional saleable trailer',['asset_type'=>'other','value_cents'=>65000,'observed_on'=>$start,'plan_sale_proceeds_cents'=>55000]);
 $service->save($owner,$space,'metal','Fictional silver holding',['metal'=>'silver','quantity'=>'1','weight'=>'1','unit'=>'troy_ounce',
   'weight_basis'=>'gross','purity_bps'=>9990,'cost_basis_cents'=>2300,'acquired_on'=>$start]);
 $payments=[];
 foreach ([-2167,-4312] as $amount) {
   $key='fictional-zelle-'.$amount;
   app(App\\Services\\Trajectory\\LedgerService::class)->ingest($cash,[['id'=>$key,'date'=>now($space->timezone)->toDateString(),'merchant'=>'Zelle: Fictional Family','amount_cents'=>$amount,'category'=>'shopping']]);
   $payment=App\\Models\\Trajectory\\Transaction::where('account_id',$cash->id)->where('source_key',$cash->source_key.':'.$key)->sole();
   $attribution->save($owner,$space,$payment,[['space_id'=>$space->id,'amount_cents'=>$amount,'category'=>'shopping','subcategory_record_id'=>$child->id]],$payment->version);
   $payments[]=$payment->id;
 }
 $reviewPurchases=[];
 foreach (['desktop','phone'] as $engine) {
   $key='fixture-manual-category-'.$engine;
   app(App\\Services\\Trajectory\\LedgerService::class)->ingest($cash,[['id'=>$key,'date'=>now($space->timezone)->toDateString(),
     'merchant'=>'Fictional category review '.$engine,'amount_cents'=>-378]]);
   $purchase=App\\Models\\Trajectory\\Transaction::where('account_id',$cash->id)->where('source_key',$cash->source_key.':'.$key)->sole();
   $purchase->update(['flow'=>'expense','category'=>'uncategorized','reviewed'=>false]);
   $reviewPurchases[$engine]=$purchase->id;
 }
 App\\Models\\Trajectory\\ExpenseNotice::firstOrCreate(['space_id'=>$space->id,'user_id'=>$owner->id,'transaction_id'=>$payments[0]],
   ['payload'=>['merchant'=>'Fictional reviewed payment'],'delivery_status'=>'inbox_only']);
 echo json_encode(['id'=>$merged->id,'sources'=>$parts,'payments'=>$payments,'child_id'=>$child->id,'review_purchases'=>$reviewPurchases]);
 `);const combinedBudget=JSON.parse((await exec('php',[seed],{cwd:base,env})).stdout);
 server=spawn('php',['artisan','serve','--host=127.0.0.1',`--port=${port}`,'--no-reload'],{cwd:base,env,stdio:'ignore'});
 await expect.poll(async()=>{try{return(await fetch(origin+'/up')).ok;}catch{return false;}}).toBe(true);
 for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
  browser=await engine.launch();const context=await browser.newContext({reducedMotion:'reduce',hasTouch:name==='webkit' && includesPlan});
  await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const csrf=(await(await context.request.get(origin+'/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];assert.ok(csrf);
  assert.ok((await context.request.post(origin+'/login',{form:{email:'flow@fixture.invalid',password:'Fictional-flow-only-2028!',_token:csrf},headers:{Accept:'application/json'}})).ok());
  for(const width of fullSuite?[390,1440]:[]){
   const page=await context.newPage(),errors=[],reads=[];page.setDefaultTimeout(12000);await page.setViewportSize({width,height:950});
   page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.method()==='GET')reads.push(new URL(request.url()).pathname);});
   let releaseDashboard,delivered=false;const dashboardGate=new Promise(done=>{releaseDashboard=done;});
   await page.route('**/visual-context*',async route=>{const response=await route.fetch();await dashboardGate;delivered=true;try{await route.fulfill({response});}catch{/* A later scope can abort the original request. */}});
   const projection=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/analysis'));
   await page.goto(origin+'/trajectory');const initial=await(await projection).json();
   await expect(page.locator('#vf-income-chart')).toBeVisible();assert.equal(delivered,false,'Real visual data paints before the blocked editing metadata');
   await expect(page.locator('#tr-content')).toHaveAttribute('aria-busy','false');await expect(page.locator('#tr-add')).toBeDisabled();
   const expected=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(initial.summary.inflow_cents/100);
   const cashTotals=page.locator('.vf-stat-row').filter({hasText:'Money coming in'});
   await expect(cashTotals).toContainText(expected);
   const income=page.locator('#vf-income-chart'),box=await income.boundingBox();
   await income.click({position:{x:box.width/2+Math.min(box.width,box.height)*.4,y:box.height/2}});
   await expect(page.locator('dialog.tr-donut-explanation')).toBeVisible();
   await expect(page.locator('#tr-page-title')).toHaveText('Home');
   await page.locator('dialog.tr-donut-explanation').getByRole('button',{name:'Close explanation',exact:true}).click();
   await expect(page.locator('dialog.tr-donut-explanation')).toHaveCount(0);
   const primary=async key=>{const phone=page.locator(`.vf-mobile-nav [data-tab="${key}"]`);await(await phone.isVisible()?phone:page.locator(`.tr-primary-navigation [data-tab="${key}"]`)).click();};
   await primary('analysis');await page.locator('[data-action="vf-ledger-all"]').click();
   const categories=page.locator('[data-vf-category]');assert.ok(await categories.count());
   assert.equal(await categories.evaluateAll(nodes=>nodes.every(node=>node.disabled)),true,'Editing waits for native editing metadata');
   await primary('budget');await expect(page.getByRole('region',{name:'Monthly budget totals',exact:true})).toBeVisible();
   await primary('overview');await expect(page.locator('#vf-income-chart')).toBeVisible();
   assert.equal(reads.filter(path=>path.endsWith('/dashboard')).length,0,'Home, Analysis and Budget never request the expensive legacy Dashboard');assert.equal(reads.filter(path=>path.endsWith('/visual-context')).length,1);assert.equal(reads.filter(path=>path.endsWith('/analysis')).length,1);assert.equal(reads.filter(path=>path.endsWith('/budget-envelopes')).length,1);
   releaseDashboard();await expect(page.locator('#tr-add')).toBeEnabled();
   await primary('analysis');assert.equal(await categories.evaluateAll(nodes=>nodes.every(node=>!node.disabled)),true);
   assert.equal(reads.filter(path=>path.endsWith('/analysis')).length,1,'The matching late metadata adopts the exact visual revision without a second projection');
   let releaseLegacy;const legacyGate=new Promise(done=>{releaseLegacy=done;});
   await page.route('**/dashboard?*',async route=>{const response=await route.fetch();await legacyGate;try{await route.fulfill({response});}catch{}});
   await primary('accounts');await expect(page.getByText('Loading account and planning details…',{exact:true})).toBeVisible();
   assert.equal(reads.filter(path=>path.endsWith('/dashboard')).length,1,'Accounts explicitly loads the real legacy Dashboard');releaseLegacy();
   await expect(page.getByRole('heading',{name:'Connections',exact:true})).toBeVisible();
   await page.goBack();await expect(page.locator('#tr-page-title')).toHaveText('Analysis');await expect(page.locator('#tr-add')).toBeEnabled();
   let releaseReload,reloadDelivered=false;const reloadGate=new Promise(done=>{releaseReload=done;});
   await page.route('**/visual-context*',async route=>{const response=await route.fetch();await reloadGate;reloadDelivered=true;await route.fulfill({response});});
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
    await phoneBudget.click();await expect(page.getByRole('region',{name:'Monthly budget totals',exact:true})).toBeVisible();
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
    await expect(page.getByRole('region',{name:'Monthly budget totals',exact:true})).toBeVisible();
    await primary('overview');
   }
   await expect(page.locator('#vf-income-chart')).toBeVisible();await expect(page.locator('#tr-content')).toHaveAttribute('aria-busy','false');
   // Period changes render the exact canonical in-memory subset before fresh
   // requests finish; no larger/older range is fabricated from a smaller one.
   let releasePeriod,periodDelivered=false;const periodGate=new Promise(done=>{releasePeriod=done;});
   await page.route('**/analysis?*',async route=>{const key=new URL(route.request().url()).searchParams.get('range');if(!['day','week'].includes(key)){await route.fallback();return;}const response=await route.fetch();await periodGate;periodDelivered=true;try{await route.fulfill({response});}catch{}});
   await page.route('**/visual-context*',async route=>{const key=new URL(route.request().url()).searchParams.get('range');if(!['day','week'].includes(key)){await route.fallback();return;}const response=await route.fetch();await periodGate;try{await route.fulfill({response});}catch{}});
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
    await expect(cashTotals).toContainText('None recorded');
    assert.doesNotMatch(await cashTotals.innerText(),/Unavailable|No receipts recorded today|No purchases recorded today/);
   }
   // A refresh invalidates the dated memory cohort instead of presenting it as fresh.
   let releaseExplicit;const explicitGate=new Promise(done=>{releaseExplicit=done;});
   await page.route('**/analysis?*',async route=>{const response=await route.fetch();await explicitGate;try{await route.fulfill({response});}catch{}});
   await openReportOptions(page);await page.locator('#tr-refresh').click();await closeReportControls(page);await expect(page.locator('.vf-finance')).toHaveCount(0);releaseExplicit();await expect(page.locator('.vf-finance')).toBeVisible();await expect(page.locator('#tr-add')).toBeEnabled();
   // Analysis tab is a section home, not a link back to Budget's drilldown.
   await primary('budget');await primary('analysis');await expect(page.locator('#tr-page-title')).toHaveText('Analysis');await expect(page.locator('#vf-period-chart')).toBeVisible();await expect(page.locator('#tr-back')).toBeHidden();
   assert.deepEqual(errors,[]);await page.close();

   const failed=await context.newPage();await failed.setViewportSize({width,height:950});
   let releaseFailure;const failureGate=new Promise(done=>{releaseFailure=done;});
   await failed.route('**/visual-context*',async route=>{const response=await route.fetch();await failureGate;await route.fulfill({response});});
   await failed.route('**/analysis?*',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Fictional projection is unavailable.'})}));
   await failed.goto(origin+'/trajectory');await expect(failed.locator('#tr-content [role="alert"]')).toContainText('Fictional projection is unavailable.');
   await expect(failed.locator('.vf-stat-row')).toHaveCount(0);assert.doesNotMatch(await failed.locator('#tr-content').innerText(),/\$0(?:\.00)?/);
   releaseFailure();await expect(failed.locator('#tr-add')).toBeEnabled();await expect(failed.locator('#tr-content [role="alert"]')).toContainText('Fictional projection is unavailable.');await failed.close();

   const denied=await context.newPage();await denied.setViewportSize({width,height:950});
   let releaseDenied;const deniedGate=new Promise(done=>{releaseDenied=done;});
   await denied.route('**/visual-context*',async route=>{try{const response=await route.fetch();await deniedGate;await route.fulfill({response});}catch{}});
   await denied.route('**/analysis?*',route=>route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({message:'Fictional financial access was revoked.'})}));
   await denied.goto(origin+'/trajectory');await expect(denied.locator('#tr-content [role="alert"]')).toContainText('revoked');releaseDenied();await denied.waitForTimeout(80);
   await expect(denied.locator('.vf-finance')).toHaveCount(0);await expect(denied.locator('#tr-content [role="alert"]')).toContainText('revoked');await denied.close();

   const switched=await context.newPage();await switched.setViewportSize({width,height:950});
   let releaseOld,held=false;const oldGate=new Promise(done=>{releaseOld=done;});
   await switched.route('**/visual-context*',async route=>{if(!new URL(route.request().url()).pathname.includes(`/spaces/${fixture.space_id}/`)){await route.continue();return;}const response=await route.fetch();held=true;await oldGate;try{await route.fulfill({response});}catch{/* The superseded fetch is aborted. */}});
   await switched.goto(origin+'/trajectory');await expect(switched.locator('#vf-income-chart')).toBeVisible();await expect.poll(()=>held).toBe(true);
   await selectReportScope(switched,'business');await expect(switched.locator('#tr-space-tabs [data-scope="business"]')).toHaveAttribute('aria-selected','true');
   await expect(switched.locator('#tr-add')).toBeEnabled();const before=await switched.locator('#tr-content').innerText();
   releaseOld();await switched.waitForTimeout(80);
   assert.equal(await switched.locator('#tr-content').innerText(),before,'A late/aborted household response cannot overwrite the selected business');
   await switched.close();checks.push(`${name} ${width}: early real visual paint, pending navigation, edit gating, failed projection and late-scope isolation`);
   if(width===390){
    const business=await context.newPage();await business.setViewportSize({width,height:950});
    let releaseBusiness,finished=false;const businessGate=new Promise(done=>{releaseBusiness=done;});
    await business.route('**/visual-context*',async route=>{const response=await route.fetch();await businessGate;finished=true;await route.fulfill({response});});
    const businessProjection=business.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/analysis'));
    await business.goto(origin+'/trajectory/business/');const actual=await(await businessProjection).json();assert.equal(actual.scope.space_id,fixture.company_id);
    await expect(business.locator('.vf-finance')).toBeVisible();assert.equal(finished,false);await expect(business.locator('#tr-add')).toBeDisabled();
    await expect(business.locator('#tr-mobile-period-label')).toContainText((await business.locator('#tr-company option:checked').textContent()).trim());
    releaseBusiness();await expect(business.locator('#tr-add')).toBeEnabled();await business.close();
   }
  }
  if(!process.argv.includes('--report-header')&&!onlyPlan){
   await checkReviewedCategoryChanges({context,origin,fixture,payments:combinedBudget.payments,childId:combinedBudget.child_id,reviewId:combinedBudget.review_purchases[name==='webkit'?'phone':'desktop'],phone:name==='webkit'});
   checks.push(name+' '+(name==='webkit'?'phone':'desktop')+': reviewed recategorizations preserve the ledger; a manual review updates the Ask count immediately and after reload');
  }
  if(!process.argv.includes('--category-edits')&&!onlyPlan){
   await checkAnalysisHeader({context,origin,phone:name==='webkit'});
   checks.push(name+': Analysis controls, focus, resize and drilled-ledger layout');
  }
  if(includesPlan){
   await checkPlanTrajectory({context,origin,width:name==='webkit'?390:1440,engine:name,database:join(dir,'db.sqlite')});
   checks.push(name+': direct and full-dashboard Plan, receipt installments, historical controls, debt comparisons, replaceable budget reservation and ledger integrity');
  }
  await context.close();await browser.close();browser=null;
 }
 console.log(JSON.stringify({checks}));
}finally{await browser?.close();server?.kill('SIGTERM');await rm(dir,{recursive:true,force:true});}
