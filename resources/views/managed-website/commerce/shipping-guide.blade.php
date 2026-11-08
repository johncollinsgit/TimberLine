@if($shippingHealth ?? null)
@php
    $checks = collect($shippingHealth['checks'])->keyBy('label');
    $steps = [
        ['Pickup and ship-from origin', '1. Add your ship-from address', '#shipping-origin'],
        ['Item shipping weights', '2. Review product weights', route('managed-website.products.shipping-weights')],
        ['Reviewed packages', '3. Measure your packages and packing', '#shipping-packages'],
        ['Carrier mode and services', '4. Choose destinations, services, and customer prices', '#shipping-policy'],
        ['Carrier credential set', '5. Connect your store’s carrier account', '#shipping-account'],
        ['Carrier webhook', '6. Configure tracking updates', '#shipping-account'],
    ];
    $complete = collect($steps)->filter(fn ($step) => $checks[$step[0]]['ready'] ?? false)->count();
@endphp
<section class="rounded-2xl border bg-white p-6" aria-labelledby="shipping-guide-title">
    <h2 id="shipping-guide-title" class="text-lg font-bold">Get ready to ship</h2>
    <p role="status" class="mt-2 text-sm text-zinc-600">{{ $complete }} of {{ count($steps) }} setup steps configured. Your progress is saved each time you save a section.</p>
    <ol class="mt-4 grid gap-3 md:grid-cols-2">
        @foreach($steps as [$check, $label, $url])
        <li class="rounded-lg border p-3"><a class="font-semibold text-emerald-800 underline" href="{{ $url }}">{{ $label }}</a><p class="mt-1 text-sm">{{ ($checks[$check]['ready'] ?? false) ? 'Configured' : 'Needs attention' }}</p></li>
        @endforeach
    </ol>
    <p class="mt-4 text-sm">Then rehearse a test order, purchase a test label, print it, and confirm signed tracking updates. Verify account funding and carrier availability in EasyPost. A saved key or test rate does not verify live billing.</p>
    <a class="mt-3 inline-block text-sm font-semibold text-emerald-800 underline" href="{{ route('managed-website.printing.settings') }}">Set up your printer and print a no-postage test</a>
    <p class="mt-3 text-xs text-zinc-600">Freight products require a separate reviewed quote including pallet measurements, freight class, residential delivery, liftgate, and appointment needs. They cannot use parcel checkout.</p>
</section>
@endif
