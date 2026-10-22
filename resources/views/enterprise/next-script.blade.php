  const nextBase = @json(url('/enterprise')), teamBase = @json(url('/team'));
  const draftPrefix = `everbranch-enterprise-draft:${workspace}:${viewer}:`;
  const selectionMemory = new Map(), noteBusy = new Set(), planningReceipts = new Map();
  let activeDiscussionKey = null, discussionGeneration = 0, planningData = null, planningPending = null, planningBusy = false, askBusy = false, askPending = null, emailPreview = null, emailPending = null, emailGeneration = 0;
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
    history.replaceState(null, '', url);
  }
  function resetNext() {
    discussionGeneration++; activeDiscussionKey = null;
    emailGeneration++; emailPending = null; emailPreview = null; $('email-form').reset(); $('email-preview').hidden = true;
    $('mailbox-status').textContent = 'No delivery transport connected. No mailbox messages are read or sent.';
    $('chat-log').replaceChildren(); $('email-preview').replaceChildren(); $('email-confirm').hidden = true;
    $('planning-list').replaceChildren(); planningData = null;
  }
  function renderNext() {
    $('ask-nav').hidden = !data.features?.ask;
    if (section === 'ask' && !data.features?.ask) section = 'requests';
    $('ask').hidden = section !== 'ask';
    $('work').hidden = ['ask', 'contractors'].includes(section);
    document.querySelectorAll('[data-section]').forEach(b => b.classList.toggle('active', b.dataset.section === section));
    if (section === 'ask') { $('heading').textContent = 'Ask with the right context.'; $('breadcrumb').textContent = 'Ask Everbranch'; if(!$('ask-question').value) $('ask-question').value=readDraft('ask:'+area).pending?.question||''; loadMailboxes(); }
    if (section === 'contractors') loadPlanning();
    rememberUrl();
  }
  async function mountDiscussion(kind, id) {
    if (!data.features?.collaboration) return;
    const key = `${area}:${kind}:${id}`, generation = ++discussionGeneration;
    activeDiscussionKey = key;
    const target = {kind, id, key};
    const host = document.createElement('section'); host.className = 'discussion'; host.id = 'discussion';
    host.innerHTML = '<h3>Notes & files</h3><p class="caption">Checking current permissions…</p>';
    $('detail').append(host);
    try {
      const result = await api(`${nextBase}/discussion/${kind}/${id}${nextQuery()}`);
      if (generation !== discussionGeneration || activeDiscussionKey !== key) return;
      const draft = readDraft(key);
      host.innerHTML = `<h3>Notes & files</h3><div class="discussion-feed" id="discussion-feed">${result.comments.map(c => `<article class="discussion-note"><p class="caption">${esc(c.created_at)} · ${esc(data.members.find(m => m.id === c.author_id)?.name || 'Workspace member')}</p><div>${esc(c.body)}</div></article>`).join('') || '<p class="caption">No notes yet.</p>'}</div><div id="discussion-files">${result.files.map(f => `<div class="file-row"><span>${esc(f.filename)} · ${esc(f.status)}</span>${f.downloadable ? `<a class="button" href="${esc(teamBase)}/files/${encodeURIComponent(f.id)}/download${nextQuery()}">Download</a>` : f.can_manage && ['uploading','quarantined'].includes(f.status) ? `<button class="button" data-cancel-upload="${esc(f.id)}">Cancel upload</button>` : ''}</div>`).join('')}</div><p class="caption">${esc(result.coverage)}</p>${result.can_write ? `<form class="discussion-composer stack" id="discussion-form"><label>Add a note<textarea id="discussion-body" maxlength="5000" placeholder="Write a project update…">${esc(draft.body || '')}</textarea></label><label>Attach photos, PDF or text<input class="file-picker" id="discussion-files-picker" type="file" multiple accept="image/jpeg,image/png,application/pdf,text/plain,.jpg,.jpeg,.png,.pdf,.txt"></label><p class="file-notice">Up to 10 MB per file. Files attach independently to this discussion and stay unavailable for download until verified clean. After interruption, select the same file to resume. No public links are created.</p><p id="upload-status" role="status" aria-live="polite"></p><button class="button primary" id="note-submit" type="submit">${draft.pending ? 'Retry the same note' : 'Post note'}</button></form>` : '<p class="caption">Discussion is read only. Requests need an assigned task before notes or files can be added.</p>'}`;
      if ($('discussion-body')) {
        $('discussion-body').oninput = stashComposer;
        $('discussion-form').onsubmit = e => {e.preventDefault(); postNote(target);};
        $('discussion-files-picker').onchange = e => uploadFiles(target, [...e.target.files]);
      }
      host.querySelectorAll('[data-cancel-upload]').forEach(b => b.onclick = async () => {
        try { await api(`${teamBase}/file-uploads/${b.dataset.cancelUpload}/cancel${nextQuery()}`, {}); refreshDiscussion(target); }
        catch (e) { message(e.message, true); }
      });
    } catch (error) {
      if (generation !== discussionGeneration) return;
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
            const response = await fetch(`${teamBase}/file-uploads/${upload.id}/chunks/${index}${nextQuery()}`, {method:'PUT', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/octet-stream','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'X-Team-Viewer':viewer,'X-Team-Workspace':workspace}, body:bytes.slice(index * upload.chunk_bytes, (index + 1) * upload.chunk_bytes)});
            const result = await response.json(); if (!response.ok) throw new Error(result.message || 'Upload interrupted.');
          }
        }
        const completed = upload.status === 'ready' ? upload : await api(`${teamBase}/file-uploads/${upload.id}/complete${nextQuery()}`, {});
        refreshDiscussion(target);
        message(`${file.name}: ${completed.status === 'ready' ? 'verified and attached' : completed.status === 'quarantined' ? 'stored privately; held until a scanner is available' : 'rejected'}.`);
      } catch (error) { status(`${file.name}: ${error.message} Select the same file to resume.`); message(error.message, true); break; }
    }
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
    try { await api(`${nextBase}/planning${nextQuery()}`, body); planningReceipts.delete(body.area_id); if (area === body.area_id) { planningPending = null; $('planning-retry').hidden = true; $('planning-form').reset(); await loadPlanning(); message('Planning record saved.'); } }
    catch (error) { if (area === body.area_id) { $('planning-retry').hidden = false; message(error.message + ' Exact record retained for retry.', true); } }
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
        if (url.origin === location.origin) { const link = document.createElement('a'); link.href = url.href; link.textContent = `View source: ${source.title}`; answer.append(link); }
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
      const result = await api(`${nextBase}/mailboxes${nextQuery()}&area_id=${encodeURIComponent(area)}`);
      if (area !== requestedArea || section !== 'ask' || generation !== emailGeneration) return;
      $('mailbox-status').textContent = result.status;
      $('email-mailbox').innerHTML = result.mailboxes.map(m => `<option value="${esc(m.id)}">${esc(m.address)}</option>`).join('');
      $('email-form').hidden = !result.mailboxes.length;
    } catch (error) { $('mailbox-status').textContent = error.message; }
  }
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
  document.querySelector('form[action="{{ route('logout') }}"]')?.addEventListener('submit', () => { for (const key of Object.keys(sessionStorage)) if (key.startsWith(draftPrefix) || key.startsWith(`enterprise-workflow:${workspace}:${viewer}:`)) sessionStorage.removeItem(key); });
  window.addEventListener('pagehide', stashComposer);
  window.visualViewport?.addEventListener('resize', () => {document.documentElement.style.setProperty('--visible-height', window.visualViewport.height + 'px'); if (document.activeElement?.matches('#discussion-body,#ask-question')) document.activeElement.scrollIntoView({block:'nearest'});});
