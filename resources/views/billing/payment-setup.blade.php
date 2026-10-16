<x-layouts::app.sidebar title="Payments">
    <flux:main>
        <div class="mx-auto max-w-4xl space-y-6 pb-10">
            <x-ui.operational-header title="Payments" :description="'Receive customer payments for '.$tenant->name.'.'" />
            @if(session('status'))<div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">{{ session('status') }}</div>@endif
            @if($errors->any())<div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-950">{{ $errors->first() }}</div>@endif
            <p class="text-sm text-zinc-600">Connect the accounts your business uses. Customer payments belong to your business. Your Everbranch subscription is managed separately.</p>
            <div class="grid gap-5 md:grid-cols-2">
                <section class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="text-lg font-semibold text-zinc-950">Cards & digital wallets</h2>
                    <p class="text-sm text-zinc-600">Accept cards, Apple Pay and Google Pay on eligible devices through Stripe. Confirm your business details and connect your payout bank account.</p>
                    <p class="text-sm font-medium">{{ $account?->isReady() ? 'Account verified' : ($account ? 'Setup needs attention' : 'Not connected') }}</p>
                    @if($stripeCanStart)
                        @if($stripeEmbedded)
                            <div data-stripe-setup data-key="{{ config('services.stripe.publishable_key') }}" data-session-url="{{ route('tenant-payments.stripe.session', ['tenant' => $tenant->slug]) }}">
                                <button type="button" class="fb-btn fb-btn-primary" data-start>{{ $account ? 'Continue payment setup' : 'Activate card payments' }}</button>
                                <p class="mt-3 text-sm text-rose-800" role="alert" data-error hidden></p>
                                <div class="mt-4" data-component></div>
                                <p class="mt-3 text-sm text-zinc-600" data-complete hidden>Setup closed. Use Check verification below to see your current status.</p>
                            </div>
                        @else
                            <form method="post" action="{{ route('tenant-payments.stripe.start', ['tenant' => $tenant->slug]) }}" class="space-y-3">
                                @csrf
                                <label class="block text-sm">Account owner email<input class="mt-1 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2" type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required></label>
                                <button class="fb-btn fb-btn-primary">{{ $account ? 'Continue payment setup' : 'Activate card payments' }}</button>
                            </form>
                        @endif
                        @if($account)
                            <form method="post" action="{{ route('tenant-payments.stripe.refresh', ['tenant' => $tenant->slug]) }}">@csrf<button class="fb-btn fb-btn-secondary">Check verification</button></form>
                        @endif
                    @else
                        <p class="text-sm text-amber-900">Card payment setup is awaiting Everbranch activation. Contact support to finish connecting your business.</p>
                    @endif
                    <p class="border-t border-zinc-100 pt-3 text-sm text-zinc-500">{{ $stripeCollectionReady ? 'Account setup is complete. Each checkout also checks its launch requirements.' : 'Customer checkout stays closed until payment and launch verification are complete.' }}</p>
                </section>
                <section class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6">
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
        </div>
        @if($stripeEmbedded && $stripeCanStart)@vite('resources/js/tenant-payment-setup.js')@endif
    </flux:main>
</x-layouts::app.sidebar>
