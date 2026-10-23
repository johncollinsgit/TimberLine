import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {checkWorkPlacement} from './enterprise-work-placement-browser.mjs';

/** Real isolated Enterprise HTTP and browser persistence. No provider, college or production records. */
export async function checkRollCallWorkspace(page, evidence) {
    const tenant='synthetic-enterprise';
    const api=async(path,body)=>{
        const response=await page.evaluate(async({path,body,tenant})=>{
            const target=new URL(path,location.origin);target.searchParams.set('tenant',tenant);
            const result=await fetch(target,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
            return {status:result.status,viewer:result.headers.get('X-Team-Viewer'),data:await result.json()};
        },{path,body,tenant});
        assert.equal(response.status,200,JSON.stringify(response.data));return response;
    };
    const command=async body=>(await api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,...body})).data;
    const created=await api('/enterprise/commands',{action:'workspace.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),title:'Synthetic Roll Call workspace',visibility:'department_only'});
    const area=created.data.id, owner=created.viewer;
    const project=(await command({action:'project.create',area_id:area,title:'Synthetic Roll Call project'})).id;
    let request=await command({action:'request.create',area_id:area,title:'Synthetic Roll Call source task',description:'Disposable browser fixture only.'});
    request=await command({action:'request.triage',id:request.id,area_id:area,expected_version:request.version,note:'Synthetic triage'});
    await command({action:'request.assign',id:request.id,area_id:area,expected_version:request.version,project_id:project,assignee_id:owner});
    await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=roll_call&scope=project&project_id=${project}`,page.url()).href);
    await page.locator('#roll-projects-heading').waitFor();
    await page.waitForFunction(()=>document.getElementById('roll-projects-body').textContent.includes('Synthetic Roll Call project'));
    assert.equal(await page.locator('#roll-meeting-tools').getAttribute('open'),null,'Meeting paragraphs must not dominate the project browser');
    await page.getByRole('button',{name:'Synthetic Roll Call project',exact:true}).click();
    await page.locator('#work-drawer[open][data-entity-type="project"]').waitFor();
    await checkWorkPlacement(page,evidence,{area,requestId:request.id});
    await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=projects&selected=${project}`,page.url()).href);
    await page.locator('#work-drawer[open][data-entity-type="project"]').waitFor();
    await page.getByLabel('Add a note',{exact:true}).fill('Synthetic project update from the Roll Call project browser.');
    await page.getByRole('button',{name:'Post note',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('#discussion-feed')?.textContent.includes('Synthetic project update from the Roll Call project browser.'));
    await page.locator('#discussion-files-picker').setInputFiles({name:'roll-call-project-synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic project evidence only.')});
    await page.waitForFunction(()=>document.querySelector('#discussion-files')?.textContent.includes('roll-call-project-synthetic.txt'));
    assert.match(await page.locator('#discussion-files').innerText(),/quarantined/);
    await page.reload();
    await page.waitForFunction(()=>document.querySelector('#discussion-feed')?.textContent.includes('Synthetic project update from the Roll Call project browser.'));
    assert.match(await page.locator('#discussion-files').innerText(),/roll-call-project-synthetic.txt/);
    assert.equal(await page.locator('#discussion-files').getByRole('link',{name:'Download',exact:true}).count(),0,'Quarantine must not be bypassed in the larger dialog');
    const dialog=await page.locator('#work-drawer').boundingBox(),viewport=page.viewportSize();
    assert.ok(Math.abs(dialog.x+dialog.width/2-viewport.width/2)<2,'Project dialog must be centered');
    await page.getByRole('button',{name:'Close',exact:true}).click();
    await page.locator('[data-section="roll_call"]').click();
    await page.waitForFunction(()=>document.getElementById('roll-projects-body').textContent.includes('Synthetic Roll Call project'));
    await page.getByText('Priorities & meeting notes',{exact:true}).click();
    await page.getByLabel('Meeting title',{exact:true}).waitFor();
    const assignedResponse=page.waitForResponse(response=>{const url=new URL(response.url());return url.pathname==='/enterprise/roll-call'&&url.searchParams.get('type')==='team'&&url.searchParams.get('team_user_id')===owner;});
    await page.getByRole('button',{name:'My assigned daily work',exact:true}).click();
    assert.equal((await assignedResponse).status(),200);
    await page.waitForFunction(()=>document.querySelector('#roll-method').textContent.includes('Assigned'));
    await page.waitForFunction(()=>!document.getElementById('roll-save').disabled);
    assert.equal(await page.getByLabel('Person',{exact:true}).inputValue(),owner);
    await page.getByLabel('Meeting scope',{exact:true}).selectOption('project');
    await page.getByLabel('Scope project',{exact:true}).selectOption(project);
    await page.getByRole('button',{name:'Refresh current work',exact:true}).click();
    await page.getByLabel('Meeting title',{exact:true}).waitFor();
    await page.waitForFunction(()=>!document.getElementById('roll-save').disabled);
    await page.getByLabel('Meeting title',{exact:true}).fill('Synthetic reviewed meeting <img src=x onerror=alert(1)>');
    await page.getByRole('button',{name:'Add decision',exact:true}).click();
    await page.getByLabel('Decision 1',{exact:true}).fill('Synthetic retained decision <img src=x onerror=alert(1)>');
    await page.getByRole('button',{name:'Add blocker',exact:true}).click();
    const blocker=page.locator('[data-roll-kind="blockers"]').first();
    await blocker.getByLabel('Blocker 1',{exact:true}).fill('Confirm the illustrative room access.');
    await blocker.getByLabel('Blocker 1 owner',{exact:true}).selectOption(owner);
    await blocker.getByLabel('Blocker 1 due date',{exact:true}).fill('2026-11-04');
    await page.getByRole('button',{name:'Add commitment',exact:true}).click();
    const commitment=page.locator('[data-roll-kind="commitments"]').first();
    await commitment.getByLabel('Commitment 1',{exact:true}).fill('Perform the synthetic reviewed entrance inspection');
    await commitment.getByLabel('Commitment 1 owner',{exact:true}).selectOption(owner);
    await commitment.getByLabel('Commitment 1 due date',{exact:true}).fill('2026-11-05');
    await commitment.getByLabel('Commitment 1 project',{exact:true}).selectOption(project);
    // In-page navigation retains text but returning reauthorizes the scope before redisplay.
    await page.locator('[data-section="projects"]').click();
    await page.locator('[data-section="roll_call"]').click();
    await page.getByLabel('Decision 1',{exact:true}).waitFor();
    assert.equal(await page.getByLabel('Decision 1',{exact:true}).inputValue(),'Synthetic retained decision <img src=x onerror=alert(1)>');
    assert.equal(await page.locator('#roll-call-panel img[src="x"]').count(),0,'Meeting text must not become HTML');
    let droppedSave=false;const saveBodies=[];
    await page.route('**/enterprise/meetings?*',async route=>{
        if(route.request().method()!=='POST')return route.continue();
        saveBodies.push(route.request().postDataJSON());
        if(!droppedSave){droppedSave=true;await route.fetch();return route.abort();}
        return route.continue();
    });
    await page.getByRole('button',{name:'Save meeting notes',exact:true}).click();
    await page.getByRole('button',{name:'Retry the same request',exact:true}).waitFor();
    await page.reload();
    await page.getByRole('button',{name:'Retry the same request',exact:true}).click();
    await page.getByRole('button',{name:'Review task changes',exact:true}).waitFor({state:'visible'});
    await page.waitForFunction(()=>!document.getElementById('roll-preview').disabled);
    assert.deepEqual(saveBodies[1],saveBodies[0],'A lost meeting-save response must retain the exact body and request key');
    assert.equal((await api(`/enterprise/operations?area_id=${area}&project_id=${project}`)).data.items.length,1,'Saving notes cannot create tasks');
    await page.getByRole('button',{name:'Review task changes',exact:true}).click();
    await page.getByRole('region',{name:'Exact task change preview',exact:true}).waitFor();
    assert.match(await page.locator('#roll-preview-actions').innerText(),/Perform the synthetic reviewed entrance inspection/);
    assert.equal((await api(`/enterprise/operations?area_id=${area}&project_id=${project}`)).data.items.length,1,'Preview cannot create tasks');
    assert.equal(await page.getByRole('button',{name:'Confirm these task changes',exact:true}).isDisabled(),true);
    let droppedConfirm=false;const confirmBodies=[];
    await page.route('**/enterprise/meetings/*/confirm?*',async route=>{
        confirmBodies.push(route.request().postDataJSON());
        if(!droppedConfirm){droppedConfirm=true;await route.fetch();return route.abort();}
        return route.continue();
    });
    await page.getByRole('checkbox',{name:'I reviewed the owners, projects, dates and exact changes shown above.',exact:true}).check();
    await page.getByRole('button',{name:'Confirm these task changes',exact:true}).click();
    await page.getByRole('button',{name:'Retry the same request',exact:true}).waitFor();
    await page.reload();
    await page.getByRole('button',{name:'Retry the same request',exact:true}).click();
    await page.waitForFunction(()=>document.getElementById('roll-status').textContent.includes('No duplicate tasks'));
    assert.deepEqual(confirmBodies[1],confirmBodies[0],'A lost confirmation response must keep its exact preview/digest/version and request key');
    assert.equal((await api(`/enterprise/operations?area_id=${area}&project_id=${project}`)).data.items.length,2,'Confirmation replay cannot duplicate a native task');
    await page.getByRole('button',{name:'Add decision',exact:true}).click();
    await page.getByLabel('Decision 2',{exact:true}).fill('Additional notes preserve the already confirmed commitment.');
    await page.getByRole('button',{name:'Save meeting notes',exact:true}).click();
    await page.waitForFunction(()=>document.getElementById('roll-status').textContent.includes('Meeting notes saved.'));
    assert.equal((await api(`/enterprise/operations?area_id=${area}&project_id=${project}`)).data.items.length,2,'Editing notes after confirmation must preserve immutable commitments without new tasks');
    const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('button',{name:'Download fresh one-page PDF',exact:true}).click()]);
    const pdfPath=resolve(evidence,'enterprise-roll-call-current-meeting.pdf');
    try { await download.saveAs(pdfPath); }
    catch (error) {
        // Inspect the authorized endpoint without weakening browser TLS/download protections.
        const response=await page.context().request.get(download.url());
        const bytes=await response.body();
        await writeFile(resolve(evidence,'enterprise-roll-call-pdf-endpoint.bin'),bytes);
        throw new Error(`PDF browser download failed: ${await download.failure()}; url=${download.url()}; authorized endpoint status=${response.status()} type=${response.headers()['content-type']} bytes=${bytes.length} pdf=${bytes.toString().startsWith('%PDF-1.4')}`,{cause:error});
    }
    const pdf=await readFile(pdfPath);assert.ok(pdf.toString().startsWith('%PDF-1.4'));assert.equal((pdf.toString().match(/\/Type \/Page\b/g)||[]).length,1);
    assert.match(pdf.toString(),/SAMPLE DATA/);
    const reportVisible=await page.locator('#quarterly-period').isVisible();
    if(reportVisible)assert.equal(await page.locator('#quarterly-project').inputValue(),'','The combined view must cover the initial all-projects report filter');
    for(const [name,width,height] of [['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]){
        await page.setViewportSize({width,height});
        for(const reportMode of reportVisible?['calendar_quarter','date_range']:[null]) {
        if(reportMode)await page.locator('#quarterly-mode').selectOption(reportMode);
        const artifact=name+(reportMode==='date_range'?'-date-range':'');
        const layout=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,scrolling:[...document.querySelectorAll('body *')].filter(element=>{const r=element.getBoundingClientRect();return r.width>0&&r.height>0&&element.scrollWidth>element.clientWidth+1;}).map(element=>({tag:element.tagName,id:element.id,class:element.className,type:element.getAttribute('type'),scrollWidth:element.scrollWidth,clientWidth:element.clientWidth,overflowX:getComputedStyle(element).overflowX})),offenders:[...document.querySelectorAll('body *')].filter(element=>{const r=element.getBoundingClientRect();return r.width>0&&r.height>0&&(r.right>innerWidth+1||r.left< -1);}).map(element=>{const r=element.getBoundingClientRect(),s=getComputedStyle(element);return {tag:element.tagName,id:element.id,class:element.className,type:element.getAttribute('type'),left:r.left,right:r.right,width:r.width,minWidth:s.minWidth,maxWidth:s.maxWidth,display:s.display,text:element.textContent?.trim().slice(0,100)};})}));
        await writeFile(resolve(evidence,`enterprise-roll-call-${artifact}-layout.json`),JSON.stringify(layout,null,2));
        await page.screenshot({path:resolve(evidence,`enterprise-roll-call-${artifact}.png`),fullPage:true});
        assert.equal(layout.scrollWidth<=layout.width+1,true,`${artifact}: meeting UI overflows horizontally; ${JSON.stringify({offenders:layout.offenders,scrolling:layout.scrolling})}`);
        assert.equal(await page.locator('#roll-save').evaluate(element=>element.getBoundingClientRect().height>=44),true,`${name}: save touch target too small`);
        assert.equal(await page.locator('#roll-scope').evaluate(element=>element.getBoundingClientRect().height>=44),true,`${name}: scope touch target too small`);
        if(reportVisible)assert.equal(await page.locator('#quarterly-project').evaluate(element=>element.getBoundingClientRect().height>=44),true,`${artifact}: project filter touch target too small`);
        }
    }
    if(reportVisible)await page.locator('#quarterly-mode').selectOption('calendar_quarter');
    await page.setViewportSize({width:1440,height:1000});
    await page.getByLabel('Meeting scope',{exact:true}).selectOption('campus');
    await page.getByRole('button',{name:'Refresh current work',exact:true}).click();
    await page.waitForFunction(()=>document.getElementById('roll-status').textContent.includes('priorities in the checked scope'));
    await page.getByLabel('Meeting scope',{exact:true}).selectOption('team');
    await page.getByLabel('Person',{exact:true}).selectOption(owner);
    await page.getByRole('button',{name:'Refresh current work',exact:true}).click();
    await page.waitForFunction(()=>document.getElementById('roll-status').textContent.includes('priorities in the checked scope'));
    assert.match(await page.locator('#roll-method').innerText(),/Current record-derived/);
    console.log('PASS Roll Call UI: authorized scopes, retained form drafts, exact lost save/confirmation recovery, no task before confirmation, one native task after replay, fresh one-page PDF, literal hostile text, desktop/phone/iPad viewport and touch checks.');
}
