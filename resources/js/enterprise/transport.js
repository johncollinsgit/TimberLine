// Shared by the inline Blade shell and the bundled workflow workspace.
// Runtime selection comes only from trusted server presentation, never URL/storage.
(() => {
    'use strict';
    const root = globalThis;
    let pendingWrites = 0;
    const refreshBlockers = new Set();
    const notify = () => {
        if (root.document?.dispatchEvent && root.CustomEvent) root.document.dispatchEvent(new root.CustomEvent('enterprise:network-activity', {detail: {pendingWrites, refreshBlockers: refreshBlockers.size}}));
    };
    const activity = delta => {
        pendingWrites += delta;
        notify();
    };
    root.EverbranchTransport = Object.freeze({
        get pendingWrites() { return pendingWrites; },
        get refreshBlockers() { return refreshBlockers.size; },
        setRefreshBlocker(key, blocked) {
            if (blocked) refreshBlockers.add(key); else refreshBlockers.delete(key);
            notify();
        },
        create(boot) {
            const mode = boot.runtime ?? 'private';
            if (!['private', 'sample'].includes(mode)) throw new Error('Unsupported Enterprise runtime.');
            if (mode === 'sample') {
                const adapter = root.EverbranchSampleTransport?.create(boot);
                if (!adapter || adapter.mode !== 'sample' || !['request', 'href', 'navigate', 'replace'].every(name => typeof adapter[name] === 'function')) {
                    throw new Error('The isolated sample adapter is unavailable.');
                }
                const href = url => adapter.href(String(url));
                return Object.freeze({
                    mode,
                    request: (url, body, options = {}) => adapter.request(String(url), body, options),
                    href,
                    navigate: url => adapter.navigate(String(url)),
                    replace: url => adapter.replace(String(url)),
                });
            }
            const identity = boot.identity ?? {};
            const href = url => String(url);
            return Object.freeze({
                mode,
                href,
                navigate: url => root.location.assign(href(url)),
                replace: url => root.history.replaceState(null, '', href(url)),
                async request(url, body, options = {}) {
                    const target = new URL(String(url), root.location.href);
                    if (target.origin !== root.location.origin || target.username || target.password) {
                        throw new Error('Enterprise requests must use the current application.');
                    }
                    if (!identity.viewer || !identity.workspace) throw new Error('The authorized Enterprise identity is unavailable.');
                    const writing = body !== undefined || (options.method && !['GET', 'HEAD'].includes(options.method.toUpperCase()));
                    if (writing) activity(1);
                    try {
                        const response = await root.fetch(target.href, {
                            credentials: 'same-origin',
                            ...(options.signal ? {signal: options.signal} : {}),
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': options.binary ? 'application/octet-stream' : 'application/json',
                                'X-CSRF-TOKEN': boot.csrf ?? root.document?.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                'X-Team-Viewer': String(identity.viewer),
                                'X-Team-Workspace': String(identity.workspace),
                            },
                            ...(body !== undefined || options.method ? {
                                method: options.method ?? 'POST',
                                ...(body !== undefined ? {body: options.binary ? body : JSON.stringify(body)} : {}),
                            } : {}),
                        });
                        const result = await response.json().catch(() => ({message: 'The server could not confirm this action.'}));
                        if (!response.ok) {
                            const error = new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || result.error || 'This action is unavailable.');
                            error.status = response.status;
                            throw error;
                        }
                        return result;
                        } finally {
                        if (writing) activity(-1);
                    }
                },
            });
        },
    });
})();
