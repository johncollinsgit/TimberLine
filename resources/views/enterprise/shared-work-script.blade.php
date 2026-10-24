  const sharedWorkspace = transport.mode === 'shared' ? EverbranchTransport.reviewerAdapter(presentation) : null;
  let sharedPrepared = sharedWorkspace?.pending() || null, sharedCommandContext = null;
  function captureSharedPin(source = data) {
    if (!sharedWorkspace) return null;
    if (!source.shared) throw Error('Refresh the saved workspace before opening an edit.');
    return Object.freeze({workspace_id:source.shared.workspace_id,browser_context:presentation.reviewerWorkspace.browser_context,
      revision:source.shared.revision,workspace_digest:source.shared.workspace_digest,selection:{...JSON.parse(JSON.stringify(source.shared.selection)),...(['demo_manager','manager','requester','executive'].includes(source.shared.selection.persona)?{area_ids:source.areas.map(row=>row.id).sort()}:{})},binding:presentation.reviewerWorkspace.binding});
  }
  const sharedRecovery = sharedWorkspace ? document.createElement('div') : null;
  let sharedRecoverButton, sharedRetryButton, sharedAcknowledgeButton, sharedDraft;
  if (sharedWorkspace) {
    sharedRecovery.className='stack'; sharedRecovery.setAttribute('aria-label','Saved shared edit');
    const controls=document.createElement('div');controls.className='actions';sharedRecovery.append(controls);
    const button=(text,handler)=>{const b=document.createElement('button');b.type='button';b.className='button';b.textContent=text;b.onclick=handler;controls.append(b);return b;};
    sharedRecoverButton=button('Check saved edit',()=>recoverSharedEdit());
    sharedRetryButton=button('Retry exact saved edit',()=>sharedCommand(pending));
    sharedAcknowledgeButton=button('Acknowledge rejected edit',async()=>{
      if(!sharedPrepared||busy)return;
      try {sharedWorkspace.acknowledgeRejection(sharedPrepared);sharedPrepared=null;pending=null;pendingUncertain=false;
        EverbranchTransport.setRefreshBlocker('workspace-command',false);if(jobDialog.open)jobDialog.close();jobWizard=null;
        await load();message('Rejected edit acknowledged. Its unsent fields are retained below. Open fresh work and review before making another save.');refreshSharedRecovery();
      } catch(error){message(error.message,true);}
    });
    sharedDraft=document.createElement('details');const label=document.createElement('summary');label.textContent='Retained unsent edit fields';sharedDraft.append(label,document.createElement('pre'));sharedRecovery.append(sharedDraft);
    $('status').after(sharedRecovery);
    if(sharedPrepared){pending={request_id:sharedPrepared.command.request_id,action:sharedPrepared.command.action};pendingUncertain=true;EverbranchTransport.setRefreshBlocker('workspace-command',true);}
    refreshSharedRecovery();
  }
  function refreshSharedRecovery(){
    if(!sharedWorkspace)return;
    sharedRecoverButton.hidden=!sharedPrepared;sharedRetryButton.hidden=!sharedPrepared;
    sharedRecoverButton.disabled=sharedRetryButton.disabled=busy;
    sharedAcknowledgeButton.hidden=sharedWorkspace.snapshot().phase!=='rejected';
    const retained=sharedWorkspace.draft();sharedDraft.hidden=!retained;sharedDraft.querySelector('pre').textContent=retained?JSON.stringify(retained,null,2):'';
    $('retry').hidden=true;
    if(typeof syncSharedPlanEditorLock==='function')syncSharedPlanEditorLock();
  }
  async function settleSharedEdit(value){
    if(value?.status!=='applied')return false;
    sharedPrepared=null;pending=null;pendingUncertain=false;EverbranchTransport.setRefreshBlocker('workspace-command',false);
    const settledContext=sharedCommandContext;sharedCommandContext=null;
    if(typeof settledContext?.onApplied==='function')settledContext.onApplied(value);
    if(!value.stale_context){$('create-dialog').close();if(jobDialog.open)jobDialog.close();jobWizard=null;proposalAiUi?.invalidate();await load();}
    message(value.stale_context?'The original edit was saved. Refresh the current perspective to inspect it.':'Saved to the shared workspace.');return true;
  }
  async function recoverSharedEdit(){
    if(!sharedWorkspace||!sharedPrepared||busy)return;
    busy=true;refreshSharedRecovery();
    try{const result=await sharedWorkspace.recover();if(!await settleSharedEdit(result))message('The server has not confirmed the original edit. Its exact saved intent remains retained; this check sent no edit.',true);}
    catch(error){message(error.message,true);}finally{busy=false;refreshSharedRecovery();}
  }
  async function sharedCommand(body,context=null){
    if(!sharedWorkspace||busy||!body)return;
    if(sharedPrepared&&body.request_id!==sharedPrepared.command.request_id)
      return message('Resolve the original saved edit before preparing another. Its exact intent is retained.',true);
    busy=true;message('Saving shared work…');
    try{
      let result;
      if(sharedPrepared){
        if(body.request_id!==sharedPrepared.command.request_id)throw Error('Resolve the saved edit before preparing another.');
        result=await sharedWorkspace.retry(sharedPrepared);
      }else{
        if(!context?.pin)throw Error('Open a fresh form to review current shared records before saving.');
        sharedCommandContext=context;
        const mapped=EverbranchSharedWorkCommandMap.map(body,context);
        sharedPrepared=await sharedWorkspace.prepareCommand(context.pin,mapped);
        pending=body;EverbranchTransport.setRefreshBlocker('workspace-command',true);
        result=await sharedWorkspace.send(sharedPrepared);
      }
      await settleSharedEdit(result);return result;
    }catch(error){
      const retained=sharedWorkspace.pending();
      if(retained){sharedPrepared=retained;
        if(pending?.request_id!==retained.command.request_id)pending={request_id:retained.command.request_id,action:retained.command.action};
        pendingUncertain=sharedWorkspace.snapshot().phase!=='rejected';EverbranchTransport.setRefreshBlocker('workspace-command',true);}
      else{sharedPrepared=null;pending=null;pendingUncertain=false;EverbranchTransport.setRefreshBlocker('workspace-command',false);}
      message(error.message,true);
    }finally{busy=false;refreshSharedRecovery();}
  }
