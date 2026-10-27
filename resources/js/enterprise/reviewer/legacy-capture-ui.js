/** Explicit local download adapter. It never boots or reads storage until its caller mounts and the owner clicks. */
export function mountLegacyCapture(doc, win, capture) {
    const button = doc.getElementById('capture-download'), status = doc.getElementById('capture-status');
    const hash = /^[a-f0-9]{64}$/, uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
    const closed = (v, keys) => v && Object.getPrototypeOf(v) === Object.prototype && Object.keys(v).length === keys.length && keys.every(k => Object.hasOwn(v, k));
    const iso = value => typeof value === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(value) && Number.isFinite(Date.parse(value)) && new Date(value).toISOString().replace('.000Z', 'Z') === value;
    const pinKeys = ['version_hash', 'manifest_sha256', 'source_hash', 'build_id', 'approval_sha256'];
    const validPins = v => closed(v, pinKeys) && pinKeys.filter(k => k !== 'build_id').every(k => typeof v[k] === 'string' && hash.test(v[k])) && typeof v.build_id === 'string' && /^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/.test(v.build_id);
    let boot;
    try {
        boot = JSON.parse(doc.getElementById('capture-context').textContent);
        const endpoint = new URL(boot.authorize_url);
        if (!closed(boot, ['version_id', 'pins', 'authorize_url', 'csrf']) || !uuid.test(boot.version_id) || !validPins(boot.pins)
            || typeof boot.csrf !== 'string' || !/^[A-Za-z0-9_-]{16,256}$/.test(boot.csrf)
            || endpoint.origin !== win.location.origin || endpoint.username || endpoint.password || endpoint.search || endpoint.hash
            || !new RegExp('^/enterprise-owner/owner-workspaces/[A-Za-z0-9_-]{1,200}/proposals/legacy-capture/' + boot.version_id + '/authorize$').test(endpoint.pathname)
            || endpoint.href !== boot.authorize_url || typeof capture !== 'function') throw new Error();
    } catch {
        if (button) button.disabled = true;
        if (status) status.textContent = 'This save screen is unavailable. Your tab has not been read.';
        return {destroy() {}};
    }
    let disposed = false, busy = false, generation = 0, controller = null, url = null;
    const message = text => { status.textContent = text; };
    const revoke = () => { if (url !== null) { win.URL.revokeObjectURL(url); url = null; } };
    const live = id => !disposed && !doc.hidden && generation === id;
    const retire = () => { generation++; controller?.abort(); controller = null; busy = false; revoke(); button.disabled = disposed || !!doc.hidden; };
    const visibility = () => { if (doc.hidden) { retire(); message('Save paused. Return to this tab and choose Download again.'); } else if (!disposed) button.disabled = false; };
    const destroy = () => { disposed = true; retire(); button.removeEventListener('click', click); doc.removeEventListener('visibilitychange', visibility); win.removeEventListener('pagehide', destroy); };
    async function click() {
        if (disposed || busy || doc.hidden) return;
        busy = true; button.disabled = true; const id = ++generation;
        controller = new AbortController();
        message('Checking your access…');
        let timer;
        try {
            timer = win.setTimeout(() => { if (live(id)) { retire(); message('Access check timed out. Your tab has not been read.'); } }, 60000);
            const response = await win.fetch(boot.authorize_url, {method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': boot.csrf}, body: JSON.stringify(boot.pins), signal: controller.signal});
            if (!live(id)) return;
            if (!response.ok || response.status !== 200 || !/^application\/json(?:;|$)/i.test(response.headers.get('Content-Type') || '')) throw new Error();
            const text = await response.text();
            if (!live(id)) return;
            if (new TextEncoder().encode(text).length > 4096) throw new Error();
            const auth = JSON.parse(text), now = Date.now();
            if (!closed(auth, ['schema_version', 'pins', 'deadline', 'capture_binding']) || auth.schema_version !== 1 || !validPins(auth.pins)
                || !pinKeys.every(key => auth.pins[key] === boot.pins[key]) || !iso(auth.deadline)) throw new Error();
            const b = auth.capture_binding;
            if (!closed(b, ['schema_version', 'capture_id', 'observed_at', 'observed_version_id', 'observed_version_hash', 'observed_source_hash', 'observed_build_id'])
                || b.schema_version !== 1 || !uuid.test(b.capture_id) || !iso(b.observed_at) || b.observed_version_id !== boot.version_id
                || b.observed_version_hash !== boot.pins.version_hash || b.observed_source_hash !== boot.pins.source_hash || b.observed_build_id !== boot.pins.build_id
                || Date.parse(b.observed_at) > now + 5000 || Date.parse(auth.deadline) <= now || Date.parse(auth.deadline) > now + 65000
                || Date.parse(auth.deadline) - Date.parse(b.observed_at) > 60000 || Date.parse(auth.deadline) <= Date.parse(b.observed_at)) throw new Error();
            if (!live(id)) return;
            // Only this branch obtains the fixed tab storage handle; the pure helper performs one fixed-key read.
            let storage;
            try { storage = win.sessionStorage; } catch { throw new Error(); }
            const result = await capture(storage, b);
            if (!live(id)) return;
            if (Date.now() >= Date.parse(auth.deadline)) { message('Access check expired. Choose Download again. Your tab was not changed.'); return; }
            if (!result.original || !['captured_opaque', 'refused'].includes(result.status)) {
                message(result.status === 'not_observed' ? 'No saved edits were observed in this tab. Other tabs may still have edits.'
                    : 'This tab could not be saved safely. Its original storage was left unchanged.');
                return;
            }
            const payload = JSON.stringify(result, null, 2) + '\n';
            const blob = new win.Blob([payload], {type: 'application/json'});
            url = win.URL.createObjectURL(blob);
            const anchor = doc.createElement('a');
            anchor.href = url; anchor.download = 'everbranch-tab-edits-' + b.capture_id + '.json';
            doc.body.appendChild(anchor);
            try { if (live(id) && Date.now() < Date.parse(auth.deadline)) anchor.click(); } finally { anchor.remove(); }
            message(result.status === 'captured_opaque' ? 'Saved locally for review. Nothing was imported. Keep the file private.'
                : 'Original text saved locally, but its format needs review. Nothing was imported.');
            const created = url;
            win.setTimeout(() => { win.URL.revokeObjectURL(created); if (url === created) url = null; }, 0);
        } catch {
            if (live(id)) message('Save unavailable. Your tab was not changed. Check access and choose Download again.');
        } finally {
            win.clearTimeout(timer);
            if (generation === id) { controller = null; busy = false; button.disabled = disposed || !!doc.hidden; }
        }
    }
    button.addEventListener('click', click);
    doc.addEventListener('visibilitychange', visibility);
    win.addEventListener('pagehide', destroy);
    button.disabled = !!doc.hidden;
    return {destroy};
}
