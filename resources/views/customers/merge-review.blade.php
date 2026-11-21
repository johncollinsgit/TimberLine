<section class="rounded-2xl border bg-white p-5 space-y-4 dark:bg-zinc-900" aria-label="Review possible duplicate customers" id="customer-merge-review"
    data-preview-url="{{ route('customers.merges.preview', ['tenant' => $tenant->slug]) }}" data-survivor="{{ $profile->id }}" data-tenant="{{ $tenant->slug }}">
    <h2 class="text-lg font-semibold">Possible duplicate customers</h2>
    <p class="text-sm text-zinc-500">Matching contact details need your review. Confirm the records describe the same person before combining their history. This keeps the current customer and preserves the original provider records.</p>
    @foreach($duplicates as $duplicate)<label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="merge_profile" value="{{ $duplicate->id }}"><span>{{ trim($duplicate->first_name.' '.$duplicate->last_name) ?: 'Customer' }}</span><a class="underline" href="{{ route('customers.profile', ['profile' => $duplicate->id, 'tenant' => $tenant->slug]) }}">View record</a></label>@endforeach
    <button class="min-h-11 rounded-lg border px-4" type="button" data-merge-preview>Review combined history</button>
    <div data-merge-summary class="space-y-3" aria-live="polite"></div>
    <div data-merge-confirm hidden><label class="flex min-h-11 items-center gap-3"><input type="checkbox" data-merge-identity>These records are the same customer.</label><button class="min-h-11 rounded-lg border px-4" type="button" data-merge-apply>Combine reviewed records</button></div>
    <p data-merge-status role="status" class="text-sm"></p>
</section>
@once
<script type="module">
const root = document.querySelector('#customer-merge-review');
if (root) {
    let receipt, busy = false;
    const status = root.querySelector('[data-merge-status]'), summary = root.querySelector('[data-merge-summary]'), confirm = root.querySelector('[data-merge-confirm]');
    const post = async (url, body) => {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(body) });
        const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Review could not be completed.'); return data;
    };
    root.querySelector('[data-merge-preview]').onclick = async () => {
        if (busy) return; receipt = null; confirm.hidden = true; summary.replaceChildren(); busy = true; status.textContent = 'Preparing the current records…';
        try {
            const donorIds = [...root.querySelectorAll('[name="merge_profile"]:checked')].map(input => Number(input.value));
            if (!donorIds.length) throw new Error('Select a possible duplicate first.');
            const data = await post(root.dataset.previewUrl, { request_id: crypto.randomUUID(), profile_ids: [Number(root.dataset.survivor), ...donorIds], survivor_profile_id: Number(root.dataset.survivor) });
            for (const profile of data.before.profiles) { const p = document.createElement('p'); p.textContent = profile.name + ' · ' + Number(profile.balance).toFixed(2) + ' Candle Cash · ' + profile.transaction_count + ' reward entries'; summary.append(p); }
            const note = document.createElement('p'); note.textContent = 'Original purchases and messages will resolve to this customer. Existing reward ledger entries remain auditable; this action does not send a message or create a new reward.'; summary.append(note);
            receipt = data; root.querySelector('[data-merge-identity]').checked = false; confirm.hidden = false; status.textContent = 'Review the records above, then confirm the identity.';
        } catch (error) { status.textContent = error.message; } finally { busy = false; }
    };
    root.querySelector('[data-merge-apply]').onclick = async () => {
        if (busy || !receipt) return;
        if (!root.querySelector('[data-merge-identity]').checked) { status.textContent = 'Confirm that the records describe the same customer.'; return; }
        busy = true; status.textContent = 'Combining the reviewed records…';
        try { const url = new URL('/workspace/customers/merges/' + receipt.operation_id + '/apply', location.origin); url.searchParams.set('tenant', root.dataset.tenant); const data = await post(url, { expected_version: receipt.version, identity_confirmed: true }); location.assign(data.url); }
        catch (error) { receipt = null; confirm.hidden = true; status.textContent = error.message + ' Review the current records again.'; }
        finally { busy = false; }
    };
}
</script>
@endonce
