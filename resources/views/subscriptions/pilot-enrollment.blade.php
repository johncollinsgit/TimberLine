<x-layouts::app.sidebar title="Review subscription pilot">
    <div class="space-y-6">
        <header><h1 class="text-2xl font-semibold">{{ $member->details['name'] }}</h1><p class="mt-2 text-sm text-zinc-600">Review this subscription before asking the customer to authorize Stripe payments. Recharge continues billing until a separate verified activation.</p></header>
        @if($errors->any())<p role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4">{{ $errors->first() }}</p>@endif
        @if(session('pilot_status'))<p role="status" class="rounded-lg border border-emerald-300 p-4">{{ session('pilot_status') }}</p>@endif
        @if(session('authorization_link'))<section class="rounded-lg border border-emerald-300 bg-white p-4"><h2 class="font-semibold">Private customer authorization link</h2><p class="mt-2 text-sm">Share this link privately with this customer. No message was sent. Saving a card does not activate billing.</p><textarea readonly class="mt-3 w-full rounded-lg border-zinc-300 text-sm" aria-label="Private customer authorization link">{{ decrypt(session('authorization_link')) }}</textarea></section>@endif
        <section class="rounded-lg border border-zinc-200 bg-white p-5">
            <h2 class="font-semibold">Recharge read connection</h2>
            <p class="mt-2 text-sm text-zinc-600">Use a private read-only API token for the original retail store with read_store, read_customers, read_subscriptions and read_orders. Do not post the token in chat.</p>
            <form method="POST" action="{{ route('subscriptions.pilot.recharge-connect',['tenant'=>$tenant->slug]) }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf
                <input type="hidden" name="shopify_store_id" value="{{ $member->shopify_store_id }}">
                <label class="grow text-sm">Private Recharge token<input name="password" type="password" autocomplete="new-password" required class="mt-1 block w-full rounded-lg border-zinc-300"></label>
                <button class="rounded-lg bg-emerald-900 px-4 py-3 text-sm text-white">Verify read-only connection</button>
            </form>
        </section>
        @if($enrollment)
            <section class="rounded-lg border border-zinc-200 bg-white p-5"><h2 class="font-semibold">Authorization: {{ ucfirst($enrollment->status) }}</h2><p class="mt-2 text-sm">{{ $enrollment->terms['currency'] }} {{ number_format($enrollment->terms['amount_cents']/100,2) }} every {{ $enrollment->terms['billing_months'] }} month(s), starting {{ $enrollment->terms['first_billing_date'] }}. Terms are retained unchanged.</p>
                <form method="POST" action="{{ route('subscriptions.pilot.enrollment.access',['member'=>$member->id,'tenant'=>$tenant->slug]) }}" class="mt-3">@csrf<button class="text-sm font-medium text-emerald-900">Show private customer link</button></form><p class="mt-2 text-sm">Customer payment authorization: {{ $enrollment->authorized_at ? 'Verified with Stripe' : 'Awaiting customer' }}.</p>
                @if($enrollment->status==='authorized')<form method="POST" action="{{ route('subscriptions.pilot.enrollment.activate',['member'=>$member->id,'tenant'=>$tenant->slug]) }}" class="mt-4 space-y-3">@csrf<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="reviewed" value="1" required class="mt-1 rounded">Activate only this reviewed subscription after fresh Recharge checks confirm stopped billing and no queued or collected cycle charge.</label><button class="rounded-lg bg-emerald-900 px-4 py-3 text-sm text-white">Verify source and activate selected pilot</button></form>@endif
            </section>
        @elseif(isset($quote))
            <section class="rounded-lg border border-zinc-200 bg-white p-5"><h2 class="font-semibold">Confirm recurring terms</h2>
                <p class="mt-2">{{ $quote['currency'] }} {{ number_format($quote['amount_cents']/100,2) }} every {{ $quote['billing_months'] }} month(s), starting {{ $quote['first_billing_date'] }} at 9:00 am {{ $quote['timezone'] }}.</p>
                <p class="mt-2 text-sm">Products {{ number_format($quote['subtotal_cents']/100,2) }}, shipping {{ number_format($quote['shipping_cents']/100,2) }}, tax {{ number_format($quote['tax_cents']/100,2) }}.</p>
                <p class="mt-2 text-sm">Ship to {{ $quote['shipping_address']['firstName'] }} {{ $quote['shipping_address']['lastName'] }}, {{ $quote['shipping_address']['address1'] }}, {{ $quote['shipping_address']['city'] }} {{ $quote['shipping_address']['provinceCode'] }} {{ $quote['shipping_address']['zip'] }}.</p>
                <p class="mt-2 text-sm">{{ $quote['cancellation_policy'] }} Candle Cash cannot pay for subscriptions.</p>
                <form method="POST" action="{{ route('subscriptions.pilot.enrollment.prepare',['member'=>$member->id,'tenant'=>$tenant->slug]) }}" class="mt-4 space-y-3">@csrf
                    @foreach($input as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ is_bool($value)?(int)$value:$value }}">@endforeach
                    <input type="hidden" name="terms_hash" value="{{ $quote['terms_hash'] }}">
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="reviewed" value="1" required class="mt-1 rounded">I reviewed the amount, tax basis, delivery address and recurring terms for this customer.</label>
                    <button class="rounded-lg bg-emerald-900 px-4 py-3 text-sm text-white">Save terms and prepare private authorization link</button>
                </form>
            </section>
        @else
            <form method="POST" action="{{ route('subscriptions.pilot.enrollment.quote',['member'=>$member->id,'tenant'=>$tenant->slug]) }}" class="space-y-4 rounded-lg border border-zinc-200 bg-white p-5">@csrf
                <h2 class="font-semibold">Review delivery and tax</h2>
                <label class="block text-sm">Verified Recharge store<select name="recharge_connection_id" required class="mt-1 block w-full rounded-lg border-zinc-300"><option value="">Select verified store</option>@foreach($connections as $connection)<option value="{{ $connection->id }}">{{ $connection->identifier }}</option>@endforeach</select></label>
                <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm">Shipping amount ({{ $member->currency }})<input type="number" min="0" max="1000" step="0.01" name="shipping_amount" required class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm">Tax rate (%)<input type="number" min="0" max="100" step="0.01" name="tax_rate_percent" required class="mt-1 block w-full rounded-lg border-zinc-300"></label></div>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="tax_shipping" value="1" class="mt-1 rounded">This tax rate also applies to shipping.</label>
                <label class="block text-sm">Confirmed tax basis<textarea name="tax_basis" minlength="10" maxlength="500" required class="mt-1 block w-full rounded-lg border-zinc-300"></textarea></label>
                <p class="text-sm text-zinc-600">The total must match the prepared subscription amount. A different address, price or tax outcome needs a new review.</p>
                <button class="rounded-lg bg-emerald-900 px-4 py-3 text-sm text-white">Preview recurring terms</button>
            </form>
        @endif
    </div>
</x-layouts::app.sidebar>
