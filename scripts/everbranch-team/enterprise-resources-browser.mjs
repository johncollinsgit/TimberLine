import assert from 'node:assert/strict';
import {resolve} from 'node:path';

/** Called by the disposable, authenticated Enterprise browser harness only. */
export async function checkResourceWorkspace(page,evidence) {
    const tenant='synthetic-enterprise';
    const api=async(path,body)=>{
        const result=await page.evaluate(async({path,body,tenant})=>{
            const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);
            const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
            return {status:response.status,viewer:response.headers.get('X-Team-Viewer'),body:await response.json()};
        },{path,body,tenant});
        assert.equal(result.status,200,JSON.stringify(result.body));return result;
    };
    const created=await api('/enterprise/commands',{action:'workspace.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,title:'Synthetic resource browser workspace',visibility:'department_only'});
    const area=created.body.id;
    const command=async body=>(await api('/enterprise/resources/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,area_id:area,...body})).body;
    const facility=await command({action:'location.save',title:'Synthetic verified reference',catalog_map_number:'52',level:'facility'});
    const room=await command({action:'location.save',title:'Synthetic room only',level:'room',parent_id:facility.id});
    const navigate=async section=>{await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=${section}`,page.url()).href);await page.waitForFunction(()=>document.getElementById('res-status')?.textContent.includes('permissions confirmed'));};
    await navigate('buildings');
    await page.locator('#res-query').fill('Financial Aid');
    assert.equal(await page.locator('#res-list [data-res-open]').count(),1);
    await page.locator('#res-list [data-res-open]').click();
    await page.getByRole('heading',{name:'Cannon Welcome Center',exact:true}).waitFor();
    assert.match(await page.locator('#res-detail').innerText(),/Cannon Student Center/);
    assert.equal(await page.locator('#res-plan-image').evaluate(image=>image.src.startsWith('data:image/png;base64,')),true);
    await page.locator('#res-zoom-in').click();assert.equal(await page.locator('#res-zoom').inputValue(),'125');
    await page.locator('#res-plan-bottom').click();
    await page.screenshot({path:resolve(evidence,'resources-official-directory.png'),fullPage:true});
    await navigate('assets');
    await page.locator('#res-new').click();
    await page.locator('#res-form-title').fill('Synthetic amplifier <img src=x onerror=alert(1)>');
    await page.locator('#res-form-location').selectOption(facility.id);
    await page.locator('#res-form-condition').fill('Synthetic inspected condition');
    let dropped=false;const receipts=[];
    await page.route('**/enterprise/resources/commands?*',async route=>{
        if(route.request().method()!=='POST')return route.continue();
        receipts.push(route.request().postDataJSON());
        if(!dropped){dropped=true;await route.fetch();return route.abort();}return route.continue();
    });
    await page.locator('#res-save').click();await page.locator('#res-form-status').filter({hasText:'The outcome is unconfirmed'}).waitFor();
    await page.reload();await page.waitForFunction(()=>document.getElementById('res-status')?.textContent.includes('permissions confirmed'));
    await page.locator('#res-new').click();await page.getByRole('button',{name:'Retry exact saved action',exact:true}).click();
    await page.waitForFunction(()=>!document.getElementById('res-dialog').open);
    assert.deepEqual(receipts[0],receipts[1],'Lost resource response must replay the identical body and receipt');
    await page.unroute('**/enterprise/resources/commands?*');
    const list=(await api('/enterprise/resources?area_id='+area)).body.items;
    const asset=list.find(row=>row.kind==='asset');assert.ok(asset);assert.equal(list.filter(row=>row.kind==='asset').length,1);
    assert.equal(await page.locator('#res-detail img[src=x]').count(),0);
    await page.getByRole('button',{name:'Check out asset',exact:true}).click();
    await page.locator('#res-form-location').selectOption(facility.id);
    await page.locator('#res-form-return').fill('2026-11-01T01:30');
    assert.match(await page.locator('#res-exact-time').innerText(),/occurs twice/);
    await page.locator('#res-form-return-utc').fill('2026-11-01T06:30:00Z');
    assert.match(await page.locator('#res-exact-time').innerText(),/2026-11-01T06:30:00Z/);
    await page.locator('#res-save').click();await page.waitForFunction(()=>!document.getElementById('res-dialog').open);
    await page.getByRole('button',{name:'Return asset',exact:true}).click();await page.locator('#res-form-location').selectOption(facility.id);await page.locator('#res-form-condition').fill('Synthetic returned condition');await page.locator('#res-save').click();await page.waitForFunction(()=>!document.getElementById('res-dialog').open);
    assert.equal((await api('/enterprise/resources/'+asset.id)).body.status,'available');
    await navigate('calendar');await page.locator('#res-new').click();
    await page.locator('#res-form-title').fill('Synthetic reviewed class');await page.locator('#res-form-resource').selectOption(room.id);
    await page.locator('#res-form-start').fill('2026-11-02T09:00');await page.locator('#res-form-end').fill('2026-11-02T10:00');
    await page.locator('#res-save').click();await page.waitForFunction(()=>!document.getElementById('res-dialog').open);
    const overlap=await command({action:'reservation.save',title:'Synthetic maintenance conflict',resource_id:room.id,starts_at:'2026-11-02T14:30:00Z',ends_at:'2026-11-02T15:30:00Z',category:'maintenance'});
    assert.equal(overlap.status,'conflicted');await page.locator('#res-refresh').click();await page.locator('#res-list [data-res-open]').filter({hasText:'Exclusive reservation conflict'}).click();
    await page.getByRole('button',{name:'Record resolution',exact:true}).click();await page.locator('#res-form-note').fill('No cancellation yet');await page.locator('#res-save').click();await page.locator('#res-form-status').filter({hasText:'Cancel the conflicting booking explicitly'}).waitFor();await page.locator('#res-close').click();
    await page.locator('#res-list [data-res-open]').filter({hasText:'Synthetic maintenance conflict'}).click();await page.getByRole('button',{name:'Cancel this reservation',exact:true}).click();await page.locator('#res-form-note').fill('Synthetic manager cancels maintenance window explicitly');await page.locator('#res-save').click();await page.waitForFunction(()=>!document.getElementById('res-dialog').open);
    await page.locator('#res-list [data-res-open]').filter({hasText:'Exclusive reservation conflict'}).click();await page.getByRole('button',{name:'Record resolution',exact:true}).click();
    // The original failed resolution draft remains reviewable; retain its exact resource version.
    await page.locator('#res-form-note').fill('Maintenance cancelled; class retained at its reviewed time');await page.locator('#res-save').click();await page.waitForFunction(()=>!document.getElementById('res-dialog').open);
    const final=(await api('/enterprise/resources?area_id='+area)).body.items;
    assert.equal(final.find(row=>row.kind==='conflict').status,'resolved');
    assert.equal(final.find(row=>row.title==='Synthetic reviewed class').data.starts_at,'2026-11-02T14:00:00Z');
    for(const [name,width,height]of[['desktop',1440,1000],['ipad',820,1180],['phone',390,844]]){await page.setViewportSize({width,height});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),name+' resources overflow');await page.screenshot({path:resolve(evidence,'resources-calendar-'+name+'.png'),fullPage:true});}
    await page.setViewportSize({width:1440,height:1000});
}
