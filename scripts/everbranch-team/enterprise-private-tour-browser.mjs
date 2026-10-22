import assert from 'node:assert/strict';
import {resolve} from 'node:path';

/** Authenticated server-backed tour acceptance. All records belong to the disposable fixture. */
export async function checkPrivateWorkspaceTour(page,evidence) {
    const tenant='synthetic-enterprise';
    const api=async(path,body)=>{
        const response=await page.evaluate(async({path,body,tenant})=>{
            const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);
            const result=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
            return {status:result.status,body:await result.json()};
        },{path,body,tenant});
        assert.equal(response.status,200,JSON.stringify(response.body));return response.body;
    };
    const area=(await api('/enterprise/commands',{action:'workspace.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),title:'Synthetic private tour workspace',visibility:'department_only'})).id;
    const recoveryArea=(await api('/enterprise/commands',{action:'workspace.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),title:'Synthetic private recovery workspace',visibility:'department_only'})).id;
    await api('/enterprise/commands',{action:'project.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),area_id:area,title:'Synthetic unrelated first project'});
    await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=projects`,page.url()).href);
    await page.locator('#create-project').click();
    await page.locator('#create-form [name=title]').fill('Synthetic private tour project');
    await page.locator('#create-form').getByRole('button',{name:'Save',exact:true}).click();
    await page.locator('#request-list').getByRole('button',{name:'Synthetic private tour project',exact:true}).click();
    const original=(await api('/enterprise/data?area_id='+area));
    const project=original.projects.find(row=>row.name==='Synthetic private tour project');assert.ok(project);
    await page.locator('[data-section=requests]').click();
    await page.locator('#create-request').click();
    await page.locator('#create-form [name=title]').fill('Synthetic private tour request');
    await page.locator('#create-form [name=description]').fill('Synthetic persisted intake; no actual campus incident.');
    await page.locator('#create-form').getByRole('button',{name:'Save',exact:true}).click();
    await page.locator('#request-list').getByRole('button',{name:'Synthetic private tour request',exact:true}).click();
    await page.locator('#action-note').fill('Synthetic current-role triage');
    await page.getByRole('button',{name:'Triage request',exact:true}).click();
    await page.locator('#assign-project').selectOption(project.id);
    await page.locator('#assign-member').selectOption(original.viewer_id);
    await page.getByRole('button',{name:'Assign task',exact:true}).click();
    await page.locator('#action-note').fill('Synthetic progress persisted by the current signed-in owner.');
    await page.getByRole('button',{name:'Save progress',exact:true}).click();
    await page.getByText('Synthetic progress persisted by the current signed-in owner.',{exact:true}).waitFor();
    await page.reload();
    const persisted=await api('/enterprise/data?area_id='+area);
    const request=persisted.requests.find(row=>row.title==='Synthetic private tour request');
    assert.equal(request.status,'in_progress');assert.equal(request.task.assignee_id,original.viewer_id);
    assert.equal(request.data.progress_note,'Synthetic progress persisted by the current signed-in owner.');
    assert.equal(persisted.projects.filter(row=>row.id===project.id).length,1);
    assert.equal(await page.locator('#reviewer-perspective').count(),0,'Private runtime must not mount the public persona adapter');
    assert.equal(await page.evaluate(()=>typeof globalThis.EverbranchSampleTransport),'undefined','Private tour must not use browser-backed sample transport');

    await page.emulateMedia({reducedMotion:'reduce'});
    const root=page.locator('[data-workspace-tour="private"]');
    await root.getByText('SERVER-BACKED DEMONSTRATION WORKSPACE',{exact:true}).waitFor();
    assert.equal(await root.locator('[data-tour-email-open]').isVisible(),false);
    assert.equal(await root.locator('[data-tour-try]').isVisible(),false);
    await page.locator('[data-section=projects]').click();
    await page.locator(`#request-list [data-open="${project.id}"]`).click();
    await page.locator('#discussion-body').fill('Synthetic unsent project draft retained through the private tour.');
    const writes=[];
    const recordWrite=req=>{if(!['GET','HEAD','OPTIONS'].includes(req.method()))writes.push({method:req.method(),url:req.url()});};
    page.on('request',recordWrite);
    const open=async()=>{await root.locator('[data-tour-open]').click();await root.locator('[data-tour-dialog]').waitFor({state:'visible'});};
    const scene=async index=>{await root.locator(`[data-tour-scene="${index}"]`).click();await page.waitForFunction(index=>document.querySelector('[data-workspace-tour="private"]').dataset.tourScene===String(index),index);};
    const expectedLinks=[['overview','buildings','calendar'],['requests','requester'],['workflow','projects'],['projects','roll-call'],['people','assets','calendar'],['roll-call','projects']];
    await open();
    assert.match(await root.locator('[data-tour-role]').innerText(),/Signed in: Fixture Owner/i);
    assert.match(await root.locator('[data-tour-role]').innerText(),/Synthetic private tour workspace/i);
    assert.match(await root.locator('[data-tour-cards]').innerText(),/Synthetic private tour request/);
    assert.match(await root.locator('[data-tour-cards]').innerText(),/Synthetic private tour project/);
    await root.locator('[data-tour-play]').click();
    await root.locator('[data-tour-play]').click();
    for(let index=0;index<6;index++){
        await scene(index);
        for(const id of expectedLinks[index])assert.equal(await root.locator(`[data-workspace-tour-link="${id}"]`).isEnabled(),true,`Scene ${index+1} current ${id} link`);
    }
    await root.locator('[data-tour-restart]').click();
    await root.locator('[data-tour-close]').click();
    assert.equal(new URL(page.url()).searchParams.get('selected'),project.id);
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project draft retained through the private tour.');
    for(const [index,section]of[[0,'home'],[1,'requests'],[3,'projects'],[4,'people'],[5,'roll_call']]){
        await open();await scene(index);await root.locator('[data-tour-screen]').click();
        await page.waitForFunction(section=>new URL(location.href).searchParams.get('section')===section,section);
        if(index===1)assert.equal(new URL(page.url()).searchParams.get('selected'),request.id,'Explore opens the actual authorized request');
    }
    assert.deepEqual(writes,[],'Tour playback, replay and actual-screen exploration must not mutate or call paid services');
    page.off('request',recordWrite);
    await page.locator('[data-section=projects]').click();await page.locator(`#request-list [data-open="${project.id}"]`).click();
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project draft retained through the private tour.');
    await page.reload();await page.locator('#discussion-body').waitFor();
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project draft retained through the private tour.');
    for(const [label,width,height]of[['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]){
        await page.setViewportSize({width,height});await open();await scene(2);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${label} private tour document overflow`);
        const touch=await root.locator('[data-workspace-tour-link="workflow"]').evaluate(el=>({height:el.getBoundingClientRect().height,minimum:parseFloat(getComputedStyle(el).minHeight)}));
        assert.ok(touch.minimum>=44&&touch.height>=43.5,`${label} workflow link touch target: ${JSON.stringify(touch)}`);
        await page.screenshot({path:resolve(evidence,`enterprise-private-tour-${label}.png`),fullPage:true});
        await root.locator('[data-tour-close]').click();
    }
    await page.setViewportSize({width:1440,height:1000});await open();await scene(2);
    await root.locator('[data-workspace-tour-link="workflow"]').click();
    await page.waitForURL(url=>url.pathname==='/projects/workspace');
    assert.equal(new URL(page.url()).searchParams.get('project_id'),project.id,'Workflow tour link must use the currently selected project, not the first project');
    assert.equal(new URL(page.url()).searchParams.get('area_id'),area);
    await page.getByRole('button',{name:'Use Illustrative maintenance workflow',exact:true}).click();
    await page.getByRole('button',{name:/Settings$/}).click();
    await page.getByLabel('Workflow name',{exact:true}).fill('Synthetic privately guided workflow');
    let savedReply,holdNextRead=false,releaseRead,markRead;
    const held=new Promise(resolve=>{releaseRead=resolve;});
    const readStarted=new Promise(resolve=>{markRead=resolve;});
    const saveBodies=[];
    await page.route('**/enterprise/workflows?*',async route=>{
        if(route.request().method()==='POST'){
            saveBodies.push(route.request().postDataJSON());
            const response=await route.fetch();savedReply=await response.json();holdNextRead=true;
            return route.fulfill({response});
        }
        if(holdNextRead){holdNextRead=false;markRead();await held;await route.abort().catch(()=>{});return;}
        return route.continue();
    });
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    let barrier;
    try{await Promise.race([readStarted,new Promise((_,reject)=>{barrier=setTimeout(()=>reject(new Error('Confirmed-save follow-up read did not begin')),30000);})]);}finally{clearTimeout(barrier);}
    assert.equal(new URL(page.url()).searchParams.get('workflow'),savedReply.id,'Confirmed save must pin selection before its follow-up read completes');
    assert.equal(await page.getByRole('button',{name:'Retry exact operation',exact:true}).count(),0,'Confirmed save must not remain retryable');
    try{await page.reload({waitUntil:'domcontentloaded'});}finally{releaseRead();}
    await page.unroute('**/enterprise/workflows?*');
    await page.getByRole('heading',{name:'Synthetic privately guided workflow',exact:true}).waitFor();
    assert.equal(saveBodies.length,1,'Reload after a confirmed save cannot submit another write');
    const workflows=await api(`/enterprise/workflows?area_id=${area}&project_id=${project.id}`);
    const workflow=workflows.items.find(row=>row.name==='Synthetic privately guided workflow');assert.ok(workflow,'Tour-opened workflow must persist through the real server engine');
    assert.equal(workflows.items.filter(row=>row.name===workflow.name).length,1);assert.equal(workflow.version,1);
    await page.waitForURL(url=>url.searchParams.get('workflow')===workflow.id);
    await page.getByRole('button',{name:'Save draft',exact:true}).waitFor({state:'visible'});
    await page.reload();await page.getByRole('heading',{name:'Synthetic privately guided workflow',exact:true}).waitFor();
    const saved=await api(`/enterprise/workflows/${workflow.id}/data`);assert.equal(saved.workflow.name,workflow.name);assert.equal(saved.workflow.project_id,project.id);
    await page.getByRole('link',{name:/Back to Projects$/}).click();
    await page.locator('#discussion-body').waitFor();
    assert.equal(new URL(page.url()).searchParams.get('selected'),project.id);
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project draft retained through the private tour.');
    await page.route('**/enterprise/data?*',async route=>{
        if(new URL(route.request().url()).searchParams.get('area_id')===area)
            return route.fulfill({status:403,json:{message:'Synthetic current workspace access refused'}});
        return route.continue();
    });
    await page.locator(`#areas [data-area="${area}"]`).click();
    await page.locator('#status').getByText('Synthetic current workspace access refused',{exact:true}).waitFor();
    await open();await scene(2);
    assert.match(await root.locator('[data-tour-detail]').innerText(),/Waiting for the current authorized workspace/);
    assert.equal(await root.locator('[data-workspace-tour-link]:enabled').count(),0,'A denied current data read must disable all scene destinations');
    assert.equal((await root.innerText()).includes('Synthetic private tour project'),false,'Denied tour context must remove its previous project');
    await root.locator('[data-tour-close]').click();await page.unroute('**/enterprise/data?*');
    await page.reload();
    await page.locator(`#request-list [data-open="${project.id}"]`).waitFor();
    let releaseArea,markArea,delayArea=true;
    const heldArea=new Promise(resolve=>{releaseArea=resolve;});
    const areaStarted=new Promise(resolve=>{markArea=resolve;});
    await page.route('**/enterprise/data?*',async route=>{
        if(delayArea&&new URL(route.request().url()).searchParams.get('area_id')===area){
            delayArea=false;const response=await route.fetch();markArea();await heldArea;
            return route.fulfill({response}).catch(()=>{});
        }
        return route.continue();
    });
    await page.locator(`#areas [data-area="${area}"]`).click();
    let areaBarrier;
    try{await Promise.race([areaStarted,new Promise((_,reject)=>{areaBarrier=setTimeout(()=>reject(new Error('Delayed current-area fixture did not begin')),30000);})]);}finally{clearTimeout(areaBarrier);}
    await page.locator(`#areas [data-area="${recoveryArea}"]`).click();
    await page.waitForURL(url=>url.searchParams.get('area_id')===recoveryArea);
    await open();await scene(2);
    assert.match(await root.locator('[data-tour-role]').innerText(),/Synthetic private recovery workspace/i);
    const oldReply=page.waitForResponse(response=>new URL(response.url()).pathname==='/enterprise/data'&&new URL(response.url()).searchParams.get('area_id')===area);
    releaseArea();await (await oldReply).finished();
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>resolve(null)))));
    assert.equal(new URL(page.url()).searchParams.get('area_id'),recoveryArea,'A delayed old scope response must not replace the current area');
    assert.match(await root.locator('[data-tour-role]').innerText(),/Synthetic private recovery workspace/i);
    assert.equal((await root.innerText()).includes('Synthetic private tour project'),false);
    assert.equal(await root.locator('[data-workspace-tour-link="workflow"]').isEnabled(),false,'The empty current area cannot inherit the old project workflow');
    await root.locator('[data-tour-close]').click();await page.unroute('**/enterprise/data?*');
    await page.locator(`#areas [data-area="${area}"]`).click();
    await page.locator(`#request-list [data-open="${project.id}"]`).click();
    await open();await scene(2);assert.equal(await root.locator('[data-workspace-tour-link="workflow"]').isEnabled(),true);
    await root.locator('[data-tour-close]').click();
    await page.emulateMedia({reducedMotion:'no-preference'});
    console.log('PASS private workspace tour: real UI persistence and confirmed-save interrupted reload; current identity and six authorized links; zero replay POSTs; selection/composer retention; denied/stale scope recovery; desktop/phone/iPad touch layouts.');
}
