import './assistant-ui.css';

/** One private conversation surface across financial drilldowns and expense notifications. */
export function initTrajectoryAssistant({root, getContext, api, endpoint, notice = () => {}, onChanged = () => {}}) {
  if (!root) return null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => value == null ? 'Not budgeted' : new Intl.NumberFormat('en-US', {style:'currency',currency:'USD'}).format(value / 100);
  const host = document.createElement('div'); host.className = 'tr-assistant';
  host.innerHTML = `<button type="button" class="tr-assistant-launch" aria-haspopup="dialog">Ask Everbranch <span data-assistant-badge hidden></span></button><dialog class="tr-assistant-panel" aria-label="Ask Everbranch financial assistant"><header><div><strong>Ask Everbranch</strong><small data-assistant-scope></small></div><button type="button" data-assistant-close aria-label="Close Ask Everbranch">×</button></header><nav aria-label="Assistant views"><button type="button" data-assistant-chat>Conversation</button><button type="button" data-assistant-inbox>Expenses</button></nav><div data-assistant-content class="tr-assistant-content" aria-live="polite"></div><form data-assistant-form><label for="tr-assistant-question">Ask about this view or categorize the selected purchase</label><div><input id="tr-assistant-question" name="question" maxlength="2000" autocomplete="off" required placeholder="e.g. Move this to groceries"><button type="submit">Send</button></div></form></dialog>`;
  root.append(host);
  const dialog = host.querySelector('dialog'), content = host.querySelector('[data-assistant-content]'), input = host.querySelector('input');
  let currentSpace = null, loadedSpace = null, messages = [], inbox = null, selectedExpense = null, busy = false, view = 'chat';
  const requestIds = new Map();
  const transactionId=Number(new URLSearchParams(location.search).get('transaction'));
  let deepLinkOpened=false;
  const context = () => {
    const raw = getContext?.() || {};
    const allowed = ['from','through','account_id','program_id','level','direction','category','subcategory','transaction_ids','transaction_versions','space_id','basis','selection_truncated','focused_transaction'];
    const value = Object.fromEntries(Object.entries(raw).filter(([key, v]) => allowed.includes(key) && v != null));
    if (value.direction && !['in','out','income','expense','both'].includes(value.direction)) delete value.direction;
    if (Array.isArray(value.transaction_ids)) value.transaction_ids = [...new Set(value.transaction_ids)].slice(0,100);
    return selectedExpense ? {...value, from:selectedExpense.date, through:selectedExpense.date, account_id:null, program_id:null, category:null, subcategory:null, direction:'out', level:'transaction', basis:'spending', focused_transaction:true, selection_truncated:false, transaction_ids:[selectedExpense.transaction_id], transaction_versions:{[selectedExpense.transaction_id]:selectedExpense.version}} : value;
  };
  const spaceId = () => getContext?.()?.scope==='both'?null:Number(getContext?.()?.space_id) || null;
  const path = suffix => endpoint(suffix, currentSpace || spaceId());
  function showError(error) {messages.push({role:'assistant',reply:error.message || 'Unable to complete this request.'}); renderChat();}
  function renderChat() {
    view='chat'; content.innerHTML = messages.length ? messages.map(message => `<article class="tr-assistant-message ${message.role}"><small>${message.role==='user'?'You':'Everbranch'}</small><div>${esc(message.reply).replaceAll('\n','<br>')}</div>${message.proposal?`<button type="button" data-assistant-apply="${message.id}">Apply these ${message.proposal.actions?.length || ''} changes</button>`:''}${message.undo?.available?`<button type="button" data-assistant-undo="${message.id}">Undo</button>`:''}${message.choices?.length?`<div class="tr-assistant-choices">${message.choices.map(choice=>`<button type="button" data-assistant-choice="${esc(choice.type+' '+choice.label)}">${esc(choice.label)} · ${esc(choice.type)}</button>`).join('')}</div>`:''}</article>`).join('') : '<p>Select a chart, category or purchase, then ask about it here. Explicit category instructions save with Undo. Suggested batches are reviewed first.</p>';
    host.querySelector('[data-assistant-form]').hidden = false;
    content.scrollTop=content.scrollHeight;
  }
  async function loadHistory() {
    if (!currentSpace || loadedSpace===currentSpace) return;
    messages=[]; selectedExpense=null;
    const result=await api(path('/assistant'));
    messages=(result.rows||[]).flatMap(turn=>[{role:'user',reply:turn.question},{role:'assistant',id:turn.id,...(turn.response||{reply:'This request has not completed.'})}]);
    loadedSpace=currentSpace;
  }
  async function openChat() {
    currentSpace=spaceId(); if(!currentSpace){notice('Select a personal or business workspace before asking.');return;}
    if(!dialog.open)dialog.showModal();
    host.querySelector('[data-assistant-scope]').textContent=selectedExpense?'Selected purchase':`Current financial view · ${getContext?.()?.category||getContext?.()?.level||'overview'}`;
    try{await loadHistory();renderChat();input.focus();}catch(error){showError(error);}
  }
  async function loadInbox() {
    currentSpace=spaceId(); if(!currentSpace)return;
    inbox=await api(path('/notifications'));
    const badge=host.querySelector('[data-assistant-badge]');badge.hidden=!inbox.unread_count;badge.textContent=inbox.unread_count || '';
  }
  const noticeRow = row => row.payload.unavailable ? '<div class="tr-expense-notice"><strong>Purchase no longer available in this household</strong><span>Its ownership, purpose or bank status changed.</span></div>' : `<button type="button" class="tr-expense-notice" data-open-expense="${row.id}"><strong>${esc(row.payload.merchant)} · ${money(row.payload.amount_cents)}</strong><span>${esc(row.payload.date)} · ${esc(row.payload.category_label)}${row.payload.pending?' · Pending':''}${row.payload.removed?' · Removed':''}</span><span>${row.payload.remaining_cents==null?'Budget not established':money(row.payload.remaining_cents)+' left in category'}${row.payload.needs_purpose?' · What was this for?':''}</span></button>`;
  async function openInbox() {
    currentSpace=spaceId();if(!currentSpace){notice('Select one workspace to open its expense inbox.');return;}
    if(!dialog.open)dialog.showModal();
    try{
      await loadInbox();view='inbox';host.querySelector('[data-assistant-form]').hidden=true;
      const prefs=inbox.preferences;
      content.innerHTML=`<div class="tr-assistant-preferences"><strong>Personal expense notifications</strong><label><input type="checkbox" data-alert-enabled ${prefs.enabled?'checked':''}> Notify me about new purchases</label><label><input type="checkbox" data-alert-pending ${prefs.include_pending?'checked':''}> Include pending purchases</label><label><input type="checkbox" data-alert-details ${prefs.show_details?'checked':''}> Show merchant, amount, category and budget left on lock screen</label><button type="button" data-save-alerts>Save preferences</button><button type="button" data-enable-push ${inbox.push.ready?'':'disabled'}>Enable phone notifications</button><small>${inbox.push.ready?'Notifications arrive when the bank feed reports a purchase.':'Push delivery needs server setup. Your expense inbox still works.'}</small><a href="/trajectory/personal/">Open Personal app</a> · <a href="/trajectory/business/">Open Business app</a><small>iPhone: open the app in Safari → Share → Add to Home Screen, then enable notifications from the installed app.</small></div>${(inbox.rows||[]).length?inbox.rows.map(noticeRow).join(''):'<p>No new expenses yet. New feed purchases will appear after you opt in; historical imports do not create a backlog of alerts.</p>'}${inbox.next_before?'<button type="button" data-load-expenses>Earlier expenses</button>':''}`;
    }catch(error){showError(error);}
  }
  async function chooseExpense(row) {
    await api(path(`/notifications/${row.id}/read`),'PATCH',{});
    await loadHistory();selectedExpense=row.payload;
    messages.push({role:'assistant',reply:`${row.payload.merchant} · ${money(row.payload.amount_cents)} · ${row.payload.date}\n${row.payload.needs_purpose?'What was this purchase for?':'Categorized as '+row.payload.category_label+'. You can change its category or ask about it.'}`});
    renderChat();input.focus();
  }
  async function send(question) {
    if(busy)return;currentSpace=spaceId();if(!currentSpace)return;
    busy=true;input.disabled=true;messages.push({role:'user',reply:question});renderChat();
    const submittedContext=context();
    try{
      const requestKey=JSON.stringify([currentSpace,question,submittedContext]);
      if(!requestIds.has(requestKey))requestIds.set(requestKey,crypto.randomUUID());
      const result=await api(path('/assistant'),'POST',{question,context:submittedContext,request_id:requestIds.get(requestKey),use_ai:true});
      messages.push({role:'assistant',...result});
      if(result.applied){selectedExpense=null;await onChanged();}
    }catch(error){messages.push({role:'assistant',reply:error.message});}
    finally{busy=false;input.disabled=false;input.value='';renderChat();input.focus();}
  }
  async function enablePush() {
    if(!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))throw new Error('Install the Personal app on a supported phone/browser to enable push. The inbox remains available.');
    const surface=location.pathname.startsWith('/trajectory/personal/')?'personal':null;
    if(!surface)throw new Error('Open the Personal app, add it to your Home Screen, then enable phone notifications there.');
    if(await Notification.requestPermission()!=='granted')throw new Error('Notification permission was not granted. Expenses remain in your inbox.');
    const registration=await navigator.serviceWorker.register('/trajectory/personal/sw.js',{scope:'/trajectory/personal/'});
    await navigator.serviceWorker.ready;
    const bytes=Uint8Array.from(atob(inbox.push.public_key.replaceAll('-','+').replaceAll('_','/')),c=>c.charCodeAt(0));
    const subscription=await registration.pushManager.getSubscription()||await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:bytes});
    await api(path('/push-subscriptions'),'POST',subscription.toJSON());
    notice('Phone notifications enabled for your personal workspace.');
  }
  host.addEventListener('click',async event=>{
    const button=event.target.closest('button');if(!button)return;
    try{
      if(button.matches('.tr-assistant-launch,[data-assistant-chat]'))await openChat();
      else if(button.hasAttribute('data-assistant-close'))dialog.close();
      else if(button.hasAttribute('data-assistant-inbox'))await openInbox();
      else if(button.hasAttribute('data-open-expense')){const row=inbox?.rows.find(row=>row.id===Number(button.dataset.openExpense));if(row&&!row.payload.removed)await chooseExpense(row);}
      else if(button.hasAttribute('data-load-expenses')){const older=await api(path(`/notifications?before=${inbox.next_before}`));inbox.rows.push(...older.rows);inbox.next_before=older.next_before;button.insertAdjacentHTML('beforebegin',older.rows.map(noticeRow).join(''));if(!older.next_before)button.remove();}
      else if(button.hasAttribute('data-assistant-choice'))await send(`Move this to ${button.dataset.assistantChoice}`);
      else if(button.hasAttribute('data-assistant-apply')||button.hasAttribute('data-assistant-undo')){
        const action=button.hasAttribute('data-assistant-apply')?'apply':'undo',id=button.dataset[action==='apply'?'assistantApply':'assistantUndo'];
        const result=await api(path(`/assistant/${id}/${action}`),'POST',{});messages=messages.map(message=>message.id===Number(id)?{role:'assistant',...result}:message);await onChanged();renderChat();
      }else if(button.hasAttribute('data-save-alerts')){
        await api(path('/notifications/preferences'),'PATCH',{enabled:content.querySelector('[data-alert-enabled]').checked,include_pending:content.querySelector('[data-alert-pending]').checked,show_details:content.querySelector('[data-alert-details]').checked});notice('Your personal expense notification preferences were saved.');
      }else if(button.hasAttribute('data-enable-push'))await enablePush();
    }catch(error){showError(error);}
  });
  host.querySelector('form').addEventListener('submit',event=>{event.preventDefault();const q=input.value.trim();if(q)void send(q);});
  const surface=location.pathname.startsWith('/trajectory/personal/')?'personal':location.pathname.startsWith('/trajectory/business/')?'business':null;
  if(surface&&'serviceWorker'in navigator&&window.isSecureContext)navigator.serviceWorker.register(`/trajectory/${surface}/sw.js`,{scope:`/trajectory/${surface}/`}).catch(()=>{});
  async function refresh(){if(transactionId&&!deepLinkOpened&&spaceId()){deepLinkOpened=true;await openInbox();let row=inbox?.rows.find(row=>row.payload.transaction_id===transactionId);if(!row){const result=await api(path(`/notifications?transaction_id=${transactionId}`));row=result.rows?.find(item=>item.payload.transaction_id===transactionId);}if(row)await chooseExpense(row);return;}if(dialog.open&&view==='inbox')await openInbox();else{try{await loadInbox();}catch{/* Availability is independent from finance navigation. */}}}
  async function contextChanged(){const next=spaceId();if(currentSpace&&next!==currentSpace){currentSpace=next;loadedSpace=null;selectedExpense=null;messages=[];if(dialog.open)await openChat();}await refresh();}
  if(transactionId&&spaceId())void refresh().catch(()=>{});
  return {openInbox,openChat,refresh,contextChanged,destroy(){dialog.close();host.remove();}};
}
