/* Persistent shared fictional comments only. No native proxy, files, provider or local saved-history fallback. */
(() => {
    'use strict';
    const root = globalThis, instances = new WeakMap(), uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/, hash = /^[a-f0-9]{64}$/;
    // A bounded256KiB stored thread can expand sixfold in PHP JSON escaping.
    const responseBytes = 2 * 1024 * 1024;
    const plain = v => JSON.parse(JSON.stringify(v)), object = v => v !== null && typeof v === 'object' && !Array.isArray(v);
    const fail = (code = 'discussion_unavailable', outcome = 'not_submitted') => Object.assign(new Error('Private work comments are unavailable. Keep your draft and recover the original request.'), { code, outcome });
    const check = (v, code) => {
        if (!v)
            throw fail(code);
    };
    const exact = (v, keys) => check(object(v) && Reflect.ownKeys(v).length === keys.length && keys.every(k => Object.hasOwn(v, k) && Object.getOwnPropertyDescriptor(v, k)?.get === undefined && Object.getOwnPropertyDescriptor(v, k)?.set === undefined));
    const frozen = v => {
        if (v && typeof v === 'object') {
            Object.values(v).forEach(frozen);
            Object.freeze(v);
        }
        return v;
    };
    const integer = (v, min = 0) => Number.isSafeInteger(v) && v >= min;
    const target = v => { exact(v, ['kind', 'id']); check(['ticket', 'task', 'project'].includes(v.kind) && uuid.test(v.id)); return plain(v); };
    function create(boot, workspace) {
        check(object(boot));
        if (instances.has(boot)) {
            const old = instances.get(boot);
            check(old.workspace === workspace, 'discussion_context');
            return old.api;
        }
        check(workspace?.mode === 'shared' && root.EverbranchSharedWorkspace, 'discussion_access');
        const canonical = root.EverbranchSharedWorkspace.canonical, digest = root.EverbranchSharedWorkspace.digest;
        const d = JSON.parse(canonical(boot.reviewerCollaboration));
        exact(d, ['enabled', 'schema_version', 'workspace_id', 'browser_context', 'binding', 'expires_at', 'csrf', 'endpoints']);
        check(d.enabled === true && d.schema_version === 1 && uuid.test(d.workspace_id) && hash.test(d.browser_context) && typeof d.csrf === 'string' && d.csrf.length > 0 && d.csrf.length <= 512);
        exact(d.binding, ['version_hash', 'source_hash', 'build_id']);
        check(hash.test(d.binding.version_hash) && hash.test(d.binding.source_hash) && typeof d.binding.build_id === 'string' && canonical(d.binding) === canonical(boot.reviewerWorkspace.binding));
        check(Number.isFinite(Date.parse(d.expires_at)) && Date.parse(d.expires_at) > Date.now());
        exact(d.endpoints, ['show', 'comment', 'status']);
        const parent = new URL(boot.reviewerWorkspace.endpoints.projection, root.location.href);
        check(parent.origin === root.location.origin && parent.pathname.endsWith('/workspace/projection') && !parent.search && !parent.hash && !parent.username && !parent.password);
        const base = parent.pathname.slice(0, -'/projection'.length) + '/collaboration/', endpoints = {};
        for (const action of ['show', 'comment', 'status']) {
            const u = new URL(d.endpoints[action], root.location.href);
            check(u.origin === parent.origin && u.pathname === base + action && !u.search && !u.hash && !u.username && !u.password, 'discussion_access');
            endpoints[action] = u.href;
        }
        const key = 'everbranch-work-comments:' + d.workspace_id + ':' + d.binding.version_hash + ':' + d.browser_context, store = root.sessionStorage;
        check(store && typeof store.getItem === 'function' && typeof store.setItem === 'function', 'discussion_storage');
        let disposed = false, inflight = false, stored = null, generation = 0, warning = false, state = { schema_version: 1, pending: null, draft: null, tombstones: [] }, rejected = null;
        const bytes = v => new TextEncoder().encode(v).length;
        function command(v) { exact(v, ['binding', 'selection', 'target', 'request_id', 'expected_target_version', 'expected_thread_revision', 'expected_reviewer_name', 'body']); check(canonical(v.binding) === canonical(d.binding) && uuid.test(v.request_id) && integer(v.expected_target_version, 1) && integer(v.expected_thread_revision)); target(v.target); check(object(v.selection) && typeof v.expected_reviewer_name === 'string' && [...v.expected_reviewer_name].length > 0 && [...v.expected_reviewer_name].length <= 80 && typeof v.body === 'string' && v.body.trim() !== '' && [...v.body].length <= 4000 && bytes(v.body) <= 16384 && bytes(canonical(v)) <= 32768); return plain(v); }
        function validate(s) {
            exact(s, ['schema_version', 'pending', 'draft', 'tombstones']);
            check(s.schema_version === 1 && Array.isArray(s.tombstones) && s.tombstones.length <= 64);
            if (s.pending !== null) {
                exact(s.pending, ['command', 'digest', 'unknown']);
                command(s.pending.command);
                check(hash.test(s.pending.digest) && typeof s.pending.unknown === 'boolean');
            }
            if (s.draft !== null)
                command(s.draft);
            for (const t of s.tombstones) {
                exact(t, ['request_id', 'digest']);
                check(uuid.test(t.request_id) && hash.test(t.digest));
            }
        }
        try {
            stored = store.getItem(key);
            if (stored !== null) {
                check(typeof stored === 'string' && bytes(stored) <= 65536);
                state = JSON.parse(stored);
                validate(state);
                if (state.pending)
                    state.pending.unknown = true;
            }
        }
        catch {
            throw fail('discussion_storage');
        }
        function persist(next) {
            validate(next);
            const value = canonical(next);
            check(bytes(value) <= 65536, 'discussion_storage');
            try {
                check(store.getItem(key) === stored, 'discussion_storage');
                const out = store.setItem(key, value);
                check(out !== false && store.getItem(key) === value, 'discussion_storage');
            }
            catch {
                throw fail('discussion_storage');
            }
            stored = value;
            state = plain(next);
        }
        function current() { check(!disposed && Date.now() < Date.parse(d.expires_at), 'discussion_context'); const s = workspace.snapshot(); check(s.workspace_id === d.workspace_id && s.browser_context === d.browser_context, 'discussion_context'); return { persona: s.persona, area_ids: [...s.area_ids], person_id: s.person_id }; }
        const contextKey = () => canonical(current()), controllers = new Set();
        const sameContext = key => {
            try {
                return key === contextKey();
            }
            catch {
                return false;
            }
        };
        check(typeof workspace.subscribe === 'function', 'discussion_access');
        let observed = contextKey();
        const unsubscribe = workspace.subscribe(() => {
            try {
                const next = contextKey();
                if (next !== observed) {
                    generation++;
                    observed = next;
                    controllers.forEach(c => c.abort());
                }
            }
            catch {
                generation++;
                controllers.forEach(c => c.abort());
            }
        });
        const owned = p => state.pending?.command.request_id === p.command.request_id && state.pending.digest === p.digest;
        async function prepared(p) { exact(p, ['workspace_id', 'browser_context', 'command', 'digest']); check(p.workspace_id === d.workspace_id && p.browser_context === d.browser_context && hash.test(p.digest)); const out = plain(p); command(out.command); check(await digest(out.command) === out.digest); return frozen(out); }
        async function post(action, body) {
            const controller = new AbortController();
            controllers.add(controller);
            try {
                const response = await root.fetch(endpoints[action], { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': d.csrf }, body: canonical(body) });
                let value;
                if (response.body?.getReader) {
                    const reader = response.body.getReader(), chunks = [];
                    let length = 0;
                    try {
                        for (;;) {
                            const part = await reader.read();
                            if (part.done)
                                break;
                            length += part.value.byteLength;
                            if (length > responseBytes) {
                                await reader.cancel();
                                throw 0;
                            }
                            chunks.push(part.value);
                        }
                    }
                    finally {
                        reader.releaseLock();
                    }
                    const raw = new Uint8Array(length);
                    let offset = 0;
                    for (const chunk of chunks) {
                        raw.set(chunk, offset);
                        offset += chunk.byteLength;
                    }
                    value = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(raw));
                }
                else if (typeof response.text === 'function') {
                    const raw = await response.text();
                    check(bytes(raw) <= responseBytes);
                    value = JSON.parse(raw);
                }
                else {
                    value = await response.json();
                    check(bytes(canonical(value)) <= responseBytes);
                }
                return { ok: response.ok, status: response.status, value };
            }
            catch {
                throw fail('discussion_unknown', 'unknown');
            }
            finally {
                controllers.delete(controller);
            }
        }
        function receipt(r, p) { exact(r, ['request_id', 'digest', 'comment_id', 'thread_revision']); check(r.request_id === p.command.request_id && r.digest === p.digest && uuid.test(r.comment_id) && integer(r.thread_revision, p.command.expected_thread_revision + 1)); return plain(r); }
        function settle(r, p) {
            const verified = receipt(r, p);
            check(owned(p), 'discussion_context');
            const next = plain(state);
            next.pending = null;
            if (next.draft && canonical(next.draft) === canonical(p.command))
                next.draft = null;
            try {
                persist(next);
            }
            catch {
                state = next;
                warning = true;
            }
            rejected = null;
            return verified;
        }
        async function execute(input, retry) {
            const before = contextKey(), epoch = generation, p = await prepared(input);
            check(epoch === generation && before === contextKey() && !inflight, 'discussion_context');
            check(canonical(p.command.selection) === canonical(current()), 'discussion_context');
            if (retry)
                check(owned(p), 'discussion_context');
            else {
                check(state.pending === null && !state.tombstones.some(t => t.request_id === p.command.request_id), 'discussion_context');
                const next = plain(state);
                next.pending = { command: p.command, digest: p.digest, unknown: false };
                next.draft = p.command;
                persist(next);
            }
            const wasUnknown = state.pending.unknown;
            inflight = true;
            rejected = null;
            try {
                const out = await post('comment', p.command);
                if (!out.ok) {
                    const v = out.value;
                    if (!wasUnknown && owned(p) && out.status === 409 && v?.error === 'workspace_conflict' && v.automatic_retry === false && v.definitive_not_applied === true && v.request_id === p.command.request_id && v.digest === p.digest && v.workspace_id === d.workspace_id && Object.keys(v).length === 6) {
                        rejected = p;
                        throw fail('discussion_conflict', 'rejected');
                    }
                    throw fail('discussion_unknown', 'unknown');
                }
                exact(out.value, ['status', 'replayed', 'receipt']);
                check(out.value.status === 'applied' && typeof out.value.replayed === 'boolean');
                const r = settle(out.value.receipt, p);
                return frozen({ status: 'applied', receipt: r, ...(!sameContext(before) || epoch !== generation ? { stale_context: true } : {}), ...(warning ? { storage_warning: true } : {}) });
            }
            catch (e) {
                if (e.outcome === 'rejected')
                    throw e;
                if (owned(p)) {
                    state.pending.unknown = true;
                    try {
                        persist(state);
                    }
                    catch { }
                }
                throw fail('discussion_unknown', 'unknown');
            }
            finally {
                inflight = false;
            }
        }
        const api = { mode: 'shared-comments',
            async show(value, before = null) {
                const selected = current(), key = contextKey(), epoch = generation, t = target(value);
                check(before === null || uuid.test(before));
                const out = await post('show', { binding: d.binding, selection: selected, target: t, before });
                check(epoch === generation && key === contextKey(), 'discussion_context');
                if (!out.ok)
                    throw fail('discussion_access', 'unknown');
                const v = out.value;
                exact(v, ['workspace_id', 'target', 'target_version', 'thread_revision', 'can_comment', 'comments', 'before', 'files_available', 'expires_at']);
                check(v.workspace_id === d.workspace_id && canonical(v.target) === canonical(t) && integer(v.target_version, 1) && integer(v.thread_revision) && typeof v.can_comment === 'boolean' && v.files_available === false && Array.isArray(v.comments) && v.comments.length <= 25 && (v.before === null || uuid.test(v.before)) && Date.parse(v.expires_at) > Date.now() && Date.parse(v.expires_at) <= Date.parse(d.expires_at));
                for (const c of v.comments) {
                    exact(c, ['id', 'body', 'name', 'identity_status', 'created_at', 'mine']);
                    check(uuid.test(c.id) && typeof c.body === 'string' && [...c.body].length <= 4000 && bytes(c.body) <= 16384 && typeof c.name === 'string' && [...c.name].length <= 80 && c.identity_status === 'self_selected_display_name' && typeof c.mine === 'boolean' && Number.isFinite(Date.parse(c.created_at)));
                }
                return frozen({ ...plain(v), selection: selected });
            },
            async prepare(view, input) { check(view.workspace_id === d.workspace_id && view.can_comment === true, 'discussion_access'); const captured = plain(input); exact(captured, ['request_id', 'expected_reviewer_name', 'body']); const c = command({ binding: d.binding, selection: view.selection, target: view.target, request_id: captured.request_id, expected_target_version: view.target_version, expected_thread_revision: view.thread_revision, expected_reviewer_name: captured.expected_reviewer_name, body: captured.body }); return frozen({ workspace_id: d.workspace_id, browser_context: d.browser_context, command: c, digest: await digest(c) }); },
            send: p => execute(p, false), retry: p => execute(p, true),
            async recover() {
                const key = contextKey(), epoch = generation;
                check(state.pending && !inflight, 'discussion_context');
                const p = await prepared({ workspace_id: d.workspace_id, browser_context: d.browser_context, command: state.pending.command, digest: state.pending.digest });
                check(epoch === generation && key === contextKey() && owned(p) && !inflight, 'discussion_context');
                inflight = true;
                state.pending.unknown = true;
                rejected = null;
                try {
                    const out = await post('status', { binding: d.binding, selection: current(), target: p.command.target, request_id: p.command.request_id, digest: p.digest });
                    if (!out.ok)
                        throw fail('discussion_unknown', 'unknown');
                    if (out.value?.status === 'not_observed') {
                        exact(out.value, ['status', 'settled']);
                        check(out.value.settled === false);
                        return frozen({ status: 'not_observed', settled: false, ...(!sameContext(key) || epoch !== generation ? { stale_context: true } : {}) });
                    }
                    exact(out.value, ['status', 'settled', 'receipt']);
                    check(out.value.status === 'applied' && out.value.settled === true);
                    const r = settle(out.value.receipt, p);
                    return frozen({ status: 'applied', settled: true, receipt: r, ...(!sameContext(key) || epoch !== generation ? { stale_context: true } : {}), ...(warning ? { storage_warning: true } : {}) });
                }
                catch {
                    throw fail('discussion_unknown', 'unknown');
                }
                finally {
                    inflight = false;
                }
            },
            acknowledgeRejection(p) { check(canonical(current()) === canonical(p.command.selection), 'discussion_context'); check(rejected && owned(rejected) && p.command.request_id === rejected.command.request_id && p.digest === rejected.digest && !state.pending.unknown && state.tombstones.length < 64, 'discussion_context'); const next = plain(state); next.tombstones.push({ request_id: p.command.request_id, digest: p.digest }); next.pending = null; persist(next); rejected = null; return frozen(plain(state.draft)); },
            pending: () => state.pending ? frozen({ workspace_id: d.workspace_id, browser_context: d.browser_context, command: plain(state.pending.command), digest: state.pending.digest }) : null,
            draft: () => state.draft ? frozen(plain(state.draft)) : null, destroy() {
                disposed = true;
                generation++;
                controllers.forEach(c => c.abort());
                unsubscribe();
                rejected = null;
                if (instances.get(boot)?.api === api)
                    instances.delete(boot);
            }
        };
        Object.freeze(api);
        instances.set(boot, { api, workspace });
        return api;
    }
    root.EverbranchWorkspaceComments = Object.freeze({ create });
})();
