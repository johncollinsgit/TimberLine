/* Current clean-attestation private images only. No scanner, native identity, URL proxy or persistent image bytes. */
(() => {
    'use strict';
    const root = globalThis, instances = new WeakMap(), U = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/, H = /^[a-f0-9]{64}$/;
    const plain = v => JSON.parse(JSON.stringify(v)), size = v => new TextEncoder().encode(v).length;
    const failure = (code = 'image_unavailable') => Object.assign(new Error('This private image is unavailable. Refresh current work.'), { code });
    const check = (v, code) => {
        if (!v)
            throw failure(code);
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
        check(boot && typeof boot === 'object' && workspace?.mode === 'shared' && root.EverbranchSharedWorkspace && root.EverbranchAttachmentGallery?.mountWithLoader);
        if (instances.has(boot)) {
            const old = instances.get(boot);
            check(old.workspace === workspace);
            return old.api;
        }
        const { canonical } = root.EverbranchSharedWorkspace, d = JSON.parse(canonical(boot.reviewerImages));
        exact(d, ['enabled', 'schema_version', 'workspace_id', 'browser_context', 'binding', 'expires_at', 'csrf', 'endpoints']);
        exact(d.binding, ['version_hash', 'source_hash', 'build_id']);
        check(d.enabled === true && d.schema_version === 1 && U.test(d.workspace_id) && H.test(d.browser_context) && H.test(d.binding.version_hash) && H.test(d.binding.source_hash) && canonical(d.binding) === canonical(boot.reviewerWorkspace.binding) && typeof d.csrf === 'string' && d.csrf.length > 0 && d.csrf.length <= 512 && Number.isFinite(Date.parse(d.expires_at)) && Date.now() < Date.parse(d.expires_at));
        exact(d.endpoints, ['metadata', 'thumbnail', 'preview']);
        const parent = new URL(boot.reviewerWorkspace.endpoints.projection, root.location.href);
        check(parent.origin === root.location.origin && parent.pathname.endsWith('/workspace/projection') && !parent.search && !parent.hash && !parent.username && !parent.password);
        const prefix = parent.pathname.slice(0, -'/projection'.length) + '/images/', endpoints = {};
        for (const action of ['metadata', 'thumbnail', 'preview']) {
            const u = new URL(d.endpoints[action], root.location.href);
            check(u.origin === parent.origin && u.pathname === prefix + action && !u.search && !u.hash && !u.username && !u.password);
            endpoints[action] = u.href;
        }
        let disposed = false, generation = 0, activeTarget = null, gallery = null, metadataSerial = 0;
        const controllers = new Set();
        function selection() { check(!disposed && Date.now() < Date.parse(d.expires_at), 'image_context'); const s = workspace.snapshot(); check(s.workspace_id === d.workspace_id && s.browser_context === d.browser_context, 'image_context'); return { persona: s.persona, area_ids: [...s.area_ids], person_id: s.person_id }; }
        const context = () => { const s = workspace.snapshot(); check(int(s.revision, 1) && H.test(s.workspace_digest) && ['ready', 'applied'].includes(s.phase), 'image_context'); return canonical({ selection: selection(), revision: s.revision, workspace_digest: s.workspace_digest, phase: s.phase }); };
        let observed = context();
        const same = (key, epoch, t) => {
            try {
                return !disposed && generation === epoch && context() === key && activeTarget === canonical(t);
            }
            catch {
                return false;
            }
        };
        function retire() { generation++; controllers.forEach(c => c.abort()); gallery?.destroy(); gallery = null; }
        const unsubscribe = workspace.subscribe(() => {
            try {
                const next = context();
                if (next === observed)
                    return;
                observed = next;
            }
            catch { }
            retire();
        });
        function activate(t) {
            target(t);
            const next = canonical(t);
            if (activeTarget !== null && activeTarget !== next)
                retire();
            activeTarget = next;
        }
        async function request(action, body, signal) {
            const controller = new AbortController(), abort = () => controller.abort();
            controllers.add(controller);
            if (signal) {
                if (signal.aborted)
                    controller.abort();
                else
                    signal.addEventListener('abort', abort, { once: true });
            }
            const timer = root.setTimeout(() => controller.abort(), 45000), release = () => { root.clearTimeout(timer); controllers.delete(controller); signal?.removeEventListener('abort', abort); };
            try {
                const response = await root.fetch(endpoints[action], { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: { Accept: action === 'metadata' ? 'application/json' : 'image/jpeg, image/png', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': d.csrf }, body: canonical(body) });
                if (!response.ok) {
                    const e = failure();
                    e.status = response.status;
                    throw e;
                }
                return { response, release };
            }
            catch (e) {
                release();
                throw e;
            }
        }
        async function bounded(response, cap) {
            check(response.headers?.get('Content-Encoding') === null || response.headers?.get('Content-Encoding') === 'identity');
            const expected = response.headers?.get('Content-Length');
            if (expected !== null && expected !== undefined)
                check(/^[1-9][0-9]*$/.test(expected) && Number(expected) <= cap);
            check(response.body?.getReader, 'image_protocol');
            const reader = response.body.getReader(), parts = [];
            let length = 0;
            try {
                for (;;) {
                    const p = await reader.read();
                    if (p.done)
                        break;
                    length += p.value.byteLength;
                    if (length > cap) {
                        await reader.cancel();
                        throw failure();
                    }
                    parts.push(p.value);
                }
            }
            finally {
                reader.releaseLock();
            }
            if (expected !== null && expected !== undefined)
                check(length === Number(expected));
            const out = new Uint8Array(length);
            let offset = 0;
            for (const p of parts) {
                out.set(p, offset);
                offset += p.byteLength;
            }
            return out;
        }
        function file(f) {
            exact(f, ['id', 'name', 'mime', 'size', 'original_sha256', 'state', 'preview_available', 'thumbnail', 'scan_expires_at', 'attestation_id', 'attestation_revision']);
            check(U.test(f.id) && typeof f.name === 'string' && [...f.name].length <= 120 && ['image/jpeg', 'image/png', 'application/pdf'].includes(f.mime) && int(f.size, 1) && f.size <= 5242880 && H.test(f.original_sha256) && ['held', 'ready'].includes(f.state) && typeof f.preview_available === 'boolean');
            if (f.state === 'ready') {
                check(f.preview_available === true && ['image/jpeg', 'image/png'].includes(f.mime) && U.test(f.attestation_id) && int(f.attestation_revision, 1) && Number.isFinite(Date.parse(f.scan_expires_at)) && Date.now() < Date.parse(f.scan_expires_at) && Date.parse(f.scan_expires_at) <= Date.parse(d.expires_at));
                exact(f.thumbnail, ['sha256', 'size', 'mime', 'width', 'height', 'renderer_sha256']);
                check(H.test(f.thumbnail.sha256) && H.test(f.thumbnail.renderer_sha256) && f.thumbnail.mime === f.mime && int(f.thumbnail.size, 1) && f.thumbnail.size <= 262144 && int(f.thumbnail.width, 1) && f.thumbnail.width <= 320 && int(f.thumbnail.height, 1) && f.thumbnail.height <= 240);
            }
            else
                check(f.preview_available === false && f.thumbnail === null && f.scan_expires_at === null && f.attestation_id === null && f.attestation_revision === null);
            return plain(f);
        }
        async function metadata(t) {
            activate(t);
            const key = context(), epoch = generation, query = ++metadataSerial, packet = await request('metadata', { binding: d.binding, selection: selection(), target: plain(t) });
            let out;
            try {
                const response = packet.response;
                check(response.headers.get('Content-Type')?.split(';')[0] === 'application/json');
                const bytes = await bounded(response, 65536);
                check(same(key, epoch, t) && query === metadataSerial, 'image_context');
                out = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
            }
            finally {
                packet.release();
            }
            exact(out, ['workspace_id', 'workspace_revision', 'workspace_digest', 'target', 'target_version', 'files', 'expires_at']);
            target(out.target);
            const state = workspace.snapshot();
            check(out.workspace_id === d.workspace_id && out.workspace_revision === state.revision && out.workspace_digest === state.workspace_digest && canonical(out.target) === canonical(t) && int(out.target_version, 1) && out.expires_at === d.expires_at && Array.isArray(out.files) && out.files.length <= 6);
            out.files.forEach(file);
            check(same(key, epoch, t) && query === metadataSerial, 'image_context');
            return freeze(plain(out));
        }
        async function mount({ host, dialog, target: t, current = () => true, onDenied = () => { }, onExpired = () => { } }) {
            target(t);
            const view = await metadata(t), key = context(), epoch = generation;
            check(same(key, epoch, t) && current(), 'image_context');
            gallery?.destroy();
            const allowed = new WeakMap(), files = view.files.filter(f => f.state === 'ready').map(f => { const object = freeze({ id: f.id, filename: f.name, caption: '', mime_type: f.mime, size: f.size }); allowed.set(object, f); return object; });
            const expires = Math.min(Date.parse(d.expires_at), ...view.files.filter(f => f.state === 'ready').map(f => Date.parse(f.scan_expires_at)));
            const live = () => same(key, epoch, t) && current() && Date.now() < expires;
            const loader = { eligible: f => allowed.has(f) && live() && Date.now() < Date.parse(allowed.get(f).scan_expires_at), fetch: async (f, unused, signal, kind) => {
                    check(allowed.has(f) && live() && ['thumbnail', 'preview'].includes(kind), 'image_context');
                    const v = allowed.get(f);
                    check(Date.now() < Date.parse(v.scan_expires_at), 'image_context');
                    const packet = await request(kind, { binding: d.binding, selection: selection(), target: plain(t), upload_id: v.id, attestation_id: v.attestation_id, attestation_revision: v.attestation_revision }, signal);
                    try {
                        const response = packet.response;
                        const expected = kind === 'thumbnail' ? v.thumbnail : { mime: v.mime, size: v.size, sha256: v.original_sha256 };
                        const headers = { 'X-Everbranch-Image-Workspace': d.workspace_id, 'X-Everbranch-Image-Version': d.binding.version_hash, 'X-Everbranch-Image-Upload': v.id, 'X-Everbranch-Image-Original-SHA256': v.original_sha256, 'X-Everbranch-Image-SHA256': expected.sha256, 'X-Everbranch-Image-Attestation': v.attestation_id, 'X-Everbranch-Image-Revision': String(v.attestation_revision), 'X-Everbranch-Image-Kind': kind, 'X-Everbranch-Image-Expires': v.scan_expires_at };
                        for (const [name, value] of Object.entries(headers))
                            check(response.headers.get(name) === value, 'image_identity');
                        check(response.headers.get('Content-Type')?.split(';')[0] === expected.mime && response.headers.get('Content-Length') === String(expected.size), 'image_identity');
                        const data = await bounded(response, kind === 'thumbnail' ? 262144 : 5242880);
                        check(data.byteLength === expected.size, 'image_identity');
                        const sha = [...new Uint8Array(await root.crypto.subtle.digest('SHA-256', data))].map(n => n.toString(16).padStart(2, '0')).join('');
                        check(sha === expected.sha256, 'image_identity');
                        check(live() && Date.now() < Date.parse(v.scan_expires_at), 'image_context');
                        return new Blob([data], { type: expected.mime });
                    }
                    finally {
                        packet.release();
                    }
                } };
            let retireOwned = () => { };
            const ownedGallery = root.EverbranchAttachmentGallery.mountWithLoader({ host, dialog, files, context: { mode: 'reviewer-private-images' }, current: live, onDenied: error => { retireOwned(); onDenied(error); } }, loader);
            let expiryTimer = null, ended = false;
            const owned = {
                close: () => ownedGallery.close(),
                destroy() {
                    if (ended)
                        return;
                    ended = true;
                    root.clearTimeout(expiryTimer);
                    ownedGallery.destroy();
                    if (gallery === owned)
                        gallery = null;
                }
            };
            const expire = () => {
                if (ended)
                    return;
                if (Date.now() < expires) {
                    expiryTimer = root.setTimeout(expire, Math.min(2147483647, expires - Date.now()));
                    return;
                }
                owned.destroy();
                onExpired();
            };
            retireOwned = owned.destroy;
            gallery = owned;
            if (files.length)
                expiryTimer = root.setTimeout(expire, Math.max(0, Math.min(2147483647, expires - Date.now())));
            return freeze({ files: view.files, close: owned.close, destroy: owned.destroy });
        }
        const api = Object.freeze({ metadata, mount, retire, destroy() {
                disposed = true;
                retire();
                unsubscribe();
                if (instances.get(boot)?.api === api)
                    instances.delete(boot);
            } });
        instances.set(boot, { workspace, api });
        return api;
    }
    root.EverbranchWorkspaceImages = Object.freeze({ create });
})();
