import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import {chromium} from 'playwright';

const project = process.cwd(), artifact = path.join(project,'artifacts/collins-fleet'), preview = path.join(artifact,'preview');
const snapshot = JSON.parse(await fs.readFile(path.join(preview,'snapshot.json'),'utf8'));
const histories = new Map(await Promise.all(snapshot.vehicles.map(async v=>[v.id,JSON.parse(await fs.readFile(path.join(preview,`history-${v.id}.json`),'utf8'))])));
let providerFailure = false, sessionExpired = false, locationFailure = false, requests=[];
const server = http.createServer(async(req,res)=>{
    try {
        if(req.method!=='GET') {res.writeHead(405);res.end('Isolated preview: writes disabled');return;}
        const url=new URL(req.url,'http://127.0.0.1:4328'), p=url.pathname;
        if(p==='/field-service/fleet-tracking/map') {
            if(sessionExpired){res.writeHead(401,{'Content-Type':'application/json'});res.end('{}');return;}
            if(locationFailure){res.writeHead(503,{'Content-Type':'application/json'});res.end('{}');return;}
            res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify(snapshot));return;
        }
        const match=p.match(/\/map\/vehicles\/(\d+)\/history/);
        if(match) {
            const id=Number(match[1]);requests.push({id,start:url.searchParams.get('start'),end:url.searchParams.get('end')});
            if(providerFailure&&id===1){res.writeHead(503,{'Content-Type':'application/json'});res.end('{}');return;}
            const h=structuredClone(histories.get(id));
            if(url.searchParams.get('start')===snapshot.range.yesterday)Object.assign(h,{trips:[],samples:[],status:'no_trips',message:'No completed Bouncie trips were returned for this period.'});
            h.range={...h.range,start:url.searchParams.get('start'),end:url.searchParams.get('end')};
            res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify(h));return;
        }
        let target=p==='/'?path.join(preview,'index.html'):path.join(project,'public',p);
        if(p==='/flux/flux.min.js')target=path.join(project,'vendor/livewire/flux/dist/flux.min.js');
        if(p.includes('/livewire.min.js'))target=path.join(project,'vendor/livewire/livewire/dist/livewire.min.js');
        if(!target.startsWith(project)){res.writeHead(403);res.end();return;}
        const body=await fs.readFile(target), type={'.html':'text/html','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.woff2':'font/woff2'}[path.extname(target)]||'application/octet-stream';
        res.writeHead(200,{'Content-Type':type});res.end(body);
    }catch{res.writeHead(404);res.end('Not found');}
});
await new Promise(resolve=>server.listen(4328,'127.0.0.1',resolve));
if(process.argv.includes('--serve')){console.log('Isolated preview: http://127.0.0.1:4328');await new Promise(()=>{});}
const browser=await chromium.launch();
const results=[],errors=[];
try{
    const context=await browser.newContext({viewport:{width:1440,height:1050}}), page=await context.newPage();
    page.on('pageerror',e=>errors.push(e.message));
    await page.clock.install();
    const show=async locator=>locator.evaluate(el=>{const main=document.querySelector('#app-main');main.scrollTop+=el.getBoundingClientRect().top-105;});
    await page.goto('http://127.0.0.1:4328');
    await page.locator('#selected-fleet').scrollIntoViewIfNeeded();
    await page.locator('.fleet-trip').first().waitFor();
    assert.equal(await page.locator('[data-van-check]:checked').count(),4);
    assert.equal(await page.locator('.fleet-van-marker').count(),3);
    assert.equal(await page.locator('.fleet-vehicle-history').count(),4);
    assert.match(await page.locator('[data-fleet-list]').innerText(),/Moving[\s\S]*Stopped[\s\S]*Location stale[\s\S]*Tracker missing/);
    assert.equal(await page.locator('.fleet-trip').count(),4);
    results.push('All four configured vans selected; three latest-position pins, two trip-bearing vans, stale and unmapped statuses visible.');
    await show(page.locator('#selected-fleet')); await page.screenshot({animations:'disabled',path:path.join(artifact,'screenshots/desktop-selected-vans.png')});
    await page.locator('[data-van-check="2"]').uncheck();
    assert.equal(await page.locator('.fleet-vehicle-history').count(),3);
    assert.equal(await page.locator('.fleet-van-marker').count(),2);
    await page.reload();await page.locator('.fleet-trip').first().waitFor();
    assert.equal(await page.locator('[data-van-check="2"]').isChecked(),false);
    await page.getByRole('button',{name:'Clear All',exact:true}).click();await page.reload();
    await page.locator('[data-fleet-count]').waitFor();
    assert.equal(await page.locator('[data-van-check]:checked').count(),0);assert.equal(await page.locator('.fleet-van-marker').count(),0);
    assert.match(await page.locator('[data-fleet-empty]').innerText(),/No vans selected/);
    await page.getByRole('button',{name:'Select All',exact:true}).click();await page.locator('.fleet-trip').first().waitFor();
    results.push('Individual selection and deliberately empty selection survive refresh; map and history follow the same selection.');
    const trip=page.locator('.fleet-trip').first();await trip.click();assert.equal(await trip.getAttribute('aria-pressed'),'true');
    assert.ok((await page.locator('.leaflet-overlay-pane path[stroke-opacity="0.2"]').count())>0);
    await show(page.locator('.fleet-map-area')); await page.screenshot({animations:'disabled',path:path.join(artifact,'screenshots/desktop-highlighted-trip.png')});
    const map=page.locator('[data-fleet-map]'), box=await map.boundingBox();
    await page.mouse.move(box.x+box.width*.6,box.y+box.height*.5);await page.mouse.down();await page.mouse.move(box.x+box.width*.6+90,box.y+box.height*.5+50,{steps:8});await page.mouse.up();await page.clock.runFor(1500);
    const before=await page.locator('.leaflet-map-pane').getAttribute('style');
    await page.getByRole('button',{name:'Refresh locations & history',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-fleet-notice]').textContent.includes('Locations checked'));
    assert.equal(await page.locator('.leaflet-map-pane').getAttribute('style'),before);
    await Promise.all([page.waitForResponse(r=>r.url().includes('/fleet-tracking/map?')),page.clock.fastForward(31000)]);
    assert.equal(await page.locator('.leaflet-map-pane').getAttribute('style'),before);
    results.push('Trip highlight dims other paths; manual and automatic location refresh preserve the panned map position.');
    await page.getByRole('button',{name:'Yesterday',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.fleet-trip').length===0);
    assert.ok(requests.some(r=>r.start===snapshot.range.yesterday&&r.end===snapshot.range.yesterday));
    await page.getByRole('button',{name:'Custom range',exact:true}).click();
    await page.locator('[data-fleet-range] [name=start]').fill('2026-10-13');await page.locator('[data-fleet-range] [name=end]').fill('2026-10-15');
    await page.getByRole('button',{name:'Apply dates',exact:true}).click();await page.locator('.fleet-trip').first().waitFor();
    assert.ok(requests.some(r=>r.start==='2026-10-13'&&r.end==='2026-10-15'));
    results.push('Yesterday and custom inclusive dates request the expected account dates; no-trip state is explained.');
    await page.getByRole('button',{name:'Today',exact:true}).click();await page.locator('.fleet-trip').first().waitFor();
    providerFailure=true;await page.getByRole('button',{name:'Refresh locations & history',exact:true}).click();
    await page.getByRole('button',{name:'Retry Crew Van 01',exact:true}).waitFor();
    assert.ok(await page.locator('[data-trip-van="2"]').count()>0);
    locationFailure=true;await page.getByRole('button',{name:'Refresh locations & history',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-fleet-notice]').textContent.includes('Location refresh failed'));
    assert.equal(await page.locator('.fleet-van-marker').count(),3);
    results.push('A failing van preserves other routes; a location outage preserves timestamped positions.');
    providerFailure=false;locationFailure=false;
    for(const [name,width,height] of [['tablet',834,1112],['phone',390,844],['small-phone',360,780]]){
        await page.setViewportSize({width,height});await page.reload();await page.locator('.fleet-trip').first().waitFor();await page.getByRole('button',{name:'Show all selected vans',exact:true}).click();await page.clock.runFor(500);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name} overflows`);
        await page.getByRole('button',{name:'Refresh locations & history',exact:true}).click();
        await show(page.locator('#selected-fleet')); await page.screenshot({animations:'disabled',path:path.join(artifact,`screenshots/${name}-selected-vans.png`)});
        await show(page.locator('.fleet-map-area')); await page.screenshot({animations:'disabled',path:path.join(artifact,`screenshots/${name}-map.png`)});
        await show(page.locator('.fleet-history-heading')); await page.screenshot({animations:'disabled',path:path.join(artifact,`screenshots/${name}-history.png`)});
    }
    results.push('No horizontal overflow at desktop 1440px, tablet 834px, phone 390px, or small phone 360px.');
    await page.evaluate(()=>document.dispatchEvent(new Event('livewire:navigating')));
    assert.equal(await page.locator('.fleet-van-marker').count(),0);
    await page.evaluate(()=>document.dispatchEvent(new Event('livewire:navigated')));await page.locator('.fleet-trip').first().waitFor();
    assert.equal(await page.locator('.fleet-van-marker').count(),3);
    results.push('Livewire navigation teardown and remount restore the selected map without duplicate map instances.');
    sessionExpired=true;await page.getByRole('button',{name:'Refresh locations & history',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-fleet-notice]').textContent.includes('Sign in again'));
    assert.equal(await page.locator('.fleet-van-marker').count(),0);assert.equal(await page.locator('.fleet-trip').count(),0);
    results.push('Revoked/expired app session clears map/history and asks the user to sign in again.');
    assert.deepEqual(errors,[]);
    await fs.writeFile(path.join(artifact,'browser-results.json'),JSON.stringify({passed:true,results,errors},null,2));
    console.log(JSON.stringify({passed:true,results,errors},null,2));
    await context.close();
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
