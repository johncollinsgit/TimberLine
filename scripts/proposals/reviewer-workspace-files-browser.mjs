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
const root=resolve('.'),engine=process.env.PROPOSAL_BROWSER_ENGINE||'chromium';
assert.ok(!process.env.PROPOSAL_WORKSPACE_SCENARIO||process.env.PROPOSAL_WORKSPACE_SCENARIO==='files','Files acceptance always runs every case; unsupported scenario input refuses before runtime creation');
assert.ok(['chromium','webkit'].includes(engine));
const php=process.env.ENTERPRISE_TEST_PHP;
assert.ok(php,'ENTERPRISE_TEST_PHP must pin a local PHP executable');
const {chromium,webkit}=await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE||'node_modules/playwright/index.mjs')).href);
const parent=dirname(root),runtime=resolve(parent,`runtime-${Date.now()}`),origin='https://127.0.0.1:9041';
const evidence=resolve(process.env.PROPOSAL_BROWSER_EVIDENCE||`../evidence-${engine}-${Date.now()}`);
await mkdir(runtime,{mode:0o700});
for(const directory of ['storage/framework/testing','storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs','storage/app/private','storage/app/team-private','bootstrap/cache'])await mkdir(resolve(runtime,directory),{recursive:true,mode:0o700});
await chmod(runtime,0o700);await writeFile(resolve(runtime,'.env'),'',{mode:0o600});
await writeFile(resolve(runtime,'storage/framework/testing/enterprise-browser-reviewer-workspace.sqlite'),'',{mode:0o600});
await mkdir(evidence,{recursive:true,mode:0o700});
const env={PATH:process.env.PATH,APP_ENV:'testing',ENTERPRISE_RUNTIME_ROOT:runtime,REVIEWER_WORKSPACE_BROWSER:'1',REVIEWER_WORKSPACE_FILES_BROWSER:'1',REVIEWER_WORKSPACE_BROWSER_ORIGIN:origin,REVIEWER_WORKSPACE_BROWSER_SEED:'core'};
const fixture=(...args)=>{const result=spawnSync(php,['tests/Support/proposal-reviewer-workspace-files-browser-fixture.php',...args],{env,encoding:'utf8',windowsHide:true});assert.equal(result.status,0,result.stdout+result.stderr);try{return JSON.parse(result.stdout);}catch{throw Error('Fictional local fixture did not return JSON: '+result.stdout.slice(0,4000));}};
let server,proxy,browser,serverLog='',currentPage,serial=Promise.resolve(),lastDispatch=0;
const external=[],errors=[],results=[],posts=[],blockers=[],responses=[],commentRequests=[],commentResponses=[],fileRequests=[],fileResponses=[];
const record=value=>{results.push(value);console.log(JSON.stringify({progress:value.scenario,status:value.status}));};
try{
 const seed=fixture('seed');assert.equal(seed.seed_projects,20);
 const keyPath=resolve(runtime,'fixture.key'),certPath=resolve(runtime,'fixture.crt');
 const cert=spawnSync(process.env.ENTERPRISE_TEST_OPENSSL||'openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyPath,'-out',certPath,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],{encoding:'utf8'});
 assert.equal(cert.status,0,'Local synthetic certificate generation failed');
 const key=await readFile(keyPath),certificate=await readFile(certPath);await rm(keyPath);await rm(certPath);
 server=spawn(php,['-S','127.0.0.1:9040','tests/Support/proposal-reviewer-workspace-files-browser-router.php'],{env,stdio:['ignore','pipe','pipe']});
 for(const stream of [server.stdout,server.stderr])stream.on('data',chunk=>{serverLog+=chunk;});
 proxy=createServer({key,cert:certificate},(req,res)=>{
  serial=serial.then(async()=>{
   await new Promise(done=>setTimeout(done,Math.max(0,lastDispatch+2150-Date.now())));
   if(req.destroyed||res.destroyed)return;lastDispatch=Date.now();
   await new Promise(done=>{let upstream,finished=false;const finish=()=>{if(finished)return;finished=true;req.off('aborted',abort);res.off('close',abort);done();};const abort=()=>{upstream?.destroy();finish();};
    upstream=httpRequest({hostname:'127.0.0.1',port:9040,path:req.url,method:req.method,headers:req.headers},response=>{if(res.destroyed){response.destroy();finish();return;}res.writeHead(response.statusCode,response.headers);response.pipe(res);response.once('end',finish);response.once('close',finish);});
    req.once('aborted',abort);res.once('close',abort);upstream.once('error',()=>{if(!res.destroyed){if(!res.headersSent)res.writeHead(503);res.end('Local fixture unavailable');}finish();});if(req.aborted||res.destroyed)abort();else if(req.readableEnded)upstream.end();else req.pipe(upstream);
   });
  });
 });proxy.listen(9041,'127.0.0.1');await once(proxy,'listening');
 browser=await(engine==='webkit'?webkit:chromium).launch({headless:true});
 const clients=[];
 async function client(){
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==origin){external.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
  const page=await context.newPage();currentPage=page;const item={context,page,projection:null};clients.push(item);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{if(request.method()==='POST' && /\/workspace\/(files|images)\//.test(request.url()))fileRequests.push({url:request.url(),body:request.postDataJSON(),csrf:request.headers()['x-csrf-token']});});
  page.on('response',async response=>{if(/\/workspace\/(files|images)\//.test(response.url()))fileResponses.push({url:response.url(),status:response.status(),content_type:response.headers()['content-type'],cache:response.headers()['cache-control']});});
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
 const pngSource=await readFile('tests/browser/enterprise-attachment-gallery.smoke.mjs','utf8');
 const png=Buffer.from(pngSource.match(/const png = Buffer\.from\('([^']+)'/)[1],'base64'),pngSha=createHash('sha256').update(png).digest('hex');
 const differentImage=spawnSync(php,['-r', '$image=imagecreatetruecolor(7,5);imagefill($image,0,0,imagecolorallocate($image,30,90,60));ob_start();imagepng($image);echo base64_encode(ob_get_clean());'],{env,encoding:'utf8'});
 assert.equal(differentImage.status,0,'Fixed fictional PNG generation must succeed');
 const pngs=[png,Buffer.from(differentImage.stdout,'base64')],imageHashes=pngs.map(bytes=>createHash('sha256').update(bytes).digest('hex'));assert.notEqual(imageHashes[0],imageHashes[1]);
 async function openTicket(item){await item.page.locator(`#request-list [data-open="${incoming}"]`).click();await item.page.locator('[data-workspace-files]').waitFor({state:'visible'});await item.page.locator('[data-work-files-choose]').waitFor({state:'visible'});}
 await openTicket(one);await openTicket(two);
 const draft='FICTIONAL unsent discussion retained through file/gallery '+engine;
 await one.page.locator('#shared-discussion-body').fill(draft);
 async function upload(name,bytes){
  const chooser=one.page.waitForEvent('filechooser');await one.page.locator('[data-work-files-choose]').click();await(await chooser).setFiles({name,mimeType:'image/png',buffer:bytes});
  await one.page.locator('[data-work-files-upload]').click();
  await one.page.locator('[data-work-files-list]').getByText(name,{exact:true}).waitFor({state:'visible'});
  await one.page.locator(`[data-work-files-list] li`).filter({hasText:name}).getByText(' · Held for scanning',{exact:true}).waitFor({state:'visible'});
 }
 const names=['FICTIONAL persisted one '+engine+'.png','FICTIONAL persisted two '+engine+'.png'];
 await upload(names[0],pngs[0]);await upload(names[1],pngs[1]);
 const held=fixture('inspect');assert.equal(held.uploads.length,2);assert.ok(held.uploads.every(row=>row.status==='quarantined'&&imageHashes.includes(row.payload.sha256)));assert.equal(held.attestations.length,0);assert.equal(held.ledger_rows,0);assert.equal(held.revision,before.revision);assert.equal(held.native_tasks,0);
 assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 assert.equal(await one.page.locator('.discussion-image').count(),0);
 record({scenario:'actual_UI_two_private_uploads_stay_quarantined_without_fake_scan_preserve_incoming_thread_draft',status:'passed'});
 await two.page.locator('[data-work-files-refresh]').click();await two.page.locator('[data-work-files-list]').getByText(names[0],{exact:true}).waitFor();
 assert.equal(await two.page.locator('.discussion-image').count(),0);
 const scans=held.uploads.map(row=>fixture('fake-scan',seed.version_id,row.id));
 for(const scan of scans){assert.equal(scan.result.status,'clean');assert.equal(scan.FAKE_scanner_calls,1);assert.equal(scan.FAKE_evidence_calls,2);assert.deepEqual(scan.observations,[{transaction_level:0,permissions:384,exact_bytes:true}]);}
 const afterScan=fixture('inspect');assert.ok(afterScan.uploads.every(row=>row.status==='quarantined'));assert.equal(afterScan.attestations.length,2);assert.ok(afterScan.attestations.every(row=>row.status==='clean'));assert.equal(afterScan.revision,before.revision);
 await one.page.locator('[data-work-files-refresh]').click();await one.page.waitForFunction(()=>document.querySelectorAll('.discussion-image').length===2);
 await two.page.reload();await two.page.locator('#work-drawer').waitFor({state:'visible'});await two.page.waitForFunction(()=>document.querySelectorAll('.discussion-image').length===2);
 record({scenario:'FAKE_scanner_evidence_only_real_scan_service_private_derivatives_and_other_client_reload_ready',status:'passed',fake_scans:scans});
 await one.page.getByRole('button',{name:'View photo '+names[0],exact:true}).click();
 await one.page.locator('#attachment-gallery').waitFor({state:'visible'});await one.page.locator('[data-gallery-image]').waitFor({state:'visible'});
 assert.equal(await one.page.locator('[data-gallery-title]').innerText(),names[0]);
 await one.page.getByRole('button',{name:'Zoom in photo',exact:true}).click();assert.equal(await one.page.locator('[data-gallery-zoom-value]').innerText(),'150%');
 await one.page.getByRole('button',{name:'Next photo',exact:true}).click();await one.page.locator('[data-gallery-title]').filter({hasText:names[1]}).waitFor();await one.page.locator('[data-gallery-image]').waitFor({state:'visible'});
 await one.page.getByRole('button',{name:'Close photo',exact:true}).click();
 assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'center');
 await one.page.getByRole('button',{name:'Side',exact:true}).click();assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'side');assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 await one.page.getByRole('button',{name:'Center',exact:true}).click();assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'center');assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 assert.equal(await one.page.locator('[data-workspace-files]').count(),1);assert.equal(await one.page.locator('.discussion-image').count(),2);
 record({scenario:'actual_nested_gallery_next_zoom_close_and_CenterSide_preserve_ticket_draft_one_host',status:'passed'});
 const binary=fileRequests.find(row=>row.url.endsWith('/images/preview'));assert.ok(binary);
 const binaryResponse=await one.context.request.post(binary.url,{headers:{'X-CSRF-TOKEN':binary.csrf,Origin:origin,'Sec-Fetch-Site':'same-origin',Accept:'image/png'},data:binary.body});
 assert.equal(binaryResponse.status(),200);assert.equal(createHash('sha256').update(await binaryResponse.body()).digest('hex'),pngSha);assert.match(binaryResponse.headers()['cache-control'],/no-store/);assert.equal(binaryResponse.headers()['x-everbranch-image-upload'],binary.body.upload_id);
 const noCsrf=await postJson(one,binary.url,binary.body,null);assert.equal(noCsrf.status,419);assert.match(noCsrf.cache,/no-store/);
 const wrong=await postJson(one,binary.url,{...binary.body,target:{kind:'project',id:project}});assert.equal(wrong.status,404);
 const foreign=await postJson(one,binary.url,{...binary.body,attestation_id:crypto.randomUUID()});assert.equal(foreign.status,404);
 record({scenario:'actual_private_binary_exact_uploaded_bytes_CSRF_wrong_thread_and_foreign_attestation_refusal',status:'passed'});
 await one.page.locator('#close-work-detail').click();
 await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/roll_call`);
 await one.page.locator(`[data-roll-project-id="${project}"]`).click();await one.page.locator('[data-workspace-files]').waitFor({state:'visible'});
 assert.equal(await one.page.locator('[data-work-files-list]').getByText(names[0],{exact:true}).count(),0);
 await one.page.locator('#shared-discussion-thread').selectOption('task:'+before.graph.tasks.find(row=>row.project_id===project).id);
 await one.page.locator('[data-workspace-files]').waitFor({state:'visible'});assert.equal(await one.page.locator('[data-work-files-list]').getByText(names[0],{exact:true}).count(),0);
 await one.page.locator('#close-work-detail').click();
 await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);await openTicket(one);
 await one.page.waitForFunction(()=>document.querySelectorAll('.discussion-image').length===2);
 assert.equal(await one.page.locator('#shared-discussion-body').inputValue(),draft);
 record({scenario:'project_task_and_incoming_threads_keep_private_files_separate_return_restores_draft',status:'passed'});
 const locked=await browser.newContext({ignoreHTTPSErrors:true});const lockedPage=await locked.newPage();await lockedPage.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}`);const lockedCsrf=await lockedPage.locator('input[name="_token"]').first().inputValue();
 const denied=await locked.request.post(binary.url,{headers:{'X-CSRF-TOKEN':lockedCsrf,Origin:origin,'Sec-Fetch-Site':'same-origin',Accept:'application/json'},data:binary.body});assert.equal(denied.status(),403);assert.match(denied.headers()['cache-control'],/no-store/);await locked.close();
 fixture('revoke',seed.version_id);
 const revoked=await postJson(one,binary.url,binary.body);assert.equal(revoked.status,410);assert.match(revoked.cache,/no-store/);
 await one.page.locator('[data-work-files-refresh]').click();await one.page.waitForFunction(()=>!document.querySelector('[data-workspace-files]'));assert.equal(await one.page.locator('.discussion-image').count(),0);
 const after=fixture('inspect');assert.equal(after.ledger_rows,0);assert.equal(after.native_tasks,0);assert.equal(after.users,before.users);assert.equal(after.memberships,before.memberships);assert.equal(after.revision,before.revision);assert.deepEqual(after.uploads,afterScan.uploads);
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(blockers,[]);
 record({scenario:'actual_locked_session_current_revocation_clear_files_preserve_identity_graph_budget',status:'passed'});
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'passed',synthetic:true,scanner_evidence:'FAKE ONLY',results,posts,fileResponses,errors,external},null,2));console.log(JSON.stringify({engine,status:'passed',scenarios:results.length}));
}catch(error){
 let state=null;try{const value=fixture('inspect');state={revision:value.revision,ledger_rows:value.ledger_rows,uploads:value.uploads?.map(row=>({id:row.id,status:row.status,version:row.version})),attestations:value.attestations};}catch{}
 await currentPage?.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'failed',results,posts,fileResponses,state,errors,external,blockers,message:error.message,ui_status:await currentPage?.locator('#status').innerText().catch(()=>null),file_status:await currentPage?.locator('[data-work-files-status]').innerText().catch(()=>null)},null,2));throw error;
}finally{
 try{await browser?.close();}finally{try{if(proxy){proxy.closeAllConnections();await new Promise(done=>proxy.close(()=>done()));}}finally{try{if(server&&server.exitCode===null&&server.signalCode===null){const exit=once(server,'exit').catch(()=>{});server.kill('SIGTERM');await Promise.race([exit,new Promise(done=>setTimeout(done,3000))]);if(server.exitCode===null&&server.signalCode===null){server.kill('SIGKILL');await Promise.race([exit,new Promise(done=>setTimeout(done,3000))]);}}}finally{await writeFile(resolve(evidence,'server.log'),serverLog);await rm(runtime,{recursive:true,force:true});await writeFile(resolve(evidence,'cleanup.json'),JSON.stringify({runtime_removed:true,php_exit_code:server?.exitCode,php_signal:server?.signalCode,private_fixture_only:true},null,2));}}}
}
