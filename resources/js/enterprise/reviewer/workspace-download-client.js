/* Explicit private original PDF downloads. No scan, native actor, fallback URL or persistent browser bytes. */
(() => {
    'use strict';
    const root = globalThis, instances = new WeakMap();
    const U = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/, H = /^[a-f0-9]{64}$/;
    const plain = value => JSON.parse(JSON.stringify(value));
    const failure = (code = 'download_unavailable', status = 0) => Object.assign(new Error('This private attachment is unavailable. Refresh current files.'), {code, status});
    const check = (value, code) => { if (!value) throw failure(code); };
    const exact = (value, keys) => check(value && typeof value === 'object' && !Array.isArray(value) && Reflect.ownKeys(value).length === keys.length && keys.every(key => Object.hasOwn(value, key) && !Object.getOwnPropertyDescriptor(value, key)?.get && !Object.getOwnPropertyDescriptor(value, key)?.set));
    const int = (value, min = 0) => Number.isSafeInteger(value) && value >= min;
    const freeze = value => { if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); } return value; };
    const target = value => { exact(value, ['kind', 'id']); check(['ticket', 'project', 'task'].includes(value.kind) && U.test(value.id)); return plain(value); };

    function create(boot, workspace) {
        check(boot && typeof boot === 'object' && workspace?.mode === 'shared' && root.EverbranchSharedWorkspace);
        if (instances.has(boot)) { const old = instances.get(boot); check(old.workspace === workspace); return old.api; }
        const {canonical} = root.EverbranchSharedWorkspace, d = JSON.parse(canonical(boot.reviewerDownloads));
        exact(d, ['enabled', 'schema_version', 'workspace_id', 'browser_context', 'binding', 'expires_at', 'csrf', 'endpoints']);
        exact(d.binding, ['version_hash', 'source_hash', 'build_id']);
        check(d.enabled === true && d.schema_version === 1 && U.test(d.workspace_id) && H.test(d.browser_context) && H.test(d.binding.version_hash) && H.test(d.binding.source_hash)
            && canonical(d.binding) === canonical(boot.reviewerWorkspace.binding) && typeof d.csrf === 'string' && d.csrf.length > 0 && d.csrf.length <= 512
            && Number.isFinite(Date.parse(d.expires_at)) && Date.now() < Date.parse(d.expires_at));
        exact(d.endpoints, ['metadata', 'download']);
        const parent = new URL(boot.reviewerWorkspace.endpoints.projection, root.location.href), endpoints = {};
        check(parent.origin === root.location.origin && parent.pathname.endsWith('/workspace/projection') && !parent.search && !parent.hash && !parent.username && !parent.password);
        for (const action of ['metadata', 'download']) {
            const u = new URL(d.endpoints[action], root.location.href);
            check(u.origin === parent.origin && u.pathname === parent.pathname.slice(0, -'/projection'.length) + '/downloads/' + action && !u.search && !u.hash && !u.username && !u.password);
            endpoints[action] = u.href;
        }
        let disposed = false, generation = 0, serial = 0, activeTarget = null, allowed = new WeakMap();
        const controllers = new Set(), transient = new Set(), listeners = [];
        function selection() {
            check(!disposed && Date.now() < Date.parse(d.expires_at), 'download_context');
            const s = workspace.snapshot();
            check(s.workspace_id === d.workspace_id && s.browser_context === d.browser_context, 'download_context');
            return {persona: s.persona, area_ids: [...s.area_ids], person_id: s.person_id};
        }
        function context() {
            const s = workspace.snapshot();
            check(int(s.revision, 1) && H.test(s.workspace_digest) && ['ready', 'applied'].includes(s.phase), 'download_context');
            return canonical({selection: selection(), revision: s.revision, workspace_digest: s.workspace_digest, phase: s.phase});
        }
        let observed = context();
        const live = proof => {
            try { return !disposed && !root.document?.hidden && proof.epoch === generation && proof.key === context() && proof.query === serial && activeTarget === canonical(proof.target); }
            catch { return false; }
        };
        function releaseObject(item) {
            if (!transient.has(item)) return;
            root.clearTimeout(item.timer); item.anchor.remove(); root.URL.revokeObjectURL(item.url); transient.delete(item);
        }
        function retire() {
            generation++; allowed = new WeakMap(); controllers.forEach(c => c.abort());
            [...transient].forEach(releaseObject);
        }
        const unsubscribe = workspace.subscribe(() => { try { const next = context(); if (next === observed) return; observed = next; } catch {} retire(); });
        const listen = (node, event, callback) => { if (!node?.addEventListener) return; node.addEventListener(event, callback); listeners.push(() => node.removeEventListener(event, callback)); };
        listen(root, 'pagehide', retire);
        listen(root.document, 'visibilitychange', () => { if (root.document.hidden) retire(); });
        function activate(t) { target(t); const next = canonical(t); if (activeTarget !== null && activeTarget !== next) retire(); activeTarget = next; }
        async function request(action, body) {
            // A response-time visibility fence cannot prevent an already-hidden dispatch.
            check(!disposed && !root.document?.hidden && Date.now() < Date.parse(d.expires_at), 'download_context');
            const controller = new AbortController(); controllers.add(controller);
            const timer = root.setTimeout(() => controller.abort(), 45000), release = () => { root.clearTimeout(timer); controllers.delete(controller); };
            try {
                const response = await root.fetch(endpoints[action], {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
                    headers: {Accept: action === 'metadata' ? 'application/json' : 'application/octet-stream', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': d.csrf}, body: canonical(body)});
                if (!response.ok || response.status !== 200) throw failure('download_unavailable', response.status);
                return {response, release, aborted: () => controller.signal.aborted};
            } catch (e) { release(); throw failure(e.code === 'download_context' ? e.code : 'download_unavailable', int(e.status) ? e.status : 0); }
        }
        async function bounded(response, cap) {
            check(response.headers.get('Content-Encoding') === null || response.headers.get('Content-Encoding') === 'identity');
            const expected = response.headers.get('Content-Length');
            if (expected !== null) check(/^[1-9][0-9]*$/.test(expected) && Number(expected) <= cap);
            check(response.body?.getReader); const reader = response.body.getReader(), parts = []; let length = 0;
            try {
                for (;;) { const part = await reader.read(); if (part.done) break; length += part.value.byteLength; if (length > cap) { await reader.cancel(); throw failure(); } parts.push(part.value); }
            } finally { reader.releaseLock(); }
            if (expected !== null) check(length === Number(expected));
            const out = new Uint8Array(length); let offset = 0; for (const part of parts) { out.set(part, offset); offset += part.byteLength; } return out;
        }
        function file(value) {
            exact(value, ['id', 'name', 'mime', 'size', 'original_sha256', 'state', 'download_available', 'scan_expires_at', 'attestation_id', 'attestation_revision']);
            check(U.test(value.id) && typeof value.name === 'string' && [...value.name].length >= 1 && [...value.name].length <= 120 && /\.pdf$/i.test(value.name)
                && !/[\\/\u0000-\u001f\u007f]/.test(value.name) && value.mime === 'application/pdf' && int(value.size, 1) && value.size <= 5242880 && H.test(value.original_sha256)
                && ['held', 'ready', 'rejected'].includes(value.state) && typeof value.download_available === 'boolean');
            if (value.state === 'ready') check(value.download_available === true && U.test(value.attestation_id) && int(value.attestation_revision, 1)
                && Number.isFinite(Date.parse(value.scan_expires_at)) && Date.now() < Date.parse(value.scan_expires_at) && Date.parse(value.scan_expires_at) <= Date.parse(d.expires_at));
            else check(value.download_available === false && value.scan_expires_at === null && value.attestation_id === null && value.attestation_revision === null);
            return freeze(plain(value));
        }
        async function metadata(t) {
            activate(t); const proof = {target: plain(t), key: context(), epoch: generation, query: ++serial}; let packet;
            try {
                packet = await request('metadata', {binding: d.binding, selection: selection(), target: plain(t)});
                check(packet.response.headers.get('Content-Type')?.split(';')[0] === 'application/json');
                const bytes = await bounded(packet.response, 65536); check(!packet.aborted() && live(proof), 'download_context');
                const raw = JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(bytes));
                exact(raw, ['workspace_id', 'workspace_revision', 'workspace_digest', 'target', 'target_version', 'files', 'expires_at']); target(raw.target);
                const state = workspace.snapshot();
                check(raw.workspace_id === d.workspace_id && raw.workspace_revision === state.revision && raw.workspace_digest === state.workspace_digest
                    && canonical(raw.target) === canonical(t) && int(raw.target_version, 1) && raw.expires_at === d.expires_at && Array.isArray(raw.files) && raw.files.length <= 6);
                const files = raw.files.map(file); check(new Set(files.map(f => f.id)).size === files.length && live(proof), 'download_context');
                allowed = new WeakMap(); for (const f of files) if (f.state === 'ready') allowed.set(f, proof);
                return Object.freeze({...raw, target: freeze(plain(raw.target)), files: Object.freeze(files)});
            } catch (e) { if (proof.epoch === generation && proof.query === serial) retire(); throw failure(e.code === 'download_context' ? e.code : 'download_unavailable', int(e.status) ? e.status : 0); }
            finally { packet?.release(); }
        }
        const eligible = f => { const proof = allowed.get(f); return !!proof && live(proof) && Date.now() < Date.parse(f.scan_expires_at); };
        async function download(f) {
            check(eligible(f), 'download_context'); const proof = allowed.get(f); let packet;
            try {
                packet = await request('download', {binding: d.binding, selection: selection(), target: plain(proof.target), upload_id: f.id, attestation_id: f.attestation_id, attestation_revision: f.attestation_revision});
                const response = packet.response, expected = {'X-Everbranch-Attachment-Workspace': d.workspace_id, 'X-Everbranch-Attachment-Version': d.binding.version_hash,
                    'X-Everbranch-Attachment-Upload': f.id, 'X-Everbranch-Attachment-SHA256': f.original_sha256, 'X-Everbranch-Attachment-Mime': 'application/pdf',
                    'X-Everbranch-Attachment-Attestation': f.attestation_id, 'X-Everbranch-Attachment-Revision': String(f.attestation_revision), 'X-Everbranch-Attachment-Expires': f.scan_expires_at};
                for (const [key, value] of Object.entries(expected)) check(response.headers.get(key) === value);
                check(response.headers.get('Content-Type')?.split(';')[0] === 'application/octet-stream' && response.headers.get('Content-Length') === String(f.size)
                    && /^attachment(?:;|$)/i.test(response.headers.get('Content-Disposition') || '') && response.headers.get('Content-Range') === null && response.headers.get('Accept-Ranges') === 'none');
                const bytes = await bounded(response, 5242880), digest = [...new Uint8Array(await root.crypto.subtle.digest('SHA-256', bytes))].map(n => n.toString(16).padStart(2, '0')).join('');
                check(bytes.byteLength === f.size && digest === f.original_sha256 && new TextDecoder().decode(bytes.subarray(0, 5)) === '%PDF-');
                check(!packet.aborted() && eligible(f) && live(proof), 'download_context');
                const blob = new Blob([bytes], {type: 'application/octet-stream'}), anchor = root.document.createElement('a'), url = root.URL.createObjectURL(blob);
                const item = {anchor, url, timer: null}; transient.add(item); anchor.hidden = true; anchor.href = url; anchor.download = f.name; root.document.body.append(anchor);
                try { check(!packet.aborted() && eligible(f), 'download_context'); anchor.click(); }
                catch (e) { releaseObject(item); throw e; }
                item.timer = root.setTimeout(() => releaseObject(item), Math.max(0, Math.min(1000, Date.parse(f.scan_expires_at) - Date.now(), Date.parse(d.expires_at) - Date.now())));
                return Object.freeze({status: 'requested'});
            } catch (e) { if (live(proof)) retire(); throw failure(e.code === 'download_context' ? e.code : 'download_unavailable', int(e.status) ? e.status : 0); }
            finally { packet?.release(); }
        }
        const api = Object.freeze({metadata, eligible, download, retire, destroy() { if (disposed) return; disposed = true; retire(); unsubscribe(); listeners.forEach(f => f()); if (instances.get(boot)?.api === api) instances.delete(boot); }});
        instances.set(boot, {workspace, api}); return api;
    }
    root.EverbranchWorkspaceDownloads = Object.freeze({create});
})();
