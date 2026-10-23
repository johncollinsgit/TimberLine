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
const evidence = resolve(process.env.PROPOSAL_BROWSER_EVIDENCE || `../evidence/proposals/review-${engine}`);
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
const errors = [], external = [], posts = [], results = [];
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
  const contexts=[];
  async function reviewer(name,version=seed.versions.main) {
    const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,viewport:{width:1440,height:1000}});contexts.push(context);
    await context.route('**/*',route=>{
      const url=new URL(route.request().url());
      if(url.origin!==origin){external.push(url.href);return route.abort();}
      if(route.request().method()==='POST')posts.push({path:url.pathname,body:route.request().postDataJSON()});
      return route.continue();
    });
    const page=await context.newPage();currentPage=page;page.on('pageerror',error=>errors.push(error.message));
    const url=origin+'/service-proposals/'+version.token;
    await page.goto(url);await page.getByLabel('Proposal password').fill(seed.password);
    await page.getByRole('button',{name:'Open proposal',exact:true}).click();
    await page.getByRole('link',{name:'Open campus review',exact:true}).click();
    await open(page);await setName(page,name);
    return {context,page,url,version};
  }
  async function open(page){
    currentPage=page;
    if(!await page.locator('#review-dialog').evaluate(node=>node.open))await page.locator('#review-open').click();
    await page.waitForFunction(()=>!document.querySelector('[data-campus-review-status]').textContent.startsWith('Loading'));
  }
  async function setName(page,name){
    await page.getByLabel('Your review display name').selectOption(name);
    await page.getByRole('button',{name:'Remember my display name'}).click();
    await page.waitForFunction(value=>document.querySelector('[data-campus-review-display-name]').textContent===value,name);
  }
  async function refresh(page){await page.locator('#review-refresh').click();await page.waitForFunction(()=>/refreshed|bounded/.test(document.querySelector('[data-campus-review-status]').textContent));}
  async function save(page,text){
    await page.locator('#review-body').fill(text);await page.getByRole('button',{name:'Save page comment',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-campus-review-status]').textContent.includes('Comment saved'));
    assert.equal(await page.locator('#review-body').inputValue(),'');
  }
  async function choosePage(page,value){
    await page.locator('[data-campus-review-page]').selectOption(value);
    await page.waitForFunction(value=>document.querySelector('[data-campus-review-page]').value===value,value);
    await page.waitForFunction(()=>/refreshed|bounded/.test(document.querySelector('[data-campus-review-status]').textContent));
  }
  const ross=await reviewer('Ross'),sarah=await reviewer('Sarah'),paul=await reviewer('Paul');
  console.log(`${engine}: three actual password-unlocked contexts ready`);
  // Aborting a queued refresh must leave the following authenticated request usable.
  await ross.page.evaluate(async()=>{
    const url=globalThis.EverbranchCampusReviewBoot.urls.data_pages.home;
    const controller=new AbortController();
    const first=fetch(url,{credentials:'same-origin'}).then(response=>{if(!response.ok)throw Error('Real refresh failed');});
    const cancelled=fetch(url,{credentials:'same-origin',signal:controller.signal}).then(()=>{throw Error('Queued refresh was not cancelled');},error=>{if(error.name!=='AbortError')throw error;});
    setTimeout(()=>controller.abort(),10);
    await Promise.all([first,cancelled]);
  });
  await refresh(ross.page);
  results.push('A cancelled queued refresh does not stall later authenticated requests.');
  const hostile='<img src=x onerror="globalThis.reviewInjected=true"><script>alert(1)</script> & quoted feedback';
  await save(ross.page,hostile);await refresh(sarah.page);
  assert.ok(await sarah.page.locator('#review-comments').innerText().then(text=>text.includes(hostile)));
  assert.equal(await sarah.page.locator('#review-comments img, #review-comments script').count(),0);
  assert.equal(await sarah.page.evaluate(()=>globalThis.reviewInjected),undefined);
  await save(sarah.page,'Sarah: the current owner and next action are clear.');
  await refresh(paul.page);assert.match(await paul.page.locator('#review-comments').innerText(),/Sarah: the current owner/);
  await ross.page.reload();await open(ross.page);assert.match(await ross.page.locator('#review-comments').innerText(),/Sarah: the current owner/);
  results.push('Three independent password-unlocked reviewer contexts share persisted, escaped page comments after refresh/reload.');
  console.log(`${engine}: shared persistent comments and hostile text passed`);
  // Same browser scope keeps per-page drafts; server comments are page-scoped.
  await ross.page.locator('#review-body').fill('Unsent home draft');
  await choosePage(ross.page,'buildings');assert.equal(await ross.page.locator('#review-body').inputValue(),'');
  assert.ok(!(await ross.page.locator('#review-comments').innerText()).includes(hostile));
  await save(ross.page,'Ross: building source provenance is visible.');
  await choosePage(ross.page,'home');assert.equal(await ross.page.locator('#review-body').inputValue(),'Unsent home draft');
  await ross.page.locator('#review-body').fill('');
  // Deliver the exact POST to the real server, then lose its response. Retry
  // after reload must keep its UUID and persist precisely one domain comment.
  const lost='One durable comment after a deliberately lost response';let original;
  await ross.page.route('**/campus/home/comments',async route=>{
    original=route.request().postDataJSON();const response=await route.fetch();assert.equal(response.status(),200);await route.abort('failed');
  },{times:1});
  await ross.page.locator('#review-body').fill(lost);await ross.page.getByRole('button',{name:'Save page comment',exact:true}).click();
  await ross.page.getByRole('button',{name:'Retry the exact unconfirmed comment',exact:true}).waitFor({state:'visible'});
  await ross.page.waitForFunction(()=>!document.querySelector('#review-comment-form button[type=submit]').disabled);
  await ross.page.reload();await open(ross.page);
  assert.equal(await ross.page.locator('#review-body').inputValue(),lost);assert.equal(await ross.page.locator('#review-body').evaluate(node=>node.readOnly),true);
  const retryPromise=ross.page.waitForRequest(request=>request.url().endsWith('/campus/home/comments')&&request.method()==='POST');
  await ross.page.getByRole('button',{name:'Retry the exact unconfirmed comment',exact:true}).click();
  assert.deepEqual((await retryPromise).postDataJSON(),original);
  await ross.page.waitForFunction(()=>document.querySelector('[data-campus-review-status]').textContent.includes('Comment saved'));
  const inspection=fixture('inspect');assert.equal(inspection.comments.filter(row=>row.payload.body===lost).length,1);
  results.push('Interrupted accepted POST retains its exact UUID/body across reload; retry confirms the single server record.');
  console.log(`${engine}: interrupted response and exact reload retry passed`);
  // Publication of identical immutable content deliberately has the same hash,
  // but a different version ID. Its draft must not leak across access links.
  const sarahBoot=await sarah.page.evaluate(()=>globalThis.EverbranchCampusReviewBoot);
  await sarah.page.locator('#review-body').fill('Only this exact Sarah publication draft');
  await sarah.page.goto(origin+'/service-proposals/'+seed.versions.expired.token);
  await sarah.page.getByLabel('Proposal password').fill(seed.password);await sarah.page.getByRole('button',{name:'Open proposal',exact:true}).click();
  await sarah.page.getByRole('link',{name:'Open campus review',exact:true}).click();await open(sarah.page);await setName(sarah.page,'Sarah');
  const twinBoot=await sarah.page.evaluate(()=>globalThis.EverbranchCampusReviewBoot);
  assert.equal(twinBoot.version_hash,sarahBoot.version_hash);assert.notEqual(twinBoot.version_id,sarahBoot.version_id);
  assert.equal(await sarah.page.locator('#review-body').inputValue(),'');
  await sarah.page.goto(sarahBoot.urls.current);await open(sarah.page);
  assert.equal(await sarah.page.locator('#review-body').inputValue(),'Only this exact Sarah publication draft');
  results.push('Equal-hash distinct publications isolate drafts by actual version ID.');
  console.log(`${engine}: equal-hash distinct publication drafts isolated`);
  // Storage denial cannot discard an unconfirmed intent in the open tab.
  await paul.page.evaluate(()=>{
    Object.defineProperty(Storage.prototype,'getItem',{configurable:true,value(){throw new DOMException('Synthetic storage denial','SecurityError');}});
    Object.defineProperty(Storage.prototype,'setItem',{configurable:true,value(){throw new DOMException('Synthetic storage denial','SecurityError');}});
  });
  let memoryIntent;const memoryText='Exact retry survives denied browser storage in this open tab';
  await paul.page.route('**/campus/home/comments',async route=>{memoryIntent=route.request().postDataJSON();const response=await route.fetch();assert.equal(response.status(),200);await route.abort('failed');},{times:1});
  await paul.page.locator('#review-body').fill(memoryText);await paul.page.getByRole('button',{name:'Save page comment',exact:true}).click();
  await paul.page.waitForFunction(()=>!document.querySelector('#review-comment-form button[type=submit]').disabled&&document.querySelector('#review-body').readOnly);
  assert.match(await paul.page.locator('[data-review-storage-warning]').innerText(),/Exact retry remains available/);
  assert.equal(await paul.page.locator('#review-body').inputValue(),memoryText);
  const memoryReplay=paul.page.waitForRequest(request=>request.url().endsWith('/campus/home/comments')&&request.method()==='POST');
  await paul.page.getByRole('button',{name:'Retry the exact unconfirmed comment',exact:true}).click();assert.deepEqual((await memoryReplay).postDataJSON(),memoryIntent);
  await paul.page.waitForFunction(()=>document.querySelector('[data-campus-review-status]').textContent.includes('Comment saved'));
  assert.equal(fixture('inspect').comments.filter(row=>row.payload.body===memoryText).length,1);
  results.push('Denied browser storage retains the exact uncertain intent in memory; retry confirms one server comment.');
  console.log(`${engine}: denied storage preserves exact uncertain intent`);
  // A parallel tab changes the self-selected name. The open tab's old actor
  // binding cannot silently rewrite attribution when it submits a stale intent.
  const boot=await ross.page.evaluate(()=>globalThis.EverbranchCampusReviewBoot);
  let heldRoute,heldResponse,received;
  const receivedOldRead=new Promise(done=>{received=done;});
  await ross.page.route('**/campus/home/review',async route=>{heldRoute=route;heldResponse=await route.fetch();assert.equal(heldResponse.status(),200);received();},{times:1});
  await ross.page.locator('#review-refresh').click();await receivedOldRead;
  const rename=await ross.context.request.post(boot.urls.name,{headers:{'X-CSRF-TOKEN':boot.csrf_token,Accept:'application/json'},data:{version_hash:boot.version_hash,build_id:boot.build_id,source_hash:boot.source_hash,reviewer_name:'Paul'}});
  assert.equal(rename.status(),200);
  await ross.page.locator('#review-body').fill('Stale Ross attribution must not be accepted');
  const rejected=ross.page.waitForResponse(response=>response.url().endsWith('/campus/home/comments')&&response.request().method()==='POST');
  await ross.page.getByRole('button',{name:'Save page comment',exact:true}).click();assert.equal((await rejected).status(),409);
  await ross.page.waitForFunction(()=>document.querySelector('#review-body').disabled);
  assert.equal(await ross.page.locator('#review-comments article').count(),0);
  await heldRoute.fulfill({response:heldResponse}).catch(()=>{});
  await ross.page.waitForTimeout(100);
  assert.equal(await ross.page.locator('#review-body').isDisabled(),true);
  assert.equal(await ross.page.locator('#review-comments article').count(),0);
  assert.equal(await ross.page.locator('[data-campus-review-display-name]').innerText(),'Review access unavailable');
  await refresh(ross.page);assert.equal(await ross.page.locator('[data-campus-review-display-name]').innerText(),'Paul');
  assert.equal(fixture('inspect').comments.filter(row=>row.payload.body==='Stale Ross attribution must not be accepted').length,0);
  results.push('Current server name binding rejects stale attribution409; delayed earlier real GET cannot restore comments or writes after lock.');
  console.log(`${engine}: stale actor rejected; delayed prelock response cannot restore access`);
  // Actual web CSRF middleware is active; no testing shortcut or excluded path.
  const csrfDenied=await ross.context.request.post(boot.urls.comment,{headers:{Accept:'application/json'},data:original});
  assert.equal(csrfDenied.status(),419);results.push('Actual middleware rejects missing CSRF with419.');
  // Keyboard and touch acceptance on actual renderer/dialog, including workflow.
  for(const [label,width,height]of[['desktop',1440,1000],['phone',390,844],['ipad-portrait',820,1180],['ipad-landscape',1180,820]]){
    await paul.page.setViewportSize({width,height});await open(paul.page);
    await paul.page.locator('#review-body').fill('Retained unsent '+label+' draft');
    const dimensions=await paul.page.evaluate(()=>({page:document.documentElement.scrollWidth,viewport:innerWidth,dialog:document.querySelector('#review-dialog').scrollWidth,client:document.querySelector('#review-dialog').clientWidth}));
    assert.ok(dimensions.page<=width+1,`${label} page overflow ${JSON.stringify(dimensions)}`);assert.ok(dimensions.dialog<=dimensions.client+1,`${label} dialog overflow ${JSON.stringify(dimensions)}`);
    await paul.page.screenshot({path:resolve(evidence,label+'.png'),fullPage:true});
    await paul.page.keyboard.press('Escape');assert.equal(await paul.page.locator('#review-dialog').evaluate(node=>node.open),false);
    // Native dialog close events are queued in WebKit. Require the actual
    // event-driven focus return, rather than sampling before that event runs.
    await paul.page.waitForFunction(()=>document.activeElement?.id==='review-open');
    assert.equal(await paul.page.evaluate(()=>document.activeElement.id),'review-open');await open(paul.page);
    assert.equal(await paul.page.locator('#review-body').inputValue(),'Retained unsent '+label+' draft');
  }
  await paul.page.goto(boot.urls.pages.workflows);await open(paul.page);
  assert.match(await paul.page.locator('#review-proposal').getAttribute('href'),/\/service-proposals\//);
  assert.equal(new URL(paul.page.url()).pathname.endsWith('/campus/workflows'),true);
  await paul.page.screenshot({path:resolve(evidence,'workflow-review.png'),fullPage:true});
  for(const[label,width,height]of[['phone',390,844],['ipad-portrait',820,1180]]){
    await paul.page.setViewportSize({width,height});
    assert.ok(await paul.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Workflow '+label+' page overflow');
    assert.ok(await paul.page.locator('#review-dialog').evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Workflow '+label+' dialog overflow');
    await paul.page.screenshot({path:resolve(evidence,'workflow-review-'+label+'.png'),fullPage:true});
  }
  results.push('Actual full-screen workflow retains review tools; desktop/phone/iPad portrait+landscape fit, Escape/focus return and unsent drafts pass.');
  console.log(`${engine}: four device layouts, focus, drafts and workflow tools passed`);
  for(const action of ['expire','revoke']){
    const version=seed.versions[action==='expire'?'expired':'revoked'];const person=await reviewer('Sarah',version);
    const boundaryBoot=await person.page.evaluate(()=>globalThis.EverbranchCampusReviewBoot);fixture(action,version.id);
    const response=await person.context.request.get(boundaryBoot.urls.data);assert.equal(response.status(),410);
    const denied=await person.context.request.post(boundaryBoot.urls.comment,{headers:{Accept:'application/json','X-CSRF-TOKEN':boundaryBoot.csrf_token},data:{version_hash:boundaryBoot.version_hash,build_id:boundaryBoot.build_id,source_hash:boundaryBoot.source_hash,expected_reviewer_name:'Sarah',body:'Forbidden after '+action,comment_key:crypto.randomUUID()}});assert.equal(denied.status(),410);
  }
  assert.equal(fixture('inspect').comments.some(row=>row.payload.body.startsWith('Forbidden after ')),false);
  assert.deepEqual(external,[]);assert.deepEqual(errors,[]);
  results.push('Expired/revoked versions deny real read/write410; no boundary comment persists; no external requests or JS errors.');
  await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'pass',results,external,errors,posts:posts.length,synthetic:true},null,2));
  console.log(JSON.stringify({engine,status:'pass',results,evidence},null,2));
  await writeFile(resolve(evidence,'server.log'),serverLog);
}catch(error){
  if(currentPage)await currentPage.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});
  await writeFile(resolve(evidence,'failure.txt'),error.stack+'\n'+JSON.stringify({url:currentPage?.url(),errors,external,posts:posts.length},null,2));throw error;
}finally{
  await writeFile(resolve(evidence,'server.log'),serverLog);
  await browser?.close();if(proxy)await new Promise(done=>proxy.close(done));
  if(server){server.kill();await once(server,'exit').catch(()=>{});}
  // All recursive cleanup is restricted to this exact generated disposable path.
  assert.equal(dirname(database),testing);assert.match(basename(database),/^proposal-review-browser-[0-9]+\.sqlite$/);
  await rm(database+'.state',{recursive:true,force:true});
  for(const file of[database,database+'-wal',database+'-shm',keyFile,certFile])await unlink(file).catch(()=>{});
}
