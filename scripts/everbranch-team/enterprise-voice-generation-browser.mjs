import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';

/** Real HTTP kernel, auth, CSRF, history, reservations and previews; provider and speech devices alone are simulated. */
export async function checkVoiceGeneration(page,evidence) {
  const tenant='synthetic-enterprise', turns=[], previews=[], cancellations=[], posts=[];
  const api=(path,body,csrf=true)=>page.evaluate(async({path,body,csrf,tenant})=>{
    const url=new URL(path,location.origin);url.searchParams.set('tenant',tenant);
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json',...(csrf?{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}:{})},...(body?{method:'POST',body:JSON.stringify(body)}:{})});
    return {status:response.status,viewer:response.headers.get('X-Team-Viewer'),data:await response.json().catch(()=>null)};
  },{path,body,csrf,tenant});
  const command=async body=>{const result=await api('/enterprise/commands',{id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,...body});assert.equal(result.status,200,JSON.stringify(result.data));return result;};
  const workspace=await command({action:'workspace.create',title:'Synthetic voice generation department',visibility:'department_only'});
  const area=workspace.data.id, owner=workspace.viewer;
  const secondArea=(await command({action:'workspace.create',title:'Synthetic separate voice scope',visibility:'department_only'})).data.id;
  const project=(await command({action:'project.create',area_id:area,title:'Synthetic voice generation project'})).data.id;
  const title='Synthetic voice generation plumbing';
  let request=(await command({action:'request.create',area_id:area,title,description:'Fictional provider acceptance fixture only.',location:'Synthetic recorded workshop entrance'})).data;
  request=(await command({action:'request.triage',id:request.id,area_id:area,expected_version:request.version,note:'Synthetic triage'})).data;
  request=(await command({action:'request.assign',id:request.id,area_id:area,expected_version:request.version,project_id:project,assignee_id:owner})).data;
  const task=request.task.id;
  const configured=await api('/enterprise/operations/commands',{action:'task.configure',task_id:task,request_id:crypto.randomUUID(),expected_version:0,expected_task_version:request.task.version,owner_user_id:owner,ball_user_id:owner,ball_team:false,next_action:'Inspect the synthetic plumbing fixture',blockers:[],checklist:[{id:'inspect',label:'Inspect synthetic fixture',weight:1}]});
  assert.equal(configured.status,200,JSON.stringify(configured.data));
  const beforeTask=(await api('/enterprise/operations/tasks/'+task)).data;
  await page.addInitScript(()=>{
    window.generationCaptures=[];window.generationSpeeches=[];
    window.SpeechRecognition=class{constructor(){generationCaptures.push(this)}start(){this.onstart?.()}abort(){this.onend?.()}result(text){const row=[{transcript:text}];row.isFinal=true;this.onresult?.({results:[row]});this.onend?.()}};
    window.SpeechSynthesisUtterance=class{constructor(text){this.text=text}};
    Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{cancel(){},speak(value){generationSpeeches.push(value)}}});
  });
  const target=new URL(page.url());target.pathname='/';target.search=new URLSearchParams({tenant,area_id:area,section:'ask'}).toString();
  await page.goto(target.href);
  const controls=page.locator('#ask-voice-controls');
  await controls.getByRole('button',{name:'Talk',exact:true}).waitFor();
  const capture=request=>{if(request.method()==='POST')posts.push({path:new URL(request.url()).pathname,csrf:!!request.headers()['x-csrf-token']});};
  page.on('request',capture);
  try {
    assert.equal(await page.evaluate(()=>generationCaptures.length),0,'Opening the pane never starts speech recognition');
    const denied=await api('/enterprise/voice/turns',{area_id:area,session_id:crypto.randomUUID(),request_id:crypto.randomUUID(),question:'Show '+title},false);
    assert.equal(denied.status,419,'The actual HTTP kernel must reject a missing CSRF token');
    const anonymous=await page.context().browser().newContext({ignoreHTTPSErrors:true});
    try {const result=await anonymous.request.get(new URL('/enterprise/ai-usage?tenant='+tenant,page.url()).href,{headers:{Accept:'application/json'}});assert.equal(result.status(),401,'Ledger aggregates require a signed-in user');}
    finally {await anonymous.close();}
    const usageBefore=await api('/enterprise/ai-usage?area_id='+area);assert.equal(usageBefore.status,200);assert.equal(usageBefore.data.totals.requests,0);
    const turnResponse=()=>page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).pathname.endsWith('/enterprise/voice/turns'));
    const accept=async response=>{
      const result=await response.json();assert.equal(response.status(),200,JSON.stringify(result));
      assert.equal(result.mode,'generated');assert.equal(result.provider_simulated,true);assert.equal(result.provider_dispatch,true);
      assert.equal(result.usage.state,'simulated_estimate');assert.equal(result.usage.feature,'voice_assistant');assert.equal(result.usage.provider_invoice_reconciled,false);
      assert.equal(result.usage.input_tokens,10);assert.equal(result.usage.output_tokens,5);assert.equal(result.usage.reserved_microusd,1900);assert.equal(result.usage.cost_microusd,4);
      const body=response.request().postDataJSON();assert.equal(result.session_id,body.session_id);assert.equal(result.request_id,body.request_id);
      assert.equal(result.scope.area_id,area);assert.equal(result.scope.user_id,owner);assert.equal(body.history,undefined,'Client history is never supplied as authority');
      const row={body,result};turns.push(row);return row;
    };
    const typed=async question=>{const response=turnResponse();await page.locator('#ask-question').fill(question);await page.locator('#ask-submit').click();const row=await accept(await response);await page.waitForFunction(()=>!document.getElementById('ask-submit').disabled);return row;};
    const first=await typed('Show '+title);
    assert.equal(first.result.outcome,'matched');await page.locator('#ask-job-detail h3').waitFor();
    assert.equal(await page.locator('#ask-job-detail h3').innerText(),title);
    assert.match(first.result.generated_text,/Prior messages: 0/);
    const second=await typed('Where is it?');
    assert.equal(second.body.session_id,first.body.session_id,'Typed follow-up reuses the server session');
    assert.equal(second.result.outcome,'answered');assert.match(second.result.generated_text,/Prior messages: 2/);
    assert.match(second.result.generated_text,/Synthetic recorded workshop entrance/);
    await page.locator('#chat-log').getByRole('link',{name:'Source job: '+title,exact:true}).waitFor();
    assert.equal(await page.locator('#chat-log .chat-turn:not(.question)').count(),2);
    const replay=await api('/enterprise/voice/turns',second.body);assert.equal(replay.status,200);assert.equal(replay.data.replayed,true);assert.equal(replay.data.usage.receipt_id,second.result.usage.receipt_id);
    const spokenResponse=turnResponse();await controls.getByRole('button',{name:'Talk',exact:true}).click();
    await page.evaluate(()=>generationCaptures.at(-1).result('Where is it?'));
    const spoken=await accept(await spokenResponse);assert.equal(spoken.body.session_id,first.body.session_id);assert.match(spoken.result.generated_text,/Prior messages: 4/);
    await controls.getByRole('button',{name:'Interrupt reply',exact:true}).waitFor();await page.waitForFunction(()=>generationSpeeches.length===1);
    const stopResponse=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith(`/voice/sessions/${first.body.session_id}/cancel`));
    await controls.getByRole('button',{name:'Stop',exact:true}).click();const stopped=await stopResponse;assert.equal(stopped.status(),200);cancellations.push(await stopped.json());

    for(const [channel,question,to]of [['email','Draft an email update','reviewer@example.invalid'],['sms','Draft an SMS update','+12025550123']]) {
      const draft=await typed(question);assert.equal(draft.result.outcome,'draft_proposed');assert.equal(draft.result.draft_proposal.channel,channel);assert.equal(draft.result.draft_proposal.to,null);
      assert.equal(draft.result.draft_proposal.delivery_ready,false);
      await controls.getByLabel('Exact draft recipient',{exact:true}).waitFor();assert.equal(await controls.getByLabel('Exact draft recipient',{exact:true}).inputValue(),'');
      assert.match(await controls.innerText(),/no recipient selected/);
      await controls.getByLabel('Exact draft recipient',{exact:true}).fill(to);
      const previewResponse=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).pathname.endsWith('/enterprise/voice/previews'));
      await controls.getByRole('button',{name:'Preview draft',exact:true}).click();const response=await previewResponse;const preview=await response.json();assert.equal(response.status(),200,JSON.stringify(preview));
      assert.deepEqual(Object.keys(response.request().postDataJSON()).sort(),['area_id','preview_id','session_id','to','turn_id']);
      assert.equal(preview.to,to);assert.equal(preview.body,draft.result.draft_proposal.body);assert.equal(preview.subject,draft.result.draft_proposal.subject);
      assert.equal(preview.delivery_ready,false);assert.equal(preview.can_confirm,false);assert.equal(preview.recipient_verified,false);assert.equal(preview.status,'preview_only');assert.match(preview.digest,/^[a-f0-9]{64}$/);
      await controls.locator('pre').filter({hasText:'Exact '+channel+' preview'}).waitFor();
      assert.match(await controls.innerText(),/Nothing was sent/);assert.equal(await controls.getByRole('button',{name:/^send/i}).count(),0);
      const exact=response.request().postDataJSON();const again=await api('/enterprise/voice/previews',exact);assert.equal(again.status,200);assert.equal(again.data.replayed,true);assert.equal(again.data.digest,preview.digest);
      const changed=await api('/enterprise/voice/previews',{...exact,to:channel==='email'?'other@example.invalid':'+12025550124'});assert.equal(changed.status,409,'An exact preview identifier cannot be reused for another recipient');
      previews.push({channel,to,preview_id:preview.id,digest:preview.digest,status:preview.status});
      for(const [name,width,height]of [['phone',390,844],['ipad-portrait',820,1180],['ipad-landscape',1180,820]]) {
        await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,`${channel} preview overflow at ${name}`);
        assert.equal(await controls.getByRole('button',{name:'Preview draft',exact:true}).evaluate(element=>element.getBoundingClientRect().height>=44),true,`${name}: preview touch target`);
        await page.screenshot({path:resolve(evidence,`voice-generation-${channel}-${name}.png`),fullPage:true});
      }
    }
    await page.setViewportSize({width:1440,height:1000});
    async function lateReply(changeArea) {
      let begin,release,delivered;const began=new Promise(resolve=>{begin=resolve;}),held=new Promise(resolve=>{release=resolve;}),done=new Promise(resolve=>{delivered=resolve;});let body,result,intercepted=false;
      const routePattern='**/enterprise/voice/turns?*';
      await page.route(routePattern,async route=>{if(intercepted)return route.continue();intercepted=true;body=route.request().postDataJSON();const response=await route.fetch();result=await response.json();assert.equal(response.status(),200,JSON.stringify(result));begin();await held;await route.fulfill({response}).catch(()=>{});delivered();});
      try {
        await page.locator('#ask-question').fill('Show '+title);await page.locator('#ask-submit').click();
        let timer;try{await Promise.race([began,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Held generation did not begin')),30000);})]);}finally{clearTimeout(timer);}
        assert.equal(result.provider_simulated,true);assert.equal(result.mode,'generated');
        const cancelled=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith(`/voice/sessions/${body.session_id}/cancel`));
        if(changeArea){await page.locator(`#areas [data-area="${secondArea}"]`).click();await page.locator(`#areas [data-area="${secondArea}"].active`).waitFor();}
        else await page.locator('[data-section=projects]').click();
        const cancellation=await cancelled;assert.equal(cancellation.status(),200);assert.equal(cancellation.request().postDataJSON().area_id,area);cancellations.push(await cancellation.json());
        release();await done;await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert.equal(await page.locator('#ask-job-detail h3').count(),0,'Late reply cannot reopen prior job evidence');
        assert.equal(await page.locator('#chat-log .chat-turn').count(),0,'Departed/changed scope cannot display prior conversation');
        const rejected=await api('/enterprise/voice/turns',{...body,request_id:crypto.randomUUID()});assert.equal(rejected.status,409,'Real server session stays cancelled');
        turns.push({body,result,discarded_after_scope_change:true});
      } finally {release();await page.unroute(routePattern);}
      if(!changeArea){await page.locator('[data-section=ask]').click();await controls.getByRole('button',{name:'Talk',exact:true}).waitFor();}
    }
    await lateReply(false);
    await lateReply(true);
    const usage=await api('/enterprise/ai-usage?area_id='+area);assert.equal(usage.status,200);
    const receipts=new Set(turns.map(row=>row.result.usage.receipt_id));assert.equal(receipts.size,7);
    assert.equal(usage.data.totals.requests,receipts.size);assert.equal(usage.data.totals.completed,receipts.size);assert.equal(usage.data.totals.input_tokens,receipts.size*10);assert.equal(usage.data.totals.output_tokens,receipts.size*5);assert.equal(usage.data.totals.settled_usage_derived_microusd,receipts.size*4);assert.equal(usage.data.provider_invoice_verified,false);
    const purpose=usage.data.departments.flatMap(row=>row.purposes).find(row=>row.purpose==='voice_answer');assert.ok(purpose);assert.match(purpose.label,/voice/i);assert.equal(purpose.totals.requests,receipts.size);
    const aggregate=JSON.stringify(usage.data);for(const secret of [title,'Where is it?','reviewer@example.invalid','+12025550123','synthetic-browser-fixture-not-a-credential','Synthetic draft only.'])assert.equal(aggregate.includes(secret),false,'Usage aggregates must not contain '+secret);
    assert.doesNotMatch(aggregate,/"history"\s*:|"provider_id"\s*:|"api_key"\s*:/);
    await page.locator('#usage-nav').click();await page.locator('#enterprise-usage-summary').waitFor();assert.match(await page.locator('#enterprise-usage-overview').innerText(),/Voice answer/i);
    for(const [name,width,height]of [['phone',390,844],['ipad-portrait',820,1180],['ipad-landscape',1180,820]]){await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,`Voice usage overflow at ${name}`);await page.screenshot({path:resolve(evidence,`voice-generation-usage-${name}.png`),fullPage:true});}
    const after=await api('/enterprise/operations/tasks/'+task);assert.equal(after.status,200);assert.deepEqual(after.data,beforeTask,'Generation and previews cannot change task records');
    assert.equal(posts.filter(row=>/\/(?:send|dispatch)(?:\/|$)/.test(row.path)).length,0,'No send/dispatch endpoint is called');
    assert.equal(posts.filter(row=>/\/enterprise\/voice\//.test(row.path)&&row.csrf).length>0,true);
    await writeFile(resolve(evidence,'voice-generation-verification.json'),JSON.stringify({provider_simulated:true,real_kernel:true,real_reservation_ledger:true,typed_session:first.body.session_id,history_lengths:[0,2,4],speech_input_simulated:true,preview_only:previews,cancellations,usage:usage.data,no_task_mutation:true,no_delivery:true,viewport_simulation_only:true},null,2));
    console.log('PASS generated voice HTTP: provider-only fake, real auth/CSRF/ledger/history, typed follow-up and spoken transition, exact human email/SMS previews, immutable replay, cancellation and late-scope fencing, privacy-filtered Voice answer usage, phone/tablet layouts; no sends or paid calls.');
  } finally {page.off('request',capture);}
}
