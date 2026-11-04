// Per-user source tasks. Provider content is always text; saves require a reviewed receipt.
export function installEventTasks({ api, el, button, field, dialog, section, note, area, content, navigate }) {
  let connection, serial = 0;
  const active = (id, ticket, node) => area()?.id === id && ticket === serial && (!node || node.isConnected);
  const when = value => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value + 'T12:00:00Z')) : 'No due date';
  function link(text, url) {
    const a = el('a', text); const parsed = new URL(url, location.origin);
    if (parsed.protocol === 'https:' && parsed.hostname === 'app.asana.com') { a.href = parsed.href; a.target = '_blank'; a.rel = 'noopener noreferrer'; }
    return a;
  }
  async function render(result, append = false) {
    if (!append) {
      content.replaceChildren(); content.append(el('h3', 'My Tasks'), el('p', 'Your assigned event work, with its original Asana details.'));
      if (result.accounts.length > 1) {
        const wrap = el('div'), select = field(wrap, '', 'Asana account', result.connection_id, 'text', result.accounts.map(a => ({ id: a.id, name: a.name })));
        select.onchange = () => { connection = select.value; navigate('my_tasks'); }; content.append(wrap);
      }
      connection = result.connection_id;
      for (const pending of result.pending_updates || []) {
        const warning = el('aside', '', 'teams-install-help');
        warning.append(el('p', 'An earlier Asana save needs review.'), button('Check current Asana state', () => check(pending.id, warning)), link('Review task in Asana', 'https://app.asana.com/0/0/' + pending.task_id)); content.append(warning);
      }
      if (!result.items.length) content.append(el('p', result.accounts.length ? 'No assigned tasks on this page. Check the next page or your selected event projects.' : 'Choose Connections to connect Asana and select the event calendar.'), button('Connections', () => navigate('connections')));
    }
    let grid = content.querySelector('.event-task-cards');
    if (!grid) { grid = el('div', '', 'event-task-cards'); content.append(grid); }
    for (const item of result.items) {
      const card = el('article', '', 'event-task-card');
      card.append(el('p', item.source_mode === 'snapshot' ? 'SAVED ASANA SNAPSHOT' : 'ASANA · ASSIGNED TO YOU', 'event-source'), el('h3', item.name), el('p', when(item.due_on)), button('Open event details', () => details(item), 'primary'));
      grid.append(card);
    }
    if (result.has_more) {
      const more = button('Load more assigned tasks', async () => {
        const id = area().id; const next = await api('/events/my-tasks', { query: { connection_id: connection, cursor: result.next_cursor } });
        if (area()?.id !== id || !more.isConnected) return; more.remove(); await render(next, true);
      }); content.append(more);
    }
  }
  async function details(item) {
    const id = area().id, ticket = ++serial, body = dialog(item.name);
    note('Checking your current Asana access…', body);
    const query = { connection_id: item.connection_id, parent_id: item.parent_id };
    const task = await api('/events/asana/tasks/' + item.gid, { query });
    if (!active(id, ticket, body)) return;
    body.replaceChildren();
    body.append(el('p', task.source_mode === 'snapshot' ? 'Saved snapshot · read only' : 'Asana is the source of truth', 'event-source'));
    body.append(el('p', [task.assignee?.name || 'Unassigned', task.start_on ? when(task.start_on) + ' → ' + when(task.due_on) : when(task.due_on), task.completed ? 'Completed' : 'Open'].join(' · ')), link('Open in Asana', task.source_url));
    if (task.start_at || task.due_at) note([task.start_at ? 'Starts: ' + new Date(task.start_at).toLocaleString() : '', task.due_at ? 'Due: ' + new Date(task.due_at).toLocaleString() : ''].filter(Boolean).join(' · '), body);
    if (task.can_write) body.append(button('Edit task in Teams', () => edit(task), 'primary'));
    const highlights = sourceHighlights(task.notes); if (highlights.length) { const summary = section('Event essentials · from original notes', body), grid = el('dl', '', 'event-essentials'); for (const line of highlights) { const cell = el('div'), split = line.indexOf(':'); cell.append(el('dt', line.slice(0, split)), el('dd', line.slice(split + 1).trim())); grid.append(cell); } summary.append(grid); }
    const tabs = el('div', '', 'event-detail-tabs'), related = el('div'); body.append(tabs, related);
    for (const [kind, title] of [['subtasks', 'Checklist'], ['stories', 'Comments & updates'], ['attachments', 'Attachments']]) {
      const tab = button(title, () => relatedPage(kind, title)); tabs.append(tab);
    }
    let relatedSerial = 0;
    async function relatedPage(kind, title, cursor, append = false) {
      const relatedTicket = ++relatedSerial;
      if (!append) { related.replaceChildren(el('h3', title)); note('Loading…', related); }
      const data = await api('/events/asana/tasks/' + item.gid + '/' + kind, { query: { ...query, cursor } });
      if (!active(id, ticket, body) || relatedTicket !== relatedSerial) return;
      if (!append) related.replaceChildren(el('h3', title));
      if (!data.items.length && !append) note('No ' + title.toLowerCase() + ' returned.', related);
      for (const row of data.items) {
        const block = el('article', '', 'event-related');
        if (kind === 'attachments') block.append(link(row.name, row.permanent_url || task.source_url));
        else if (kind === 'subtasks') {
          block.append(el('strong', (row.completed ? '✓ ' : '○ ') + row.name), el('p', [row.assignee?.name, row.due_on ? when(row.due_on) : ''].filter(Boolean).join(' · ')));
          if (row.notes) block.append(el('p', row.notes, 'event-original-notes'));
          block.append(button('Open checklist task', () => details({ ...row, connection_id: task.connection_id, parent_id: task.gid })));
        } else { block.append(el('p', [row.created_by?.name, row.created_at ? new Date(row.created_at).toLocaleString() : ''].filter(Boolean).join(' · ')), el('p', row.text || row.name || '', 'event-original-notes')); }
        related.append(block);
      }
      if (data.has_more) { const more = button('Load more ' + title.toLowerCase(), async () => { more.remove(); await relatedPage(kind, title, data.next_cursor, true); }); related.append(more); }
    }
    await relatedPage('subtasks', 'Checklist');
    const notes = el('details', '', 'event-notes-disclosure'); notes.append(el('summary', 'Complete original event notes'), el('p', task.notes || 'No source notes.', 'event-original-notes')); body.append(notes);
  }
  function sourceHighlights(notes) {
    return String(notes || '').split('\n').map(line => line.trim()).filter(line => /^(date|venue address|set.?up time|show hours|event hours|booth number|booth size|indoor\/outdoor|tent needed|table\(s\)|hotel info|event pickup time\/date|event return deadline|tear down instructions|electricity)\s*:/i.test(line));
  }
  async function check(id, parent) {
    const checked = await api('/events/asana/changes/' + id + '/reconcile', { method: 'POST', body: { reviewed: true } });
    if (!parent.isConnected) return;
    note(checked.status === 'succeeded' ? 'The original save was confirmed by Asana.' : checked.result?.current_values_match ? 'Asana currently matches these values. The original response remains unconfirmed.' : 'The current values differ. Review the task in Asana before making another change.', parent);
    if (['unknown', 'executing'].includes(checked.status) && !parent.querySelector('[data-close-uncertain]')) {
      for (const [field, value] of Object.entries(checked.result?.observed_values || {})) note('Current ' + field.replaceAll('_', ' ') + ': ' + String(value ?? 'Not set'), parent);
      const close = button('I reviewed Asana; close this uncertain save', async () => {
        close.disabled = true;
        try {
          const result = await api('/events/asana/changes/' + id + '/reconcile', { method: 'POST', body: { reviewed: true, acknowledge: true } });
          if (parent.isConnected) note(result.status === 'reviewed' ? 'Review recorded. Refresh the task before making another change. No write was retried.' : result.result?.notice || 'Review remains open.', parent);
        } catch (error) { close.disabled = false; throw error; }
      }); close.dataset.closeUncertain = '1'; parent.append(close);
    }
  }
  function edit(task) {
    const body = dialog('Review Asana task changes'), form = el('form', '', 'event-edit-form'); body.append(form);
    note('Only the fields you change will be sent to Asana. Original notes and attachments stay untouched.', form);
    const title = field(form, 'name', 'Task title', task.name); title.required = true;
    const start = field(form, 'start_on', 'Start date', task.start_on || '', 'date'), due = field(form, 'due_on', 'Due date', task.due_on || '', 'date');
    if (task.start_at || task.due_at) { start.disabled = due.disabled = true; note('This task has a scheduled time. Edit its dates in Asana to preserve the time.', form); }
    const complete = field(form, 'completed', 'Status', task.completed ? 'yes' : 'no', 'text', [{ id: 'no', name: 'Open' }, { id: 'yes', name: 'Completed' }]);
    const preview = button('Review changes', () => {}); preview.type = 'submit'; form.append(preview);
    let requestId = crypto.randomUUID(), busy = false;
    form.addEventListener('input', () => { requestId = crypto.randomUUID(); });
    form.onsubmit = async event => {
      event.preventDefault(); if (busy) return;
      const changes = {}; if (title.value !== task.name) changes.name = title.value;
      if ((start.value || null) !== task.start_on) { changes.start_on = start.value || null; changes.due_on = due.value || null; }
      if ((due.value || null) !== task.due_on) changes.due_on = due.value || null;
      if ((complete.value === 'yes') !== task.completed) changes.completed = complete.value === 'yes';
      if (!Object.keys(changes).length) { note('There are no changes to send.', body); return; }
      busy = true; preview.disabled = true;
      try {
        const result = await api('/events/asana/changes', { method: 'POST', body: { connection_id: task.connection_id, task_id: task.gid, parent_id: task.parent_id, version: task.version, request_id: requestId, changes } });
        if (!body.isConnected) return;
        const reviewed = section('Changes to save in Asana', body);
        for (const [key, value] of Object.entries(changes)) {
          const name = { name: 'Task title', start_on: 'Start date', due_on: 'Due date', completed: 'Status' }[key];
          const display = value => key === 'completed' ? (value ? 'Completed' : 'Open') : key.endsWith('_on') ? (value ? when(value) : 'Not set') : String(value ?? 'Not set');
          reviewed.append(el('p', name + ': ' + display(result.before[key]) + ' → ' + display(value)));
        }
        preview.disabled = true; for (const input of [title, start, due, complete]) input.disabled = true;
        const confirm = button('Save these changes to Asana', async () => {
          confirm.disabled = true;
          try {
            const receipt = await api('/events/asana/changes/' + result.id + '/dispatch', { method: 'POST', body: { reviewed: true } });
            if (!body.isConnected) return;
            if (receipt.status === 'succeeded') { note('Saved in Asana.', reviewed); reviewed.append(button('Read updated task', () => details(task))); return; }
            note(receipt.result?.notice || 'This save needs review. It will not be retried automatically.', reviewed);
          } catch (error) {
            if (!body.isConnected) return; note(error.message + ' Check Asana before trying another save.', reviewed);
          }
          reviewed.append(button('Check current Asana state', () => check(result.id, reviewed)), link('Review task in Asana', task.source_url));
        }, 'primary'); reviewed.append(confirm);
      } catch (error) { note(error.message, body); preview.disabled = false; }
      finally { busy = false; }
    };
  }
  return { render, connection: () => connection, reset: () => { connection = undefined; serial++; } };
}
