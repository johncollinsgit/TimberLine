import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {createServer} from 'node:https';
import {request as httpRequest} from 'node:http';
import {once} from 'node:events';
import {mkdir, readFile, writeFile, unlink, rm} from 'node:fs/promises';
import {resolve, dirname, basename} from 'node:path';
import {pathToFileURL} from 'node:url';

// Run from the repository root. Only the loopback proxy is reachable; production
// routes/controllers/domain/CSRF are real. No API responses are fabricated.
const root = resolve('.'), engine = process.env.PROPOSAL_BROWSER_ENGINE || 'chromium';
assert.ok(['chromium', 'webkit'].includes(engine));
const php = process.env.ENTERPRISE_TEST_PHP;
assert.ok(php, 'ENTERPRISE_TEST_PHP must identify the local PHP binary');
const {chromium, webkit} = await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE || 'node_modules/playwright/index.mjs')).href);
const testing = resolve('storage/framework/testing');
await mkdir(testing, {recursive: true});
const database = resolve(testing, `proposal-review-browser-${Date.now()}.sqlite`);
await writeFile(database, '');
const origin = 'https://127.0.0.1:8895';
const evidence = resolve(process.env.PROPOSAL_BROWSER_EVIDENCE || `../evidence/proposals/financial-${engine}`);
await mkdir(evidence, {recursive: true});
const env = {...process.env, PROPOSAL_REVIEW_BROWSER: '1', APP_ENV: 'testing',
  APP_URL: origin, DB_CONNECTION: 'sqlite', DB_DATABASE: database,
  APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', LOG_CHANNEL: 'null'};
const fixture = (...args) => {
  const result = spawnSync(php, ['tests/Support/proposal-review-browser-fixture.php', ...args], {env, encoding:'utf8',windowsHide:true});
  assert.equal(result.status, 0, result.stdout + result.stderr);
  return JSON.parse(result.stdout);
};
let server, proxy, browser, currentPage, serial = Promise.resolve(), dispatched = 0, serverLog='';
const errors = [], external = [], posts = [], results = [], isolatedResponses = [];
const keyFile = database+'.key', certFile = database+'.crt';
try {
  const seed = fixture('seed');
  await writeFile(resolve(evidence, 'fixture.json'), JSON.stringify({engine, synthetic:true, owner:seed.owner_id, tenant:seed.tenant_id, versionIds:Object.fromEntries(Object.entries(seed.versions).map(([key,value]) => [key,value.id]))},null,2));
  const generated = spawnSync(process.env.ENTERPRISE_TEST_OPENSSL || (process.platform==='win32'?'C:/Program Files/Git/usr/bin/openssl.exe':'openssl'),
    ['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyFile,'-out',certFile,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'], {encoding:'utf8',windowsHide:true});
  assert.equal(generated.status,0,'Disposable loopback certificate generation failed');
  const key=await readFile(keyFile),cert=await readFile(certFile);await unlink(keyFile);await unlink(certFile);
  server=spawn(php,['-S','127.0.0.1:8894','tests/Support/proposal-review-browser-router.php'],{env,windowsHide:true,stdio:['ignore','pipe','pipe']});
  for(const stream of [server.stdout,server.stderr])stream.on('data',chunk=>{serverLog+=chunk;});
  proxy=createServer({key,cert},(req,res)=>{
    // Keep the actual public 30/min limiter enabled, including refreshes and
    // failed requests. The synthetic multi-person scenario respects its budget.
    serial=serial.then(async()=>{
      await new Promise(done=>setTimeout(done,Math.max(0,dispatched+2150-Date.now())));
      // A browser may cancel a queued refresh while navigating. It must not
      // leave an unended upstream request holding every later real request.
      if(req.destroyed||res.destroyed)return;
      dispatched=Date.now();
      await new Promise(done=>{
        let upstream;
        const finish=()=>{req.off('aborted',abort);res.off('close',abort);done();};
        const abort=()=>{upstream?.destroy();finish();};
        upstream=httpRequest({hostname:'127.0.0.1',port:8894,path:req.url,method:req.method,headers:req.headers},response=>{
          if(res.destroyed){response.destroy();finish();return;}
          res.writeHead(response.statusCode,response.headers);response.pipe(res);
          response.once('end',finish);response.once('close',finish);
        });
        req.once('aborted',abort);res.once('close',abort);
        upstream.once('error',()=>{if(!res.destroyed){if(!res.headersSent)res.writeHead(503);res.end('Fixture starting');}finish();});
        if(req.aborted||res.destroyed){abort();return;}
        if(req.readableEnded)upstream.end();else req.pipe(upstream);
      });
    });
  });proxy.listen(8895,'127.0.0.1');await once(proxy,'listening');
  browser=await(engine==='webkit'?webkit:chromium).launch({headless:true});
  const inspect = () => {
    const result = spawnSync(php, ['tests/Support/proposal-financial-browser-inspect.php'], {env, encoding:'utf8',windowsHide:true});
    assert.equal(result.status, 0, result.stdout+result.stderr);
    return JSON.parse(result.stdout);
  };
  const before = inspect();
  const context = await browser.newContext({ignoreHTTPSErrors:true, viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>{
    const url=new URL(route.request().url());
    if(url.origin!==origin){external.push(url.href);return route.abort();}
    if(route.request().method()==='POST')posts.push({path:url.pathname,body:route.request().postData()});
    return route.continue();
  });
  const page = await context.newPage(); currentPage=page;
  page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(()=>{globalThis.financialPolicyViolations=[];document.addEventListener('securitypolicyviolation',e=>financialPolicyViolations.push({directive:e.violatedDirective,blocked:e.blockedURI}));});
  const url = origin+'/service-proposals/'+seed.versions.main.token;
  await page.goto(url);await page.getByLabel('Proposal password').fill(seed.password);
  await page.getByRole('button',{name:'Open proposal',exact:true}).click();
  let documentResponse = await page.goto(url+'?section=scope');
  const fixedNavigation=await readFile(resolve('resources/js/service-proposals/mobile-navigation.js'),'utf8');
  const fixedActivity=await readFile(resolve('resources/js/service-proposals/activity.js'),'utf8');
  async function assertMenuAndActivityPolicy(response) {
    assert.equal(await page.locator('script').count(),2,'Only the exact local menu and activity scripts are rendered');
    const expected=[];
    for(const [marker,source] of [['data-proposal-navigation',fixedNavigation],['data-review-activity-script',fixedActivity]]) {
      assert.equal(await page.locator('script['+marker+']').count(),1);
      const rendered=await page.locator('script['+marker+']').textContent();
      assert.equal(rendered,source,'Each controller equals its reviewed source byte-for-byte');
      expected.push("'sha256-"+createHash('sha256').update(rendered).digest('base64')+"'");
    }
    const csp=response.headers()['content-security-policy'];
    assert.match(csp,/default-src 'none'/);
    const scripts=csp.match(/(?:^|;)\s*script-src\s+([^;]+)/);
    assert.deepEqual(scripts?.[1].trim().split(/\s+/).sort(),expected.sort(),'Only both exact hashes; no self, unsafe-inline or external script source');
    assert.equal(csp.match(/(?:^|;)\s*script-src-attr\s+([^;]+)/)?.[1].trim(),"'none'");
    assert.equal(csp.match(/(?:^|;)\s*connect-src\s+([^;]+)/)?.[1].trim(),"'self'");
  }
  await assertMenuAndActivityPolicy(documentResponse);
  async function post(button) {
    [documentResponse]=await Promise.all([page.waitForNavigation(),button.click()]);
    assert.equal(new URL(page.url()).searchParams.get('section'),'scope');
    assert.equal(new URL(page.url()).hash,'#usage-estimator');
    assert.equal(await page.locator('[data-proposal-section="scope"]').isChecked(),true);
    assert.equal(await page.locator('.proposal-section-panel:visible').count(),1);
  }
  for (const [index,count] of [[0,1],[1,3],[2,2]]) {
    await page.locator('[data-financial-setup] > summary').click();
    await page.locator('#financial-name').fill(['One workspace comparison','Full bundle comparison','Two departments · scope pending'][index]);
    const offer=await page.locator('#financial-offer option').nth(index===0?1:2).getAttribute('value');
    await page.locator('#financial-offer').selectOption(offer);
    await page.locator('#financial-count').selectOption(String(count));
    await page.locator('#financial-profile').selectOption(index===1?'moderate':'light');
    assert.equal(await page.locator('#financial-management').inputValue(),'');
    await page.locator('#financial-management').selectOption(index===1?'department':'university');
    await post(page.getByRole('button',{name:'Set up scenario',exact:true}));
    assert.equal(await page.locator('[data-financial-department]').count(),count);
    assert.equal(await page.locator('#scenario-method').inputValue(),'');
    if(index===1){
      await page.locator('#dept-1-profile').selectOption('busy');
      await post(page.getByRole('button',{name:'Apply profile to department 2',exact:true}));
      assert.equal(await page.locator('#dept-0-questions').inputValue(),'500');
      assert.equal(await page.locator('#dept-1-questions').inputValue(),'2000');
      await page.locator('#dept-1-method').selectOption('actual');
    }
    if(index===0){await page.locator('#dept-0-label').fill('<script>fictional private label</script>');await page.locator('#scenario-method').selectOption('prepaid');}
    if(index===1){
      for(const [label,width,height] of [['desktop-edit',1440,1000],['ipad-edit',834,1194],['mobile-edit',390,844]]){
        await page.setViewportSize({width,height});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,label+' overflow');
        const controls=await page.locator('[data-financial-edit] input:visible:not([type=checkbox]),[data-financial-edit] select:visible,[data-financial-edit] button:visible').evaluateAll(ns=>ns.map(n=>({name:n.name,height:n.getBoundingClientRect().height,label:n.labels?.[0]?.textContent?.trim()||n.textContent.trim()})));
        assert.ok(controls.every(c=>c.height>=44&&c.label));
        await page.locator('#scenario-name').scrollIntoViewIfNeeded();
        await page.screenshot({path:resolve(evidence,label+'.png'),fullPage:false});
        results.push({name:label,width,height,controls,overflow:false});
      }
      await page.setViewportSize({width:1440,height:1000});
      await page.locator('#dept-0-model').focus();
      await page.keyboard.press('Tab');
      assert.equal(await page.locator('#dept-0-questions').evaluate(n=>n===document.activeElement),true);
    }
    await post(page.getByRole('button',{name:'Add to comparison',exact:true}));
    assert.equal(await page.locator('[data-financial-result]').count(),index+1);
  }
  const cards=page.locator('[data-financial-result]');
  assert.match(await cards.nth(0).innerText(),/1,000.00 \/month/);
  assert.match(await cards.nth(1).innerText(),/2,500.00 \/month/);
  assert.match(await cards.nth(2).innerText(),/Pending · no matching fully modeled package/);
  await assertMenuAndActivityPolicy(documentResponse);
  assert.equal(await page.locator('[data-financial-prepare]').count(),0);
  await page.reload();assert.equal(await cards.count(),3);
  const sizes=[['desktop',1440,1000],['ipad',834,1194],['mobile',390,844],['zoom-reflow',640,450]];
  for(const [name,width,height] of sizes){
    await page.setViewportSize({width,height});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,name+' overflow');
    assert.equal(await page.locator('[data-proposal-panel="scope"]').isVisible(),true);
    const buttons=await page.locator('#usage-estimator button:visible').evaluateAll(ns=>ns.map(n=>({label:n.textContent.trim(),height:n.getBoundingClientRect().height,width:n.getBoundingClientRect().width})));
    assert.ok(buttons.every(b=>b.height>=44&&b.width>=44));
    await page.locator('#usage-estimator').scrollIntoViewIfNeeded();
    await page.screenshot({path:resolve(evidence,name+'-cart.png'),fullPage:false});
    results.push({name,width,height,oneFinancialPanel:true,buttons,overflow:false});
  }
  // Native radio arrows and the real POST controls remain keyboard reachable.
  const proposalMenu=page.locator('.proposal-menu-toggle');
  assert.equal(await proposalMenu.isVisible(),true,'Reflow section controls require the visible proposal menu');
  await proposalMenu.click();
  await page.locator('.proposal-section-controls').waitFor({state:'visible'});
  await page.locator('[data-proposal-section="overview"]').focus();
  assert.equal(await page.locator('[data-proposal-section="overview"]').evaluate(node=>node===document.activeElement),true,'Native section-radio focus inside the opened menu');
  await page.keyboard.press('ArrowRight');await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('[data-proposal-section="scope"]').isChecked(),true);
  await cards.nth(0).getByRole('button',{name:/Remove One workspace/}).focus();
  await Promise.all([page.waitForNavigation(),page.keyboard.press('Enter')]);
  assert.equal(await cards.count(),2);
  await page.reload();assert.equal(await cards.count(),2);
  await page.emulateMedia({media:'print'});assert.equal(await page.locator('#usage-estimator').isVisible(),false);await page.emulateMedia({media:'screen'});
  const isolated=await browser.newContext({ignoreHTTPSErrors:true});
  await isolated.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const other=await isolated.newPage(); currentPage=other;
  other.on('response',response=>{if(response.request().isNavigationRequest())isolatedResponses.push({status:response.status(),path:new URL(response.url()).pathname,method:response.request().method()});});
  other.on('pageerror',error=>errors.push(error.message));
  const lockedResponse=await other.goto(url);assert.equal(lockedResponse.status(),200,'Isolated proposal password page is available');
  await other.getByLabel('Proposal password').fill(seed.password);
  const [unlockResponse]=await Promise.all([
    other.waitForNavigation({waitUntil:'load'}),
    other.getByRole('button',{name:'Open proposal',exact:true}).click()
  ]);
  assert.equal(unlockResponse.status(),200,'Isolated customer unlock must finish successfully');
  await other.locator('#financial-management').waitFor({state:'attached'});
  const isolatedScopeResponse=await other.goto(url+'?section=scope');
  assert.equal(isolatedScopeResponse.status(),200,'Isolated scope page must load successfully');
  assert.equal(await other.locator('#financial-management').inputValue(),'');
  assert.equal(await other.locator('[data-financial-result]').count(),0);
  await isolated.close(); currentPage=page;
  assert.deepEqual(inspect(),before,'Planning must not change any encrypted proposal/access/business row');
  assert.deepEqual(external,[]);assert.deepEqual(errors,[]);
  assert.deepEqual(await page.evaluate(()=>globalThis.financialPolicyViolations),[]);
  assert.ok(posts.every(p=>p.path.endsWith('/unlock')||p.path.endsWith('/financial-planning')||p.path.endsWith('/activity')));
  for(const post of posts.filter(p=>p.path.endsWith('/activity'))){
    const report=JSON.parse(post.body);
    assert.deepEqual(Object.keys(report).sort(),['event','event_id','page','scene','version_hash']);
    assert.equal(report.event,'proposal_section');assert.ok(['overview','approach','scope','terms','review'].includes(report.page));assert.equal(report.scene,null);
  }
  await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'pass',synthetic:true,results,comparisonCount:3,persistedReload:true,isolatedSession:true,exactBusinessRowsUnchanged:true,external,errors,posts:posts.length},null,2));
  console.log(JSON.stringify({engine,status:'pass',viewports:results.length,comparisonCount:3,exactBusinessRowsUnchanged:true,external:0},null,2));
}catch(error){
  if(currentPage)await currentPage.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});
  await writeFile(resolve(evidence,'failure.txt'),error.stack+'\n'+JSON.stringify({url:currentPage?.url(),errors,external,posts:posts.length,isolatedResponses},null,2));throw error;
}finally{
  await writeFile(resolve(evidence,'server.log'),serverLog);
  await browser?.close();if(proxy)await new Promise(done=>proxy.close(done));
  if(server){server.kill();await once(server,'exit').catch(()=>{});}
  assert.equal(dirname(database),testing);assert.match(basename(database),/^proposal-review-browser-[0-9]+\.sqlite$/);
  await rm(database+'.state',{recursive:true,force:true});
  for(const file of[database,database+'-wal',database+'-shm',keyFile,certFile])await unlink(file).catch(()=>{});
}
