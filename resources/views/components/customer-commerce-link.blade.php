@props(['profile'])
@if(app(\App\Services\Marketing\CustomerCommerceLinkService::class)->canLink(auth()->user(), (int) $profile->tenant_id))
    <details class="rounded-2xl border border-zinc-200 bg-white p-5 text-zinc-950">
        <summary class="cursor-pointer font-semibold">Connect a customer or purchase source</summary>
        <p class="mt-3 text-sm text-zinc-600">Connect a verified source to this customer to show purchases together. Review the source record and confirm the identity before saving.</p>
        <form method="POST" action="{{ route('marketing.customers.commerce-sources.store', $profile) }}" class="mt-4 grid gap-4">
            @csrf
            <label class="text-sm">Source
                <select name="source_type" required class="mt-1 block w-full rounded-lg border-zinc-300">
                    <option value="website_customer">Website customer ID</option>
                    <option value="website_order">Website order ID</option>
                    <option value="order">Shopify/imported order record ID</option>
                    <option value="square_order">Square order ID</option>
                    <option value="square_payment">Square payment ID</option>
                </select>
            </label>
            <label class="text-sm">Source record ID<input name="source_id" required maxlength="190" class="mt-1 block w-full rounded-lg border-zinc-300" /></label>
            <label class="text-sm">How did you verify this is the same customer?<textarea name="reason" required minlength="8" maxlength="1000" class="mt-1 block w-full rounded-lg border-zinc-300"></textarea></label>
            <label class="flex gap-2 text-sm"><input type="checkbox" name="identity_confirmed" value="1" required />I reviewed this source and confirmed it belongs to this customer.</label>
            <button class="justify-self-start rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white" type="submit">Connect source</button>
        </form>
    </details>
@endif
