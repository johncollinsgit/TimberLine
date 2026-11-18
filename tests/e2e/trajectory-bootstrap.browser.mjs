import assert from 'node:assert/strict';
import {mkdtemp,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit,expect} from '@playwright/test';
import {selectReportScope} from './trajectory-controls.browser-helpers.mjs';
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
   assert.deepEqual(errors,[]);await page.close();

   const failed=await context.newPage();await failed.setViewportSize({width,height:950});
   let releaseFailure;const failureGate=new Promise(done=>{releaseFailure=done;});
   await failed.route('**/dashboard?*',async route=>{const response=await route.fetch();await failureGate;await route.fulfill({response});});
   await failed.route('**/analysis?*',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Fictional projection is unavailable.'})}));
   await failed.goto(origin+'/trajectory');await expect(failed.locator('#tr-content [role="alert"]')).toContainText('Fictional projection is unavailable.');
   await expect(failed.locator('.vf-stat-row')).toHaveCount(0);assert.doesNotMatch(await failed.locator('#tr-content').innerText(),/\$0(?:\.00)?/);
   releaseFailure();await expect(failed.locator('#tr-add')).toBeEnabled();await expect(failed.locator('#tr-content [role="alert"]')).toContainText('Fictional projection is unavailable.');await failed.close();

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
