import './assistant-ui.css';

/** One private conversation surface across financial drilldowns and expense notifications. */
export function initTrajectoryAssistant({root, getContext, api, endpoint, notice = () => {}, onChanged = () => {}, onMatchTransfers = null}) {
  if (!root) return null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => value == null ? 'Not budgeted' : new Intl.NumberFormat('en-US', {style:'currency',currency:'USD'}).format(value / 100);
  const host = document.createElement('div'); host.className = 'tr-assistant';
  host.innerHTML = `<button type="button" class="tr-assistant-launch" aria-haspopup="dialog">Ask Everbranch <span data-assistant-badge hidden></span></button><dialog class="tr-assistant-panel" aria-label="Ask Everbranch financial assistant"><header><div><strong>Ask Everbranch</strong><small data-assistant-scope></small></div><button type="button" data-assistant-close aria-label="Close Ask Everbranch">×</button></header><nav aria-label="Assistant views"><button type="button" data-assistant-chat>Chat</button><button type="button" data-assistant-inbox>Expenses</button></nav><div data-assistant-content class="tr-assistant-content"></div><form data-assistant-form><label for="tr-assistant-question">Message Everbranch</label><div><input id="tr-assistant-question" name="question" maxlength="2000" autocomplete="off" required placeholder="Ask a question or type a category"><button type="submit">Send</button></div></form></dialog>`;
  root.append(host);
  const dialog = host.querySelector('dialog'), content = host.querySelector('[data-assistant-content]'), input = host.querySelector('input');
  let currentSpace = null, loadedSpace = null, messages = [], inbox = null, selectedExpense = null, busy = false, view = 'chat';
  let pushCheckVersion = 0, badgeRequest = 0;
  const requestIds = new Map();
  const transactionId=Number(new URLSearchParams(location.search).get('transaction'));
  let deepLinkOpened=false;
  const context = () => {
    const raw = getContext?.() || {};
    const allowed = ['from','through','account_id','budget_account_id','program_id','level','direction','category','subcategory','transaction_ids','transaction_versions','space_id','basis','selection_truncated','focused_transaction'];
    const value = Object.fromEntries(Object.entries(raw).filter(([key, v]) => allowed.includes(key) && v != null));
    if (value.direction && !['in','out','income','expense','both'].includes(value.direction)) delete value.direction;
    if (Array.isArray(value.transaction_ids)) value.transaction_ids = [...new Set(value.transaction_ids)].slice(0,100);
    return selectedExpense ? {...value, from:selectedExpense.date, through:selectedExpense.date, account_id:null, budget_account_id:null, program_id:null, category:null, subcategory:null, direction:'out', level:'transaction', basis:'spending', focused_transaction:true, selection_truncated:false, transaction_ids:[selectedExpense.transaction_id], transaction_versions:{[selectedExpense.transaction_id]:selectedExpense.version}} : value;
  };
  const spaceId = () => getContext?.()?.scope==='both'?null:Number(getContext?.()?.space_id) || null;
  const path = suffix => endpoint(suffix, currentSpace || spaceId());
  let generation=0, action=null, historyAction=null, picker=null, catalog=null, historyRequest=null, baseSelection='';
  const baseKey = () => {
    const v=getContext?.() || {};
    return JSON.stringify([v.space_id,v.from,v.through,v.account_id,v.budget_account_id,v.program_id,v.level,v.category,v.subcategory,v.direction,v.basis,v.transaction_ids]);
  };
  function enterSpace(id) {
    if(currentSpace===id)return;
    generation++;currentSpace=id;loadedSpace=null;messages=[];selectedExpense=null;inbox=null;
    action=null;historyAction=null;picker=null;catalog=null;historyRequest=null;busy=false;baseSelection=baseKey();
    requestIds.clear();input.disabled=false;input.value='';
    const badge=host.querySelector('[data-assistant-badge]');
    badge.hidden=true;badge.textContent='';badge.removeAttribute('aria-label');
    host.querySelector('.tr-assistant-launch').removeAttribute('title');
    host.querySelector('[data-assistant-inbox]').textContent='Expenses';
    content.replaceChildren();
  }
  const isCurrent = token => generation===token && currentSpace!==null && spaceId()===currentSpace;
  function showError(error) {
    if(!currentSpace||spaceId()!==currentSpace)return;
    if(view!=='chat'){notice(error.message || 'Unable to complete this request.');return;}
    messages.push({role:'assistant',reply:error.message || 'Unable to complete this request.'});renderChat();
  }
  function documentSources(message) {
    if (!currentSpace || !Array.isArray(message.medical_documents)) return '';
    const sources = message.medical_documents.filter(doc => Number.isSafeInteger(doc.id) && (doc.need_id === null || Number.isSafeInteger(doc.need_id)));
    return sources.length ? '<ul class="tr-ask-documents">' + sources.map(doc =>
      `<li><a download href="${esc(path('/medical/' + (doc.need_id ? 'needs/' + doc.need_id + '/' : '') + 'documents/' + doc.id))}">${esc(doc.filename)}</a><small>${esc(doc.case)}</small>${doc.note ? '<p>Saved note · ' + esc(doc.note) + '</p>' : ''}</li>`
    ).join('') + '</ul>' : '';
  }
  function chatActions(message) {
    if(busy)return '';
    if(message.historical){
      if(message!==historyAction||messages.some(m=>!m.historical))return '';
      if(message.undo?.available)return `<button type="button" data-assistant-undo="${message.id}">Undo</button>`;
      if(message.proposal && sameProposalContext(message))return `<button type="button" data-assistant-apply="${message.id}">Apply ${message.proposal.actions?.length || ''} changes</button>`;
      return '';
    }
    if(message!==action)return '';
    const review=message.review_transaction;
    const offered=message.choices||[];
    const specificChoices=offered.length>=1 && offered.length<=3 && new Set(offered.map(c=>c.label.toLowerCase())).size===1 ? offered : [];
    const choiceButtons=(specificChoices||[]).map(choice=>`<button type="button" data-assistant-choice="${esc(choice.type+' '+choice.label)}">${esc(choice.label)} · ${esc(choice.type)}</button>`).join('');
    const showPicker=review||message.needs_destination||message.choices?.length;
    const pickerButton=showPicker ? '<button type="button" data-assistant-pick>Choose category</button>' : '';
    if(review){
      return `<section class="tr-assistant-review" aria-label="Transaction to review"><div class="tr-review-heading"><strong>${esc(review.merchant)}</strong><strong>${money(review.amount_cents)}</strong></div><small>${esc(review.date)}</small><div class="tr-assistant-actions">${review.suggestion?`<button class="tr-ask-primary" type="button" data-assistant-review-yes="${message.id}">Yes · ${esc(review.suggestion.label)}</button>`:''}${choiceButtons||pickerButton}${review.reason?'<button type="button" data-assistant-review-explain>Explain</button>':''}</div>${message.showReason?`<p>${esc(review.reason)}</p>`:''}</section>`;
    }
    return `<div class="tr-assistant-actions">${message.open_transfer_review && onMatchTransfers?'<button type="button" class="tr-ask-primary" data-assistant-match-transfers>Review transfer matches</button>':''}
      ${choiceButtons}${choiceButtons?'':pickerButton}
      ${message.proposal?`<button type="button" class="tr-ask-primary" data-assistant-apply="${message.id}">Apply ${message.proposal.actions?.length || ''} changes</button>`:''}
      ${message.applied?'<button type="button" data-assistant-review-next>Next transaction</button>':''}
      ${message.undo?.available?`<button type="button" data-assistant-undo="${message.id}">Undo</button>`:''}</div>`;
  }
  function sameProposalContext(message) {
    const previous=message.originalContext, current=context();
    if(!previous || (previous.space_id??currentSpace)!==currentSpace)return false;
    const fields=['from','through','account_id','budget_account_id','program_id','level','category','subcategory','direction','basis','focused_transaction','selection_truncated'];
    if(fields.some(key=>(previous[key]??null)!==(current[key]??null)))return false;
    const ids=previous.transaction_ids||[], now=current.transaction_ids||[];
    return ids.length>0 && ids.length===now.length && ids.every(id=>now.includes(id) && previous.transaction_versions?.[id]===current.transaction_versions?.[id] && Number.isInteger(current.transaction_versions?.[id]));
  }
  function messageHtml(message) {
    const oldCard=message.review_transaction&&message!==action ? `<small>${esc(message.review_transaction.merchant)} · ${money(message.review_transaction.amount_cents)}</small>` : '';
    return `<article class="tr-assistant-message ${message.role}" aria-label="${message.role==='user'?'You':'Everbranch'}"><div>${esc(message.reply).replaceAll('\n','<br>')}</div>${documentSources(message)}${oldCard}${chatActions(message)}</article>`;
  }
  function updatePicker(query) {
    if(!picker)return;
    const value=query.trim().toLowerCase();
    const box=content.querySelector('[data-assistant-search-results]');
    if(!box)return;
    if(!value){box.replaceChildren();return;}
    if(!picker.items){box.textContent=picker.error||'Loading categories…';return;}
    const matches=picker.items.filter(c=>c.label.toLowerCase().includes(value)||c.key.toLowerCase().replaceAll('_',' ').includes(value))
      .sort((a,b)=>Number(b.label.toLowerCase().startsWith(value))-Number(a.label.toLowerCase().startsWith(value))).slice(0,5);
    box.innerHTML=matches.map(c=>`<button type="button" data-assistant-choice="${esc(c.type+' '+c.label)}"><span>${esc(c.label)}</span><small>${esc(c.type)}</small></button>`).join('')||'<small>No matching categories or programs.</small>';
  }
  function renderChat() {
    view='chat';
    host.querySelector('[data-assistant-chat]').setAttribute('aria-pressed','true');
    host.querySelector('[data-assistant-inbox]').setAttribute('aria-pressed','false');
    host.querySelector('[data-assistant-scope]').textContent=selectedExpense?'Selected purchase':getContext?.()?.scope==='business'?'Business workspace':'Current financial view';
    const history=messages.filter(m=>m.historical), live=messages.filter(m=>!m.historical);
    const previous=history.length ? `<details class="tr-ask-history"><summary>Previous conversation</summary>${history.map(messageHtml).join('')}</details>` : '';
    const quick=(!action||!action.review_transaction&&!action.applied&&!action.open_transfer_review) && !busy ? `<div class="tr-assistant-actions tr-ask-start"><button type="button" data-assistant-review-next>Review next transaction</button>${onMatchTransfers?'<button type="button" data-assistant-match-transfers>Match transfers</button>':''}</div>` : '';
    content.innerHTML=previous + (!live.length?'<h2 class="tr-ask-prompt">What would you like to review?</h2>':'')+
      `<div role="log" aria-label="Conversation">${live.map(messageHtml).join('')}</div>`+quick+
      (busy?'<p role="status">Working…</p>':'')+
      (picker?'<section class="tr-ask-picker"><div><label for="tr-ask-category">Search categories and programs</label><button type="button" data-assistant-dismiss-picker aria-label="Close category search">×</button></div><input id="tr-ask-category" type="search" autocomplete="off" data-assistant-category-search><div data-assistant-search-results></div></section>':'');
    host.querySelector('[data-assistant-form]').hidden=false;
    host.querySelector('[data-assistant-form] button').disabled=busy;
    content.scrollTop=content.scrollHeight;
  }
  function categoryChoices(result) {
    const defs=(result.category_definitions||[]).filter(d=>d.active);
    const names=new Map(defs.map(d=>[d.key,d]));
    const values=new Map();
    for(const key of result.categories||[]) {
      const d=names.get(key);
      values.set(key,{key,type:d?.parent_record_id?'subcategory':'category',label:d?.name||key.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase())});
    }
    for(const d of defs) values.set(d.key,{key:d.key,type:d.parent_record_id?'subcategory':'category',label:d.name});
    for(const p of result.programs||[]) if(p.active) values.set('program:'+p.id,{key:p.key,type:'program',label:p.name});
    return [...values.values()];
  }
  async function openPicker() {
    if(!action || busy)return;
    const currentPicker=picker={items:catalog}, token=generation;
    renderChat();content.querySelector('[data-assistant-category-search]').focus();
    if(catalog)return;
    try {
      const result=await api(path('/attribution/definitions'));
      if(!isCurrent(token)||picker!==currentPicker||view!=='chat')return;
      catalog=categoryChoices(result);picker.items=catalog;
    }catch(error){if(!isCurrent(token)||picker!==currentPicker)return;picker.error=error.message || 'Unable to load categories.';}
    updatePicker(content.querySelector('[data-assistant-category-search]')?.value||'');
  }
  async function loadHistory() {
    if(!currentSpace || loadedSpace===currentSpace)return;
    if(historyRequest)return historyRequest;
    const id=currentSpace, token=generation;
    historyRequest=api(endpoint('/assistant',id)).then(result=>{
      if(!isCurrent(token))return;
      const history=(result.rows||[]).flatMap(turn=>[{historical:true,role:'user',reply:turn.question},{historical:true,role:'assistant',id:turn.id,originalContext:turn.context,...(turn.response||{reply:'This request has not completed.'})}]);
      messages=[...history,...messages.filter(m=>!m.historical)];
      historyAction=history.at(-1)??null;loadedSpace=id;
    }).finally(()=>{if(isCurrent(token))historyRequest=null;});
    return historyRequest;
  }
  async function openChat() {
    enterSpace(spaceId());if(!currentSpace){if(dialog.open)dialog.close();notice('Select a personal or business workspace before asking.');return;}
    const token=generation;view='chat';picker=null;
    if(!dialog.open)dialog.showModal();
    renderChat();
    try{await loadHistory();if(isCurrent(token)&&view==='chat'&&dialog.open){renderChat();(content.querySelector('.tr-ask-start button')??input).focus();}}catch(error){if(isCurrent(token))showError(error);}
  }
  async function loadInbox(summary=false) {
    const requestedSpace=spaceId(); if(!requestedSpace)return;
    const token=generation, request=++badgeRequest;
    const result=await api(endpoint(summary?'/notifications?summary=1':'/notifications',requestedSpace));
    if(!isCurrent(token)||spaceId()!==requestedSpace)return false;
    if(!summary)inbox=result;
    if(request===badgeRequest){
      const badge=host.querySelector('[data-assistant-badge]'), launch=host.querySelector('.tr-assistant-launch');
      const count=result.review_count, hasCount=Number.isSafeInteger(count) && count >= 0;
      badge.hidden=!hasCount || count===0;badge.textContent=hasCount && count>0 ? String(count) : '';
      const label=hasCount ? count + (count===1?' transaction needs':' transactions need') + ' category review in this workspace' : '';
      if(label){launch.title=label;badge.setAttribute('aria-label',label);}else{launch.removeAttribute('title');badge.removeAttribute('aria-label');}
      host.querySelector('[data-assistant-inbox]').textContent=result.unread_count>0 ? 'Expenses ('+result.unread_count+' unread)' : 'Expenses';
    }
    return true;
  }
  const noticeRow = row => row.payload.unavailable ? '<div class="tr-expense-notice"><strong>Purchase no longer available in this household</strong><span>Its ownership, purpose or bank status changed.</span></div>' : `<button type="button" class="tr-expense-notice" data-open-expense="${row.id}"><strong>${esc(row.payload.merchant)} · ${money(row.payload.amount_cents)}</strong><span>${esc(row.payload.date)} · ${esc(row.payload.category_label)}${row.payload.pending?' · Pending':''}${row.payload.removed?' · Removed':''}</span><span>${row.payload.remaining_cents==null?'Budget not established':money(row.payload.remaining_cents)+' left in category'}${row.payload.needs_purpose?' · What was this for?':''}</span></button>`;
  async function openInbox() {
    enterSpace(spaceId());if(!currentSpace){notice('Select one workspace to open its expense inbox.');return;}
    view='inbox';picker=null;
    host.querySelector('[data-assistant-chat]').setAttribute('aria-pressed','false');
    host.querySelector('[data-assistant-inbox]').setAttribute('aria-pressed','true');
    host.querySelector('[data-assistant-form]').hidden=true;content.textContent='Loading expenses…';
    if(!dialog.open)dialog.showModal();
    if(getContext?.()?.scope==='business'||root.dataset.surface==='business'){
      void refreshBadge();
      view='inbox';host.querySelector('[data-assistant-form]').hidden=true;
      host.querySelector('[data-assistant-scope]').textContent='Business workspace';
      content.innerHTML='<div class="tr-assistant-preferences"><strong>Personal expense alerts live in the Personal app</strong><p>Open Trajectory Personal to review household purchases and manage your phone notifications. Ask Everbranch is available here for this business’s financial information.</p><a href="/trajectory/personal/">Open Trajectory Personal</a><button type="button" data-assistant-chat>Ask about this business</button></div>';
      return;
    }
    try{
      if(!await loadInbox()||view!=='inbox'||!dialog.open)return;view='inbox';host.querySelector('[data-assistant-form]').hidden=true;
      const prefs=inbox.preferences;
      content.innerHTML=`<div class="tr-assistant-preferences"><strong>Personal expense notifications</strong><label><input type="checkbox" data-alert-enabled ${prefs.enabled?'checked':''}> Notify me about new purchases</label><label><input type="checkbox" data-alert-pending ${prefs.include_pending?'checked':''}> Include pending purchases</label><label><input type="checkbox" data-alert-details ${prefs.show_details?'checked':''}> Show merchant, amount, category and budget left on lock screen</label><button type="button" data-save-alerts>Save preferences</button><button type="button" data-enable-push disabled>Enable phone notifications</button><small data-push-status role="status">Checking this device’s notification setup…</small><a href="/trajectory/personal/">Open Personal app</a> · <a href="/trajectory/business/">Open Business app</a><small>iPhone: open the app in Safari → Share → Add to Home Screen, then enable notifications from the installed app.</small></div>${(inbox.rows||[]).length?inbox.rows.map(noticeRow).join(''):'<p>No new expenses yet. New feed purchases will appear after you opt in; historical imports do not create a backlog of alerts.</p>'}${inbox.next_before?'<button type="button" data-load-expenses>Earlier expenses</button>':''}`;
      void checkPushState();
    }catch(error){showError(error);}
  }
  async function chooseExpense(row) {
    const token=generation;
    await api(path(`/notifications/${row.id}/read`),'PATCH',{});
    if(!isCurrent(token)||view!=='inbox')return;
    void refreshBadge();
    await loadHistory();if(!isCurrent(token)||view!=='inbox')return;
    selectedExpense=row.payload;picker=null;
    action={role:'assistant',reply:row.payload.needs_purpose?'What was this purchase for?':'Choose a category or ask about this purchase.',
      review_transaction:row.payload};
    messages.push(action);renderChat();input.focus();
  }
  async function send(question) {
    if(busy||!currentSpace||spaceId()!==currentSpace)return;
    const token=generation, submittedContext=context(), submittedView=baseKey(), id=currentSpace;
    const requestKey=JSON.stringify([id,question,submittedContext]);
    busy=true;picker=null;input.disabled=true;action=null;messages.push({role:'user',reply:question});renderChat();
    try{
      if(!requestIds.has(requestKey))requestIds.set(requestKey,crypto.randomUUID());
      const result=await api(endpoint('/assistant',id),'POST',{question,context:submittedContext,request_id:requestIds.get(requestKey),use_ai:true});
      if(!isCurrent(token))return;
      requestIds.delete(requestKey);
      const response={role:'assistant',...result};messages.push(response);
      action=baseKey()===submittedView || result.applied ? response : null;
      selectedExpense=action?.review_transaction??(action?selectedExpense:null);
      if(result.applied){selectedExpense=null;void refreshBadge();await onChanged();}
    }catch(error){if(isCurrent(token))messages.push({role:'assistant',reply:error.message});}
    finally{if(isCurrent(token)){busy=false;input.disabled=false;input.value='';if(view==='chat'){renderChat();if(dialog.open)input.focus();}}}
  }
  const pushSupported = () => 'serviceWorker'in navigator && 'PushManager'in window && 'Notification'in window;
  function pushFeedback(button, text, label = 'Enable phone notifications', disabled = false, action = 'enable') {
    if(!button?.isConnected)return;
    button.textContent=label;button.disabled=disabled;button.dataset.pushAction=action;
    content.querySelector('[data-push-status]').textContent=text;
  }
  const enabledPushMessage = () => inbox.preferences.enabled
    ? 'Phone notifications are enabled on this device. Alerts arrive when the bank feed reports a purchase.'
    : 'Phone notifications are enabled on this device. Turn on “Notify me about new purchases” and save to receive alerts.';
  async function checkPushState() {
    const button=content.querySelector('[data-enable-push]'), checkedSpace=currentSpace, checkVersion=++pushCheckVersion;
    const current=()=>button?.isConnected && currentSpace===checkedSpace && checkVersion===pushCheckVersion && view==='inbox';
    if(!button)return;
    if(!inbox.push.ready){pushFeedback(button,'Push delivery needs server setup. Your expense inbox still works.','Server setup needed',true);return;}
    if(!pushSupported()){pushFeedback(button,'Install the Personal app on a supported phone/browser to enable push. Your expense inbox still works.','Phone notifications unavailable',true);return;}
    if(!location.pathname.startsWith('/trajectory/personal/')){pushFeedback(button,'Open the Personal app to enable phone notifications on this device.','Open Personal app to enable',true);return;}
    if(Notification.permission==='denied'){pushFeedback(button,'Notifications are blocked on this device. Allow them in your device settings, then check again.','Check notification permission',false,'check');return;}
    if(Notification.permission!=='granted'){pushFeedback(button,'Phone notifications are not enabled on this device.');return;}
    pushFeedback(button,'Checking this device’s notification setup…','Checking notifications…',true,'check');
    try{
      const registration=await navigator.serviceWorker.getRegistration('/trajectory/personal/');
      const subscription=registration?.scope===new URL('/trajectory/personal/',location.origin).href?await registration.pushManager.getSubscription():null;
      if(!current())return;
      if(!subscription){pushFeedback(button,'Phone notifications are not enabled on this device.');return;}
      const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(subscription.endpoint))),byte=>byte.toString(16).padStart(2,'0')).join('');
      const result=await api(endpoint(`/push-subscriptions?endpoint_hash=${hash}`,checkedSpace));
      if(!current())return;
      if(result.registered===true)pushFeedback(button,enabledPushMessage(),'Phone notifications enabled',true);
      else pushFeedback(button,'This device is not registered for your personal workspace. Enable phone notifications to finish setup.');
    }catch{
      if(current())pushFeedback(button,'Couldn’t confirm this device’s notification setup. Try again.','Retry notification check',false,'check');
    }
  }
  async function enablePush() {
    const button=content.querySelector('[data-enable-push]'), enabledSpace=currentSpace, checkVersion=++pushCheckVersion;
    const current=()=>button?.isConnected && currentSpace===enabledSpace && checkVersion===pushCheckVersion && view==='inbox';
    pushFeedback(button,'Enabling phone notifications on this device…','Enabling notifications…',true);
    try{
      if(!pushSupported())throw new Error('Install the Personal app on a supported phone/browser to enable push. The inbox remains available.');
      if(!location.pathname.startsWith('/trajectory/personal/'))throw new Error('Open the Personal app, add it to your Home Screen, then enable phone notifications there.');
      if(await Notification.requestPermission()!=='granted'){if(current())await checkPushState();return;}
      const registration=await navigator.serviceWorker.register('/trajectory/personal/sw.js',{scope:'/trajectory/personal/'});
      await navigator.serviceWorker.ready;
      const bytes=Uint8Array.from(atob(inbox.push.public_key.replaceAll('-','+').replaceAll('_','/')),c=>c.charCodeAt(0));
      const subscription=await registration.pushManager.getSubscription()||await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:bytes});
      if(!current())return;
      const result=await api(endpoint('/push-subscriptions',enabledSpace),'POST',subscription.toJSON());
      if(!current())return;
      if(!result.id||result.ready!==true)throw new Error('This device was saved, but phone notification delivery could not be confirmed. Try again.');
      pushFeedback(button,enabledPushMessage(),'Phone notifications enabled',true);
      notice('Phone notifications enabled for your personal workspace.');
    }catch(error){
      if(current())pushFeedback(button,error.message||'Phone notification setup failed. Try again.','Retry enabling notifications');
    }
  }
  async function saveAlertPreferences(button) {
    const savedSpace=currentSpace, savedInbox=inbox;
    const current=()=>button.isConnected && dialog.open && view==='inbox' && currentSpace===savedSpace && spaceId()===savedSpace && inbox===savedInbox;
    const preferences={enabled:content.querySelector('[data-alert-enabled]').checked,include_pending:content.querySelector('[data-alert-pending]').checked,show_details:content.querySelector('[data-alert-details]').checked};
    button.disabled=true;
    try{
      const result=await api(endpoint('/notifications/preferences',savedSpace),'PATCH',preferences);
      if(!current())return;
      savedInbox.preferences=result;notice('Your personal expense notification preferences were saved.');await checkPushState();
    }catch(error){if(current())showError(error);}
    finally{if(button.isConnected)button.disabled=false;}
  }
  host.addEventListener('click',async event=>{
    const button=event.target.closest('button');if(!button)return;
    try{
      if(button.matches('.tr-assistant-launch,[data-assistant-chat]'))await openChat();
      else if(button.hasAttribute('data-assistant-close'))dialog.close();
      else if(button.hasAttribute('data-assistant-inbox'))await openInbox();
      else if(button.hasAttribute('data-open-expense')){const row=inbox?.rows.find(row=>row.id===Number(button.dataset.openExpense));if(row&&!row.payload.removed)await chooseExpense(row);}
      else if(button.hasAttribute('data-load-expenses')){
        const token=generation, previous=inbox;
        const older=await api(path(`/notifications?before=${inbox.next_before}`));
        if(!isCurrent(token)||view!=='inbox'||!button.isConnected||previous!==inbox)return;
        inbox.rows.push(...older.rows);inbox.next_before=older.next_before;
        button.insertAdjacentHTML('beforebegin',older.rows.map(noticeRow).join(''));if(!older.next_before)button.remove();
      }
      else if(button.hasAttribute('data-assistant-match-transfers')){
        if(currentSpace!==spaceId()||!onMatchTransfers)return;
        dialog.close();try{await onMatchTransfers();}catch(error){notice(error.message || 'Could not load transfer matches.');}
      }
      else if(button.hasAttribute('data-assistant-review-next')){selectedExpense=null;await send('Review the next uncategorized transaction');}
      else if(button.hasAttribute('data-assistant-pick'))await openPicker();
      else if(button.hasAttribute('data-assistant-dismiss-picker')){picker=null;renderChat();content.querySelector('[data-assistant-pick]')?.focus();}
      else if(button.hasAttribute('data-assistant-review-yes')){
        if(action?.review_transaction?.suggestion){
          const suggestion=action.review_transaction.suggestion;
          selectedExpense=action.review_transaction;await send(`Move this to ${suggestion.type} ${suggestion.label}`);
        }
      }
      else if(button.hasAttribute('data-assistant-review-explain')){
        if(action?.review_transaction){action.showReason=!action.showReason;renderChat();content.querySelector('[data-assistant-review-explain]')?.focus();}
      }
      else if(button.hasAttribute('data-assistant-choice')){
        if(!action||busy||currentSpace!==spaceId())return;
        await send(`Move this to ${button.dataset.assistantChoice}`);
      }
      else if(button.hasAttribute('data-assistant-apply')||button.hasAttribute('data-assistant-undo')){
        const command=button.hasAttribute('data-assistant-apply')?'apply':'undo', id=Number(button.dataset[command==='apply'?'assistantApply':'assistantUndo']);
        const previous=action?.id===id ? action : historyAction?.id===id && !messages.some(m=>!m.historical) ? historyAction : null;
        if(busy||currentSpace!==spaceId()||!previous)return;
        if(previous.historical && command==='apply' && !sameProposalContext(previous))return;
        const token=generation;
        busy=true;input.disabled=true;picker=null;renderChat();
        try{
          const result=await api(path(`/assistant/${id}/${command}`),'POST',{});
          if(!isCurrent(token))return;
          action={role:'assistant',...result};messages=messages.map(message=>message===previous?action:message);selectedExpense=null;void refreshBadge();await onChanged();
        }finally{
          if(isCurrent(token)){busy=false;input.disabled=false;if(view==='chat')renderChat();}
        }
      }else if(button.hasAttribute('data-save-alerts')){
        await saveAlertPreferences(button);
      }else if(button.hasAttribute('data-enable-push')){if(button.dataset.pushAction==='check')await checkPushState();else await enablePush();}
    }catch(error){showError(error);}
  });
  host.addEventListener('input',event=>{if(event.target.matches('[data-assistant-category-search]'))updatePicker(event.target.value);});
  host.addEventListener('keydown',event=>{
    if(!event.target.matches('[data-assistant-category-search]'))return;
    const choices=[...content.querySelectorAll('[data-assistant-search-results] [data-assistant-choice]')];
    if(event.key==='ArrowDown' && choices.length){event.preventDefault();choices[0].focus();}
    if(event.key==='Enter' && choices.length===1){event.preventDefault();choices[0].click();}
    if(event.key==='Escape'){
      event.preventDefault();event.stopPropagation();picker=null;renderChat();content.querySelector('[data-assistant-pick]')?.focus();
    }
  });
  host.querySelector('form').addEventListener('submit',event=>{event.preventDefault();const q=input.value.trim();if(q)void send(q);});
  const surface=location.pathname.startsWith('/trajectory/personal/')?'personal':location.pathname.startsWith('/trajectory/business/')?'business':null;
  if(surface&&'serviceWorker'in navigator&&window.isSecureContext)navigator.serviceWorker.register(`/trajectory/${surface}/sw.js`,{scope:`/trajectory/${surface}/`}).catch(()=>{});
  async function refreshBadge(){
    enterSpace(spaceId());
    if(currentSpace)try{await loadInbox(true);}catch{/* Keep the last confirmed count until the next read. */}
  }
  async function refresh(){
    enterSpace(spaceId());
    if(transactionId&&!deepLinkOpened&&currentSpace){
      deepLinkOpened=true;const token=generation;
      await openInbox();if(!isCurrent(token)||view!=='inbox')return;
      let row=inbox?.rows.find(item=>item.payload.transaction_id===transactionId);
      if(!row){
        const result=await api(path(`/notifications?transaction_id=${transactionId}`));
        if(!isCurrent(token)||view!=='inbox')return;
        row=result.rows?.find(item=>item.payload.transaction_id===transactionId);
      }
      if(row)await chooseExpense(row);return;
    }
    if(dialog.open&&view==='inbox')await openInbox();else await refreshBadge();
  }
  async function contextChanged(){
    const next=spaceId();
    if(next!==currentSpace){
      const rememberedView=view;
      enterSpace(next);
      if(dialog.open){
        if(!next){dialog.close();return;}
        if(rememberedView==='chat')await openChat();
      }
    }else if(baseSelection!==baseKey()){
      baseSelection=baseKey();
      if(!busy){selectedExpense=null;action=null;picker=null;if(dialog.open&&view==='chat')renderChat();}
    }
    await refresh();
  }
  if(transactionId&&spaceId())void refresh().catch(()=>{});
  return {openInbox,openChat,refresh,refreshBadge,contextChanged,destroy(){generation++;dialog.close();host.remove();}};
}
