import {clickEnterpriseNavigation} from './enterprise-mobile-navigation-browser.mjs';
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {checkEnterpriseApp} from './enterprise-app-browser.mjs';

/** Real isolated HTTP persistence with fictional references; never a Finance integration. */
export async function checkFinanceWorkspace(page, evidence) {
    const tenant='synthetic-enterprise';
    const api=async(path,body)=>page.evaluate(async({path,body,tenant})=>{
        const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);
        const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
        const result=await response.json();if(!response.ok)throw new Error(response.status+':'+JSON.stringify(result));return result;
    },{path,body,tenant});
    const command=body=>api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,...body});
    const area=(await command({action:'workspace.create',title:'Synthetic Finance packets',visibility:'department_only'})).id;
    const project=(await command({action:'project.create',area_id:area,title:'Synthetic classroom repair'})).id;
    await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=finance`,page.url()).href);
    await page.locator('#finance-new').waitFor({state:'visible'});
    await page.locator('#finance-new').click();
    const title='Synthetic invoice packet <img src=x onerror=alert(1)>';
    await page.locator('#finance-title').fill(title);
    await page.locator('#finance-project').selectOption(project);
    await page.locator('#finance-amount').fill('12500');
    await page.locator('#finance-document').fill('Synthetic original invoice held by existing Finance process');
    await page.locator('#finance-fund').fill('SAMPLE-CAPITAL');
    await page.locator('#finance-restriction').fill('Synthetic restriction reference; check with Finance');
    await page.locator('#finance-source').fill('Synthetic ledger reference, not a ledger balance');
    const owner=await page.locator('#finance-owner option').evaluateAll(items=>items.find(i=>i.value)?.value);
    assert.ok(owner);await page.locator('#finance-owner').selectOption(owner);
    // Ordinary navigation/reload preserves the unsent packet without submitting it.
    await clickEnterpriseNavigation(page,page.locator('[data-section="requests"]'));
    await clickEnterpriseNavigation(page,page.locator('[data-section="finance"]'));
    await page.locator('#finance-form').waitFor({state:'visible'});
    assert.equal(await page.locator('#finance-title').inputValue(),title);
    await page.reload();await page.locator('#finance-form').waitFor({state:'visible'});
    assert.equal(await page.locator('#finance-fund').inputValue(),'SAMPLE-CAPITAL');
    const bodies=[];let dropped=false;
    await page.route('**/enterprise/finance/commands?*',async route=>{
        const body=route.request().postDataJSON();if(body.action!=='packet.save')return route.continue();bodies.push(body);
        if(!dropped){dropped=true;await route.fetch();return route.abort();}return route.continue();
    });
    await page.getByRole('button',{name:'Save packet draft',exact:true}).click();
    await page.locator('#finance-retry').waitFor({state:'visible'});
    await page.reload();await page.locator('#finance-retained').waitFor({state:'visible'});
    assert.equal(await page.locator('#finance-new').isDisabled(),true);
    await page.locator('#finance-retry').click();
    await page.locator('#finance-status').getByText('Existing packet action recovered without a second change.',{exact:true}).waitFor();
    assert.deepEqual(bodies[1],bodies[0]);await page.unroute('**/enterprise/finance/commands?*');
    const listing=await api('/enterprise/finance?area_id='+area);assert.equal(listing.items.length,1);
    let packet=listing.items[0];assert.equal(packet.status,'draft');assert.equal(packet.version,1);
    assert.equal(await page.locator('#finance-detail img').count(),0);
    const transition=async(name,extra={})=>{
        const form=page.getByRole('form',{name,exact:true});await form.locator('[name=reason]').fill('Synthetic explicit reviewed handoff; no payment authorization');
        for(const[key,value]of Object.entries(extra)){const field=form.locator(`[name=${key}]`);if(key==='decision'||key==='next_owner_id')await field.selectOption(value);else await field.fill(value);}
        await form.locator('[name=confirmed]').check();await form.getByRole('button',{name,exact:true}).click();
        await page.locator('#finance-status').getByText('Packet action recorded. No payment or external notification was sent.',{exact:true}).waitFor();
        packet=await api('/enterprise/finance/'+packet.id);
    };
    await transition('Submit exact packet for review');assert.equal(packet.status,'submitted');
    await transition('Confirm internal packet review',{decision:'approved'});assert.equal(packet.status,'approved');assert.equal(packet.external_completion,null);
    await transition('Reopen packet and invalidate prior review');assert.equal(packet.status,'draft');assert.equal(packet.review,null);
    const layouts=[];
    for(const[name,width,height]of[['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]){
        await page.setViewportSize({width,height});await page.locator('#finance-workspace').scrollIntoViewIfNeeded();
        const layout=await page.locator('#finance-workspace').evaluate(panel=>({overflow:document.documentElement.scrollWidth>innerWidth+1,buttons:[...panel.querySelectorAll('button')].filter(e=>e.getClientRects().length).map(e=>({label:e.textContent.trim(),height:e.getBoundingClientRect().height}))}));
        assert.equal(layout.overflow,false,name+' finance overflow');assert.ok(layout.buttons.every(b=>b.height>=43.5),JSON.stringify(layout));
        layouts.push({name,width,height,...layout});await page.screenshot({path:resolve(evidence,'finance-'+name+'.png'),fullPage:true});
    }
    const history=await api('/enterprise/finance/'+packet.id+'/history');assert.equal(history.items.length,4);
    await page.reload();await page.locator('#finance-new').waitFor({state:'visible'});await page.locator('[data-finance-record="'+packet.id+'"]').click();
    await page.locator('#finance-history').getByText('Version 4',{exact:true}).waitFor();
    assert.equal((await api('/enterprise/finance/'+packet.id)).fund_code,'SAMPLE-CAPITAL');
    await writeFile(resolve(evidence,'finance-acceptance.json'),JSON.stringify({packet_id:packet.id,version:packet.version,history:history.items.length,layouts,provider_calls:0,kind:'fictional isolated real-HTTP acceptance; no payment verification'},null,2));
    await checkEnterpriseApp(page,evidence,packet,tenant);
    console.log('PASS real HTTP Finance packet: retained draft, exact interrupted save/reload/replay, one persistent packet, assigned review/reopen/history, inert hostile text and desktop/phone/iPad viewport checks; no payment/provider acceptance claimed.');
}
