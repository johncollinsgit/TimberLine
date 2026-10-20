// Account-bound questionnaire draft. No local storage, contacts, passwords or tenant creation.
export function workspaceDraft(form, initialRevision, surface, snapshot) {
    const status = document.createElement('p');
    status.setAttribute('role', 'status');
    status.className = 'mt-3 text-sm text-zinc-600';
    form.append(status);
    let revision = Number(initialRevision || 0), timer, pending = false, queued = false, stopped = false;
    const save = async () => {
        if (stopped) return;
        if (pending) { queued = true; return; }
        pending = true;
        status.textContent = 'Saving your progress…';
        try {
            const response = await fetch(form.dataset.draftUrl, {
                method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value },
                body: JSON.stringify({ revision, fields: { ...snapshot(), surface } }),
            });
            if (!response.ok) {
                stopped = response.status === 409;
                throw new Error(stopped ? 'Your draft changed in another session. Reload before continuing.' : 'Progress could not be saved. Keep this page open and try another change.');
            }
            const result = await response.json();
            if (result.saved !== true || !Number.isInteger(result.revision)) throw new Error('Progress could not be confirmed.');
            revision = result.revision;
            status.textContent = 'Progress saved to your account. You can return later.';
        } catch (error) { status.textContent = error.message || 'Progress could not be saved.'; }
        finally { pending = false; if (queued) { queued = false; save(); } }
    };
    const changed = () => { clearTimeout(timer); timer = setTimeout(save, 600); };
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('workspace-draft-change', changed);
    form.addEventListener('submit', () => { stopped = true; clearTimeout(timer); });
    return changed;
}
window.workspaceDraft = workspaceDraft;
window.dispatchEvent(new Event('workspace-draft-ready'));
