import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';

// This tests the public browser simulation, including real response policy.
// Simulated fetch calls below are intercepted by the demo's local adapter.
// Playwright independently records and blocks every actual network mutation.
const url=process.env.DEMO_URL||'http://127.0.0.1:4332/platform/demos/lees-mcrae';
const target=new URL(url);
// Set DEMO_EVIDENCE explicitly to keep review artifacts outside the checkout.
const evidence=resolve(process.env.DEMO_EVIDENCE||'../hosting-evidence/local');
const playwright=process.env.DEMO_PLAYWRIGHT_MODULE
 ?await import(pathToFileURL(process.env.DEMO_PLAYWRIGHT_MODULE).href)
 :await import('playwright');
const {chromium}=playwright;
await mkdir(evidence,{recursive:true});
const browser=await chromium.launch({headless:true,...(process.env.DEMO_BROWSER_EXECUTABLE?{executablePath:process.env.DEMO_BROWSER_EXECUTABLE}:{})});
const context=await browser.newContext({viewport:{width:1440,height:1080}});
const checks=[],errors=[],consoleErrors=[],violations=[],unexpectedNetwork=[],network=[];
await context.addInitScript(()=>{
 globalThis.__campusPolicyViolations=[];
 document.addEventListener('securitypolicyviolation',e=>globalThis.__campusPolicyViolations.push({directive:e.violatedDirective,blocked:e.blockedURI}));
});
function observe(page){page.setDefaultTimeout(10000);page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});}
await context.route('**/*',async route=>{
 const req=route.request(),u=new URL(req.url());
 if(u.protocol==='data:'||u.protocol==='blob:'){await route.continue();return;}
 const allowed=req.method()==='GET'&&u.origin===target.origin&&[target.pathname,'/favicon.ico'].includes(u.pathname);
 network.push({method:req.method(),url:u.origin+u.pathname,allowed});
 if(allowed)await route.continue();else{unexpectedNetwork.push({method:req.method(),url:u.href});await route.abort();}
});
const page=await context.newPage();observe(page);
const check=(ok,name)=>{assert.ok(ok,name);checks.push(name);console.log(`ok ${checks.length} - ${name}`);};
async function api(path,body,view=page){return view.evaluate(async({path,body})=>{const s=await(await fetch('/api/state')).json();const r=await fetch('/api/'+path,body?{method:'POST',headers:{'Content-Type':'application/json','X-Demo-CSRF':s.csrf},body:JSON.stringify(body)}:{});return{status:r.status,data:await r.json()};},{path,body});}
async function state(view=page){const r=await api('state',undefined,view);assert.equal(r.status,200);return r.data;}
async function persona(value){assert.equal((await api('persona',{persona:value})).status,200);await page.reload();await page.locator('#persona').waitFor();return state();}
async function home(){await page.locator('nav [data-go="home"]').click();await page.locator('#job-select').waitFor();}
async function requests(){await page.locator('nav [data-go="intake"]').click();await page.getByRole('heading',{name:'Requests',exact:true}).waitFor();}
async function operations(){await page.locator('nav [data-go="operations"]').click();await page.locator('#operations-workspace').waitFor();await page.locator('[data-ops-tab="jobs"]').click();}
async function act(action,item,fields={},key=crypto.randomUUID()){return api('operations',{action,id:item.id,expected_version:item.version||1,operation_key:key,...fields});}
const noMoney=j=>['materials','unit_cost_cents','material_total_cents','labor_rate_cents','labor_total_cents','contractor_total_cents','total_cents','budget_cents','variance_cents'].every(k=>!(k in j));
try{
 const response=await page.goto(url);assert.ok(response);check(response.status()===200,'Approved campus demo GET route returns 200');
 const headers=response.headers(),csp=headers['content-security-policy']||'';
 check(/connect-src\s+'none'/.test(csp)&&/form-action\s+'none'/.test(csp),'Response policy prohibits network connections and form submissions');
 check(/script-src[^;]*'nonce-[^']+'/.test(csp)&&!/script-src[^;]*'unsafe-inline'/.test(csp),'Executable scripts require a response-specific CSP nonce');
 check(/img-src[^;]*data:/.test(csp)&&/frame-ancestors\s+'none'/.test(csp)&&(/object-src\s+'none'/.test(csp)||/default-src\s+'none'/.test(csp)&&!/(?:^|;)\s*object-src\s/.test(csp)),'CSP permits embedded imagery and rejects frames and plugin objects');
 check((headers['x-robots-tag']||'').includes('noindex')||await page.locator('meta[name="robots"][content*="noindex"]').count()>0,'Public demo is marked noindex');
 await page.locator('#persona').waitFor();
 check(await page.locator('script').evaluateAll(s=>s.every(el=>!el.src&&!!el.nonce)),'Every embedded script has a nonce and no remote script source');
 check(await page.locator('style').evaluateAll(s=>s.every(el=>!!el.nonce)),'Every embedded stylesheet has a response nonce');
 check(/demo|prototype/i.test(await page.locator('.demo-ribbon').textContent()),'Fictional browser demonstration boundary is prominent');
 await api('reset',{});await page.reload();await page.locator('#persona').waitFor();
 await page.locator('#campus-map .leaflet-image-layer').waitFor();check(await page.locator('#campus-map .leaflet-image-layer').evaluate(i=>i.complete&&i.naturalWidth>1000&&i.src.startsWith('data:')),'Official campus basemap loads from embedded image data at useful resolution');
 await page.screenshot({path:resolve(evidence,'01-campus-map.png'),fullPage:true});
 let data=await persona('admin'),ops=data.operations;
 const lifecycle=data.requests.filter(r=>r.seed_source==='fictional-campus-request-lifecycle-v1');
 check(lifecycle.length===14&&['Facilities','Housing','Athletics','Events','Technology Services'].every(d=>lifecycle.some(r=>r.department===d)),'Fourteen lifecycle examples cover the requested campus departments');
 check(['new','awaittriage','queued','assigned','inprogress','blocked','awaitapproval','completed'].every(s=>lifecycle.some(r=>r.stage===s)),'Fictional Requests cover all eight lifecycle stages');
 check(ops.jobs.length>=10&&ops.projects.some(p=>p.phase==='upcoming'),'Operations includes multiple job types and upcoming projects');
 for(const j of ops.jobs){const materials=j.materials.reduce((n,m)=>n+Math.round(m.quantity_hundredths*m.unit_cost_cents/100),0);assert.equal(j.material_total_cents,materials);assert.equal(j.total_cents,materials+j.labor_total_cents+j.contractor_total_cents);assert.equal(j.variance_cents,j.budget_cents-j.total_cents);}
 for(const p of ops.projects){assert.equal(p.estimated_total_cents,ops.jobs.filter(j=>j.project_id===p.id).reduce((n,j)=>n+j.total_cents,0));assert.equal(p.variance_cents,p.budget_cents-p.estimated_total_cents);}
 check(true,'Material, labor, contractor, project and budget variance arithmetic agrees');
 await home();await page.locator('#job-select').selectOption('J03');const quantity=page.locator('#job-cost-form [name="qty0"]');const original=await quantity.inputValue(),pending=String(Number(original)+2.5);await quantity.fill(pending);await page.locator('#map-toggle').click();
 check(await page.locator('#map-toggle').textContent()==='View Map'&&await quantity.inputValue()===pending&&await page.locator('#job-select').inputValue()==='J03','Hide Map preserves selected job and unsaved quantity');
 await page.locator('#map-toggle').click();check(await page.locator('#map-toggle').textContent()==='Hide Map'&&await quantity.inputValue()===pending,'View Map restores the map without replacing unsaved inputs');
 await page.locator('#job-select').selectOption('J05');await page.waitForFunction(()=>document.querySelector('#campus-job-info img')?.complete&&document.querySelector('#campus-job-info img')?.naturalWidth>0);
 check(await page.locator('#campus-job-info img').count()===1&&/CC0 source/.test(await page.locator('.job-stock-photo figcaption').textContent()),'Pipe repair displays its locally embedded attributed construction stock photo');
 for(const id of ['J01','J02','J09']){await page.locator('#job-select').selectOption(id);check(await page.locator('#campus-job-info img, #campus-job-info .job-story svg').count()===0,`${id} has no unrelated photo or diagram placeholder`);}
 await page.locator('#job-select').selectOption('J02');data=await state();const job=data.jobs.find(j=>j.id==='J02'),beforeStyle=await page.locator('[title^="J02:"]').getAttribute('style');
 const materials=job.materials.map((m,i)=>({id:m.id,quantity_hundredths:m.quantity_hundredths+(i===0?100:0),unit_cost_cents:m.unit_cost_cents}));
 let result=await api('job',{id:job.id,expected_version:job.version,x:job.x+.02,y:job.y,labor_minutes:job.labor_minutes+60,labor_rate_cents:job.labor_rate_cents,materials});check(result.status===200,'Demo admin can save browser-only map locations and estimates');
 await page.reload();await page.locator('#job-select').selectOption('J02');data=await state();const updated=data.jobs.find(j=>j.id==='J02');
 check(updated.x===job.x+.02&&await page.locator('[title^="J02:"]').getAttribute('style')!==beforeStyle,'Saved fictional location persists across reload and moves the map pin');
 check(await page.locator('#job-estimate-total').textContent()===new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(updated.total_cents/100),'Map detail total agrees with the browser estimate projection');
 data=await persona('staff');check(data.jobs.length>0&&data.jobs.every(j=>j.assignee_id==='staff'&&noMoney(j)),'Staff receives its assigned jobs without financial fields');
 check(data.people.staff.title.toLowerCase().includes('crew')||data.operations.workers.find(w=>w.id==='staff')?.department==='Events','Staff perspective follows the campus crew and Events narrative');
 result=await api('job',{id:'J01',expected_version:1,x:.5});check(result.status===403,'Browser simulation rejects staff location edits');
 result=await act('priority',data.operations.jobs[0],{priority:1});check(result.status===403,'Browser simulation rejects staff priority changes');
 await requests();check(await page.locator('#intake-form').count()===0,'Requests opens as a workspace with a modal New Request action');
 await page.locator('#new-request').click();const requestTitle='Hosted QA fictional crew request';
 await page.locator('#intake-form [name="title"]').fill(requestTitle);await page.locator('#intake-form [name="type"]').selectOption('facilities');await page.locator('#intake-form [name="department"]').selectOption('Facilities');await page.locator('#intake-form [name="location_id"]').selectOption('J03');await page.locator('#intake-form [name="urgency"]').selectOption('urgent');await page.locator('#intake-form [name="due"]').fill('2026-10-28');await page.locator('#intake-form [name="details"]').fill('Fictional QA example for a campus crew; no real incident.');await page.locator('#intake-form').getByRole('button',{name:'Submit demo request'}).click();await page.locator('#modal').waitFor({state:'hidden'});
 data=await state();const request=data.requests.find(r=>r.title===requestTitle);check(request?.location_id==='J03'&&request.type==='facilities'&&request.urgency==='urgent','Request submission persists its type, urgency and location in browser state');
 await page.locator('[data-request-layout="calendar"]').click();check(await page.locator(`[data-request="${request.id}"]`).count()===1,'Calendar shows the same submitted request on its requested date');
 await page.locator('[data-request-layout="map"]').click();check(await page.locator(`#requests-map [title="${requestTitle}"]`).count()===1,'Request map shows the same submitted record');await page.screenshot({path:resolve(evidence,'02-request-map.png'),fullPage:true});
 await page.reload();await page.locator('#persona').waitFor();check((await state()).requests.some(r=>r.id===request.id),'Submitted request persists through page reload');
 // Same browser context, independent tab: no copying via window.open/opener.
 const fresh=await context.newPage();observe(fresh);await fresh.goto(url);await fresh.locator('#persona').waitFor();const freshState=await state(fresh);
 check(freshState.persona==='leader'&&!freshState.requests.some(r=>r.title===requestTitle)&&freshState.jobs.find(j=>j.id==='J02').version===1,'A fresh browser tab starts with independent seed state');
 violations.push(...await fresh.evaluate(()=>globalThis.__campusPolicyViolations));await fresh.close();
 await persona('admin');await requests();await page.locator('[data-request-layout="list"]').click();await page.locator(`[data-request="${request.id}"]`).click();await page.locator('#request-triage').click();await page.locator('#review-form [name="project"]').selectOption('residence');await page.locator('#review-form [name="priority"]').selectOption('urgent');await page.locator('#review-form [name="assignee_id"]').selectOption('manager');await page.locator('#review-form').getByRole('button',{name:'Confirm assignment'}).click();
 await page.waitForFunction(async id=>(await(await fetch('/api/state')).json()).requests.find(r=>r.id===id)?.status==='Assigned',request.id);data=await state();const assignedRequest=data.requests.find(r=>r.id===request.id);
 check(data.tasks.some(t=>t.id===assignedRequest.task_id)&&data.jobs.some(j=>j.task_id===assignedRequest.task_id),'Admin triage links the same request to a task and map job');await page.locator('#modal').evaluate(e=>e.close());
 await requests();const requestCount=data.requests.length,jobCount=data.jobs.length;await page.locator('#demo-incoming').click();await page.waitForFunction(async()=>(await(await fetch('/api/state')).json()).requests.some(r=>r.request_key==='campus-demo-incoming-dorm-v1'));await page.locator('#demo-incoming').click();data=await state();const incoming=data.requests.find(r=>r.request_key==='campus-demo-incoming-dorm-v1');
 check(data.requests.length===requestCount+1&&incoming.job_id==='J05','Repeated incoming shortcut creates exactly one request linked to existing J05');
 result=await api('review',{id:incoming.id,project:'residence',priority:'urgent',assignee_id:'manager'});check(result.status===200,'Admin triages the controlled Dorm request');data=await state();const taskCount=data.tasks.length;
 result=await api('review',{id:incoming.id,project:'residence',priority:'urgent',assignee_id:'manager'});data=await state();check(result.status===200&&data.jobs.length===jobCount&&data.tasks.length===taskCount&&data.jobs.find(j=>j.id==='J05').phase==='active','Dorm review reuses J05 without duplicate tasks or jobs');
 ops=data.operations;let dorm=ops.jobs.find(j=>j.id==='J05'),firstDate=dorm.projected_completion_on;result=await act('capacity',ops.workers.find(w=>w.id==='manager'),{capacity_minutes_per_day:60});data=await state();
 check(result.status===200&&data.operations.jobs.find(j=>j.id==='J05').projected_completion_on>firstDate,'Worker capacity changes transparently recalculate estimated completion dates');
 ops=data.operations;const signup=ops.projects.find(p=>p.id==='visit'),key=crypto.randomUUID(),roster={participant_ids:['staff','manager']};check((await act('signup',signup,roster,key)).status===200&&(await act('signup',signup,roster,key)).status===200,'Multiple project sign-ups replay safely');data=await state();check(data.operations.projects.find(p=>p.id==='visit').participant_ids.length===2,'Repeated sign-up does not duplicate participants');
 await page.reload();await page.locator('nav [data-go="contractors"]').click();await page.getByRole('heading',{name:'Contractor assignments',exact:true}).waitFor();check(await page.locator('[data-ops-job="J09"]').count()===1&&await page.locator('[data-ops-job="J10"]').count()===1,'Direct Contractors navigation shows both jobs to Demo admin');await page.screenshot({path:resolve(evidence,'03-contractors-admin.png'),fullPage:true});
 data=await persona('manager');check(data.jobs.every(j=>j.department==='Facilities'&&Array.isArray(j.materials)),'Manager projection is restricted to Facilities with authorized estimates');await home();await page.locator('#job-select').selectOption('J03');await page.locator('#job-linked-task').click();const comment='Hosted QA fictional crew comment; no real dispatch.';await page.locator('#comment-form [name="body"]').fill(comment);await page.locator('#comment-form').getByRole('button',{name:'Add comment',exact:true}).click();await page.waitForFunction(async note=>Object.values((await(await fetch('/api/state')).json()).tasks.find(t=>t.id==='r1').comments).some(c=>c.body===note),comment);await page.reload();data=await state();check(Object.values(data.tasks.find(t=>t.id==='r1').comments).some(c=>c.body===comment),'Browser task discussion persists through reload');
 data=await persona('leader');check(data.jobs.length>=10&&data.jobs.every(j=>'total_cents'in j&&!('materials'in j)&&!('labor_rate_cents'in j)&&!('assignee_id'in j)),'Leadership receives cross-department summaries without line costs or staffing identity');
 data=await persona('it');check(data.jobs.length>=3&&data.jobs.every(j=>j.type==='it'&&noMoney(j)),'IT projection includes simulated tickets without financial fields');let ticket=data.operations.jobs.find(j=>j.id==='J07');result=await act('ticket_update',ticket,{urgency:'urgent',status:'done',resolution:'Fictional hosted QA resolution.'});check(result.status===200&&(await state()).jobs.find(j=>j.id==='J07').phase==='done','IT can resolve its assigned simulated ticket');result=await act('ticket_update',{id:'J02',version:updated.version},{urgency:'normal',status:'done',resolution:'Not own assignment'});check(result.status===403,'IT cannot resolve another worker’s ticket');
 data=await persona('contractor');check(data.jobs.length>0&&data.jobs.every(j=>j.assignee_id==='contractor'&&'agreed_cost_cents'in j&&!('budget_cents'in j)&&!('labor_rate_cents'in j)),'Contractor projection exposes only its own agreed scope');await page.locator('nav [data-go="contractors"]').click();await page.getByRole('heading',{name:'Contractor assignments',exact:true}).waitFor();check(await page.locator('[data-ops-job="J09"]').count()===1&&await page.locator('[data-ops-job="J10"]').count()===0,'Contractor view excludes the other contractor’s job');result=await act('complete',{id:'J10',version:1},{logged_minutes:60});check(result.status===403,'Contractor cannot complete another contractor’s work');ticket=data.operations.jobs.find(j=>j.id==='J09');check((await act('complete',ticket,{logged_minutes:360})).status===200,'Assigned contractor can record fictional completion');await page.screenshot({path:resolve(evidence,'04-contractor-scope.png'),fullPage:true});
 data=await persona('staff');const own=data.operations.jobs.find(j=>j.phase==='active');check((await act('complete',own,{logged_minutes:180})).status===200,'Staff can complete its own fictional active work');check((await state()).operations.workers.find(w=>w.id==='staff').status==='Ready to Assign','Completing final active assignment moves staff to Ready to Assign');
 await persona('leader');await home();await page.locator('#start-tour').click();await page.locator('.tour-panel').waitFor();await page.locator('#tour-next').click();await page.waitForFunction(()=>document.querySelector('#persona')?.value==='staff');check(await page.locator('.tour-panel').count()===1,'Guided tour advances and switches role within the browser demo');await page.locator('.tour-close').click();
 await persona('admin');await operations();await page.locator('[data-ops-tab="reports"]').click();await page.screenshot({path:resolve(evidence,'05-cost-report.png'),fullPage:true});
 violations.push(...await page.evaluate(()=>globalThis.__campusPolicyViolations));
 check(violations.length===0,'Real hosted CSP permits the rendered demo with zero policy violations');check(errors.length===0,'All hosted demo interactions have zero page JavaScript errors');check(consoleErrors.length===0,'No browser console errors occur');check(unexpectedNetwork.length===0,'No external or unapproved network request is attempted');check(network.every(r=>r.method==='GET'),'No actual POST or other network mutation occurs');
 const report={passed:checks.length,checks,errors,consoleErrors,violations,unexpectedNetwork,network,boundary:'Hosted fictional browser simulation only; no native engine, server authorization, tenant writes or provider calls'};await writeFile(resolve(evidence,'campus-hosted-demo.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
}catch(error){await page.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});await writeFile(resolve(evidence,'failure.json'),JSON.stringify({error:error.message,checks,errors,consoleErrors,violations,unexpectedNetwork,network},null,2));throw error;}
finally{await context.close();await browser.close();}
