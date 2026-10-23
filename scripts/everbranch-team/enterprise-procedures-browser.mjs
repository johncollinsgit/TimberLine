import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';

/** Disposable real-HTTP procedures fixtures; no provider, insurance or college records. */
export async function checkProceduresWorkspace(page,evidence) {
    const independentFlags=process.env.ENTERPRISE_PROCEDURES_INDEPENDENT==='1',oldFeatureRequests=[];
    if(independentFlags)page.on('request',request=>{const path=new URL(request.url()).pathname;if(/^\/enterprise\/(operations|roll-call)(\/|$)/.test(path))oldFeatureRequests.push(path);});
    const tenant='synthetic-enterprise';
    const area='90000000-0000-4000-8000-000000000001';
    const contractor='90000000-0000-4000-8000-000000000004';
    const call=async(target,path,body,scope=tenant)=>target.evaluate(async({path,body,scope})=>{
        const url=new URL(path,location.origin);url.searchParams.set('tenant',scope);
        const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
        return {status:response.status,body:await response.json()};
    },{path,body,scope});
    const api=async(path,body)=>{const result=await call(page,path,body);assert.equal(result.status,200,JSON.stringify(result.body));return result.body;};
    const command=async body=>api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,...body});
    const registry=await api('/enterprise/certificates?area_id='+area);
    assert.ok(registry.contractors.some(row=>row.id===contractor));
    const addDays=(date,days)=>{const d=new Date(date+'T12:00:00Z');d.setUTCDate(d.getUTCDate()+days);return d.toISOString().slice(0,10);};
    const oldExpiry=addDays(registry.as_of_on,-1),futureExpiry=addDays(registry.as_of_on,180);
    const project=await command({action:'project.create',area_id:area,title:'=Synthetic procedures project'});
    let request=await command({action:'request.create',area_id:area,title:'Synthetic procedures request',description:'PROCEDURES_PRIVATE_DESCRIPTION_MUST_NOT_EXPORT'});
    request=await command({action:'request.triage',id:request.id,area_id:area,expected_version:request.version,note:'PROCEDURES_PRIVATE_TRIAGE_MUST_NOT_EXPORT'});
    const native=(await api('/enterprise/data?area_id='+area));
    assert.equal(native.features.certificates,true);assert.equal(native.features.reports,true);
    if(independentFlags){assert.equal(native.features.operations,false);assert.equal(native.features.roll_call,false);}
    request=await command({action:'request.assign',id:request.id,area_id:area,expected_version:request.version,project_id:project.id,assignee_id:native.viewer_id});
    await page.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=people`,page.url()).href);
    await page.locator('#certificate-new').click();
    assert.equal(await page.locator('[data-section="people"]').isVisible(),true,'Certificate managers need reachable People navigation');
    await page.locator('#certificate-contractor').selectOption(contractor);
    await page.locator('#certificate-title').fill('Synthetic restricted certificate <img src=x onerror=alert(1)>');
    await page.locator('#certificate-document').fill('SYNTHETIC_PRIVATE_CERTIFICATE_REFERENCE');
    await page.locator('#certificate-expiry').fill(oldExpiry);
    await page.locator('#certificate-save').click();
    await page.getByRole('form',{name:'Review exact certificate replacement',exact:true}).waitFor();
    let certificate=(await api('/enterprise/certificates?area_id='+area)).items[0];
    assert.equal(certificate.accepted,null);assert.equal(certificate.pending.expires_on,oldExpiry);
    const review=page.getByRole('form',{name:'Review exact certificate replacement',exact:true});
    await review.locator('[name=decision]').selectOption('accept');
    await review.locator('[name=reason]').fill('Synthetic explicit procedural review; no coverage determination.');
    await review.locator('[name=confirmed]').check();
    await review.getByRole('button',{name:'Confirm replacement review',exact:true}).click();
    await page.getByRole('form',{name:'Propose certificate replacement',exact:true}).waitFor();
    certificate=await api('/enterprise/certificates/'+certificate.id);
    assert.equal(certificate.accepted.expires_on,oldExpiry);assert.equal(certificate.expiry_state,'expired');
    assert.ok(certificate.reminder_reasons.includes('warning_not_configured'));
    const proposal=page.getByRole('form',{name:'Propose certificate replacement',exact:true});
    await proposal.locator('[name=document_reference]').fill('SYNTHETIC_PRIVATE_FUTURE_REFERENCE');
    await proposal.locator('[name=expires_on]').fill(futureExpiry);
    await proposal.getByRole('button',{name:'Save replacement proposal',exact:true}).click();
    await page.getByRole('form',{name:'Review exact certificate replacement',exact:true}).waitFor();
    certificate=await api('/enterprise/certificates/'+certificate.id);
    assert.equal(certificate.accepted.expires_on,oldExpiry);assert.equal(certificate.pending.expires_on,futureExpiry);
    assert.equal(certificate.expiry_state,'expired');
    assert.ok((await api('/enterprise/certificates/reminders?area_id='+area)).items.some(row=>row.id===certificate.id&&row.expiry_state==='expired'));
    await page.getByRole('button',{name:'Edit certificate metadata',exact:true}).click();
    await page.locator('#certificate-contact').fill('Synthetic internal renewal contact');
    await page.locator('#certificate-warning').fill('30');
    await page.locator('#certificate-review-date').fill(addDays(registry.as_of_on,7));
    const metadataBodies=[];let droppedMetadata=false;
    await page.route('**/enterprise/certificates/commands?*',async route=>{
        const body=route.request().postDataJSON();
        if(body.action!=='certificate.metadata')return route.continue();
        metadataBodies.push(body);
        if(!droppedMetadata){droppedMetadata=true;await route.fetch();return route.abort();}
        return route.continue();
    });
    await page.locator('#certificate-save').click();
    await page.waitForFunction(()=>{const button=document.querySelector('#certificate-retry');return button&&!button.hidden&&!button.disabled&&document.querySelector('#certificate-status').textContent.includes('unconfirmed');});
    await page.reload();await page.locator('#certificate-retained').waitFor({state:'visible'});
    assert.equal(await page.locator('#certificate-new').isDisabled(),true,'Unconfirmed retained action must lock edits after reload');
    await page.setViewportSize({width:390,height:844});
    await page.locator('#certificate-retained summary').tap();
    assert.deepEqual(JSON.parse(await page.locator('#certificate-retained-body').textContent()),metadataBodies[0]);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Retained exact action must fit phone width');
    await page.screenshot({path:resolve(evidence,'procedures-retained-action-phone.png'),fullPage:true});
    await page.locator('#certificate-retry').tap();
    await page.locator('#certificate-status').getByText('Existing certificate action recovered without repeating the change.',{exact:true}).waitFor();
    assert.deepEqual(metadataBodies[1],metadataBodies[0]);await page.unroute('**/enterprise/certificates/commands?*');
    certificate=await api('/enterprise/certificates/'+certificate.id);
    assert.equal(certificate.warning_lead_days,30);assert.equal(certificate.version,4);
    assert.equal(certificate.accepted.expires_on,oldExpiry);assert.equal(certificate.pending.expires_on,futureExpiry);
    assert.ok(!certificate.reminder_reasons.includes('warning_not_configured'));
    assert.equal((await api('/enterprise/certificates/'+certificate.id+'/history')).items.length,4);
    assert.equal(await page.locator('#certificate-workspace img[src=x]').count(),0);
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('#certificate-list [data-certificate="'+certificate.id+'"]').click();
    await page.locator('#certificate-history').getByText('Version 4',{exact:true}).waitFor();
    const layouts=[];
    const devices=async(panel,prefix)=>{
        for(const [name,width,height]of[['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]){
            await page.setViewportSize({width,height});
            await page.locator(panel).scrollIntoViewIfNeeded();
            if(name.startsWith('ipad')||name==='phone'){
                if(prefix==='procedures-certificates'){
                    await page.locator('#certificate-list [data-certificate="'+certificate.id+'"]').tap();
                    await page.locator('#certificate-history').getByText('Version 4',{exact:true}).waitFor();
                }else{
                    const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/enterprise/reports/quarterly');
                    await page.locator('#quarterly-preview').tap();assert.equal((await response).status(),200);
                    await page.locator('#quarterly-json').waitFor({state:'visible'});
                }
            }
            const layout=await page.locator(panel).evaluate(element=>({overflow:document.documentElement.scrollWidth>innerWidth+1,width:innerWidth,scroll_width:document.documentElement.scrollWidth,offenders:[...document.querySelectorAll('body *')].filter(e=>e.getClientRects().length&&e.getBoundingClientRect().right>innerWidth+1).slice(0,20).map(e=>({tag:e.tagName,id:e.id,class:e.className,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width})),buttons:[...element.querySelectorAll('button,a.button')].filter(e=>e.getClientRects().length).map(e=>({text:e.textContent.trim(),height:e.getBoundingClientRect().height}))}));
            assert.equal(layout.overflow,false,prefix+' '+name+' horizontal overflow '+JSON.stringify(layout));
            assert.ok(layout.buttons.every(row=>row.height>=43.5),JSON.stringify(layout.buttons));
            layouts.push({panel:prefix,name,width,height,...layout});
            await page.screenshot({path:resolve(evidence,`${prefix}-${name}.png`),fullPage:true});
        }
    };
    await devices('#certificate-workspace','procedures-certificates');
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('[data-section="roll_call"]').click();
    await page.locator('#quarterly-period').waitFor({state:'visible'});
    const now=new Date(registry.as_of_on+'T12:00:00Z'),year=now.getUTCFullYear(),quarter=Math.floor(now.getUTCMonth()/3)+1;
    await page.locator('#quarterly-year').fill(String(year));
    await page.locator('#quarterly-quarter').selectOption(String(quarter));
    await page.locator('#quarterly-timezone').fill('America/New_York');
    await page.locator('#quarterly-project').selectOption(project.id);
    const preview=async()=>{
        const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/enterprise/reports/quarterly'&&r.request().method()==='GET');
        await page.locator('#quarterly-preview').click();const result=await response;assert.equal(result.status(),200);
        await page.locator('#quarterly-json').waitFor({state:'visible'});return result.json();
    };
    const privateMarkers=['PROCEDURES_PRIVATE_DESCRIPTION_MUST_NOT_EXPORT','PROCEDURES_PRIVATE_TRIAGE_MUST_NOT_EXPORT','SYNTHETIC_PRIVATE_CERTIFICATE_REFERENCE','SYNTHETIC_PRIVATE_FUTURE_REFERENCE'];
    const checkReport=report=>{
        assert.equal(report.schema_version,1);assert.equal(report.report_type,'period_activity');assert.equal(report.sample_data,true);
        assert.match(report.label,/SAMPLE DATA/);assert.ok(Number.isFinite(Date.parse(report.generated_at_utc)));
        assert.deepEqual(report.selection,{area_id:area,project_id:project.id});
        for(const id of[project.id,request.id,request.data.task_id])assert.ok(report.current_records.some(row=>row.id===id),'Missing current record '+id);
        assert.match(report.basis.current_state,/Not a quarter-end snapshot/);assert.equal(report.coverage.partial,true);
        assert.match(report.snapshot_notice,/dated snapshots/);
        for(const row of[...report.current_records,...report.recorded_events]){const link=new URL(row.source_url,page.url());assert.equal(link.origin,new URL(page.url()).origin);assert.equal(link.searchParams.get('tenant'),tenant);}
        for(const marker of privateMarkers)assert.ok(!JSON.stringify(report).includes(marker),'Private marker exported: '+marker);
    };
    const calendar=await preview();checkReport(calendar);
    assert.equal(calendar.window.mode,'calendar_quarter');assert.equal(calendar.window.timezone,'America/New_York');
    assert.ok(calendar.recorded_events.some(row=>row.action==='request.assign'&&row.id===request.id));
    const download=async(format,label)=>{
        const pending=page.waitForEvent('download');await page.locator('#quarterly-'+format).click();const file=await pending;
        assert.equal(file.suggestedFilename(),'everbranch-period-activity.'+format);assert.equal(await file.failure(),null);
        const path=resolve(evidence,`procedures-${label}.${format}`);await file.saveAs(path);const bytes=await readFile(path,'utf8');
        for(const marker of privateMarkers)assert.ok(!bytes.includes(marker),'Private marker downloaded: '+marker);return bytes;
    };
    const csv=await download('csv','calendar-quarter');
    assert.ok(csv.includes("'=Synthetic procedures project"),'CSV formula-leading title must be neutralized');
    assert.ok(csv.includes('SAMPLE DATA'));assert.ok(csv.includes('Not a quarter-end snapshot'));
    const calendarDownloaded=JSON.parse(await download('json','calendar-quarter'));checkReport(calendarDownloaded);
    for(const key of['mode','timezone','start_local','end_local_inclusive','start_utc','end_utc_exclusive'])assert.equal(calendarDownloaded.window[key],calendar.window[key]);
    await page.locator('#quarterly-mode').selectOption('date_range');
    assert.equal(await page.locator('#quarterly-json').isVisible(),false,'Changed selection must invalidate downloads');
    await page.locator('#quarterly-start').fill('2025-11-02');await page.locator('#quarterly-end').fill('2025-11-02');
    const ranged=await preview();checkReport(ranged);
    assert.equal(ranged.window.start_utc,'2025-11-02T04:00:00+00:00');assert.equal(ranged.window.end_utc_exclusive,'2025-11-03T05:00:00+00:00');
    assert.equal(Date.parse(ranged.window.end_utc_exclusive)-Date.parse(ranged.window.start_utc),25*60*60*1000);
    assert.equal(ranged.recorded_events.length,0,'New current records must not fabricate past-period events');
    const rangedDownloaded=JSON.parse(await download('json','dst-date-range'));checkReport(rangedDownloaded);
    assert.deepEqual(rangedDownloaded.window,ranged.window);
    await devices('#quarterly-workspace','procedures-period-report');
    const contexts=[],identityErrors=[],external=[];
    const identity=async(email,workspaceName)=>{
        const context=await page.context().browser().newContext({ignoreHTTPSErrors:true});contexts.push(context);
        let queue=Promise.resolve(),last=0;
        await context.route('**/*',async route=>{const url=new URL(route.request().url());if(url.origin!==new URL(page.url()).origin){external.push(url.origin+url.pathname);return route.abort();}if(!url.pathname.startsWith('/build/'))await(queue=queue.then(async()=>{const delay=Math.max(0,last+800-Date.now());if(delay)await new Promise(resolve=>setTimeout(resolve,delay));last=Date.now();}));return route.continue();});
        const actor=await context.newPage();actor.on('pageerror',error=>identityErrors.push(error.message));
        await actor.goto(new URL('/login',page.url()).href);await actor.getByLabel('Email',{exact:true}).fill(email);await actor.getByLabel('Password',{exact:true}).fill('Synthetic-browser-only-2026!');
        const [signedIn]=await Promise.all([actor.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname==='/login'),actor.getByRole('button',{name:'Sign in',exact:true}).click()]);
        assert.equal(signedIn.status(),200);await actor.getByRole('link',{name:workspaceName,exact:true}).click();return actor;
    };
    const denials=[];
    const denied=async(actor,path,status,body,scope=tenant)=>{const result=await call(actor,path,body,scope);assert.equal(result.status,status,path+' '+JSON.stringify(result));for(const marker of privateMarkers)assert.ok(!JSON.stringify(result.body).includes(marker));denials.push({path:path.split('?')[0],status});};
    try{
        const viewer=await identity('fixture-viewer@example.invalid','Synthetic Enterprise');
        assert.equal((await call(viewer,'/enterprise/data?area_id='+area)).status,200,'Viewer fixture must have actual shared workspace access');
        await viewer.goto(new URL(`/?tenant=${tenant}&area_id=${area}&section=people`,page.url()).href);
        await viewer.locator('#certificate-status').getByText('Certificate register is disabled or unavailable to your current role. No records are loaded.',{exact:true}).waitFor();
        assert.equal(await viewer.locator('#certificate-body').isVisible(),false);
        assert.equal(await viewer.locator('#certificate-retained').isVisible(),false);
        assert.ok(!(await viewer.locator('body').innerText()).includes('SYNTHETIC_PRIVATE_CERTIFICATE_REFERENCE'));
        for(const path of['/enterprise/certificates?area_id='+area,'/enterprise/certificates/reminders?area_id='+area,'/enterprise/certificates/'+certificate.id,'/enterprise/certificates/'+certificate.id+'/history'])await denied(viewer,path,404);
        const reportQuery=new URLSearchParams({area_id:area,mode:'calendar_quarter',year:String(year),quarter:String(quarter),timezone:'America/New_York'});
        for(const suffix of['','.csv','.json'])await denied(viewer,'/enterprise/reports/quarterly'+suffix+'?'+reportQuery,403);
        await denied(viewer,'/enterprise/certificates/commands',404,metadataBodies[0]);
        const foreign=await identity('fixture-foreign-owner@example.invalid','Synthetic Foreign Enterprise');
        const foreignTenant='synthetic-foreign-enterprise',foreignArea='90000000-0000-4000-8000-000000000002',foreignContractor='90000000-0000-4000-8000-000000000003';
        const foreignId=crypto.randomUUID();
        const created=await call(foreign,'/enterprise/certificates/commands',{action:'certificate.propose',id:foreignId,area_id:foreignArea,request_id:crypto.randomUUID(),expected_version:0,contractor_id:foreignContractor,title:'Synthetic foreign private certificate',replacement_id:crypto.randomUUID(),document_reference:'SYNTHETIC_FOREIGN_PRIVATE_REFERENCE',expires_on:futureExpiry},foreignTenant);
        assert.equal(created.status,200,JSON.stringify(created.body));assert.equal((await call(foreign,'/enterprise/certificates/'+foreignId,undefined,foreignTenant)).status,200);
        await denied(foreign,'/enterprise/certificates/'+certificate.id,404,undefined,foreignTenant);
        await denied(foreign,'/enterprise/certificates/'+certificate.id+'/history',404,undefined,foreignTenant);
        await denied(foreign,'/enterprise/certificates?area_id='+area,404,undefined,foreignTenant);
        await denied(foreign,'/enterprise/certificates/commands',404,metadataBodies[0],foreignTenant);
        await denied(page,'/enterprise/certificates/'+foreignId,404);
        await denied(page,'/enterprise/certificates/'+foreignId+'/history',404);
        await denied(page,'/enterprise/reports/quarterly?'+new URLSearchParams({...Object.fromEntries(reportQuery),area_id:foreignArea}),404);
        assert.equal((await api('/enterprise/certificates/'+certificate.id)).version,4);
        assert.equal((await api('/enterprise/certificates/'+certificate.id+'/history')).items.length,4,'Denied replay must not append revisions');
        assert.deepEqual(identityErrors,[]);assert.deepEqual(external,[]);
    }finally{for(const context of contexts)await context.close();}
    await writeFile(resolve(evidence,'procedures-verification.json'),JSON.stringify({independent_flags:independentFlags,disabled_feature_requests:oldFeatureRequests,certificate:{id:certificate.id,version:4,accepted_expiry:oldExpiry,pending_expiry:futureExpiry,remains_expired:true,warning_lead_days:30,history_revisions:4,exact_reload_retry:true},report:{current_quarter:{year,quarter},date_range:ranged.window,basis:ranged.basis,current_record_count:ranged.current_records.length,past_period_event_count:ranged.recorded_events.length,downloads:['calendar-quarter.csv','calendar-quarter.json','dst-date-range.json'],csv_formula_neutralized:true},denials,layouts,physical_ipad_tested:false,external_requests:external,identity_errors:identityErrors},null,2));
    if(independentFlags)assert.deepEqual(oldFeatureRequests,[],'Procedure cards must not call disabled Operations/Roll Call APIs');
    console.log('PASS: real HTTP certificate propose/review, authoritative accepted expiry, exact metadata retry after reload, dated CSV/JSON current-record and period-event exports, DST window, actual viewer/foreign-tenant denials, desktop/phone/iPad viewport layouts.');
}
