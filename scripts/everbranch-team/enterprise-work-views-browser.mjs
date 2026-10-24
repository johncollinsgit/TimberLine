import assert from 'node:assert/strict';
import {resolve} from 'node:path';
import {writeFile} from 'node:fs/promises';

/** Real isolated native HTTP; saved-building links, calendar dates and bookings remain distinct. */
export async function checkWorkViews(page,evidence) {
  const tenant='synthetic-enterprise';
  const api=async(path,body)=>{
    const result=await page.evaluate(async({path,body,tenant})=>{const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{method:'POST',body:JSON.stringify(body)}:{})});return {status:response.status,viewer:response.headers.get('X-Team-Viewer'),body:await response.json()};},{path,body,tenant});
    assert.equal(result.status,200,JSON.stringify(result.body));return result;
  };
  const made=await api('/enterprise/commands',{action:'workspace.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,title:'Synthetic month and map workspace',visibility:'department_only'}),area=made.body.id,owner=made.viewer;
  const command=async body=>(await api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,area_id:area,...body})).body;
  const resource=async body=>(await api('/enterprise/resources/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,area_id:area,...body})).body;
  const facility=await resource({action:'location.save',title:'Official source reference',catalog_map_number:'52',level:'facility'});
  const project=await command({action:'project.create',title:'Synthetic month project'});
  let request=await command({action:'request.create',title:'Synthetic mapped due task',due_on:'2026-11-03',location_id:facility.id,location_version:facility.version});
  request=await command({action:'request.triage',id:request.id,expected_version:request.version,note:'Reviewed synthetic intake'});
  await command({action:'request.assign',id:request.id,expected_version:request.version,project_id:project.id,assignee_id:owner});
  await command({action:'request.create',title:'Synthetic unscheduled ticket',location:'Bryan Hall'});
  const navigate=async(section,extra={})=>{await page.goto(new URL('/?'+new URLSearchParams({tenant,area_id:area,section,month:'2026-11',...extra}),page.url()).href);};
  await navigate('projects',{view:'calendar'});
  await page.locator('#request-list [data-date="2026-11-03"] [data-open]').filter({hasText:'Synthetic mapped due task'}).waitFor();
  assert.equal(await page.locator('#request-list .month-cell').count(),42);
  assert.match(await page.locator('#request-list [data-date="2026-11-03"]').innerText(),/Fixture Owner/);
  await page.locator('#request-list [data-month-step="1"]').click();assert.match(await page.locator('#work-month-title').innerText(),/December 2026/);assert.equal(new URL(page.url()).searchParams.get('month'),'2026-12');
  const backRead=page.waitForResponse(response=>new URL(response.url()).pathname==='/enterprise/data');await page.goBack();await(await backRead).finished();await page.locator('#work-month-title').filter({hasText:'November 2026'}).waitFor();
  await page.locator('#request-list [data-month-picker]').evaluate(input=>{input.value='2026-10';input.dispatchEvent(new Event('change',{bubbles:true}));});await page.locator('#work-month-title').filter({hasText:'October 2026'}).waitFor();
  await page.locator('#request-list [data-month-step="1"]').click();
  await page.locator('#request-list [data-date="2026-11-03"] [data-open]').click();await page.locator('#work-drawer[open]').waitFor();await page.getByRole('button',{name:'Close',exact:true}).click();
  await page.screenshot({path:resolve(evidence,'work-month-projects-desktop.png'),fullPage:true});
  const debugDto=(await api('/enterprise/data?area_id='+area)).body;await writeFile(resolve(evidence,'native-work-map-dto.json'),JSON.stringify(debugDto,null,2));
  await page.locator('[data-view="map"]').click();
  await page.locator('#map-markers [data-map-building="52"]').waitFor();
  assert.equal(await page.locator('#map-markers [data-map-building]').count(),1);
  await page.locator('#map-markers [data-map-building="52"]').click();assert.match(await page.locator('#campus-map-tickets').innerText(),/Synthetic mapped due task/);
  assert.equal(await page.locator('#campus-work-plan').evaluate(image=>image.complete&&image.naturalWidth===1700),true);
  await page.locator('#campus-map-tickets [data-open]').first().click();await page.locator('#work-drawer[open]').waitFor();await page.getByRole('button',{name:'Close',exact:true}).click();
  await page.screenshot({path:resolve(evidence,'work-campus-map-desktop.png'),fullPage:true});
  for(const [label,width,height]of[['tablet',820,1180],['phone',390,844]]){await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,label+' map overflow');const pin=await page.locator('#map-markers [data-map-building="52"]').boundingBox();assert.ok(pin.width>=44&&pin.height>=44);await page.screenshot({path:resolve(evidence,'work-campus-map-'+label+'.png'),fullPage:true});}
  await page.setViewportSize({width:1440,height:1000});
  const other=(await api('/enterprise/commands',{action:'workspace.create',id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,title:'Synthetic other map workspace',visibility:'department_only'})).body.id;
  const crossStatus=await page.evaluate(async({other,facility,tenant})=>{const response=await fetch('/enterprise/commands?tenant='+tenant,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({action:'request.create',area_id:other,id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,title:'Forbidden cross-scope relation',location_id:facility.id,location_version:facility.version})});return response.status;},{other,facility,tenant});assert.ok([403,404,422].includes(crossStatus),'Cross-workspace building rebinding must refuse');
  let releaseScope,sawScope;const heldScope=new Promise(resolve=>{releaseScope=resolve;}),startedScope=new Promise(resolve=>{sawScope=resolve;});
  const scopePattern=/\/enterprise\/data(?:\?|$)/,holdScope=async route=>{if(new URL(route.request().url()).searchParams.get('area_id')!==other)return route.continue();sawScope();await heldScope;await route.continue();};await page.route(scopePattern,holdScope);
  try{await page.evaluate(other=>document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{area:other,section:'requests',view:'map'}})),other);await Promise.race([startedScope,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Scope transition did not start')),15000))]);assert.doesNotMatch(await page.locator('#campus-map-tickets').innerText(),/Synthetic mapped due task/);assert.equal(await page.locator('#map-markers button').count(),0);releaseScope();await page.waitForFunction(()=>document.getElementById('status')?.textContent==='');assert.equal(await page.locator('#map-markers button').count(),0);}finally{releaseScope();await page.unroute(scopePattern,holdScope);}
  // Await the actual destination before a new full navigation tears down its requests.
  await page.locator('#workspace-settings-open').waitFor({state:'visible'});await page.waitForLoadState('networkidle');
  await navigate('requests',{view:'calendar'});await page.locator('#request-list .month-cell').first().waitFor();
  if(!await page.locator('.month-unscheduled').evaluate(node=>node.open))await page.getByText('Unscheduled (1)',{exact:true}).click();assert.match(await page.locator('.month-unscheduled-list').innerText(),/Synthetic unscheduled ticket/);
  await navigate('buildings',{selected:'catalog:52'});await page.locator('#res-tickets [data-building-ticket]').filter({hasText:'Synthetic mapped due task'}).waitFor();assert.doesNotMatch(await page.locator('#res-tickets').innerText(),/Ticket links are not established/);
  await page.locator('#res-tickets [data-building-ticket]').click();assert.match(await page.locator('#res-detail').innerText(),/Synthetic mapped due task/);
  await page.screenshot({path:resolve(evidence,'building-actual-tickets-desktop.png'),fullPage:true});
  const room=await resource({action:'location.save',title:'Synthetic month room',level:'room',parent_id:facility.id});
  await resource({action:'reservation.save',title:'Synthetic DST full day',resource_id:room.id,starts_at:'2026-11-01T04:00:00Z',ends_at:'2026-11-02T05:00:00Z',category:'event'});
  await navigate('calendar');await page.locator('#res-list .month-cell').first().waitFor();await page.locator('#res-list [data-date="2026-11-01"] [data-res-open]').filter({hasText:'Synthetic DST full day'}).waitFor();
  assert.equal(await page.locator('#res-list .month-cell').count(),42);
  assert.equal(await page.locator('#res-list [data-date="2026-11-02"] [data-res-open]').count(),0,'Half-open midnight end must not occupy the next Eastern day');
  await page.locator('#res-list [data-date="2026-11-01"] [data-res-open]').click();assert.match(await page.locator('#res-detail').innerText(),/2026-11-02T05:00:00Z/);
  for(const [label,width,height]of[['desktop',1440,1000],['tablet',820,1180],['phone',390,844]]) {
    await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,label+' resource month overflow');await page.screenshot({path:resolve(evidence,'resource-month-'+label+'.png'),fullPage:true});
  }
  await page.locator('#res-list [data-month-step="1"]').click();await page.locator('#resource-month-title').filter({hasText:'December 2026'}).waitFor();await page.reload();await page.locator('#resource-month-title').filter({hasText:'December 2026'}).waitFor();
  assert.equal(await page.locator('#res-list .month-cell').count(),42,'An empty month still renders the full month');
  await page.setViewportSize({width:1440,height:1000});
  await writeFile(resolve(evidence,'work-views-acceptance.json'),JSON.stringify({native_http:true,engine:process.env.ENTERPRISE_BROWSER_ENGINE,project_task_due_date_and_assignee:true,previous_next_picker_url_history:true,task_opens_project_dialog:true,official_plan_natural_width:1700,current_explicit_saved_building_marker:true,free_text_not_mapped:true,cross_scope_saved_link_refused:true,map_clears_during_authorized_scope_transition:true,building_tickets_visible:true,sidebar_resource_month:true,half_open_eastern_dst_booking:true,empty_month_grid:true,viewports:[390,820,1440],provider_calls:0},null,2));
}
