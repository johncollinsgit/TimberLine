import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {spawn,spawnSync} from 'node:child_process';
import {createServer} from 'node:https';
import {request as httpRequest} from 'node:http';
import {once} from 'node:events';
import {mkdir,writeFile,readFile,rm,chmod} from 'node:fs/promises';
import {resolve,dirname} from 'node:path';
import {pathToFileURL} from 'node:url';

// Real loopback Enterprise kernel, private SQLite/file sessions, two independent browser cookies.
// No API response interception, sender approval, provider/key/AI/runtime action or browser-local work fallback.
const startedAt=Date.now();
const root=resolve('.'),engine=process.env.PROPOSAL_BROWSER_ENGINE||'chromium';
assert.ok(!process.env.PROPOSAL_WORKSPACE_SCENARIO||process.env.PROPOSAL_WORKSPACE_SCENARIO==='downloads','PDF acceptance always runs every case; unsupported scenario input refuses before runtime creation');
assert.ok(['chromium','webkit'].includes(engine));
const php=process.env.ENTERPRISE_TEST_PHP;
assert.ok(php,'ENTERPRISE_TEST_PHP must pin a local PHP executable');
const {chromium,webkit}=await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE||'node_modules/playwright/index.mjs')).href);
const parent=dirname(root),runtime=resolve(parent,`runtime-${Date.now()}`),origin='https://127.0.0.1:9043';
const evidence=resolve(process.env.PROPOSAL_BROWSER_EVIDENCE||`../evidence-${engine}-${Date.now()}`);
await mkdir(runtime,{mode:0o700});
for(const directory of ['storage/framework/testing','storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs','storage/app/private','storage/app/team-private','bootstrap/cache'])await mkdir(resolve(runtime,directory),{recursive:true,mode:0o700});
await chmod(runtime,0o700);await writeFile(resolve(runtime,'.env'),'',{mode:0o600});
await writeFile(resolve(runtime,'storage/framework/testing/enterprise-browser-reviewer-workspace.sqlite'),'',{mode:0o600});
await mkdir(evidence,{recursive:true,mode:0o700});
const env={PATH:process.env.PATH,APP_ENV:'testing',ENTERPRISE_RUNTIME_ROOT:runtime,REVIEWER_WORKSPACE_BROWSER:'1',REVIEWER_WORKSPACE_DOWNLOADS_BROWSER:'1',REVIEWER_WORKSPACE_BROWSER_ORIGIN:origin,REVIEWER_WORKSPACE_BROWSER_SEED:'core'};
const fixture=(...args)=>{const result=spawnSync(php,['tests/Support/proposal-reviewer-workspace-downloads-browser-fixture.php',...args],{env,encoding:'utf8',windowsHide:true,timeout:120000});assert.equal(result.status,0,result.stdout+result.stderr);try{return JSON.parse(result.stdout);}catch{throw Error('Fictional local fixture did not return JSON: '+result.stdout.slice(0,4000));}};
let server,proxy,browser,serverLog='',currentPage,serial=Promise.resolve(),lastDispatch=0;
let deadlineExpired=false;
const deadline=setTimeout(()=>{deadlineExpired=true;void browser?.close();},15*60*1000);deadline.unref();
const external=[],errors=[],results=[],posts=[],blockers=[],responses=[],commentRequests=[],commentResponses=[],fileRequests=[],fileResponses=[];
const record=value=>{results.push(value);console.log(JSON.stringify({progress:value.scenario,status:value.status}));};
try{
 const seed=fixture('seed');assert.equal(seed.seed_projects,20);
 const keyPath=resolve(runtime,'fixture.key'),certPath=resolve(runtime,'fixture.crt');
 const cert=spawnSync(process.env.ENTERPRISE_TEST_OPENSSL||'openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyPath,'-out',certPath,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],{encoding:'utf8'});
 assert.equal(cert.status,0,'Local synthetic certificate generation failed');
 const key=await readFile(keyPath),certificate=await readFile(certPath);await rm(keyPath);await rm(certPath);
 server=spawn(php,['-S','127.0.0.1:9042','tests/Support/proposal-reviewer-workspace-downloads-browser-router.php'],{env,stdio:['ignore','pipe','pipe']});
 for(const stream of [server.stdout,server.stderr])stream.on('data',chunk=>{serverLog+=chunk;});
 proxy=createServer({key,cert:certificate},(req,res)=>{
  serial=serial.then(async()=>{
   await new Promise(done=>setTimeout(done,Math.max(0,lastDispatch+2150-Date.now())));
   if(req.destroyed||res.destroyed)return;lastDispatch=Date.now();
   await new Promise(done=>{let upstream,finished=false;const finish=()=>{if(finished)return;finished=true;req.off('aborted',abort);res.off('close',abort);done();};const abort=()=>{upstream?.destroy();finish();};
    upstream=httpRequest({hostname:'127.0.0.1',port:9042,path:req.url,method:req.method,headers:req.headers},response=>{if(res.destroyed){response.destroy();finish();return;}res.writeHead(response.statusCode,response.headers);response.pipe(res);response.once('end',finish);response.once('close',finish);});
    req.once('aborted',abort);res.once('close',abort);upstream.once('error',()=>{if(!res.destroyed){if(!res.headersSent)res.writeHead(503);res.end('Local fixture unavailable');}finish();});if(req.aborted||res.destroyed)abort();else if(req.readableEnded)upstream.end();else req.pipe(upstream);
   });
  });
 });proxy.listen(9043,'127.0.0.1');await once(proxy,'listening');
 browser=await(engine==='webkit'?webkit:chromium).launch({headless:true});
 const clients=[];
 async function client(){
  const context=await browser.newContext({ignoreHTTPSErrors:true,acceptDownloads:true,viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==origin){external.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
  const page=await context.newPage();currentPage=page;const item={context,page,projection:null};clients.push(item);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{if(request.method()==='POST' && /\/workspace\/(files|images|downloads)\//.test(request.url()))fileRequests.push({url:request.url(),body:request.postDataJSON(),csrf:request.headers()['x-csrf-token']});});
  page.on('response',async response=>{if(/\/workspace\/(files|images|downloads)\//.test(response.url()))fileResponses.push({url:response.url(),status:response.status(),content_type:response.headers()['content-type'],cache:response.headers()['cache-control']});});
  page.on('response',async response=>{if(response.url().endsWith('/collaboration/comment')){try{commentResponses.push({status:response.status(),body:await response.json()});}catch{commentResponses.push({status:response.status(),json:false});}}if(response.request().method()==='POST'&&response.url().endsWith('/workspace/commands')){try{const body=await response.json();responses.push({status:response.status(),keys:Object.keys(body),error:body.error||null,receipt:body.receipt||null,selection:body.projection?.selection||null});}catch{responses.push({status:response.status(),json:false});}}});
  page.on('request',request=>{if(request.method()==='POST'&&new URL(request.url()).pathname.includes('/workspace/')){const body=request.postDataJSON();if(request.url().endsWith('/collaboration/comment'))commentRequests.push({url:request.url(),body,csrf:request.headers()['x-csrf-token']});posts.push({action:new URL(request.url()).pathname.split('/').at(-1),request_id:body?.request_id||null});if(request.url().endsWith('/projection'))item.projection={url:request.url(),body,csrf:request.headers()['x-csrf-token']};}});
  await page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}`);
  await page.getByLabel('Proposal password').fill(seed.password);await page.getByRole('button',{name:'Open proposal',exact:true}).click();
  await page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);
  await page.locator('#create-request').waitFor({state:'visible'});await page.waitForFunction(()=>!document.getElementById('status')?.textContent.includes('Loading'));
  try{assert.equal(await page.getByText('Shared fictional work is saved on the server.',{exact:false}).count(),1);}catch{blockers.push({scenario:'truthful_shared_ribbon',actual:await page.locator('.reviewer-boundary').innerText(),expected:'Shared fictional work is saved on the server.'});}
  assert.equal(await page.locator('#reviewer-reset-open').isVisible(),false);
  assert.ok(item.projection,'Real server projection request must be observed');return item;
 }
 const one=await client(),two=await client();currentPage=one.page;
 assert.notDeepEqual(await one.context.cookies(),await two.context.cookies());
 const before=fixture('inspect');assert.equal(before.ledger_rows,0);assert.equal(before.users,1);assert.equal(before.memberships,1);assert.deepEqual(before.route_locks,{show:[10,10],comment:[10,10],status:[10,10]});
 record({scenario:'two_actual_unlocked_cookie_contexts',status:'passed',seed_projects:20});
 // Actual review-name UI: server session choice, never a native or fictional role grant.
 async function chooseName(item,name){
  await item.page.locator('#review-open').click();
  await item.page.locator('#campus-review-name').selectOption(name);
  await item.page.locator('#review-name-form').getByRole('button',{name:'Remember my display name',exact:true}).click();
  await item.page.locator('#review-status').filter({hasText:'Review display name saved.'}).waitFor();
  await item.page.locator('#review-close').click();
 }
 async function postJson(item,url,body,csrf=item.projection.csrf){
  return item.page.evaluate(async({url,body,csrf})=>{const r=await fetch(url,{method:'POST',credentials:'same-origin',redirect:'error',headers:{Accept:'application/json','Content-Type':'application/json',...(csrf?{'X-CSRF-TOKEN':csrf}:{})},body:JSON.stringify(body)});let out;try{out=await r.json();}catch{out=null;}return{status:r.status,body:out,cache:r.headers.get('Cache-Control'),csp:r.headers.get('Content-Security-Policy')};},{url,body,csrf});
 }
 await chooseName(one,seed.reviewer_names[0]);await chooseName(two,seed.reviewer_names[1]);
 assert.equal(fixture('inspect').comment_receipt_rows,0);
 record({scenario:'actual_independent_server_backed_display_name_choices',status:'passed'});

 const incoming='85000000-0000-4000-8000-000000000002',project='83000000-0000-4000-8000-000000000001';
 const target={kind:'ticket',id:incoming};
 const pdf=Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% FICTIONAL private mounted PDF '+engine+'\ntrailer<</Root 1 0 R>>\n%%EOF\n'),pdfSha=createHash('sha256').update(pdf).digest('hex'),pdfName='FICTIONAL résumé '+engine+'.pdf';
 assert.deepEqual(before.download_route_locks,{metadata:[10,10],download:[10,10]});
 async function openTicket(item){await item.page.bringToFront();await item.page.locator(`#request-list [data-open="${incoming}"]`).click();await item.page.locator('[data-workspace-files]').waitFor({state:'visible'});await item.page.locator('[data-work-files-choose]').waitFor({state:'visible'});}
 await openTicket(one);await openTicket(two);await one.page.bringToFront();
 const draft='FICTIONAL unsent PDF discussion retained '+engine;
 await one.page.locator('#shared-discussion-body').fill(draft);
 assert.match(await one.page.locator('[data-work-files-input]').getAttribute('accept'),/application\/pdf/);
 assert.equal(await one.page.locator('[data-work-files-choose]').innerText(),'Add file');
 async function upload(item,name,mimeType,bytes){
  await item.page.bringToFront();const chooser=item.page.waitForEvent('filechooser');await item.page.locator('[data-work-files-choose]').click();await(await chooser).setFiles({name,mimeType,buffer:bytes});
  await item.page.locator('[data-work-files-upload]').click();await item.page.locator('[data-work-files-list]').getByText(name,{exact:true}).waitFor({state:'visible'});
  await item.page.locator('[data-work-files-list] li').filter({hasText:name}).getByText(' · Held for scanning',{exact:true}).waitFor({state:'visible'});
 }
 await upload(one,pdfName,'application/pdf',pdf);
 const held=fixture('inspect');assert.equal(held.uploads.length,1);assert.equal(held.uploads[0].status,'quarantined');assert.equal(held.uploads[0].payload.sha256,pdfSha);assert.equal(held.pdf_attestations.length,0);assert.equal(held.attestations.length,0);
 assert.equal(await one.page.getByRole('button',{name:'Download '+pdfName,exact:true}).count(),0);
 assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 const begin=fileRequests.find(row=>row.url.endsWith('/files/begin'));assert.ok(begin);assert.deepEqual(begin.body.target,target);assert.equal(begin.body.expected_reviewer_name,seed.reviewer_names[0]);assert.equal(begin.body.mime,'application/pdf');assert.equal(begin.body.sha256,pdfSha);
 await two.page.bringToFront();await two.page.locator('[data-work-files-refresh]').click();await two.page.locator('[data-work-files-list]').getByText(pdfName,{exact:true}).waitFor();assert.equal(await two.page.getByRole('button',{name:'Download '+pdfName,exact:true}).count(),0);
 record({scenario:'actual_file_picker_saved_name_and_exact_incoming_target_persist_PDF_quarantine_other_session_held',status:'passed'});
 const scan=fixture('fake-scan',seed.version_id,held.uploads[0].id);assert.equal(scan.result.status,'clean');assert.equal(scan.FAKE_scanner_calls,1);assert.equal(scan.FAKE_evidence_calls,2);assert.deepEqual(scan.observations,[{transaction_level:0,permissions:384,exact_bytes:true}]);
 const scanned=fixture('inspect');assert.equal(scanned.pdf_attestations.length,1);assert.equal(scanned.pdf_attestations[0].status,'clean');assert.equal(scanned.uploads[0].status,'quarantined');assert.equal(scanned.revision,before.revision);
 async function ready(item){await item.page.bringToFront();await item.page.locator('[data-work-files-refresh]').click();await item.page.getByRole('button',{name:'Download '+pdfName,exact:true}).waitFor({state:'visible'});}
 async function download(item){await item.page.bringToFront();const event=item.page.waitForEvent('download');const click=item.page.getByRole('button',{name:'Download '+pdfName,exact:true}).click();const [artifact]=await Promise.all([event,click]);const rawFilename=artifact.suggestedFilename(),normalizedFilename=rawFilename.normalize('NFC');assert.equal(normalizedFilename,pdfName);assert.equal(await artifact.failure(),null);const bytes=await readFile(await artifact.path());assert.equal(createHash('sha256').update(bytes).digest('hex'),pdfSha);assert.deepEqual(bytes,pdf);return{sha256:pdfSha,size:bytes.length,filename:rawFilename,normalized_filename:normalizedFilename};}
 await ready(one);const downloadedOne=await download(one);
 await two.page.bringToFront();await two.page.reload();await two.page.locator('#work-drawer').waitFor({state:'visible'});await two.page.getByRole('button',{name:'Download '+pdfName,exact:true}).waitFor({state:'visible'});const downloadedTwo=await download(two);
 assert.equal(fixture('inspect').pdf_attestations.length,1);
 record({scenario:'FAKE_owner_scan_real_services_then_actual_download_event_exact_bytes_name_two_sessions_reload',status:'passed',scanner_evidence:'FAKE ONLY',scan,downloads:[downloadedOne,downloadedTwo]});
 await one.page.bringToFront();await one.page.setViewportSize({width:390,height:844});await ready(one);
 assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'center');assert.equal(await one.page.locator('[data-workspace-files]').count(),1);
 const phoneButton=one.page.getByRole('button',{name:'Download '+pdfName,exact:true});await phoneButton.scrollIntoViewIfNeeded();assert.equal(await phoneButton.isEnabled(),true);
 const phoneBounds=await phoneButton.boundingBox(),hostBounds=await one.page.locator('[data-workspace-files]').boundingBox();
 assert.ok(phoneBounds&&phoneBounds.x>=0&&phoneBounds.y>=0&&phoneBounds.x+phoneBounds.width<=391&&phoneBounds.y+phoneBounds.height<=845&&phoneBounds.width>=30&&phoneBounds.height>=15);
 assert.ok(hostBounds&&hostBounds.x>=0&&hostBounds.x+hostBounds.width<=391);
 // Visible file controls must own their actual pointer target, including docked shell tools.
 assert.equal(await phoneButton.evaluate(button=>{const r=button.getBoundingClientRect(),hit=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return hit===button||button.contains(hit);}),true,'The actual phone download button must not be covered by the shell tools');
 const dockBounds=await one.page.locator('#campus-shell-tools').boundingBox();assert.ok(dockBounds&&phoneBounds.y+phoneBounds.height<=dockBounds.y,'Drawer scroll content must remain above its tools footer');
 assert.equal(await one.page.locator('#campus-assistant-launcher').isVisible(),true);
 const phoneDownload=await download(one);assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 await one.page.screenshot({path:resolve(evidence,'phone-viewport.png'),fullPage:true});
 record({scenario:'actual_engine_phone_viewport_390x844_centered_file_controls_download_exact_bytes_name_and_draft',status:'passed',emulated_viewport_only:true,physical_mobile_or_Safari:false,bounds:phoneBounds,download:phoneDownload});
 await one.page.setViewportSize({width:1440,height:1000});

 await one.page.bringToFront();await one.page.getByRole('button',{name:'Side',exact:true}).click();assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'side');assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 await one.page.getByRole('button',{name:'Center',exact:true}).click();assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'center');assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);assert.equal(await one.page.locator('[data-workspace-files]').count(),1);
 const imageSource=await readFile('tests/browser/enterprise-attachment-gallery.smoke.mjs','utf8'),png=Buffer.from(imageSource.match(/const png = Buffer\.from\('([^']+)'/)[1],'base64'),imageName='FICTIONAL retained photo '+engine+'.png';
 await upload(one,imageName,'image/png',png);const imageUpload=fixture('inspect').uploads.find(row=>row.payload.mime==='image/png');assert.ok(imageUpload);
 const imageScan=fixture('fake-scan',seed.version_id,imageUpload.id);assert.equal(imageScan.result.status,'clean');assert.equal(imageScan.FAKE_scanner_calls,1);assert.equal(imageScan.FAKE_evidence_calls,2);
 await ready(one);await one.page.getByRole('button',{name:'View photo '+imageName,exact:true}).waitFor({state:'visible'});await one.page.getByRole('button',{name:'View photo '+imageName,exact:true}).click();await one.page.locator('[data-gallery-image]').waitFor({state:'visible'});
 await one.page.getByRole('button',{name:'Zoom in photo',exact:true}).click();assert.equal(await one.page.locator('[data-gallery-zoom-value]').innerText(),'150%');await one.page.keyboard.press('Escape');assert.equal(await one.page.locator('#attachment-gallery').isVisible(),false);assert.equal(await one.page.locator('#work-drawer').isVisible(),true);assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);assert.equal(await one.page.getByRole('button',{name:'Download '+pdfName,exact:true}).count(),1);
 record({scenario:'same_CenterSide_ticket_draft_and_one_host_retain_actual_existing_photo_gallery_alongside_PDF',status:'passed'});
 const beforeDenial=fixture('inspect');
 const binary=fileRequests.find(row=>row.url.endsWith('/downloads/download'));assert.ok(binary);assert.deepEqual(binary.body.target,target);
 const raw=await one.context.request.post(binary.url,{headers:{'X-CSRF-TOKEN':binary.csrf,Origin:origin,'Sec-Fetch-Site':'same-origin',Accept:'application/octet-stream'},data:binary.body});
 assert.equal(raw.status(),200);assert.deepEqual(await raw.body(),pdf);assert.equal(raw.headers()['content-type'],'application/octet-stream');assert.match(raw.headers()['content-disposition'],/^attachment;/);assert.equal(raw.headers()['content-encoding'],'identity');assert.equal(raw.headers()['content-length'],String(pdf.length));assert.match(raw.headers()['cache-control'],/no-store/);assert.match(raw.headers()['cache-control'],/no-transform/);assert.equal(raw.headers()['x-everbranch-attachment-upload'],held.uploads[0].id);assert.equal(raw.headers()['x-everbranch-attachment-sha256'],pdfSha);
 const noCsrf=await postJson(one,binary.url,binary.body,null);assert.equal(noCsrf.status,419);assert.match(noCsrf.cache,/no-store/);
 const wrong=await postJson(one,binary.url,{...binary.body,target:{kind:'project',id:project}});assert.equal(wrong.status,404);
 const foreign=await postJson(one,binary.url,{...binary.body,attestation_id:crypto.randomUUID()});assert.equal(foreign.status,404);
 const imageAttestation=fixture('inspect').attestations[0];assert.ok(imageAttestation);const crossKind=await postJson(one,binary.url,{...binary.body,attestation_id:imageAttestation.id});assert.equal(crossKind.status,404);
 record({scenario:'actual_octetstream_identity_headers_CSRF_wrong_target_foreign_and_image_attestation_refusal',status:'passed'});
 await one.page.locator('#close-work-detail').click();await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/roll_call`);await one.page.locator(`[data-roll-project-id="${project}"]`).click();await one.page.locator('[data-workspace-files]').waitFor({state:'visible'});assert.equal(await one.page.locator('[data-work-files-list]').getByText(pdfName,{exact:true}).count(),0);
 await one.page.locator('#shared-discussion-thread').selectOption('task:'+before.graph.tasks.find(row=>row.project_id===project).id);await one.page.locator('[data-workspace-files]').waitFor({state:'visible'});assert.equal(await one.page.locator('[data-work-files-list]').getByText(pdfName,{exact:true}).count(),0);
 await one.page.locator('#close-work-detail').click();await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);await openTicket(one);await one.page.getByRole('button',{name:'Download '+pdfName,exact:true}).waitFor();assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 record({scenario:'actual_project_task_target_isolation_return_retains_PDF_thread_and_unsent_draft',status:'passed'});
 const locked=await browser.newContext({ignoreHTTPSErrors:true}),lockedPage=await locked.newPage();await lockedPage.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}`);const lockedCsrf=await lockedPage.locator('input[name="_token"]').first().inputValue();const denied=await locked.request.post(binary.url,{headers:{'X-CSRF-TOKEN':lockedCsrf,Origin:origin,'Sec-Fetch-Site':'same-origin',Accept:'application/json'},data:binary.body});assert.equal(denied.status(),403);assert.match(denied.headers()['cache-control'],/no-store/);await locked.close();
 fixture('revoke',seed.version_id);const revoked=await postJson(one,binary.url,binary.body);assert.equal(revoked.status,410);assert.match(revoked.cache,/no-store/);await one.page.locator('[data-work-files-refresh]').click();await one.page.waitForFunction(()=>!document.querySelector('[data-workspace-files]'));assert.equal(await one.page.getByRole('button',{name:'Download '+pdfName,exact:true}).count(),0);assert.equal(await one.page.locator('.discussion-image').count(),0);
 const after=fixture('inspect');assert.equal(after.ledger_rows,0);assert.equal(after.native_tasks,0);assert.equal(after.users,before.users);assert.equal(after.memberships,before.memberships);assert.equal(after.revision,before.revision);assert.deepEqual(after.uploads,beforeDenial.uploads);assert.deepEqual(after.pdf_attestations,beforeDenial.pdf_attestations);assert.deepEqual(after.attestations,beforeDenial.attestations);assert.deepEqual(after.private_files,beforeDenial.private_files);assert.ok(after.uploads.every(row=>row.status==='quarantined'));assert.equal(after.pdf_attestations.length,1);assert.equal(after.attestations.length,1);assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(blockers,[]);assert.equal(deadlineExpired,false);
 record({scenario:'actual_locked_session_and_current_revocation_clear_file_controls_preserve_graph_native_users_and_budget',status:'passed'});

 assert.equal(results.length,9,'Every mounted PDF scenario is mandatory');
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,elapsed_ms:Date.now()-startedAt,pacing_ms:2150,scenario_mode:'all',network_guard:'abort nonloopback; every loopback request continued unchanged; no local API response substitution',status:'passed',synthetic:true,scanner_evidence:'FAKE ONLY',results,posts,fileResponses,errors,external},null,2));console.log(JSON.stringify({engine,status:'passed',scenarios:results.length}));
}catch(error){
 let state=null;try{const value=fixture('inspect');state={revision:value.revision,ledger_rows:value.ledger_rows,uploads:value.uploads?.map(row=>({id:row.id,status:row.status,version:row.version})),attestations:value.attestations};}catch{}
 await currentPage?.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'failed',results,posts,fileResponses,state,errors,external,blockers,message:error.message,ui_status:await currentPage?.locator('#status').innerText().catch(()=>null),file_status:await currentPage?.locator('[data-work-files-status]').innerText().catch(()=>null)},null,2));throw error;
}finally{
 clearTimeout(deadline);
 try{await browser?.close();}finally{try{if(proxy){proxy.closeAllConnections();await new Promise(done=>proxy.close(()=>done()));}}finally{try{if(server&&server.exitCode===null&&server.signalCode===null){const exit=once(server,'exit').catch(()=>{});server.kill('SIGTERM');await Promise.race([exit,new Promise(done=>setTimeout(done,3000))]);if(server.exitCode===null&&server.signalCode===null){server.kill('SIGKILL');await Promise.race([exit,new Promise(done=>setTimeout(done,3000))]);}}}finally{await writeFile(resolve(evidence,'server.log'),serverLog);await rm(runtime,{recursive:true,force:true});await writeFile(resolve(evidence,'cleanup.json'),JSON.stringify({runtime_removed:true,php_exit_code:server?.exitCode,php_signal:server?.signalCode,private_fixture_only:true},null,2));}}}
}
