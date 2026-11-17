import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFile,spawn} from 'node:child_process';
import {promisify} from 'node:util';
import {createServer} from 'node:net';
import {chromium,webkit} from 'playwright';
import {expect} from '@playwright/test';
const exec=promisify(execFile),base=resolve(),probe=createServer();
await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;await new Promise(done=>probe.close(done));
const dir=await mkdtemp(join(tmpdir(),'codex-money-flow-')),origin=`http://127.0.0.1:${port}`,evidence=resolve(process.env.TRAJECTORY_FLOW_EVIDENCE||'test-results/trajectory-money-flow');
await mkdir(evidence,{recursive:true});await writeFile(join(dir,'db.sqlite'),'');
const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:origin,APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',APP_CONFIG_CACHE:join(dir,'config.php'),DB_CONNECTION:'sqlite',DB_DATABASE:join(dir,'db.sqlite'),DB_URL:'',DATABASE_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',SESSION_SECURE_COOKIE:'false',SESSION_DOMAIN:'',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',LOG_CHANNEL:'null',BCRYPT_ROUNDS:'4',TRAJECTORY_ENABLED:'true',TRAJECTORY_AI_ENABLED:'false',NIGHTWATCH_ENABLED:'false',TELESCOPE_ENABLED:'false',PULSE_ENABLED:'false'};
let server,browser;const checks=[];
try{
 await exec('php',['artisan','migrate','--force','--no-interaction'],{cwd:base,env,maxBuffer:2e6});
 const fixture=JSON.parse((await exec('php',['scripts/trajectory/money-flow-browser-fixture.php'],{cwd:base,env})).stdout);
 server=spawn('php',['artisan','serve','--host=127.0.0.1',`--port=${port}`,'--no-reload'],{cwd:base,env,stdio:'ignore'});
 let ready=false;for(let i=0;i<100;i++){try{if((await fetch(origin+'/up')).ok){ready=true;break;}}catch{}await new Promise(done=>setTimeout(done,100));}assert.ok(ready);
 for(const [name,engine] of [['chromium',chromium],['webkit',webkit]]){
  browser=await engine.launch();const context=await browser.newContext({reducedMotion:'reduce'});
  await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const csrf=(await(await context.request.get(origin+'/login')).text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];assert.ok(csrf);
  assert.ok((await context.request.post(origin+'/login',{form:{email:'flow@fixture.invalid',password:'Fictional-flow-only-2028!',_token:csrf},headers:{Accept:'application/json'}})).ok());
  const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));page.setDefaultTimeout(12000);
  for(const width of [390,1440]){
   await page.setViewportSize({width,height:950});await page.goto(origin+'/trajectory');
   if(await page.locator('#tr-splash-skip').isVisible())await page.locator('#tr-splash-skip').click();
   await page.locator('#tr-content:not([aria-busy="true"])').waitFor();
   await expect(page.locator('#tr-overview-explore')).not.toHaveAttribute('open','');
   await expect(page.getByRole('heading',{name:'What does your income need to cover?'})).toBeVisible();
   await page.locator('#tr-overview-explore > summary').click();
   await expect(page.locator('#tr-overview-explore')).toHaveAttribute('open','');
   await expect(page.locator('#tr-cashflow')).toBeVisible();
   await page.locator('#tr-overview-explore > summary').click();
   await expect(page.locator('#tr-overview-explore')).not.toHaveAttribute('open','');
   await page.evaluate(()=>scrollTo(0,0));
   await page.screenshot({path:join(evidence,`${name}-${width}-overview.png`),fullPage:true});
   if(width<700)await page.locator('#tr-mobile-menu').click();
   await page.getByRole('button',{name:'Money flow',exact:true}).click();
   await page.getByRole('heading',{name:'Follow your money'}).waitFor();
   await page.getByRole('heading',{name:'Last year and the monthly trend'}).waitFor();
   await expect(page.locator('#tr-flow-trend')).not.toBeAttached(); // Unknown tax cost prevents a fabricated monthly economic total.
   await expect(page.getByRole('button',{name:'Spending',exact:true})).toHaveAttribute('aria-pressed','true');
   await expect(page.locator('.tr-tax-economic-warning')).toContainText('Economic totals unavailable');
   await expect(page.locator('.tr-money-flow > .tr-grid')).toContainText('Unavailable');
   await page.getByText('All flow amounts and transactions',{exact:true}).click();
   const taxInterest=page.getByRole('row').filter({hasText:'Outgoing · Tax interest'});
   const taxRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await taxInterest.getByRole('button',{name:'View transactions',exact:true}).click();
   const taxSent=(await taxRequest).postDataJSON();assert.equal(taxSent.economic_costs,true);assert.equal(taxSent.economic_category,'interest');
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();
   const taxEvidence=page.getByRole('row').filter({hasText:'Fictional known sales-tax cash'});
   await expect(taxEvidence).toContainText('-$2.00');await expect(taxEvidence).toContainText('Bank amount -$100.00');
   await expect(taxEvidence).toContainText('Shown: Interest');await expect(taxEvidence).toContainText('Sales-tax principal $90.00');
   await page.locator('#tr-back').click();
   assert.equal(await page.getByText('All flow amounts and transactions',{exact:true}).evaluate(node=>node.parentElement.open),false);
   await page.evaluate(()=>scrollTo(0,0));
   await page.screenshot({path:join(evidence,`${name}-${width}-tax-spending.png`),fullPage:true});

   assert.ok(await page.getByText('Medical sharing · bill reimbursements',{exact:false}).count());
   assert.ok(await page.getByText('Needs review · incoming',{exact:false}).count());
   const diagram=page.locator(width<700?'.tr-flow-phone':'.tr-flow-desktop');
   await expect(diagram).toBeVisible();
   await expect(page.locator('.tr-flow-payroll-note')).toContainText('not total employer cost');
   for(const label of ['Owner payroll','Studio Payroll','Event Payroll','Payroll'])await expect(diagram.locator(`[data-label="${label}"]`)).toBeVisible();
   if(width<700){assert.ok(await diagram.evaluate(node=>node.scrollWidth<=node.clientWidth+1));await expect(diagram.locator('text').filter({hasText:'Where it went'})).toBeVisible();}
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await page.getByRole('button',{name:'Cash movement',exact:true}).click();
   await expect(page.getByRole('button',{name:'Cash movement',exact:true})).toHaveAttribute('aria-pressed','true');
   await expect(page.locator('#tr-flow-trend')).toBeAttached();
   if(width<700){const cards=await page.locator('.tr-money-flow > .tr-grid > .tr-metric').evaluateAll(nodes=>nodes.map(node=>({top:node.getBoundingClientRect().top,height:node.getBoundingClientRect().height})));assert.equal(cards.length,3);assert.ok(cards.every(card=>Math.abs(card.top-cards[0].top)<1&&card.height<100),'Phone totals stay in one compact row');}
   const openMerchant=async(label,category,keyboard=false)=>{
    const categoryNode=diagram.locator(`[data-action="money-flow-category"][data-label="${category}"]`);
    let node=width<700?(await categoryNode.count()?categoryNode:diagram.locator('[data-action="money-flow-category"][data-label="Other outgoing categories"]')):diagram.locator('.tr-flow-action').filter({has:page.locator('title').filter({hasText:label})});
    if(width>=700&&!await node.count())node=diagram.locator('[data-action="money-flow-evidence"][data-label="Other outgoing categories"]'); // Aggregated categories still open every underlying source.
    if(keyboard){await node.first().focus();await page.keyboard.press('Enter');}else await node.first().click();
    if(width<700){await expect(page.locator('#tr-dialog')).toBeVisible();await page.locator('#tr-dialog .tr-row').filter({hasText:label}).getByRole('button',{name:'View transactions',exact:true}).click();}
   };
   const request=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await openMerchant('Fictional shared cost','Shopping',true);const sent=await request;
   assert.equal(sent.postDataJSON().cash_movement,true);assert.deepEqual(sent.postDataJSON().ids,[fixture.zero_id]);
   const zeroRow=page.getByRole('row').filter({hasText:'Fictional shared cost'});
   await expect(zeroRow).toContainText('$770.00');await expect(zeroRow).toContainText('Economic allocation $0.00');
   await page.locator('#tr-back').click();
   await openMerchant('Fictional partial cost','Transport');
   const partial=page.getByRole('row').filter({hasText:'Fictional partial cost'});
   await expect(partial).toContainText('$100.00');await expect(partial).toContainText('Economic allocation -$25.00');
   await page.locator('#tr-back').click();
   await page.getByText(/^Excluded and zero-amount records/).click();
   await page.getByRole('row').filter({hasText:'Internal transfers'}).getByRole('button',{name:'View transactions',exact:true}).click();
   await expect(page.getByRole('row').filter({hasText:'Fictional internal transfer'})).toContainText('-$123.45');
   await expect(page.getByRole('row').filter({hasText:'Fictional internal transfer'})).toContainText('Economic allocation $0.00');
   await page.locator('#tr-back').click();
   const trend=page.locator('#tr-flow-trend');await trend.scrollIntoViewIfNeeded();let point;
   await expect.poll(async()=>{point=await trend.evaluate(node=>{const ctx=node.getContext('2d'),data=ctx.getImageData(0,0,node.width,node.height).data,box=node.getBoundingClientRect();let left=node.width,right=0,top=node.height,bottom=0,count=0;for(let y=0;y<node.height;y++)for(let x=0;x<node.width;x++){const i=(y*node.width+x)*4;if(Math.abs(data[i]-167)<3&&Math.abs(data[i+1]-131)<3&&Math.abs(data[i+2]-75)<3&&data[i+3]>240){left=Math.min(left,x);right=Math.max(right,x);top=Math.min(top,y);bottom=Math.max(bottom,y);count++;}}return count>=4?{x:box.x+(left+right)/2*box.width/node.width,y:box.y+(top+bottom)/2*box.height/node.height}:null;});return Boolean(point);}).toBe(true);
   const pointRequest=page.waitForRequest(request=>request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/evidence'));
   await page.mouse.click(point.x,point.y);const pointSent=(await pointRequest).postDataJSON();
   assert.equal(pointSent.cash_movement,true);assert.deepEqual(pointSent.ids.toSorted((a,b)=>a-b),fixture.cash_outflow_ids.toSorted((a,b)=>a-b),'Outflow point opens only outflows, excluding income/borrowing and internal transfers');
   await page.getByRole('heading',{name:'Review transactions',level:1,exact:true}).waitFor();await page.locator('#tr-back').click();
   assert.equal(await page.getByText('All flow amounts and transactions',{exact:true}).evaluate(node=>node.parentElement.open),false);
   await page.getByText('All flow amounts and transactions',{exact:true}).click();
   await expect(page.getByRole('table',{name:'Recorded incoming sources, outgoing categories and merchants'})).toBeVisible();
   await page.getByText('All flow amounts and transactions',{exact:true}).click();
   await page.evaluate(()=>scrollTo(0,0));
   await page.screenshot({path:join(evidence,`${name}-${width}.png`),fullPage:true});
   await diagram.screenshot({path:join(evidence,`${name}-${width}-diagram.png`)});
   checks.push(`${name} ${width}: actual navigation, mode switch, keyboard/touch evidence, zero/partial allocations, table and horizontal containment`);
  }
  assert.deepEqual(errors,[]);await context.close();await browser.close();browser=null;
 }
 console.log(JSON.stringify({checks,evidence}));
}finally{await browser?.close();server?.kill('SIGTERM');await rm(dir,{recursive:true,force:true});}
