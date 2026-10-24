/* Encrypted server quarantine stage only. No preview, scan, native identity, local file-byte cache or automatic retry. */
(() => {
    'use strict';
    const root = globalThis, instances = new WeakMap(), U = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/, H = /^[a-f0-9]{64}$/;
    const plain = v => JSON.parse(JSON.stringify(v)), bytes = v => new TextEncoder().encode(v).length;
    const error = (code = 'upload_unavailable', outcome = 'not_submitted') => Object.assign(new Error('Private upload unavailable. Keep the selected file and recover the original upload.'), { code, outcome });
    const check = (v, code) => {
        if (!v)
            throw error(code);
    };
    const exact = (v, keys) => check(v && typeof v === 'object' && !Array.isArray(v) && Reflect.ownKeys(v).length === keys.length && keys.every(k => Object.hasOwn(v, k) && !Object.getOwnPropertyDescriptor(v, k)?.get && !Object.getOwnPropertyDescriptor(v, k)?.set));
    const freeze = v => {
        if (v && typeof v === 'object') {
            Object.values(v).forEach(freeze);
            Object.freeze(v);
        }
        return v;
    };
    const int = (v, min = 0) => Number.isSafeInteger(v) && v >= min;
    const target = v => { exact(v, ['kind', 'id']); check(['ticket', 'project', 'task'].includes(v.kind) && U.test(v.id)); return plain(v); };
    function create(boot, workspace) {
        check(boot && typeof boot === 'object' && workspace?.mode === 'shared' && root.EverbranchSharedWorkspace);
        if (instances.has(boot)) {
            const old = instances.get(boot);
            check(old.workspace === workspace);
            return old.api;
        }
        const { canonical, digest } = root.EverbranchSharedWorkspace;
        const d = JSON.parse(canonical(boot.reviewerAttachments));
        exact(d, ['enabled', 'schema_version', 'workspace_id', 'browser_context', 'binding', 'expires_at', 'csrf', 'endpoints']);
        check(d.enabled === true && d.schema_version === 1 && U.test(d.workspace_id) && H.test(d.browser_context) && typeof d.csrf === 'string' && d.csrf.length > 0 && d.csrf.length <= 512);
        exact(d.binding, ['version_hash', 'source_hash', 'build_id']);
        check(H.test(d.binding.version_hash) && H.test(d.binding.source_hash) && canonical(d.binding) === canonical(boot.reviewerWorkspace.binding));
        check(Number.isFinite(Date.parse(d.expires_at)) && Date.now() < Date.parse(d.expires_at));
        const actions = ['show', 'begin', 'status', 'chunk', 'complete', 'cancel'];
        exact(d.endpoints, actions);
        const parent = new URL(boot.reviewerWorkspace.endpoints.projection, root.location.href);
        check(parent.origin === root.location.origin && parent.pathname.endsWith('/workspace/projection') && !parent.search && !parent.hash && !parent.username && !parent.password);
        const prefix = parent.pathname.slice(0, -'/projection'.length) + '/files/', endpoints = {};
        for (const action of actions) {
            const u = new URL(d.endpoints[action], root.location.href);
            check(u.origin === parent.origin && u.pathname === prefix + action && !u.search && !u.hash && !u.username && !u.password);
            endpoints[action] = u.href;
        }
        let disposed = false, generation = 0, busy = false, serial = 0, warning = false, activeTarget = null;
        const controllers = new Set(), selectedFiles = new Map();
        const storage = root.sessionStorage, key = 'everbranch-work-upload:' + d.workspace_id + ':' + d.binding.version_hash + ':' + d.browser_context;
        let stored = null, state = { schema_version: 1, pending: null };
        function command(c) {
            exact(c, ['binding', 'selection', 'target', 'request_id', 'expected_target_version', 'expected_reviewer_name', 'name', 'mime', 'size', 'sha256']);
            target(c.target);
            check(canonical(c.binding) === canonical(d.binding) && U.test(c.request_id) && int(c.expected_target_version, 1) && typeof c.expected_reviewer_name === 'string' && [...c.expected_reviewer_name].length > 0 && [...c.expected_reviewer_name].length <= 80);
            check(typeof c.name === 'string' && c.name.trim() !== '' && [...c.name].length <= 120 && !/[\x00-\x1f\x7f/\\]/.test(c.name) && int(c.size, 1) && c.size <= 5242880 && H.test(c.sha256));
            check((c.mime === 'image/png' && /\.png$/i.test(c.name)) || (c.mime === 'image/jpeg' && /\.jpe?g$/i.test(c.name)) || (c.mime === 'application/pdf' && /\.pdf$/i.test(c.name)));
            check(bytes(canonical(c)) <= 16384);
            return plain(c);
        }
        function validate(s) {
            exact(s, ['schema_version', 'pending']);
            check(s.schema_version === 1);
            if (s.pending !== null) {
                exact(s.pending, ['command', 'digest', 'upload_id']);
                command(s.pending.command);
                check(H.test(s.pending.digest) && (s.pending.upload_id === null || U.test(s.pending.upload_id)));
            }
        }
        try {
            check(storage && typeof storage.getItem === 'function' && typeof storage.setItem === 'function');
            stored = storage.getItem(key);
            if (stored !== null) {
                check(typeof stored === 'string' && bytes(stored) <= 32768);
                state = JSON.parse(stored);
                validate(state);
            }
        }
        catch {
            throw error('upload_storage');
        }
        function persist(next) {
            validate(next);
            const value = canonical(next);
            check(bytes(value) <= 32768, 'upload_storage');
            try {
                check(storage.getItem(key) === stored, 'upload_storage');
                check(storage.setItem(key, value) !== false && storage.getItem(key) === value, 'upload_storage');
            }
            catch {
                throw error('upload_storage');
            }
            stored = value;
            state = plain(next);
        }
        function current() {
            check(!disposed && Date.now() < Date.parse(d.expires_at), 'upload_context');
            const s = workspace.snapshot();
            check(s.workspace_id === d.workspace_id && s.browser_context === d.browser_context, 'upload_context');
            return { persona: s.persona, area_ids: [...s.area_ids], person_id: s.person_id };
        }
        const context = () => canonical(current());
        const same = (key, epoch) => {
            try {
                return !disposed && generation === epoch && context() === key;
            }
            catch {
                return false;
            }
        };
        let observed = context();
        check(typeof workspace.subscribe === 'function');
        const unsubscribe = workspace.subscribe(() => {
            try {
                const next = context();
                if (next === observed)
                    return;
                observed = next;
            }
            catch { }
            generation++;
            controllers.forEach(c => c.abort());
            selectedFiles.clear();
        });
        const onTarget = p => activeTarget === canonical(p.command.target);
        const own = p => state.pending?.command.request_id === p.command.request_id && state.pending.digest === p.digest;
        async function prepared(p) { exact(p, ['workspace_id', 'browser_context', 'command', 'digest']); const out = plain(p); check(out.workspace_id === d.workspace_id && out.browser_context === d.browser_context && H.test(out.digest)); command(out.command); check(await digest(out.command) === out.digest); return freeze(out); }
        async function post(action, body) {
            const abort = new AbortController();
            controllers.add(abort);
            try {
                const response = await root.fetch(endpoints[action], { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': d.csrf }, body: canonical(body) });
                check(response.ok, 'upload_unknown');
                let value;
                if (response.body?.getReader) {
                    const reader = response.body.getReader(), chunks = [];
                    let size = 0;
                    try {
                        for (;;) {
                            const part = await reader.read();
                            if (part.done)
                                break;
                            size += part.value.byteLength;
                            if (size > 131072) {
                                await reader.cancel();
                                throw 0;
                            }
                            chunks.push(part.value);
                        }
                    }
                    finally {
                        reader.releaseLock();
                    }
                    const all = new Uint8Array(size);
                    let offset = 0;
                    for (const p of chunks) {
                        all.set(p, offset);
                        offset += p.byteLength;
                    }
                    value = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(all));
                }
                else if (typeof response.text === 'function') {
                    const raw = await response.text();
                    check(bytes(raw) <= 131072);
                    value = JSON.parse(raw);
                }
                else {
                    value = await response.json();
                    check(bytes(canonical(value)) <= 131072);
                }
                return value;
            }
            catch {
                throw error('upload_unknown', 'unknown');
            }
            finally {
                controllers.delete(abort);
            }
        }
        function fileView(f, p = null) {
            exact(f, ['id', 'name', 'mime', 'size', 'sha256', 'status', 'revision', 'target', 'mine', 'reservation', 'received', 'chunk_bytes', 'busy', 'preview_available', 'download_available', 'scan_status']);
            check(U.test(f.id) && typeof f.name === 'string' && [...f.name].length <= 120 && ['image/png', 'image/jpeg', 'application/pdf'].includes(f.mime) && int(f.size, 1) && f.size <= 5242880 && H.test(f.sha256) && int(f.revision, 1));
            target(f.target);
            check(['uploading', 'assembling', 'quarantined', 'cancelled', 'expired', 'cleanup_pending'].includes(f.status) && typeof f.mine === 'boolean' && typeof f.busy === 'boolean' && f.chunk_bytes === 262144 && f.preview_available === false && f.download_available === false && f.scan_status === 'not_admitted');
            check(Array.isArray(f.received) && f.received.length <= 20);
            const indexes = new Set();
            for (const c of f.received) {
                exact(c, ['index', 'size', 'sha256']);
                check(int(c.index) && c.index < Math.ceil(f.size / 262144) && !indexes.has(c.index) && c.size === Math.min(262144, f.size - c.index * 262144) && H.test(c.sha256));
                indexes.add(c.index);
            }
            if (f.reservation !== null) {
                exact(f.reservation, ['request_id', 'digest']);
                check(f.mine && U.test(f.reservation.request_id) && H.test(f.reservation.digest));
            }
            if (p) {
                check(f.mine && f.reservation?.request_id === p.command.request_id && f.reservation.digest === p.digest && canonical(f.target) === canonical(p.command.target) && f.mime === p.command.mime && f.size === p.command.size && f.sha256 === p.command.sha256 && (f.name === p.command.name || ['cancelled', 'expired'].includes(f.status)));
                check(state.pending?.upload_id === null || state.pending?.upload_id === f.id);
            }
            return plain(f);
        }
        function rememberFile(f, p) {
            fileView(f, p);
            const next = plain(state);
            check(own(p));
            next.pending.upload_id = f.id;
            try {
                persist(next);
            }
            catch {
                state = next;
                warning = true;
            }
            return f;
        }
        function receipt(f, p) { return { upload_id: f.id, request_id: p.command.request_id, digest: p.digest }; }
        function retire(f, p) {
            check(own(p));
            try {
                persist({ schema_version: 1, pending: null });
            }
            catch {
                state.pending = null;
                warning = true;
            }
            selectedFiles.delete(p.command.request_id);
            return receipt(f, p);
        }
        async function select(p, file) {
            check(file && typeof file.arrayBuffer === 'function' && file.size === p.command.size && file.type === p.command.mime && file.name === p.command.name && file.size <= 5242880, 'upload_file');
            const all = new Uint8Array(await file.arrayBuffer());
            check(all.byteLength === file.size && [...new Uint8Array(await root.crypto.subtle.digest('SHA-256', all))].map(n => n.toString(16).padStart(2, '0')).join('') === p.command.sha256, 'upload_file');
            return all;
        }
        async function withBusy(operation) {
            check(!busy, 'upload_context');
            busy = true;
            let dispatched = false;
            const send = (action, body) => {
                dispatched = true;
                return post(action, body);
            };
            try {
                return await operation(send);
            }
            catch (failure) {
                if (dispatched)
                    throw error(['upload_context', 'upload_file', 'upload_storage', 'upload_unavailable', 'upload_unknown'].includes(failure?.code) ? failure.code : 'upload_unknown', 'unknown');
                throw failure;
            }
            finally {
                busy = false;
            }
        }
        const api = {
            async show(t) {
                target(t);
                const nextTarget = canonical(t);
                if (activeTarget !== null && activeTarget !== nextTarget) {
                    generation++;
                    controllers.forEach(c => c.abort());
                    selectedFiles.clear();
                }
                activeTarget = nextTarget;
                const key = context(), epoch = generation, request = ++serial;
                const out = await post('show', { binding: d.binding, selection: current(), target: plain(t) });
                check(same(key, epoch) && request === serial, 'upload_context');
                exact(out, ['workspace_id', 'target', 'target_version', 'can_upload', 'files', 'expires_at', 'preview_available', 'scanner_available']);
                check(out.workspace_id === d.workspace_id && canonical(out.target) === canonical(t) && int(out.target_version, 1) && typeof out.can_upload === 'boolean' && out.expires_at === d.expires_at && out.preview_available === false && out.scanner_available === false && Array.isArray(out.files) && out.files.length <= 6);
                out.files.forEach(f => { fileView(f); check(canonical(f.target) === canonical(out.target)); });
                return freeze(plain(out));
            },
            async prepare(view, file, options) {
                check(!busy && !state.pending && view.workspace_id === d.workspace_id && view.can_upload === true && canonical(view.target) === activeTarget);
                target(view.target);
                exact(options, ['request_id', 'expected_reviewer_name']);
                const key = context(), epoch = generation;
                check(file && int(file.size, 1) && file.size <= 5242880 && typeof file.arrayBuffer === 'function');
                const all = new Uint8Array(await file.arrayBuffer());
                check(all.byteLength === file.size);
                const sha = [...new Uint8Array(await root.crypto.subtle.digest('SHA-256', all))].map(n => n.toString(16).padStart(2, '0')).join('');
                const c = command({ binding: d.binding, selection: current(), target: plain(view.target), request_id: options.request_id, expected_target_version: view.target_version, expected_reviewer_name: options.expected_reviewer_name, name: file.name, mime: file.type, size: file.size, sha256: sha });
                const p = { workspace_id: d.workspace_id, browser_context: d.browser_context, command: c, digest: await digest(c) };
                check(same(key, epoch), 'upload_context');
                selectedFiles.set(c.request_id, file);
                return freeze(p);
            },
            async begin(input) {
                return withBusy(async send => {
                    const key = context(), epoch = generation, p = await prepared(input);
                    check(same(key, epoch) && onTarget(p) && canonical(current()) === canonical(p.command.selection) && (!state.pending || own(p)), 'upload_context');
                    if (!state.pending)
                        persist({ schema_version: 1, pending: { command: plain(p.command), digest: p.digest, upload_id: null } });
                    const out = await send('begin', p.command);
                    exact(out, ['status', 'replayed', 'file']);
                    check(out.status === 'reserved' && typeof out.replayed === 'boolean');
                    rememberFile(out.file, p);
                    return freeze({ status: 'reserved', receipt: receipt(out.file, p), ...(!same(key, epoch) ? { stale_context: true } : {}), ...(warning ? { storage_warning: true } : {}) });
                });
            },
            async recover() {
                return withBusy(async send => {
                    const key = context(), epoch = generation;
                    check(state.pending, 'upload_context');
                    const p = await prepared({ workspace_id: d.workspace_id, browser_context: d.browser_context, command: state.pending.command, digest: state.pending.digest });
                    check(same(key, epoch) && onTarget(p) && canonical(current()) === canonical(p.command.selection));
                    const out = await send('status', { binding: d.binding, selection: current(), target: p.command.target, request_id: p.command.request_id, digest: p.digest });
                    if (out.status === 'not_observed') {
                        exact(out, ['status', 'settled']);
                        check(out.settled === false);
                        return freeze({ status: 'not_observed', settled: false, ...(!same(key, epoch) ? { stale_context: true } : {}) });
                    }
                    exact(out, ['status', 'settled', 'file']);
                    check(out.status === 'reserved' && out.settled === true);
                    rememberFile(out.file, p);
                    const final = ['quarantined', 'cancelled', 'expired'].includes(out.file.status);
                    const r = final ? retire(out.file, p) : receipt(out.file, p);
                    return freeze({ status: out.file.status, settled: final, receipt: r, ...(!same(key, epoch) ? { stale_context: true } : { file: plain(out.file) }), ...(warning ? { storage_warning: true } : {}) });
                });
            },
            async resume(input, file = null) {
                return withBusy(async send => {
                    const key = context(), epoch = generation, p = await prepared(input);
                    check(own(p) && onTarget(p) && U.test(state.pending.upload_id) && canonical(current()) === canonical(p.command.selection), 'upload_context');
                    const all = await select(p, file ?? selectedFiles.get(p.command.request_id));
                    check(same(key, epoch), 'upload_context');
                    // Explicit resume obtains current durable chunk state; it never creates a replacement reservation.
                    const status = await send('status', { binding: d.binding, selection: current(), target: p.command.target, request_id: p.command.request_id, digest: p.digest });
                    exact(status, ['status', 'settled', 'file']);
                    check(status.status === 'reserved' && status.settled === true);
                    rememberFile(status.file, p);
                    check(same(key, epoch), 'upload_context');
                    if (['quarantined', 'cancelled', 'expired'].includes(status.file.status)) {
                        const r = retire(status.file, p);
                        return freeze({ status: status.file.status, receipt: r, file: plain(status.file), ...(warning ? { storage_warning: true } : {}) });
                    }
                    check(['uploading', 'assembling'].includes(status.file.status) && !status.file.busy, 'upload_context');
                    for (let i = 0; i < Math.ceil(all.length / 262144); i++) {
                        const part = all.slice(i * 262144, (i + 1) * 262144), sha = [...new Uint8Array(await root.crypto.subtle.digest('SHA-256', part))].map(n => n.toString(16).padStart(2, '0')).join('');
                        const done = status.file.received.find(c => c.index === i);
                        if (done) {
                            check(done.sha256 === sha);
                            continue;
                        }
                        check(same(key, epoch), 'upload_context');
                        let binary = '';
                        for (const b of part)
                            binary += String.fromCharCode(b);
                        const out = await send('chunk', { binding: d.binding, selection: current(), target: p.command.target, upload_id: state.pending.upload_id, index: i, sha256: sha, bytes: root.btoa(binary) });
                        exact(out, ['status', 'file']);
                        check(out.status === 'uploading');
                        rememberFile(out.file, p);
                        check(same(key, epoch), 'upload_context');
                    }
                    check(same(key, epoch), 'upload_context');
                    const out = await send('complete', { binding: d.binding, selection: current(), target: p.command.target, upload_id: state.pending.upload_id });
                    exact(out, ['status', 'file']);
                    check(out.status === 'quarantined');
                    rememberFile(out.file, p);
                    const r = retire(out.file, p);
                    return freeze({ status: 'quarantined', receipt: r, ...(!same(key, epoch) ? { stale_context: true } : { file: plain(out.file) }), ...(warning ? { storage_warning: true } : {}) });
                });
            },
            async cancel() {
                return withBusy(async send => {
                    const key = context(), epoch = generation;
                    check(state.pending && U.test(state.pending.upload_id));
                    const p = await prepared({ workspace_id: d.workspace_id, browser_context: d.browser_context, command: state.pending.command, digest: state.pending.digest });
                    check(same(key, epoch) && onTarget(p) && canonical(current()) === canonical(p.command.selection));
                    const out = await send('cancel', { binding: d.binding, selection: current(), target: p.command.target, upload_id: state.pending.upload_id });
                    exact(out, ['status', 'file']);
                    check(['cancelled', 'expired'].includes(out.status));
                    rememberFile(out.file, p);
                    const r = retire(out.file, p);
                    return freeze({ status: out.status, receipt: r, ...(!same(key, epoch) ? { stale_context: true } : {}), ...(warning ? { storage_warning: true } : {}) });
                });
            },
            pending: () => state.pending ? freeze({ workspace_id: d.workspace_id, browser_context: d.browser_context, command: plain(state.pending.command), digest: state.pending.digest }) : null,
            destroy() {
                disposed = true;
                generation++;
                controllers.forEach(c => c.abort());
                selectedFiles.clear();
                unsubscribe();
                if (instances.get(boot)?.api === api)
                    instances.delete(boot);
            }
        };
        Object.freeze(api);
        instances.set(boot, { api, workspace });
        return api;
    }
    root.EverbranchWorkspaceUploads = Object.freeze({ create });
})();
