import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {createServer} from 'node:https';
import {request as httpRequest} from 'node:http';
import {once} from 'node:events';
import {mkdir,writeFile,readFile,rm,chmod} from 'node:fs/promises';
import {resolve,dirname} from 'node:path';
import {pathToFileURL} from 'node:url';

// Real loopback Enterprise kernel, private SQLite/file sessions, two independent browser cookies.
// Only exact Responses is fake; all application HTTP/CSRF/authority and browser controls are real. No paid/provider authority.
const root=resolve('.'),engine=process.env.PROPOSAL_BROWSER_ENGINE||'chromium';
assert.ok(['chromium','webkit'].includes(engine));
const php=process.env.ENTERPRISE_TEST_PHP;
assert.ok(php,'ENTERPRISE_TEST_PHP must pin a local PHP executable');
const {chromium,webkit}=await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE||'node_modules/playwright/index.mjs')).href);
const parent=dirname(root),runtime=resolve(parent,`runtime-${Date.now()}`),origin='https://127.0.0.1:9029';
const evidence=resolve(process.env.PROPOSAL_BROWSER_EVIDENCE||`../evidence-ai-${engine}-${Date.now()}`);
await mkdir(runtime,{mode:0o700});
for(const directory of ['storage/framework/testing','storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs','storage/app/private','storage/app/team-private','bootstrap/cache'])await mkdir(resolve(runtime,directory),{recursive:true,mode:0o700});
await chmod(runtime,0o700);await writeFile(resolve(runtime,'.env'),'',{mode:0o600});
await writeFile(resolve(runtime,'storage/framework/testing/enterprise-browser-reviewer-workspace.sqlite'),'',{mode:0o600});
await mkdir(evidence,{recursive:true,mode:0o700});
const env={PATH:process.env.PATH,APP_ENV:'testing',ENTERPRISE_RUNTIME_ROOT:runtime,REVIEWER_WORKSPACE_BROWSER:'1',REVIEWER_WORKSPACE_AI_BROWSER:'1',REVIEWER_WORKSPACE_BROWSER_ORIGIN:origin};
const fixture=(...args)=>{const result=spawnSync(php,['tests/Support/proposal-reviewer-workspace-browser-fixture.php',...args],{env,encoding:'utf8',windowsHide:true});assert.equal(result.status,0,result.stdout+result.stderr);try{return JSON.parse(result.stdout);}catch{throw Error('Fictional local fixture did not return JSON: '+result.stdout.slice(0,4000));}};
let server,proxy,browser,serverLog='',currentPage,serial=Promise.resolve(),lastDispatch=0;
const external=[],errors=[],results=[],posts=[],blockers=[],responses=[];
const record=value=>{results.push(value);console.log(JSON.stringify({progress:value.scenario,status:value.status}));};
const aiFixture=(...args)=>{const r=spawnSync(php,['tests/Support/proposal-reviewer-workspace-ai-browser-fixture.php',...args],{env,encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);return JSON.parse(r.stdout);};
try{
 const seed=fixture('seed');assert.equal(seed.seed_projects,20);const setup=aiFixture('prepare-fixture',seed.version_id);

 const keyPath=resolve(runtime,'fixture.key'),certPath=resolve(runtime,'fixture.crt');
 const cert=spawnSync(process.env.ENTERPRISE_TEST_OPENSSL||'openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyPath,'-out',certPath,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],{encoding:'utf8'});
 assert.equal(cert.status,0,'Local synthetic certificate generation failed');
 const key=await readFile(keyPath),certificate=await readFile(certPath);await rm(keyPath);await rm(certPath);
 server=spawn(php,['-S','127.0.0.1:9028','tests/Support/proposal-reviewer-workspace-ai-browser-router.php'],{env,stdio:['ignore','pipe','pipe']});
 for(const stream of [server.stdout,server.stderr])stream.on('data',chunk=>{serverLog+=chunk;});
 proxy=createServer({key,cert:certificate},(req,res)=>{
  serial=serial.then(async()=>{
   await new Promise(done=>setTimeout(done,Math.max(0,lastDispatch+2150-Date.now())));
   if(req.destroyed||res.destroyed)return;lastDispatch=Date.now();
   await new Promise(done=>{let upstream,finished=false;const finish=()=>{if(finished)return;finished=true;req.off('aborted',abort);res.off('close',abort);done();};const abort=()=>{upstream?.destroy();finish();};
    upstream=httpRequest({hostname:'127.0.0.1',port:9028,path:req.url,method:req.method,headers:req.headers},response=>{if(res.destroyed){response.destroy();finish();return;}res.writeHead(response.statusCode,response.headers);response.pipe(res);response.once('end',finish);response.once('close',finish);});
    req.once('aborted',abort);res.once('close',abort);upstream.once('error',()=>{if(!res.destroyed){if(!res.headersSent)res.writeHead(503);res.end('Local fixture unavailable');}finish();});if(req.aborted||res.destroyed)abort();else if(req.readableEnded)upstream.end();else req.pipe(upstream);
   });
  });
 });proxy.listen(9029,'127.0.0.1');await once(proxy,'listening');
 browser=await(engine==='webkit'?webkit:chromium).launch({headless:true});

 const clients=[];
 async function client(){
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==origin){external.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
  const page=await context.newPage();currentPage=page;const item={context,page,authority:null};clients.push(item);
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{if(request.method()==='POST'&&(request.url().includes('/workspace/')||request.url().includes('/ai/'))){const body=request.postDataJSON();posts.push({action:new URL(request.url()).pathname.split('/').at(-1),keys:Object.keys(body)});if(request.url().endsWith('/workspace/projection'))item.authority={body,csrf:request.headers()['x-csrf-token']};}});
  await page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}`);
  await page.getByLabel('Proposal password').fill(seed.password);await page.getByRole('button',{name:'Open proposal',exact:true}).click();
  await page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/ask`);
  await page.locator('#proposal-ai-question').waitFor({state:'visible'});
  await page.waitForFunction(()=>!document.getElementById('proposal-ai-submit')?.disabled);
  assert.ok(item.authority);return item;
 }
 const one=await client(),two=await client();assert.notDeepEqual(await one.context.cookies(),await two.context.cookies());
 const initial=aiFixture('inspect');assert.equal(initial.ledger_count,1);assert.equal(initial.ledger_id,setup.ledger_id);assert.equal(initial.ledger.cap_microusd,5000000);assert.equal(initial.calls.length,0);assert.equal(initial.charges.length,0);assert.equal(initial.users,1);assert.equal(initial.memberships,1);
 record({scenario:'actual_shared_ai_render_two_unlocked_contexts_one_fictional_lifetime_ledger',status:'passed'});
 const endpoint=action=>`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/ask/ai/${action}`;
 const receipt=preview=>({binding:one.authority.body.binding,preview_id:preview.id,request_id:preview.request_id,digest:preview.digest,snapshot_digest:preview.snapshot_digest});
 async function post(item,action,body,csrf=item.authority.csrf){return item.page.evaluate(async({url,body,csrf})=>{const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json',...(csrf?{'X-CSRF-TOKEN':csrf}:{})},body:JSON.stringify(body)});return{status:response.status,body:await response.json(),cache:response.headers.get('Cache-Control')};},{url:endpoint(action),body,csrf});}
 async function prepare(item,question){
  currentPage=item.page;await item.page.locator('#proposal-ai-question').fill(question);
  const response=item.page.waitForResponse(r=>r.url()===endpoint('preview')&&r.request().method()==='POST');
  await item.page.locator('#proposal-ai-submit').click();const http=await response;const value=await http.json();
  responses.push({phase:'preview',status:http.status(),error:value.error||null});assert.equal(http.status(),200,JSON.stringify(value));
  const sent=http.request().postDataJSON();assert.equal('snapshot' in sent,false);assert.deepEqual(Object.keys(sent.workspace).sort(),['expected_revision','selection']);
  assert.equal(sent.workspace.expected_revision,value.workspace.pin.revision);assert.equal(value.snapshot.local_revision,value.workspace.pin.revision);assert.match(value.snapshot.coverage,/^Server-held shared fictional workspace\./);
  await item.page.locator('dialog.proposal-ai-preview').waitFor({state:'visible'});assert.match(await item.page.locator('dialog.proposal-ai-preview').innerText(),/shared \$5 lifetime trial allowance/);
  assert.ok(value.snapshot.sources.length>0);assert.ok(value.snapshot.sources.every(source=>source.title.startsWith('Fictional')||source.title.startsWith('FICTIONAL')));
  return {value,sent};
 }
 async function confirm(item,preview){
  const response=item.page.waitForResponse(r=>r.url()===endpoint('confirm')&&r.request().method()==='POST');
  await item.page.getByRole('button',{name:'Send once to OpenAI',exact:true}).click();const http=await response;const value=await http.json();
  responses.push({phase:'confirm',status:http.status(),outcome:value.status||value.error});assert.equal(http.status(),200,JSON.stringify(value));assert.equal(value.status,'accepted');assert.equal(value.digest,preview.digest);
  assert.ok(value.refs.every(ref=>preview.snapshot.sources.some(source=>source.ref===ref)));
  await item.page.locator('#proposal-ai-log').getByText('FICTIONAL local test answer from current saved shared work.',{exact:true}).last().waitFor({state:'visible'});
  return value;
 }
 const first=await prepare(one,'Which saved fictional projects need attention?');assert.equal(aiFixture('inspect').calls.length,0,'Sharing preview cannot dispatch a model.');
 await confirm(one,first.value);const firstReceipt=receipt(first.value);const afterFirst=aiFixture('inspect');assert.equal(afterFirst.calls.length,1);assert.equal(afterFirst.charges.length,1);assert.equal(afterFirst.calls[0].history_count,0);
 const replay=await post(one,'confirm',firstReceipt);assert.equal(replay.status,200);assert.equal(replay.body.status,'accepted');assert.equal(aiFixture('inspect').calls.length,1);assert.equal(aiFixture('inspect').charges.length,1);
 record({scenario:'real_manual_sharing_preview_confirm_once_current_source_answer_and_no_redispatch',status:'passed'});
 const ownTwo=await prepare(two,'Which fictional work can this independent reviewer inspect?');const twoReceipt={...receipt(ownTwo.value),binding:two.authority.body.binding};
 assert.equal((await post(two,'status',twoReceipt)).body.status,'prepared');assert.equal((await post(two,'status',firstReceipt)).status,404);assert.equal((await post(one,'status',twoReceipt)).status,404);
 assert.equal((await post(two,'cancel',twoReceipt)).body.status,'cancelled');await two.page.getByRole('button',{name:'Keep editing',exact:true}).click();assert.equal(aiFixture('inspect').calls.length,1);
 record({scenario:'independent_browser_receipts_cross_receipt_404_and_explicit_unclaimed_cancel',status:'passed'});
 const follow=await prepare(one,'And which of those should I inspect first?');assert.equal(follow.sent.history.length,1);assert.deepEqual(Object.keys(follow.sent.history[0]).sort(),['binding','digest','preview_id','request_id','snapshot_digest']);assert.deepEqual(follow.sent.history[0],firstReceipt);assert.equal('answer' in follow.sent.history[0],false);assert.equal(follow.value.history.length,1);
 assert.match(await one.page.locator('dialog.proposal-ai-preview').innerText(),/Previous turns included with this question/);
 await confirm(one,follow.value);const afterFollow=aiFixture('inspect');assert.equal(afterFollow.calls.length,2);assert.equal(afterFollow.calls[1].history_count,1);assert.deepEqual(afterFollow.calls[1].history_questions,['Which saved fictional projects need attention?']);assert.equal(afterFollow.ledger_id,setup.ledger_id);assert.equal(afterFollow.ledger_count,1);assert.equal(afterFollow.charges.length,2);
 record({scenario:'opaque_accepted_receipt_followup_resolved_server_side_and_explicitly_previewed',status:'passed'});
 const stale=await prepare(one,'Show the current fictional work once more.');const staleReceipt=receipt(stale.value);assert.equal(stale.sent.history.length,2);
 currentPage=two.page;await two.page.goto(`${origin}/enterprise-owner/service-proposals/${seed.token}/campus/requests`);await two.page.locator('#create-request').waitFor({state:'visible'});
 await two.page.locator('#create-request').click();await two.page.locator('#create-dialog input[name="title"]').fill('FICTIONAL other-client AI evidence change');await two.page.locator('#create-dialog textarea[name="description"]').fill('Fictional saved server edit for stale evidence check.');await two.page.locator('#request-building').selectOption('82000000-0000-4000-8000-000000000001');await two.page.locator('#create-submit').click();await two.page.waitForFunction(()=>!document.getElementById('create-dialog').open,null,{timeout:20000});
 const edited=aiFixture('inspect');assert.equal(edited.workspace_revision,initial.workspace_revision+1);
 currentPage=one.page;await one.page.getByRole('button',{name:'Send once to OpenAI',exact:true}).click();await one.page.locator('#proposal-ai-controls').getByText('The preview expired or the fictional records changed. Review the fields again before sending.',{exact:true}).waitFor({state:'visible'});
 assert.equal((await post(one,'confirm',staleReceipt)).status,409);
 const staleStatus=await post(one,'status',staleReceipt);assert.equal(staleStatus.status,200);assert.equal(staleStatus.body.content_expired,true);assert.equal('preview' in staleStatus.body,false);assert.equal('answer' in staleStatus.body,false);
 const expiredAnswer=await post(one,'status',firstReceipt);assert.equal(expiredAnswer.body.content_expired,true);assert.equal('answer' in expiredAnswer.body,false);
 const final=aiFixture('inspect');assert.equal(final.calls.length,2);assert.equal(final.charges.length,2);assert.deepEqual(final.charges,afterFollow.charges);assert.equal(final.ledger_id,initial.ledger_id);assert.equal(final.ledger_count,1);assert.equal(final.users,1);assert.equal(final.memberships,1);
 record({scenario:'other_client_saved_edit_invalidates_preview_and_withholds_stale_answer_without_charge_or_identity_bridge',status:'passed'});
 const bad={binding:one.authority.body.binding,request_id:crypto.randomUUID(),question:'FICTIONAL closed payload test',workspace:stale.sent.workspace,snapshot:stale.value.snapshot};assert.equal((await post(one,'preview',bad)).status,422);
 assert.equal((await post(one,'preview',stale.sent,null)).status,419);assert.equal(aiFixture('inspect').calls.length,2);
 record({scenario:'shared_mode_rejects_browser_facts_and_actual_missing_csrf_before_provider',status:'passed'});
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);
 await writeFile(resolve(evidence,'result.json'),JSON.stringify({engine,status:'passed',synthetic:true,results,posts,responses,provider_calls:final.calls.length,charges:final.charges.length,one_shared_cap_microusd:final.ledger.cap_microusd,errors,external},null,2));
 console.log(JSON.stringify({engine,status:'passed',scenarios:results.length}));

}catch(error){
 let state=null;try{const value=aiFixture('inspect');state={workspace_revision:value.workspace_revision,ledger_count:value.ledger_count,charges:value.charges.length,calls:value.calls.length};}catch{}
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
