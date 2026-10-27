import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {createServer} from 'node:https';
import {request as httpRequest} from 'node:http';
import {once} from 'node:events';
import {mkdir,writeFile,readFile,rm,chmod} from 'node:fs/promises';
import {resolve,dirname} from 'node:path';
import {pathToFileURL} from 'node:url';

// Real loopback Enterprise kernel, private SQLite/file sessions, two independent browser cookies.
// No API response interception, sender approval, provider/key/AI/runtime action or browser-local work fallback.
const root=resolve('.'),engine=process.env.PROPOSAL_BROWSER_ENGINE||'chromium',directoryMode=false;
assert.ok(!process.env.PROPOSAL_WORKSPACE_SCENARIO||process.env.PROPOSAL_WORKSPACE_SCENARIO==='core','Comments acceptance always runs all13 scenarios; unsupported scenario input refuses before runtime creation');
assert.ok(['chromium','webkit'].includes(engine));
const php=process.env.ENTERPRISE_TEST_PHP;
assert.ok(php,'ENTERPRISE_TEST_PHP must pin a local PHP executable');
const {chromium,webkit}=await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE||'node_modules/playwright/index.mjs')).href);
const parent=dirname(root),runtime=resolve(parent,`runtime-${Date.now()}`),origin='https://127.0.0.1:9039';
const evidence=resolve(process.env.PROPOSAL_BROWSER_EVIDENCE||`../evidence-${engine}-${Date.now()}`);
await mkdir(runtime,{mode:0o700});
for(const directory of ['storage/framework/testing','storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs','storage/app/private','storage/app/team-private','bootstrap/cache'])await mkdir(resolve(runtime,directory),{recursive:true,mode:0o700});
await chmod(runtime,0o700);await writeFile(resolve(runtime,'.env'),'',{mode:0o600});
await writeFile(resolve(runtime,'storage/framework/testing/enterprise-browser-reviewer-workspace.sqlite'),'',{mode:0o600});
await mkdir(evidence,{recursive:true,mode:0o700});
const env={PATH:process.env.PATH,APP_ENV:'testing',ENTERPRISE_RUNTIME_ROOT:runtime,REVIEWER_WORKSPACE_BROWSER:'1',REVIEWER_WORKSPACE_COMMENTS_BROWSER:'1',REVIEWER_WORKSPACE_BROWSER_ORIGIN:origin,REVIEWER_WORKSPACE_BROWSER_SEED:directoryMode?'official-map-v1':'core'};
const fixture=(...args)=>{const result=spawnSync(php,['tests/Support/proposal-reviewer-workspace-comments-browser-fixture.php',...args],{env,encoding:'utf8',windowsHide:true});assert.equal(result.status,0,result.stdout+result.stderr);try{return JSON.parse(result.stdout);}catch{throw Error('Fictional local fixture did not return JSON: '+result.stdout.slice(0,4000));}};
let server,proxy,browser,serverLog='',currentPage,serial=Promise.resolve(),lastDispatch=0;
const external=[],errors=[],results=[],posts=[],blockers=[],responses=[],commentRequests=[],commentResponses=[];
const record=value=>{results.push(value);console.log(JSON.stringify({progress:value.scenario,status:value.status}));};
try{
 const seed=fixture('seed');assert.equal(seed.seed_projects,20);
 const keyPath=resolve(runtime,'fixture.key'),certPath=resolve(runtime,'fixture.crt');
 const cert=spawnSync(process.env.ENTERPRISE_TEST_OPENSSL||'openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyPath,'-out',certPath,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],{encoding:'utf8'});
 assert.equal(cert.status,0,'Local synthetic certificate generation failed');
 const key=await readFile(keyPath),certificate=await readFile(certPath);await rm(keyPath);await rm(certPath);
 server=spawn(php,['-S','127.0.0.1:9038','tests/Support/proposal-reviewer-workspace-comments-browser-router.php'],{env,stdio:['ignore','pipe','pipe']});
 for(const stream of [server.stdout,server.stderr])stream.on('data',chunk=>{serverLog+=chunk;});
 proxy=createServer({key,cert:certificate},(req,res)=>{
  serial=serial.then(async()=>{
   await new Promise(done=>setTimeout(done,Math.max(0,lastDispatch+2150-Date.now())));
   if(req.destroyed||res.destroyed)return;lastDispatch=Date.now();
   await new Promise(done=>{let upstream,finished=false;const finish=()=>{if(finished)return;finished=true;req.off('aborted',abort);res.off('close',abort);done();};const abort=()=>{upstream?.destroy();finish();};
    upstream=httpRequest({hostname:'127.0.0.1',port:9038,path:req.url,method:req.method,headers:req.headers},response=>{if(res.destroyed){response.destroy();finish();return;}res.writeHead(response.statusCode,response.headers);response.pipe(res);response.once('end',finish);response.once('close',finish);});
    req.once('aborted',abort);res.once('close',abort);upstream.once('error',()=>{if(!res.destroyed){if(!res.headersSent)res.writeHead(503);res.end('Local fixture unavailable');}finish();});if(req.aborted||res.destroyed)abort();else if(req.readableEnded)upstream.end();else req.pipe(upstream);
   });
  });
 });proxy.listen(9039,'127.0.0.1');await once(proxy,'listening');
 browser=await(engine==='webkit'?webkit:chromium).launch({headless:true});
 const clients=[];
 async function client(){
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==origin){external.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
  const page=await context.newPage();currentPage=page;const item={context,page,projection:null};clients.push(item);
  page.on('pageerror',error=>errors.push(error.message));
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
 const one=await client(),two=await client();
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
 const incomingNote='FICTIONAL incoming discussion <script>literal only</script> '+engine;
 await one.page.locator(`#request-list [data-open="${incoming}"]`).click();
 await one.page.locator('#shared-discussion').waitFor({state:'visible'});
 await one.page.locator('#shared-discussion-post').waitFor({state:'visible'});
 await one.page.locator('#shared-discussion-body').fill(incomingNote);
 await one.page.locator('#shared-discussion-post').click();
 await one.page.locator('#shared-discussion').getByText(incomingNote,{exact:true}).waitFor({state:'visible'});
 assert.equal(await one.page.locator('#shared-discussion script').count(),0);
 const afterIncoming=fixture('inspect');assert.equal(afterIncoming.graph.tickets.find(t=>t.id===incoming).task_id,null);
 assert.equal(afterIncoming.native_tasks,before.native_tasks);assert.equal(afterIncoming.revision,before.revision);
 assert.equal(afterIncoming.thread_rows,1);assert.equal(afterIncoming.comment_receipt_rows,1);
 await two.page.locator(`#request-list [data-open="${incoming}"]`).click();
 await two.page.locator('#shared-discussion').getByText(incomingNote,{exact:true}).waitFor({state:'visible'});
 await two.page.reload();await two.page.locator('#work-drawer').waitFor({state:'visible'});
 await two.page.locator('#shared-discussion').getByText(incomingNote,{exact:true}).waitFor({state:'visible'});
 await one.page.reload();await one.page.locator('#work-drawer').waitFor({state:'visible'});
 await one.page.locator('#shared-discussion').getByText(incomingNote,{exact:true}).waitFor({state:'visible'});
 record({scenario:'actual_incoming_ticket_comment_independent_clients_reload_literal_text_no_native_task',status:'passed'});
 const originalComment=structuredClone(commentRequests[0]);assert.ok(originalComment);
 const replay=await postJson(one,originalComment.url,originalComment.body,originalComment.csrf);
 assert.equal(replay.status,200);assert.equal(replay.body.replayed,true);assert.equal(fixture('inspect').comment_receipt_rows,1);
 const noCsrf=await postJson(one,originalComment.url,{...originalComment.body,request_id:crypto.randomUUID()},null);
 assert.equal(noCsrf.status,419);assert.match(noCsrf.cache,/no-store/);assert.match(noCsrf.csp,/default-src 'none'/);
 const other=await postJson(two,originalComment.url.replace(/comment$/,'status'),{binding:originalComment.body.binding,selection:originalComment.body.selection,target:originalComment.body.target,request_id:originalComment.body.request_id,digest:replay.body.receipt.digest});
 assert.equal(other.status,200);assert.equal(other.body.status,'not_observed');assert.equal(other.body.settled,false);
 record({scenario:'actual_comment_once_only_replay_csrf_and_independent_receipt_ownership',status:'passed'});
 const lockedContext=await browser.newContext({ignoreHTTPSErrors:true});
 await lockedContext.route('**/*',route=>{if(new URL(route.request().url()).origin!==origin){external.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
 const lockedPage=await lockedContext.newPage();await lockedPage.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}`);
 const lockedCsrf=await lockedPage.locator('input[name="_token"]').first().inputValue();
 const lockedResponse=await lockedContext.request.post(originalComment.url.replace(/comment$/,'show'),{headers:{'X-CSRF-TOKEN':lockedCsrf,Origin:origin,'Sec-Fetch-Site':'same-origin',Accept:'application/json'},data:{binding:originalComment.body.binding,selection:originalComment.body.selection,target:originalComment.body.target,before:null},maxRedirects:0});
 const locked={status:lockedResponse.status(),cache:lockedResponse.headers()['cache-control'],body:await lockedResponse.json()};
 assert.equal(locked.status,403);assert.match(locked.cache,/no-store/);await lockedContext.close();
 const method=await one.page.evaluate(async url=>{const r=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});return{status:r.status,cache:r.headers.get('Cache-Control'),csp:r.headers.get('Content-Security-Policy'),body:await r.text()};},originalComment.url);
 assert.equal(method.status,405);assert.match(method.cache,/no-store/);assert.match(method.csp,/default-src 'none'/);assert.ok(!method.body.includes('trace'));
 record({scenario:'actual_locked_session_and_method_refusal_private_boundary',status:'passed'});

 // A stale expected display name is a real server conflict, not another user's identity.
 const staleName={...originalComment.body,request_id:crypto.randomUUID(),expected_thread_revision:1,expected_reviewer_name:seed.reviewer_names[1],body:'FICTIONAL name conflict must not save'};
 const conflict=await postJson(one,originalComment.url,staleName);assert.equal(conflict.status,409);assert.equal(conflict.body.definitive_not_applied,true);
 assert.equal(fixture('inspect').comment_receipt_rows,1);
 await one.page.locator('#close-work-detail').click();
 await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/roll_call`);
 await one.page.locator(`[data-roll-project-id="${project}"]`).click();
 const projectNote='FICTIONAL project thread '+engine;
 await one.page.locator('#shared-discussion-body').fill(projectNote);await one.page.locator('#shared-discussion-post').click();
 await one.page.locator('#shared-discussion').getByText(projectNote,{exact:true}).waitFor({state:'visible'});
 assert.equal(await one.page.locator('#shared-discussion').getByText(incomingNote,{exact:true}).count(),0);
 await one.page.reload();await one.page.locator('#work-drawer').waitFor({state:'visible'});
 await one.page.locator('#shared-discussion').getByText(projectNote,{exact:true}).waitFor({state:'visible'});
 assert.equal(fixture('inspect').thread_rows,2);assert.equal(fixture('inspect').comment_receipt_rows,2);
 record({scenario:'actual_project_thread_isolation_reload_and_expected_name_conflict',status:'passed'});
 // Return to Requests for the unchanged original six core acceptance scenarios below.
 await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);
 await two.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);

 if(directoryMode){
  const graph=before.graph,area=graph.areas[0].id;
  assert.deepEqual(Object.fromEntries(['projects','tasks','tickets','people','contractors','buildings'].map(key=>[key,graph[key].length])),{projects:20,tasks:21,tickets:25,people:11,contractors:5,buildings:25});
  assert.equal(graph.buildings.filter(row=>row.map_reference).length,25);
  record({scenario:'actual_full_official_fictional_graph_20_jobs_10_workers_manager_5_contractors',status:'passed'});
  currentPage=one.page;
  await one.page.locator('[data-section="projects"]').click();
  await one.page.locator('#work-status').selectOption('all');
  await one.page.waitForFunction(()=>document.querySelectorAll('#request-list [data-work-row]').length===20,null,{timeout:30000});
  assert.equal(await one.page.locator('#work-department option').count(),5);
  await one.page.locator('#work-department').selectOption(area);
  const areaProjects=graph.projects.filter(row=>row.area_id===area);
  await one.page.waitForFunction(count=>document.querySelectorAll('#request-list [data-work-row]').length===count,areaProjects.length);
  const assigned=graph.tasks.find(row=>row.area_id===area&&row.assignee_id);
  await one.page.locator('#work-person').selectOption(assigned.assignee_id);
  const personProjects=areaProjects.filter(project=>graph.tasks.some(task=>task.project_id===project.id&&task.assignee_id===assigned.assignee_id));
  const assignedName=graph.people.find(person=>person.id===assigned.assignee_id).name;
  assert.ok((await one.page.locator('#request-list').innerText()).includes(assignedName),'Assigned names must be visible in project rows');
  await one.page.waitForFunction(count=>document.querySelectorAll('#request-list [data-work-row]').length===count,personProjects.length);
  await one.page.locator('#work-person').selectOption('all');await one.page.locator('#work-department').selectOption('all');
  await one.page.waitForFunction(()=>document.querySelectorAll('#request-list [data-work-row]').length===20);
  record({scenario:'project_catalog_20_rows_department_and_person_controls',status:'passed'});
  await one.page.locator('[data-view="calendar"]').click();
  assert.equal(await one.page.locator('#request-list .month-cell').count(),42);
  const month=one.page.locator('#request-list [data-month-picker]');await month.fill('2026-11');await month.dispatchEvent('change');
  assert.ok(await one.page.locator('#request-list .month-event').count()>0);
  await one.page.locator('#request-list [data-month-step="1"]').click();
  assert.equal(await one.page.locator('#request-list [data-month-picker]').inputValue(),'2026-12');
  await one.page.reload();assert.equal(await one.page.locator('#request-list [data-month-picker]').inputValue(),'2026-12');
  assert.equal(await one.page.locator('#request-list .month-cell').count(),42);
  record({scenario:'actual_project_month_grid_controls_and_reload_month',status:'passed'});
  await one.page.locator('[data-view="map"]').click();
  await one.page.locator('#map [data-map-building]').first().waitFor({state:'visible'});
  assert.ok(await one.page.locator('#map [data-map-building]').count()>0);
  await one.page.waitForFunction(()=>document.getElementById('campus-work-plan')?.naturalWidth>0);
  await one.page.locator('#map [data-map-building]').first().click();
  await one.page.locator('#campus-map-tickets [data-open]').first().click();
  await one.page.locator('#work-drawer').waitFor({state:'visible'});
  assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'center');
  await one.page.locator('[data-detail-placement="side"]').click();assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'side');
  await one.page.locator('[data-detail-placement="center"]').click();assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),'center');
  await one.page.locator('#close-work-detail').click();
  record({scenario:'actual_official_map_markers_open_work_and_center_side_controls',status:'passed'});
  // A previous project filter must not redirect a building-specific intake form.
  await one.page.locator('#work-department').selectOption(graph.areas.find(row=>row.id!==area).id);
  await one.page.locator('#buildings-nav').click();
  const ticket=graph.tickets.find(row=>row.area_id===area&&row.building_id);
  const building=graph.buildings.find(row=>row.id===ticket.building_id);
  const directory=one.page.locator('#shared-campus-directory');
  await directory.getByRole('button',{name:building.title,exact:true}).click();
  await directory.getByRole('button',{name:ticket.title+' · '+ticket.status,exact:true}).click();
  await one.page.locator('#work-drawer').waitFor({state:'visible'});assert.ok((await one.page.locator('#detail').innerText()).includes(ticket.title));
  await one.page.locator('#close-work-detail').click();await one.page.locator('#buildings-nav').click();
  await directory.getByRole('button',{name:building.title,exact:true}).click();
  await directory.getByRole('button',{name:'New ticket in this building',exact:true}).click();
  assert.equal(await one.page.locator('#request-building').inputValue(),building.id);
  assert.equal(await one.page.locator('#request-department').inputValue(),area);
  assert.equal(await one.page.locator('#create-submit').isEnabled(),true);
  await one.page.locator('#cancel-create').click();
  record({scenario:'actual_building_ticket_links_open_and_prefill_new_ticket',status:'passed'});
  await one.page.locator('#people-nav').click();
  for(const person of graph.people.filter(row=>row.active&&row.area_ids.includes(area)))await directory.getByRole('heading',{name:person.name,exact:true}).waitFor({state:'visible'});
  for(const contractor of graph.contractors.filter(row=>row.area_id===area))await directory.getByRole('heading',{name:contractor.title,exact:true}).waitFor({state:'visible'});
  await one.page.locator('#calendar-nav').click();assert.equal(await directory.locator('.month-cell').count(),42);
  await directory.locator('[data-month-picker]').fill('2026-11');await directory.locator('[data-month-picker]').dispatchEvent('change');
  assert.ok(await directory.locator('.month-event').count()>0);await directory.locator('.month-event').first().click();await one.page.locator('#work-drawer').waitFor({state:'visible'});
  await one.page.locator('#close-work-detail').click();
  const after=fixture('inspect');assert.equal(after.revision,before.revision);assert.equal(after.ledger_rows,0);assert.equal(after.users,before.users);assert.equal(after.memberships,before.memberships);
  assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(blockers,[]);
  record({scenario:'actual_department_people_contractors_and_calendar_open_current_project',status:'passed'});
 }else{
 currentPage=one.page;
 const title='FICTIONAL browser ticket '+engine;
 await one.page.locator('#create-request').click();await one.page.locator('#create-dialog input[name="title"]').fill(title);
 await one.page.locator('#create-dialog textarea[name="description"]').fill('Fictional browser-created queue item; no real college work.');
 await one.page.locator('#request-building').selectOption('82000000-0000-4000-8000-000000000001');
 await one.page.locator('#create-submit').click();
 await one.page.waitForFunction(()=>!document.getElementById('create-dialog').open,null,{timeout:20000});
 await one.page.getByText(title,{exact:true}).first().waitFor({state:'visible'});
 const created=fixture('inspect');const ticket=created.graph.tickets.find(row=>row.title===title);assert.ok(ticket);assert.equal(ticket.status,'submitted');assert.equal(created.revision,before.revision+1);
 await two.page.reload();await two.page.getByText(title,{exact:true}).first().waitFor({state:'visible'});
 await one.page.reload();await one.page.getByText(title,{exact:true}).first().waitFor({state:'visible'});
 record({scenario:'new_ticket_saved_server_other_client_reload_and_creator_reload',status:'passed'});
 await one.page.locator(`[data-plan="${ticket.id}"]`).click();
 await one.page.locator('#job-next').click();await one.page.waitForFunction(()=>document.getElementById('job-project')?.options.length>0);
 await one.page.locator('#job-project').selectOption('83000000-0000-4000-8000-000000000001');
 await one.page.locator('#job-person').selectOption('p1');await one.page.locator('#job-building').selectOption('82000000-0000-4000-8000-000000000001');
 await one.page.locator('#job-next').click();await one.page.locator('#job-confirm').check();await one.page.locator('#job-save').click();
 await one.page.waitForFunction(()=>!document.getElementById('job-wizard').open,null,{timeout:20000});
 const assigned=fixture('inspect');const linked=assigned.graph.tickets.find(row=>row.id===ticket.id);assert.equal(linked.status,'assigned');assert.ok(linked.task_id);assert.equal(assigned.graph.tasks.find(row=>row.id===linked.task_id).assignee_id,'p1');
 currentPage=two.page;
 await two.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/roll_call`);
 const rollProject=two.page.locator('[data-roll-project-id="83000000-0000-4000-8000-000000000001"]');
 await rollProject.waitFor({state:'visible'});await rollProject.click();
 await two.page.locator('#detail').getByText(title,{exact:true}).first().waitFor({state:'visible'});
 assert.match(await two.page.locator('#detail').innerText(),/Fictional Alex/);
 record({scenario:'actual_move_assign_wizard_and_other_client_rollcall',status:'passed'});
 const authority=structuredClone(one.projection);
 const security=async(body,csrf)=>one.page.evaluate(async({url,body,csrf})=>{const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',...(csrf?{'X-CSRF-TOKEN':csrf}:{})},body:JSON.stringify(body)});return{status:r.status,body:await r.json(),cache:r.headers.get('Cache-Control')};},{url:authority.url,body,csrf});
 const missing=await security(authority.body,null);assert.equal(missing.status,419);assert.match(missing.cache,/no-store/);
 const wrong=structuredClone(authority.body);wrong.binding.source_hash='f'.repeat(64);assert.equal((await security(wrong,authority.csrf)).status,409);
 assert.equal(fixture('inspect').revision,assigned.revision);
 record({scenario:'real_csrf_and_wrong_binding_refuse_without_work_mutation',status:'passed'});
 fixture('marker-remove');assert.equal((await security(authority.body,authority.csrf)).status,503);
 fixture('marker-mismatch');assert.equal((await security(authority.body,authority.csrf)).status,503);
 fixture('marker-restore');assert.equal((await security(authority.body,authority.csrf)).status,200);
 assert.equal(fixture('inspect').revision,assigned.revision);
 record({scenario:'fresh_http_actual_client_silo_removed_mismatched_marker_denial_and_exact_restore',status:'passed'});

 // BEGIN exact responsive comments case (simulated viewport, not physical mobile/Safari).
 currentPage=one.page;
 await one.page.setViewportSize({width:390,height:844});
 await one.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);
 await one.page.locator(`#request-list [data-open="${incoming}"]`).click();
 await one.page.locator('#shared-discussion').getByText(incomingNote,{exact:true}).waitFor({state:'visible'});
 const mobileDraft='FICTIONAL unsent responsive comment '+engine;
 const composer=one.page.locator('#shared-discussion-body'),mobilePost=one.page.locator('#shared-discussion-post');
 await composer.fill(mobileDraft);
 await one.page.waitForFunction(()=>document.getElementById('shared-discussion-post')?.disabled===false);
 for(const placement of ['center','side','center']){
  await one.page.locator(`[data-detail-placement="${placement}"]`).click();
  assert.equal(await one.page.locator('#work-drawer').getAttribute('data-placement'),placement);
  assert.equal(await composer.inputValue(),mobileDraft);
  await composer.scrollIntoViewIfNeeded();const inputBox=await composer.boundingBox();assert.ok(inputBox&&inputBox.width>0&&inputBox.x>=-1&&inputBox.x+inputBox.width<=391,'Composer must fit the390px viewport');
  await mobilePost.scrollIntoViewIfNeeded();const postBox=await mobilePost.boundingBox();assert.ok(postBox&&postBox.width>0&&postBox.x>=-1&&postBox.x+postBox.width<=391,'Post control must fit the390px viewport');
  assert.equal(await mobilePost.isEnabled(),true);
  assert.equal(await composer.evaluate(node=>node.closest('form').scrollWidth<=node.closest('form').clientWidth+1),true);
 }
 assert.equal(fixture('inspect').comment_receipt_rows,2,'Responsive unsent draft must not post a comment');
 await one.page.screenshot({path:resolve(evidence,'responsive-comments.png'),fullPage:true});
 record({scenario:'simulated390px_center_side_comment_controls_reachable_and_unsent_draft_retained',status:'passed',viewport:{width:390,height:844},physical_mobile:false});
 // END exact responsive comments case.
 const discussionUrl=originalComment.url.replace(/comment$/,'show');
 const workerSelection={...originalComment.body.selection,persona:'worker',person_id:'p1'};
 const worker=await postJson(one,discussionUrl,{binding:originalComment.body.binding,selection:workerSelection,target:{kind:'project',id:project},before:null});
 assert.equal(worker.status,200);assert.equal(worker.body.can_comment,false);
 const workerWrite=await postJson(one,originalComment.url,{...originalComment.body,selection:workerSelection,target:{kind:'project',id:project},request_id:crypto.randomUUID(),body:'FICTIONAL forbidden worker project comment'});
 assert.equal(workerWrite.status,403);
 const live=fixture('inspect'),currentProject=live.graph.projects.find(p=>p.id===project);
 const archived=await postJson(one,authority.url.replace(/projection$/,'commands'),{binding:authority.body.binding,selection:originalComment.body.selection,request_id:crypto.randomUUID(),expected_revision:live.revision,action:'project.archive',record_id:project,expected_record_version:currentProject.version,payload:{archived:true}},authority.csrf);
 assert.equal(archived.status,200);
 const archivedShow=await postJson(one,discussionUrl,{binding:originalComment.body.binding,selection:originalComment.body.selection,target:{kind:'project',id:project},before:null});
 assert.equal(archivedShow.status,200);assert.equal(archivedShow.body.can_comment,false);
 const archivedWrite=await postJson(one,originalComment.url,{...originalComment.body,target:{kind:'project',id:project},request_id:crypto.randomUUID(),expected_target_version:currentProject.version+1,expected_thread_revision:1,body:'FICTIONAL archived must not save'});
 assert.equal(archivedWrite.status,403);assert.equal(fixture('inspect').comment_receipt_rows,2);
 record({scenario:'actual_current_worker_role_and_archived_target_refuse_comment_without_new_receipt',status:'passed'});
 fixture('revoke',seed.version_id);
 assert.equal((await security(authority.body,authority.csrf)).status,410);
 const revokedDiscussion=await postJson(one,discussionUrl,{binding:originalComment.body.binding,selection:originalComment.body.selection,target:originalComment.body.target,before:null});assert.equal(revokedDiscussion.status,410);
 const response=await two.page.reload();assert.equal(response.status(),410);assert.equal(await two.page.locator('#create-request').count(),0);
 const after=fixture('inspect');assert.equal(after.ledger_rows,before.ledger_rows);assert.equal(after.users,before.users);assert.equal(after.memberships,before.memberships);
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(blockers,[],'All collected UI assertions must pass; none are waived.');
 record({scenario:'actual_version_revocation_denies_both_clients_preserving_identity_and_budget',status:'passed'});
 }
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,scenario:directoryMode?'directory':'comments_plus_original_core6',status:'passed',synthetic:true,results,posts,commentResponses,errors,external},null,2));
 console.log(JSON.stringify({engine,status:'passed',scenarios:results.length}));
}catch(error){
 let state=null;try{const value=fixture('inspect');state={revision:value.revision,ledger_rows:value.ledger_rows,projects:value.graph.projects.length,tickets:value.graph.tickets.filter(row=>row.title==='FICTIONAL browser ticket '+engine).map(row=>({id:row.id,title:row.title,status:row.status,task_id:row.task_id}))};}catch{}
 await currentPage?.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'failed',results,posts,responses,state,errors,external,blockers,message:error.message,ui_status:await currentPage?.locator('#status').innerText().catch(()=>null),ribbon:await currentPage?.locator('.reviewer-boundary').innerText().catch(()=>null)},null,2));
 throw error;
 }finally{
 try{await browser?.close();}
 finally{
  try{if(proxy){proxy.closeAllConnections();await new Promise(done=>proxy.close(()=>done()));}}
  finally{
   try{
    if(server&&server.exitCode===null&&server.signalCode===null){
     const exit=once(server,'exit').catch(()=>{});server.kill('SIGTERM');
     await Promise.race([exit,new Promise(done=>setTimeout(done,3000))]);
     if(server.exitCode===null&&server.signalCode===null){server.kill('SIGKILL');await Promise.race([exit,new Promise(done=>setTimeout(done,3000))]);}
    }
   }finally{
    await writeFile(resolve(evidence,'server.log'),serverLog);await rm(runtime,{recursive:true,force:true});
    await writeFile(resolve(evidence,'cleanup.json'),JSON.stringify({runtime_removed:true,php_exit_code:server?.exitCode,php_signal:server?.signalCode,private_fixture_only:true},null,2));
   }
  }
 }
}
