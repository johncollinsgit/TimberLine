<x-layouts::app :title="__('Your plan')">
    <main class="mx-auto max-w-4xl space-y-6 p-4 text-zinc-900 md:p-8">
        <h1 class="text-2xl font-semibold">{{ $paid ? 'You’re ready.' : ($purchased ? 'Your subscription' : 'Review your plan') }}</h1>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if(session('status_error'))<p role="alert" class="rounded-xl bg-amber-50 p-4">{{ session('status_error') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-xl bg-amber-50 p-4">{{ $errors->first() }}</div>@endif
        @if($purchased)
            <section class="rounded-[20px] border border-zinc-200 bg-white p-6">
                @if($paid)
                <h2 class="text-xl font-semibold">Build your website</h2>
                <p class="my-4">Choose a design, add your content, and publish to your Everbranch address.</p>
                <form method="post" action="{{ route('self-service.purchase.branches', ['tenant' => $tenant->slug]) }}">
                    @csrf @method('PUT')<input type="hidden" name="branches[]" value="managed_website">
                    <button type="submit" class="fb-btn fb-btn-primary"><x-everbranch.action-leaf />Build website</button>
                </form>
                <p class="mt-4 text-sm text-zinc-600">Custom domains connect from Website → Domains.</p>
                @else
                    <h2 class="text-xl font-semibold">Check your billing</h2>
                    <p class="my-4">Update your payment method or review invoices to restore publishing.</p>
                @endif
                <form method="post" action="{{ route('self-service.purchase.portal', ['tenant' => $tenant->slug]) }}" class="mt-5">
                    @csrf<button type="submit" class="fb-btn fb-btn-secondary"><x-everbranch.action-leaf />Manage billing</button>
                </form>
            </section>
            @if($paid)
            <details class="rounded-[20px] border border-zinc-200 bg-white p-6">
                <summary class="cursor-pointer font-semibold">Add included branches</summary>
                <form method="post" action="{{ route('self-service.purchase.branches', ['tenant' => $tenant->slug]) }}" class="mt-5 space-y-4">
                    @csrf @method('PUT')
                    @foreach($modules as $module)<label class="flex gap-3"><input name="branches[]" type="checkbox" value="{{ $module['key'] }}">{{ $module['display_name'] }}</label>@endforeach
                    <button type="submit" class="fb-btn fb-btn-primary"><x-everbranch.action-leaf />Add branches</button>
                </form>
            </details>
            @endif
        @else
            <section class="rounded-[20px] border border-zinc-200 bg-white p-6">
                <h2 class="text-xl font-semibold">Everbranch Simple</h2>
                <dl class="my-5 space-y-4">
                    <div class="flex justify-between"><dt>Setup</dt><dd>${{ number_format($quote['setup_cents'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between"><dt>Monthly</dt><dd>${{ number_format($quote['monthly_cents'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between border-t pt-4 font-semibold"><dt>Setup + first month</dt><dd>${{ number_format($quote['subtotal_cents'] / 100, 2) }}</dd></div>
                </dl>
                <p class="text-sm text-zinc-600">Before applicable tax, calculated at secure checkout. {{ $quote['usage_note'] }}</p>
                <details class="my-5">
                    <summary class="cursor-pointer text-sm font-semibold">Included tools</summary>
                    <div class="mt-3 flex flex-wrap gap-2" aria-label="Included tools">
                        @foreach($modules as $module)@if(in_array($module['key'], $selected, true))<span class="rounded-full bg-emerald-50 px-3 py-2 text-sm">{{ $module['display_name'] }}</span>@endif @endforeach
                    </div>
                </details>
                @unless($pending)<a class="text-sm underline" href="{{ route('platform.build') }}">Change template</a>@endunless
                @if($pending)
                    <div class="my-5 rounded-xl bg-emerald-50 p-4" data-purchase-pending data-status-url="{{ route('self-service.purchase.status', ['tenant' => $tenant->slug], false) }}">
                        <p role="status" data-payment-status>Waiting for payment confirmation.</p>
                        <p class="mt-2 text-sm">Your saved checkout will be reused. Returning here does not create a second charge.</p>
                        <p class="mt-2 text-sm">Add more included branches after payment.</p>
                    </div>
                @endif
                <form method="post" action="{{ route('self-service.purchase.checkout', ['tenant' => $tenant->slug]) }}" class="mt-6 space-y-5">
                    @csrf
                    <input type="hidden" name="quote_hash" value="{{ $quote['quote_hash'] }}">
                    @foreach($selected as $key)<input type="hidden" name="branches[]" value="{{ $key }}">@endforeach
                    <label class="flex gap-3 text-sm"><input name="terms_accepted" type="checkbox" value="1" required> <span>I accept the <a class="underline" href="{{ route('legal.terms') }}" target="_blank" rel="noopener">service terms</a>, ${{ number_format($quote['setup_cents'] / 100, 2) }} setup, ${{ number_format($quote['monthly_cents'] / 100, 2) }}/month recurring service, applicable tax, and disclosed usage fees.</span></label>
                    @if($ready)<button class="fb-btn fb-btn-primary" type="submit"><x-everbranch.action-leaf />{{ $pending ? 'Resume checkout' : 'Continue to payment' }}</button>
                    @else<p class="rounded-xl bg-amber-50 p-4" role="status">Payments are temporarily unavailable. Your choices are saved; try again shortly.</p>@endif
                </form>
            </section>
        @endif
    </main>
    <script>
    (() => {
        const panel = document.querySelector('[data-purchase-pending]');
        if (!panel) return;
        let checks = 0;
        const check = async () => {
            if (++checks > 24) { panel.querySelector('[data-payment-status]').textContent = 'Confirmation is taking longer. Refresh this page to check again; your checkout is saved.'; return; }
            try {
                const response = await fetch(panel.dataset.statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
                if (response.ok && (await response.json()).paid === true) { location.reload(); return; }
            } catch (_) {}
            setTimeout(check, 5000);
        };
        setTimeout(check, 2000);
    })();
    </script>
</x-layouts::app>
