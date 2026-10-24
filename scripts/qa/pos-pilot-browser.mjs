// Isolated HTTP fixtures for the actual production register assets. No merchant network access.
import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const root=new URL('../../',import.meta.url),read=name=>readFileSync(new URL(name,root));
const base='https://pos-pilot.test/pos/staff/fictional/terminal',reg='a'.repeat(32),sale='b'.repeat(32),event='c'.repeat(32),operator='d'.repeat(32),item='e'.repeat(32);
const html=read('resources/views/pos/pilot.blade.php').toString().replace(/\{\{\s*(.*?)\s*\}\}/g,(_,expression)=>expression==='$base'?base:expression==='$tenantName'?'Fictional Forestry':expression==='csrf_token()'?'fixture-csrf':expression.startsWith('hash_file(')?'fixture-version':(()=>{throw Error('Unhandled Blade expression '+expression)})());
const output=process.env.POS_PILOT_BROWSER_OUTPUT||new URL('../../work/pos-pilot-ui/',import.meta.url).pathname;mkdirSync(output,{recursive:true});
for(const [engine,name] of [[chromium,'chromium'],[webkit,'webkit']]){
 const browser=await engine.launch({headless:true});
 try{
  for(const width of [390,1024]){
   const page=await browser.newPage({viewport:{width,height:900}}),errors=[],calls=[];
   page.on('pageerror',error=>errors.push(error.message));let paid=false,created=false;
   const state={company:'Fictional Forestry',operator:{id:operator,name:'John Sample',role:'admin'},device:{id:'f'.repeat(32),name:'Fictional phone',register_id:reg},current_shift:{event_id:event,state:'open'},events:[{id:event,name:'Sample market',state:'open',timezone:'America/New_York',today_total_minor:paid?1070:0}],registers:[{id:reg,name:'Sample register',blockers:[]}],owner_setup:false,shared:false,baskets:[],favorites:[],reporting_timezone:'America/New_York',build:'fixture-build',collection_enabled:true};
   const product={item_id:item,title:'Fictional candle / 8oz',product_id:'product-fixture',product_title:'Fictional candle',option_title:'8oz cotton wick',image_url:base+'/assets/item.svg',sku:'FIXTURE-8',barcode:'123456789012',revision:'1'.repeat(64),unit_amount_minor:1000,available_quantity:4,kind:'merchandise'};
   const payment=()=>({sale_id:sale,request_id:'11111111-1111-4111-8111-111111111111',state:paid?'succeeded':created?'processing':'quoted',stock_state:paid?'committed':created?'held':'unprepared',amount_minor:1070,lines:[{title:product.title,quantity:1,subtotal_minor:1000}],canonical_confirmation:paid,recovery_required:created&&!paid,receipt_available:paid,can_leave:paid,manager_refunds_allowed:true,retry_available:false,resume_available:false});
   await page.route('**/*',async route=>{
    const r=route.request(),url=new URL(r.url());assert.equal(url.origin,'https://pos-pilot.test','Unexpected outbound request');const path=url.pathname.slice(new URL(base).pathname.length);let body;
    if(r.method()==='POST'){assert.equal(r.headers()['x-csrf-token'],'fixture-csrf');body=r.postDataJSON();calls.push({path,body})}
    const json=data=>route.fulfill({json:data});
    if(path===''||path==='/')return route.fulfill({contentType:'text/html',body:html});
    if(path==='/assets/register.js')return route.fulfill({contentType:'application/javascript',body:read('resources/js/pos-pilot-register.js')});
    if(path==='/assets/register.css')return route.fulfill({contentType:'text/css',body:read('resources/css/pos-pilot.css')});
    if(path==='/assets/trees.png')return route.fulfill({contentType:'image/png',body:read('resources/images/pos-trees.png')});
    if(path==='/assets/item.svg')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><rect width="160" height="160" fill="#e5e8d9"/><rect x="48" y="42" width="64" height="86" rx="9" fill="#a68659"/><rect x="45" y="35" width="70" height="15" rx="5" fill="#103e32"/><rect x="56" y="67" width="48" height="42" fill="#f5f1e6"/><path d="M80 73 67 96h26Z" fill="#103e32"/></svg>'});
    if(path==='/state')return json(state);
    if(path===`/registers/${reg}/catalog`)return json({items:[product],quotes:[]});
    if(path===`/registers/${reg}/quotes`){assert.deepEqual(body.items,[{item_id:item,quantity:1,revision:'1'.repeat(64)}]);assert(!('amount_minor'in body));return json(payment())}
    if(path===`/registers/${reg}/sales/${sale}/collect`){created=true;return json(payment())}
    if(path===`/registers/${reg}/sales/${sale}/reconcile`){paid=true;return json(payment())}
    if(path.includes('/requests/'))return json(payment());
    if(path===`/registers/${reg}/sales/${sale}/receipt`)return json({receipt_number:'EB-FIXTURE',attribution:{event_name:'Sample market',operator_name:'John Sample'},lines:payment().lines,tax_minor:70,amount_minor:1070,refunded_minor:0,payment_reference:'pi_fixture',settlement_status:'provider_reconciliation_required',restock_items:[]});
    if(path==='/activity'){
     const period=url.searchParams.get('period'),n=period==='year'?12:period==='month'?31:24,buckets=Array.from({length:n},(_,i)=>({key:i,label:period==='year'?new Date(2026,i,1).toLocaleString('en-US',{month:'short'}):String(i),total_minor:i===10?1070:i===12?2140:0,count:i===10?1:i===12?2:0}));
     return json({timezone:'America/New_York',totals:{subtotal_minor:3000,tax_minor:210,collected_minor:3210,refund_minor:0,net_minor:3210,count:3,average_sale_minor:1070},buckets,transactions:[{id:sale,register_id:reg,kind:'sale',event:'Sample market',staff:'John Sample',at:'2026-10-24T14:00:00Z',amount_minor:1070,sync_pending:false}],next_offset:null,products:[{title:'Fictional candle / 8oz',quantity:3,subtotal_minor:3000}],staff:[],events:[],historical_unassigned:0});
    }
    if(path==='/registers/'+reg+'/favorites')return json({favorite:true});
    throw Error('Unhandled fixture request '+r.method()+' '+path);
   });
   await page.addInitScript(()=>Object.defineProperty(navigator,'share',{configurable:true,value:async data=>{window.__sharedReceipt=data}}));
   await page.goto(base);await page.getByRole('heading',{name:'Fictional candle',exact:true}).waitFor();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   const header=page.locator('.brandheader');assert.equal(await header.evaluate(e=>getComputedStyle(e).position),'sticky');
   await page.getByRole('button',{name:'Add Fictional candle / 8oz'}).click();
   await page.getByRole('button',{name:/Review basket/}).click();
   await page.getByRole('button',{name:'Check prices & tax'}).click();
   await page.getByRole('button',{name:'Collect $10.70',exact:true}).click();
   await page.getByText('processing · stock held',{exact:true}).waitFor();
   assert.equal(await page.getByRole('button',{name:'Collect $10.70',exact:true}).count(),0);
   await page.getByRole('button',{name:'Check original payment'}).click();
   await page.getByRole('button',{name:'View receipt'}).click();await page.getByText('Receipt EB-FIXTURE').waitFor();
   await page.getByRole('button',{name:'Share receipt',exact:true}).click();
   const shared=await page.evaluate(()=>window.__sharedReceipt);assert(shared.text.includes('Sample market')&&shared.text.includes('John Sample')&&shared.text.includes('pi_fixture')&&shared.text.includes('Tax $0.70'));
   await page.getByRole('button',{name:'Next customer'}).click();
   await page.getByRole('button',{name:'Activity',exact:true}).click();
   await page.getByRole('heading',{name:'Sales over time'}).waitFor();
   await page.getByRole('button',{name:'Line',exact:true}).click();assert.equal(await page.locator('polyline.chart-line').count(),1);
   await page.locator('[data-action="chart-bucket"][data-index="10"]').first().click();await page.getByRole('button',{name:'Full period',exact:true}).waitFor();
   await Promise.all([page.waitForResponse(r=>r.url().includes('/activity?')&&new URL(r.url()).searchParams.get('period')==='month'),page.getByRole('button',{name:'Month',exact:true}).click()]);await page.getByRole('button',{name:'Bar',exact:true}).click();
   await page.locator('rect.chart-bar').nth(10).waitFor();
   assert.equal(await page.locator('g.chart-target').count(),31);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await page.getByLabel('Find receipt in this period').fill('EB-FIXTURE');
   await Promise.all([page.waitForResponse(r=>r.url().includes('/activity?')&&new URL(r.url()).searchParams.get('search')==='EB-FIXTURE'),page.getByRole('button',{name:'Search receipts',exact:true}).click()]);
   await page.screenshot({path:`${output}/${name}-${width}-activity.png`});
   await page.getByRole('button',{name:'Sell',exact:true}).click();await page.getByRole('heading',{name:'Fictional candle',exact:true}).waitFor();
   await page.screenshot({path:`${output}/${name}-${width}-sell.png`});
   assert.deepEqual(errors,[]);assert.equal(calls.filter(c=>c.path.endsWith('/collect')).length,1);
   assert(!JSON.stringify(await page.evaluate(()=>Object.entries(localStorage))).includes('John Sample'),'Only opaque recovery identifiers may be stored');
   await page.close();console.log(`PASS ${name} ${width}: responsive register, exact quote, pending recovery, receipt and chart controls`);
  }
 }finally{await browser.close()}
}
