  @include('enterprise.read-only-assistant-script')
  @include('enterprise.conversation-script')
  @include('enterprise.saved-assistant-script')
  @include('enterprise.private-memory-script')
  const nextBase = urls.enterprise, teamBase = urls.team;
  const draftPrefix = `everbranch-enterprise-draft:${transport.mode === 'sample' ? 'sample:' : ''}${workspace}:${viewer}:`;
  const selectionMemory = new Map(), noteBusy = new Set(), planningReceipts = new Map(), planningUncertain = new Set();
  let activeDiscussionKey = null, discussionGeneration = 0, planningData = null, planningPending = null, planningBusy = false, askBusy = false, askPending = null, emailPreview = null, emailPending = null, emailGeneration = 0, gmailConnection = null, gmailBusy = false;
  const nextQuery = () => '?tenant=' + encodeURIComponent(tenantSlug);
  function readDraft(key) { try { return JSON.parse(sessionStorage.getItem(draftPrefix + key) || '{}'); } catch { return {}; } }
  function writeDraft(key, value) { try { sessionStorage.setItem(draftPrefix + key, JSON.stringify(value)); } catch {} }
  function stashComposer() {
    const input = $('discussion-body');
    if (input && activeDiscussionKey) {
      const old = readDraft(activeDiscussionKey);
      writeDraft(activeDiscussionKey, {...old, body: input.value});
    }
  }
  function rememberSelection() { stashComposer(); selectionMemory.set(area + ':' + section, selected); }
  function rememberUrl() {
    const url = new URL(location.href);
    for (const [key, value] of Object.entries({area_id: area, section, selected})) value ? url.searchParams.set(key, value) : url.searchParams.delete(key);
    transport.replace(url);
  }
  function resetNext() {
    resetReadOnlyAssistant();
    resetConversation();
    if(typeof resetVoice==='function')resetVoice();
    if (typeof resetOperations === 'function') resetOperations();
    discussionGeneration++; activeDiscussionKey = null;
    emailGeneration++; emailPending = null; emailPreview = null; $('email-form').reset(); $('email-preview').hidden = true;
    $('mailbox-status').textContent = 'No delivery transport connected. No mailbox messages are read or sent.';
    gmailConnection=null; $('gmail-connect').disabled=true; $('gmail-disconnect').hidden=true; $('gmail-status').textContent='Choose an authorized workspace to check your Gmail connection.';
    $('chat-log').replaceChildren(); $('email-preview').replaceChildren(); $('email-confirm').hidden = true;
    $('planning-list').replaceChildren(); planningData = null;
  }
  function renderNext() {
    if(typeof renderVoice==='function')renderVoice();
    renderReadOnlyAssistant();
    $('generated-ask-panel').hidden = !data.features?.ask && !data.features?.conversation_ui;
    $('ask-model-caption').hidden = !data.features?.ask && !data.features?.conversation_ui;
    renderConversation();
    $('email-context-panel').hidden = !data.features?.email_drafts;
    $('ask-nav').hidden = !data.features?.ask && !data.features?.read_only_assistant && !data.features?.conversation_ui;
    if (section === 'ask' && !data.features?.ask && !data.features?.read_only_assistant && !data.features?.conversation_ui) section = 'requests';
    $('ask').hidden = section !== 'ask';
    $('work').hidden = ['ask', 'contractors'].includes(section);
    document.querySelectorAll('[data-section]').forEach(b => b.classList.toggle('active', b.dataset.section === section));
    if (section === 'ask') { $('heading').textContent = 'Ask with the right context.'; $('breadcrumb').textContent = 'Ask Everbranch'; if(!conversationMode()&&!$('ask-question').value) $('ask-question').value=readDraft('ask:'+area).pending?.question||''; loadMailboxes(); }
    if (section === 'contractors') loadPlanning();
    rememberUrl();
  }
  async function mountDiscussion(kind, id) {
    if (!data.features?.collaboration) return;
    stashComposer(); $('discussion')?.remove();
    const key = `${area}:${kind}:${id}`, generation = ++discussionGeneration, scopeArea=area, scopeSection=section, scopeSelection=selected;
    activeDiscussionKey = key;
    const target = {kind, id, key};
    const host = document.createElement('section'); host.className = 'discussion'; host.id = 'discussion';
    const current=()=>generation===discussionGeneration&&activeDiscussionKey===key&&area===scopeArea&&section===scopeSection&&selected===scopeSelection&&host.isConnected;
    const heading=kind==='task'?'Task notes & evidence':'Notes & files';
    host.innerHTML = '<h3>Notes & files</h3><p class="caption">Checking current permissions…</p>';
    $('detail').append(host);
    try {
      const result = await api(`${nextBase}/discussion/${kind}/${id}${nextQuery()}`);
      if (!current()) return;
      const draft = readDraft(key);
      host.innerHTML = `<h3>Notes & files</h3><div class="discussion-feed" id="discussion-feed">${result.comments.map(c => `<article class="discussion-note"><p class="caption">${esc(c.created_at)} · ${esc(data.members.find(m => m.id === c.author_id)?.name || 'Workspace member')}</p><div>${esc(c.body)}</div></article>`).join('') || '<p class="caption">No notes yet.</p>'}</div><div id="discussion-files">${result.files.map(f => `<div class="file-row"><span>${esc(f.filename)} · ${esc(f.status)}</span>${f.downloadable ? `<a class="button" href="${esc(transport.href(teamBase + '/files/' + encodeURIComponent(f.id) + '/download' + nextQuery()))}">Download</a>` : f.can_manage && ['uploading','quarantined'].includes(f.status) ? `<button class="button" data-cancel-upload="${esc(f.id)}">Cancel upload</button>` : ''}</div>`).join('')}</div><p class="caption">${esc(result.coverage)}</p>${result.can_write ? `<form class="discussion-composer stack" id="discussion-form"><label>Add a note<textarea id="discussion-body" maxlength="5000" placeholder="Write a project update…">${esc(draft.body || '')}</textarea></label><label>Attach photos, PDF or text<input class="file-picker" id="discussion-files-picker" type="file" multiple accept="image/jpeg,image/png,application/pdf,text/plain,.jpg,.jpeg,.png,.pdf,.txt"></label><p class="file-notice">Up to 10 MB per file. Files attach independently to this discussion and stay unavailable for download until verified clean. After interruption, select the same file to resume. No public links are created.</p><p id="upload-status" role="status" aria-live="polite"></p><button class="button primary" id="note-submit" type="submit">${draft.pending ? 'Retry the same note' : 'Post note'}</button></form>` : '<p class="caption">Discussion is read only. Requests need an assigned task before notes or files can be added.</p>'}`;
      if ($('discussion-body')) {
        $('discussion-body').oninput = stashComposer;
        $('discussion-form').onsubmit = e => {e.preventDefault(); postNote(target);};
        $('discussion-files-picker').onchange = e => uploadFiles(target, [...e.target.files]);
      }
      host.querySelector('h3').textContent=heading;
      if(kind==='task'){const caption=document.createElement('p');caption.className='caption';caption.textContent='Internal evidence for this canonical task. Existing task and department permissions apply; requester updates are published separately.';host.querySelector('h3').after(caption);}
      if(data.features?.operations){
        const rows=[...host.querySelectorAll('#discussion-files .file-row')];
        result.files.forEach((file,index)=>{
          const row=rows[index];if(!row)return;
          const captionText=document.createElement('p');captionText.className='caption';captionText.hidden=!file.caption;captionText.textContent='Caption: '+(file.caption||'');row.append(captionText);
          if(!result.can_write||!file.can_manage||!['ready','quarantined'].includes(file.status))return;
          const draftKey=`${key}:caption:${file.id}`,saved=readDraft(draftKey);let state={value:file.caption||'',version:file.caption_version||0,...saved},sending=false;
          const form=document.createElement('form');form.className='stack';form.style.flexBasis='100%';form.style.minWidth='0';form.setAttribute('aria-label','Caption for '+file.filename);
          const label=document.createElement('label');label.textContent='File caption (metadata only)';const input=document.createElement('textarea');input.name='caption';input.maxLength=300;input.rows=2;input.value=state.value;label.append(input);
          const submit=document.createElement('button');submit.type='submit';submit.className='button';const discard=document.createElement('button');discard.type='button';discard.className='button';discard.textContent='Discard caption draft and reload';
          const status=document.createElement('p');status.className='caption';status.setAttribute('role','status');status.setAttribute('aria-live','polite');
          const lock=()=>{input.readOnly=!!state.pending||sending;submit.disabled=sending;submit.textContent=state.pending?'Retry exact caption':'Save caption';discard.disabled=sending||!!state.pending&&!state.definitive;};
          const persist=()=>{if(!state.pending)state.value=input.value;writeDraft(draftKey,state);};input.oninput=persist;
          form.onsubmit=async event=>{event.preventDefault();if(sending||!current())return;if(!state.pending)state.pending={caption:input.value,expected_version:state.version,request_id:crypto.randomUUID()};const exact=state.pending;state.definitive=false;state.uncertain=true;sending=true;persist();lock();status.textContent='Saving caption metadata; file bytes and permissions stay unchanged.';
            try{const result=await api(`${nextBase}/files/${encodeURIComponent(file.id)}/caption${nextQuery()}`,exact);writeDraft(draftKey,{});if(!current())return;state={value:result.caption,version:result.version};input.value=result.caption;captionText.hidden=!result.caption;captionText.textContent='Caption: '+result.caption;status.textContent=result.replayed?'Existing caption recovered without repeating the save.':'Caption saved.';}
            catch(error){state.uncertain=!error.status||error.status>=500;state.definitive=!state.uncertain;if([403,404].includes(error.status)){writeDraft(draftKey,{});if(current()){form.replaceChildren();status.textContent='Caption access is no longer available.';form.append(status);}return;}if(error.status===422){state.pending=null;state.uncertain=false;}writeDraft(draftKey,state);if(current())status.textContent=error.message+(state.uncertain?' Retry this exact caption; the outcome is unconfirmed.':' Your reviewed draft and version are retained.');}
            finally{sending=false;if(current())lock();}
          };
          discard.onclick=()=>{if(sending||state.uncertain||!current())return;writeDraft(draftKey,{});refreshDiscussion(target);};
          form.append(label,submit,discard,status);row.append(form);lock();
        });
      }
      host.querySelectorAll('[data-cancel-upload]').forEach(b => b.onclick = async () => {
        try { await api(`${teamBase}/file-uploads/${b.dataset.cancelUpload}/cancel${nextQuery()}`, {}); refreshDiscussion(target); }
        catch (e) { message(e.message, true); }
      });
    } catch (error) {
      if (!current()) return;
      host.replaceChildren(); host.textContent = error.message;
      // Do not redisplay retained text until this target is currently authorized.
      activeDiscussionKey = null;
    }
  }
  function refreshDiscussion(target) {
    if (activeDiscussionKey !== target.key) return;
    stashComposer(); $('discussion')?.remove(); mountDiscussion(target.kind, target.id);
  }
  async function postNote(target) {
    if (noteBusy.has(target.key)) return;
    stashComposer(); const draft = readDraft(target.key);
    if (!draft.body?.trim()) return message('Write a note first.', true);
    if (draft.pending && draft.pending.body !== draft.body) return message('Retry the retained note before changing its text. The previous submission may already have saved.', true);
    const pendingNote = draft.pending || {body: draft.body, request_id: crypto.randomUUID()};
    writeDraft(target.key, {...draft, pending: pendingNote}); noteBusy.add(target.key);
    try {
      await api(`${nextBase}/discussion/${target.kind}/${target.id}/comments${nextQuery()}`, pendingNote);
      const current = readDraft(target.key);
      writeDraft(target.key, {body: current.body === pendingNote.body ? '' : current.body});
      if (activeDiscussionKey === target.key && $('discussion-body')?.value === pendingNote.body) $('discussion-body').value = '';
      refreshDiscussion(target); message('Note saved.');
    } catch (error) { message(error.message + ' The exact note is retained for retry.', true); }
    finally { noteBusy.delete(target.key); }
  }
  async function digestBytes(bytes) { return [...new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))].map(x => x.toString(16).padStart(2,'0')).join(''); }
  async function uploadFiles(target, files) {
    const refreshKey = 'upload-batch:' + crypto.randomUUID();
    globalThis.EverbranchTransport.setRefreshBlocker(refreshKey, true);
    try {
    const status = text => { if (activeDiscussionKey === target.key && $('upload-status')) $('upload-status').textContent = text; };
    for (const file of files) {
      try {
        if (file.size < 1 || file.size > 10485760 || !['image/jpeg','image/png','application/pdf','text/plain'].includes(file.type)) throw new Error('Choose a JPEG, PNG, PDF or UTF-8 text file up to 10 MB.');
        const bytes = await file.arrayBuffer(), sha256 = await digestBytes(bytes);
        const fingerprint = await digestBytes(new TextEncoder().encode(target.key + ':' + file.name + ':' + file.type + ':' + sha256));
        const key = 'upload:' + fingerprint, saved = readDraft(key);
        let clientKey = saved.client_key || crypto.randomUUID(); writeDraft(key, {client_key: clientKey});
        const open = await api(`${nextBase}/discussion/${target.kind}/${target.id}/open${nextQuery()}`, {});
        const begin = () => api(`${teamBase}/tasks/${open.task_id}/files${nextQuery()}`, {client_key: clientKey, filename: file.name, mime_type: file.type, size: file.size, sha256});
        let upload = await begin();
        if (['cancelled','rejected'].includes(upload.status)) { clientKey = crypto.randomUUID(); writeDraft(key, {client_key: clientKey}); upload = await begin(); }
        if (upload.status === 'uploading') {
          for (let index = 0; index < Math.ceil(file.size / upload.chunk_bytes); index++) {
            if (upload.received_chunks.includes(index)) continue;
            status(`${file.name}: uploading part ${index + 1}…`);
            await api(`${teamBase}/file-uploads/${upload.id}/chunks/${index}${nextQuery()}`, bytes.slice(index * upload.chunk_bytes, (index + 1) * upload.chunk_bytes), {method:'PUT', binary:true});
          }
        }
        const completed = upload.status === 'ready' ? upload : await api(`${teamBase}/file-uploads/${upload.id}/complete${nextQuery()}`, {});
        refreshDiscussion(target);
        message(`${file.name}: ${completed.status === 'ready' ? 'verified and attached' : completed.status === 'quarantined' ? 'stored privately; held until a scanner is available' : 'rejected'}.`);
      } catch (error) { status(`${file.name}: ${error.message} Select the same file to resume.`); message(error.message, true); break; }
    }
    } finally { globalThis.EverbranchTransport.setRefreshBlocker(refreshKey, false); }
  }
  async function loadPlanning() {
    $('planning-work').hidden = !data.features?.planning || !area;
    if (!data.features?.planning || !area) return;
    const requestedArea = area;
    try {
      const result = await api(`${nextBase}/planning${nextQuery()}&area_id=${encodeURIComponent(area)}`);
      if (area !== requestedArea || section !== 'contractors') return;
      planningData = result; $('planning-status').textContent = result.basis + (result.partial ? ' Results are partial.' : '');
      planningPending = planningReceipts.get(area) || null; $('planning-retry').hidden = !planningPending;
      $('planning-form').hidden = !result.can_coordinate; $('cost-option').hidden = !result.can_finance;
      $('planning-project').innerHTML = data.projects.filter(p => !p.archived).map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
      $('planning-contractor').innerHTML = result.items.filter(r => r.kind === 'contractor' && r.status === 'active').map(r => `<option value="${esc(r.id)}">${esc(r.title)}</option>`).join('');
      $('planning-list').innerHTML = result.items.map(r => `<article class="planning-record" id="record-${esc(r.id)}"><strong>${esc(r.title)}</strong><p class="caption">${esc(r.kind)} · ${esc(r.status)} · version ${r.version}${r.kind === 'schedule' ? `<br>${esc(r.starts_on)} through ${esc(r.ends_on)} (inclusive)` : ''}${r.kind === 'cost' ? `<br>${esc(r.data.amount_minor)} minor units ${esc(r.data.currency)} · ${esc(r.data.basis)}` : ''}</p>${r.kind === 'schedule' && r.status === 'planned' && result.can_coordinate ? `<button class="button" data-cancel-plan="${esc(r.id)}">Cancel date block</button>` : ''}</article>`).join('') || '<p class="caption">No planning records in the checked sample.</p>';
      $('planning-list').querySelectorAll('[data-cancel-plan]').forEach(b => b.onclick = () => {
        const row = planningData.items.find(r => r.id === b.dataset.cancelPlan);
        savePlanning({kind:row.kind,id:row.id,area_id:area,expected_version:row.version,title:row.title,status:'cancelled',starts_on:row.starts_on,ends_on:row.ends_on,...row.data,request_id:crypto.randomUUID()});
      });
      planningFields();
      if (selected) document.getElementById('record-' + selected)?.scrollIntoView({block:'nearest'});
    } catch (error) { $('planning-list').replaceChildren(); message(error.message, true); }
  }
  function planningFields() {
    const kind = $('planning-kind').value;
    $('planning-project-field').hidden = kind === 'contractor'; $('planning-schedule-fields').hidden = kind !== 'schedule'; $('planning-cost-fields').hidden = kind !== 'cost';
  }
  async function savePlanning(body) {
    if (planningBusy || body.area_id !== area) return; planningBusy = true; planningPending = body; planningReceipts.set(body.area_id, body);
    globalThis.EverbranchTransport.setRefreshBlocker('planning:' + body.area_id, true);
    try { await api(`${nextBase}/planning${nextQuery()}`, body); planningReceipts.delete(body.area_id); planningUncertain.delete(body.request_id); globalThis.EverbranchTransport.setRefreshBlocker('planning:' + body.area_id, false); if (area === body.area_id) { planningPending = null; $('planning-retry').hidden = true; $('planning-form').reset(); await loadPlanning(); message('Planning record saved.'); } }
    catch (error) {
      const rejected = !planningUncertain.has(body.request_id) && [403,404,409,422].includes(error.status);
      if (rejected) { planningReceipts.delete(body.area_id); globalThis.EverbranchTransport.setRefreshBlocker('planning:' + body.area_id, false); }
      else planningUncertain.add(body.request_id);
      if (area === body.area_id) {
        if (rejected) planningPending = null;
        $('planning-retry').hidden = rejected;
        message(error.message + (rejected ? ' The server rejected this action. Review current access and correct or reload the form before trying again.' : ' Exact record retained for retry. Refresh is paused until its outcome is confirmed.'), true);
      }
    }
    finally { planningBusy = false; }
  }
  $('planning-kind').onchange = planningFields;
  $('planning-retry').onclick = () => planningPending && savePlanning(planningPending);
  $('planning-form').onsubmit = e => {
    e.preventDefault(); if (planningPending) return message('Retry the retained record first.', true);
    const f = new FormData(e.target), kind = f.get('kind');
    const body = {kind,id:crypto.randomUUID(),area_id:area,expected_version:0,title:f.get('title'),status:kind === 'schedule' ? 'planned' : kind === 'cost' ? 'recorded' : 'active',request_id:crypto.randomUUID()};
    const keys = kind === 'contractor' ? [] : kind === 'schedule' ? ['project_id','contractor_id','starts_on','ends_on'] : ['project_id','amount_minor','currency','basis'];
    for (const key of keys) body[key] = f.get(key);
    savePlanning(body);
  };
  function chatMessage(text, className) { const node = document.createElement('div'); node.className = 'chat-turn ' + (className || ''); node.textContent = text; $('chat-log').append(node); return node; }
  $('ask-form').onsubmit = async e => {
    if(conversationMode())return conversationSend(e);
    e.preventDefault(); if (askBusy || !area) return; const question = $('ask-question').value.trim(); if (!question) return;
    askPending = readDraft('ask:' + area).pending || null;
    if (askPending && askPending.question !== question) return message('The previous Ask request is retained. Retry its exact text to check the outcome before asking a different question.', true);
    askPending ||= {area_id:area,question,request_id:crypto.randomUUID()};
    writeDraft('ask:' + area, {pending:askPending});
    askBusy = true; $('ask-submit').disabled = true; const requestedArea = area;
    chatMessage(question,'question'); $('ask-question').value = '';
    try {
      const result = await api(`${nextBase}/ask${nextQuery()}`, askPending);
      if (area !== requestedArea) return;
      askPending = null;
      writeDraft('ask:' + requestedArea, {});
      const answer = chatMessage(result.reply);
      const labels = {status:'Status',due_on:'Due date',starts_on:'Start date',ends_on:'End date (inclusive)',amount_minor:'Amount in minor units',currency:'Currency',basis:'Basis',updated_at:'Recorded at'};
      for (const source of result.sources) {
        const facts = document.createElement('p'); facts.textContent = `${source.title} — ${Object.entries(source.facts).filter(([key]) => key !== 'version').map(([k,v]) => `${labels[k] || k}: ${v ?? 'not recorded'}`).join('; ')}`; answer.append(facts);
        const url = new URL(source.url, location.href);
        if (url.origin === location.origin) { const link = document.createElement('a'); link.href = transport.href(url.href); link.textContent = `View source: ${source.title}`; answer.append(link); }
      }
      const coverage = document.createElement('p'); coverage.className = 'caption'; coverage.textContent = `${result.model_status} As of ${result.as_of}. ${result.coverage}`; answer.append(coverage);
      answer.scrollIntoView({block:'nearest'});
    } catch (error) { if (area === requestedArea) { $('chat-log').replaceChildren(); chatMessage(error.message); $('ask-question').value = question; } }
    finally { askBusy = false; $('ask-submit').disabled = false; }
  };
  async function loadMailboxes() {
    const generation = ++emailGeneration;
    $('email-form').hidden = true; $('email-confirm').hidden = true; emailPreview = null;
    if (!area || !data.features?.email_drafts) return;
    const requestedArea = area;
    try {
      const [mailboxResult,gmailResult] = await Promise.allSettled([api(`${nextBase}/mailboxes${nextQuery()}&area_id=${encodeURIComponent(area)}`),api(`${nextBase}/gmail${nextQuery()}&area_id=${encodeURIComponent(area)}`)]);
      if (area !== requestedArea || section !== 'ask' || generation !== emailGeneration) return;
      const result=mailboxResult.status==='fulfilled'?mailboxResult.value:null;
      $('mailbox-status').textContent = result?result.status:mailboxResult.reason.message;
      $('email-mailbox').innerHTML = result?result.mailboxes.map(m => `<option value="${esc(m.id)}">${esc(m.address)}</option>`).join(''):'';
      $('email-form').hidden = !result?.mailboxes.length;
      if(gmailResult.status!=='fulfilled')throw gmailResult.reason;
      const gmail=gmailResult.value;
      gmailConnection=gmail.connection_id?gmail:null;
      $('gmail-status').textContent=gmail.status;
      $('gmail-connect').disabled=!gmail.can_connect||gmailBusy; $('gmail-connect').textContent=gmail.connected?'Reconnect Google Workspace':'Connect Google Workspace';
      $('gmail-disconnect').hidden=!gmail.connection_id; $('gmail-disconnect').disabled=gmailBusy;
    } catch (error) { if(area!==requestedArea||section!=='ask'||generation!==emailGeneration)return; gmailConnection=null; $('gmail-connect').disabled=true;$('gmail-disconnect').hidden=true;$('mailbox-status').textContent = error.message; }
  }
  $('gmail-connect').onclick=async()=>{
    if(gmailBusy||!area)return;gmailBusy=true;$('gmail-connect').disabled=true;const requestedArea=area,generation=emailGeneration;
    try {
      const result=await api(`${nextBase}/gmail/connect${nextQuery()}`,{area_id:requestedArea});
      if(area!==requestedArea||section!=='ask'||generation!==emailGeneration)return;
      const url=new URL(result.authorization_url);
      if(url.origin!=='https://accounts.google.com'||url.pathname!=='/o/oauth2/v2/auth'||url.username||url.password||url.hash)throw new Error('An unsupported Google consent destination was returned.');
      transport.navigate(url.href);
    }catch(error){if(area===requestedArea&&generation===emailGeneration)message(error.message,true);}
    finally{gmailBusy=false;if(area===requestedArea&&section==='ask')loadMailboxes();}
  };
  $('gmail-disconnect').onclick=async()=>{
    if(gmailBusy||!gmailConnection||!area)return;
    const exact=gmailConnection,requestedArea=area,generation=emailGeneration;gmailBusy=true;$('gmail-disconnect').disabled=true;
    try {const result=await api(`${nextBase}/gmail/${encodeURIComponent(exact.connection_id)}/disconnect${nextQuery()}`,{area_id:requestedArea});if(area!==requestedArea||section!=='ask'||generation!==emailGeneration)return;emailPreview=null;emailPending=null;$('email-preview').replaceChildren();$('email-preview').hidden=true;$('email-confirm').hidden=true;message(result.provider_revocation==='uncertain'?'Gmail is disconnected here. Google revocation could not be confirmed; review access in your Google account.':'Gmail disconnected. Existing approvals can no longer send.');}
    catch(error){if(area===requestedArea&&generation===emailGeneration)message(error.message,true);}
    finally{gmailBusy=false;if(area===requestedArea&&section==='ask')loadMailboxes();}
  };
  $('email-form').oninput = () => {emailGeneration++; emailPreview = null; emailPending = null; $('email-confirm').hidden = true; $('email-preview').hidden = true;};
  $('email-form').onsubmit = async e => {
    e.preventDefault(); const generation = emailGeneration; const body = emailPending || {area_id:area,mailbox_id:$('email-mailbox').value,to:$('email-to').value,subject:$('email-subject').value,body:$('email-body').value,source_refs:[],request_id:crypto.randomUUID()}; if (body.area_id !== area) return; emailPending = body;
    try { const result = await api(`${nextBase}/email-drafts${nextQuery()}`, body); if (area !== body.area_id || section !== 'ask' || generation !== emailGeneration || result.area_id !== area) return; emailPreview = result; $('email-preview').hidden = false; $('email-preview').textContent = `From: ${result.from}\nTo: ${result.to}\nSubject: ${result.subject}\n\n${result.body}\n\nApproval expires: ${result.expires_at}`; $('email-confirm').hidden = !result.delivery_ready || result.status !== 'draft'; }
    catch (error) { message(error.message, true); }
  };
  $('email-confirm').onclick = async () => {
    if (!emailPreview || emailPreview.area_id !== area || section !== 'ask') return; const exact = emailPreview, generation = emailGeneration; $('email-confirm').disabled = true;
    try { const result = await api(`${nextBase}/email-drafts/${exact.id}/confirm${nextQuery()}`, {digest:exact.digest,confirmed:true}); if (area !== exact.area_id || section !== 'ask' || generation !== emailGeneration) return; $('email-confirm').hidden = true; message(result.status === 'accepted' ? 'Provider accepted this email. Delivery is not confirmed.' : 'Outcome uncertain. Reconcile the existing attempt; do not resend.', result.status !== 'accepted'); }
    catch (error) { message(error.message, true); }
    finally { $('email-confirm').disabled = false; }
  };
  $('enterprise-logout')?.addEventListener('submit', () => { for (const key of Object.keys(sessionStorage)) if (key.startsWith(draftPrefix) || key.startsWith(`enterprise-workflow:${workspace}:${viewer}:`)) sessionStorage.removeItem(key); });
  window.addEventListener('pagehide', stashComposer);
  window.visualViewport?.addEventListener('resize', () => {document.documentElement.style.setProperty('--visible-height', window.visualViewport.height + 'px'); if (document.activeElement?.matches('#discussion-body,#ask-question')) document.activeElement.scrollIntoView({block:'nearest'});});
