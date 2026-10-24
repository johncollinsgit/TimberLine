/* A current permitted shared ticket only. No sample IDs, writes, native route or local record fallback. */
(() => {
    'use strict';
    const root = globalThis, uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
    function create({adapter, dispatch, notify, currentArea, context}) {
        if (adapter?.mode !== 'shared' || typeof adapter.snapshot !== 'function' || typeof adapter.readSelection !== 'function'
            || ![dispatch, notify, currentArea, context].every(value => typeof value === 'function')) throw Error('Shared tour navigation is unavailable.');
        let epoch = 0, busy = false, retired = false, controller = null;
        const canonical = root.EverbranchSharedWorkspace.canonical;
        function capture() {
            const value = adapter.snapshot();
            return {identity: canonical({workspace_id:value.workspace_id, browser_context:value.browser_context}),
                selection:{persona:value.persona, area_ids:[...value.area_ids], person_id:value.person_id}, context:context()};
        }
        function same(before) {
            try {
                const fresh = capture();
                return fresh.identity === before.identity && canonical(fresh.selection) === canonical(before.selection) && fresh.context === before.context;
            } catch { return false; }
        }
        function invalidate() { epoch++; controller?.abort(); }
        const listeners = [[root.document, 'enterprise:navigate'], [root.document, 'enterprise:workspace-state'],
            [root.document, 'enterprise:tour-context'], [root, 'popstate'], [root, 'pagehide']];
        for (const [target, event] of listeners) target.addEventListener(event, invalidate);
        return Object.freeze({async open() {
            if (retired || busy) return {available:false};
            let before, preferred;
            try { before = capture(); preferred = currentArea(); }
            catch { notify('Ticket access could not be confirmed. Refresh current work and try again.'); return {available:false}; }
            const own = epoch;
            busy = true; const request = new AbortController(); controller = request;
            const current = () => !retired && own === epoch && same(before);
            try {
                const view = await adapter.readSelection(before.selection, {signal:request.signal});
                if (!current()) return {available:false};
                if (canonical(view.selection) !== canonical(before.selection) || !Array.isArray(view.graph?.tickets)) throw Error('Invalid shared projection.');
                const rows = view.graph.tickets;
                if (rows.some(row => !uuid.test(row.id) || !uuid.test(row.area_id) || !before.selection.area_ids.includes(row.area_id))) throw Error('Invalid shared ticket.');
                const ordered = [...rows].sort((a,b) => Number(b.area_id === preferred) - Number(a.area_id === preferred) || a.id.localeCompare(b.id));
                const ticket = ordered[0];
                if (!ticket) {
                    notify('No currently permitted ticket is available in this selection.');
                    return {available:false};
                }
                dispatch({section:'requests', area:ticket.area_id, selected:ticket.id});
                return {available:true};
            } catch {
                if (current()) notify('Ticket access could not be confirmed. Refresh current work and try again.');
                return {available:false};
            } finally {
                if (controller === request) controller = null;
                busy = false;
            }
        }, destroy() {
            if (retired) return;
            retired = true; invalidate();
            for (const [target, event] of listeners) target.removeEventListener(event, invalidate);
        }});
    }
    root.EverbranchSharedTourNavigation = Object.freeze({create});
})();
