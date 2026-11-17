import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit} from 'playwright';
import {expect} from '@playwright/test';
import {verifyProfitAccountLedger,verifyProfitReportRefresh} from './trajectory-profit-loss.browser-helpers.mjs';
const exec=promisify(execFile),base=resolve(),probe=createServer();
await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;await new Promise(done=>probe.close(done));
const dir=await mkdtemp(join(tmpdir(),'codex-money-flow-')),origin=`http://127.0.0.1:${port}`,evidence=resolve(process.env.TRAJECTORY_PNL_LEDGER_EVIDENCE||'test-results/trajectory-profit-loss-ledger');
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
  const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));page.setDefaultTimeout(12000);
  for(const width of [390,1440]){await page.setViewportSize({width,height:950});await page.goto(origin+'/trajectory');await verifyProfitAccountLedger({page,fixture,width,name,evidence});await verifyProfitReportRefresh({page,fixture});checks.push(`${name} ${width}: account GL loading, signed source entries, reconcile/mismatch, sanitized error/retry and closed-dialog response isolation`);}
  assert.deepEqual(errors,[]);await context.close();await browser.close();browser=null;
 }
 console.log(JSON.stringify({checks,evidence}));
}finally{await browser?.close();server?.kill('SIGTERM');await rm(dir,{recursive:true,force:true});}
