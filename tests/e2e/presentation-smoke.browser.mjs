import assert from 'node:assert/strict';
import {mkdtemp, mkdir, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {spawn, execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium, webkit} from 'playwright';

const exec = promisify(execFile), base = resolve(), probe = createServer();
await new Promise(done => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port;
await new Promise(done => probe.close(done));
const directory = await mkdtemp(join(tmpdir(), 'codex-team-calendar-presentation-'));
const evidence = resolve('test-results/presentation-smoke');
const origin = `http://127.0.0.1:${port}`;
await mkdir(evidence, {recursive:true});
await writeFile(join(directory, 'db.sqlite'), '');
const env = {...process.env, APP_ENV:'testing', APP_DEBUG:'false', APP_URL:origin,
  APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
  APP_CONFIG_CACHE:join(directory,'config.php'), DB_CONNECTION:'sqlite', DB_DATABASE:join(directory,'db.sqlite'),
  DB_URL:'', DATABASE_URL:'', CACHE_STORE:'array', SESSION_DRIVER:'database', SESSION_SECURE_COOKIE:'false',
  SESSION_DOMAIN:'', MAIL_MAILER:'array', QUEUE_CONNECTION:'sync', LOG_CHANNEL:'null', BCRYPT_ROUNDS:'4',
  TEAM_WORK_ENABLED:'true', TEAM_WORK_PREMIUM_ENABLED:'true', TEAM_OPERATIONS_ENABLED:'true',
  TEAM_WORK_ASANA_ENABLED:'false', EVERBRANCH_AI_STANDARD_ENABLED:'false',
  NIGHTWATCH_ENABLED:'false', TELESCOPE_ENABLED:'false', PULSE_ENABLED:'false'};
let server, browser;
const errors = [], writes = [], results = [];
try {
  await exec('php',['artisan','migrate','--force','--no-interaction'],{cwd:base,env,maxBuffer:2e6});
  const fixture = JSON.parse((await exec('php',['tests/e2e/fixtures/team-main-event-fixture.php'],{cwd:base,env,maxBuffer:2e6})).stdout);
  env.TEAM_WORK_TENANT_ALLOWLIST = `${fixture.tenant_id},${fixture.other_tenant_id}`;
  server = spawn('php',['artisan','serve','--host=127.0.0.1',`--port=${port}`,'--no-reload'],{cwd:base,env,stdio:'ignore'});
  let ready = false;
  for (let i=0;i<100;i++) {
    try { if ((await fetch(origin+'/up')).ok) { ready=true; break; } } catch {}
    await new Promise(done=>setTimeout(done,100));
  }
  assert.ok(ready,'Disposable Laravel server started');
  for (const [name,engine] of [['chromium',chromium],['webkit',webkit]]) {
    browser = await engine.launch();
    const context = await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
    const page=await context.newPage(); page.setDefaultTimeout(12000);
    page.on('pageerror',error=>errors.push(`${name}: ${error.message}`));
    for (const width of [1440,390]) {
      await page.setViewportSize({width,height:width<600?844:1000});
      assert.equal((await page.goto(origin+'/login')).status(),200);
      for (const selector of ['input[name="email"]','input[name="password"]','button[type="submit"]']) {
        assert.ok(await page.locator(selector).first().isVisible(),'Login controls remain visible');
      }
    }
    const csrf=(await(await context.request.get(origin+'/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok(csrf,'Login retains CSRF protection');
    assert.ok((await context.request.post(origin+'/login',{form:{email:'owner@fixture.invalid',password:'Fictional-local-only-2026!',_token:csrf},headers:{Accept:'application/json'}})).ok());
    await page.clock.setFixedTime(new Date('2026-11-08T01:00:00Z'));
    page.on('request',request=>{if(new URL(request.url()).pathname.startsWith('/team/')&&!['GET','HEAD'].includes(request.method()))writes.push(request.url());});
    for (const width of [1440,390]) {
      await page.setViewportSize({width,height:width<600?844:1000});
      assert.equal((await page.goto(origin+'/platform/promo')).status(),200);
      await page.locator('[data-studio-hero]').waitFor({state:'visible'});
      assert.ok(await page.locator('a[href*="/start"]').first().isVisible(),'Public primary action is visible');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Public page has no horizontal overflow');
      assert.equal((await page.goto(`${origin}/teams?tenant=${fixture.tenant}`)).status(),200);
      await page.waitForFunction(()=>window.TeamPremium?.context?.ready());
      await page.locator('#tw-list .teams-task-card').first().waitFor({state:'visible'});
      assert.ok(await page.locator('#tw-filter-toggle').isVisible(),'Teams controls remain available');
      await page.locator('#tw-filter-toggle').click();
      assert.ok(await page.locator('#tw-controls-panel').isVisible(),'Controls open');
      await page.locator('#tw-filter-toggle').click();
      assert.equal(await page.locator('#tw-controls-panel').isVisible(),false,'Controls close');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Teams has no horizontal overflow');
      await page.screenshot({path:join(evidence,`${name}-${width}.png`)});
      results.push({engine:name,width,public:true,teams:true});
    }
    const guest=await browser.newContext();
    const denied=await guest.request.get(`${origin}/teams?tenant=${fixture.tenant}`,{maxRedirects:0});
    assert.ok([302,401,403].includes(denied.status()),'Guest cannot open protected Teams');
    assert.ok(!(await denied.text()).includes('Fictional seasonal makers'),'Guest response contains no event data');
    await guest.close(); await context.close(); await browser.close(); browser=null;
  }
  assert.deepEqual(errors,[],'No browser runtime errors');
  assert.deepEqual(writes,[],'Presentation smoke performs no Teams writes');
  await writeFile(join(evidence,'results.json'),JSON.stringify({results,errors,writes},null,2));
  console.log('Presentation smoke: desktop/mobile Chromium and WebKit, public navigation, authenticated Teams controls, guest denial passed.');
} finally {
  if(browser)await browser.close();
  if(server)server.kill('SIGTERM');
  await rm(directory,{recursive:true,force:true});
}
