  let usageView=null,usageGeneration=0,usageLoadedScope=null,usageDepartment=null;
  function clearUsage(){usageGeneration++;usageLoadedScope=null;usageDepartment=null;usageView?.destroy();usageView=null;$('enterprise-usage-overview').replaceChildren();$('enterprise-usage-overview').hidden=true;$('enterprise-usage-tools').hidden=true;}
  function readUsage(department=null){
    usageDepartment=department;usageView?.destroy();usageView=null;$('enterprise-usage-overview').hidden=false;$('enterprise-usage-overview').textContent='Checking currently authorized usage records.';$('enterprise-usage-tools').hidden=false;$('usage-all').hidden=!department;
    const generation=++usageGeneration;
    transport.request(`${nextBase}/ai-usage${nextQuery()}${department?'&area_id='+encodeURIComponent(department):''}`).then(usage=>{
      if(generation!==usageGeneration||section!=='usage'||!data.features?.ai_usage)return;
      usageView=createUsageOverview($('enterprise-usage-overview'),{usage,onDepartment:id=>readUsage(id)});
    }).catch(error=>{if(generation===usageGeneration){$('enterprise-usage-overview').replaceChildren();$('enterprise-usage-overview').textContent=error.message;}});
  }
  $('usage-all').onclick=()=>readUsage();$('usage-refresh').onclick=()=>readUsage(usageDepartment);
  document.addEventListener('enterprise:workspace-state',event=>{if(!event.detail?.loaded){clearUsage();resetVoice();}});
  document.addEventListener('enterprise:render',()=>{
    const enabled=transport.mode!=='sample'&&!!data.features?.ai_usage; $('usage-nav').hidden=!enabled;
    if(section!=='usage'||!enabled){clearUsage();return;}
    for(const id of ['work','campus-home','executive-demo','ask','contractors'])if($(id))$(id).hidden=true;
    $('create-request').hidden=true;$('create-project').hidden=true;$('heading').textContent='Understand workspace usage.';$('breadcrumb').textContent='AI & voice usage';
    $('enterprise-usage-overview').hidden=false;
    const scope=JSON.stringify([workspace,data.viewer_id,area]);if(usageLoadedScope===scope)return;
    clearUsage();usageLoadedScope=scope;readUsage();
  });
