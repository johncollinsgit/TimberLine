import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import {chromium, webkit} from '@playwright/test';

const base = process.cwd();
const fixture = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/visual.css"><script src="/chart.js"></script></head><body class="trajectory-site"><main id="trajectory"><div class="tr-workspace"><button id="back">Back</button><div id="content"></div><p id="notice" role="status"></p></div></main><script type="module">
import {createVisualFinance} from '/module.js';
import {reportNavigation} from '/navigation.js';
import {chartPresentation} from '/theme.js';
import {canRefreshFinance,createForegroundRefresh} from '/foreground.js';
const root=document.querySelector('#trajectory'), content=document.querySelector('#content'), calls=[], changes=[];
const ctx={space_id:7,scope:'personal',account_id:null,from:'2028-02-01',through:'2028-02-15'};
let budgetOverride=null,budgetReviews=0;let tab='overview',charts=[],rows=[
{id:1,entry_key:'one',date:'2028-02-03',merchant:'Fictional expensive market',category:'groceries',parent_category:'food',amount_cents:-30000,version:1,flow:'expense',editable:true,direction:'out',face_punched:false,bullshit_spending:false},
{id:2,entry_key:'two',date:'2028-02-04',merchant:'Fictional small market',category:'groceries',parent_category:'food',amount_cents:-10000,version:1,flow:'expense',editable:true,direction:'out',face_punched:false,bullshit_spending:false},
{id:3,entry_key:'three',date:'2028-02-05',merchant:'Fictional cafe',category:'dining',parent_category:'food',amount_cents:-5000,version:1,flow:'expense',editable:true,direction:'out',face_punched:false,bullshit_spending:false},
{id:4,entry_key:'refund',date:'2028-02-06',merchant:'Fictional market refund',category:'groceries',parent_category:'food',amount_cents:2000,version:1,flow:'refund',editable:true,direction:'out',face_punched:false,bullshit_spending:false}];
function report(params){const food=rows.filter(r=>['groceries','dining'].includes(r.category)),office=rows.filter(r=>r.category==='office');const amount=rs=>-rs.reduce((n,r)=>n+r.amount_cents,0);return {today:'2028-02-15',range:{from:params.get('from'),through:params.get('through')},summary:{inflow_cents:100000,spending_cents:amount(rows)},inflows:[{key:'company',label:'Fictional business',amount_cents:100000,ids:[99]}],categories:[...(food.length?[{key:'food',label:'Food',amount_cents:amount(food),ids:food.map(r=>r.id),children:['groceries','dining'].map(c=>({key:c,label:c==='groceries'?'Groceries':'Dining',amount_cents:amount(food.filter(r=>r.category===c)),ids:food.filter(r=>r.category===c).map(r=>r.id)}))}]:[]),...(office.length?[{key:'office',label:'Office',amount_cents:amount(office),ids:office.map(r=>r.id)}]:[])],entries:rows,periods:[{label:'Jan',from:'2028-01-01',through:'2028-01-31',inflow_cents:80000,spending_cents:30000,comparison:{from:'2027-01-01',through:'2027-01-31',spending_cents:22000}},{label:'Feb',from:'2028-02-01',through:'2028-02-15',inflow_cents:100000,spending_cents:43000,comparison:{from:'2027-02-01',through:'2027-02-15',spending_cents:null}},{label:'Mar',from:'2028-03-01',through:'2028-03-31',future:true,inflow_cents:null,spending_cents:null,comparison:{from:'2027-03-01',through:'2027-03-31',spending_cents:null}}],category_options:['food','groceries','dining','office'],category_definitions:[{key:'food',label:'Food'},{key:'groceries',label:'Groceries',parent_key:'food'},{key:'dining',label:'Dining',parent_key:'food'},{key:'office',label:'Office'}],program_options:[{id:13,name:'Fictional Soccer'}],coverage:{complete:false,notes:['Fictional testing history.']}};}
const api=async(url,method='GET',body)=>{calls.push({url,method,body});const params=new URL('http://fixture'+url).searchParams;if(url.includes('budget-envelopes'))return budgetOverride??{month:'2028-02',actual_through:'2028-02-15',rows:[{key:'food',category:'food',label:'Food',target_cents:50000,spent_cents:43000,pending_cents:2000,remaining_cents:5000}],summary:{remaining_cents:5000}};if(method==='PATCH'){const row=rows.find(r=>r.id===Number(url.split('/').at(-1)));assertVersion(row,body);row.category=body.category;row.parent_category=null;row.version++;changes.push(body);return {ok:true};}return report(params);};
function assertVersion(row,body){if(row.version!==body.version)throw Error('Changed source');}
const precise=x=>Number.isSafeInteger(x)?new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(x/100):'Unavailable';
const esc=x=>String(x??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
const nav=reportNavigation({window,capture:()=>({tab,state:ui.capture(),scroll:scrollY}),context:()=>JSON.stringify(ctx),restore:async(value,current)=>{tab=value.tab;ui.restore(value.state);await ui.refresh();if(current()){render();scrollTo(0,value.scroll);}}});
function chart(id,type,labels,datasets,click,extra){const canvas=document.getElementById(id);if(!canvas)return;const presentation=chartPresentation(canvas,precise,type,extra);const c=new Chart(canvas,{type,data:{labels,datasets},plugins:presentation.plugins,options:{...presentation.options,onClick:(event,elements)=>{if(elements[0])click(elements[0].index,elements[0].datasetIndex);}}});charts.push(c);}
const ui=createVisualFinance({root,getContext:()=>ctx,api,endpoint:p=>'/spaces/7'+p,esc,precise,chart,onBeforeChange:()=>nav.push(),onChange:()=>render(),onChanged:async()=>{await ui.refresh();render();},onNavigate:(view,remember=true)=>{if(remember)nav.push();tab=view;render();},onReviewTransaction:()=>{},onEditBudget:()=>{budgetReviews++;},onAddDefinition:()=>{},notice:x=>document.querySelector('#notice').textContent=x});
function render(){charts.forEach(c=>c.destroy());charts=[];content.innerHTML=ui.render(tab);ui.draw();nav.sync();}
root.addEventListener('click',async e=>{const t=e.target.closest('[data-action]');if(t)await ui.handleClick(t);});root.addEventListener('change',async e=>{if(e.target.matches('[data-vf-input],[data-vf-category]'))await ui.handleInput(e.target);});document.querySelector('#back').onclick=()=>nav.back();
const foreground=createForegroundRefresh({eligible:()=>canRefreshFinance({visible:!document.hidden,online:true,hasData:!!ui.getReport(),tab,scope:ctx.scope,loading:ui.isBusy(),editing:!!document.querySelector('dialog[open]')||document.activeElement?.matches('input,select,textarea,[contenteditable="true"]')}),snapshot:()=>JSON.stringify([ctx,tab,ui.capture()]),refresh:async current=>{const position=scrollY,entry=document.activeElement?.dataset.entry;const changed=await ui.refresh({background:true,isCurrent:current});if(!changed||!current())return false;render();scrollTo(0,position);if(entry)root.querySelector('[data-entry="'+entry+'"]')?.focus({preventScroll:true});return true;}});
await ui.refresh();render();window.fixture={ui,calls,changes,foreground,setBudgets:async value=>{budgetOverride=value;tab='budget';await ui.refresh();render();},budgetReviews:()=>budgetReviews,updateSource:()=>{rows[0].amount_cents=-32000;rows[0].version++;},charts:()=>charts};
</script></body></html>`;

const files = {'/module.js': 'resources/js/trajectory/visual-finance.js', '/navigation.js': 'resources/js/trajectory/navigation-history.js', '/foreground.js':'resources/js/trajectory/foreground-refresh.js', '/theme.js': 'resources/js/trajectory/chart-theme.js', '/chart.js': 'node_modules/chart.js/dist/chart.umd.js', '/app.css': 'resources/js/trajectory/app.css', '/visual.css': 'resources/js/trajectory/visual-finance.css'};
const server = createServer(async (req, res) => {
  if (req.url === '/fixture') {res.setHeader('content-type', 'text/html'); res.end(fixture); return;}
  const file = files[req.url]; if (!file) {res.statusCode = 404; res.end(); return;}
  res.setHeader('content-type', file.endsWith('.css') ? 'text/css' : 'application/javascript'); res.end(await readFile(path.join(base, file)));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const port = server.address().port, results = [];
try {
  for (const [name, engine] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch();
    for (const width of [1440, 390]) {
      const page = await browser.newPage({viewport: {width, height: 1000}}), errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(`http://127.0.0.1:${port}/fixture`);await page.waitForFunction(() => !!window.fixture);
      assert.equal(await page.locator('.vf-envelope').count(), 1);
      await page.getByRole('button', {name: 'Explore analysis', exact: true}).click();
      // Select an actual canvas bar, not a synthetic data-only action.
      const point = await page.locator('#vf-period-chart').evaluate(canvas => {const chart = Chart.getChart(canvas), el = chart.getDatasetMeta(1).data[1], b = canvas.getBoundingClientRect(); return {x: b.x + el.x, y: b.y + el.y + Math.min(3, el.base - el.y)};});
      await page.mouse.click(point.x, point.y);
      await page.waitForFunction(() => window.fixture.ui.capture().period?.from === '2028-02-01');
      await page.locator('[data-action="vf-category"][data-key="food"]').click();
      await page.locator('[data-action="vf-subcategory"][data-key="groceries"]').click();
      assert.match(await page.locator('.vf-table tbody tr').first().innerText(), /Fictional expensive market/);
      await page.locator('[data-vf-input="sort"]').selectOption('newest');
      assert.match(await page.locator('.vf-table tbody tr').first().innerText(), /refund/);
      // Quiet shared-backend updates preserve a user's drilldown and Back stack,
      // while form focus and the context-aware Ask/edit modal suppress polling.
      await page.locator('[data-vf-input="query"]').focus();
      const reads=await page.evaluate(()=>window.fixture.calls.length);
      assert.equal(await page.evaluate(()=>window.fixture.foreground()),false);
      assert.equal(await page.evaluate(()=>window.fixture.calls.length),reads);
      await page.evaluate(()=>{document.activeElement.blur();const dialog=document.createElement('dialog');dialog.id='fictional-ask';document.body.append(dialog);dialog.showModal();});
      assert.equal(await page.evaluate(()=>window.fixture.foreground()),false);
      await page.evaluate(()=>{const dialog=document.querySelector('#fictional-ask');dialog.close();dialog.remove();});
      await page.locator('[data-action="vf-select-row"][data-entry="one"]').focus();
      const beforeRefresh=await page.evaluate(()=>({selection:window.fixture.ui.capture(),depth:history.length,scroll:scrollY}));
      await page.evaluate(()=>window.fixture.updateSource());
      assert.equal(await page.evaluate(()=>window.fixture.foreground()),true);
      assert.deepEqual(await page.evaluate(()=>({selection:window.fixture.ui.capture(),depth:history.length,scroll:scrollY})),beforeRefresh);
      assert.equal(await page.evaluate(()=>document.activeElement.dataset.entry),'one');
      assert.match(await page.locator('.vf-table tbody tr').filter({hasText:'Fictional expensive market'}).innerText(),/320\.00/);
      await page.locator('[data-vf-category="one"]').selectOption('office');
      await page.waitForFunction(() => window.fixture.changes.length === 1);
      assert.match(await page.locator('#notice').innerText(), /Category saved/);
      assert.equal(await page.locator('[data-vf-input="sort"]').inputValue(), 'newest');
      await page.locator('#back').click();
      await page.waitForSelector('#vf-category-chart');
      await page.locator('#back').click();
      await page.waitForSelector('#vf-income-chart');
      assert.ok(page.url().endsWith('/fixture'));
      await page.locator('#back').click();
      await page.waitForFunction(() => window.fixture.ui.capture().period === null);
      await page.locator('#back').click();
      await page.waitForSelector('.vf-budget-preview');
      await page.locator('.vf-envelope').click();
      await page.waitForSelector('.vf-ledger');
      const request = await page.evaluate(() => window.fixture.calls.filter(c => c.url.includes('/analysis?')).at(-1));
      assert.match(request.url, /through=2028-02-15/);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
      if (overflow) console.error(JSON.stringify({browser:name,width,layout:await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,wrap:[...document.querySelectorAll('.vf-table-wrap')].map(e=>({width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right,overflow:getComputedStyle(e).overflowX})),wide:[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).map(e=>({tag:e.tagName,cl:e.className,right:e.getBoundingClientRect().right})).slice(0,15)}))}));
      assert.equal(overflow, false);assert.deepEqual(errors, []);
      await page.evaluate(()=>window.fixture.setBudgets({future:false,rows:[
        {key:'0|groceries',category:'groceries',label:'Groceries',target_cents:140000,planned_cents:140000,spent_cents:64000,remaining_cents:null,reasons:['cash_not_recorded'],source_notes:['Fictional cash grocery amount still unknown.']},
        {key:'0|fuel',category:'fuel',label:'Fuel',target_cents:35000,planned_cents:35000,spent_cents:21000,remaining_cents:null,category_review_pending:true,reasons:['category_review_pending']},
        {key:'0|materials',category:'materials',label:'Materials',target_cents:null,planned_cents:45000,spent_cents:10000,remaining_cents:null,status:'draft',partial_period:true,plan_period:{from:'2028-02-17',through:'2028-02-29'},reasons:['draft','partial_period','tax_split_pending']},
        {key:'0|education',category:'education',label:'Education',target_cents:null,spent_cents:5000,remaining_cents:null,status:'not_budgeted'},
        {key:'0|travel',category:'travel',label:'Travel',target_cents:50000,planned_cents:50000,spent_cents:null,remaining_cents:null,reasons:['needs_opening_balance','missing_history']},
        {key:'0|dining',category:'dining',label:'Dining',uses_parent_budget:'0|food',parent_budget_label:'Food',spent_cents:3000,remaining_cents:null,reasons:['uses_parent_budget']},
      ]}));
      const budgetCards=page.locator('.vf-envelope-grid');
      assert.doesNotMatch(await budgetCards.innerText(),/Unavailable|\$0\.00|left after recorded spending/);
      assert.match(await budgetCards.innerText(),/\$1,400\.00/);assert.match(await budgetCards.innerText(),/\$640\.00 recorded spending/);
      assert.match(await budgetCards.innerText(),/recorded \/ estimated spending/);assert.match(await budgetCards.innerText(),/2028-02-17 – 2028-02-29/);
      assert.match(await budgetCards.innerText(),/Draft · needs confirmation/);assert.match(await budgetCards.innerText(),/Tax split pending/);
      assert.match(await budgetCards.innerText(),/Uses parent budget/);assert.match(await budgetCards.innerText(),/Spending history not available/);
      await page.getByRole('button',{name:'Confirm budget…',exact:true}).click();assert.equal(await page.evaluate(()=>window.fixture.budgetReviews()),1);
      await page.locator('.vf-envelope-notes summary').click();assert.match(await page.locator('.vf-envelope-notes').innerText(),/cash grocery amount still unknown/);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
      await page.evaluate(()=>window.fixture.setBudgets({rows:[{key:'0|groceries',category:'groceries',label:'Groceries',target_cents:140000,planned_cents:140000,bank_spent_cents:64000,reported_cash_spent_cents:50000,reported_cash_as_of:'2028-02-15',spent_cents:114000,remaining_cents:26000}]}));
      assert.match(await page.locator('.vf-envelope').innerText(),/\$640\.00 bank-recorded spending \+ \$500\.00 reported cash through 2028-02-15/);
      assert.match(await page.locator('.vf-envelope').innerText(),/\$260\.00/);assert.match(await page.locator('.vf-envelope').innerText(),/transaction charts exclude it/);
      assert.deepEqual(errors,[]);
      results.push({browser: name, width, chartDrilldown: true, inlineSave: true, backNavigation: true, budgetDateClamped: true, noPageOverflow: true, causeSpecificBudgets: true, nativeBudgetReview: true});
      await page.close();
    }
    await browser.close();
  }
  console.log(JSON.stringify(results));
} finally {await new Promise(resolve => server.close(resolve));}
