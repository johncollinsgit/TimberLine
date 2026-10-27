  // Separate file/image capability; every record read/write remains server-authorized.
  let sharedFilesUi = null, sharedFilesUploads = null, sharedFilesImages = null, sharedFilesDownloads = null;
  let sharedFilesScope = null, sharedFilesSerial = 0, sharedFilesName = null, sharedFilesQueued = null, sharedFilesNameSerial = 0;
  const sharedFileUuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
  const sharedFilesSelection = () => {
    const state=sharedWorkspace.snapshot();
    return EverbranchSharedWorkspace.canonical({persona:state.persona,area_ids:[...state.area_ids],person_id:state.person_id});
  };
  function clearSharedFiles() {
    sharedFilesSerial++; sharedFilesNameSerial++; sharedFilesUi?.destroy(); sharedFilesScope?.host?.remove(); sharedFilesUi=null; sharedFilesScope=null; sharedFilesName=null;
  }
  function sharedFilesTarget() {
    const scope=sharedFilesScope;
    if(!scope || !scope.node.isConnected || !$('work-drawer').open || scope.context!==sharedDiscussionContext())return null;
    const picker=scope.node.querySelector('#shared-discussion-thread');
    if(!picker)return scope.target;
    const [kind,id,...rest]=picker.value.split(':');
    return !rest.length && ['project','task'].includes(kind) && sharedFileUuid.test(id) ? {kind,id} : null;
  }
  async function openSharedFilesOriginal(target) {
    if(!sharedWorkspace || !['ticket','project','task'].includes(target?.kind) || !sharedFileUuid.test(target.id))return;
    const serial=++sharedFilesSerial, context=sharedDiscussionContext(), selection=sharedFilesSelection();
    const state=sharedWorkspace.snapshot();
    try {
      const view=await sharedWorkspace.readSelection({persona:state.persona,area_ids:[...state.area_ids],person_id:state.person_id});
      if(serial!==sharedFilesSerial || context!==sharedDiscussionContext() || selection!==sharedFilesSelection())return;
      const collection={ticket:'tickets',project:'projects',task:'tasks'}[target.kind];
      const record=view.graph[collection].find(row=>row.id===target.id);
      if(!record)throw Error('The original work is not in the current permitted selection.');
      const project=target.kind==='task' ? view.graph.projects.find(row=>row.id===record.project_id) : null;
      if(target.kind==='task' && !project)throw Error('The original project is not available.');
      const next={section:target.kind==='ticket'?'requests':'projects',area:record.area_id,selected:project?.id||record.id};
      sharedFilesQueued={...next,target:{...target},selection};
      document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:next}));
    } catch {
      if(serial===sharedFilesSerial && context===sharedDiscussionContext())message('The original upload is retained. Its work is unavailable in the current selection.',true);
    }
  }
  async function mountSharedFilesFromDiscussion() {
    if(!sharedWorkspace || !Object.hasOwn(presentation,'reviewerAttachments'))return null;
    if(sharedFilesQueued) {
      const queued=sharedFilesQueued;
      sharedFilesQueued=null;
      if(queued.section===section && queued.area===area && queued.selected===selected && queued.selection===sharedFilesSelection() && queued.target.kind==='task') {
        await getSharedDiscussionUi()?.mount('task',queued.target.id,selected);
      }
    }
    const node=$('shared-discussion');
    if(!node?.isConnected || !$('work-drawer').open)return null;
    const picker=node.querySelector('#shared-discussion-thread');
    const fallback={kind:section==='projects'?'project':'ticket',id:selected};
    const parts=picker?.value.split(':');
    const target=parts ? {kind:parts[0],id:parts[1]} : fallback;
    if(!['project','task','ticket'].includes(target.kind) || !sharedFileUuid.test(target.id) || parts && parts.length!==2)return null;
    const context=sharedDiscussionContext(); ++sharedFilesSerial;
    if(sharedFilesScope?.node!==node)clearSharedFiles();
    // clearSharedFiles retires old IO; capture the resulting epoch for this mounted node.
    const epoch=sharedFilesSerial;
    sharedFilesScope={node,context,target,host:sharedFilesScope?.node===node?sharedFilesScope.host:null};
    const current=()=>epoch===sharedFilesSerial && node.isConnected && context===sharedDiscussionContext() && $('work-drawer').open
      && EverbranchSharedWorkspace.canonical(sharedFilesTarget())===EverbranchSharedWorkspace.canonical(target);
    try {
      sharedFilesUploads ||= EverbranchWorkspaceUploads.create(presentation,sharedWorkspace);
      if(Object.hasOwn(presentation,'reviewerImages'))sharedFilesImages ||= EverbranchWorkspaceImages.create(presentation,sharedWorkspace);
      if(Object.hasOwn(presentation,'reviewerDownloads'))sharedFilesDownloads ||= EverbranchWorkspaceDownloads.create(presentation,sharedWorkspace);
      const nameSerial=++sharedFilesNameSerial;
      const nameRead=readSharedReviewName().then(value=>{
        if(nameSerial===sharedFilesNameSerial && current())sharedFilesName=value.reviewer_name;
      },error=>{if(nameSerial===sharedFilesNameSerial && current())throw error;});
      const [fresh]=await Promise.all([sharedFilesUploads.show(target),nameRead]);
      if(!current())return null;
      if(!sharedFilesUi) {
        const template=$('shared-work-files-template');
        const host=template?.content.firstElementChild?.cloneNode(true);
        if(!host)throw Error('Private file controls are unavailable.');
        node.append(host); sharedFilesScope.host=host;
        sharedFilesUi=EverbranchWorkspaceFileUi.mount({host,uploads:sharedFilesUploads,images:sharedFilesImages,downloads:sharedFilesDownloads,
          workspace:sharedWorkspace,dialog:$('attachment-gallery'),getReviewerName:()=>sharedFilesName,getCurrentTarget:sharedFilesTarget,
          onOpenTarget:openSharedFilesOriginal,onAccessLoss:()=>{if(sharedFilesScope?.host===host && node.isConnected && context===sharedDiscussionContext()){clearSharedFiles();message('File access changed. Refresh current work to check access again.',true);}}});
      }
      await sharedFilesUi.setTarget(target,fresh.target_version);
      if(current())sharedFilesUi.refreshName();
      return current()?sharedFilesUi:null;
    } catch {
      if(current()){clearSharedFiles();message('Files are unavailable. Any original pending upload remains retained for recovery.',true);}
      return null;
    }
  }
  if(sharedWorkspace && Object.hasOwn(presentation,'reviewerAttachments')) {
    window.addEventListener('pagehide',()=>{
      clearSharedFiles();
      sharedFilesUploads?.destroy(); sharedFilesImages?.destroy(); sharedFilesDownloads?.destroy();
      sharedFilesUploads=null; sharedFilesImages=null; sharedFilesDownloads=null;
    });
    window.addEventListener('pageshow',event=>{
      if(event.persisted)void mountSharedFilesFromDiscussion();
    });
    document.addEventListener('change',event=>{
      if(event.target?.id!=='shared-discussion-thread')return;
      clearSharedFiles(); queueMicrotask(()=>void mountSharedFilesFromDiscussion());
    },true);
    document.addEventListener('click',event=>{
      if(!event.target?.closest?.('#shared-discussion-name-refresh'))return;
      const scope=sharedFilesScope, nameSerial=++sharedFilesNameSerial;
      void readSharedReviewName().then(value=>{if(scope && scope===sharedFilesScope && nameSerial===sharedFilesNameSerial){sharedFilesName=value.reviewer_name;sharedFilesUi?.refreshName();}}).catch(()=>{if(scope && scope===sharedFilesScope && nameSerial===sharedFilesNameSerial){sharedFilesName=null;sharedFilesUi?.refreshName();}});
    });
  }
