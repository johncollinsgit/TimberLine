import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdir} from 'node:fs/promises';
import {chromium,webkit} from 'playwright';

const origin='http://127.0.0.1:8961',evidence='test-results/rentals';
await mkdir(evidence,{recursive:true});
const server=spawn(process.env.PHP_BINARY||'php',['-S','127.0.0.1:8961','-t','public','tests/e2e/fixtures/rentals-router.php'],{env:{...process.env,APP_ENV:'testing'},stdio:['ignore','pipe','pipe']});
let log='';server.stderr.on('data',value=>log+=value);
try{
  let ready=false;
  for(let i=0;i<60;i++){try{ready=(await fetch(origin+'/rentals')).ok;if(ready)break;}catch{}await new Promise(resolve=>setTimeout(resolve,100));}
  assert.equal(ready,true,log);
  for(const [engine,type] of Object.entries({chromium,webkit})){
    const browser=await type.launch();
    try{
      for(const width of [1440,390,320]){
        const context=await browser.newContext({viewport:{width,height:1000}});const page=await context.newPage();
        const year=new Date().getFullYear(),errors=[],posts=[];
        const state={property:{id:'00000000-0000-4000-8000-000000000001',name:'Fictional shared villa',unit:'Unit A',location:'Example Coast',address:'Example address',timezone:'America/New_York',scheduleStatus:'Owner-entered weekly allocations'},
          version:7,today:`${year}-01-01`,actor:{id:'123',name:'Casey Example'},currentHousehold:'h1',workspaceName:'Fictional property fixture',members:[{id:123,name:'Casey Example',email:'casey@example.test'}],
          capabilities:{admin:true,write:true,maintenance:true,rentals:false,payments:'offline-records'},households:[{id:'h1',name:'Example household',color:'#157f69',shares:[1],userIds:['123'],members:[{id:'123',name:'Casey Example',email:'casey@example.test'}]}],
          weeks:[{id:'week-fixture',start:`${year}-12-06`,end:`${year}-12-13`,status:'assigned',owner:'h1',originalOwner:'h1',currentOwner:'h1',share:1,comments:[]}],
          announcements:[],maintenance:[],journal:[],dues:[],teams:[],documents:[],links:[],guides:[],messages:[],swaps:[],permanentRequests:[],audit:[]};
        page.on('pageerror',error=>errors.push(error.message));
        await page.route('**/*',async route=>{
          const request=route.request(),url=new URL(request.url());
          if(url.origin!==origin)return route.abort();
          if(url.pathname==='/rentals/properties'&&request.method()==='GET')return route.fulfill({json:{properties:[state.property],canCreate:true}});
          if(url.pathname===`/rentals/properties/${state.property.id}`&&request.method()==='GET')return route.fulfill({json:state});
          if(url.pathname.endsWith('/commands')&&request.method()==='POST'){
            assert.equal(request.headers()['x-csrf-token'],'fictional-csrf');
            const input=request.postDataJSON();posts.push(input);assert.equal(input.action,'stayComment');assert.equal(input.version,state.version);
            state.weeks[0].comments.push({id:'comment-fixture',author:'Casey Example',body:input.body,date:new Date().toISOString(),attachmentIds:[],canDelete:true});state.version++;
            return route.fulfill({json:state});
          }
          if(url.pathname.startsWith('/rentals/properties'))throw Error('Unstubbed request: '+url.pathname);
          return route.continue();
        });
        await page.goto(origin+'/rentals');await page.getByRole('heading',{name:'Good to be home.'}).waitFor();
        assert.equal(await page.getByLabel('Demo role').count(),0);
        if(width<700)await page.getByRole('button',{name:'Open navigation'}).click();
        await page.locator('.sidebar nav').getByRole('button',{name:'Calendar',exact:true}).click();
        await page.locator('.week-row').first().click();
        await page.getByRole('dialog').waitFor();await page.getByLabel('Comment',{exact:true}).fill('Saved with the actual selected week');
        await page.getByRole('button',{name:'Post',exact:true}).click();
        await page.getByText('Saved with the actual selected week',{exact:true}).waitFor();
        assert.equal(posts.length,1);assert.equal(posts[0].weekId,'week-fixture');assert.match(posts[0].requestKey,/^[a-f0-9-]{36}$/);
        await page.keyboard.press('Escape');assert.equal(await page.getByRole('dialog').count(),0);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,`${engine} ${width} fits viewport`);
        await page.screenshot({path:`${evidence}/${engine}-${width}.png`,fullPage:true});
        assert.deepEqual(errors,[]);await context.close();
      }
    }finally{await browser.close();}
  }
  console.log('PASS: native rentals UI, real compiled assets, intercepted fictional API, Chromium/WebKit at 1440/390/320, per-stay save and no overflow.');
}finally{server.kill();}
