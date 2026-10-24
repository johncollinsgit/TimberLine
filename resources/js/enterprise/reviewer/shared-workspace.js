/* Server-held fictional review work only. No seed, native proxy, provider or browser-facts upload. */
(() => {
    'use strict';
    const root = globalThis, instances = new WeakMap();
    const limits = Object.freeze({ responseBytes: 524288, commandBytes: 16384, storageBytes: 65536, tombstones: 64 });
    const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
    const hash = /^[a-f0-9]{64}$/, person = /^[A-Za-z0-9_-]{1,32}$/;
    const personas = ['demo_manager', 'executive', 'manager', 'worker', 'requester'];
    const actions = Object.freeze({
        'project.create': ['area_id', 'title', 'description', 'owner_id', 'due_on'],
        'project.update': ['title', 'description', 'owner_id', 'due_on'], 'project.archive': ['archived'],
        'job.create': ['area_id', 'title', 'description', 'task_title', 'owner_id', 'task_id', 'ticket_id', 'assignee_id', 'building_id', 'building_version', 'priority', 'due_on', 'type'],
        'task.create': ['area_id', 'project_id', 'title', 'description', 'assignee_id', 'building_id', 'building_version', 'priority', 'due_on'],
        'task.update': ['title', 'description', 'assignee_id', 'building_id', 'building_version', 'priority', 'due_on'],
        'task.assign': ['assignee_id'], 'task.status': ['status'], 'task.archive': ['acknowledge_removal'],
        'ticket.create': ['area_id', 'title', 'description', 'priority', 'due_on', 'building_id', 'building_version', 'type'],
        'ticket.update': ['title', 'description', 'priority', 'due_on', 'building_id', 'building_version', 'type'],
        'ticket.classify': ['type', 'priority'],
        'ticket.assign': ['target_area_id', 'project_id', 'project_version', 'task_id', 'assignee_id', 'building_id', 'building_version', 'acknowledge_routing'],
        'ticket.plan': ['target_area_id', 'project_id', 'project_version', 'task_id', 'assignee_id', 'building_id', 'building_version', 'acknowledge_routing'],
        'ticket.status': ['status'], 'ticket.reorder': ['position'],
        'plan.create': ['area_id', 'project_id', 'project_version', 'contractor_id', 'contractor_version', 'starts_on', 'ends_on'],
        'plan.update': ['area_id', 'project_id', 'project_version', 'contractor_id', 'contractor_version', 'starts_on', 'ends_on'], 'plan.cancel': []
    });
    const graphKeys = { areas: ['id', 'name', 'visibility'], buildings: ['id', 'area_id', 'title', 'version', 'status', 'updated_at'], people: ['id', 'name', 'version', 'area_ids', 'active', 'updated_at'], projects: ['id', 'area_id', 'title', 'description', 'version', 'status', 'owner_id', 'due_on', 'updated_at', 'allowed_actions'], tasks: ['id', 'project_id', 'area_id', 'title', 'description', 'version', 'status', 'priority', 'due_on', 'assignee_id', 'building_id', 'updated_at', 'allowed_actions'], tickets: ['id', 'area_id', 'title', 'description', 'version', 'status', 'priority', 'due_on', 'building_id', 'project_id', 'task_id', 'position', 'type', 'updated_at', 'allowed_actions', 'owned_by_caller'], contractors: ['id', 'area_id', 'title', 'version', 'status', 'updated_at'], plans: ['id', 'area_id', 'project_id', 'contractor_id', 'version', 'status', 'starts_on', 'ends_on', 'updated_at', 'allowed_actions'], costs: ['id', 'area_id', 'project_id', 'version', 'amount_minor', 'currency', 'basis', 'updated_at'] };
    const caps = { areas: 8, buildings: 100, people: 50, projects: 100, tasks: 200, tickets: 200, contractors: 50, plans: 100, costs: 100 };
    function error(code, outcome = 'not_submitted', status = 0) { const messages = { workspace_conflict: 'Shared work changed. Keep your draft and review fresh records.', workspace_invalid: 'This shared edit was rejected. Keep your draft and review its fields.', workspace_unknown: 'The server has not confirmed this edit. Keep it and recover or retry this exact action.', workspace_unsupported: 'This feature is unavailable in the shared fictional workspace.', workspace_storage: 'This browser could not safely retain the exact edit. It was not submitted.', workspace_context: 'The shared workspace context changed. Refresh current records.', workspace_access: 'Current shared workspace access is unavailable.', workspace_invalid_response: 'The shared workspace response could not be verified.' }; const e = new Error(messages[code] || 'The shared fictional workspace is unavailable.'); Object.assign(e, { code, outcome, status }); return e; }
    function check(ok, code = 'workspace_invalid') {
        if (!ok)
            throw error(code);
    }
    function object(x) { return x !== null && typeof x === 'object' && !Array.isArray(x) && (Object.getPrototypeOf(x) === null || Object.getPrototypeOf(Object.getPrototypeOf(x)) === null); }
    function exact(x, keys) { check(object(x)); check(Reflect.ownKeys(x).length === keys.length && keys.every(k => Object.hasOwn(x, k) && Object.getOwnPropertyDescriptor(x, k)?.get === undefined && Object.getOwnPropertyDescriptor(x, k)?.set === undefined)); }
    function string(x, max, empty = false) { return typeof x === 'string' && [...x].length <= max && (empty || x.trim() !== '') && ![...x].some(c => { const n = c.codePointAt(0); return n >= 0xd800 && n <= 0xdfff; }) && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/u.test(x); }
    const integer = (x, min = 1, max = Number.MAX_SAFE_INTEGER) => Number.isSafeInteger(x) && x >= min && x <= max;
    const date = x => x === null || typeof x === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(x) && x.slice(0, 4) !== '0000' && Number.isFinite(Date.parse(x + 'T00:00:00Z')) && new Date(x + 'T00:00:00Z').toISOString().slice(0, 10) === x;
    const utc = x => typeof x === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(x) && Number.isFinite(Date.parse(x)) && new Date(x).toISOString().replace('.000Z', 'Z') === x;
    const clone = x => JSON.parse(JSON.stringify(x));
    function freeze(x) {
        if (x && typeof x === 'object') {
            Object.values(x).forEach(freeze);
            Object.freeze(x);
        }
        return x;
    }
    function canonical(x) {
        const seen = new Set();
        function visit(v) {
            if (v === null || typeof v === 'boolean')
                return v;
            if (typeof v === 'string') {
                check(string(v, limits.responseBytes, true));
                return v;
            }
            if (typeof v === 'number') {
                check(Number.isSafeInteger(v));
                return v;
            }
            check(v && typeof v === 'object' && !seen.has(v));
            seen.add(v);
            let out;
            if (Array.isArray(v)) {
                check(Object.keys(v).length === v.length);
                out = v.map(visit);
            }
            else {
                check(object(v));
                out = {};
                for (const k of Object.keys(v).sort()) {
                    check(/^[A-Za-z0-9_]+$/.test(k) && !['__proto__', 'constructor', 'prototype'].includes(k));
                    const d = Object.getOwnPropertyDescriptor(v, k);
                    check(d && 'value' in d && d.enumerable);
                    out[k] = visit(d.value);
                }
            }
            seen.delete(v);
            return out;
        }
        return JSON.stringify(visit(x));
    }
    const bytes = x => new TextEncoder().encode(x).length;
    async function digest(x) { return [...new Uint8Array(await root.crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical(x))))].map(v => v.toString(16).padStart(2, '0')).join(''); }
    function binding(x) { exact(x, ['version_hash', 'build_id', 'source_hash']); check(hash.test(x.version_hash) && hash.test(x.source_hash) && string(x.build_id, 120)); return clone(x); }
    function selection(x) { exact(x, ['persona', 'area_ids', 'person_id']); check(personas.includes(x.persona) && Array.isArray(x.area_ids) && integer(x.area_ids.length, 1, 8) && x.area_ids.every(v => typeof v === 'string' && uuid.test(v)) && new Set(x.area_ids).size === x.area_ids.length && JSON.stringify([...x.area_ids].sort()) === JSON.stringify(x.area_ids) && ((x.persona === 'worker' && typeof x.person_id === 'string' && person.test(x.person_id)) || (x.persona !== 'worker' && x.person_id === null))); return clone(x); }
    function payload(action, x) {
        check(Object.hasOwn(actions, action), 'workspace_unsupported');
        exact(x, actions[action]);
        for (const [k, v] of Object.entries(x)) {
            if (['owner_id', 'assignee_id'].includes(k))
                check(v === null || typeof v === 'string' && person.test(v));
            else if (k.endsWith('_id'))
                check(k === 'building_id' && v === null || typeof v === 'string' && uuid.test(v));
            else if (k.endsWith('_version'))
                check(k === 'building_version' && v === null || integer(v));
            else if (['title', 'task_title'].includes(k))
                check(string(v, 100));
            else if (k === 'description')
                check(string(v, 5000, true));
            else if (['due_on', 'starts_on', 'ends_on'].includes(k))
                check(date(v));
            else if (k === 'priority')
                check(v === null || ['low', 'normal', 'high', 'urgent'].includes(v));
            else if (k === 'type')
                check(['general', 'it_support', 'event_setup', 'plumbing', 'lighting', 'maintenance'].includes(v));
            else if (k === 'position')
                check(integer(v, 0, 199));
            else if (k === 'archived')
                check(typeof v === 'boolean');
            else if (k.startsWith('acknowledge_'))
                check(v === true);
            else if (k === 'status')
                check((action === 'task.status' ? ['active', 'in_progress', 'interrupted', 'awaiting_verification', 'completed', 'closed'] : ['submitted', 'triaged', 'assigned', 'in_progress', 'completed', 'closed']).includes(v));
        }
        if ('building_id' in x)
            check((x.building_id === null) === (x.building_version === null));
        if (action.startsWith('plan.') && action !== 'plan.cancel')
            check(x.starts_on !== null && x.ends_on !== null && x.starts_on <= x.ends_on);
        if (action === 'job.create')
            check(x.assignee_id !== null && new Set([x.task_id, x.ticket_id]).size === 2);
        return clone(x);
    }
    function command(x, expectedBinding) {
        exact(x, ['binding', 'selection', 'request_id', 'expected_revision', 'action', 'record_id', 'expected_record_version', 'payload']);
        binding(x.binding);
        check(canonical(x.binding) === canonical(expectedBinding));
        selection(x.selection);
        check(uuid.test(x.request_id) && uuid.test(x.record_id) && integer(x.expected_revision) && integer(x.expected_record_version, 0));
        payload(x.action, x.payload);
        check(x.action.endsWith('.create') ? x.expected_record_version === 0 : x.expected_record_version > 0);
        if (x.action === 'job.create')
            check(![x.payload.task_id, x.payload.ticket_id].includes(x.record_id));
        check(bytes(canonical(x)) <= limits.commandBytes);
        return clone(x);
    }
    function actionList(x) { check(Array.isArray(x) && x.length <= Object.keys(actions).length && new Set(x).size === x.length && x.every(v => Object.hasOwn(actions, v))); }
    function projection(x, descriptor, requested) {
        check(bytes(canonical(x)) <= limits.responseBytes, 'workspace_invalid_response');
        exact(x, ['workspace_id', 'revision', 'workspace_digest', 'selection', 'allowed_areas', 'graph', 'permitted_actions', 'capabilities', 'origin', 'expires_at']);
        check(x.workspace_id === descriptor.workspace_id && integer(x.revision) && hash.test(x.workspace_digest) && x.origin === 'server_shared_fictional' && utc(x.expires_at) && Date.parse(x.expires_at) > Date.now() && Date.parse(x.expires_at) <= Date.parse(descriptor.expires_at), 'workspace_invalid_response');
        selection(x.selection);
        check(canonical(x.selection) === canonical(requested), 'workspace_context');
        actionList(x.permitted_actions);
        exact(x.capabilities, ['can_view_costs', 'can_manage_plans', 'can_manage_costs']);
        check(typeof x.capabilities.can_view_costs === 'boolean' && typeof x.capabilities.can_manage_plans === 'boolean' && x.capabilities.can_manage_costs === false);
        check(Array.isArray(x.allowed_areas) && integer(x.allowed_areas.length, 1, 8));
        const directory = new Set();
        for (const r of x.allowed_areas) {
            exact(r, graphKeys.areas);
            check(uuid.test(r.id) && string(r.name, 100) && ['shared', 'department_only'].includes(r.visibility) && !directory.has(r.id));
            directory.add(r.id);
        }
        check(requested.area_ids.every(id => directory.has(id)));
        exact(x.graph, ['schema_version', ...Object.keys(graphKeys)]);
        check(x.graph.schema_version === 1);
        const maps = {};
        for (const [k, keys] of Object.entries(graphKeys)) {
            const rows = x.graph[k];
            check(Array.isArray(rows) && rows.length <= caps[k]);
            maps[k] = new Map();
            for (const r of rows) {
                const mappedBuilding = k === 'buildings' && Object.hasOwn(r, 'map_reference');
                exact(r, mappedBuilding ? [...keys, 'map_reference'] : keys);
                if (mappedBuilding) {
                    check(root.EverbranchSharedWorkspaceMap && typeof root.EverbranchSharedWorkspaceMap.validate === 'function', 'workspace_invalid_response');
                    root.EverbranchSharedWorkspaceMap.validate(r.map_reference);
                }
                check(typeof r.id === 'string' && (k === 'people' ? person : uuid).test(r.id) && !maps[k].has(r.id));
                if ('area_id' in r)
                    check(requested.area_ids.includes(r.area_id));
                if ('version' in r)
                    check(integer(r.version));
                if ('updated_at' in r)
                    check(r.updated_at === null || utc(r.updated_at));
                if ('title' in r)
                    check(string(r.title, k === 'buildings' ? 200 : 100));
                if ('description' in r)
                    check(string(r.description, 5000, true));
                if ('due_on' in r)
                    check(date(r.due_on));
                if ('priority' in r)
                    check(r.priority === null || ['low', 'normal', 'high', 'urgent'].includes(r.priority));
                if ('allowed_actions' in r) {
                    actionList(r.allowed_actions);
                    check(r.allowed_actions.every(a => x.permitted_actions.includes(a)));
                }
                maps[k].set(r.id, r);
            }
        }
        check(x.graph.areas.length === requested.area_ids.length && x.graph.areas.every(r => directory.has(r.id) && requested.area_ids.includes(r.id) && string(r.name, 100) && ['shared', 'department_only'].includes(r.visibility)));
        for (const r of x.graph.people)
            check(string(r.name, 100) && typeof r.active === 'boolean' && Array.isArray(r.area_ids) && r.area_ids.length > 0 && r.area_ids.length <= 8 && new Set(r.area_ids).size === r.area_ids.length && r.area_ids.every(id => uuid.test(id)));
        for (const k of ['buildings', 'contractors'])
            for (const r of x.graph[k])
                check(['active', 'inactive'].includes(r.status));
        for (const r of x.graph.projects)
            check(['active', 'on_track', 'on_hold', 'at_risk', 'archived'].includes(r.status) && (r.owner_id === null || maps.people.has(r.owner_id)));
        for (const r of x.graph.tasks) {
            const p = maps.projects.get(r.project_id);
            check(p && p.area_id === r.area_id && ['active', 'in_progress', 'interrupted', 'awaiting_verification', 'completed', 'closed', 'archived'].includes(r.status) && (r.assignee_id === null || maps.people.has(r.assignee_id)) && (r.building_id === null || maps.buildings.get(r.building_id)?.area_id === r.area_id));
        }
        for (const r of x.graph.tickets) {
            check(typeof r.owned_by_caller === 'boolean' && integer(r.position, 0, 1000000) && ['submitted', 'triaged', 'assigned', 'in_progress', 'completed', 'closed'].includes(r.status) && ['general', 'it_support', 'event_setup', 'plumbing', 'lighting', 'maintenance'].includes(r.type) && (r.building_id === null || maps.buildings.get(r.building_id)?.area_id === r.area_id) && (r.project_id === null || maps.projects.get(r.project_id)?.area_id === r.area_id) && (r.task_id === null || maps.tasks.get(r.task_id)?.project_id === r.project_id));
        }
        for (const r of x.graph.plans)
            check(maps.projects.get(r.project_id)?.area_id === r.area_id && maps.contractors.get(r.contractor_id)?.area_id === r.area_id && ['planned', 'cancelled'].includes(r.status) && r.starts_on !== null && r.ends_on !== null && date(r.starts_on) && date(r.ends_on) && r.starts_on <= r.ends_on);
        check(x.graph.people.length + x.graph.contractors.length <= 50 && x.graph.plans.length + x.graph.costs.length <= 100);
        for (const r of x.graph.costs)
            check(x.capabilities.can_view_costs && ['demo_manager', 'manager', 'executive'].includes(requested.persona) && maps.projects.get(r.project_id)?.area_id === r.area_id && integer(r.amount_minor, 0, 1000000000) && /^[A-Z]{3}$/.test(r.currency) && ['estimate', 'recorded_cost'].includes(r.basis));
        if (requested.persona === 'requester')
            check(['people', 'projects', 'tasks', 'contractors', 'plans', 'costs'].every(k => x.graph[k].length === 0) && x.graph.tickets.every(r => r.owned_by_caller));
        if (requested.persona === 'worker')
            check(x.graph.tasks.every(r => r.assignee_id === requested.person_id) && x.graph.tickets.every(r => r.owned_by_caller || maps.tasks.has(r.task_id)));
        if (['worker', 'requester'].includes(requested.persona))
            check(x.capabilities.can_view_costs === false);
        return freeze(clone(x));
    }
    function data(value, areaId) {
        check(value.selection.area_ids.includes(areaId), 'workspace_context');
        const g = value.graph, has = (r, a) => r.allowed_actions?.includes(a) === true, global = a => value.permitted_actions.includes(a);
        const building = id => g.buildings.find(r => r.id === id && r.status === 'active');
        const tasks = g.tasks.filter(r => r.area_id === areaId).map(r => { const linked = g.tickets.filter(t => t.task_id === r.id && t.project_id === r.project_id), assigned = g.people.find(p => p.id === r.assignee_id && p.active && p.area_ids.includes(areaId)); return { ...clone(r), assignee: assigned ? { id: assigned.id, name: assigned.name } : r.assignee_id === null ? null : { id: r.assignee_id, name: 'Assigned person · name unavailable' }, enterprise_area_id: r.area_id, deleted: r.status === 'archived', completed_at: r.status === 'completed' ? r.updated_at : null, can_edit: has(r, 'task.update') && r.status !== 'archived', edit_link_status: 'server_shared', linked_request: linked.length === 1 ? { id: linked[0].id, version: linked[0].version } : null }; });
        const projects = g.projects.filter(r => r.area_id === areaId).map(r => ({ ...clone(r), name: r.title, enterprise_area_id: r.area_id, owner_user_id: r.owner_id, archived: r.status === 'archived', current_status: { code: r.status, label: r.status.replaceAll('_', ' ') }, tasks: tasks.filter(t => t.project_id === r.id && !t.deleted), locations: [...new Map(tasks.filter(t => t.project_id === r.id).map(t => building(t.building_id)).filter(Boolean).map(b => [b.id, { id: b.id, title: b.title, version: b.version }])).values()], can_edit: has(r, 'project.update') || has(r, 'project.archive'), can_add_task: global('task.create') && r.status !== 'archived' }));
        const requests = g.tickets.filter(r => r.area_id === areaId).sort((a, b) => a.position - b.position || a.id.localeCompare(b.id)).map(r => { const b = building(r.building_id), task = tasks.find(t => t.id === r.task_id && t.project_id === r.project_id) || null; return { ...clone(r), request_type: r.type, requested_priority: r.priority, priority: task?.priority ?? r.priority, priority_source: task ? 'task' : 'request', task, can_coordinate: ['ticket.classify', 'ticket.assign', 'ticket.plan'].some(a => has(r, a)), can_progress: has(r, 'ticket.status') || has(task || {}, 'task.status'), shared_status_target: has(task || {}, 'task.status') ? { action: 'task.status', record_id: task.id, version: task.version } : has(r, 'ticket.status') ? { action: 'ticket.status', record_id: r.id, version: r.version } : null, data: { description: r.description, location: b?.title || '', location_id: b?.id || null, location_version: b?.version || null, project_id: r.project_id, task_id: r.task_id } }; });
        return freeze({ areas: clone(value.allowed_areas), selected_area_id: areaId, demo_persona: value.selection.persona, sample_data: true, shared_fictional: true, projects, tasks: tasks.filter(t => !t.deleted), requests, members: g.people.filter(p => p.active && p.area_ids.includes(areaId)).map(p => ({ id: p.id, name: p.name, version: p.version })), buildings: { items: g.buildings.filter(b => b.area_id === areaId && b.status === 'active').map(b => ({ id: b.id, title: b.title, version: b.version, ...(Object.hasOwn(b, 'map_reference') ? root.EverbranchSharedWorkspaceMap.buildingDto(b) : {}) })), has_more: false, coverage: 'Current saved buildings in the server-held fictional workspace.' }, routing_departments: clone(value.allowed_areas), request_fields: { version: 1, types: { general: 'General request', it_support: 'IT support', event_setup: 'Event setup', plumbing: 'Plumbing', lighting: 'Lighting', maintenance: 'Maintenance' }, priorities: ['low', 'normal', 'high', 'urgent'] }, features: { planning: true, finance: value.capabilities.can_view_costs, collaboration: false, operations: false, resources: false, workflow: false, roll_call: true, requester: false, ask: false, voice_jobs: false, email_drafts: false }, can_coordinate: ['job.create', 'project.create', 'ticket.assign', 'ticket.plan'].some(global), can_submit: global('ticket.create'), can_manage: false, can_review: false, has_more: false, scan_partial: false, tasks_has_more: false, coverage: 'Server-held shared fictional demonstration records. No native college or employee authority; unsupported features are separate.', shared: { workspace_id: value.workspace_id, revision: value.revision, workspace_digest: value.workspace_digest, selection: clone(value.selection) } });
    }
    function planning(value, areaId) { check(value.selection.area_ids.includes(areaId), 'workspace_context'); const g = value.graph; const items = [...g.contractors.filter(r => r.area_id === areaId).map(r => ({ ...clone(r), kind: 'contractor', starts_on: null, ends_on: null, data: {}, can_manage: false })), ...g.plans.filter(r => r.area_id === areaId).map(r => ({ ...clone(r), kind: 'schedule', title: `Planned contractor block · ${g.contractors.find(c => c.id === r.contractor_id)?.title || 'Contractor not in this result'}`, data: { project_id: r.project_id, contractor_id: r.contractor_id }, can_manage: r.allowed_actions.length > 0 })), ...g.costs.filter(r => r.area_id === areaId).map(r => ({ ...clone(r), kind: 'cost', title: 'Fictional project cost input', status: 'recorded', starts_on: null, ends_on: null, data: { project_id: r.project_id, amount_minor: r.amount_minor, currency: r.currency, basis: r.basis }, can_manage: false }))]; return freeze({ items, can_coordinate: value.capabilities.can_manage_plans, can_finance: value.capabilities.can_view_costs, can_manage_costs: false, basis: 'Server-held fictional plans and cost inputs; not attendance, approved budgets, invoices or payments.', partial: false }); }
    function create(boot) {
        check(object(boot));
        if (instances.has(boot))
            return instances.get(boot);
        check(object(boot.reviewerWorkspace), 'workspace_access');
        const d = JSON.parse(canonical(boot.reviewerWorkspace));
        exact(d, ['enabled', 'schema_version', 'workspace_id', 'browser_context', 'seed_sha256', 'policy_sha256', 'expires_at', 'binding', 'selection', 'endpoints', 'csrf']);
        check(d.enabled === true && d.schema_version === 1 && uuid.test(d.workspace_id) && hash.test(d.browser_context) && hash.test(d.seed_sha256) && hash.test(d.policy_sha256) && utc(d.expires_at) && Date.parse(d.expires_at) > Date.now(), 'workspace_access');
        binding(d.binding);
        selection(d.selection);
        check(string(d.csrf, 512));
        exact(d.endpoints, ['projection', 'commands', 'status', 'facts']);
        const endpoint = {};
        for (const [k, suffix] of Object.entries({ projection: '/projection', commands: '/commands', status: '/command-status', facts: '/facts' })) {
            const u = new URL(d.endpoints[k], root.location.href);
            check(u.origin === root.location.origin && !u.username && !u.password && !u.search && !u.hash && u.pathname.endsWith('/workspace' + suffix), 'workspace_access');
            endpoint[k] = u.href;
        }
        check(new Set(Object.values(endpoint).map(u => u.slice(0, u.lastIndexOf('/')))).size === 1, 'workspace_access');
        check(object(boot.routes) && ['back', 'data', 'enterprise'].every(k => typeof boot.routes[k] === 'string' && boot.routes[k] !== ''), 'workspace_access');
        const nav = new URL(boot.routes.back, root.location.href);
        check(nav.origin === root.location.origin && !nav.username && !nav.password && !nav.search && !nav.hash, 'workspace_access');
        const aliases = { data: new URL(boot.routes.data, root.location.href), planning: new URL(boot.routes.enterprise + '/planning', root.location.href) }, logicalTenant = aliases.data.searchParams.get('tenant') ?? boot.identity?.tenantSlug ?? null;
        check(Object.values(aliases).every(u => u.origin === root.location.origin && !u.username && !u.password && !u.hash), 'workspace_access');
        const key = `everbranch-shared-workspace:${d.workspace_id}:${d.binding.version_hash}:${d.browser_context}`;
        let store;
        try {
            store = root.sessionStorage;
        }
        catch {
            throw error('workspace_storage');
        }
        check(store && typeof store.getItem === 'function' && typeof store.setItem === 'function');
        let generation = 0, readSerial = 0, disposed = false, phase = 'idle', latestRevision = 0, latestDigest = null, lastProjection = null, active = clone(d.selection), inflight = null, verifiedRejection = null, warning = false;
        const listeners = new Set(), readControllers = new Set();
        let stored = null, state = { schema_version: 1, workspace_id: d.workspace_id, browser_context: d.browser_context, binding: d.binding, pending: null, draft: null, tombstones: [] };
        function validateEntry(e) { exact(e, ['command', 'digest', 'unknown']); command(e.command, d.binding); check(hash.test(e.digest) && typeof e.unknown === 'boolean'); }
        function validateState(s) {
            exact(s, ['schema_version', 'workspace_id', 'browser_context', 'binding', 'pending', 'draft', 'tombstones']);
            check(s.schema_version === 1 && s.workspace_id === d.workspace_id && s.browser_context === d.browser_context && canonical(s.binding) === canonical(d.binding) && Array.isArray(s.tombstones) && s.tombstones.length <= limits.tombstones);
            if (s.pending !== null)
                validateEntry(s.pending);
            if (s.draft !== null)
                command(s.draft, d.binding);
            for (const t of s.tombstones) {
                exact(t, ['request_id', 'digest', 'kind']);
                check(uuid.test(t.request_id) && hash.test(t.digest) && t.kind === 'rejected');
            }
        }
        try {
            stored = store.getItem(key);
            if (stored !== null) {
                check(typeof stored === 'string' && bytes(stored) <= limits.storageBytes);
                state = JSON.parse(stored);
                validateState(state);
                if (state.pending) {
                    state.pending.unknown = true;
                    phase = 'unknown';
                }
            }
        }
        catch {
            throw error('workspace_storage');
        }
        const owned = (a, b) => a && b && a.digest === b.digest && a.command.request_id === b.command.request_id;
        const notify = () => {
            const s = freeze({ mode: 'shared', phase, revision: lastProjection?.revision ?? null, pending: state.pending ? { request_id: state.pending.command.request_id, digest: state.pending.digest } : null, warning });
            for (const f of listeners) {
                try {
                    f(s);
                }
                catch { }
            }
        };
        function persist(next) {
            validateState(next);
            const value = canonical(next);
            check(bytes(value) <= limits.storageBytes, 'workspace_storage');
            try {
                if (store.getItem(key) !== stored)
                    throw 0;
                const result = store.setItem(key, value);
                if (result === false || store.getItem(key) !== value)
                    throw 0;
            }
            catch {
                throw error('workspace_storage');
            }
            stored = value;
            state = clone(next);
        }
        function current(epoch) { return !disposed && epoch === generation; }
        function accept(value, sel, epoch) {
            const out = projection(value, d, sel);
            check(out.revision >= latestRevision && (out.revision !== latestRevision || latestDigest === null || out.workspace_digest === latestDigest), 'workspace_context');
            if (current(epoch)) {
                latestRevision = out.revision;
                latestDigest = out.workspace_digest;
                if (canonical(sel) === canonical(active))
                    lastProjection = out;
            }
            return out;
        }
        async function post(kind, body, signal) {
            check(!disposed, 'workspace_context');
            let response;
            try {
                response = await root.fetch(endpoint[kind], { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': d.csrf }, body: canonical(body) });
            }
            catch {
                throw error(signal?.aborted ? 'workspace_context' : 'workspace_unknown', 'unknown');
            }
            let value;
            try {
                if (response.body?.getReader) {
                    const reader = response.body.getReader(), chunks = [];
                    let total = 0;
                    try {
                        for (;;) {
                            const part = await reader.read();
                            if (part.done)
                                break;
                            total += part.value.byteLength;
                            if (total > limits.responseBytes) {
                                await reader.cancel();
                                throw 0;
                            }
                            chunks.push(part.value);
                        }
                    }
                    finally {
                        reader.releaseLock();
                    }
                    const raw = new Uint8Array(total);
                    let offset = 0;
                    for (const chunk of chunks) {
                        raw.set(chunk, offset);
                        offset += chunk.byteLength;
                    }
                    value = JSON.parse(new root.TextDecoder('utf-8', { fatal: true }).decode(raw));
                }
                else if (typeof response.text === 'function') {
                    const raw = await response.text();
                    if (bytes(raw) > limits.responseBytes)
                        throw 0;
                    value = JSON.parse(raw);
                }
                else {
                    value = await response.json();
                    if (bytes(canonical(value)) > limits.responseBytes)
                        throw 0;
                }
            }
            catch {
                throw error('workspace_unknown', 'unknown', response.status);
            }
            return { ok: response.ok, status: response.status, value };
        }
        function unavailable(status) {
            if ([403, 404, 410, 419].includes(status)) {
                return error('workspace_access', 'unknown', status);
            }
            return error('workspace_unknown', 'unknown', status);
        }
        async function verifyPrepared(p) { exact(p, ['schema_version', 'workspace_id', 'browser_context', 'command', 'digest']); check(p.schema_version === 1 && p.workspace_id === d.workspace_id && p.browser_context === d.browser_context && hash.test(p.digest)); command(p.command, d.binding); const captured = clone(p); check(await digest(captured.command) === captured.digest); return freeze(captured); }
        function receipt(r, p) { exact(r, ['request_id', 'record_id', 'revision', 'digest']); check(r.request_id === p.command.request_id && r.record_id === p.command.record_id && r.digest === p.digest && integer(r.revision) && r.revision > p.command.expected_revision, 'workspace_invalid_response'); return clone(r); }
        function definitive(out, p) {
            try {
                exact(out.value, ['error', 'automatic_retry', 'definitive_not_applied', 'request_id', 'digest', 'workspace_id']);
                return [409, 422].includes(out.status) && out.value.error === (out.status === 409 ? 'workspace_conflict' : 'workspace_invalid') && out.value.automatic_retry === false && out.value.definitive_not_applied === true && out.value.request_id === p.command.request_id && out.value.digest === p.digest && out.value.workspace_id === d.workspace_id;
            }
            catch {
                return false;
            }
        }
        async function applied(out, p, epoch, recovering) {
            exact(out, recovering ? ['status', 'settled', 'receipt', 'projection'] : ['status', 'replayed', 'receipt', 'projection']);
            check(out.status === 'applied' && (recovering ? out.settled === true : typeof out.replayed === 'boolean'), 'workspace_invalid_response');
            const r = receipt(out.receipt, p), view = projection(out.projection, d, p.command.selection);
            check(view.revision >= r.revision, 'workspace_invalid_response');
            if (!owned(state.pending, p))
                throw error('workspace_context', 'unknown');
            const publish = current(epoch) && canonical(p.command.selection) === canonical(active) && view.revision >= latestRevision && (view.revision !== latestRevision || latestDigest === null || view.workspace_digest === latestDigest);
            if (publish) {
                latestRevision = view.revision;
                latestDigest = view.workspace_digest;
                lastProjection = view;
            }
            const next = clone(state);
            next.pending = null;
            if (next.draft && canonical(next.draft) === canonical(p.command))
                next.draft = null;
            try {
                persist(next);
            }
            catch {
                warning = true;
                state = next;
            }
            verifiedRejection = null;
            phase = 'applied';
            notify();
            return freeze(publish ? clone(out) : { status: 'applied', receipt: r, stale_context: true });
        }
        async function execute(p, retry = false) {
            const begin = generation;
            p = await verifyPrepared(p);
            check(!disposed && begin === generation, 'workspace_context');
            check(!inflight, 'workspace_context');
            if (retry)
                check(owned(state.pending, p), 'workspace_context');
            else {
                check(state.pending === null, 'workspace_context');
                check(!state.tombstones.some(t => t.request_id === p.command.request_id), 'workspace_conflict');
                const next = clone(state);
                next.pending = { command: p.command, digest: p.digest, unknown: false };
                next.draft = p.command;
                persist(next);
            }
            const epoch = generation, hadUnknown = state.pending.unknown;
            verifiedRejection = null;
            phase = 'saving';
            notify();
            const marker = {};
            inflight = marker;
            try {
                const out = await post('commands', p.command);
                if (!out.ok) {
                    if (!hadUnknown && owned(state.pending, p) && definitive(out, p)) {
                        verifiedRejection = p;
                        phase = 'rejected';
                        notify();
                        throw error(out.value.error, 'rejected', out.status);
                    }
                    throw unavailable(out.status);
                }
                return await applied(out.value, p, epoch, false);
            }
            catch (e) {
                if (e.outcome === 'rejected')
                    throw e;
                if (owned(state.pending, p)) {
                    state.pending.unknown = true;
                    try {
                        persist(state);
                    }
                    catch {
                        warning = true;
                    }
                    phase = 'unknown';
                    notify();
                }
                throw error(e.code === 'workspace_access' ? 'workspace_access' : 'workspace_unknown', 'unknown', e.status || 0);
            }
            finally {
                if (inflight === marker)
                    inflight = null;
            }
        }
        function href(value) {
            const u = new URL(String(value), root.location.href);
            check(u.origin === nav.origin && u.pathname === nav.pathname && !u.username && !u.password && !u.search, 'workspace_unsupported');
            const params = new URLSearchParams(u.hash.slice(1));
            for (const [k, v] of params) {
                check(['area_id', 'selected', 'section', 'view', 'map', 'q'].includes(k) && params.getAll(k).length === 1, 'workspace_unsupported');
                if (['area_id', 'selected'].includes(k))
                    check(uuid.test(v));
                if (k === 'section')
                    check(['home', 'projects', 'requests', 'roll_call', 'contractors', 'people', 'buildings', 'calendar', 'ask'].includes(v), 'workspace_unsupported');
                if (k === 'view')
                    check(['list', 'calendar', 'map'].includes(v));
                if (k === 'map')
                    check(['0', '1'].includes(v));
                if (k === 'q')
                    check(string(v, 200, true));
            }
            return u.href;
        }
        const api = Object.freeze({ mode: 'shared', limits,
            async readSelection(sel, options = {}) {
                sel = selection(sel);
                exact(options, options.signal ? ['signal'] : []);
                const epoch = generation, ticket = ++readSerial, controller = new AbortController();
                readControllers.add(controller);
                const abort = () => controller.abort();
                options.signal?.addEventListener('abort', abort, { once: true });
                if (options.signal?.aborted)
                    controller.abort();
                phase = 'loading';
                notify();
                try {
                    const out = await post('projection', { binding: d.binding, selection: sel }, controller.signal);
                    if (!out.ok)
                        throw unavailable(out.status);
                    check(current(epoch), 'workspace_context');
                    const value = accept(out.value, sel, epoch);
                    if (ticket === readSerial) {
                        phase = state.pending ? 'unknown' : 'ready';
                        notify();
                    }
                    return value;
                }
                catch (e) {
                    if (current(epoch) && ticket === readSerial) {
                        lastProjection = null;
                        phase = state.pending ? 'unknown' : e.code === 'workspace_access' ? 'blocked' : 'unavailable';
                        notify();
                    }
                    throw e;
                }
                finally {
                    readControllers.delete(controller);
                    options.signal?.removeEventListener('abort', abort);
                }
            },
            async readFacts(sel, options = {}) {
                sel = selection(sel);
                exact(options, options.signal ? ['signal'] : []);
                const epoch = generation, controller = new AbortController();
                readControllers.add(controller);
                const abort = () => controller.abort();
                options.signal?.addEventListener('abort', abort, { once: true });
                if (options.signal?.aborted)
                    controller.abort();
                try {
                    const out = await post('facts', { binding: d.binding, selection: sel }, controller.signal);
                    if (!out.ok)
                        throw unavailable(out.status);
                    check(current(epoch), 'workspace_context');
                    exact(out.value, ['snapshot', 'pin']);
                    check(object(out.value.snapshot) && object(out.value.pin) && bytes(canonical(out.value.snapshot)) <= 24000, 'workspace_invalid_response');
                    const p = out.value.pin;
                    exact(p, ['workspace_id', 'revision', 'workspace_digest', 'selection_digest', 'snapshot_digest', 'policy_hash', 'capability_hash', 'version_hash', 'build_id', 'source_hash']);
                    check(p.workspace_id === d.workspace_id && integer(p.revision) && p.version_hash === d.binding.version_hash && p.source_hash === d.binding.source_hash && p.build_id === d.binding.build_id && p.policy_hash === d.policy_sha256 && ['workspace_digest', 'selection_digest', 'snapshot_digest', 'policy_hash', 'capability_hash'].every(k => typeof p[k] === 'string' && hash.test(p[k])), 'workspace_invalid_response');
                    return freeze(clone(out.value));
                }
                finally {
                    readControllers.delete(controller);
                    options.signal?.removeEventListener('abort', abort);
                }
            },
            pin(value) { const v = projection(value, d, value.selection); return freeze({ workspace_id: d.workspace_id, browser_context: d.browser_context, revision: v.revision, workspace_digest: v.workspace_digest, selection: clone(v.selection), binding: clone(d.binding) }); },
            async prepareCommand(pin, input) { exact(pin, ['workspace_id', 'browser_context', 'revision', 'workspace_digest', 'selection', 'binding']); check(pin.workspace_id === d.workspace_id && pin.browser_context === d.browser_context && integer(pin.revision) && hash.test(pin.workspace_digest) && canonical(pin.binding) === canonical(d.binding)); selection(pin.selection); exact(input, ['request_id', 'action', 'record_id', 'expected_record_version', 'payload']); const c = command({ binding: d.binding, selection: pin.selection, request_id: input.request_id, expected_revision: pin.revision, action: input.action, record_id: input.record_id, expected_record_version: input.expected_record_version, payload: input.payload }, d.binding); return freeze({ schema_version: 1, workspace_id: d.workspace_id, browser_context: d.browser_context, command: c, digest: await digest(c) }); },
            send: p => execute(p, false), retry: p => execute(p, true),
            async recover() {
                check(state.pending !== null && !inflight, 'workspace_context');
                const begin = generation, p = await verifyPrepared({ schema_version: 1, workspace_id: d.workspace_id, browser_context: d.browser_context, command: state.pending.command, digest: state.pending.digest });
                check(!disposed && begin === generation && !inflight && owned(state.pending, p), 'workspace_context');
                const epoch = generation, marker = {};
                inflight = marker;
                state.pending.unknown = true;
                verifiedRejection = null;
                try {
                    const out = await post('status', { binding: d.binding, selection: p.command.selection, request_id: p.command.request_id, digest: p.digest });
                    if (!out.ok)
                        throw unavailable(out.status);
                    if (out.value?.status === 'not_observed') {
                        exact(out.value, ['status', 'settled']);
                        check(out.value.settled === false);
                        phase = 'unknown';
                        notify();
                        return freeze({ status: 'not_observed', settled: false });
                    }
                    return await applied(out.value, p, epoch, true);
                }
                catch (e) {
                    if (owned(state.pending, p)) {
                        phase = 'unknown';
                        notify();
                    }
                    throw error(e.code === 'workspace_access' ? 'workspace_access' : 'workspace_unknown', 'unknown', e.status || 0);
                }
                finally {
                    if (inflight === marker)
                        inflight = null;
                }
            },
            acknowledgeRejection(p) { check(verifiedRejection && owned(state.pending, verifiedRejection) && p.digest === verifiedRejection.digest && p.command.request_id === verifiedRejection.command.request_id && !state.pending.unknown, 'workspace_context'); check(state.tombstones.length < limits.tombstones, 'workspace_storage'); const next = clone(state); next.tombstones.push({ request_id: p.command.request_id, digest: p.digest, kind: 'rejected' }); next.pending = null; persist(next); verifiedRejection = null; phase = 'draft'; notify(); return freeze(clone(state.draft)); },
            pending: () => state.pending ? freeze({ schema_version: 1, workspace_id: d.workspace_id, browser_context: d.browser_context, command: clone(state.pending.command), digest: state.pending.digest }) : null,
            draft: () => state.draft ? freeze(clone(state.draft)) : null,
            snapshot: () => freeze({ mode: 'shared', workspace_id: d.workspace_id, browser_context: d.browser_context, persona: active.persona, area_ids: [...active.area_ids], person_id: active.person_id, revision: lastProjection?.revision ?? null, workspace_digest: lastProjection?.workspace_digest ?? null, phase }),
            setSelection(sel) { active = selection(sel); api.invalidate(); },
            subscribe(fn) { check(typeof fn === 'function'); listeners.add(fn); return () => listeners.delete(fn); },
            invalidate() { generation++; readSerial++; lastProjection = null; readControllers.forEach(c => c.abort()); phase = state.pending ? 'unknown' : 'idle'; notify(); },
            destroy() {
                if (disposed)
                    return;
                api.invalidate();
                disposed = true;
                listeners.clear();
                if (instances.get(boot) === api)
                    instances.delete(boot);
            },
            data: (value, areaId) => data(projection(value, d, value.selection), areaId), planning: (value, areaId) => planning(projection(value, d, value.selection), areaId),
            async request(url, body, options = {}) {
                const u = new URL(String(url), root.location.href);
                check(u.origin === root.location.origin && !u.username && !u.password && !u.hash, 'workspace_unsupported');
                const kind = Object.keys(aliases).find(k => u.pathname === aliases[k].pathname);
                check(kind && body === undefined, 'workspace_unsupported');
                for (const [k] of u.searchParams)
                    check(['tenant', 'area_id'].includes(k) && u.searchParams.getAll(k).length === 1, 'workspace_unsupported');
                check(u.searchParams.get('tenant') === logicalTenant, 'workspace_unsupported');
                check([...u.searchParams.keys()].filter(k => k === 'area_id').length === 1, 'workspace_unsupported');
                const area = u.searchParams.get('area_id');
                check(uuid.test(area));
                const value = await api.readSelection({ ...active, area_ids: [area] }, options);
                return kind === 'data' ? api.data(value, area) : api.planning(value, area);
            },
            href, navigate: value => root.location.assign(href(value)), replace: value => root.history.replaceState(null, '', href(value))
        });
        instances.set(boot, api);
        return api;
    }
    freeze(actions);
    root.EverbranchSharedWorkspace = Object.freeze({ create, canonical, digest, actions, limits });
})();
