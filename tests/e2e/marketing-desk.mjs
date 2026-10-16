import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
const base=process.env.MARKETING_DESK_QA_BASE_URL || 'http://127.0.0.1:8736';
assert.ok(['localhost','127.0.0.1'].includes(new URL(base).hostname),'Use an isolated local fixture server.');
const out=process.env.MARKETING_DESK_QA_OUTPUT || '/tmp/marketing-desk-browser';
await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1440,height:1000}});
await page.route('**/*',route=>new URL(route.request().url()).origin===new URL(base).origin ? route.continue() : route.abort());
const errors=[]; page.on('pageerror',e=>errors.push(e.message));
try {
 await page.goto(base+'/login?tenant=marketing-preview');
 await page.getByRole('textbox',{name:/email/i}).fill('marketing-preview@example.test');
 await page.getByLabel('Password',{exact:true}).fill('Preview-only-2026!');
 await page.getByRole('button',{name:/log in|sign in/i}).click();
 await page.waitForLoadState('networkidle');
 const checked=[];
 for(const width of [1440,390]) {
  await page.setViewportSize({width,height:1000});
  for(const view of ['today','chat','calendar','insights','inbox','recipes','knowledge','settings']) {
   const response=await page.goto(base+'/marketing/desk?tenant=marketing-preview&view='+view);
   assert.equal(response.status(),200,`${width} ${view} ${response.status()}`);
   await page.getByRole('heading',{name:'Marketing desk',exact:true}).waitFor();
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+2);
   assert.equal(overflow,false,`${width} ${view} horizontal overflow`);
   if(['today','calendar','inbox'].includes(view))await page.screenshot({path:path.join(out,`marketing-${view}-${width}.png`),fullPage:true});
   checked.push(`${view}@${width}`);
  }
 }
 await page.setViewportSize({width:1440,height:1000});
 const response=await page.goto(base+'/workflows?tenant=marketing-preview');assert.equal(response.status(),200);
 await page.getByRole('link',{name:/Retail welcome/}).first().click();
 await page.getByRole('navigation',{name:'Workflow views'}).waitFor();
 await page.getByRole('button',{name:'Overview',exact:true}).click();
 await page.getByRole('heading',{name:'How this workflow works'}).waitFor();
 await page.screenshot({path:path.join(out,'workflow-overview-1440.png'),fullPage:true});
 await page.getByRole('button',{name:'Build',exact:true}).click();
 await page.getByRole('complementary',{name:'Step outline'}).waitFor();
 await page.getByRole('button',{name:'Fit to view',exact:true}).click();
 await page.screenshot({path:path.join(out,'workflow-build-1440.png'),fullPage:true});
 const withinCanvas=await page.evaluate(()=>{const canvas=document.querySelector('.eb-workflow-canvas').getBoundingClientRect();const node=document.querySelector('.eb-workflow-node').getBoundingClientRect();return node.left>=canvas.left && node.right<=canvas.right;});
 assert.equal(withinCanvas,true,'Workflow nodes fit inside the canvas beside the inspector');
 await page.setViewportSize({width:390,height:1000});
 await page.getByRole('button',{name:'Close step setup',exact:true}).click();
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+2),false);
 await page.screenshot({path:path.join(out,'workflow-build-390.png'),fullPage:true});
 await page.setViewportSize({width:1440,height:1000});
 await page.getByRole('button',{name:'Hide step outline',exact:true}).click();
 await page.getByRole('button',{name:'Show step outline',exact:true}).click();
 await page.route('**/workflows/*/draft',route=>route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Review this draft.',errors:{'steps.0.config.body':['Write a clear approved message']}})}));
 await page.getByRole('textbox',{name:'Workflow name',exact:true}).fill('Retail welcome QA validation');
 await page.getByRole('button',{name:'Write a clear approved message →',exact:true}).click();
 await page.waitForFunction(()=>document.activeElement?.id==='eb-config-body');
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({checked,studio:'overview, outline, fit to view',errors,output:out}));
} finally {await browser.close();}
