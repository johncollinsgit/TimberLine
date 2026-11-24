import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit} from 'playwright';
import {expect} from '@playwright/test';

// Actual authenticated app and built assets, disposable fictional database.
// Only the new provider transport is mocked; no bank credentials or paid APIs.
const exec=promisify(execFile),base=resolve(),probe=createServer();
await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;await new Promise(done=>probe.close(done));
const dir=await mkdtemp(join(tmpdir(),'codex-money-flow-simplefin-')),origin=`http://127.0.0.1:${port}`;
const evidence=resolve(process.env.TRAJECTORY_SIMPLEFIN_EVIDENCE||'test-results/trajectory-simplefin');
await mkdir(evidence,{recursive:true});await writeFile(join(dir,'db.sqlite'),'');
const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:origin,APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',APP_CONFIG_CACHE:join(dir,'config.php'),DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'db.sqlite'),DB_URL:'',DATABASE_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',SESSION_SECURE_COOKIE:'false',SESSION_DOMAIN:'',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',LOG_CHANNEL:'null',BCRYPT_ROUNDS:'4',TRAJECTORY_ENABLED:'true',TRAJECTORY_AI_ENABLED:'false',NIGHTWATCH_ENABLED:'false',TELESCOPE_ENABLED:'false',PULSE_ENABLED:'false'};
let server,browser;
try{
  await exec('php',['artisan','migrate','--force','--no-interaction'],{cwd:base,env,maxBuffer:2e6});
  const fixture=JSON.parse((await exec('php',['scripts/trajectory/money-flow-browser-fixture.php'],{cwd:base,env})).stdout);
  server=spawn('php',['artisan','serve','--host=127.0.0.1',`--port=${port}`,'--no-reload'],{cwd:base,env:{...env,SIMPLEFIN_ENABLED:'true',SIMPLEFIN_LINK_SPACE_IDS:`${fixture.space_id},${fixture.company_id}`},stdio:'ignore'});
  let ready=false;for(let i=0;i<100;i++){try{if((await fetch(origin+'/up')).ok){ready=true;break;}}catch{}await new Promise(done=>setTimeout(done,100));}assert.ok(ready);
  for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
    browser=await engine.launch();
    for(const width of [1440,390]){
      const context=await browser.newContext({viewport:{width,height:950},reducedMotion:'reduce'});
      let connected=false,claim=null,mapping=null;
      await context.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(url.origin!==origin)return route.abort();
        if(url.pathname.endsWith('/banks/simplefin')){
          claim=request.postDataJSON();
          return route.fulfill({json:{ok:true,connection_id:900,needs_mapping:true}});
        }
        if(url.pathname.endsWith('/banks/900/accounts')){
          if(request.method()==='POST'){
            mapping=request.postDataJSON();connected=true;
            return route.fulfill({json:{ok:true}});
          }
          return route.fulfill({json:{provider:'simplefin',accounts:[
            {id:'cash-remote',name:'Fictional SimpleFIN checking',currency:'USD',reported_balance_cents:100050,existing:false},
            {id:'card-remote',name:'Fictional SimpleFIN card',currency:'USD',reported_balance_cents:-50050,existing:false},
          ],manual_accounts:[{id:777,space_id:fixture.space_id,name:'Fictional balance snapshot',kind:'cash',currency:'USD'}]}});
        }
        if(url.pathname.endsWith('/banks/900')&&request.method()==='DELETE'){
          connected=false;return route.fulfill({json:{ok:true}});
        }
        if(url.pathname.endsWith('/dashboard')&&connected){
          const response=await route.fetch(),data=await response.json();
          data.connections.push({id:900,provider:'simplefin',status:'connected',institution_name:'SimpleFIN Bridge',synced_at:new Date().toISOString(),managed_here:true,history_complete:true,errors:[]});
          return route.fulfill({response,json:data});
        }
        return route.continue();
      });
      const csrf=(await(await context.request.get(origin+'/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];assert.ok(csrf);
      assert.ok((await context.request.post(origin+'/login',{form:{email:'flow@fixture.invalid',password:'Fictional-flow-only-2028!',_token:csrf},headers:{Accept:'application/json'}})).ok());
      const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));page.setDefaultTimeout(15000);
      await page.goto(`${origin}/trajectory?space=${fixture.space_id}&tab=accounts`);
      if(await page.locator('#tr-splash-skip').isVisible())await page.locator('#tr-splash-skip').click();
      await page.getByRole('button',{name:'Connect a bank',exact:true}).click();
      await expect(page.getByRole('dialog')).toContainText('Choose a bank connection');
      await page.getByRole('button',{name:'Use SimpleFIN',exact:true}).click();
      await expect(page.getByLabel('SimpleFIN setup token')).toHaveAttribute('type','password');
      await expect(page.getByRole('dialog')).toContainText('Subscription billing is managed by SimpleFIN');
      await page.getByLabel('SimpleFIN setup token').fill('fictional-browser-setup-token');
      await page.getByRole('button',{name:'Connect & review accounts',exact:true}).click();
      await expect(page.getByRole('dialog')).toContainText('Choose who owns each bank account');
      await page.getByRole('combobox',{name:/^Owner/}).nth(0).selectOption(String(fixture.space_id));
      await page.getByRole('combobox',{name:/^Account type/}).nth(0).selectOption('cash');
      await expect(page.getByLabel('Confirm the current balance').nth(0)).toHaveValue('cash');
      await page.getByLabel('Confirm the current balance').nth(0).scrollIntoViewIfNeeded();
      await expect(page.getByLabel('Confirm the current balance').nth(0)).toBeInViewport();
      await page.getByRole('combobox',{name:/^Existing account/}).nth(0).selectOption('777');
      await page.getByRole('combobox',{name:/^Owner/}).nth(1).selectOption(String(fixture.company_id));
      await page.getByRole('combobox',{name:/^Account type/}).nth(1).selectOption('credit');
      await page.getByLabel('Confirm the current balance').nth(1).selectOption({label:'You owe $500.50'});
      await page.getByLabel('Confirm the current balance').nth(1).scrollIntoViewIfNeeded();
      await expect(page.getByLabel('Confirm the current balance').nth(1)).toBeInViewport();
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      await page.screenshot({path:join(evidence,`${name}-${width}-mapping.png`)});
      const overflow=await page.locator('#tr-fields').evaluate(node=>({width:node.clientWidth,scroll:node.scrollWidth,children:[...node.querySelectorAll('*:not(option)')].filter(child=>child.scrollWidth>child.clientWidth+1||child.getBoundingClientRect().right>node.getBoundingClientRect().right).map(child=>({tag:child.tagName,class:child.className,width:child.clientWidth,scroll:child.scrollWidth,right:child.getBoundingClientRect().right,min:getComputedStyle(child).minWidth}))}));
      assert.equal(overflow.scroll<=overflow.width,true,`Bank mapping must wrap inside the dialog: ${JSON.stringify(overflow)}`);
      assert.equal(await page.locator('.tr-bank-mapping').evaluateAll(nodes=>nodes.every(node=>node.scrollHeight<=node.clientHeight+1)),true,'Account groups must keep every field in the dialog scroll area');
      await page.getByRole('button',{name:'Assign accounts & import',exact:true}).click();
      await expect(page.getByRole('dialog')).not.toBeVisible();
      assert.deepEqual(mapping.assignments,[{id:'cash-remote',space_id:fixture.space_id,kind:'cash',balance_multiplier:1,reuse_account_id:777},{id:'card-remote',space_id:fixture.company_id,kind:'credit',balance_multiplier:-1}]);
      assert.equal(claim.setup_token,'fictional-browser-setup-token');assert.ok(claim.request_id);assert.equal(claim.connection_id,null);
      await page.getByRole('button',{name:'Reconnect',exact:true}).click();
      await expect(page.getByLabel('SimpleFIN setup token')).toBeVisible();
      await page.keyboard.press('Escape');
      await page.getByRole('button',{name:'Disconnect',exact:true}).click();
      await expect(page.getByRole('dialog')).toContainText('does not cancel your SimpleFIN subscription');
      await page.locator('#tr-save').click();
      await expect(page.getByRole('dialog')).not.toBeVisible();
      assert.deepEqual(errors,[]);
      await context.close();
    }
    await browser.close();browser=null;
  }
  console.log('SimpleFIN chooser, masked setup, balance confirmation, mixed ownership, snapshot reuse, reconnect and disconnect passed in Chromium/WebKit at desktop/phone widths.');
}finally{
  await browser?.close();
  if(server && server.exitCode===null){server.kill();await new Promise(done=>server.once('exit',done));}
  await rm(dir,{recursive:true,force:true,maxRetries:10,retryDelay:100});
}
