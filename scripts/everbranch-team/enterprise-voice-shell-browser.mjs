import assert from 'node:assert/strict';
import {resolve} from 'node:path';

/** Real shell/transport records; speech devices alone are simulated. Never calls a provider. */
export async function checkVoiceShell(page,evidence,{sample=false}={}) {
  const tenant='synthetic-enterprise';
  const api=async(path,body)=>page.evaluate(async({path,body,tenant})=>{
    const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
    return {status:response.status,viewer:response.headers.get('X-Team-Viewer'),data:await response.json()};
  },{path,body,tenant});
  let area,title,jobId,beforeTask;
  if(sample){area='10000000-0000-4000-8000-000000000001';title='event audio job';}
  else{
    const command=async body=>{const row=await api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,...body});assert.equal(row.status,200,JSON.stringify(row.data));return row;};
    const created=await command({action:'workspace.create',title:'Synthetic voice department',visibility:'department_only'});area=created.data.id;
    const project=(await command({action:'project.create',area_id:area,title:'Synthetic voice project'})).data.id;
    title='Synthetic classroom sound job';
    let request=(await command({action:'request.create',area_id:area,title,description:'Fictional faculty classroom issue, not a college record.',location:'Fictional classroom, placement unverified'})).data;
    request=(await command({action:'request.triage',id:request.id,area_id:area,expected_version:request.version,note:'Synthetic triage'})).data;
    request=(await command({action:'request.assign',id:request.id,area_id:area,expected_version:request.version,project_id:project,assignee_id:created.viewer})).data;jobId=request.task.id;
    const configured=await api('/enterprise/operations/commands',{action:'task.configure',task_id:jobId,request_id:crypto.randomUUID(),expected_version:0,expected_task_version:request.task.version,owner_user_id:created.viewer,ball_user_id:created.viewer,ball_team:false,next_action:'Inspect the fictional sound fixture',blockers:['Fictional access check'],checklist:[{id:'inspect',label:'Inspect synthetic fixture',weight:1}]});assert.equal(configured.status,200,JSON.stringify(configured.data));
  }
  if(!sample){const row=await api('/enterprise/operations/tasks/'+jobId);assert.equal(row.status,200);beforeTask=row.data;}
  await page.addInitScript(()=>{
    window.shellCaptures=[];window.shellSpeeches=[];
    window.SpeechRecognition=class{constructor(){shellCaptures.push(this)}start(){this.onstart?.()}abort(){this.onend?.()}result(text){const row=[{transcript:text}];row.isFinal=true;this.onresult?.({results:[row]});this.onend?.()}};
    window.SpeechSynthesisUtterance=class{constructor(text){this.text=text}};
    Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{cancel(){},speak(value){shellSpeeches.push(value)}}});
  });
  const target=new URL(page.url());if(sample)target.hash=`area_id=${area}&section=ask`;else{target.pathname='/';target.search=`?tenant=${tenant}&area_id=${area}&section=ask`;}
  await page.goto(target.href);if(sample)await page.reload();await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>shellCaptures.length),0,'Opening Ask never starts a microphone');
  await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).click();
  await page.evaluate(question=>shellCaptures.at(-1).result(question),'Show '+title);
  await page.locator('#ask-job-detail h3').waitFor();
  assert.match(await page.locator('#ask-job-detail').innerText(),/No recorded coordinates|Recorded position/);
  assert.equal(await page.locator('#ask-voice-controls').getByRole('button',{name:'Interrupt reply',exact:true}).isEnabled(),true);
  assert.equal(await page.evaluate(()=>shellSpeeches.length),1);
  await page.locator('#ask-voice-controls').getByRole('button',{name:'Stop',exact:true}).click();
  await page.locator('#ask-question').fill('Where is it?');await page.locator('#ask-submit').click();
  await page.locator('#ask-submit').waitFor({state:'visible'});await page.waitForFunction(()=>!document.getElementById('ask-submit').disabled);
  assert.equal(await page.locator('#chat-log .chat-turn:not(.question)').count(),2);
  await page.locator('#ask-question').fill('Unfindablefictionalword');await page.locator('#ask-submit').click();await page.waitForFunction(()=>!document.getElementById('ask-submit').disabled);
  assert.equal(await page.locator('#ask-job-detail h3').count(),0,'No match clears the previous job and message preview context');assert.equal(await page.locator('#ask-message-preview-actions').isHidden(),true);
  await page.locator('#ask-question').fill('Show '+title);await page.locator('#ask-submit').click();await page.locator('#ask-job-detail h3').waitFor();
  for(const kind of ['email','text']){
    await page.locator(`[data-ask-preview=${kind}]`).click();await page.locator('#ask-message-preview[open]').waitFor();
    assert.match(await page.locator('#ask-message-preview').innerText(),/not sent/);
    assert.match(await page.locator('#ask-message-preview-body').innerText(),/Recipient|Source:/);
    await page.keyboard.press('Escape');await page.waitForFunction(kind=>document.querySelector(`[data-ask-preview=${kind}]`)===document.activeElement,kind);
  }
  for(const [label,width,height]of [['desktop',1440,1000],['phone',390,844],['ipad-portrait',820,1180],['ipad-landscape',1180,820]]){
    await page.setViewportSize({width,height});await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await page.locator('#ask-question').evaluate(field=>field.scrollIntoView({block:'center',behavior:'instant'}));await page.locator('#ask-question').focus();
    const composer=await page.locator('#ask-question').evaluate(field=>{const box=field.getBoundingClientRect();return {top:box.top,bottom:box.bottom,width:box.width,height:box.height,viewport:innerHeight,display:getComputedStyle(field).display};});
    assert.equal(composer.width>0&&composer.height>=44&&composer.top>=0&&composer.bottom<=composer.viewport,true,`Usable composer at ${label}: ${JSON.stringify(composer)}`);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,`Voice overflow at ${label}`);
    await page.screenshot({path:resolve(evidence,`voice-${sample?'sample':'private'}-${label}.png`),fullPage:true});
  }
  // Reduced visual area exercises scroll/composer layout, not a real device keyboard.
  await page.setViewportSize({width:390,height:450});await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await page.locator('#ask-question').evaluate(field=>field.scrollIntoView({block:'center',behavior:'instant'}));await page.locator('#ask-question').focus();
  assert.equal(await page.evaluate(()=>{const box=document.getElementById('ask-question').getBoundingClientRect();return box.top>=0&&box.bottom<=innerHeight;}),true,'Composer fits a reduced phone viewport');
  await page.setViewportSize({width:1180,height:820});
  await page.locator('#ask-job-detail').getByRole('link',{name:'Open recorded request / map',exact:true}).click();
  await page.locator('#map').waitFor({state:'visible'});await page.locator('#detail h2').waitFor();
  const backToAsk=sample?null:page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/enterprise/data'));
  await page.goBack();if(backToAsk)await backToAsk;await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).waitFor();
  assert.equal(await page.locator('#ask-job-detail h3').count(),0,'Returning from a source requires a fresh lookup');
  // Actual navigation must stop capture and clear record context; going Back never reuses the old answer as evidence.
  await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).click();
  await page.locator('[data-section=projects]').click();assert.equal(await page.locator('#ask-job-detail h3').count(),0);
  const secondBack=sample?null:page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/enterprise/data'));
  await page.goBack();if(secondBack)await secondBack;assert.equal(new URLSearchParams(sample?new URL(page.url()).hash.slice(1):new URL(page.url()).search).get('section'),'ask','Back restores the Ask route');await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).waitFor();
  assert.equal(await page.locator('#ask-job-detail h3').count(),0);
  if(!sample){
    let release;const held=new Promise(resolve=>{release=resolve;});let started;const began=new Promise(resolve=>{started=resolve;});
    await page.route('**/enterprise/voice/jobs?*',async route=>{const response=await route.fetch();started();await held;await route.fulfill({response}).catch(()=>{});});
    await page.locator('#ask-question').fill('Show '+title);await page.locator('#ask-submit').click();let timer;try{await Promise.race([began,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('The held voice lookup did not begin within 30 seconds')),30000);})]);}finally{clearTimeout(timer);}
    await page.locator('[data-section=projects]').click();release();await page.waitForFunction(()=>!document.getElementById('ask-submit').disabled);
    assert.equal(await page.locator('#ask-job-detail h3').count(),0,'A late authorized lookup cannot reopen a departed Ask scope');await page.unroute('**/enterprise/voice/jobs?*');
    await page.locator('[data-section=ask]').click();
    await page.route('**/enterprise/voice/jobs?*',route=>route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({message:'Synthetic access revoked'})}));
    await page.locator('#ask-question').fill('Show '+title);await page.locator('#ask-submit').click();await page.locator('#status').filter({hasText:'Synthetic access revoked'}).waitFor();
    assert.equal(await page.locator('#ask-job-detail h3').count(),0);assert.equal(await page.locator('#chat-log .chat-turn').count(),0);assert.equal(await page.locator('#ask-message-preview-actions').isHidden(),true);await page.unroute('**/enterprise/voice/jobs?*');
  }
  await page.locator('#usage-nav').click();await page.locator('#enterprise-usage-summary').waitFor();
  assert.match(await page.locator('#enterprise-usage-overview').innerText(),sample?/FICTIONAL|Fictional/:/ledger|Ledger/);
  const departmentCards=await page.locator('.eusage-department').count();assert.ok(departmentCards>0);
  const firstDepartment=await page.locator('.eusage-department').first().getAttribute('data-department-id');
  await page.locator('.eusage-department').first().getByRole('button',{name:/^View usage for /}).click();await page.locator('#enterprise-usage-summary').waitFor();
  assert.equal(await page.locator('.eusage-department').count(),1);assert.equal(await page.locator('.eusage-department').getAttribute('data-department-id'),firstDepartment);assert.equal(await page.locator('#usage-all').isVisible(),true);
  await page.locator('#usage-all').click();await page.locator('#enterprise-usage-summary').waitFor();assert.equal(await page.locator('.eusage-department').count(),departmentCards);
  await page.locator('#usage-refresh').click();await page.locator('#enterprise-usage-summary').waitFor();
  for(const [label,width,height]of [['desktop',1440,1000],['phone',390,844],['ipad-portrait',820,1180],['ipad-landscape',1180,820]]){
    await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,`Usage overflow at ${label}`);
    await page.screenshot({path:resolve(evidence,`usage-${sample?'sample':'private'}-${label}.png`),fullPage:true});
  }
  await page.setViewportSize({width:1440,height:1000});
  let unexpectedModelCalls=0;const modelRequest=request=>{if(new URL(request.url()).pathname.endsWith('/enterprise/ask'))unexpectedModelCalls++;};page.on('request',modelRequest);
  await page.evaluate(()=>{window.savedShellVoice=window.EverbranchVoiceJobs;window.EverbranchVoiceJobs=undefined;});
  await page.locator('[data-section=ask]').click();await page.locator('#ask-voice-controls').getByText(/No model request is substituted/).waitFor();assert.equal(await page.locator('#ask-submit').isDisabled(),true);
  await page.evaluate(()=>document.getElementById('ask-form').dispatchEvent(new Event('submit',{cancelable:true,bubbles:true})));
  await page.locator('#status').filter({hasText:'No model request was made'}).waitFor();assert.equal(unexpectedModelCalls,0);
  await page.evaluate(()=>{window.EverbranchVoiceJobs=window.savedShellVoice;delete window.savedShellVoice;});page.off('request',modelRequest);
  await page.locator('[data-section=projects]').click();await page.locator('[data-section=ask]').click();await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).waitFor();
  if(!sample){const row=await api('/enterprise/operations/tasks/'+jobId);assert.equal(row.status,200);assert.deepEqual(row.data,beforeTask,'Voice and previews do not mutate the job');}
}
