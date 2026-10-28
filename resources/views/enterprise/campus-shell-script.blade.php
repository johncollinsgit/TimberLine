  let campusAssistantOpen=false,campusAssistantContext=null,campusSearchGeneration=0,campusShellSection=null,campusAssistantEpoch=0;
  const campusAssistantDrafts=new Map(),campusAskHome=$('ask').parentNode,campusAskAnchor=document.createComment('Existing Ask surface');
  campusAskHome.insertBefore(campusAskAnchor,$('ask'));
  const campusToolsHome=$('campus-shell-tools').parentNode,campusPanelHome=$('campus-assistant-panel').parentNode;
  function campusAssistantModalAllowed(){const dialogs=[...document.querySelectorAll('dialog[open]')];return !dialogs.length||dialogs.length===1&&dialogs[0]===$('work-drawer')&&$('work-drawer').open;}
  const campusActorKey=()=>JSON.stringify([transport.mode,workspace,viewer,String(data.viewer_id||data.viewer||viewer),area]);
  const campusNativeAvailable=()=>transport.mode==='private'&&!!area&&data.selected_area_id===area&&!!(data.features?.ask||data.features?.read_only_assistant||data.features?.conversation_ui);
  function campusAssistantContextValid(){return campusNativeAvailable()&&campusAssistantContext===campusActorKey();}
  function campusAssistantActive(){return campusAssistantOpen&&campusAssistantContextValid();}
  function campusAskActionAllowed(){return section==='ask'||campusAssistantActive();}
  function campusAskEpoch(){return campusAssistantEpoch;}
  function campusAssistantVisible(){return campusAssistantOpen&&campusAssistantActive()&&section!=='ask';}
  function retainCampusAssistantDraft(){
    if(!campusAssistantContext)return;
    const value=$('ask-question').value,oldArea=JSON.parse(campusAssistantContext)[4],inMemory=typeof askPending!=='undefined'?askPending:null,pendingDraft=inMemory?.area_id===oldArea?inMemory:readDraft('ask:'+oldArea).pending;
    if(value)campusAssistantDrafts.set(campusAssistantContext,value);
    else if(pendingDraft?.area_id===oldArea&&pendingDraft?.question)campusAssistantDrafts.set(campusAssistantContext,pendingDraft.question);
    else if(!(typeof conversationState!=='undefined'&&conversationState&&['waiting','restore','unconfirmed','clearing'].includes(conversationState.phase)))campusAssistantDrafts.delete(campusAssistantContext);
  }
  function retireCampusAssistantActions(){
    campusAssistantEpoch++;
    if(typeof readOnlyGeneration!=='undefined')readOnlyGeneration++;
    $('read-only-submit').disabled=false;
    if(typeof conversationGeneration!=='undefined')conversationGeneration++;
    if(typeof conversationBusy!=='undefined')conversationBusy=false;
    if(typeof conversationOperation!=='undefined')conversationOperation=null;
    if(typeof conversationState!=='undefined'&&conversationState&&['waiting','clearing'].includes(conversationState.phase)){
      conversationState.phase='unconfirmed';$('conversation-status').textContent='The original outcome is unconfirmed. Check its existing receipt before continuing.';
    }
    if(typeof savedAssistantGeneration!=='undefined')savedAssistantGeneration++;
    if(typeof savedAssistantBusy!=='undefined')savedAssistantBusy=false;
    if(typeof memoryGeneration!=='undefined')memoryGeneration++;
    if(typeof memoryBusy!=='undefined')memoryBusy=false;
    if(typeof askBusy!=='undefined')askBusy=false;
    // Stop the SAME UI/speech adapter. Its existing cancellation retains
    // any server-side unknown cost exposure; receipt/config intents stay intact.
    if(typeof voiceSession!=='undefined')voiceSession?.stop();
  }
  function closeCampusSearch({focus=false}={}){campusSearchGeneration++;$('campus-search-results-panel').hidden=true;$('campus-search-query').setAttribute('aria-expanded','false');if(focus&&!document.querySelector('dialog[open]'))$('campus-search-query').focus({preventScroll:true});}
  function closeCampusAssistant({focus=false}={}){
    const wasOpen=campusAssistantOpen;retainCampusAssistantDraft();campusAssistantOpen=false;$('campus-assistant-panel').hidden=true;$('campus-assistant-launcher').setAttribute('aria-expanded','false');
    // Use the existing exact-session stop; never clear a paid receipt or retry.
    if(wasOpen)retireCampusAssistantActions();
    if(section!=='ask')$('ask').hidden=true;
    if(focus&&campusAssistantModalAllowed())$('campus-assistant-launcher').focus({preventScroll:true});
  }
  function resetCampusShell(){retainCampusAssistantDraft();closeCampusSearch();closeCampusAssistant();campusAssistantContext=null;$('ask-question').value='';$('campus-search-results').replaceChildren();$('campus-search-coverage').textContent='';}
  function placeCampusAssistant(){
    const drawer=$('work-drawer'),dock=drawer.open?drawer:null;
    for(const [node,home]of[[$('campus-shell-tools'),campusToolsHome],[$('campus-assistant-panel'),campusPanelHome]])if(node.parentNode!==(dock||home))(dock||home).append(node);
    if(section==='ask'){campusAskAnchor.after($('ask'));closeCampusAssistant();$('ask').hidden=false;return;}
    if(campusAssistantContext&&!campusAssistantContextValid()){closeCampusAssistant();campusAssistantContext=null;}
    if(campusAssistantContextValid())$('campus-assistant-body').append($('ask'));
    $('ask').hidden=!campusAssistantVisible();
    $('campus-assistant-panel').hidden=!campusAssistantOpen;
    $('campus-assistant-launcher').setAttribute('aria-expanded',String(campusAssistantOpen));
    const name=data.areas?.find(row=>row.id===area)?.name||'Choose a current workspace';
    $('campus-assistant-context').textContent=transport.mode==='private'?name+' · Your current account and permissions':name+' · Existing reviewer tools';
    $('campus-assistant-unavailable').hidden=campusAssistantActive();
    $('campus-assistant-existing').hidden=!allowedSections.includes('ask')||transport.mode==='private'&&!campusNativeAvailable();
  }
  $('campus-assistant-launcher').onclick=()=>{
    if(!campusAssistantModalAllowed())return;
    if(campusAssistantOpen){closeCampusAssistant({focus:true});return;}
    if(section==='ask'){$('ask-question').focus();return;}
    closeCampusSearch();campusAssistantOpen=true;
    if(campusNativeAvailable()){
      campusAssistantContext=campusActorKey();renderReadOnlyAssistant();renderConversation();renderVoice();
      if(!$('ask-question').value&&!askBusy&&!(typeof conversationState!=='undefined'&&conversationState))$('ask-question').value=campusAssistantDrafts.get(campusAssistantContext)||readDraft('ask:'+area).pending?.question||'';
    }
    placeCampusAssistant();
    const target=campusAssistantActive()&&!$('generated-ask-panel').hidden?$('ask-question'):$('campus-assistant-close');target.focus({preventScroll:true});
  };
  $('campus-tour-launcher').onclick=()=>{const opener=document.querySelector('[data-tour-open]');if(!opener||opener.disabled||document.querySelector('dialog[open]'))return;closeCampusAssistant();closeCampusSearch();const dialog=document.querySelector('[data-tour-dialog]');dialog?.addEventListener('close',()=>{if(document.activeElement===opener&&!document.querySelector('dialog[open]'))$('campus-tour-launcher').focus({preventScroll:true});},{once:true});opener.click();};
  $('campus-assistant-close').onclick=()=>closeCampusAssistant({focus:true});
  $('campus-assistant-existing').onclick=()=>{closeCampusAssistant();document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:'ask',selected:null}}));};
  $('campus-search-close').onclick=()=>closeCampusSearch({focus:true});
  $('campus-search-query').oninput=()=>closeCampusSearch();
  function campusSearchLink(value){
    try{
      const href=transport.href(value),url=new URL(href,location.href),back=new URL(urls.back,location.href);
      if(url.origin!==location.origin||!['http:','https:'].includes(url.protocol)||url.username||url.password||url.pathname!==back.pathname)return null;
      const parameters=reviewerMode?new URLSearchParams(url.hash.slice(1)):url.searchParams;
      if(transport.mode==='private'&&parameters.get('tenant')!==tenantSlug)return null;
      const destination=parameters.get('section'),department=parameters.get('area_id');
      if(!allowedSections.includes(destination)||department&&!data.areas.some(row=>row.id===department))return null;
      return {href,parameters};
    }catch{return null;}
  }
  function sharedCampusMatches(query){
    const current=data,department=current.selected_area_id,items=[];
    const add=(kind,id,title,text,sectionName,selected=id)=>{
      if(items.length>=100||!String(title+' '+text).toLowerCase().includes(query.toLowerCase()))return;
      const target=new URL(location.href);target.search='';target.hash=new URLSearchParams({area_id:department,section:sectionName,selected}).toString();
      items.push({kind,title,excerpt:text,source_url:transport.href(target.href)});
    };
    for(const row of current.projects||[])add('project',row.id,row.name,row.description||'','projects');
    for(const row of current.requests||[])add('request',row.id,row.title,row.data?.description||'','requests');
    for(const row of current.tasks||[])if(row.project_id&&(current.projects||[]).some(project=>project.id===row.project_id))add('task',row.id,row.title,row.description||'','projects',row.project_id);
    return {items,coverage:'Up to 100 matches in this currently loaded, authorized shared department projection. Other departments, file contents and unsupported records were not searched.'};
  }
  async function campusSearchSubmit(event){
    event.preventDefault();const query=$('campus-search-query').value.trim().slice(0,150);if(!query)return;
    const generation=++campusSearchGeneration,scope=campusActorKey(),scopeLoad=workspaceLoadGeneration,source=data;
    const current=()=>generation===campusSearchGeneration&&scope===campusActorKey()&&scopeLoad===workspaceLoadGeneration&&source===data;
    $('campus-search-results').replaceChildren();$('campus-search-results-panel').hidden=false;$('campus-search-query').setAttribute('aria-expanded','true');$('campus-search-coverage').textContent='Checking current permitted records…';
    try{
      if(transport.mode!=='shared'&&!data.features?.operations)throw new Error('Campus search is unavailable for this workspace.');
      const result=transport.mode==='shared'?sharedCampusMatches(query):await api(opUrl('/search',{q:query}));
      if(!current())return;$('campus-search-coverage').textContent=result.coverage;
      let shown=0;
      for(const row of (result.items||[]).slice(0,100)){
        const destination=campusSearchLink(row.source_url);if(!destination)continue;
        const article=document.createElement('article');article.className='campus-result';
        const badge=document.createElement('span');badge.className='badge';badge.textContent=row.kind;
        const heading=document.createElement('h3'),link=document.createElement('a');link.href=destination.href;link.textContent=row.title;heading.append(link);
        const excerpt=document.createElement('p');excerpt.textContent=row.excerpt||'';article.append(badge,heading,excerpt);$('campus-search-results').append(article);shown++;
        link.onclick=click=>{
          if(click.button!==0||click.ctrlKey||click.metaKey||click.altKey||click.shiftKey)return;
          if(!current()){click.preventDefault();closeCampusSearch();return;}
          if((EverbranchTransport.pendingWrites||0)||(EverbranchTransport.refreshBlockers||0)){click.preventDefault();$('campus-search-coverage').textContent='Finish the current save or upload and resolve its retained outcome before opening another record.';return;}
          click.preventDefault();closeCampusSearch();const p=destination.parameters,detail={section:p.get('section'),selected:p.get('selected')||null};if(p.get('area_id')&&p.get('area_id')!==area)detail.area=p.get('area_id');document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail}));
        };
      }
      if(!shown){const empty=document.createElement('p');empty.textContent='No currently authorized matching records.';$('campus-search-results').append(empty);}
    }catch(error){if(current()){$('campus-search-results').replaceChildren();$('campus-search-coverage').textContent=error.message;}}
  }
  document.addEventListener('keydown',event=>{
    if(event.key!=='Escape'||event.defaultPrevented||!campusAssistantModalAllowed())return;
    if(!$('campus-search-results-panel').hidden&&$('campus-search').contains(event.target)){event.preventDefault();closeCampusSearch({focus:true});}
    else if(campusAssistantOpen&&$('campus-assistant-panel').contains(event.target)){event.preventDefault();closeCampusAssistant({focus:true});}
  });
  document.addEventListener('enterprise:reset',()=>closeCampusSearch());
  document.addEventListener('enterprise:render',()=>{
    if(campusShellSection!==section){closeCampusSearch();campusShellSection=section;}
    $('campus-search-query').placeholder=transport.mode==='shared'?'Projects, tickets and tasks':'Buildings, work and people';
    $('campus-search-query').disabled=!area||transport.mode!=='shared'&&!data.features?.operations;
    $('campus-search-form').querySelector('button').disabled=$('campus-search-query').disabled;
    $('campus-tour-launcher').hidden=!document.querySelector('[data-tour-open]');$('campus-tour-launcher').disabled=!!$('work-drawer').open;
    placeCampusAssistant();
  });

  if(typeof MutationObserver!=='undefined')new MutationObserver(()=>placeCampusAssistant()).observe($('work-drawer'),{attributes:true,attributeFilter:['open']});
  $('work-drawer').addEventListener('close',()=>{closeCampusAssistant();placeCampusAssistant();});

  $('campus-assistant-panel').addEventListener('click',event=>{if(event.target.closest('a')&&((EverbranchTransport.pendingWrites||0)||(EverbranchTransport.refreshBlockers||0))){event.preventDefault();event.stopImmediatePropagation();$('campus-assistant-context').textContent='Finish the current save or upload and resolve its retained outcome before opening another record.';}},true);
