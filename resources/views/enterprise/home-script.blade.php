  let homeGeneration = 0, homeArea = null, briefingBusy = false, briefingPending = null, executiveView = null;
  document.addEventListener('enterprise:workspace-state',event=>{if(!event.detail?.loaded){executiveView?.destroy();executiveView=null;if($('executive-demo'))$('executive-demo').hidden=true;}});
  function renderHome() {
    const executiveRoot = $('executive-demo');
    if (transport.mode === 'sample' && data.demo_persona === 'executive' && section === 'home' && executiveRoot) {
      homeGeneration++;homeArea=null;
      const config={...data.executive,onNavigate:target=>{document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:target}));return {available:true};}};
      if(executiveView)executiveView.update(config);else executiveView=EverbranchExecutiveDemo.mount(executiveRoot,config);
      $('campus-home').hidden=true;$('work').hidden=true;$('contractors').hidden=true;$('ask').hidden=true;
      $('heading').textContent='Campus operations, at a glance.';$('breadcrumb').textContent='Executive overview';$('home-nav').textContent='Executive overview';$('home-nav').hidden=false;
      $('create-request').hidden=true;$('create-project').hidden=true;rememberUrl();return;
    }
    if(executiveRoot){executiveView?.destroy();executiveView=null;executiveRoot.hidden=true;}
    $('home-nav').textContent='Campus overview';
    $('home-nav').hidden = !data.features?.briefing;
    if (section === 'home' && !data.features?.briefing) section = 'requests';
    $('campus-home').hidden = section !== 'home';
    if (section !== 'home') {homeGeneration++; homeArea=null; return;}
    $('work').hidden = true; $('contractors').hidden=true; $('ask').hidden=true;
    $('heading').textContent='A campus moving together.'; $('breadcrumb').textContent='Campus overview';
    $('create-request').hidden=true; $('create-project').hidden=true;
    if (!area) {$('briefing-body').textContent='Choose an authorized workspace to see its current work.';return;}
    $('briefing-pdf').href=transport.href(urls.briefingPdf+'?tenant='+encodeURIComponent(tenantSlug)+'&area_id='+encodeURIComponent(area));
    if (homeArea !== area) {homeArea=area;loadBriefing(false);}
    rememberUrl();
  }
  async function loadBriefing(withAI) {
    if (briefingBusy) return;
    const chosenArea=area, token=++homeGeneration;
    briefingBusy=true;$('briefing-refresh').disabled=true;$('briefing-ai').disabled=true;
    $('briefing-asof').textContent=withAI?'Requesting a current, authorized summary…':'Checking current project data…';
    if(withAI) {briefingPending=readDraft('briefing:'+chosenArea).pending||{area_id:chosenArea,request_id:crypto.randomUUID()};writeDraft('briefing:'+chosenArea,{pending:briefingPending});}
    try {
      const result=await api(urls.briefing+(withAI?'/refresh':'')+'?tenant='+encodeURIComponent(tenantSlug)+(withAI?'':'&area_id='+encodeURIComponent(chosenArea)),withAI?briefingPending:undefined);
      if(token!==homeGeneration||area!==chosenArea||section!=='home')return;
      if(withAI && result.ai_request?.status !== 'uncertain') {briefingPending=null;writeDraft('briefing:'+chosenArea,{});}
      $('briefing-asof').textContent=`As of ${result.as_of} · ${result.scope.department} · ${result.sample_data?'SAMPLE DATA':'Current authorized records'}`;
      const c=result.counts;
      $('briefing-body').innerHTML=`<div class="briefing-hero"><div class="eyebrow">${esc(result.label)}</div><h2>${esc(result.summary.headline)}</h2><p class="subtle">${esc(result.summary.text)}</p><p class="caption">${esc(result.summary.model_status)}</p>${(result.summary.priority_items||[]).map(p=>`<p><a href="${esc(transport.href(p.url))}">${esc(p.title)}</a><br><span class="caption">${esc(p.reason)}</span></p>`).join('')}</div><div class="briefing-counts">${[['Projects',c.projects],['Tasks in view',c.tasks],['Completed',c.completed_tasks],['Overdue',c.overdue_tasks]].map(([label,count])=>`<article><strong>${esc(count)}</strong><span class="caption">${esc(label)}</span></article>`).join('')}</div><div class="briefing-grid"><section class="panel"><div class="eyebrow">PROJECTS IN PROCESS</div><h2>Work with a next step.</h2>${result.projects.map(p=>`<article class="briefing-project"><h3><a href="${esc(transport.href(p.url))}">${esc(p.title)}</a></h3><p class="caption">${esc(p.status)} · ${p.completed_tasks}/${p.tasks} authorized tasks complete · ${p.overdue_tasks} overdue</p><progress aria-label="Authorized task completion" max="${Math.max(1,p.tasks)}" value="${p.completed_tasks}"></progress></article>`).join('')||'<p class="subtle">No projects in the checked authorized records.</p>'}</section><section class="panel"><div class="eyebrow">REQUESTS & CONNECTIONS</div><h2>Keep the handoff visible.</h2>${result.requests.map(r=>`<article class="briefing-project"><a href="${esc(transport.href(r.url))}">${esc(r.title)}</a><p class="caption">${esc(r.status)} · ${esc(r.due_on||'No date recorded')}</p></article>`).join('')||'<p class="subtle">No open requests in the checked sample.</p>'}<h3>Integration readiness</h3><div id="briefing-integrations"><p class="caption">Checking configured connections…</p></div></section></div><p class="caption">${esc(result.coverage)}</p><p class="caption">${esc(result.snapshot_notice)}</p>`;
      const connections=await api(urls.enterprise+'/integrations?tenant='+encodeURIComponent(tenantSlug)+'&area_id='+encodeURIComponent(chosenArea));
      if(token!==homeGeneration||area!==chosenArea||section!=='home')return;
      $('briefing-integrations').innerHTML=Object.entries(connections).filter(([key])=>key!=='as_of').map(([key,value])=>`<div class="briefing-status"><strong>${esc(key==='ai'?'Ask Everbranch':key)}</strong><p class="caption">${esc(value.status||value.reason||'Configuration checked; live acceptance pending.')}</p></div>`).join('');
      $('briefing-ai').dataset.ready=String(connections.ai.ready);
    } catch(e) {
      if(e.status===409 && !/uncertain|pending/i.test(e.message)){briefingPending=null;writeDraft('briefing:'+chosenArea,{});}
      if(token===homeGeneration&&area===chosenArea)$('briefing-asof').textContent=e.message+(withAI?' Retry uses the same request key; unknown provider outcomes are not resent.':'');
    } finally {briefingBusy=false;$('briefing-refresh').disabled=false;$('briefing-ai').disabled=false;}
  }
  $('briefing-refresh').onclick=()=>loadBriefing(false);
  $('briefing-ai').onclick=()=>loadBriefing(true);
