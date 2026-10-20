<x-layouts::app.sidebar title="Connections">
    <flux:main>
        @php
            $stripeReady = $account?->isReady() ?? false;
            $stripeSteps = [
                ['title' => 'Connect your payment account', 'detail' => 'A payment account for your business', 'done' => (bool) $account],
                ['title' => 'Confirm your payout bank', 'detail' => 'Sign in to your bank securely in Stripe', 'done' => (bool) $account?->payouts_enabled],
                ['title' => 'Finish verification', 'detail' => 'Payments and payouts enabled', 'done' => $stripeReady],
            ];
        @endphp
        <style>
            [data-payment-setup] .fb-btn { min-height: 48px; border-radius: 10px; padding: 12px 22px; font-size: 15px; font-weight: 650; line-height: 1.3; white-space: normal; }
            [data-payment-setup] .fb-btn-primary { background: #183e34; border-color: #183e34; color: #fff; }
            [data-payment-setup] .fb-btn-primary span { color: inherit; }
            [data-payment-setup] .fb-btn-primary:hover { background: #285246; border-color: #285246; }
            [data-payment-setup] .fb-btn:focus-visible { outline: 3px solid #78a48a; outline-offset: 3px; }
        </style>
        <div data-payment-setup class="mx-auto max-w-6xl space-y-6 pb-10">
            <x-ui.operational-header title="Accept payments" :description="'Receive customer payments for '.$tenant->name.'.'" />
            @if(session('status'))<div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">{{ session('status') }}</div>@endif
            @if($errors->any())<div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-950">{{ $errors->first() }}</div>@endif
            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" aria-labelledby="card-setup-title">
                <div class="border-b border-zinc-100 bg-emerald-50/60 p-6 sm:p-8">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-widest text-emerald-800">Your business · Your payments</p>
                        <span class="rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ $stripeReady ? 'Account verified' : ($account ? 'Setup in progress' : 'Not connected') }}</span>
                    </div>
                    <h2 id="card-setup-title" class="mt-4 text-2xl font-semibold tracking-tight text-zinc-950 sm:text-3xl">{{ $stripeReady ? 'Your payment account is connected.' : 'Let’s get your business ready to get paid.' }}</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-zinc-600">Accept cards, Apple Pay and Google Pay on eligible devices through Stripe. Connect your existing Stripe account or create one for {{ $tenant->name }}. Reuse your verified payout bank, or sign in to your bank during secure Stripe setup.</p>
                    <ol class="mt-7 grid gap-4 sm:grid-cols-3" aria-label="Stripe setup progress">
                        @foreach($stripeSteps as $step)
                            <li class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $step['done'] ? 'bg-emerald-800 text-white' : 'border border-zinc-300 bg-white text-zinc-600' }}" aria-hidden="true">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                                <div><p class="text-sm font-semibold text-zinc-900">{{ $step['title'] }}<span class="sr-only"> · {{ $step['done'] ? 'Complete' : 'Pending' }}</span></p><p class="mt-1 text-xs leading-5 text-zinc-500">{{ $step['detail'] }}</p></div>
                            </li>
                        @endforeach
                    </ol>
                </div>
                <div class="grid lg:grid-cols-3">
                    <div class="space-y-5 p-6 sm:p-8 lg:col-span-2">
                    <div><h3 class="text-lg font-semibold text-zinc-950">Cards & digital wallets</h3><p class="mt-2 text-sm leading-6 text-zinc-600">{{ $stripeReady ? 'Review your business information or check your latest verification status.' : ($account?->details_submitted ? 'Your details have been submitted. Continue in Stripe to review any outstanding requirements, then check verification here.' : 'Start with your business details. Stripe securely collects identity information and your payout bank account during setup.') }}</p></div>
                    @if($stripeCanStart)
                        @if(!$account && $stripeOAuthAvailable)
                        <form method="post" action="{{ route('tenant-payments.stripe.connect', ['tenant' => $tenant->slug]) }}">@csrf<button class="fb-btn fb-btn-primary">Connect existing Stripe account</button><p class="mt-2 text-xs text-zinc-500">Sign in to reuse your account and payout bank.</p></form>
                        @endif
                        @if($stripeEmbedded)
                            <div data-stripe-setup data-key="{{ config('services.stripe.publishable_key') }}" data-session-url="{{ route('tenant-payments.stripe.session', ['tenant' => $tenant->slug]) }}">
                                <button type="button" class="fb-btn fb-btn-primary" data-start>{{ $account ? 'Continue setup' : ($stripeOAuthAvailable ? 'Create payment account' : 'Accept payments') }}</button>
                                <p class="mt-3 text-sm text-rose-800" role="alert" data-error hidden></p>
                                <div class="mt-4" data-component></div>
                                <p class="mt-3 text-sm text-zinc-600" data-complete hidden>Setup closed. Use Check verification below to see your current status.</p>
                            </div>
                        @else
                            <form method="post" action="{{ route('tenant-payments.stripe.start', ['tenant' => $tenant->slug]) }}" class="space-y-4" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
                                @csrf
                                <label class="block max-w-md text-sm font-medium text-zinc-800">Account owner email<input class="mt-2 block min-h-12 w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base" type="email" name="email" autocomplete="email" value="{{ old('email', auth()->user()->email) }}" required></label>
                                <button class="fb-btn fb-btn-primary min-h-12 w-full justify-center px-6 sm:w-auto disabled:opacity-60" x-bind:disabled="submitting"><span x-show="!submitting">{{ $account ? 'Continue setup' : ($stripeOAuthAvailable ? 'Create payment account' : 'Accept payments') }}</span><span x-show="submitting" x-cloak>Opening secure setup…</span></button>
                                <p class="text-xs leading-5 text-zinc-500">Continue securely with Stripe. You can return here and resume setup.</p>
                            </form>
                        @endif
                        @if($account)
                            <form method="post" action="{{ route('tenant-payments.stripe.refresh', ['tenant' => $tenant->slug]) }}">@csrf<button class="fb-btn fb-btn-secondary">Check verification</button></form>
                        @endif
                    @else
                        <p class="text-sm text-amber-900">Card payment setup is awaiting Everbranch activation. Contact support to finish connecting your business.</p>
                    @endif
                    @if($account)
                        @if($account->routingMode() === 'direct')<a class="fb-btn fb-btn-secondary inline-flex" href="https://dashboard.stripe.com/{{ $account->provider_account_id }}/dashboard" target="_blank" rel="noopener noreferrer">Open Stripe dashboard</a>@endif
                        @if(count(data_get($account->metadata, 'requirements', [])))<div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-950"><strong>Still needed in Stripe</strong><ul class="mt-2 list-disc pl-5">@foreach(data_get($account->metadata, 'requirements', []) as $requirement)<li>{{ str($requirement)->replace(['.', '_'], ' ')->headline() }}</li>@endforeach</ul></div>@endif
                        <dl class="grid grid-cols-2 gap-4 rounded-xl bg-zinc-50 p-4 text-sm">
                            <div><dt class="text-zinc-500">Card payments</dt><dd class="mt-1 font-semibold text-zinc-900">{{ $account->charges_enabled ? 'Enabled' : 'Pending Stripe approval' }}</dd></div>
                            <div><dt class="text-zinc-500">Bank payouts</dt><dd class="mt-1 font-semibold text-zinc-900">{{ $account->payouts_enabled ? 'Enabled' : 'Pending setup or approval' }}</dd></div>
                        </dl>
                        <p class="text-xs text-zinc-500">{{ $account->last_checked_at ? 'Last checked '.$account->last_checked_at->diffForHumans().'.' : 'Use Check verification after returning from Stripe.' }}</p>
                    @endif
                    <p class="border-t border-zinc-100 pt-4 text-sm leading-6 text-zinc-500">{{ $stripeCollectionReady ? 'Account setup is complete. Each checkout also checks its launch requirements.' : 'Customer checkout stays closed until payment and launch verification are complete.' }}</p>
                </div>
                <aside class="space-y-6 border-t border-zinc-100 bg-zinc-50/70 p-6 sm:p-8 lg:border-l lg:border-t-0" aria-label="Payment setup help">
                    <div><h3 class="text-sm font-semibold text-zinc-950">Your next step</h3><p class="mt-2 text-sm leading-6 text-zinc-600">{{ !$stripeCanStart ? 'Everbranch needs to finish platform setup. Your business can continue when that is ready.' : ($stripeReady ? 'Your Stripe account is verified. Finish any remaining launch checks before accepting customer payments.' : ($account ? 'Continue the same Stripe account to complete outstanding details. Then return and check verification.' : 'Confirm the account owner’s email and open secure setup. Have your legal business details ready.')) }}</p></div>
                    <div><h3 class="text-sm font-semibold text-zinc-950">Waiting for your bank?</h3><p class="mt-2 text-sm leading-6 text-zinc-600">You can begin business setup while your bank account is being approved. If Stripe asks for payout details, return to finish that step when your bank is ready.</p></div>
                    <div class="border-t border-zinc-200 pt-5"><h3 class="text-sm font-semibold text-zinc-950">Secure from the start</h3><p class="mt-2 text-sm leading-6 text-zinc-600">Identity and bank details go directly to Stripe. Customer payments belong to your business. Your Everbranch subscription is managed separately.</p></div>
                </aside>
                </div>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 sm:p-8" aria-labelledby="bank-feed-title"><h2 id="bank-feed-title" class="text-lg font-semibold">Connect bank transactions <span class="text-sm font-normal text-zinc-500">Optional</span></h2><p class="mt-2 text-sm leading-6 text-zinc-600">Bring bank transactions into your cash-flow view and reconcile deposits. This is separate from your payout bank and is never required to accept payments. Existing accounting records stay authoritative.</p>@if($accountingConnected ?? false)<p class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-950">QuickBooks is already connected for read-only accounting. Keep using that connection for the information it supplies; add a bank feed only if you need additional transaction detail.</p>@endif @if($bankSpace)<a class="fb-btn fb-btn-secondary mt-4 inline-flex" href="{{ route('trajectory.index', ['space' => $bankSpace->id, 'tab' => 'accounts']) }}">Connect bank transactions</a>@else<p class="mt-3 text-sm text-zinc-500">Available when your business cash-flow workspace is enabled. Your payment setup can continue without it.</p>@endif</section>
            <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-zinc-950">PayPal</h2>
                    <p class="text-sm text-zinc-600">Use your existing PayPal business account. Sign in to PayPal, approve the connection, then return here.</p>
                    <p class="text-sm text-zinc-500">Review <a class="underline" href="https://www.paypal.com/us/legalhub/paypal/seller-protection" target="_blank" rel="noopener noreferrer">PayPal Seller Protection</a> for eligibility and delivery requirements.</p>
                    @if($paypalConfigured)
                        @if($paypalSandbox)<p class="text-sm font-medium text-amber-900">Test connection · no real payments</p>@endif
                        <p class="text-sm font-medium">{{ $paypalConnection?->isConnected() && $paypalFresh ? 'Account verified' : ($paypalConnection?->status === 'disconnected' ? 'Disconnected' : ($paypalConnection ? 'Verification needed' : 'Not connected')) }}</p>
                        <form method="post" action="{{ route('tenant-payments.paypal.start', ['tenant' => $tenant->slug]) }}" class="space-y-3">
                            @csrf
                            <label class="flex items-start gap-2 text-sm text-zinc-700"><input type="checkbox" name="share_data_consent" value="1" required class="mt-1"> <span>I agree to share my account email with PayPal to set up this connection.</span></label>
                            <button class="fb-btn fb-btn-primary">{{ $paypalConnection && $paypalConnection->status !== 'disconnected' ? 'Continue PayPal setup' : 'Connect PayPal' }}</button>
                        </form>
                        @if($paypalConnection && $paypalConnection->status !== 'disconnected')
                            <div class="flex flex-wrap gap-2">
                                <form method="post" action="{{ route('tenant-payments.paypal.refresh', ['tenant' => $tenant->slug]) }}">@csrf<button class="fb-btn fb-btn-secondary">Check verification</button></form>
                                <form method="post" action="{{ route('tenant-payments.paypal.disconnect', ['tenant' => $tenant->slug]) }}">@csrf<button class="fb-btn fb-btn-secondary">Disconnect</button></form>
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-amber-900">PayPal connection is awaiting Everbranch setup. It is not available for customer payments yet.</p>
                    @endif
                    <p class="border-t border-zinc-100 pt-3 text-sm text-zinc-500">PayPal checkout is not active yet. Connecting an account does not start an Everbranch subscription or charge customers.</p>
            </section>
        </div>
        @if($stripeEmbedded && $stripeCanStart)@vite('resources/js/tenant-payment-setup.js')@endif
    </flux:main>
</x-layouts::app.sidebar>
