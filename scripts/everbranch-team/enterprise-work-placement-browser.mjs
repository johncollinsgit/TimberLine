import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';

/** Same native dialog and real isolated HTTP. Only the upload timing is delayed. */
export async function checkWorkPlacement(page,evidence,{area,requestId}) {
  const drawer=page.locator('#work-drawer'),body=page.locator('#discussion-body');
  await body.waitFor();
  assert.equal(await drawer.getAttribute('data-placement'),'center');
  const centered=await drawer.boundingBox(),viewport=page.viewportSize();
  assert.ok(centered.width>1000 && Math.abs(centered.x+centered.width/2-viewport.width/2)<2);
  await body.fill('Retained unsent draft while changing placement.');
  await page.evaluate(()=>{globalThis.detailPlacementNodes={dialog:document.getElementById('work-drawer'),body:document.getElementById('discussion-body'),files:document.getElementById('discussion-photo-picker')};});
  let release,seen;
  const gate=new Promise(resolve=>{release=resolve;}),started=new Promise(resolve=>{seen=resolve;});
  const pattern=/\/file-uploads\/[^/]+\/chunks\/\d+(?:\?|$)/;
  const delay=async route=>{seen();await gate;await route.continue();};
  await page.route(pattern,delay);
  try {
    await page.locator('#discussion-photo-picker').setInputFiles({name:'synthetic-placement-photo.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=','base64')});
    await Promise.race([started,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Native photo chunk did not begin')),15000))]);
    await page.getByRole('button',{name:'Side',exact:true}).click();
    assert.equal(await drawer.getAttribute('data-placement'),'side');
    assert.equal(await body.inputValue(),'Retained unsent draft while changing placement.');
    assert.deepEqual(await page.evaluate(()=>({sameDialog:detailPlacementNodes.dialog===document.getElementById('work-drawer'),sameBody:detailPlacementNodes.body===document.getElementById('discussion-body'),sameFiles:detailPlacementNodes.files===document.getElementById('discussion-photo-picker'),file:document.getElementById('discussion-photo-picker').files[0]?.name})),{sameDialog:true,sameBody:true,sameFiles:true,file:'synthetic-placement-photo.png'});
    const side=await drawer.boundingBox();assert.ok(side.width<=720 && Math.abs(side.x+side.width-viewport.width)<2);
    await page.getByRole('button',{name:'Center',exact:true}).click();
    assert.equal(await body.inputValue(),'Retained unsent draft while changing placement.');
    release();
    await page.waitForFunction(()=>document.getElementById('discussion-files')?.textContent.includes('synthetic-placement-photo.png'));
    assert.match(await page.locator('#discussion-files').innerText(),/quarantined/);
    assert.equal(await body.inputValue(),'Retained unsent draft while changing placement.');
    assert.equal(await page.locator('#discussion-files a').count(),0,'A larger modal must not expose quarantined file bytes');
  } finally {release();await page.unroute(pattern,delay);}
  await page.getByRole('button',{name:'Post note',exact:true}).click();
  await page.waitForFunction(()=>document.getElementById('discussion-feed')?.textContent.includes('Retained unsent draft while changing placement.'));
  await page.getByRole('button',{name:'Side',exact:true}).click();
  await page.reload();
  await page.locator('#discussion-body').waitFor();
  assert.equal(await drawer.getAttribute('data-placement'),'side');
  assert.match(await page.locator('#discussion-feed').innerText(),/Retained unsent draft/);
  assert.match(await page.locator('#discussion-files').innerText(),/synthetic-placement-photo.png/);
  const stored=await page.evaluate(()=>Object.entries(localStorage).filter(([key])=>key.startsWith('everbranch-enterprise:detail-placement:')));
  assert.equal(stored.length,1);assert.equal(stored[0][1],'side');
  await page.getByRole('button',{name:'Center',exact:true}).click();
  await page.screenshot({path:resolve(evidence,'ticket-detail-center-desktop.png'),fullPage:true});
  // Same setting applies to an assigned request. Closing preserves the searched list.
  await page.getByRole('button',{name:'Close',exact:true}).click();
  const target=new URL(page.url());target.searchParams.set('section','requests');target.searchParams.set('area_id',area);target.searchParams.set('selected',requestId);target.searchParams.set('q','Synthetic Roll Call');
  await page.goto(target.href);await body.waitFor();
  assert.equal(await drawer.getAttribute('data-placement'),'center');
  await body.fill('Unsent request draft retained through Side, Center and Escape.');
  await page.getByRole('button',{name:'Side',exact:true}).click();
  await page.getByRole('button',{name:'Center',exact:true}).click();
  assert.equal(await body.inputValue(),'Unsent request draft retained through Side, Center and Escape.');
  await page.keyboard.press('Escape');assert.equal(await drawer.getAttribute('open'),null);
  assert.equal(await page.locator('#search').inputValue(),'Synthetic Roll Call');
  await page.locator('#work-status').selectOption('active');
  await page.getByRole('button',{name:'Synthetic Roll Call source task',exact:true}).click();
  await body.waitFor();assert.equal(await body.inputValue(),'Unsent request draft retained through Side, Center and Escape.');
  for(const width of [390,768,1024]) {
    await page.setViewportSize({width,height:850});
    const bounds=await drawer.boundingBox();assert.ok(bounds.x>=-1 && bounds.x+bounds.width<=width+1);
    assert.equal(await page.locator('#work-drawer').evaluate(node=>node.scrollWidth<=node.clientWidth+1),true);
    for(const label of ['Center','Side','Close']){const box=await page.getByRole('button',{name:label,exact:true}).boundingBox();assert.ok(box.height>=38 && box.x>=0 && box.x+box.width<=width);}
  }
  await page.setViewportSize({width:390,height:850});await page.screenshot({path:resolve(evidence,'ticket-detail-center-phone.png'),fullPage:true});
  await page.setViewportSize(viewport);
  await writeFile(resolve(evidence,'ticket-placement-acceptance.json'),JSON.stringify({native_http:true,engines:process.env.ENTERPRISE_BROWSER_ENGINE,center_default:true,same_dom_during_toggle:true,native_photo_quarantine_reload:true,native_comment_reload:true,request_draft_retained:true,value_only_scoped_preference:true,viewports:[390,768,1024,1440],provider_calls:0},null,2));
  // Restore the project selected by the calling Roll Call scenario.
  await page.getByRole('button',{name:'Close',exact:true}).click();
}
