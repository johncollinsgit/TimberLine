import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {chromium, webkit, expect} from '@playwright/test';

const base = resolve();
const fixture = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/popup.css"><script src="/chart.js"></script><style>body{margin:0;font-family:sans-serif}main{max-width:700px;margin:auto}.charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}.chart{position:relative;height:240px}canvas{max-width:100%}</style></head><body><main><div class="charts" id="charts"></div><section id="ledger" hidden><h1>Recorded entries</h1><p id="entry-description"></p></section></main><script type="module">
import {createDonutExplanation} from '/module.js';
const ids=['vf-income-chart','vf-spending-chart','vf-category-chart','tr-income-donut','tr-spending-donut','tr-allocation'];
const titles=['Incoming sources','Money spent','Food subcategories','Cash received','Net spending','Assets and liabilities'];
let context='household:7:2028-02',revision=1;
const calls=[],charts=new Map(),explanations=new Map();
history.replaceState({fixture:'chart'},'',location.href);
window.addEventListener('popstate',()=>{document.querySelector('#ledger').hidden=true;document.querySelector('#charts').hidden=false;});
for(const [i,id] of ids.entries()){
 const wrapper=document.createElement('div');wrapper.className='chart';const canvas=document.createElement('canvas');canvas.id=id;canvas.setAttribute('aria-label',titles[i]);wrapper.append(canvas);document.querySelector('#charts').append(wrapper);
 const captured=context+':'+revision;
 const explanation=createDonutExplanation({canvas,formatMoney:c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(c/100),title:titles[i],context:{period:'February 1–15, 2028',basis:i===5?'Recorded balances':'Purchases when made',source:'Fictional authorized chart; history may be incomplete',description:i===5?'These are recorded balances, not period income or expenses.':'This slice groups the recorded amount shown in the selected financial view.'},isCurrent:()=>captured===context+':'+revision,onSelect:i===5?null:(index,datasetIndex)=>{calls.push({id,index,datasetIndex});history.pushState({fixture:'entries'},'',location.href);document.querySelector('#charts').hidden=true;document.querySelector('#ledger').hidden=false;document.querySelector('#entry-description').textContent=id+' slice '+index;}});
 explanations.set(id,explanation);
 const chart=new Chart(canvas,{type:'doughnut',data:{labels:i===5?['Assets','Liabilities']:['Groceries <img src=x onerror=window.injected=true>','Dining','Hidden source'],datasets:[{data:i===5?[30000,10000]:[12000,3000,85000],backgroundColor:['#257868','#bc8c44','#987ab9']}]},plugins:[explanation.plugin],options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},tooltip:{enabled:false}},onClick:explanation.onClick}});
 if(i!==5){chart.toggleDataVisibility(2);chart.update();}charts.set(id,chart);
}
window.fixture={ids,calls,charts,explanations,changeContext:()=>{context='business:9:2028-03';},updateRevision:()=>{revision++;}};
</script></body></html>`;
const files = {'/chart.js':'node_modules/chart.js/dist/chart.umd.js','/module.js':'resources/js/trajectory/donut-explanation.js','/donut-explanation-data.js':'resources/js/trajectory/donut-explanation-data.js','/popup.css':'resources/js/trajectory/donut-explanation.css'};
const server = createServer(async (request, response) => {
  if (request.url === '/fixture') {response.setHeader('Content-Type','text/html');response.end(fixture);return;}
  const path = files[request.url];
  if (!path) {response.statusCode=404;response.end();return;}
  let source=await readFile(resolve(base,path),'utf8');
  if(request.url==='/module.js')source=source.replace("import './donut-explanation.css';",'');
  response.setHeader('Content-Type',path.endsWith('.css')?'text/css':'application/javascript');response.end(source);
});
await new Promise(done=>server.listen(0,'127.0.0.1',done));
try {
  for (const [name, engine] of [['chromium',chromium],['webkit',webkit]]) {
    const browser=await engine.launch();
    try {
      for(const width of [390,1440]) {
        const page=await browser.newPage({viewport:{width,height:900},hasTouch:true}),errors=[];
        page.setDefaultTimeout(10000);
        page.on('pageerror',error=>errors.push(error.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/fixture`);
        await page.waitForFunction(()=>Boolean(window.fixture));
        const tap=async id=>{
          const canvas=page.locator('#'+id);await canvas.scrollIntoViewIfNeeded();
          const point=await canvas.evaluate(node=>{const chart=Chart.getChart(node),arc=chart.getDatasetMeta(0).data[0],angle=(arc.startAngle+arc.endAngle)/2,r=(arc.innerRadius+arc.outerRadius)/2,b=node.getBoundingClientRect();return{x:b.left+arc.x+Math.cos(angle)*r,y:b.top+arc.y+Math.sin(angle)*r};});
          await page.touchscreen.tap(point.x,point.y);
        };
        const popup=page.getByRole('dialog');
        for(const [i,id] of (await page.evaluate(()=>window.fixture.ids)).entries()) {
          const before=await page.evaluate(()=>({calls:window.fixture.calls.length,state:history.state,length:history.length}));
          await tap(id);await expect(popup).toBeVisible();
          await expect(popup).toContainText(i===5?'$300.00':'$120.00');
          await expect(popup).toContainText(i===5?'75% of the visible slices':'80% of the visible slices');
          await expect(popup).toContainText('February 1–15, 2028');
          await expect(popup).toContainText('history may be incomplete');
          expect(await popup.locator('img').count()).toBe(0);
          expect(await page.evaluate(()=>window.injected)).toBeUndefined();
          expect(await page.evaluate(()=>({calls:window.fixture.calls.length,state:history.state,length:history.length}))).toEqual(before);
          const box=await popup.boundingBox();expect(box.width).toBeLessThanOrEqual(width-20);
          await page.getByRole('button',{name:'Back to chart',exact:true}).click();await expect(popup).toHaveCount(0);
          expect(await page.evaluate(()=>({calls:window.fixture.calls.length,state:history.state,length:history.length}))).toEqual(before);
          await tap(id);await expect(popup).toBeVisible();
          if(i===5) {
            await expect(page.getByRole('button',{name:'View recorded entries',exact:true})).toHaveCount(0);
            await page.getByRole('button',{name:'Close explanation',exact:true}).click();
          } else {
            await page.getByRole('button',{name:'View recorded entries',exact:true}).click();
            await expect(page.getByRole('heading',{name:'Recorded entries',exact:true})).toBeVisible();
            expect((await page.evaluate(()=>window.fixture.calls)).at(-1)).toEqual({id,index:0,datasetIndex:0});
            await page.goBack();await expect(page.locator('#charts')).toBeVisible();await expect(popup).toHaveCount(0);
          }
        }
        await tap('vf-income-chart');await expect(popup).toBeVisible();await page.keyboard.press('Escape');await expect(popup).toHaveCount(0);
        const calls=await page.evaluate(()=>window.fixture.calls.length);
        await tap('vf-spending-chart');await expect(popup).toBeVisible();await page.evaluate(()=>window.fixture.updateRevision());
        await page.getByRole('button',{name:'View recorded entries',exact:true}).click();await expect(popup).toHaveCount(0);
        expect(await page.evaluate(()=>window.fixture.calls.length)).toBe(calls);
        await tap('tr-allocation');await expect(popup).toHaveCount(0);
        // Fresh page: destroying its chart must remove every retained explanation.
        await page.reload();await page.waitForFunction(()=>Boolean(window.fixture));await tap('vf-income-chart');await expect(popup).toBeVisible();
        await page.evaluate(()=>window.fixture.charts.get('vf-income-chart').destroy());await expect(popup).toHaveCount(0);
        await tap('vf-spending-chart');await expect(popup).toBeVisible();await page.evaluate(()=>window.fixture.changeContext());
        await page.getByRole('button',{name:'View recorded entries',exact:true}).click();await expect(popup).toHaveCount(0);
        expect(await page.evaluate(()=>window.fixture.calls.length)).toBe(0);
        expect(errors).toEqual([]);await page.close();
        console.log(name+' '+width+': all six donut touch explanations, explicit entries/Back, close/Escape, visible share, stale scope/revision and destroy cleanup passed');
      }
    } finally {await browser.close();}
  }
} finally {await new Promise(done=>server.close(done));}
