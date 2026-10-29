/* Current-projection adapter. Never reads the public fixture or native/private workspace. */
(function () {
  'use strict';
  let palette, host, snapshot, documents = [], generation = 0;
  let environment;
  const engine = globalThis.EverbranchSearch;
  const api = (...args) => environment.api(...args), publicPerson = (...args) => environment.publicPerson(...args), esc = value => environment.esc(value);
  const go = id => environment.go(id), toast = (...args) => environment.toast(...args), showModal = (...args) => environment.showModal(...args);
  const text = value => typeof value === 'string' ? value : Array.isArray(value) ? value.map(text).join(' ') : value && typeof value === 'object' ? Object.entries(value).filter(([key]) => !['versions', 'history', 'members', 'credentials'].includes(key)).map(([, v]) => text(v)).join(' ') : '';
  const ref = (kind, id) => kind + ':' + id;
  function collect(current) {
    const result = [];
    const add = (kind, records, title) => {
      for (const record of records || []) {
        if (!record || !record.id || record.deleted) continue;
        const projects = kind === 'project' ? [record.id] : Object.keys(record.memberships || {});
        const projectId = record.project_id; if (projectId) projects.push(projectId);
        const comments = (Array.isArray(record.comments) ? record.comments : Object.values(record.comments || {})).filter(comment => !comment.deleted_at);
        const body = [record.description, record.details, record.location, record.department, text(comments), text(record.current_status), record.resolution, record.notes].filter(Boolean).join(' ');
        result.push({ id: ref(kind, record.id), entity_id: record.id, identifier: record.id, type: kind, title: String(title(record) || record.id), body,
          context: record.department || projects.map(id => current.projects?.find(p => p.id === id)?.name).filter(Boolean).join(', '),
          status: record.status || record.phase || (record.completed_at ? 'Completed' : record.archived ? 'Archived' : 'Active'), source: 'Fictional campus workspace',
          projects, people: [record.assignee_id, record.owner_id, record.creator_id].filter(Boolean).map(String), completed: !!record.completed_at || ['completed', 'done'].includes(record.phase), archived: !!record.archived,
          updated_at: record.updated_at || '', target: { kind, id: record.id } });
      }
    };
    add('project', current.projects, row => row.name); add('task', current.tasks, row => row.title); add('request', current.requests, row => row.title);
    add('job', current.operations?.jobs, row => row.title); add('contractor', current.operations?.contractors, row => row.name); add('operation-project', current.operations?.projects, row => row.name || row.title);
    const knownPeople = new Set([current.persona, ...result.flatMap(item => item.people)]);
    for (const [id, person] of Object.entries(current.people || {})) {
      if (!knownPeople.has(id)) continue;
      const shown = typeof publicPerson === 'function' ? publicPerson(id, person) : person;
      result.push({ id: ref('person', id), entity_id: id, type: 'person', title: shown.name, body: shown.perspective || '', people: [id], source: 'Fictional campus workspace', target: { kind: 'person', id } });
    }
    const locations = new Set();
    for (const job of current.operations?.jobs || []) {
      if (!job.location || locations.has(job.location)) continue;
      locations.add(job.location); result.push({ id: ref('location', job.id), type: 'location', title: job.location, body: job.title, context: job.department, source: 'Fictional campus workspace', target: { kind: 'location', id: job.id } });
    }
    for (const file of current.files || []) {
      if (file.can_read !== true) continue;
      result.push({ id: ref('file', file.id), entity_id: file.id, type: 'file', title: file.name || file.filename, body: '', source: 'Fictional document', target: { kind: 'file', id: file.id } });
    }
    return result;
  }
  function commands(current) {
    const make = (id, title, target) => ({ id: ref('command', id), type: 'command', title, body: '', source: 'Everbranch', target });
    const rows = environment.nav.map(([id, , title]) => make(id, 'Go to ' + title, { kind: 'navigate', id }));
    const request = globalThis.EverbranchCommandDefinitions['submit-request'];
    rows.push(make('request', request.title, { kind: request.kind }));
    return rows;
  }
  async function rebuild(current) {
    const ticket = ++generation; snapshot = current; documents = collect(current);
    for (const item of documents.filter(row => row.type === 'file')) {
      try {
        const file = await api('file?id=' + encodeURIComponent(item.entity_id));
        if (ticket !== generation || snapshot !== current) return;
        item.body = file.content || '';
      } catch { /* A removed or newly denied file contributes no searchable text. */ }
    }
    documents.forEach(engine.prepare);
    if (ticket === generation) palette?.refresh();
  }
  function facets(items, current) {
    const unique = list => [...new Map(list.map(item => [item.id, item])).values()];
    return { type: unique(items.map(item => ({ id: item.type, title: item.type[0].toUpperCase() + item.type.slice(1) }))),
      project: unique(items.flatMap(item => (item.projects || []).map(id => ({ id, title: current.projects?.find(p => p.id === id)?.name || current.operations?.projects?.find(p => p.id === id)?.name || id })))),
      person: unique(items.flatMap(item => (item.people || []).map(id => ({ id, title: current.people?.[id] ? publicPerson(id, current.people[id]).name : id })))) };
  }
  async function open(item, current) {
    if (environment.pending || !current()) return false;
    const persona = environment.data.persona, scope = environment.scope, navigation = environment.navigation;
    const fresh = await api('state');
    if (!current() || environment.pending || scope !== environment.scope || persona !== fresh.persona || navigation !== environment.navigation) return false;
    const available = [...collect(fresh), ...commands(fresh)].find(row => row.id === item.id);
    if (!available) throw new Error('This item is no longer visible in this perspective.');
    const target = available.target;
    // Existing modal editors retain their owned draft lifecycle.
    if (environment.modal.open) { toast('Close the current editor before opening another item. Your draft stays here.', true); return false; }
    if (['request', 'task', 'project', 'job'].includes(target.kind)) { await environment.openCampusRecord(target.kind, target.id, persona, current); return true; }
    environment.data = fresh;
    if (target.kind === 'navigate') { go(target.id); return true; }
    if (target.kind === 'submit-request') { go('intake'); const create = document.getElementById('new-request'); if (create) create.click(); else document.querySelector('#intake-form [name="title"]')?.focus(); return true; }
    if (target.kind === 'file') {
      const file = await api('file?id=' + encodeURIComponent(target.id));
      if (!current() || persona !== environment.data.persona || environment.pending) return false;
      showModal('<h2>' + esc(file.name) + '</h2><pre>' + esc(file.content) + '</pre>'); return true;
    }
    if (target.kind === 'location') { globalThis.campusSelectedJobId = target.id; go('home'); const select = document.getElementById('job-select'); if (select) { select.value = target.id; select.dispatchEvent(new Event('change')); } return true; }
    if (['contractor', 'operation-project'].includes(target.kind)) { globalThis.setOperationsTab?.(target.kind === 'contractor' ? 'contractors' : 'projects'); go('operations'); showModal('<h2>' + esc(available.title) + '</h2><p>' + esc([available.context, available.status].filter(Boolean).join(' · ')) + '</p><p>' + esc(available.body || '') + '</p>'); return true; }
    if (target.kind === 'person') { showModal('<h2>' + esc(available.title) + '</h2><p>Fictional campus workspace member</p>'); return true; }
    return false;
  }
  globalThis.mountCampusSearch = function (env) {
    if (env) environment = env;
    const slot = document.getElementById('campus-search-slot'); if (!slot || !environment?.data) return;
    if (!host) {
      host = document.createElement('div'); host.id = 'campus-instant-search';
      palette = engine.palette({ host, id: 'campus-global', scope: 'demo:' + environment.data.persona, available: () => !environment.pending,
        async search({ q, filters, cursor, limit, recent }) {
          const current = environment.data;
          if (snapshot !== current) await rebuild(current);
          let found = engine.search([...documents, ...commands(current)], q, filters);
          if (!engine.parse(q).text && !engine.parse(q).commandsOnly) {
            const recents = recent.map(id => found.find(row => row.id === id)).filter(Boolean);
            found = [...recents, ...found.filter(row => row.type === 'command')];
          }
          const offset = Number(cursor || 0);
          return { items: found.slice(offset, offset + limit).map(row => ({ ...row, snippet: row.body?.slice(0, 180) })),
            next_cursor: found.length > offset + limit ? String(offset + limit) : null, facets: facets(documents, current) };
        }, open,
      });
      globalThis.CampusSearchPalette = palette;
    }
    slot.replaceChildren(host); palette.setScope('demo:' + environment.data.persona);
    if (snapshot !== environment.data) rebuild(environment.data);
  };
  globalThis.detachCampusSearch = () => {
    const focused = host?.contains(document.activeElement), start = palette?.input.selectionStart, end = palette?.input.selectionEnd;
    host?.remove();
    return () => { if (focused && !environment.pending && palette) { palette.input.focus({ preventScroll: true }); try { palette.input.setSelectionRange(start, end); } catch { /* Search input selection varies by browser. */ } } };
  };
  globalThis.retireCampusSearch = () => { generation++; snapshot = null; documents = []; palette?.setScope('retired:' + crypto.randomUUID()); };
})();
