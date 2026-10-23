import assert from 'node:assert/strict';
import {resolve} from 'node:path';
import {closeWorkDetail} from './enterprise-work-detail-browser.mjs';

/** Synthetic authenticated fixtures only; no access grants, providers or college data. */
export async function checkTaskEvidenceWorkflow(page,evidence) {
    const tenant='synthetic-enterprise';
    const api=async(path,body)=>{
        const result=await page.evaluate(async({path,body,tenant})=>{const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});return {status:response.status,viewer:response.headers.get('X-Team-Viewer'),body:await response.json()};},{path,body,tenant});
        assert.equal(result.status,200,JSON.stringify(result.body));return result;
    };
    const command=async body=>(await api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,...body})).body;
    const source=await command({action:'workspace.create',title:'Synthetic evidence source',visibility:'department_only'});
    const destination=await command({action:'workspace.create',title:'Synthetic evidence destination',visibility:'department_only'});
    const project=await command({action:'project.create',area_id:source.id,title:'Synthetic evidence project'});
    const targetProject=await command({action:'project.create',area_id:destination.id,title:'Synthetic destination project'});
    const owner=(await api('/enterprise/data?area_id='+source.id)).body.viewer_id;
    let request=await command({action:'request.create',area_id:source.id,title:'Synthetic evidence request',description:'Disposable browser fixture; no actual campus incident.'});
    request=await command({action:'request.triage',id:request.id,area_id:source.id,expected_version:request.version,note:'Synthetic triage'});
    request=await command({action:'request.assign',id:request.id,area_id:source.id,expected_version:request.version,project_id:project.id,assignee_id:owner});
    const task=request.task;
    const go=async(section,selected)=>{await page.goto(new URL(`/?tenant=${tenant}&area_id=${source.id}&section=${section}&selected=${selected}`,page.url()).href);await page.locator('#request-operations,#project-operations').first().waitFor();};
    await go('requests',request.id);
    const update=page.locator('#request-public-update-form');await update.locator('[name=message]').fill('Synthetic public progress <img src=x onerror=alert(1)>');await update.locator('[name=confirmed_public]').check();
    await closeWorkDetail(page);
    await page.locator('[data-section=projects]').click();await page.locator('[data-section=requests]').click();
    await page.locator(`#request-list [data-open="${request.id}"]`).click();
    assert.equal(await update.locator('[name=message]').inputValue(),'Synthetic public progress <img src=x onerror=alert(1)>');
    let droppedPublic=false;const publicBodies=[];
    await page.route('**/enterprise/requester/*/updates?*',async route=>{publicBodies.push(route.request().postDataJSON());if(!droppedPublic){droppedPublic=true;await route.fetch();return route.abort();}return route.continue();});
    await update.getByRole('button',{name:'Publish in-app request update',exact:true}).click();await update.getByRole('button',{name:'Retry exact public update',exact:true}).waitFor();
    await page.reload();await update.getByRole('button',{name:'Retry exact public update',exact:true}).click();await update.getByText('The existing action was recovered without creating a duplicate.',{exact:true}).waitFor();
    assert.deepEqual(publicBodies[0],publicBodies[1]);await page.unroute('**/enterprise/requester/*/updates?*');
    const handoff=page.locator('#request-handoff-form');if(!await handoff.evaluate(form=>form.parentElement.open))await page.getByText('Propose a department handoff',{exact:true}).click();
    await handoff.locator('[name=target_area_id]').selectOption(destination.id);await handoff.locator('[name=target_project_id] option').filter({hasText:'Synthetic destination project'}).waitFor({state:'attached'});await handoff.locator('[name=target_project_id]').selectOption(targetProject.id);await handoff.locator('[name=assignee_id]').selectOption(owner);
    await handoff.locator('[name=next_action]').fill('Synthetic shared action only; no internal notes copied.');await handoff.locator('[name=confirmed]').check();
    let droppedHandoff=false;const handoffBodies=[];
    await page.route('**/enterprise/operations/commands?*',async route=>{if(route.request().postDataJSON()?.action!=='handoff.propose')return route.continue();handoffBodies.push(route.request().postDataJSON());if(!droppedHandoff){droppedHandoff=true;await route.fetch();return route.abort();}return route.continue();});
    await handoff.getByRole('button',{name:'Propose reviewed handoff',exact:true}).click();await handoff.getByRole('button',{name:'Retry exact handoff proposal',exact:true}).waitFor();
    await page.reload();await handoff.waitFor({state:'attached'});if(!await handoff.evaluate(form=>form.parentElement.open))await page.getByText('Propose a department handoff',{exact:true}).click();await handoff.getByRole('button',{name:'Retry exact handoff proposal',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('#request-handoff-form [type=submit]')?.textContent.includes('Retry exact'));
    assert.deepEqual(handoffBodies[0],handoffBodies[1]);await page.unroute('**/enterprise/operations/commands?*');
    const target=(await api('/enterprise/operations?area_id='+destination.id+'&project_id='+targetProject.id)).body;
    assert.equal(target.pending_handoffs.length,1);assert.equal(target.items.length,0,'Proposal must not create a destination task before explicit acceptance');
    assert.equal(target.pending_handoffs[0].next_action,'Synthetic shared action only; no internal notes copied.');
    await go('projects',project.id);const card=page.locator(`[data-ops-task="${task.id}"]`);await card.getByText('Configure ownership & checklist',{exact:true}).click();await card.locator('[name=next_action]').fill('Unsent retained synthetic configuration');
    await closeWorkDetail(page);
    await page.locator('[data-section=requests]').click();await page.locator('[data-section=projects]').click();
    await page.locator(`#request-list [data-open="${project.id}"]`).click();await card.getByText('Configure ownership & checklist',{exact:true}).click();assert.equal(await card.locator('[name=next_action]').inputValue(),'Unsent retained synthetic configuration');
    await page.locator('#discussion-body').fill('Unsent project-only note');
    await card.getByRole('button',{name:'Notes & evidence for '+task.title,exact:true}).click();await page.getByRole('heading',{name:'Task notes & evidence',exact:true}).waitFor();
    assert.equal(await page.locator('#discussion').count(),1,'Only one discussion target may be mounted');assert.equal(await page.locator('#discussion-body').inputValue(),'','Project draft must not leak into task discussion');
    await page.locator('#discussion-body').fill('Synthetic task-only evidence');await page.locator('#note-submit').click();await page.locator('#discussion-feed').getByText('Synthetic task-only evidence',{exact:true}).waitFor();
    await page.locator('#discussion-files-picker').setInputFiles({name:'synthetic-caption.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic caption fixture. No actual campus evidence.')});
    const caption=page.getByRole('form',{name:'Caption for synthetic-caption.txt',exact:true});await caption.waitFor();await caption.locator('[name=caption]').fill('Synthetic searchable caption <img src=x onerror=alert(1)>');
    let droppedCaption=false;const captionBodies=[];
    await page.route('**/enterprise/files/*/caption?*',async route=>{if(route.request().method()!=='POST')return route.continue();captionBodies.push(route.request().postDataJSON());if(!droppedCaption){droppedCaption=true;await route.fetch();return route.abort();}return route.continue();});
    await caption.getByRole('button',{name:'Save caption',exact:true}).click();await caption.getByRole('button',{name:'Retry exact caption',exact:true}).waitFor();await page.reload();await card.getByRole('button',{name:'Notes & evidence for '+task.title,exact:true}).click();await caption.getByRole('button',{name:'Retry exact caption',exact:true}).click();await caption.getByText('Existing caption recovered without repeating the save.',{exact:true}).waitFor();
    assert.deepEqual(captionBodies[0],captionBodies[1]);assert.equal(await page.locator('#discussion img[src=x]').count(),0);await page.unroute('**/enterprise/files/*/caption?*');
    for(const [label,width,height]of[['desktop',1440,1000],['ipad',820,1180],['phone',390,844]]){await page.setViewportSize({width,height});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),label+' task evidence overflow');await page.screenshot({path:resolve(evidence,'task-evidence-'+label+'.png'),fullPage:true});}
    await page.setViewportSize({width:1440,height:1000});
}
