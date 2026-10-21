import {createRequire} from 'node:module';
import {spawn, execFileSync} from 'node:child_process';
import {mkdir, writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const {chromium, webkit} = require(process.env.PLAYWRIGHT_PACKAGE || 'playwright');

const root = fileURLToPath(new URL('../../', import.meta.url));
const evidence = path.join(root, 'output/retail/avery-visualizer-qa');
await mkdir(evidence, {recursive:true});
const port = Number(process.env.AVERY_VISUALIZER_QA_PORT || 8799);
const liveBase = process.env.AVERY_VISUALIZER_QA_BASE_URL;
if (liveBase && liveBase !== 'https://everbranch-retail-demo.on-forge.com') throw Error('Only the existing approved Avery preview host may be checked remotely.');
const base = liveBase || `http://127.0.0.1:${port}`;
let fixtureCode = 'visualizer-fixture-only';
if(liveBase){let input='';for await(const chunk of process.stdin)input+=chunk;fixtureCode=input.trim();if(!fixtureCode||fixtureCode.length>32)throw Error('Supply the existing access code through hidden standard input.');}
const hash = liveBase ? '' : execFileSync('php', ['-r', 'echo password_hash("visualizer-fixture-only", PASSWORD_BCRYPT);'], {encoding:'utf8'}).trim();
const env = {...process.env, APP_ENV:'staging', APP_DEBUG:'false', APP_URL:base,
  APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
  TENANCY_CANONICAL_SCHEME:'http', TENANCY_CANONICAL_LANDLORD_HOST:'127.0.0.1',
  TENANCY_LANDLORD_PRIMARY_HOST:'127.0.0.1', TENANCY_LANDLORD_HOSTS:'127.0.0.1',
  DB_CONNECTION:'sqlite', DB_DATABASE:path.join(evidence,'fixture.sqlite'), CACHE_STORE:'file',
  SESSION_DRIVER:'file', SESSION_SECURE_COOKIE:'false', QUEUE_CONNECTION:'sync', MAIL_MAILER:'array',
  LOG_CHANNEL:'stderr', BCRYPT_ROUNDS:'4', VIEW_COMPILED_PATH:path.join(evidence,'views'),
  TMPDIR:path.join(evidence,'tmp'), AVERY_VISUALIZER_LOCAL_QA:'1',
  RETAIL_SHOWROOM_ENABLED:'true', RETAIL_SHOWROOM_OWNER_EMAIL:'visualizer-owner@example.invalid',
  RETAIL_AVERY_PIN_ENABLED:'true', RETAIL_AVERY_PIN_HASH:hash, RETAIL_PRESENTER_HOST:'127.0.0.1',
  RETAIL_SHOWROOM_AI_ENABLED:'false', RETAIL_SHOWROOM_VOICE_ENABLED:'false',
  RETAIL_PRESENTER_TRANSPORT_ENABLED:'false', RETAIL_SIGNUP_CONFIRMATION_ENABLED:'false'};
await mkdir(env.VIEW_COMPILED_PATH,{recursive:true}); await mkdir(env.TMPDIR,{recursive:true});
if (!liveBase && process.env.AVERY_VISUALIZER_QA_REUSE !== '1') execFileSync('php', ['scripts/retail/avery-visualizer-fixture.php'], {cwd:root,env,stdio:'pipe'});
const server = liveBase ? null : spawn('php', ['-S',`127.0.0.1:${port}`,path.join(root,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {cwd:path.join(root,'public'),env,stdio:['ignore','pipe','pipe']});
let serverLog=''; server?.stdout.on('data',d=>serverLog+=d); server?.stderr.on('data',d=>serverLog+=d);
const rows=[];
try {
  for(let n=0;n<100;n++) {try{if((await fetch(base+'/avery')).status===200)break;}catch{} if(n===99)throw Error('Local QA server unavailable');await new Promise(r=>setTimeout(r,200));}
  for(const [engine,driver] of [['chromium',chromium],['webkit',webkit]].filter(([name])=>!process.env.AVERY_VISUALIZER_QA_ENGINES||process.env.AVERY_VISUALIZER_QA_ENGINES.split(',').includes(name))) {
    const browser=await driver.launch({headless:true,...(engine==='chromium'&&process.env.AVERY_VISUALIZER_QA_CHROMIUM_EXECUTABLE?{executablePath:process.env.AVERY_VISUALIZER_QA_CHROMIUM_EXECUTABLE}:{})});
    try { for(const viewport of [{width:390,height:844},{width:1024,height:768}]) {
      const context=await browser.newContext({viewport,deviceScaleFactor:1});
      const page=await context.newPage(); const errors=[],failed=[],writes=[],foreign=[],runtimeRequests=[];
      context.on('request',request=>{const url=new URL(request.url());if(url.pathname.includes('/runtime/'))runtimeRequests.push(url.pathname);});
      page.on('pageerror',error=>errors.push(error.message));
      page.on('requestfailed',request=>failed.push({url:request.url(),error:request.failure()?.errorText}));
      await page.route('**/*', async route=>{
        const request=route.request(),url=new URL(request.url());
        if(!['http:','https:'].includes(url.protocol))return route.continue();
        if(url.origin!==base){foreign.push(url.origin+url.pathname);return route.abort();}
        if(!['GET','HEAD'].includes(request.method())&&!url.pathname.endsWith('/avery'))writes.push(request.method()+' '+url.pathname);
        if(url.pathname.includes('/runtime/'))runtimeRequests.push(url.pathname);
        return route.continue();
      });
      await page.addInitScript(()=>{
        window.__cameraCalls=0; window.__tracks=[];
        navigator.mediaDevices.getUserMedia=async()=>{window.__cameraCalls++;throw new DOMException('Fictional permission denial','NotAllowedError');};
      });
      const record=(name,detail={})=>rows.push({engine,browserVersion:browser.version(),viewport,name,...detail});
      assert.equal((await context.request.get(base+'/avery/visualizer/model.glb')).status(),404);
      await page.goto(base+'/avery/visualizer');await page.waitForURL('**/avery');
      await page.locator('input[name=code]').fill(fixtureCode);
      await Promise.all([page.waitForURL('**/retail/showroom/concepts/avery-knifeworks'),page.locator('button[type=submit]').click()]);
      await page.getByRole('link',{name:'Open the knife visualizer →'}).click();
      await page.waitForURL('**/avery/visualizer');
      await page.getByText('Ready to explore. Camera access is optional.',{exact:true}).waitFor();
      assert.equal(await page.evaluate(()=>window.__cameraCalls),0);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      await page.screenshot({path:path.join(evidence,`${engine}-${viewport.width}-page.png`),fullPage:true});
      record('existing grant / Avery entry / responsive page / camera off');
      await page.getByRole('button',{name:'Open illustrative 3D preview'}).click();
      const dialog=page.getByRole('dialog',{name:'Product visualization'});
      await page.waitForFunction(()=>document.querySelector('#avery-knife-viewer')?.modelReady===true);
      assert.equal(await page.evaluate(()=>window.__cameraCalls),0);
      await page.screenshot({path:path.join(evidence,`${engine}-${viewport.width}-3d.png`),fullPage:true});
      await dialog.getByRole('button',{name:'Start camera',exact:true}).click();
      await dialog.getByText('Camera access was declined. Allow it in browser or app settings, then retry.',{exact:true}).waitFor();
      assert.equal(await page.evaluate(()=>window.__cameraCalls),1);
      await dialog.getByRole('button',{name:'Close product preview'}).click();
      await page.waitForFunction(()=>document.activeElement?.classList.contains('pv-launch'));
      record('protected model / explicit camera / permission denial / close focus');
      await page.getByRole('button',{name:'Open illustrative 3D preview'}).click();
      await page.waitForFunction(()=>document.querySelector('#avery-knife-viewer')?.modelReady===true);
      // Use a synthetic video-only stream; real camera/device acceptance is separate.
      await page.evaluate(()=>{
        navigator.mediaDevices.getUserMedia=async constraints=>{
          if(constraints.audio!==false)throw Error('Unexpected microphone request');
          window.__cameraCalls++;
          const canvas=document.createElement('canvas');canvas.width=640;canvas.height=480;
          const ctx=canvas.getContext('2d');ctx.fillStyle='#e4e0d7';ctx.fillRect(0,0,640,480);
          const stream=canvas.captureStream(10);window.__tracks.push(...stream.getTracks());
          return stream;
        };
      });
      await dialog.getByRole('button',{name:'Start camera',exact:true}).click();
      await page.waitForFunction(()=>{const v=document.querySelector('#avery-knife-viewer');return Boolean(v?.hand&&v.worker&&!v.canvas.hidden);},null,{timeout:90000});
      assert(runtimeRequests.some(url=>url.endsWith('vision_wasm_internal.js')||url.endsWith('vision_wasm_nosimd_internal.js')));
      assert(runtimeRequests.every(url=>!url.includes('/wasm//')));
      await page.evaluate(()=>window.dispatchEvent(new Event('pagehide')));
      await page.waitForFunction(()=>window.__tracks.every(track=>track.readyState==='ended'));
      assert.equal(await page.evaluate(()=>{const v=document.querySelector('#avery-knife-viewer');return v.worker===null&&v.hand===null;}),true);
      await dialog.getByRole('button',{name:'Close product preview'}).click();
      record('self-hosted Worker/WASM ready / video only / pagehide cleanup',{runtimeRequests:[...new Set(runtimeRequests)]});
      await page.getByRole('button',{name:'Open illustrative 3D preview'}).click();
      await page.evaluate(()=>{navigator.mediaDevices.getUserMedia=async()=>{throw new DOMException('Fictional device unavailable','NotFoundError');};});
      await dialog.getByRole('button',{name:'Start camera',exact:true}).click();
      await dialog.getByText('No camera was found on this device.',{exact:true}).waitFor();
      await dialog.getByRole('button',{name:'Close product preview'}).click();
      record('unavailable camera / truthful 3D fallback');
      const rejected=await context.request.get(base+'/avery/visualizer/model.glb?tenant_id=999');assert.equal(rejected.status(),404);
      await page.getByRole('link',{name:'← Back to the Avery demo',exact:true}).click();
      await page.waitForURL('**/retail/showroom/concepts/avery-knifeworks');
      await page.getByRole('link',{name:'Open the knife visualizer →'}).click();
      await page.getByText('Ready to explore. Camera access is optional.',{exact:true}).waitFor();
      assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);assert.deepEqual(foreign,[]);
      record('return navigation / foreign-scope rejected / no provider or mutation requests',{pageErrors:errors,failedRequests:failed});
      await page.route('**/avery/visualizer/model.glb',route=>route.abort('internetdisconnected'));
      await page.reload();
      await page.getByRole('button',{name:'Open illustrative 3D preview'}).click();
      await page.locator('.pv-status').filter({hasText:'The product model could not load. Please try again later.'}).waitFor();
      assert.equal(await page.evaluate(()=>window.__cameraCalls),0);
      await page.getByRole('dialog').getByRole('button',{name:'Close product preview'}).click();
      assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);assert.deepEqual(foreign,[]);
      record('blocked model / truthful connection failure / camera remains off',{expectedFailedRequest:'/avery/visualizer/model.glb',failedRequests:failed,pageErrors:errors});
      await context.close();
    }} finally {await browser.close();}
  }
  await writeFile(path.join(evidence,'browser-results.json'),JSON.stringify({scope:liveBase?'Existing Avery-only approved preview; same grant; headless phone/tablet; synthetic video; no physical camera':'Disposable loopback staging fixture; fictional grant; headless phone/tablet; no physical camera',rows},null,2)+'\n');
  console.log(`${rows.length} browser proof groups passed: ${[...new Set(rows.map(row=>row.engine))].join(', ')}, phone/tablet.`);
} finally {
  server?.kill('SIGTERM');
  await writeFile(path.join(evidence,'local-server.log'),serverLog);
}
