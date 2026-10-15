@if($shippingHealth ?? null)
<section class="rounded-2xl border bg-white p-6">
    <h2 class="text-lg font-bold">Shipping setup checks</h2>
    <p class="mt-2 text-sm text-zinc-600">{{ $shippingHealth['shipping_enabled'] ? 'Carrier rollout is enabled for this workspace.' : 'Carrier rollout is paused.' }} Physical printer, carrier, tax, and settlement checks are still required before launch.</p>
    <dl class="mt-5 grid gap-4 md:grid-cols-2">@foreach($shippingHealth['checks'] as $check)<div class="rounded-lg border p-3"><dt class="font-semibold">{{ $check['label'] }} <span class="text-xs {{ $check['ready'] ? 'text-emerald-800' : 'text-amber-800' }}">{{ $check['ready'] ? 'Configured' : 'Needs attention' }}</span></dt><dd class="mt-2 text-sm text-zinc-600">{{ $check['detail'] }}</dd></div>@endforeach</dl>
    <p class="mt-4 text-sm">{{ $shippingHealth['unresolved_shipments'] }} unresolved postage operations · {{ $shippingHealth['notification_issues'] }} notification issues.</p>
    <p class="mt-2 text-xs text-zinc-600">Last verified carrier tracking update: {{ $shippingHealth['last_tracking_update'] ?: 'None recorded' }}.</p>
    <a class="mt-4 inline-block font-semibold text-emerald-800 underline" href="{{ route('managed-website.printing.settings') }}">Set up printers and run a no-postage test</a>
</section>
<section class="rounded-2xl border bg-white p-6">
    <h2 class="text-lg font-bold">Tenant-owned carrier account</h2>
    <p class="mt-2 text-sm text-zinc-600">Save this store’s EasyPost key and webhook secret. A new credential set is used for new rates; existing shipments keep their original account for recovery. Saving credentials does not purchase postage or enable checkout.</p>
    @if($shippingHealth['connection_label'])<p class="mt-3 text-sm">Current credential set: {{ $shippingHealth['connection_label'] }}</p>@endif
    <p class="mt-3 break-all text-sm">EasyPost webhook endpoint: {{ route('managed-website.store.shipping-webhook') }}</p>
    <form method="POST" action="{{ route('managed-website.commerce.shipping.connection.store') }}" class="mt-5 grid gap-4 sm:grid-cols-2">@csrf
        <label class="text-sm font-semibold">Credential set name<input required name="label" maxlength="190" value="{{ old('label') }}" class="mt-1 block w-full rounded-lg border-zinc-300" placeholder="Store test account"></label>
        <label class="text-sm font-semibold">Key mode<select name="mode" class="mt-1 block w-full rounded-lg border-zinc-300"><option value="test">Test</option><option value="production" @selected(old('mode') === 'production')>Production</option></select></label>
        <label class="text-sm font-semibold">EasyPost API key<input required name="api_key" type="password" minlength="16" maxlength="255" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-zinc-300"></label>
        <label class="text-sm font-semibold">Matching webhook secret<input required name="webhook_secret" type="password" minlength="16" maxlength="255" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-zinc-300"></label>
        <p class="text-xs text-zinc-600 sm:col-span-2">Configure this secret on the EasyPost webhook above. Rehearse with test credentials and test payments; production activation follows the reviewed merchant launch.</p>
        <button class="fb-btn fb-btn-secondary sm:col-span-2" type="submit">Save new credential set</button>
    </form>
</section>
@endif
