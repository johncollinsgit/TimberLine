  // One shared workspace and comment client; native discussion remains separately authorized.
  let sharedDiscussionUi = null, sharedReviewNameReader = null, sharedDiscussionEpoch = 0;
  const sharedDiscussionContext = () => JSON.stringify([area, section, selected]);
  function stashSharedDiscussion() { sharedDiscussionUi?.stash(); }
  function invalidateSharedDiscussion() { sharedDiscussionEpoch++; sharedDiscussionUi?.invalidate({stash:true}); }
  async function readSharedReviewName() {
    const boot = globalThis.EverbranchCampusReviewBoot;
    if (!sharedReviewNameReader) sharedReviewNameReader = EverbranchReviewerNameReader.create(boot, presentation.reviewerWorkspace);
    const page = boot.pages.some(row=>row.id===section) ? section : boot.page;
    return sharedReviewNameReader.read(page);
  }
  function getSharedDiscussionUi() {
    if (!sharedWorkspace || !Object.hasOwn(presentation, 'reviewerCollaboration')) return null;
    if (sharedDiscussionUi) return sharedDiscussionUi;
    sharedDiscussionUi = EverbranchSharedCommentsUI.create({
      boot:presentation, workspace:sharedWorkspace, host:()=>$('detail'), context:sharedDiscussionContext,
      readReviewerName:readSharedReviewName,
      chooseReviewerName:()=>$('review-open')?.click(),
      tasksForProject:id=>{
        const project = data.projects.find(row=>row.id===id);
        return (project?.tasks || []).map(task=>({id:task.id,title:task.title}));
      }
    });
    return sharedDiscussionUi;
  }
  async function mountSharedDiscussion(kind, id) {
    const context = sharedDiscussionContext();
    if (!sharedWorkspace || !Object.hasOwn(presentation, 'reviewerCollaboration')) return null;
    const epoch = ++sharedDiscussionEpoch;
    try {
      // Department catalog reads deliberately cannot publish a different active
      // selection. Obtain the real active projection rather than inventing its
      // revision or weakening the comment client's accepted-projection fence.
      const state = sharedWorkspace.snapshot();
      if (state.revision === null) {
        await sharedWorkspace.readSelection({persona:state.persona, area_ids:[...state.area_ids], person_id:state.person_id});
      }
      if (epoch !== sharedDiscussionEpoch || context !== sharedDiscussionContext()) return null;
      const node = await getSharedDiscussionUi()?.mount(kind,id);
      if (node && epoch === sharedDiscussionEpoch && context === sharedDiscussionContext()
        && typeof mountSharedFilesFromDiscussion === 'function') await mountSharedFilesFromDiscussion();
      return node;
    }
    catch {
      if (epoch === sharedDiscussionEpoch && context === sharedDiscussionContext()) message('Shared comments are unavailable. Refresh current work to check access again.', true);
      return null;
    }
  }
