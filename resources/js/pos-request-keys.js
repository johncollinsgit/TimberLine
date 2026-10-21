/* Durable browser request recovery. This source is inlined into the private register only. */
(() => {
    const valid = key => typeof key === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(key);
    async function resolve({ savedKey, suppliedKey, replayKey, records = [], lookup }) {
        const key = [replayKey, savedKey, suppliedKey].find(valid);
        if (!key) throw new Error('A valid request key is required.');
        let record = records.find(candidate => candidate.request_id === key) || null;
        if (!record && (valid(savedKey) || valid(replayKey)) && lookup) record = await lookup(key);
        if (record && record.request_id !== key) throw new Error('The recovered request does not match.');
        return { key, record };
    }

    async function bind({ form, input, scope, freshKey, records, lookup, terminalStatuses, label, nextLabel, blocked = false, storage = null, document = globalThis.document }) {
        let savedKey = null;
        try { storage = storage || globalThis.sessionStorage; savedKey = storage?.getItem(scope); } catch (_) { /* Server UUID checks remain authoritative. */ }
        const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
        let paused = blocked;
        const setBlocked = value => { paused = value || blocked; buttons.forEach(button => { button.disabled = paused; }); };
        form.addEventListener('submit', event => { if (paused) event.preventDefault(); });
        const notice = document.createElement('div');
        notice.className = 'notice compact'; notice.setAttribute('role', 'status');
        const remember = key => { input.value = key; try { storage?.setItem(scope, key); } catch (_) { /* No storage available. */ } };
        if (valid(savedKey) && input.dataset.replay !== 'true') input.value = savedKey;
        if (valid(savedKey) || input.dataset.replay === 'true') setBlocked(true);
        try {
            const result = await resolve({ savedKey, suppliedKey: input.value, replayKey: input.dataset.replay === 'true' ? input.value : null, records, lookup });
            remember(result.key);
            if (!result.record) { setBlocked(false); return; }
            setBlocked(true);
            const message = document.createElement('p');
            message.textContent = `Your previous ${label} is already recorded as ${result.record.status.replaceAll('_', ' ')}. Review it before preparing another.`;
            const link = document.createElement('a'); link.href = result.record.url; link.textContent = `Open recorded ${label}`;
            notice.append(message, link);
            if (terminalStatuses.includes(result.record.status) && valid(freshKey) && freshKey !== result.key && !blocked) {
                const next = document.createElement('button'); next.type = 'button'; next.className = 'secondary'; next.textContent = nextLabel;
                next.addEventListener('click', () => { remember(freshKey); notice.remove(); setBlocked(false); });
                const actions = document.createElement('div'); actions.className = 'actions'; actions.append(next); notice.append(actions);
            }
            form.prepend(notice);
        } catch (_) {
            setBlocked(true); notice.textContent = `Unable to check the previous ${label}. Refresh this page before preparing another.`; form.prepend(notice);
        }
    }
    globalThis.PosRequestKeys = { resolve, bind };
})();
