import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from '@playwright/test';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/highlevel/fleet.js'];
const fixture = {
  workspace:{name:'Pilot fleet'}, vehicles:Array.from({length:25}, (_,i)=>({id:i+1,device_id:`imei-${i+1}`,name:`Van ${i+1}`,location:{latitude:34.5+i/1000,longitude:-82,reported_at:new Date(Date.now()-3600000).toISOString(),older_reading:true}})),
  connection:{status:'connected',label:'Pilot Bouncie',health:{status:'connected'}},
  settings:{retention_days:30,policy_approved:true,policy_version:'v1',policy_sha256:'a'.repeat(64),tracking_enabled:true,vehicle_limit:25},
  subscription:{status:'COMPLETE',has_access:true,collection_active:true,billing_authority:'highlevel'},
  map_key:null,support_email:'support@example.test',
};
for (const browserType of [chromium, webkit]) {
  test(`${browserType.name()} embeds 25 vehicles without cookies, searches and caps selection at responsive sizes`, async()=>{
    let origin, parentOrigin, exchanges=0, apiCalls=0, bodyData;
    const server=createServer(async(req,res)=>{
      res.setHeader('Content-Type','application/json');
      if(req.url==='/parent'){
        res.setHeader('Content-Type','text/html');
        return res.end(`<iframe title="Everbranch" src="${origin}/launch" style="width:100%;height:100vh;border:0"></iframe><script>addEventListener('message',e=>{if(e.origin==='${origin}'&&e.data.message==='REQUEST_USER_DATA')e.source.postMessage({message:'REQUEST_USER_DATA_RESPONSE',payload:'fixture-encrypted-context'},e.origin)})</script>`);
      }
      if(req.url==='/launch'){
        res.setHeader('Content-Type','text/html');
        return res.end(`<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><div id="fleet-app"></div><script id="fleet-config" type="application/json">${JSON.stringify({challenge:'c'.repeat(64),parentOrigins:[parentOrigin]})}</script>${(entry.css||[]).map(css=>`<link rel="stylesheet" href="/build/${css}">`).join('')}<script type="module" src="/build/${entry.file}"></script>`);
      }
      if(req.url.startsWith('/build/')){
        res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'application/javascript');
        return res.end(readFileSync(`public${req.url}`));
      }
      if(req.url==='/crm/fleet/session/challenge')return res.end(JSON.stringify({challenge:'c'.repeat(64)}));
      if(req.url==='/crm/fleet/session/exchange'){
        let body='';for await(const chunk of req)body+=chunk;
        assert.equal(JSON.parse(body).parentOrigin,parentOrigin);
        exchanges++;return res.end(JSON.stringify({token:'t'.repeat(80),expires_at:new Date(Date.now()+900000).toISOString()}));
      }
      if(req.url.startsWith('/crm/fleet/api/')){
        assert.equal(req.headers.cookie,undefined);
        assert.equal(req.headers.authorization,`Bearer ${'t'.repeat(80)}`);
        assert.equal(req.headers['x-everbranch-parent-origin'],parentOrigin);apiCalls++;
        if(req.url.endsWith('/bootstrap'))return res.end(JSON.stringify(fixture));
        if(req.url.endsWith('/devices')&&req.method==='GET')return res.end(JSON.stringify({devices:Array.from({length:26},(_,i)=>({id:`imei-${i+1}`,name:`Van ${i+1}`,selected:i<25})),limit:25}));
        let body='';for await(const chunk of req)body+=chunk;
        bodyData=JSON.parse(body);return res.end(JSON.stringify({saved:true}));
      }
      res.statusCode=404;res.end('{}');
    });
    await new Promise(resolve=>server.listen(0,'0.0.0.0',resolve));
    const port=server.address().port;origin=`http://127.0.0.1:${port}`;parentOrigin=`http://localhost:${port}`;
    const browser=await browserType.launch();
    try{
      const context=await browser.newContext({viewport:{width:1200,height:900}});
      await context.addInitScript(()=>{document.cookie='blocked-test=unused';});
      const page=await context.newPage();await page.goto(`${parentOrigin}/parent`);
      const frame=page.frameLocator('iframe');
      await frame.getByRole('heading',{name:'Your fleet, in view'}).waitFor();
      if(process.env.HIGHLEVEL_SCREENSHOT_DIR && browserType.name()==='chromium'){
        mkdirSync(process.env.HIGHLEVEL_SCREENSHOT_DIR,{recursive:true});
        await page.setViewportSize({width:1280,height:720});
        await page.screenshot({path:`${process.env.HIGHLEVEL_SCREENSHOT_DIR}/fleet.png`});
        await frame.getByRole('button',{name:'Connection',exact:true}).click();
        await page.screenshot({path:`${process.env.HIGHLEVEL_SCREENSHOT_DIR}/connection.png`});
        await frame.getByRole('button',{name:'Settings',exact:true}).click();
        await page.screenshot({path:`${process.env.HIGHLEVEL_SCREENSHOT_DIR}/settings.png`});
        await frame.getByRole('button',{name:'Fleet',exact:true}).click();
        await page.setViewportSize({width:1200,height:900});
      }
      assert.equal(await frame.locator('.vehicle-row').count(),25);
      await frame.getByRole('searchbox').fill('Van 25');
      assert.equal(await frame.locator('.vehicle-row').count(),1);
      await frame.locator('.vehicle-row').click();
      assert.match(await frame.locator('#vehicle-details').innerText(),/older reading can mean the vehicle is parked/);
      await frame.getByRole('button',{name:'Connection',exact:true}).click();
      await frame.getByRole('button',{name:'Choose vehicles'}).click();
      await frame.locator('#device-form').waitFor();
      await frame.locator('input[value="imei-26"]').check();
      assert.equal(await frame.getByRole('button',{name:'Save vehicle selection'}).isDisabled(),true);
      await frame.locator('input[value="imei-1"]').uncheck();
      await frame.getByRole('button',{name:'Save vehicle selection'}).click();
      await frame.getByRole('heading',{name:'Your fleet, in view'}).waitFor();
      assert.equal(bodyData.devices.length,25);
      assert.equal(bodyData.devices.includes('imei-1'),false);
      assert.equal(bodyData.devices.includes('imei-26'),true);
      await page.setViewportSize({width:390,height:844});
      assert.equal(await frame.locator('.shell').evaluate(el=>el.scrollWidth<=el.clientWidth),true);
      assert.equal(exchanges,1);assert.ok(apiCalls>=4);
      const storage=await page.frames()[1].evaluate(()=>({local:localStorage.length,session:sessionStorage.length}));
      assert.deepEqual(storage,{local:0,session:0});
      await context.close();
    }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
  });
}
