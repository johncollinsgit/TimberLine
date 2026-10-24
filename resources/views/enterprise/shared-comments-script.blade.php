/* Shared fictional work comments only. Root supplies current server-backed name and detail hooks. */
(() => {
  'use strict';
  const instances = new WeakMap(), root = globalThis;
  const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
  const plain = value => JSON.parse(JSON.stringify(value));
  const unavailable = () => new Error('Shared comments are unavailable. Your unsent draft is retained.');
  function create(options) {
    const {boot, workspace, host, context, readReviewerName, chooseReviewerName, tasksForProject = () => []} = options;
    if (!Object.hasOwn(boot, 'reviewerCollaboration')) return null;
    if (instances.has(boot)) return instances.get(boot);
    if (workspace?.mode !== 'shared' || typeof host !== 'function' || typeof context !== 'function'
      || typeof readReviewerName !== 'function' || typeof chooseReviewerName !== 'function') throw unavailable();
    // The reviewed transport validates the descriptor and owns durable command/receipt state.
    const client = root.EverbranchWorkspaceComments.create(boot, workspace);
    const canonical = root.EverbranchSharedWorkspace.canonical, descriptor = boot.reviewerCollaboration;
    const prefix = 'everbranch-shared-comment-draft:' + descriptor.workspace_id + ':' + descriptor.binding.version_hash + ':' + descriptor.browser_context + ':';
    const drafts = new Map();
    let active = null, generation = 0, operation = 0, busy = false, destroyed = false;
    let storage = null, storageWarning = false;
    try { storage = root.sessionStorage; } catch { storageWarning = true; }
    const element = (tag, text, id) => {
      const node = document.createElement(tag);
      if (text !== undefined) node.textContent = text;
      if (id) node.id = id;
      return node;
    };
    const button = (text, id, run) => {
      const node = element('button', text, id); node.type = 'button'; node.className = 'button';
      node.addEventListener('click', () => { void run(); });
      return node;
    };
    function selection() {
      const s = workspace.snapshot();
      if (s.workspace_id !== descriptor.workspace_id || s.browser_context !== descriptor.browser_context
        || s.revision === null || ['blocked', 'unavailable', 'idle'].includes(s.phase)) throw unavailable();
      return {persona: s.persona, area_ids: [...s.area_ids], person_id: s.person_id};
    }
    const identity = () => canonical(selection());
    const keyFor = (target, selected) => canonical({target, selection: selected});
    const pinFor = view => ({target: plain(view.target), selection: plain(view.selection), target_version: view.target_version, thread_revision: view.thread_revision});
    function validDraft(value, target, selected) {
      if (!value || Object.keys(value).sort().join(',') !== 'body,pin,review_required' || typeof value.body !== 'string'
        || [...value.body].length > 4000 || new TextEncoder().encode(value.body).length > 16384 || typeof value.review_required !== 'boolean') return false;
      const p = value.pin;
      return p === null || p && Object.keys(p).sort().join(',') === 'selection,target,target_version,thread_revision'
        && canonical(p.target) === canonical(target) && canonical(p.selection) === canonical(selected)
        && Number.isSafeInteger(p.target_version) && p.target_version >= 1 && Number.isSafeInteger(p.thread_revision) && p.thread_revision >= 0;
    }
    function draftFor(target, selected) {
      const key = keyFor(target, selected);
      if (drafts.has(key)) return drafts.get(key);
      let value = {body: '', pin: null, review_required: false};
      try {
        const raw = storage?.getItem(prefix + key);
        if (raw !== null && raw !== undefined) {
          if (new TextEncoder().encode(raw).length > 32768) throw unavailable();
          const parsed = JSON.parse(raw);
          if (!validDraft(parsed, target, selected)) throw unavailable();
          value = parsed;
        }
      } catch { storageWarning = true; }
      const state = {...value, key, target: plain(target), selection: plain(selected), view: null, rejected: null};
      const pending = client.pending();
      if (pending && matches(state, pending)) {
        state.body = pending.command.body;
        state.pin = {target: plain(pending.command.target), selection: plain(pending.command.selection),
          target_version: pending.command.expected_target_version, thread_revision: pending.command.expected_thread_revision};
      }
      drafts.set(key, state);
      return state;
    }
    function persist(state) {
      const value = {body: state.body, pin: state.pin, review_required: state.review_required};
      try {
        if (!storage) throw unavailable();
        const raw = canonical(value);
        if (new TextEncoder().encode(raw).length > 32768 || storage.setItem(prefix + state.key, raw) === false
          || storage.getItem(prefix + state.key) !== raw) throw unavailable();
      } catch { storageWarning = true; }
    }
    function matches(state, pending) {
      return canonical(state.target) === canonical(pending.command.target) && canonical(state.selection) === canonical(pending.command.selection);
    }
    function current(session) {
      try { return !destroyed && active === session && session.epoch === generation && session.node.isConnected
        && session.container === host() && session.context === context() && session.selected === identity(); }
      catch { return false; }
    }
    function stash() {
      if (active?.displaying) { active.state.body = active.input.value; persist(active.state); }
    }
    function clearBytes(session) {
      session.feed.replaceChildren(); session.input.value = ''; session.displaying = false; session.state.view = null;
      session.name = null; session.who.textContent = '';
    }
    function invalidate({stash: keep = true} = {}) {
      if (keep) stash();
      generation++;
      if (active) { clearBytes(active); active.node.remove(); }
      active = null;
    }
    function notice(session, text) {
      if (!current(session)) return;
      session.status.textContent = text + (storageWarning ? ' Browser draft storage is unavailable; keep this tab open.' : '');
    }
    function controls(session) {
      if (!current(session)) return;
      const state = session.state, pending = client.pending(), mine = pending && matches(state, pending);
      const allowed = !!state.view?.can_comment;
      session.input.readOnly = busy || !!mine || !allowed;
      session.post.disabled = busy || !!pending || !allowed || !session.name || !state.pin || state.review_required || !session.displaying || !state.body.trim();
      session.refresh.disabled = busy;
      session.nameRefresh.disabled = busy;
      session.check.hidden = !mine; session.check.disabled = busy;
      session.retry.hidden = !mine; session.retry.disabled = busy || !allowed;
      session.ack.hidden = !mine || !state.rejected; session.ack.disabled = busy;
      session.reviewPin.disabled = busy || !!pending || !allowed;
      session.reviewPin.hidden = !state.body || !!mine;
      session.pin.textContent = state.pin ? 'Draft: target version ' + state.pin.target_version + ', thread revision ' + state.pin.thread_revision + '.' : '';
      session.blocked.textContent = pending && !mine ? 'Another thread has an unconfirmed comment. Return to that exact thread and selection to check or retry it; this draft stays separate.' : '';
      session.who.textContent = session.name ? 'Commenting as ' + session.name + ' · self-selected display name.' : 'Choose your review display name before posting.';
    }
    async function refreshName(session) {
      const serial = ++session.nameSerial;
      try {
        const value = await readReviewerName();
        if (!current(session) || serial !== session.nameSerial) return null;
        const b = descriptor.binding;
        if (!value || !['version_hash', 'build_id', 'source_hash'].every(k => value[k] === b[k])
          || !(value.reviewer_name === null || typeof value.reviewer_name === 'string' && value.reviewer_name.length > 0 && [...value.reviewer_name].length <= 80)) throw unavailable();
        session.name = value.reviewer_name;
        controls(session);
        return session.name;
      } catch {
        if (current(session) && serial === session.nameSerial) { clearBytes(session); controls(session); notice(session, 'Saved name access could not be confirmed. Refresh this thread and your saved name or choose it in the review panel.'); }
        return null;
      }
    }
    function renderComments(session, view, older = false) {
      const cards = [];
      for (const comment of view.comments) {
        const article = element('article'); article.className = 'discussion-note';
        const label = element('p', comment.name + ' · self-selected display name · ' + comment.created_at); label.className = 'caption';
        const text = element('div', comment.body); text.style.whiteSpace = 'pre-wrap';
        article.append(label, text); cards.push(article);
      }
      if (older) session.feed.prepend(...cards);
      else session.feed.replaceChildren(...(cards.length ? cards : [element('p', 'No shared comments on this thread yet.')]));
    }
    async function load(session, {repin = false, before = null} = {}) {
      if (!current(session) || repin && (busy || client.pending())) return;
      stash();
      const serial = ++session.readSerial;
      if (!before) { session.state.view = null; controls(session); notice(session, 'Checking current shared comments and access…'); }
      try {
        const view = await client.show(session.state.target, before);
        if (!current(session) || serial !== session.readSerial) return;
        const state = session.state;
        state.view = view;
        if (repin || !state.pin || state.body === '' && !client.pending()) {
          state.pin = pinFor(view); state.review_required = false; persist(state);
        }
        const scroll = session.container.scrollTop;
        renderComments(session, view, !!before);
        session.container.scrollTop = scroll;
        session.before = view.before; session.older.hidden = !view.before;
        session.input.value = state.body; session.displaying = true;
        notice(session, view.can_comment ? 'Shared comments are saved on the server. Nothing is posted until you choose Post comment.' : 'This thread is read only under your current selection.');
        controls(session);
      } catch {
        if (!current(session) || serial !== session.readSerial) return;
        clearBytes(session); session.older.hidden = true; controls(session);
        notice(session, 'Current thread access could not be confirmed. Saved comments and retained text are withheld; refresh this thread to check again.');
      }
    }
    function clearMatchingDraft(pending) {
      const key = keyFor(pending.command.target, pending.command.selection), state = drafts.get(key);
      if (state && state.body === pending.command.body && state.pin?.target_version === pending.command.expected_target_version
        && state.pin?.thread_revision === pending.command.expected_thread_revision) {
        state.body = ''; state.pin = null; state.review_required = false; state.rejected = null; persist(state);
      }
    }
    async function act(session, action) {
      if (!current(session) || busy) return;
      stash();
      let pending = client.pending();
      if (action !== 'post' && (!pending || !matches(session.state, pending))) return;
      const ticket = ++operation;
      busy = true; controls(session);
      try {
        if (action === 'post') {
          if (pending || !session.state.view?.can_comment || session.state.review_required || !session.state.body.trim()) return;
          const name = await refreshName(session);
          if (!current(session) || !name || !session.state.pin) return;
          const view = {...session.state.pin, workspace_id: descriptor.workspace_id, can_comment: true};
          pending = await client.prepare(view, {request_id: crypto.randomUUID(), expected_reviewer_name: name, body: session.state.body});
          if (!current(session)) return;
        }
        notice(session, action === 'check' ? 'Checking the original comment outcome…' : 'Submitting this exact comment…');
        const result = action === 'check' ? await client.recover() : action === 'retry' ? await client.retry(pending) : await client.send(pending);
        if (result.status === 'applied' && result.receipt?.request_id === pending.command.request_id && result.receipt.digest === pending.digest) clearMatchingDraft(pending);
        if (!current(session) || result.stale_context) return;
        if (result.status === 'applied') {
          session.input.value = session.state.body; session.displaying = true;
          await load(session);
          notice(session, result.storage_warning ? 'Comment saved. Receipt cleanup needs care; keep this tab open.' : 'Comment saved and shared on this exact thread.');
        } else notice(session, 'The original comment has not been observed. Its exact intent remains pending; check again or explicitly retry the same comment.');
      } catch (error) {
        if (!current(session)) return;
        if (error.outcome === 'rejected' && pending) {
          session.state.rejected = pending;
          notice(session, 'This exact comment was not applied because its thread, target or saved name changed. Acknowledge the rejection, then review the current thread before posting a new intent.');
        } else if (client.pending()) {
          clearBytes(session);
          notice(session, 'Comment outcome is unconfirmed. The exact intent is retained; check its outcome or refresh current access before retrying.');
        } else notice(session, 'No comment was confirmed or dispatched. Your unsent draft is retained; check the saved name and browser storage.');
      } finally {
        if (ticket === operation) busy = false;
        if (active && current(active)) controls(active);
      }
    }
    async function mount(kind, id, projectRoot = null) {
      if (destroyed || !['project', 'task', 'ticket'].includes(kind) || !uuid.test(id)) throw unavailable();
      const selected = selection(), contextValue = context();
      if (active && current(active) && (active.state.target.kind === kind && active.state.target.id === id
        || kind === 'project' && active.projectRoot === id)) return active.node;
      invalidate();
      const container = host();
      if (!container?.isConnected) return null;
      const state = draftFor({kind, id}, selected), node = element('section', undefined, 'shared-discussion'); node.className = 'discussion';
      const session = {state, node, container, context: contextValue, selected: canonical(selected), epoch: generation,
        projectRoot: projectRoot ?? (kind === 'project' ? id : null), readSerial: 0, nameSerial: 0, name: null, before: null, displaying: false};
      active = session;
      node.append(element('h3', kind === 'project' ? 'Project comments' : kind === 'task' ? 'Task comments' : 'Ticket comments'));
      if (session.projectRoot) {
        const label = element('label', 'Thread'); const picker = element('select', undefined, 'shared-discussion-thread');
        picker.append(Object.assign(element('option', 'Project comments'), {value: 'project:' + session.projectRoot}));
        const tasks = tasksForProject(session.projectRoot);
        if (!Array.isArray(tasks) || tasks.length > 64 || tasks.some(t => !uuid.test(t.id) || typeof t.title !== 'string')) throw unavailable();
        for (const task of tasks) picker.append(Object.assign(element('option', 'Task: ' + task.title), {value: 'task:' + task.id}));
        picker.value = kind + ':' + id;
        picker.addEventListener('change', () => {
          const [nextKind, nextId] = picker.value.split(':');
          if (nextKind === 'project' && nextId === session.projectRoot || nextKind === 'task' && tasks.some(t => t.id === nextId)) void mount(nextKind, nextId, session.projectRoot);
        }); label.append(picker); node.append(label);
      }
      session.feed = element('div', undefined, 'shared-discussion-feed'); session.feed.className = 'discussion-feed'; session.feed.setAttribute('aria-label', 'Shared thread comments');
      session.status = element('p', '', 'shared-discussion-status'); session.status.setAttribute('role', 'status'); session.status.setAttribute('aria-live', 'polite');
      session.blocked = element('p', '');
      session.who = element('p', '', 'shared-discussion-name');
      session.pin = element('p', ''); session.pin.className = 'caption';
      const form = element('form', undefined, 'shared-discussion-form'); form.className = 'discussion-composer stack';
      const label = element('label', 'Add a shared comment');
      session.input = element('textarea', undefined, 'shared-discussion-body'); session.input.maxLength = 4000; session.input.rows = 4;
      session.input.addEventListener('input', () => { if (current(session) && session.displaying && !session.input.readOnly) { state.body = session.input.value; persist(state); controls(session); } });
      label.append(session.input);
      session.post = button('Post comment', 'shared-discussion-post', () => act(session, 'post')); session.post.className = 'button primary';
      session.refresh = button('Refresh thread', 'shared-discussion-refresh', () => load(session));
      session.nameRefresh = button('Refresh saved name', 'shared-discussion-name-refresh', () => refreshName(session));
      const choose = button('Choose review name', 'shared-discussion-choose-name', () => { if (current(session)) chooseReviewerName(); });
      session.check = button('Check comment outcome', 'shared-discussion-check', () => act(session, 'check'));
      session.retry = button('Retry exact comment', 'shared-discussion-retry', () => act(session, 'retry'));
      session.ack = button('Acknowledge rejection', 'shared-discussion-ack', () => {
        if (!current(session) || busy || !state.rejected) return;
        try { client.acknowledgeRejection(state.rejected); state.rejected = null; state.review_required = true; persist(state); controls(session);
          notice(session, 'Rejected intent acknowledged. Your draft remains. Review the current thread before creating a new comment intent.'); }
        catch { notice(session, 'The original intent is still retained. Check its outcome before continuing.'); }
      });
      session.reviewPin = button('Review current thread for this draft', 'shared-discussion-review-pin', () => load(session, {repin: true}));
      session.older = button('Show earlier comments', 'shared-discussion-older', () => load(session, {before: session.before})); session.older.hidden = true;
      form.addEventListener('submit', event => { event.preventDefault(); void act(session, 'post'); });
      form.append(label, session.post, session.check, session.retry, session.ack, session.reviewPin);
      node.append(session.who, choose, session.nameRefresh, session.pin, session.blocked, session.feed, session.older, session.refresh,
        form, session.status, ...(Object.hasOwn(boot,'reviewerAttachments') ? [] : [element('p', 'File uploads are not available in this shared review.')]));
      container.append(node); controls(session);
      await Promise.all([load(session), refreshName(session)]);
      return current(session) ? node : null;
    }
    let observed = '';
    try { observed = identity(); } catch { /* First mount will fail closed until an actual projection exists. */ }
    const unsubscribe = workspace.subscribe(() => {
      try { const next = identity(); if (next !== observed) { observed = next; invalidate(); } }
      catch { invalidate(); }
    });
    const onPageHide = () => invalidate();
    root.addEventListener('pagehide', onPageHide);
    const api = Object.freeze({mount, stash, invalidate, destroy() {
      if (destroyed) return; invalidate(); destroyed = true; unsubscribe(); client.destroy();
      root.removeEventListener('pagehide', onPageHide); if (instances.get(boot) === api) instances.delete(boot);
    }});
    instances.set(boot, api);
    return api;
  }
  root.EverbranchSharedCommentsUI = Object.freeze({create});
})();
