const root = document.getElementById('event-audience-builder');
if (root) {
  const form = document.getElementById('event-audience-form'), kind = document.getElementById('event-audience-kind'), preview = document.getElementById('event-audience-preview'), status = document.getElementById('event-audience-status'), counts = document.getElementById('event-audience-counts'), members = document.getElementById('event-audience-members');
  let generation = 0, controller;
  const sync = () => { for (const fieldset of form.querySelectorAll('[data-audience-kinds]')) { fieldset.hidden = !fieldset.dataset.audienceKinds.split(',').includes(kind.value); fieldset.disabled = fieldset.hidden; } };
  sync(); kind.addEventListener('change', sync);
  form.addEventListener('input', () => { generation++; controller?.abort(); preview.disabled = false; counts.replaceChildren(); members.replaceChildren(); status.textContent = 'Filters changed. Preview again to update membership.'; });
  preview.addEventListener('click', async () => {
    const ticket = ++generation, values = Object.fromEntries(new FormData(form)); values.legacy_ids = new FormData(form).getAll('legacy_ids[]'); delete values['legacy_ids[]']; values.preview_id = crypto.randomUUID();
    controller?.abort(); controller = new AbortController(); preview.disabled = true; status.textContent = 'Preparing the audience preview…'; counts.replaceChildren(); members.replaceChildren();
    try {
      let result;
      do {
        const response = await fetch(root.dataset.previewUrl, { method: 'POST', credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': values._token }, body: JSON.stringify(values) });
        if (generation !== ticket) return;
        if ([401, 403, 419].includes(response.status) || (response.ok && (response.headers.get('X-Audience-Viewer') !== root.dataset.viewer || response.headers.get('X-Audience-Tenant') !== root.dataset.tenantId))) { counts.replaceChildren(); members.replaceChildren(); throw new Error('Your account or workspace changed. Refresh before continuing.'); }
        result = await response.json();
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'The preview could not continue. Start a fresh preview.');
        if (generation !== ticket) return;
        status.textContent = (result.complete ? 'Preview complete' : 'Reviewing ' + result.offset + ' of ' + result.candidates + ' candidates') + ' · ' + result.matched + ' members';
        counts.replaceChildren();
        for (const [title, value] of [['Members', result.matched], ['Email eligible', result.email_eligible], ['SMS eligible', result.sms_eligible], ['Workspace customers missing a saved location', result.workspace_missing_locations], ['Members without eligible email', result.missing_email_permission], ['Members without eligible SMS', result.missing_sms_permission]]) { const card = document.createElement('div'); card.className = 'rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800'; const label = document.createElement('p'); label.className = 'text-sm text-zinc-500'; label.textContent = title; const count = document.createElement('p'); count.className = 'mt-1 text-2xl font-semibold'; count.textContent = value; card.append(label, count); counts.append(card); }
      } while (!result.complete && generation === ticket);
      if (generation !== ticket) return;
      for (const member of result.samples) { const card = document.createElement('div'); card.className = 'rounded-xl border p-4'; const link = document.createElement('a'); const url = new URL(root.dataset.customerUrl, location.origin); url.pathname += '/' + member.id; link.href = url.href; link.textContent = member.name; link.className = 'block min-h-11 font-semibold underline'; const reason = document.createElement('p'); reason.className = 'text-sm text-zinc-500'; reason.textContent = member.reasons.join(' · '); card.append(link, reason); members.append(card); }
      const basis = document.createElement('p'); basis.className = 'text-sm text-zinc-500'; basis.textContent = result.basis; members.append(basis);
      for (const issue of result.review_items) { const note = document.createElement('p'); note.textContent = issue; members.append(note); }
    } catch (error) { if (generation === ticket && error.name !== 'AbortError') status.textContent = error.message || 'The preview could not complete. Try again.'; }
    finally { if (generation === ticket) preview.disabled = false; }
  });
}
