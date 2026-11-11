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
        let releasePropertySave;
        const state={property:{id:'00000000-0000-4000-8000-000000000001',name:'Fictional shared villa',unit:'Unit A',location:'Example Coast',address:'Example address',timezone:'America/New_York',scheduleStatus:'Owner-entered weekly allocations'},
          version:7,today:`${year}-01-01`,actor:{id:'123',name:'Casey Example'},currentHousehold:'h1',workspaceName:'Fictional property fixture',members:[{id:123,name:'Casey Example',email:'casey@example.test'}],
          capabilities:{admin:true,write:true,maintenance:true,rentals:false,payments:'offline-records'},households:[{id:'h1',name:'Example household',color:'#157f69',shares:[1],userIds:['123'],members:[{id:'123',name:'Casey Example',email:'casey@example.test'}]}],
          weeks:[{id:'week-fixture',start:`${year}-12-06`,end:`${year}-12-13`,status:'assigned',owner:'h1',originalOwner:'h1',currentOwner:'h1',share:1,comments:[]},
            {id:'other-week-fixture',start:`${year}-12-20`,end:`${year}-12-27`,status:'assigned',owner:'h2',originalOwner:'h2',currentOwner:'h2',share:2,comments:[]}],
          announcements:[],maintenance:[],journal:[],dues:[],teams:[],documents:[],links:[],guides:[],messages:[],
          swaps:[{id:'swap-fixture',requesterHouseholdId:'h2',targetHouseholdId:'h1',fromWeekId:'other-week-fixture',toWeekId:'week-fixture',status:'pending'}],
          permanentRequests:[{id:'permanent-fixture',weekId:'week-fixture',fromHouseholdId:'h1',toHouseholdId:'h2',reason:'Fictional reviewed change request',status:'pending'}],audit:[]};
        state.households.push({id:'h2',name:'Other example household',color:'#728f8c',shares:[2],userIds:[],members:[]});
        page.on('pageerror',error=>errors.push(error.message));
        await page.route('**/*',async route=>{
          const request=route.request(),url=new URL(request.url());
          if(url.origin!==origin)return route.abort();
          if(url.pathname==='/rentals/properties'&&request.method()==='GET')return route.fulfill({json:{properties:[state.property],canCreate:true}});
          if(url.pathname===`/rentals/properties/${state.property.id}`&&request.method()==='GET')return route.fulfill({json:state});
          if(url.pathname.endsWith('/commands')&&request.method()==='POST'){
            assert.equal(request.headers()['x-csrf-token'],'fictional-csrf');
            const input=request.postDataJSON();posts.push(input);assert.equal(input.version,state.version);
            if(input.action==='stayComment')state.weeks[0].comments.push({id:'comment-fixture',author:'Casey Example',body:input.body,date:new Date().toISOString(),attachmentIds:[],canDelete:true});
            else if(input.action==='property'){await new Promise(resolve=>releasePropertySave=resolve);state.property.name=input.name;}
            else throw Error('Unstubbed command: '+input.action);
            state.version++;
            return route.fulfill({json:state});
          }
          if(url.pathname.startsWith('/rentals/properties'))throw Error('Unstubbed request: '+url.pathname);
          return route.continue();
        });
        await page.goto(origin+'/rentals');await page.getByRole('heading',{name:'Good to be home.'}).waitFor();
        assert.equal(await page.getByLabel('Demo role').count(),0);
        if(width<700)await page.getByRole('button',{name:'Open navigation'}).click();
        await page.locator('.sidebar nav').getByRole('button',{name:'Calendar',exact:true}).click();
        const swaps=page.getByRole('heading',{name:'Swap requests',exact:true}).locator('../..');
        assert.match(await swaps.innerText(),new RegExp(`Dec 6, ${year}`));
        assert.match(await swaps.innerText(),new RegExp(`Dec 20, ${year}`));
        assert.doesNotMatch(await page.locator('body').innerText(),/\u00e2[\u20ac\u2020]|\u00c2\u00b7/,'calendar punctuation is valid Unicode');
        await page.locator('.week-row').first().click();
        await page.getByRole('dialog').waitFor();
        assert.equal(await page.getByLabel('Close panel',{exact:true}).evaluate(node=>node===document.activeElement),true);
        await page.keyboard.press('Shift+Tab');
        assert.equal(await page.getByRole('dialog').evaluate(node=>node.contains(document.activeElement)),true);
        await page.keyboard.press('Tab');
        assert.equal(await page.getByLabel('Close panel',{exact:true}).evaluate(node=>node===document.activeElement),true);
        await page.getByLabel('Comment',{exact:true}).fill('Saved with the actual selected week');
        await page.getByRole('button',{name:'Post',exact:true}).click();
        await page.getByText('Saved with the actual selected week',{exact:true}).waitFor();
        assert.equal(posts.length,1);assert.equal(posts[0].weekId,'week-fixture');assert.match(posts[0].requestKey,/^[a-f0-9-]{36}$/);
        await page.keyboard.press('Escape');assert.equal(await page.getByRole('dialog').count(),0);
        if(width<700)await page.getByRole('button',{name:'Open navigation'}).click();
        await page.locator('.sidebar nav').getByRole('button',{name:'Administration',exact:true}).click();
        const changes=page.getByRole('heading',{name:'Permanent change requests',exact:true}).locator('../..');
        assert.match(await changes.innerText(),new RegExp(`Dec 6, ${year}`));
        await page.getByRole('button',{name:'Add owner week',exact:true}).click();
        await page.getByLabel('Permanent household').selectOption('h1');
        const slot=page.getByLabel('Ownership slot');
        assert.deepEqual(await slot.locator('option').allTextContents(),['Choose ownership slot','1']);
        assert.equal(await slot.evaluate(node=>node.required),true);
        await page.getByLabel('Hold for house care instead of an owner stay').check();
        assert.equal(await slot.isDisabled(),true);
        assert.equal(await page.getByLabel('Permanent household').isDisabled(),true);
        await page.getByLabel('Close panel',{exact:true}).click();
        await page.getByRole('button',{name:'Edit property',exact:true}).click();
        await page.getByRole('button',{name:'Save',exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('.rental-form fieldset')?.disabled);
        await page.getByLabel('Close panel',{exact:true}).focus();
        await page.keyboard.press('Shift+Tab');
        assert.equal(await page.getByLabel('Close panel',{exact:true}).evaluate(node=>node===document.activeElement),true,'disabled fieldset controls are excluded from the focus trap');
        await page.keyboard.press('Tab');
        assert.equal(await page.getByLabel('Close panel',{exact:true}).evaluate(node=>node===document.activeElement),true);
        releasePropertySave();
        await page.getByRole('dialog').waitFor({state:'hidden'});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,`${engine} ${width} fits viewport`);
        assert.doesNotMatch(await page.locator('body').innerText(),/\u00e2[\u20ac\u2020]|\u00c2\u00b7/,'administration punctuation is valid Unicode');
        await page.screenshot({path:`${evidence}/${engine}-${width}.png`,fullPage:true});
        assert.deepEqual(errors,[]);await context.close();
      }
    }finally{await browser.close();}
  }
  console.log('PASS: native rentals UI, compiled assets, fictional API, Chromium/WebKit at 1440/390/320, per-stay save, dated requests, enabled/disabled dialog focus and no overflow.');
}finally{server.kill();}
