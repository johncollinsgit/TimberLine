// Local fictional SQLite + real app routes. AI replies and microphone denial are
// explicit browser fixtures; this test never establishes provider acceptance.
import {chromium,webkit} from 'playwright';
import {spawn,execFileSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root=process.cwd();fs.mkdirSync(path.join(root,'.tmp'),{recursive:true});
const directory=fs.mkdtempSync(path.join(root,'.tmp/gerald-'));
const database=path.join(directory,'trajectory-gerald-preview.sqlite'),url='http://127.0.0.1:8098';
fs.writeFileSync(database,'');fs.mkdirSync(path.join(root,'output/trajectory/gerald'),{recursive:true});
const env={...process.env,APP_ENV:'local',APP_DEBUG:'true',APP_KEY:'base64:'+randomBytes(32).toString('base64'),APP_URL:url,DB_CONNECTION:'sqlite',DB_URL:'',DATABASE_URL:'',DB_DATABASE:database,SESSION_DRIVER:'file',CACHE_STORE:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',TRAJECTORY_ENABLED:'true',PULSE_ENABLED:'false',TELESCOPE_ENABLED:'false',NIGHTWATCH_ENABLED:'false',EVERBRANCH_AI_BRANCH_API_KEY:''};
execFileSync('php',['artisan','migrate','--force'],{cwd:root,env,stdio:'pipe'});
let seed=fs.readFileSync(path.join(root,'tests/Support/trajectory-preview.php'),'utf8').replaceAll('dirname(__DIR__, 2)',JSON.stringify(root)).replaceAll('/tmp/trajectory-preview.sqlite',database);
seed+=`
foreach (\\App\\Models\\Trajectory\\Space::all() as $debtSpace) {
    $credit = Account::create(['space_id'=>$debtSpace->id,'source_key'=>'gerald-demo:'.$debtSpace->id,'name'=>'Fictional card','kind'=>'credit','balance_cents'=>180000,'observed_at'=>now(),'history_start'=>now()->subDays(90)]);
    Record::create(['space_id'=>$debtSpace->id,'kind'=>'debt','name'=>'Fictional card terms','data'=>['kind'=>'credit','account_id'=>$credit->id,'balance_cents'=>180000,'apr_bps'=>1200,'payment_cents'=>15000,'escrow_cents'=>0,'fees_cents'=>0,'next_due_on'=>now()->addMonth()->toDateString(),'observed_on'=>now()->toDateString(),'confirmed'=>true]]);
}
`;
fs.writeFileSync(path.join(directory,'seed.php'),seed);
execFileSync('php',[path.join(directory,'seed.php')],{cwd:root,env,stdio:'pipe'});
const log=fs.openSync(path.join(directory,'server.log'),'w');
const server=spawn('php',['-S','127.0.0.1:8098',path.join(root,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'public'),env,stdio:['ignore',log,log]});
try{
  let ready=false;for(let i=0;i<80;i++){try{const r=await fetch(url+'/up');if(r.ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}assert.ok(ready,'Local preview server did not start');
  for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
    const browser=await engine.launch({headless:true});
    try{
      const context=await browser.newContext({viewport:{width:1440,height:1100}}),page=await context.newPage(),errors=[];
      page.on('pageerror',e=>errors.push(e.message));
      await page.addInitScript(()=>{Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{throw new Error('Microphone permission denied. You can type instead.');}}});});
      await page.route('**/trajectory/spaces/*/gerald?*',async route=>{const response=await route.fetch(),body=await response.json();body.ai={...body.ai,ready:true,reason:null,audio_approved:true,budget_cents:500,credit_available_micros:5000000};await route.fulfill({json:body});});
      await page.route('**/trajectory/spaces/*/gerald/messages',async route=>{
        const input=route.request().postDataJSON(),space=route.request().url().match(/spaces\/(\d+)/)[1];
        const d=await (await page.request.get(url+`/trajectory/spaces/${space}/dashboard?range=year`)).json(),p=d.debt_progress;
        const money=v=>v===null?'not yet available':'$'+(v/100).toFixed(2),focus=d.transactions.filter(t=>input.focus_ids?.includes(t.id));
        await route.fulfill({json:{conversation_id:77,reply:'Very good. The income gap is '+money(p.income_gap_cents)+'. The paperwork has, regrettably, done its sums.',facts:{expected_income:money(p.expected_monthly_cents),income_needed:money(p.required_monthly_cents),income_gap:money(p.income_gap_cents),debt_payment:money(p.payment_monthly_cents)},category_options:focus.map(t=>({id:t.id,categories:['groceries','materials'],reason:'Was this for household groceries or business materials?'})),focus_transactions:focus.map(t=>({...t,space_id:Number(space)})),links:[],provisional:p.provisional}});
      });
      await page.goto(url+'/login');await page.getByLabel('Email address').fill('owner@trajectory.test');await page.getByLabel('Password',{exact:true}).fill('Trajectory-local-preview-2026!');
      await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('/login')),page.getByRole('button',{name:'Sign in',exact:true}).click()]);
      await page.goto(url+'/trajectory');await page.getByRole('heading',{name:'Your debt-free progress',exact:true}).waitFor();
      assert.equal(await page.getByRole('progressbar').count(),3);
      await page.getByRole('button',{name:/Start payoff plan|Edit payoff plan/}).click();
      await page.getByLabel('Payoff goal (months)',{exact:true}).fill('24');await page.getByLabel('Extra monthly payment floor ($)',{exact:true}).fill('100');
      await page.locator('#tr-save').click();await page.getByRole('dialog').waitFor({state:'hidden'});
      await page.getByRole('button',{name:'Edit payoff plan',exact:true}).waitFor();
      await page.screenshot({path:`output/trajectory/gerald/${name}-debt.png`});
      await page.getByRole('tab',{name:'Both',exact:true}).click();await page.getByRole('heading',{name:'Personal + business',exact:true}).waitFor();assert.equal(await page.getByRole('progressbar').count(),3);
      await page.locator('[data-tab="bud"]').click();await page.getByRole('button',{name:'Ask Gerald',exact:true}).waitFor();
      await page.locator('#tr-gerald-question').fill('What income do we need?');await page.getByRole('button',{name:'Ask Gerald',exact:true}).click();await page.getByText(/The paperwork has, regrettably/).waitFor();
      await page.getByRole('button',{name:'Start voice conversation',exact:true}).click();await page.locator('#tr-feedback').filter({hasText:/Microphone permission denied|Microphone recording is unavailable/}).waitFor();
      await page.screenshot({path:`output/trajectory/gerald/${name}-gerald.png`});
      await page.getByRole('tab',{name:'Personal',exact:true}).click();await page.locator('#tr-content[aria-busy="false"]').waitFor();await page.locator('[data-tab="transactions"]').click();await page.getByRole('button',{name:'Discuss with Gerald',exact:true}).first().click();
      await page.getByRole('button',{name:'Ask Gerald',exact:true}).waitFor();await page.locator('#tr-gerald-question').fill('What other categories might fit this purchase?');await page.getByRole('button',{name:'Ask Gerald',exact:true}).click();
      await page.getByRole('button',{name:'Review materials',exact:true}).first().click();await page.getByRole('dialog').getByRole('button',{name:'Apply this category',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});
      await page.setViewportSize({width:390,height:844});await page.locator('[data-tab="overview"]').click();await page.getByRole('heading',{name:'Your debt-free progress',exact:true}).waitFor();
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile viewport overflows');await page.screenshot({path:`output/trajectory/gerald/${name}-mobile.png`,fullPage:true});
      assert.deepEqual(errors,[]);console.log(`${name}: debt plans, three thermometers, Gerald, alternative categories, denied microphone and mobile layout passed`);
    }finally{await browser.close();}
  }
}finally{server.kill('SIGTERM');fs.closeSync(log);}
