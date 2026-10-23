import assert from 'node:assert/strict';
import {resolve} from 'node:path';
import {closeWorkDetail} from './enterprise-work-detail-browser.mjs';

/** Disposable authenticated fixture only: real HTTP persistence, zero provider calls. */
export async function checkMappedOperationsWorkflow(page, evidence) {
    const within = async promise => {
        let timer;
        try {return await Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Delayed navigation fixture did not settle within 30 seconds')),30000);})]);}
        finally {clearTimeout(timer);}
    };
    page.on('dialog', async dialog => {assert.equal(dialog.type(), 'beforeunload'); await dialog.accept();});
    const tenant = 'synthetic-enterprise';
    const api = async (path, body) => {
        const result = await page.evaluate(async ({path, body, tenant}) => {
            const url = new URL(path, location.origin); url.searchParams.set('tenant', tenant);
            const response = await fetch(url, {credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                ...(body ? {method:'POST',body:JSON.stringify(body)} : {})});
            return {status:response.status, viewer:response.headers.get('X-Team-Viewer'), data:await response.json()};
        }, {path, body, tenant});
        assert.equal(result.status, 200, JSON.stringify(result.data));
        return result;
    };
    const uuid = () => crypto.randomUUID();
    const command = async body => (await api('/enterprise/commands', {id:uuid(), request_id:uuid(), expected_version:0, ...body})).data;
    const created = await api('/enterprise/commands', {action:'workspace.create',id:uuid(),request_id:uuid(),title:'Synthetic mapped Operations',visibility:'department_only'});
    const area = created.data.id, owner = created.viewer;
    const project = (await command({action:'project.create',area_id:area,title:'Synthetic mapped repair project'})).id;
    let request = await command({action:'request.create',area_id:area,title:'Synthetic canonical repair',description:'Disposable mapped workflow fixture; no college incident.'});
    request = await command({action:'request.triage',id:request.id,area_id:area,expected_version:request.version,note:'Synthetic triage'});
    request = await command({action:'request.assign',id:request.id,area_id:area,expected_version:request.version,project_id:project,assignee_id:owner});
    const nativeId = request.task.id;
    await api('/enterprise/operations/commands', {action:'task.configure',request_id:uuid(),task_id:nativeId,expected_version:0,expected_task_version:request.task.version,
        owner_user_id:owner,ball_user_id:owner,ball_team:false,next_action:'Inspect and repair the synthetic fixture',due_on:'2026-11-05',blockers:[],
        checklist:[{id:'inspect',label:'Inspect fixture',weight:1},{id:'repair',label:'Repair fixture',weight:3}]});
    // Navigation away and back must not revive a delayed operation's detached UI context.
    const navigationProject = (await command({action:'project.create',area_id:area,title:'Synthetic pending navigation project'})).id;
    let navigationRequest = await command({action:'request.create',area_id:area,title:'Synthetic delayed navigation task',description:'Disposable retained-operation fixture.'});
    navigationRequest = await command({action:'request.triage',id:navigationRequest.id,area_id:area,expected_version:navigationRequest.version,note:'Synthetic triage'});
    navigationRequest = await command({action:'request.assign',id:navigationRequest.id,area_id:area,expected_version:navigationRequest.version,project_id:navigationProject,assignee_id:owner});
    const navigationTask = navigationRequest.task.id;
    await api('/enterprise/operations/commands', {action:'task.configure',request_id:uuid(),task_id:navigationTask,expected_version:0,expected_task_version:navigationRequest.task.version,
        owner_user_id:owner,ball_user_id:owner,ball_team:false,next_action:'Verify delayed response stays in its original project',blockers:[],
        checklist:[{id:'navigation',label:'Retain the original navigation action',weight:1}]});
    await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=projects&selected=${navigationProject}`,page.url()).href);
    await page.getByRole('checkbox',{name:'Retain the original navigation action',exact:true}).waitFor();
    let releaseNavigation, navigationStarted, navigationEnded;
    const released = new Promise(resolve=>{releaseNavigation=resolve;});
    const started = new Promise(resolve=>{navigationStarted=resolve;});
    const ended = new Promise(resolve=>{navigationEnded=resolve;});
    const navigationBodies = [];
    await page.route('**/enterprise/operations/commands?*',async route=>{
        const body=route.request().postDataJSON();
        if(body.task_id!==navigationTask || body.action!=='task.check')return route.continue();
        navigationBodies.push(body);
        if(navigationBodies.length===1){await route.fetch();navigationStarted();await released;await route.abort();navigationEnded();return;}
        return route.continue();
    });
    await page.getByRole('checkbox',{name:'Retain the original navigation action',exact:true}).click();
    await within(started);
    await closeWorkDetail(page);
    await page.locator(`#request-list [data-open="${project}"]`).click();
    await page.locator('#detail').getByRole('heading',{name:'Synthetic mapped repair project',exact:true}).waitFor();
    await closeWorkDetail(page);
    await page.locator(`#request-list [data-open="${navigationProject}"]`).click();
    await page.locator(`#project-operations [data-ops-task="${navigationTask}"]`).waitFor();
    releaseNavigation();await within(ended);
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>resolve(null))));
    assert.equal(await page.locator('#operations-retry').isVisible(),false,'Returning to a project cannot revive an old response UI context');
    await page.locator(`#project-operations [data-ops-task="${navigationTask}"]`).getByRole('button',{name:'Retry the same recorded action',exact:true}).click();
    await page.waitForFunction(()=>document.getElementById('status').textContent.includes('without repeating'));
    assert.deepEqual(navigationBodies[1],navigationBodies[0],'Returning to the original project must replay its exact retained mutation');
    assert.equal((await api(`/enterprise/operations/tasks/${navigationTask}`)).data.version,2,'A navigation retry cannot apply the checklist twice');
    await page.unroute('**/enterprise/operations/commands?*');
    await page.goto(new URL(`/projects/workspace?tenant=${tenant}&area_id=${area}&project_id=${project}`, page.url()).href);
    await page.getByRole('button',{name:'Use Illustrative maintenance workflow',exact:true}).click();
    await page.getByRole('button',{name:'Accessible list',exact:true}).click();
    await page.getByRole('button',{name:'Complete the assigned work assignment',exact:false}).click();
    await page.getByLabel('Canonical project task',{exact:true}).selectOption(nativeId);
    await page.getByRole('button',{name:'Coordinator approval approval',exact:false}).click();
    await page.getByLabel('Canonical project task',{exact:true}).selectOption(nativeId);
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    await page.getByRole('button',{name:/Settings$/}).click();
    await page.getByRole('button',{name:'Review activation preview',exact:true}).click();
    await page.getByRole('button',{name:'Confirm activation',exact:true}).click();
    await page.locator('.ew-badge').filter({hasText:/^active/}).waitFor();
    await page.getByRole('button',{name:/Runs & history$/}).click();
    await page.getByRole('button',{name:'Start a run',exact:true}).click();
    await page.getByLabel('Work description *',{exact:true}).fill('Synthetic canonical repair <img src=x onerror=alert(1)>');
    await page.getByLabel('Priority *',{exact:true}).selectOption('Urgent');
    await page.getByRole('button',{name:'Submit & continue',exact:true}).click();
    await page.getByRole('region',{name:'Linked native task',exact:true}).waitFor();
    assert.equal(await page.getByRole('button',{name:'Submit & continue',exact:true}).count(),0,'Unperformed native task must not advance');
    let droppedCheck = false;
    const checkBodies = [];
    await page.route('**/enterprise/operations/commands?*', async route => {
        if (route.request().postDataJSON().action === 'task.check') {
            checkBodies.push(route.request().postDataJSON());
            if (!droppedCheck) {droppedCheck=true; await route.fetch(); return route.abort();}
        }
        return route.continue();
    });
    await page.getByRole('checkbox',{name:'Inspect fixture (native task)',exact:true}).click();
    await page.getByRole('button',{name:'Retry exact operation',exact:true}).waitFor();
    await page.reload();
    await page.getByRole('button',{name:'Retry exact operation',exact:true}).click();
    await page.getByRole('checkbox',{name:'Inspect fixture (native task)',exact:true}).waitFor();
    assert.equal(await page.getByRole('checkbox',{name:'Inspect fixture (native task)',exact:true}).isChecked(),true);
    assert.deepEqual(checkBodies[1],checkBodies[0],'Lost native checklist response must retain its exact operation');
    await page.getByRole('checkbox',{name:'Repair fixture (native task)',exact:true}).click();
    await page.getByText('100% performed · 3.9 / 4.0 · awaiting verification',{exact:true}).waitFor();
    await page.getByLabel('Completion note *',{exact:true}).fill('Synthetic work performed; awaiting manager verification');
    for (const [label,width,height] of [['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]) {
        await page.setViewportSize({width,height});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${label} mapped workflow overflow`);
        assert.ok(await page.getByRole('button',{name:'Refresh native task & run',exact:true}).evaluate(el=>el.getBoundingClientRect().height>=44),`${label} mapped workflow touch target`);
        await page.screenshot({path:resolve(evidence,`enterprise-mapped-run-${label}.png`),fullPage:true});
    }
    await page.setViewportSize({width:1440,height:1000});
    await page.getByRole('button',{name:'Submit & continue',exact:true}).click();
    await page.getByRole('heading',{name:'Review urgent work',exact:true}).waitFor();
    assert.equal(await page.locator('.ew-collected img').count(),0,'Prior intake was rendered as executable HTML');
    let droppedApproval = false;
    const approvals = [];
    await page.route('**/enterprise/workflows/*/runs/*/advance?*',async route=>{
        if(route.request().postDataJSON().decision==='approve') {
            approvals.push(route.request().postDataJSON());
            if(!droppedApproval){droppedApproval=true;await route.fetch();return route.abort();}
        }
        return route.continue();
    });
    await page.getByRole('button',{name:'Approve',exact:true}).click();
    await page.getByRole('button',{name:'Retry exact operation',exact:true}).waitFor();
    await page.reload();
    await page.getByRole('button',{name:'Retry exact operation',exact:true}).click();
    await page.locator('.ew-run-layout .ew-badge').filter({hasText:'completed'}).waitFor();
    assert.deepEqual(approvals[1],approvals[0],'Lost approval response must retain its exact reviewed version tuple');
    assert.equal(approvals[0].expected_task_version,2);
    assert.equal(approvals[0].expected_operations_version,3);
    const final = (await api(`/enterprise/operations/tasks/${nativeId}`)).data;
    assert.equal(final.completion_score,4); assert.equal(final.status,'completed'); assert.equal(final.version,4);
    const listing=(await api(`/enterprise/operations?area_id=${area}&project_id=${project}`)).data;
    assert.equal(listing.items.length,1,'Workflow mapping created another task');
    await page.screenshot({path:resolve(evidence,'enterprise-mapped-workflow-approved.png'),fullPage:true});
    console.log('PASS canonical operations workflow: explicit existing task selectors, configured owner/ball/checklist, 3.9 performed and manager-approved native4.0, version-bound confirmation, exact lost checklist/approval recovery, no duplicate task, desktop/phone/iPad viewport checks.');
}
