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
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>{
    const url=new URL(route.request().url());
    if(url.origin!==origin){external.push(url.href);return route.abort();}
    if(route.request().method()==='POST')posts.push({path:url.pathname,body:route.request().postDataJSON()});
    return route.continue();
  });
  const page=await context.newPage();currentPage=page;page.on('pageerror',error=>errors.push(error.message));
  const url=origin+'/service-proposals/'+seed.versions.main.token;
  await page.goto(url);await page.getByLabel('Proposal password').fill(seed.password);
  await page.getByRole('button',{name:'Open proposal',exact:true}).click();
  const sectionSaved=page.waitForResponse(response=>response.url().endsWith('/activity')&&response.request().postDataJSON()?.event==='proposal_section');
  await page.locator('label[data-proposal-section-label="scope"]').click();assert.equal((await sectionSaved).status(),200);
  await page.locator('label[data-proposal-section-label="overview"]').click();
  await page.evaluate(()=>{const script=document.createElement('script');script.textContent='globalThis.unrelatedScriptExecuted=true';document.body.append(script);});
  assert.equal(await page.evaluate(()=>globalThis.unrelatedScriptExecuted),undefined,'Unrelated inline scripts stay blocked');
  await page.getByRole('link',{name:'Open campus review',exact:true}).click();
  await page.locator('#review-open').click();
  await page.getByLabel('Your review display name').selectOption('Ross');
  await page.getByRole('button',{name:'Remember my display name'}).click();
  await page.waitForFunction(()=>document.querySelector('[data-campus-review-display-name]').textContent==='Ross');
  await page.locator('#review-close').click();
  const pageSaved=page.waitForResponse(response=>response.url().endsWith('/activity')&&response.request().postDataJSON()?.event==='campus_page'&&response.request().postDataJSON()?.page==='projects');
  await page.locator('[data-section="projects"]').first().click();assert.equal((await pageSaved).status(),200);
  await page.locator('[data-tour-open]').first().click();
  await page.waitForResponse(response=>response.url().endsWith('/activity')&&response.request().postDataJSON()?.event==='tour_scene');
  const sceneSaved=page.waitForResponse(response=>response.url().endsWith('/activity')&&response.request().postDataJSON()?.scene===7);
  await page.locator('[data-tour-scene="6"]').click();assert.equal((await sceneSaved).status(),200);
  console.log(`${engine}: proposal sections, campus navigation, selected name and scene 7 recorded; checking natural playback completion`);
  const completion=page.waitForResponse(response=>response.url().endsWith('/activity')&&response.request().postDataJSON()?.event==='tour_completed',{timeout:60000});
  await page.locator('[data-tour-play]').click();assert.equal((await completion).status(),200);
  const journal=fixture('activity',seed.versions.main.id), events=journal.events;
  assert.equal(journal.unavailable,false);assert.equal(journal.limited,false);
  assert.ok(events.some(event=>event.event==='link_opened'));
  assert.ok(events.some(event=>event.event==='unlocked'));
  assert.ok(events.some(event=>event.event==='proposal_section'&&event.page==='scope'));
  assert.ok(events.some(event=>event.event==='campus_page'&&event.page==='projects'&&event.name==='Ross'));
  assert.ok(events.some(event=>event.event==='tour_scene'&&event.scene===7));
  assert.ok(events.some(event=>event.event==='tour_completed'&&event.scene===7));
  assert.equal(new Set(events.map(event=>event.visit)).size,1);
  const activityPosts=posts.filter(post=>post.path.endsWith('/activity'));
  assert.ok(activityPosts.length>=5);
  for(const post of activityPosts)assert.deepEqual(Object.keys(post.body).sort(),['event','event_id','page','scene','version_hash']);
  assert.deepEqual(external,[]);assert.deepEqual(errors,[]);
  await page.screenshot({path:resolve(evidence,'tour-completed.png'),fullPage:true});
  results.push('Actual password gate, hashed proposal script, section/campus navigation, selected name, scene 7 and natural final-scene playback persist in one encrypted visit. Unrelated inline scripts stay blocked; no external requests or form contents collected.');
  await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'pass',results,eventCount:events.length,synthetic:true},null,2));
  console.log(JSON.stringify({engine,status:'pass',results,evidence},null,2));
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
