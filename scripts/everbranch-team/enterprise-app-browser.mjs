import assert from 'node:assert/strict';
import {resolve} from 'node:path';

/** Install controls/update simulation only; never installs on a user's device. */
export async function checkEnterpriseApp(page,evidence,packet,tenant) {
    const manifest=await page.locator('link[rel=manifest]').getAttribute('href');
    const data=await page.evaluate(async url=>{const response=await fetch(url,{cache:'no-store'});return {status:response.status,body:await response.json()};},manifest);
    assert.equal(data.status,200);assert.equal(data.body.display,'standalone');assert.equal(data.body.start_url,new URL('/',page.url()).href);
    assert.equal(data.body.scope,data.body.start_url);assert.equal(data.body.icons.length,2);
    assert.equal(await page.evaluate(async()=>navigator.serviceWorker?(await navigator.serviceWorker.getRegistrations()).length:0),0,'No private offline cache/service worker is installed');
    await page.locator('#enterprise-install-open').click();
    await page.locator('#enterprise-install-title').waitFor();
    assert.match(await page.locator('#enterprise-install-dialog').innerText(),/Add to Home Screen/);
    await page.locator('#enterprise-update-check').click();
    await page.locator('#enterprise-update-preview').waitFor({state:'visible'});
    assert.equal(await page.locator('#enterprise-update-reload').isDisabled(),true);
    await page.keyboard.press('Escape');assert.equal(await page.locator('#enterprise-install-open').evaluate(e=>e===document.activeElement),true);
    // Simulate a newer release response without deploying or installing anything.
    await page.route('**/enterprise/app-version',route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({version:'synthetic-next-release',offline_support:false})}));
    await page.locator('#enterprise-install-open').click();await page.locator('#enterprise-update-check').click();
    await page.locator('#enterprise-update-status').getByText('An updated server version is available. Review the refresh details before continuing.',{exact:true}).waitFor();
    await page.locator('#enterprise-update-confirm').check();assert.equal(await page.locator('#enterprise-update-reload').isEnabled(),true);
    let release;const held=new Promise(resolve=>{release=resolve;});
    await page.route('**/enterprise/finance/commands?*',async route=>{const response=await route.fetch();await held;await route.fulfill({response});});
    const save=page.evaluate(async({packet,tenant})=>{
        const workspace=await(await fetch('/enterprise/data?tenant='+tenant+'&area_id='+packet.area_id)).json();
        const transport=EverbranchTransport.create({identity:{viewer:workspace.viewer_id,workspace:String(workspace.workspace.id)},csrf:document.querySelector('meta[name=csrf-token]').content});
        return transport.request('/enterprise/finance/commands?tenant='+tenant,{action:'packet.handoff',id:packet.id,area_id:packet.area_id,expected_version:packet.version,request_id:crypto.randomUUID(),next_owner_id:Number(packet.next_owner_id),confirmed:true,reason:'Synthetic existing-owner handoff while testing safe refresh gating'});
    },{packet,tenant});
    try{
        await page.waitForFunction(()=>EverbranchTransport.pendingWrites===1);
        assert.equal(await page.locator('#enterprise-update-reload').isDisabled(),true,'A pending save must block refresh even after confirmation');
        await page.waitForTimeout(250);assert.equal(await page.locator('#enterprise-install-dialog').evaluate(e=>e.open),true,'No automatic refresh');
    }finally{release();}
    const saved=await save;assert.equal(saved.version,packet.version+1);
    await page.waitForFunction(()=>EverbranchTransport.pendingWrites===0);
    assert.equal(await page.locator('#enterprise-update-reload').isDisabled(),true,'A new write invalidates the earlier refresh confirmation');
    await page.locator('#enterprise-update-confirm').check();
    await page.unroute('**/enterprise/finance/commands?*');
    for(const[name,width,height]of[['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]){
        await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
        await page.screenshot({path:resolve(evidence,'app-install-'+name+'.png'),fullPage:true,caret:'initial'});
    }
    await page.locator('#enterprise-update-reload').click();await page.locator('#finance-new').waitFor({state:'visible'});
    await page.locator('[data-finance-record="'+packet.id+'"]').click();await page.locator('#finance-history').getByText('Version '+saved.version,{exact:true}).waitFor();
    await page.unroute('**/enterprise/app-version');
    console.log('PASS installable app foundation: real manifest/standalone declaration, no service worker, instructions/focus, simulated newer version, pending-write refresh fence and explicit reload preserves the persistent packet. No physical installation or hosted update is claimed.');
}
