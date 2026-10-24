/** Intercepted synthetic presentation acceptance only; no server, scanner, provider or campus data. */
import assert from 'node:assert/strict';
import {readFile, mkdir, writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {chromium, webkit} from 'playwright';

const module = await readFile(new URL('../../resources/js/enterprise/attachment-gallery.js', import.meta.url), 'utf8');
const panel = await readFile(new URL('../../resources/views/enterprise/attachment-gallery.blade.php', import.meta.url), 'utf8');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAUAAAADICAIAAAAWZq/8AAACa0lEQVR4nO3TQQmAQAAAwctjFnNYVzCCSa6DiLI6MAH2s2M/DyBqvF4AXGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEPTrwsq3wBwaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxh3xwYuJeBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQNgHgWrOuiWkl9AAAAABJRU5ErkJggg==', 'base64');
const evidence = resolve(process.env.ENTERPRISE_EVIDENCE || 'test-results/enterprise/attachment-gallery');
await mkdir(evidence, {recursive:true});
const origin = 'https://enterprise-gallery.test';
const files = ['one', 'two'].map(id => ({id,filename:`Synthetic ${id}.png`,mime_type:'image/png',size:png.length,status:'ready',downloadable:true,
    preview_url:`${origin}/enterprise-campus/enterprise/discussion/request/request-a/files/${id}/preview?tenant=synthetic-campus`}));
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:16px system-ui}#work-drawer{width:80vw;height:80vh}#detail{height:60vh;overflow:auto}.spacer{height:650px}.file-row{margin:10px}.button{padding:8px}</style>
<button id="open">Open synthetic ticket</button><dialog id="work-drawer" data-placement="side"><h2>Synthetic ticket A</h2><button id="placement">Center</button><div id="detail"><textarea id="draft">Unsent synthetic note</textarea><input id="upload" type="file"><div class="spacer"></div><section id="discussion">${files.map(f=>`<div class="file-row" data-discussion-file="${f.id}">${f.filename}</div>`).join('')}<div class="file-row" data-discussion-file="quarantine">Quarantined synthetic photo</div></section><div class="spacer"></div></div></dialog>${panel}<script>${module}
window.galleryRechecks=[];const originalTimeout=window.setTimeout.bind(window);window.setTimeout=(callback,delay,...args)=>{if(delay===30000)galleryRechecks.push(callback);return originalTimeout(callback,delay,...args)};
let allowed = true; window.syntheticHidden=new URL(location.href).searchParams.get('hidden')==='1';if(syntheticHidden)Object.defineProperty(document,'hidden',{configurable:true,get:()=>syntheticHidden}); const created=[], revoked=[]; const originalCreate=URL.createObjectURL.bind(URL),originalRevoke=URL.revokeObjectURL.bind(URL);
URL.createObjectURL=blob=>{const url=originalCreate(blob);created.push(url);return url};URL.revokeObjectURL=url=>{revoked.push(url);originalRevoke(url)};
const drawer=document.getElementById('work-drawer');document.getElementById('open').onclick=()=>drawer.showModal();document.getElementById('placement').onclick=()=>drawer.dataset.placement='center';
const gallery=EverbranchAttachmentGallery.mount({host:document.getElementById('discussion'),files:${JSON.stringify([...files,{...files[0],id:'quarantine',status:'quarantined',downloadable:false}])},context:{mode:'private',origin:location.origin,base:location.origin+'/enterprise-campus/enterprise',kind:'request',id:'request-a',tenant:'synthetic-campus',viewer:'synthetic-actor',workspace:'synthetic-workspace'},current:()=>allowed,dialog:document.getElementById('attachment-gallery'),onDenied:()=>document.body.dataset.denied='true'});
window.fixture={gallery,created,revoked,recheck(){galleryRechecks.at(-1)?.()},revokeScope(){allowed=false;document.dispatchEvent(new CustomEvent('enterprise:workspace-state'))}};
</script>`;
const results=[];
for(const [engine,type] of [['chromium',chromium],['webkit',webkit]]) {
    const browser=await type.launch({headless:true});
    const context=await browser.newContext({viewport:{width:1440,height:1000},hasTouch:true});
    const page=await context.newPage();let denied=false, delay=false, release, pending=false;const calls=[],errors=[],releases=[];
    try {
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',async route=>{
        const url=new URL(route.request().url());
        if(url.origin!==origin)throw new Error('Unexpected network '+url.href);
        if(url.protocol==='blob:')return route.continue();
        if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
        assert.match(url.pathname,/\/files\/(one|two)\/preview$/);calls.push({url:url.href,headers:route.request().headers()});
        if(delay){pending=true;await new Promise(resolve=>{releases.push(resolve);release=()=>releases.splice(0).forEach(done=>done());});}
        return route.fulfill({status:denied?403:200,headers:{'Content-Type':denied?'application/json':'image/png','X-Team-Viewer':'synthetic-actor','X-Team-Workspace':'synthetic-workspace','Cache-Control':'private, no-store'},body:denied?Buffer.from('{}'):png}).catch(()=>{});
    });
    await page.goto(origin+'/?hidden=1');await page.locator('#open').click();await page.locator('.discussion-image').first().scrollIntoViewIfNeeded();
    await page.waitForTimeout(100);assert.equal(await page.evaluate(()=>document.hidden),true);assert.equal(calls.length,0,'A gallery mounted after tab hiding must not fetch');assert.equal(await page.locator('.discussion-image img[src]').count(),0);assert.equal(await page.evaluate(()=>fixture.created.length),0);
    await page.evaluate(()=>{syntheticHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);assert.equal(calls.length,2,'Visible return freshly authorizes only clean images');
    calls.length=0;
    await page.goto(origin);assert.equal(calls.length,0,'Hidden ticket must not fetch private image bytes');await page.locator('#open').click();await page.locator('.discussion-image').first().scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
    assert.equal(calls.length,2,'Quarantine must perform zero fetches');
    for(const call of calls){assert.equal(call.headers['x-team-viewer'],'synthetic-actor');assert.equal(call.headers['x-team-workspace'],'synthetic-workspace');}
    await page.locator('#placement').click();await page.locator('#upload').setInputFiles({name:'unsent.png',mimeType:'image/png',buffer:png});
    const first=page.getByRole('button',{name:'View photo Synthetic one.png',exact:true});await first.scrollIntoViewIfNeeded();
    const top=await page.locator('#detail').evaluate(node=>node.scrollTop);
    await first.click();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);
    assert.equal(await page.locator('#attachment-gallery').evaluate(node=>node.open),true);
    await page.getByRole('button',{name:'Zoom in photo'}).click();assert.equal(await page.locator('[data-gallery-zoom-value]').textContent(),'150%');
    await page.keyboard.press('ArrowRight');assert.equal(await page.locator('[data-gallery-title]').textContent(),'Synthetic one.png','Zoomed arrows must preserve panning');
    await page.getByRole('button',{name:'Fit',exact:true}).click();await page.keyboard.press('ArrowRight');await page.getByRole('heading',{name:'Synthetic two.png',exact:true}).waitFor();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);
    await page.getByRole('button',{name:'Previous photo',exact:true}).click();await page.getByRole('heading',{name:'Synthetic one.png',exact:true}).waitFor();
    await page.keyboard.press('Escape');assert.equal(await page.locator('#attachment-gallery').evaluate(node=>node.open),false);assert.equal(await page.locator('#work-drawer').evaluate(node=>node.open),true,'Escape must close only photo');
    assert.equal(await page.locator('#draft').inputValue(),'Unsent synthetic note');assert.equal(await page.locator('#upload').evaluate(node=>node.files[0].name),'unsent.png');assert.equal(await page.locator('#work-drawer').getAttribute('data-placement'),'center');
    assert.equal(await first.evaluate(node=>document.activeElement===node),true);assert.equal(await page.locator('#detail').evaluate(node=>node.scrollTop),top);
    await first.click();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);
    await page.evaluate(()=>{window.syntheticHidden=true;Object.defineProperty(document,'hidden',{configurable:true,get:()=>syntheticHidden});document.dispatchEvent(new Event('visibilitychange'));});
    assert.equal(await page.locator('#attachment-gallery').evaluate(node=>node.open),false);assert.equal(await page.evaluate(()=>fixture.created.length===fixture.revoked.length),true,'Tab hiding must revoke all private image bytes');assert.equal(await page.locator('.discussion-image').count(),2,'Tab hiding retains authorized controls for fresh recheck');
    await page.evaluate(()=>{window.syntheticHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
    assert.equal(await page.locator('#draft').inputValue(),'Unsent synthetic note');
    await page.setViewportSize({width:390,height:844});await first.click();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);
    const stage=page.locator('[data-gallery-stage]');await stage.dispatchEvent('touchstart',{touches:[{identifier:1,clientX:300,clientY:300}]});await stage.dispatchEvent('touchend',{changedTouches:[{identifier:1,clientX:120,clientY:310}]});await page.getByRole('heading',{name:'Synthetic two.png',exact:true}).waitFor();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);
    const fit=await page.locator('#attachment-gallery').boundingBox();assert.ok(fit.width<=390&&fit.height<=844,'Phone modal fits viewport');
    await page.screenshot({path:resolve(evidence,`${engine}-phone.png`)});
    await page.getByRole('button',{name:'Close photo',exact:true}).click();
    denied=true;await first.click();await page.waitForFunction(()=>document.body.dataset.denied==='true');assert.equal(await page.locator('#attachment-gallery').evaluate(node=>node.open),false);assert.equal(await page.locator('.discussion-image').count(),0);assert.equal(await page.locator('[data-gallery-image]').getAttribute('src'),null);
    assert.equal(await page.evaluate(()=>fixture.created.length===fixture.revoked.length),true,'Every blob is revoked after denial');
    denied=false;await page.reload();await page.locator('#open').click();await first.scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
    delay=true;pending=false;await first.click();await page.waitForFunction(()=>document.querySelector('[data-gallery-status]').textContent.includes('Checking'));
    for(let count=0;!pending&&count<50;count++)await new Promise(resolve=>setTimeout(resolve,10));assert.equal(pending,true);
    await page.evaluate(()=>fixture.revokeScope());assert.equal(await page.locator('#attachment-gallery').evaluate(node=>node.open),false);release();delay=false;
    await page.waitForFunction(()=>fixture.created.length===fixture.revoked.length);assert.equal(await page.locator('[data-gallery-image]').getAttribute('src'),null,'Late bytes must not revive revoked target');
    await page.reload();await page.locator('#open').click();await first.scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
    delay=true;pending=false;await first.click();
    for(let count=0;!pending&&count<50;count++)await new Promise(resolve=>setTimeout(resolve,10));assert.equal(pending,true);
    await page.evaluate(()=>dispatchEvent(new PageTransitionEvent('pagehide')));release();delay=false;
    await page.waitForFunction(()=>fixture.created.length===fixture.revoked.length);assert.equal(await page.locator('[data-gallery-image]').getAttribute('src'),null,'Page exit must clear all preview URLs');
    pending=false;delay=true;await page.reload();await page.locator('#open').click();await first.scrollIntoViewIfNeeded();
    for(let count=0;!pending&&count<50;count++)await new Promise(resolve=>setTimeout(resolve,10));assert.equal(pending,true);
    // A late completion must see current visibility even before the event callback runs.
    await page.evaluate(()=>{window.syntheticHidden=true;Object.defineProperty(document,'hidden',{configurable:true,get:()=>syntheticHidden});});release();delay=false;
    await page.waitForTimeout(100);assert.equal(await page.locator('.discussion-image img[src]').count(),0);assert.equal(await page.evaluate(()=>fixture.created.length),0,'Hidden late responses must not create retained blobs');
    await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await page.evaluate(()=>{syntheticHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
    for(const phase of ['initial viewer','periodic recheck']) {
        denied=false;delay=false;await page.reload();await page.locator('#open').click();await first.scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
        if(phase==='periodic recheck'){await first.click();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);}
        pending=false;delay=true;
        if(phase==='initial viewer')await first.click();else await page.evaluate(()=>fixture.recheck());
        for(let count=0;!pending&&count<50;count++)await new Promise(resolve=>setTimeout(resolve,10));assert.equal(pending,true);
        await page.evaluate(()=>{window.syntheticHidden=true;Object.defineProperty(document,'hidden',{configurable:true,get:()=>syntheticHidden});});release();delay=false;
        await page.waitForFunction(()=>!document.querySelector('#attachment-gallery').open);assert.equal(await page.locator('body').getAttribute('data-denied'),null,phase+' visibility retirement is not access denial');assert.equal(await page.locator('.discussion-image').count(),2);assert.equal(await page.evaluate(()=>fixture.created.length===fixture.revoked.length),true,phase+' retires all image bytes');
        await page.evaluate(()=>{document.dispatchEvent(new Event('visibilitychange'));syntheticHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await page.waitForFunction(()=>document.querySelectorAll('.discussion-image img[src]').length===2);
        assert.equal(await page.locator('#draft').inputValue(),'Unsent synthetic note');
        if(phase==='periodic recheck'){
            await first.click();await page.waitForFunction(()=>document.querySelector('[data-gallery-image]')?.naturalWidth>0);denied=true;await page.evaluate(()=>fixture.recheck());await page.waitForFunction(()=>document.body.dataset.denied==='true');assert.equal(await page.locator('.discussion-image').count(),0,'A real periodic access refusal still destroys image controls');
        }
    }
    assert.deepEqual(errors,[]);
    results.push({engine,synthetic:true,phoneViewport:true,physicalTouch:false,nativeServer:false,checks:['ready-only headers','zoom fit navigation','nested Escape','draft file placement focus scroll retention','simulated swipe','revocation revoke','pending scope abort late fence','pagehide abort and revoke','tab hide revoke and fresh return','pre-hidden mount zero fetch','hidden late completion zero retained bytes','hidden viewer/recheck retirement preserves controls','real periodic denial still clears access'],requests:calls.length});
    } finally { release?.();await context.close();await browser.close(); }
}
await writeFile(resolve(evidence,'result.json'),JSON.stringify(results,null,2));
console.log(JSON.stringify(results));
