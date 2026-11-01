/** Actual isolated managed runtime + authenticated browser + HTTP-faked OpenAI. Never a paid/live test. */
import assert from 'node:assert/strict';
import {openEnterpriseAction} from '../../scripts/everbranch-team/enterprise-mobile-navigation-browser.mjs';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,readFile,writeFile,rm,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {once} from 'node:events';
import {createServer as createHttpsServer,request as httpsRequest} from 'node:https';
import {request as httpRequest} from 'node:http';
import {pathToFileURL} from 'node:url';
const {chromium,webkit}=await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE||'node_modules/playwright/index.mjs')).href);
const php=process.env.ENTERPRISE_TEST_PHP||'php',evidence=resolve(process.env.ENTERPRISE_EVIDENCE||'test-results/enterprise/conversation');
await mkdir(evidence,{recursive:true});
// Explicit navigation readiness and closed failure metadata only. No transcript or receipt values are exported.
async function waitForConversationSection(page,expected) {
  await page.waitForURL(url=>url.pathname==='/enterprise-campus/teams'&&url.searchParams.get('section')===expected);
  await page.waitForFunction(expected=>{
    const nav=document.querySelector('[data-section="'+expected+'"]'),panel=document.getElementById(expected==='ask'?'ask':'work');
    return nav?.classList.contains('active')===true&&panel?.hidden===false;
  },expected);
}
async function conversationFailureState(page) {
  if(!page)return {state:'page_unavailable'};
  try{return await page.evaluate(()=>{
    const section=new URL(location.href).searchParams.get('section'),status=document.getElementById('conversation-status')?.textContent||'';
    const known=[['restore','A short conversation may be available.'],['restored','Restored from the current authorized short conversation.'],['unconfirmed','This turn is unconfirmed'],['waiting','Waiting for'],['stopped','Stopped waiting.'],['denied','This conversation cannot be restored']];
    let receiptPresent=false,receiptShapeValid=false;
    try{for(let i=0;i<Math.min(sessionStorage.length,128);i++){const key=sessionStorage.key(i);if(!key?.startsWith('everbranch-conversation-v1:'))continue;receiptPresent=true;const text=sessionStorage.getItem(key);if(typeof text==='string'&&text.length<=1024){const value=JSON.parse(text),uuid=/^[a-f0-9-]{36}$/i;receiptShapeValid=uuid.test(value?.session_id)&&uuid.test(value?.request_id);}break;}}catch{}
    const savedStatus=document.getElementById('saved-assistant-status')?.textContent||'',savedForm=document.getElementById('saved-assistant-form');
    const savedKnown=[['saved','Private assistant saved.'],['deleted','Assistant configuration deleted.'],['unconfirmed','The outcome is unconfirmed.'],['conflict','Close configuration, refresh saved assistants'],['unavailable','Saved assistants are unavailable under current access.'],['review','Review this private configuration before saving.']];
    const savedAssistant={status_category:savedKnown.find(([,prefix])=>savedStatus.startsWith(prefix))?.[0]||'other',container_hidden:document.getElementById('saved-assistants')?.hidden!==false,form_hidden:savedForm?.hidden!==false,save_disabled:document.getElementById('saved-assistant-save')?.disabled!==false,confirmed:document.getElementById('saved-assistant-confirm')?.checked===true,exact_retry_label:document.getElementById('saved-assistant-save')?.textContent==='Retry this exact configuration',invalid_field_count:[...(savedForm?.querySelectorAll('input,textarea,select')||[])].filter(node=>node.willValidate&&!node.validity.valid).length};
    return {state:'captured',saved_assistant:savedAssistant,section:['ask','requests'].includes(section)?section:'other',active_sections:[...document.querySelectorAll('[data-section].active')].map(node=>node.dataset.section).filter(value=>['ask','requests'].includes(value)),status_category:known.find(([,prefix])=>status.startsWith(prefix))?.[0]||'other',ask_hidden:document.getElementById('ask')?.hidden!==false,controls_hidden:document.getElementById('conversation-controls')?.hidden!==false,send_disabled:document.getElementById('ask-submit')?.disabled!==false,transcript_empty:document.getElementById('chat-log')?.textContent==='',receipt_present:receiptPresent,receipt_shape_valid:receiptShapeValid};
  });}catch{return {state:'snapshot_unavailable'};}
}
async function boundedConversationDiagnostics(report) {
  let timer;
  try{await Promise.race([Promise.resolve().then(report).catch(()=>{}),new Promise(resolve=>{timer=setTimeout(resolve,2000);})]);}
  finally{clearTimeout(timer);}
}
// Fixed loopback saved-configuration metadata only; never retain URLs or bodies.
function recordSavedAssistantHttp(target,phase,kind,request,status=null) {
  try {
    if(!['setup','first_assistant_save','second_assistant_save','remaining_scenarios'].includes(phase)||!['request','response','request_failed'].includes(kind))return;
    const url=new URL(request.url()),method=request.method();
    if(url.origin!=='https://127.0.0.1:8893'||url.username||url.password||url.pathname!=='/enterprise-campus/enterprise/conversation/assistants'||!['GET','POST'].includes(method))return;
    const event={phase,kind,operation:method==='POST'?'save':'list',status:Number.isInteger(status)&&status>=100&&status<=599?status:null};
    if(target.events.length<24)target.events.push(event);else target.dropped++;
  } catch {}
}
const records=[];
for(const [engine,launcher] of Object.entries({chromium,webkit})){
  const scratch=await mkdtemp(join(tmpdir(),'enterprise-conversation-browser-')),db=join(scratch,'fixture.sqlite'),origin='https://127.0.0.1:8893';
  await writeFile(db,'');
  let env={...process.env,ENTERPRISE_APP_ENV:'testing',ENTERPRISE_RUNTIME_ISOLATED:'true',ENTERPRISE_APP_KEY:'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',ENTERPRISE_APP_URL:origin,ENTERPRISE_TEST_CONVERSATION_PROVIDER:'1',ENTERPRISE_TEST_CONVERSATION_DATABASE:db};
  let server,proxy,browser,runtime,exited,diagnosticPage,diagnosticErrors,diagnosticRequests,diagnosticCalls,scenarioFailed=false;
  let diagnosticPhase='setup';const diagnosticSavedHttp={events:[],dropped:0};
  try{
    const seeded=spawnSync(php,['tests/Support/enterprise-conversation-browser-fixture.php'],{env,encoding:'utf8'});assert.equal(seeded.status,0,seeded.stdout+seeded.stderr);
    assert.ok(seeded.stdout.trim().startsWith('{'),seeded.stdout+seeded.stderr);const fixture=JSON.parse(seeded.stdout);runtime=fixture.runtime;env={...env,ENTERPRISE_RUNTIME_ROOT:runtime,ENTERPRISE_TEST_CONVERSATION_DATABASE:fixture.database};
    const statePath=join(runtime,'conversation-browser.json'),callsPath=join(runtime,'provider-receipts.jsonl');
    const state=()=>readFile(statePath,'utf8').then(JSON.parse),change=async update=>{const value=await state();update(value);await writeFile(statePath,JSON.stringify(value));};
    const calls=async()=>{try{return(await readFile(callsPath,'utf8')).trim().split('\n').filter(Boolean).map(JSON.parse);}catch{return[];}};
    const keyPath=join(scratch,'one-use.key'),certPath=join(scratch,'one-use.crt');
    const tls=spawnSync(process.env.ENTERPRISE_TEST_OPENSSL||'openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyPath,'-out',certPath,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],{encoding:'utf8'});assert.equal(tls.status,0,tls.stderr);
    const key=await readFile(keyPath),cert=await readFile(certPath);await rm(keyPath);await rm(certPath);
    server=spawn(php,['-S','127.0.0.1:8892','-t','apps/enterprise/public','tests/Support/enterprise-conversation-browser-router.php'],{env,stdio:['ignore','ignore','pipe']});exited=once(server,'exit');let serverErrors='';server.stderr.on('data',chunk=>serverErrors+=chunk);
    proxy=createHttpsServer({key,cert},(req,res)=>{const upstream=httpRequest({hostname:'127.0.0.1',port:8892,path:req.url,method:req.method,headers:req.headers},response=>{res.writeHead(response.statusCode,response.headers);response.pipe(res);});upstream.on('error',()=>{if(!res.headersSent)res.writeHead(503);res.end();});req.pipe(upstream);});proxy.listen(8893,'127.0.0.1');await once(proxy,'listening');
    let ready=false,lastStatus,lastBody;for(let i=0;i<40&&!ready;i++){ready=await new Promise(done=>{const req=httpsRequest(origin+'/enterprise-campus/login',{ca:cert},res=>{lastStatus=res.statusCode;lastBody='';res.on('data',chunk=>lastBody+=chunk);res.on('end',()=>done(res.statusCode===200));});req.on('error',()=>done(false));req.end();});if(!ready)await new Promise(done=>setTimeout(done,100));}assert.ok(ready,JSON.stringify({lastStatus,lastBody,runtime})+' '+serverErrors);
    browser=await launcher.launch({headless:true});const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}}),external=[],errors=[],requests=[];
    await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external.push(route.request().url()),route.abort()));
    const page=await context.newPage();diagnosticPage=page;diagnosticErrors=errors;diagnosticRequests=requests;diagnosticCalls=calls;page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.method()==='POST'&&request.url().includes('/conversation/turns'))requests.push(JSON.parse(request.postData()));});
    await page.goto(origin+'/enterprise-campus/login');await page.getByLabel('Email',{exact:true}).fill('conversation@example.invalid');await page.getByLabel('Password',{exact:true}).fill('Fictional-conversation-browser-only!');
    const login=page.waitForResponse(response=>response.request().method()==='POST'&&response.url().endsWith('/login'));await page.getByRole('button',{name:'Sign in',exact:true}).click();assert.equal((await login).status(),200);
    page.on('request',request=>recordSavedAssistantHttp(diagnosticSavedHttp,diagnosticPhase,'request',request));
    page.on('response',response=>recordSavedAssistantHttp(diagnosticSavedHttp,diagnosticPhase,'response',response.request(),response.status()));
    page.on('requestfailed',request=>recordSavedAssistantHttp(diagnosticSavedHttp,diagnosticPhase,'request_failed',request));
    const askUrl=origin+'/enterprise-campus/teams?tenant='+fixture.tenant+'&area_id='+fixture.area+'&section=ask';
    await change(value=>value.config['enterprise.conversation'].enabled=false);
    await page.goto(askUrl);await page.locator('#conversation-controls').waitFor({state:'visible'});assert.equal(await page.locator('#ask-submit').isDisabled(),true);assert.match(await page.locator('#conversation-status').textContent(),/setup is unavailable/);assert.equal((await calls()).length,0);
    // Saving private configuration works independently of provider activation and never calls it.
    await page.locator('#saved-assistant-new').click();await page.locator('#saved-assistant-name').fill('Fictional repair reader');await page.locator('#saved-assistant-purpose').fill('Review current authorized repairs');await page.locator('#saved-assistant-instructions').fill('Use short sentences and identify missing facts.');
    for(const value of ['building','costs'])await page.locator('[data-saved-assistant-check][value="'+value+'"]').check();
    assert.equal(await page.locator('#saved-assistant-save').isDisabled(),true);await page.locator('#saved-assistant-confirm').check();diagnosticPhase='first_assistant_save';await page.locator('#saved-assistant-save').click();await page.getByText('Private assistant saved.',{exact:false}).waitFor();assert.equal((await calls()).length,0);
    const assistantId=await page.locator('#saved-assistant-choice').inputValue();assert.match(assistantId,/^[a-f0-9-]{36}$/i);
    await page.locator('#saved-assistant-edit').click();assert.equal(await page.locator('#saved-assistant-instructions').inputValue(),'Use short sentences and identify missing facts.');await page.locator('#saved-assistant-instructions').fill('Explain briefly and show current linked sources.');await page.locator('#saved-assistant-confirm').check();diagnosticPhase='second_assistant_save';await page.locator('#saved-assistant-save').click();await page.getByText('Private assistant saved.',{exact:false}).waitFor();
    diagnosticPhase='remaining_scenarios';
    // Typed memory is explicit and private; these actions never activate or call a model.
    assert.equal(await page.locator('#memory-use').isChecked(),false);await page.locator('#memory-manager summary').click();
    await page.locator('#memory-new').click();assert.equal(await page.locator('#memory-save').isDisabled(),true);await page.locator('#memory-key').selectOption('reply_length');await page.locator('#memory-value').selectOption('brief');await page.locator('#memory-retention').selectOption('7');await page.locator('#memory-confirm').check();await page.locator('#memory-save').click();await page.getByText('Private typed memory saved.',{exact:false}).waitFor();assert.equal(await page.locator('#memory-refresh').evaluate(node=>document.activeElement===node),true);
    await page.locator('#read-only-submit').click();await page.getByText('Deterministic record check complete.',{exact:false}).waitFor();await page.locator('#read-only-answer summary').click();await page.locator('#read-only-answer').getByRole('button',{name:'Save observation',exact:true}).first().click();
    await page.getByText('Source version 1.',{exact:false}).waitFor();assert.equal(await page.locator('#memory-field').evaluate(node=>document.activeElement===node),true);await page.locator('#memory-retention').selectOption('1');await page.locator('#memory-confirm').check();await page.locator('#memory-save').click();await page.getByText('Private typed memory saved.',{exact:false}).waitFor();assert.equal(await page.locator('#memory-list article').count(),2);
    await page.getByRole('button',{name:'Edit preference',exact:true}).click();await page.locator('#memory-value').selectOption('standard');await page.locator('#memory-confirm').check();await page.locator('#memory-save').click();await page.getByText('Private typed memory saved.',{exact:false}).waitFor();assert.equal((await calls()).length,0);
    await change(value=>value.config['enterprise.conversation'].enabled=true);await page.reload();await page.locator('#saved-assistant-choice').selectOption(assistantId);await page.locator('#ask-submit:not([disabled])').waitFor();assert.equal(await page.locator('#memory-list article').count(),2);assert.equal(await page.locator('#memory-use').isChecked(),false);await page.locator('#memory-use').check();
    assert.equal(await page.locator('#ask-voice-pane').isVisible(),false,'Conversation mode leaves legacy voice off');
    const question='What current work needs attention?';await page.locator('#ask-question').fill(question);await page.locator('#ask-submit').focus();await page.keyboard.press('Enter');await page.getByText('Simulated OpenAI reply · local testing · no paid API call',{exact:true}).waitFor();
    assert.equal(requests.length,1);assert.equal((await calls()).length,1);const first=requests[0];assert.equal(first.assistant_id,assistantId);assert.equal(first.use_memory,true);assert.equal(first.intent,'overview');assert.equal(first.area_id,fixture.area);assert.match(first.session_id,/^[a-f0-9-]{36}$/i);assert.match(first.request_id,/^[a-f0-9-]{36}$/i);await page.getByText('Inspect 2 typed memory entries used',{exact:true}).waitFor();
    const storage=await page.evaluate(()=>Object.entries(sessionStorage).filter(([key])=>key.startsWith('everbranch-conversation-v1:')));assert.equal(storage.length,1);assert.deepEqual(Object.keys(JSON.parse(storage[0][1])).sort(),['request_id','session_id']);assert.ok(!JSON.stringify(storage).includes(question));
    await page.locator('#chat-log summary').filter({hasText:/current source records$/}).click();const source=page.getByRole('link',{name:'View source: Synthetic conversation project',exact:true});assert.equal(await source.count(),1);
    const sourceResponse=await context.request.get(await source.getAttribute('href'));assert.equal(sourceResponse.status(),200);assert.match(await sourceResponse.text(),/Synthetic conversation project/);
    await page.reload();await page.getByText('A short conversation may be available.',{exact:false}).waitFor();assert.equal(await page.locator('#ask-submit').isDisabled(),true);assert.equal((await calls()).length,1);
    await page.locator('#conversation-check').click();await page.getByText('Restored from the current authorized short conversation.',{exact:false}).waitFor();assert.equal((await calls()).length,1);assert.equal(await page.locator('#saved-assistant-choice').inputValue(),assistantId);assert.equal(await page.locator('#ask-submit').isDisabled(),false);
    // A read-only action refusal never discards the existing short session receipt.
    await page.locator('#ask-question').fill('Please send an email');await page.locator('#ask-submit').click();await page.getByText('Read-only policy · no model request',{exact:true}).waitFor();assert.equal((await calls()).length,1);assert.equal(await page.evaluate(()=>JSON.parse(Object.entries(sessionStorage).find(([key])=>key.startsWith('everbranch-conversation-v1:'))[1]).session_id),first.session_id);
    // Hold only the browser response after the real route and fake provider completed.
    let release,held,heldComplete;const holding=new Promise(done=>held=done),released=new Promise(done=>release=done),completed=new Promise(done=>heldComplete=done);
    const hold=async route=>{const response=await route.fetch();held();await released;await route.fulfill({response});heldComplete();};await page.route('**/conversation/turns?*',hold);
    await page.locator('#ask-question').fill('And what about that same project?');await page.locator('#ask-submit').click();await holding;assert.equal((await calls()).length,2);
    await page.locator('#conversation-stop').focus();await page.keyboard.press('Enter');assert.equal(await page.locator('#ask-submit').isDisabled(),true);assert.equal(await page.locator('#conversation-check').evaluate(node=>document.activeElement===node),true);release();await completed;await page.unroute('**/conversation/turns?*',hold);
    await page.locator('#conversation-check').click();await page.getByText('Restored from the current authorized short conversation.',{exact:false}).waitFor();assert.equal((await calls()).length,2);assert.equal(requests.length,3);assert.equal(requests[2].session_id,first.session_id);assert.notEqual(requests[2].request_id,first.request_id);
    await page.getByRole('button',{name:'Requests',exact:true}).click();await waitForConversationSection(page,'requests');await page.waitForLoadState('networkidle');await page.locator('#ask-nav').click();await waitForConversationSection(page,'ask');await page.getByText('A short conversation may be available.',{exact:false}).waitFor();assert.equal(await page.locator('#chat-log').textContent(),'');assert.equal((await calls()).length,2);
    await page.locator('#conversation-clear').click();assert.equal(await page.locator('#conversation-clear-yes').evaluate(node=>document.activeElement===node),true);await page.locator('#conversation-clear-no').click();assert.equal(await page.locator('#conversation-clear').evaluate(node=>document.activeElement===node),true);
    await page.locator('#conversation-clear').click();await page.locator('#conversation-clear-yes').click();await page.getByText('Conversation content cleared. Existing reservations and metering evidence remain.',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>Object.keys(sessionStorage).filter(key=>key.startsWith('everbranch-conversation-v1:')).length),0);assert.equal((await calls()).length,2);
    assert.equal(await page.locator('#memory-use').isChecked(),false,'A new conversation needs explicit memory opt-in');
    for(const [name,viewport] of [['desktop',{width:1440,height:1000}],['ipad',{width:820,height:1180}],['mobile',{width:390,height:844}],['reflow',{width:720,height:450}]]){
      await page.setViewportSize(viewport);await page.locator('#conversation-intent').selectOption('building');await page.locator('#conversation-building').selectOption('40000000-0000-4000-8000-000000000001');
      const geometry=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,controls:[...document.querySelectorAll('#conversation-controls button,#conversation-controls select,#ask-submit')].filter(node=>node.checkVisibility()).map(node=>({id:node.id,height:node.getBoundingClientRect().height,left:node.getBoundingClientRect().left,right:node.getBoundingClientRect().right}))}));assert.ok(geometry.scroll<=geometry.width+1,JSON.stringify(geometry));assert.ok(geometry.controls.every(control=>control.height>=44&&control.left>=0&&control.right<=geometry.width+1),JSON.stringify(geometry));
      await page.locator('#saved-assistant-choice').selectOption(assistantId);await page.locator('#saved-assistant-edit').click();
      assert.equal(await page.locator('#ask-submit').isDisabled(),true,'Editing a reader blocks Send until its configuration is closed');
      const editor=await page.locator('#saved-assistant-form').evaluate(node=>({width:innerWidth,scroll:document.documentElement.scrollWidth,controls:[...node.querySelectorAll('input:not([type=checkbox]),textarea,button,label.saved-assistant-confirm,fieldset label')].filter(value=>value.checkVisibility()).map(value=>({height:value.getBoundingClientRect().height,left:value.getBoundingClientRect().left,right:value.getBoundingClientRect().right}))}));
      assert.ok(editor.scroll<=editor.width+1&&editor.controls.every(value=>value.height>=44&&value.left>=0&&value.right<=editor.width+1),JSON.stringify(editor));
      await page.locator('#saved-assistant-form').scrollIntoViewIfNeeded();await page.screenshot({path:resolve(evidence,engine+'-'+name+'-saved-assistant.png'),fullPage:true});await page.locator('#saved-assistant-cancel').click();
      if(!await page.locator('#memory-manager').evaluate(node=>node.open))await page.locator('#memory-manager summary').click();await page.getByRole('button',{name:'Edit preference',exact:true}).click();assert.equal(await page.locator('#ask-submit').isDisabled(),true);
      const memoryGeometry=await page.locator('#memory-form').evaluate(node=>({width:innerWidth,scroll:document.documentElement.scrollWidth,controls:[...node.querySelectorAll('select,button,label.memory-check')].filter(value=>value.checkVisibility()).map(value=>({height:value.getBoundingClientRect().height,left:value.getBoundingClientRect().left,right:value.getBoundingClientRect().right}))}));assert.ok(memoryGeometry.scroll<=memoryGeometry.width+1&&memoryGeometry.controls.every(value=>value.height>=44&&value.left>=0&&value.right<=memoryGeometry.width+1),JSON.stringify(memoryGeometry));
      await page.locator('#memory-form').scrollIntoViewIfNeeded();await page.screenshot({path:resolve(evidence,engine+'-'+name+'-private-memory.png'),fullPage:true});await page.locator('#memory-cancel').click();
      const tour=await page.evaluate(async({fixture})=>{const data=await(await fetch('/enterprise-campus/enterprise/data?tenant='+fixture.tenant+'&area_id='+fixture.area)).json();const tour=EverbranchWorkspaceTour.create({runtime:'private',enabled:true,sample_data:true,identity:{viewer:fixture.user_id,workspace:fixture.tenant_id}});tour.update({transport:{mode:'private'},area:fixture.area,data});return tour.describe(6,{});},{fixture});assert.equal(tour.target,'ask-form');assert.match(tour.text,/Send to OpenAI/);assert.match(JSON.stringify(tour.cards),/Stop waiting/);assert.match(JSON.stringify(tour.cards),/Choose what to remember/);assert.ok(tour.targets.includes('memory-use'));
      // Real shared tour modal uses the new text copy/target and only opens the screen.
      const beforeTour=(await calls()).length;
      await openEnterpriseAction(page,'Tour',page.locator('[data-tour-open]'));await page.locator('button[data-tour-scene="6"]').click();
      assert.match(await page.locator('[data-tour-title]').textContent(),/Ask about current work/);
      assert.match(await page.locator('[data-tour-text]').textContent(),/Send to OpenAI/);
      assert.match(await page.locator('button[data-tour-scene="6"]').textContent(),/Ask Everbranch · text/);
      const tourBox=await page.locator('[data-tour-dialog]').evaluate(node=>({left:node.getBoundingClientRect().left,right:node.getBoundingClientRect().right,width:innerWidth,scroll:node.scrollWidth,client:node.clientWidth}));
      assert.ok(tourBox.left>=0&&tourBox.right<=tourBox.width+1&&tourBox.scroll<=tourBox.client+1,JSON.stringify(tourBox));
      await page.screenshot({path:resolve(evidence,engine+'-'+name+'-tour.png'),fullPage:true});
      await page.locator('[data-tour-screen]').click();await page.locator('[data-tour-dialog]').waitFor({state:'hidden'});
      assert.equal((await calls()).length,beforeTour,'Tour navigation never sends a question');
      await page.locator('#conversation-controls').scrollIntoViewIfNeeded();await page.screenshot({path:resolve(evidence,engine+'-'+name+'.png'),fullPage:true});records.push({engine,name,geometry,tour:{target:tour.target,text:tour.text},verified:'actual managed runtime; fake HTTP only'});
      await page.locator('#conversation-intent').selectOption('overview');
    }
    await page.setViewportSize({width:1440,height:1000});await page.locator('#conversation-intent').selectOption('building');await page.getByRole('button',{name:'Edit preference',exact:true}).click();
    for(const [name,forcedColors,colorScheme] of [['normal-light','none','light'],['normal-dark','none','dark'],['forced-light','active','light'],['forced-dark','active','dark']]){
      await page.emulateMedia({forcedColors,colorScheme});const controls=[];
      for(const id of ['conversation-intent','conversation-building','saved-assistant-choice','memory-key','memory-value','memory-retention']){
        const control=page.locator('#'+id);await control.scrollIntoViewIfNeeded();
        const paint=await control.evaluate(node=>{const style=getComputedStyle(node),caret=()=>{const p=getComputedStyle(node.parentElement,'::after');return{content:p.content,color:p.borderBottomColor,pointerEvents:p.pointerEvents}},before={color:style.color,border:style.borderColor,caret:caret()};node.disabled=true;const disabled={color:getComputedStyle(node).color,border:getComputedStyle(node).borderColor,caret:caret()};node.disabled=false;return{height:node.getBoundingClientRect().height,scheme:style.colorScheme,image:style.backgroundImage,before,disabled,forced:matchMedia('(forced-colors:active)').matches};});
        assert.ok(paint.height>=44);if(!paint.forced){assert.ok(paint.image.startsWith('url('));assert.equal(paint.scheme,'light');}if(paint.forced){assert.equal(paint.image,'none');assert.equal(paint.before.caret.content,'""');assert.equal(paint.before.caret.pointerEvents,'none');assert.notEqual(paint.before.caret.color,paint.disabled.caret.color);assert.notEqual(paint.before.color,paint.disabled.color);assert.notEqual(paint.before.border,paint.disabled.border);}
        const png=await control.screenshot({path:resolve(evidence,engine+'-'+name+'-'+id+'.png')});
        const raster=await page.evaluate(async source=>{const image=new Image();image.src=source;await image.decode();const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;const ctx=canvas.getContext('2d');ctx.drawImage(image,0,0);const pixel=(x,y)=>[...ctx.getImageData(x,y,1,1).data].slice(0,3),bg=pixel(image.width-8,Math.floor(image.height/2));const lum=rgb=>rgb.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0),ratio=rgb=>(Math.max(lum(bg),lum(rgb))+.05)/(Math.min(lum(bg),lum(rgb))+.05);let caret=bg;for(let x=image.width-24;x<image.width-12;x++)for(let y=Math.floor(image.height/2)-4;y<Math.floor(image.height/2)+4;y++){const value=pixel(x,y);if(ratio(value)>ratio(caret))caret=value;}return{background:bg,caret,contrast:ratio(caret)};},'data:image/png;base64,'+png.toString('base64'));
        assert.ok(raster.contrast>=3,JSON.stringify({engine,name,id,raster}));controls.push({id,paint,raster});
      }
      records.push({engine,name,controls,verified:'actual CSS-painted selector; native controls; fake HTTP only'});
    }
    await page.emulateMedia({forcedColors:'none',colorScheme:'light'});
    await page.locator('#memory-cancel').click();
    await page.locator('#conversation-intent').selectOption('costs');await page.locator('#ask-question').fill('Show the recorded costs and estimates');await page.locator('#ask-submit').click();
    await page.getByText('Checked estimate: USD 10.00. Recorded cost: USD 15.00. Difference: USD 5.00.',{exact:true}).waitFor();
    await page.getByText('Inspect calculation inputs',{exact:true}).click();const inputs=page.locator('#chat-log details').filter({has:page.getByText('Inspect calculation inputs',{exact:true})}).locator('a');assert.equal(await inputs.count(),2);
    for(const link of await inputs.all()){const response=await context.request.get(await link.getAttribute('href'));assert.equal(response.status(),200);assert.match(await response.text(),/Synthetic/);}
    assert.equal((await calls()).length,3);await page.locator('#conversation-clear').click();await page.locator('#conversation-clear-yes').click();await page.getByText('Conversation content cleared. Existing reservations and metering evidence remain.',{exact:true}).waitFor();
    await page.locator('#conversation-intent').selectOption('building');await page.locator('#conversation-building').selectOption('40000000-0000-4000-8000-000000000001');await page.locator('#ask-question').fill('What is happening in this saved building?');await page.locator('#ask-submit').click();await page.getByText('Simulated OpenAI reply · local testing · no paid API call',{exact:true}).waitFor();assert.equal((await calls()).length,4);
    assert.equal(requests.at(-1).building_id,'40000000-0000-4000-8000-000000000001');await page.locator('#conversation-clear').click();await page.locator('#conversation-clear-yes').click();await page.getByText('Conversation content cleared. Existing reservations and metering evidence remain.',{exact:true}).waitFor();await page.locator('#conversation-intent').selectOption('overview');
    await page.locator('#saved-assistant-choice').selectOption(assistantId);await page.locator('#saved-assistant-edit').click();await page.locator('#saved-assistant-delete').click();await page.locator('#saved-assistant-delete-no').click();assert.equal(await page.locator('#saved-assistant-delete').evaluate(node=>document.activeElement===node),true);
    await page.locator('#saved-assistant-delete').click();await page.locator('#saved-assistant-delete-yes').click();await page.getByText('Assistant configuration deleted.',{exact:false}).waitFor();assert.equal(await page.locator('#saved-assistant-choice option').count(),1);assert.equal((await calls()).length,4);
    const preference=page.locator('#memory-list article').filter({has:page.getByRole('button',{name:'Edit preference',exact:true})});await preference.getByRole('button',{name:'Delete memory',exact:true}).click();await page.locator('#memory-delete-no').click();assert.equal(await page.locator('#memory-refresh').evaluate(node=>document.activeElement===node),true);await preference.getByRole('button',{name:'Delete memory',exact:true}).click();await page.locator('#memory-delete-yes').click();await page.getByText('Private memory value deleted.',{exact:false}).waitFor();assert.equal(await page.locator('#memory-list article').count(),1);assert.equal((await calls()).length,4);await page.reload();await page.locator('#private-memory').waitFor({state:'visible'});await page.waitForLoadState('networkidle');assert.equal(await page.locator('#memory-list article').count(),1);assert.equal(await page.locator('#memory-use').isChecked(),false);
    // A provider-ambiguous result holds funds and pauses. Repeated GET checks cannot redispatch.
    await change(value=>value.fake_mode='timeout');await page.locator('#ask-question').fill('What work is next?');await page.locator('#ask-submit').click();await page.getByText('This turn is unconfirmed or unavailable.',{exact:false}).waitFor();assert.equal((await calls()).length,5);
    await page.locator('#conversation-check').click();await page.getByText('This turn is pending or uncertain.',{exact:false}).waitFor();assert.equal((await calls()).length,5);assert.equal(await page.locator('#ask-submit').isDisabled(),true);
    await page.reload();await page.locator('#conversation-check').click();await page.getByText('This turn is pending or uncertain.',{exact:false}).waitFor();assert.equal((await calls()).length,5);
    assert.deepEqual(errors,[]);assert.deepEqual(external,[]);await context.close();
    await writeFile(resolve(evidence,engine+'-requests.json'),JSON.stringify({provider:'fake HTTP only',turnRequests:requests,providerReceipts:await calls(),externalRequests:external,errors},null,2));
  }catch(error){
    scenarioFailed=true;
    await boundedConversationDiagnostics(async()=>{const state=await conversationFailureState(diagnosticPage),observed=diagnosticCalls?await diagnosticCalls():null;await writeFile(resolve(evidence,engine+'-failure.json'),JSON.stringify({engine,phase:diagnosticPhase,state,saved_assistant_http:diagnosticSavedHttp,turn_request_count:diagnosticRequests?.length??null,provider_receipts_observed:observed?.length??null,page_error_count:diagnosticErrors?.length??null},null,2));});
    throw error;
  }finally{
    try{
    if(browser)await browser.close();if(proxy)await new Promise(done=>proxy.close(done));if(server){server.kill();await exited;}
    if(runtime){try{await writeFile(resolve(evidence,engine+'-server.log'),await readFile(join(runtime,'storage/logs/enterprise.log')));}catch{}}
    if(runtime?.startsWith(join(tmpdir(),'enterprise-conversation-test-')))await rm(runtime,{recursive:true,force:true});await rm(scratch,{recursive:true,force:true});
    }catch(cleanupError){if(!scenarioFailed)throw cleanupError;console.error('Conversation fixture cleanup also failed; original scenario failure is retained.');}
  }
}
await writeFile(resolve(evidence,'results.json'),JSON.stringify(records,null,2));console.log(JSON.stringify({cases:records.length,engines:['chromium','webkit'],actualRuntime:true,provider:'fake HTTP only',activation:false,evidence}));
