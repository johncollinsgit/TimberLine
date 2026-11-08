// The event discussion uses the shared Teams upload and command services. Keep
// unfinished work in page memory, scoped to its original actor and event.
const drafts = new Map();
const supported = new Map([['image/jpeg', ['jpg', 'jpeg']], ['image/png', ['png']], ['application/pdf', ['pdf']], ['text/plain', ['txt']]]);
const dirty = state => !!(state.body.trim() || state.files.length || state.savedFileIds?.length || state.intent || state.busy);
export const hasEventUpdateDrafts = () => [...drafts.values()].some(dirty);

function node(tag, text = '', cls = '') {
  const result = document.createElement(tag); result.className = cls; result.textContent = text; return result;
}
function action(text, run, cls = '') {
  const result = node('button', text, cls); result.type = 'button'; result.addEventListener('click', run); return result;
}
function icon(kind) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none'); svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(svg.namespaceURI, 'path'); path.setAttribute('d', kind === 'attach' ? 'm8 13 6-6a3 3 0 0 1 4 4l-8 8a5 5 0 0 1-7-7l9-9' : 'M12 19V5m-6 6 6-6 6 6');
  svg.append(path); return svg;
}
function safeUrl(value, sameOrigin = false) {
  if (typeof value !== 'string' || !value) return null;
  try { const url = new URL(value, location.origin); return ['http:', 'https:'].includes(url.protocol) && (!sameOrigin || url.origin === location.origin) ? url.href : null; } catch { return null; }
}
function pathFor(value) {
  const url = safeUrl(value, true); if (!url) throw new Error('This upload destination is unavailable.');
  const parsed = new URL(url);
  if (!parsed.pathname.startsWith('/team/')) throw new Error('This upload destination is unavailable.');
  return parsed.pathname.slice('/team'.length) + parsed.search;
}
function bytes(size) { return size >= 1048576 ? (size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(size / 1024)) + ' KB'; }
function when(value) {
  const date = new Date(value); return value && !Number.isNaN(date.getTime()) ? new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date) : '';
}
function mimeFor(file) {
  if (supported.has(file.type)) return file.type;
  if (file.type && file.type !== 'application/octet-stream') return null;
  const ext = file.name.split('.').pop().toLowerCase(); return [...supported].find(([, extensions]) => extensions.includes(ext))?.[0] || null;
}

/** Mount the feed in the scrolling body and composerHost in the dialog footer. */
export function installEventUpdates(container, { api, record, scope, current = () => true, composerHost = container, dropTarget = container.closest('dialog') || container, onDirtyChange = () => {}, onEnableUpdates = null }) {
  if (!scope || typeof scope !== 'string') throw new Error('An exact event discussion scope is required.');
  const key = scope + '|' + record.id;
  const state = drafts.get(key) || { body: '', files: [], intent: null, attempt: null, busy: false, sealed: false };
  state.savedFileIds ||= [];
  // Never reveal retained text/files before this mount's fresh admission read.
  state.sealed = true;
  state.listeners ||= new Set();
  drafts.set(key, state);
  let disposed = false, generation = 0, loading = false, capability = {}, cursor = null, hasMore = false, canPost = false, canUpload = false, dragDepth = 0;
  const entries = new Map();
  const active = () => !disposed && current() && container.isConnected && composerHost.isConnected;
  const writable = () => active() && canPost && !state.sealed && !state.busy && !state.intent;
  const changed = () => onDirtyChange(dirty(state));
  const notify = posted => { for (const listener of state.listeners) listener(posted); changed(); };
  const root = node('section', '', 'event-updates'); root.setAttribute('aria-label', 'Event updates');
  const heading = node('div', '', 'event-updates-heading'); heading.append(node('h3', 'Updates'));
  const refresh = action('Refresh', () => void load().catch(showError), 'event-updates-refresh'); heading.append(refresh);
  const feed = node('div', '', 'event-updates-feed'); feed.setAttribute('aria-label', 'Event update history');
  const feedStatus = node('p', 'Loading updates…', 'event-updates-empty'); feedStatus.setAttribute('role', 'status');
  const pendingHost = node('div', '', 'event-update-pending');
  const older = action('More updates', () => void load(true).catch(showError), 'event-updates-older'); older.hidden = true;
  root.append(heading, feedStatus, pendingHost, feed, older); container.append(root);
  const form = node('form', '', 'event-update-composer'); form.setAttribute('aria-label', 'Write an event update');
  const label = node('label', '', 'event-update-label'), input = node('textarea');
  input.rows = 2; input.maxLength = 50000; input.placeholder = 'Share an update…'; input.setAttribute('aria-label', 'Write an update');
  label.append(input);
  const selected = node('div', '', 'event-update-files'); selected.setAttribute('aria-label', 'Selected attachments');
  const tools = node('div', '', 'event-update-tools'), attach = action('Attach', () => picker.click(), 'event-update-attach'); attach.prepend(icon('attach'));
  const picker = node('input'); picker.type = 'file'; picker.multiple = true; picker.accept = '.jpg,.jpeg,.png,.pdf,.txt'; picker.hidden = true; picker.setAttribute('aria-label', 'Attach photos or files');
  const hint = node('span', 'Drop files or paste a screenshot', 'event-update-hint');
  const submit = node('button', 'Post update', 'event-update-send'); submit.type = 'submit'; submit.append(icon('send'));
  const check = action('Check update', () => void reconcile(), 'event-update-check'); check.hidden = true;
  const enable = action('Enable updates', async () => {
    if (enable.disabled || !active() || state.sealed || typeof onEnableUpdates !== 'function') return;
    enable.disabled = true;
    try { await onEnableUpdates(); } catch (error) { showError(error); } finally { if (active()) controls(); }
  }, 'event-update-enable'); enable.hidden = true;
  tools.append(attach, picker, hint, check, submit, enable);
  const status = node('p', '', 'event-update-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  const reviewTools = node('div', '', 'event-update-review'); reviewTools.hidden = true;
  form.append(label, selected, tools, status, reviewTools); composerHost.append(form);
  const message = value => { if (active()) status.textContent = String(value || ''); };
  function showError(error) {
    if ([401, 403, 404, 419].includes(error?.status)) seal();
    message(error?.message || 'Updates could not be loaded. Your draft is retained.'); controls();
  }
  function controls() {
    if (!active()) return;
    input.readOnly = !canPost || state.sealed || state.busy || !!state.intent;
    attach.disabled = !writable() || !canUpload;
    submit.disabled = !canPost || state.sealed || state.busy || (!state.intent && !state.body.trim() && !state.files.length && !state.savedFileIds.length);
    const checking = needsCheck();
    submit.textContent = state.busy ? 'Saving…' : checking ? 'Check update' : state.intent ? state.attempt?.status === 'prepared' ? 'Resume update' : 'Retry same update' : 'Post update'; submit.append(icon('send'));
    const needsConnection = capability.requires_update_connection === true && !canPost && typeof onEnableUpdates === 'function';
    enable.hidden = !needsConnection; enable.disabled = state.busy || state.sealed;
    submit.hidden = needsConnection;
    check.hidden = !state.attempt?.id || checking; check.disabled = state.busy || state.sealed;
    picker.disabled = attach.disabled; hint.hidden = !canUpload || !canPost || !!state.intent;
    form.classList.toggle('event-update-locked', state.sealed);
    renderReview();
    changed();
  }
  function needsCheck() { return state.attempt?.id && ['unknown', 'executing', 'succeeded', 'posted'].includes(state.attempt.status); }
  function reviewContext() {
    const attempt = state.attempt;
    if (state.sealed || !attempt?.id || !attempt.can_acknowledge || !['unsent', 'source'].includes(attempt.review_kind)) return null;
    const source = safeUrl(attempt.source_review_url);
    if (attempt.review_kind === 'source' && (!source || !/^https:\/\/app\.asana\.com\//.test(source))) return null;
    return { key: String(attempt.id) + '|' + attempt.review_kind, kind: attempt.review_kind, source };
  }
  function renderReview() {
    reviewTools.replaceChildren(); reviewTools.hidden = true;
    const context = reviewContext();
    if (state.reviewIdentity !== context?.key) { state.reviewIdentity = context?.key || null; state.reviewOpened = null; }
    if (!context) return;
    reviewTools.hidden = false;
    const unsent = context.kind === 'unsent';
    const acknowledge = action(unsent ? 'Keep as editable draft' : 'Confirm original reviewed', () => void acknowledgeReview());
    acknowledge.disabled = state.busy || (!unsent && state.reviewOpened !== context.key);
    if (!unsent) {
      const open = node('a', 'Review the original in Asana'); open.href = context.source; open.target = '_blank'; open.rel = 'noopener noreferrer';
      open.addEventListener('click', () => { if (!active() || reviewContext()?.key !== context.key) return; state.reviewOpened = context.key; acknowledge.disabled = state.busy; });
      reviewTools.append(open);
    }
    reviewTools.append(acknowledge, node('small', unsent ? 'Keep the original text and files so you can edit them before posting. The server checks that no post was claimed.' : 'Review the original before confirming. The original attempt stays in history.'));
  }
  function renderSelected() {
    if (!active()) return; selected.replaceChildren();
    if (state.sealed) return;
    if (!state.files.length && !state.savedFileIds.length && state.intent?.file_ids?.length) selected.append(node('p', state.intent.file_ids.length + ' saved attachment' + (state.intent.file_ids.length === 1 ? '' : 's') + ' included', 'event-update-status'));
    for (const [index, id] of state.savedFileIds.entries()) {
      const tile = node('div', '', 'event-update-file'), copy = node('div'); copy.append(node('strong', 'Saved attachment ' + (index + 1)), node('small', 'Included in this draft'));
      const remove = action('×', () => { if (!writable()) return; state.savedFileIds = state.savedFileIds.filter(value => value !== id); renderSelected(); controls(); }, 'event-update-file-remove');
      remove.setAttribute('aria-label', 'Remove saved attachment ' + (index + 1)); remove.disabled = !writable(); tile.append(copy, remove); selected.append(tile);
    }
    for (const file of state.files) {
      const tile = node('div', '', 'event-update-file');
      if (file.mime.startsWith('image/') && !state.sealed) {
        if (!file.preview) file.preview = URL.createObjectURL(file.file);
        const image = node('img'); image.src = file.preview; image.alt = file.file.name; tile.append(image);
      } else tile.append(node('span', file.mime === 'application/pdf' ? 'PDF' : 'FILE', 'event-update-file-type'));
      const copy = node('div'); copy.append(node('strong', file.file.name), node('small', file.label || bytes(file.file.size))); tile.append(copy);
      const remove = action('×', () => removeFile(file), 'event-update-file-remove'); remove.setAttribute('aria-label', 'Remove ' + file.file.name); remove.disabled = !writable(); tile.append(remove); selected.append(tile);
    }
  }
  async function removeFile(file) {
    if (!writable()) return;
    if (file.upload?.id && !file.referenced) {
      state.busy = true; controls();
      try { await api(pathFor(template('cancel_url_template', file.upload.id)), { method: 'DELETE' }); }
      catch (error) { showError(error); state.busy = false; controls(); return; }
      state.busy = false;
    }
    if (file.preview) URL.revokeObjectURL(file.preview);
    state.files = state.files.filter(item => item !== file); renderSelected(); controls();
  }
  function addFiles(values) {
    if (!writable() || !canUpload) { message('Verify access and finish the current update before adding files.'); return; }
    const errors = [];
    for (const file of values) {
      const mime = mimeFor(file), limit = Number(capability.max_file_bytes || 10485760);
      if (!mime || !(capability.allowed_mimes || [...supported.keys()]).includes(mime)) { errors.push(file.name + ': use JPEG, PNG, PDF or text.'); continue; }
      if (file.size < 1 || file.size > limit) { errors.push(file.name + ': choose a non-empty file up to ' + bytes(limit) + '.'); continue; }
      if (state.files.length + state.savedFileIds.length >= 10) { errors.push('Attach up to 10 files per update.'); break; }
      if (state.files.some(item => item.file === file)) continue;
      state.files.push({ file, mime, clientKey: crypto.randomUUID(), upload: null, sha256: null, label: null, preview: null });
    }
    message(errors.join(' ')); renderSelected(); controls();
  }
  input.addEventListener('input', () => {
    if (!writable()) { input.value = state.body; return; }
    state.body = input.value; input.style.height = 'auto'; input.style.height = Math.min(140, Math.max(56, input.scrollHeight)) + 'px'; controls();
  });
  picker.addEventListener('change', () => { addFiles([...picker.files]); picker.value = ''; });
  function paste(event) {
    const files = [...(event.clipboardData?.files || [])]; if (!files.length) return;
    event.preventDefault(); addFiles(files);
    const text = event.clipboardData.getData('text/plain');
    if (text && writable()) { input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end'); input.dispatchEvent(new Event('input', { bubbles: true })); }
  }
  input.addEventListener('paste', paste);
  input.addEventListener('keydown', event => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter' && !event.isComposing) { event.preventDefault(); form.requestSubmit(); } });
  const isFileDrag = event => [...(event.dataTransfer?.types || [])].includes('Files');
  function dragOver(event) { if (!isFileDrag(event)) return; event.preventDefault(); if (event.dataTransfer) event.dataTransfer.dropEffect = writable() && canUpload ? 'copy' : 'none'; form.classList.add('event-update-dragging'); }
  function dragEnter(event) { if (isFileDrag(event)) { dragDepth++; dragOver(event); } }
  function dragLeave(event) { if (!isFileDrag(event)) return; dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) form.classList.remove('event-update-dragging'); }
  function drop(event) { if (!isFileDrag(event)) return; event.preventDefault(); dragDepth = 0; form.classList.remove('event-update-dragging'); addFiles([...(event.dataTransfer.files || [])]); }
  dropTarget.addEventListener('dragenter', dragEnter); dropTarget.addEventListener('dragover', dragOver); dropTarget.addEventListener('dragleave', dragLeave); dropTarget.addEventListener('drop', drop);

  function renderFeed() {
    if (!active()) return; feed.replaceChildren();
    const rows = [...entries.values()].sort((a, b) => String(a.created_at || '').localeCompare(String(b.created_at || '')) || String(a.id).localeCompare(String(b.id)));
    for (const item of rows) {
      const article = node('article', '', 'event-update-entry'), avatar = node('span', '', 'event-update-avatar');
      const name = item.author_name || item.author?.name || 'Team member'; avatar.textContent = name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase(); avatar.setAttribute('aria-hidden', 'true');
      const content = node('div', '', 'event-update-copy'), meta = node('div', '', 'event-update-meta'); meta.append(node('strong', name), node('time', when(item.created_at)));
      content.append(meta, node('p', item.body || '', 'event-update-body'));
      const files = node('div', '', 'event-update-attachments');
      for (const file of item.attachments || []) {
        const download = safeUrl(file.download_url), preview = safeUrl(file.preview_url, true), entry = node('div', '', 'event-update-attachment');
        if (preview && String(file.mime_type || '').startsWith('image/')) { const image = node('img'); image.src = preview; image.alt = file.filename || 'Attached photo'; image.loading = 'lazy'; entry.append(image); }
        if (download) { const a = node('a', file.filename || 'Download attachment'); a.href = download; a.target = '_blank'; a.rel = 'noopener noreferrer'; entry.append(a); }
        else entry.append(node('span', file.filename || 'Attachment'), node('small', file.status === 'rejected' ? 'File check failed' : 'File check pending'));
        files.append(entry);
      }
      if (files.childElementCount) content.append(files); article.append(avatar, content); feed.append(article);
    }
    feedStatus.textContent = rows.length ? '' : 'No updates yet. Share the first one.';
    older.hidden = !hasMore; older.disabled = loading;
  }
  function renderPending(items) {
    pendingHost.replaceChildren();
    for (const item of items) {
      if (item.id === state.attempt?.id) continue;
      const row = node('div', '', 'event-update-pending-row'), copy = node('p', item.notice || 'An earlier update needs review.');
      if (item.body) copy.append(node('span', String(item.body).slice(0, 180), 'event-update-pending-excerpt'));
      row.append(copy);
      if (item.request_id && typeof item.body === 'string' && Array.isArray(item.file_ids)) {
        const resume = action('Resume update', () => {
          if (!active() || state.busy || state.sealed) return;
          if (dirty(state) && state.intent?.request_id !== item.request_id) { message('Finish your current update before resuming the earlier one.'); return; }
          state.body = item.body; state.intent = { request_id: item.request_id, body: item.body, file_ids: [...item.file_ids] }; state.attempt = item;
          input.value = state.body; renderSelected(); controls(); renderPending(items); message(item.notice || 'The original update is retained. Check it or retry this same update.');
        }); resume.disabled = !canPost; row.append(resume);
      }
      const checkSaved = action('Check update', async () => {
        checkSaved.disabled = true;
        try {
          const reply = await api('/events/' + encodeURIComponent(record.id) + '/updates/' + encodeURIComponent(item.id) + '/reconcile', { method: 'POST' });
          if (!active()) return;
          if (state.attempt?.id === item.id) { const posted = finish(reply); notify(posted); if (!posted) copy.textContent = reply.notice || 'This update is still unconfirmed.'; }
          else if (accepted(reply)) { row.remove(); await load(); }
          else copy.textContent = reply.notice || 'This update is still unconfirmed. Review it before posting another copy.';
        } catch (error) { showError(error); }
        finally { if (active()) checkSaved.disabled = false; }
      }); row.append(checkSaved); pendingHost.append(row);
    }
  }
  async function load(more = false) {
    if (!active() || loading) return; loading = true; const ticket = ++generation; refresh.disabled = true; older.disabled = true;
    try {
      const result = await api('/events/' + encodeURIComponent(record.id) + '/updates', { query: more && cursor ? { cursor } : {} });
      if (!active() || ticket !== generation) return;
      capability = result; canPost = result.can_post === true; canUpload = result.can_upload === true && !!result.upload;
      cursor = result.next_cursor || null; hasMore = result.has_more === true;
      if (!more) entries.clear(); for (const item of result.items || []) entries.set(String(item.id), item);
      const matched = result.pending?.find(item => item.request_id === state.intent?.request_id); if (matched) state.attempt = matched;
      state.sealed = false; input.value = state.body; renderFeed(); renderPending(result.pending || []); renderSelected(); controls();
      if (!canPost) message(result.notice || 'Updates are read only for this event.');
      else if (state.intent) message(state.attempt?.notice || 'The previous update is not confirmed. Check it or retry the same update.');
      else if (dirty(state)) message('Your unfinished update is retained.');
      else message(result.pending?.length ? 'An earlier update needs review above.' : '');
    } catch (error) { if (active() && ticket === generation) { feedStatus.textContent = 'Updates are unavailable. Your draft is retained.'; showError(error); } }
    finally { if (active() && ticket === generation) { loading = false; refresh.disabled = false; older.disabled = !hasMore; controls(); } }
  }
  function template(name, upload, index) {
    const value = capability.upload?.[name]; if (!value) throw new Error('File uploads are unavailable for this event.');
    return value.replaceAll('{upload}', encodeURIComponent(upload)).replaceAll('{index}', String(index));
  }
  async function uploadFile(file) {
    const buffer = await file.file.arrayBuffer();
    if (!file.sha256) file.sha256 = [...new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))].map(part => part.toString(16).padStart(2, '0')).join('');
    if (!active() || state.sealed) throw new Error('Upload paused. Reopen this event to resume.');
    let upload = await api(pathFor(capability.upload.begin_url), { method: 'POST', body: { client_key: file.clientKey, filename: file.file.name, mime_type: file.mime, size: file.file.size, sha256: file.sha256 } });
    file.upload = upload;
    if (upload.status === 'ready' || upload.downloadable === true) return upload.id;
    if (upload.status === 'rejected' || upload.status === 'cancelled') throw new Error(file.file.name + ': file check failed. Remove this attachment before retrying.');
    const chunkBytes = Number(upload.chunk_bytes); if (!Number.isSafeInteger(chunkBytes) || chunkBytes <= 0) throw new Error('The upload could not be prepared. Retry the same update.');
    for (let index = 0; index < Math.ceil(buffer.byteLength / chunkBytes); index++) {
      if (!active() || state.sealed) throw new Error('Upload paused. Reopen this event to resume.');
      if ((upload.received_chunks || []).includes(index)) continue;
      file.label = 'Uploading ' + Math.round(100 * Number(upload.received_bytes || 0) / buffer.byteLength) + '%'; renderSelected();
      upload = await api(pathFor(template('chunk_url_template', upload.id, index)), { method: 'PUT', raw: true, body: buffer.slice(index * chunkBytes, Math.min(buffer.byteLength, (index + 1) * chunkBytes)) }); file.upload = upload;
    }
    if (!active() || state.sealed) throw new Error('Upload paused. Reopen this event to resume.');
    file.label = 'Checking file…'; renderSelected();
    upload = await api(pathFor(template('complete_url_template', upload.id)), { method: 'POST' }); file.upload = upload;
    if (upload.status !== 'ready' && upload.downloadable !== true) { file.label = upload.status === 'rejected' ? 'File check failed' : 'File check pending'; renderSelected(); throw new Error(file.file.name + ': waiting for a successful file check. Retry to check again.'); }
    file.label = 'Ready'; renderSelected(); return upload.id;
  }
  function accepted(reply) {
    return reply.status === 'succeeded' || reply.status === 'posted';
  }
  function finish(reply) {
    state.attempt = reply;
    if (reply.status === 'reviewed') {
      const localIds = new Set(state.files.map(file => file.upload?.id).filter(Boolean));
      state.savedFileIds = [...new Set([...state.savedFileIds, ...(reply.file_ids || state.intent?.file_ids || [])])].filter(id => !localIds.has(id));
      for (const file of state.files) file.referenced = true;
      state.intent = null; state.attempt = null; state.reviewOpened = null; state.reviewIdentity = null;
      message(reply.notice || 'Review recorded. Your text and files are retained as an editable draft.'); return false;
    }
    if (!accepted(reply)) { message(reply.notice || 'This update is not confirmed. Check its status before starting another.'); return false; }
    for (const file of state.files) if (file.preview) URL.revokeObjectURL(file.preview);
    state.body = ''; state.files = []; state.savedFileIds = []; state.intent = null; state.attempt = null; state.reviewOpened = null; state.reviewIdentity = null;
    if (active()) { input.value = ''; input.style.height = ''; renderSelected(); message(reply.notice || 'Update posted.'); }
    return true;
  }
  async function post() {
    if (!active() || state.busy || state.sealed || !canPost) return;
    if (!state.intent && !state.body.trim() && !state.files.length && !state.savedFileIds.length) return;
    state.busy = true; controls(); message('Saving update…');
    let completed = false;
    try {
      if (!state.intent) {
        const fileIds = [...state.savedFileIds]; for (const file of state.files) fileIds.push(await uploadFile(file));
        if (!active() || state.sealed) throw new Error('Your unfinished update is retained. Reopen this event to continue.');
        state.intent = { request_id: crypto.randomUUID(), body: state.body.trim(), file_ids: fileIds };
      }
      const reply = await api('/events/' + encodeURIComponent(record.id) + '/updates', { method: 'POST', body: state.intent });
      completed = finish(reply);
    } catch (error) {
      if (error?.saved === false && [409, 422].includes(error.status)) state.intent = null;
      showError(error);
    } finally {
      state.busy = false; notify(completed);
    }
  }
  async function reconcile() {
    if (!active() || !state.attempt?.id || state.busy || state.sealed) return;
    state.busy = true; controls(); let completed = false;
    try { completed = finish(await api('/events/' + encodeURIComponent(record.id) + '/updates/' + encodeURIComponent(state.attempt.id) + '/reconcile', { method: 'POST' })); }
    catch (error) { showError(error); }
    finally { state.busy = false; notify(completed); }
  }
  async function acknowledgeReview() {
    const context = reviewContext();
    if (!active() || !context || (context.kind === 'source' && state.reviewOpened !== context.key) || state.busy || state.sealed) return;
    state.busy = true; controls();
    try {
      const reply = await api('/events/' + encodeURIComponent(record.id) + '/updates/' + encodeURIComponent(state.attempt.id) + '/reconcile', { method: 'POST', body: { reviewed: true, acknowledge: true, review_kind: context.kind } });
      if (reply.status === 'reviewed') { finish(reply); if (active()) await load(); message(reply.notice || 'Review recorded. Your text and files are retained as an editable draft.'); }
      else { state.attempt = reply; message(reply.notice || 'The posting state changed. Your original draft is retained; check it before changing anything.'); }
    } catch (error) { showError(error); }
    finally { state.busy = false; notify(false); }
  }
  form.addEventListener('submit', event => { event.preventDefault(); if (needsCheck()) void reconcile(); else void post(); });
  function seal() { state.sealed = true; generation++; loading = false; canPost = false; canUpload = false; if (active()) { refresh.disabled = false; feed.replaceChildren(); pendingHost.replaceChildren(); input.value = ''; selected.replaceChildren(); message('Verify your original account to resume this update.'); controls(); } }
  const accessLost = () => seal(); document.addEventListener('team:access-lost', accessLost);
  const sync = posted => { if (!active()) return; input.value = state.sealed ? '' : state.body; renderSelected(); controls(); if (posted) void load(); };
  state.listeners.add(sync);
  controls(); renderSelected();
  const ready = load();
  return {
    ready, hasDraft: () => dirty(state), refresh: () => load(), focus: () => { if (active() && !state.sealed) input.focus(); },
    dispose({ seal: shouldSeal = false } = {}) {
      if (disposed) return; if (shouldSeal) state.sealed = true; disposed = true; generation++;
      document.removeEventListener('team:access-lost', accessLost);
      state.listeners.delete(sync);
      dropTarget.removeEventListener('dragenter', dragEnter); dropTarget.removeEventListener('dragover', dragOver); dropTarget.removeEventListener('dragleave', dragLeave); dropTarget.removeEventListener('drop', drop);
      for (const file of state.files) if (file.preview) { URL.revokeObjectURL(file.preview); file.preview = null; }
      root.remove(); form.remove(); if (!dirty(state)) drafts.delete(key); changed();
    },
  };
}
