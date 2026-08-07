<x-layouts::app.sidebar title="Connected commerce imports">
    <flux:main>
        <div class="mx-auto max-w-6xl space-y-6 pb-12">
            <header class="rounded-3xl border border-emerald-100 bg-gradient-to-br from-emerald-950 to-emerald-800 p-7 text-white shadow-sm">
                <a class="text-sm font-semibold text-emerald-100 hover:text-white hover:underline" href="{{ route('managed-website.products.index') }}">← Website Commerce</a>
                <p class="mt-6 text-xs font-bold uppercase tracking-[.16em] text-emerald-200">Connected operations</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight">Bring context over before you move anything.</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-emerald-100">This wizard creates a mapping report first. It stores no data in native Website Commerce, never writes back to a source, and never enrolls imported contacts in email or text marketing.</p>
            </header>

            @if(session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-950">{{ $errors->first() }}</div>@endif

            <form method="POST" action="{{ route('managed-website.commerce.imports.dry-run') }}" class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm" data-import-wizard>
                @csrf
                <div class="border-b border-zinc-200 p-6">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-800">1. Choose a source</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach(['shopify' => 'Shopify', 'woocommerce' => 'WooCommerce', 'squarespace' => 'Squarespace', 'wix' => 'Wix'] as $id => $label)
                            <label class="group cursor-pointer rounded-2xl border border-zinc-200 p-4 transition hover:border-emerald-500 has-[:checked]:border-emerald-700 has-[:checked]:bg-emerald-50">
                                <input class="sr-only" type="radio" name="provider" value="{{ $id }}" @checked(old('provider', 'shopify') === $id) data-provider-control>
                                <span class="flex items-center justify-between font-bold text-zinc-950">{{ $label }}<span class="grid h-5 w-5 place-items-center rounded-full border border-zinc-300 text-xs text-transparent group-has-[:checked]:border-emerald-700 group-has-[:checked]:bg-emerald-700 group-has-[:checked]:text-white">✓</span></span>
                                <span class="mt-2 block text-xs leading-5 text-zinc-600">Read-only context first, with a source-specific capability report.</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="border-b border-zinc-200 p-6">
                    <div class="flex flex-wrap items-end justify-between gap-2"><div><p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-800">2. Choose what to map</p><p class="mt-1 text-sm text-zinc-600">Unavailable items are explained instead of silently skipped.</p></div><span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-bold text-zinc-700">Dry run only</span></div>
                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                        @foreach(['catalog', 'inventory', 'customers', 'orders', 'fulfillment', 'content', 'consent'] as $resource)
                            <label class="flex cursor-pointer gap-3 rounded-2xl border border-zinc-200 p-4 transition hover:border-emerald-400 has-[:checked]:border-emerald-700 has-[:checked]:bg-emerald-50" data-resource-card="{{ $resource }}">
                                <input class="mt-1 h-4 w-4 rounded border-zinc-300 text-emerald-700 focus:ring-emerald-700" type="checkbox" name="resources[]" value="{{ $resource }}" @checked(in_array($resource, old('resources', ['catalog', 'customers', 'orders']))) data-resource-control="{{ $resource }}">
                                <span><span class="block font-bold text-zinc-950" data-resource-label="{{ $resource }}">{{ $providerCapabilities['shopify'][$resource]['label'] }}</span><span class="mt-1 block text-xs leading-5 text-zinc-600" data-resource-reason="{{ $resource }}">{{ $providerCapabilities['shopify'][$resource]['reason'] }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-950">Consent records are source evidence only. Everbranch will never automatically send email or SMS to an imported person.</p>
                </div>
                <div class="border-b border-zinc-200 p-6">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-800">3. Optional connection</p>
                    <p class="mt-1 text-sm text-zinc-600">Select a matching tenant connection if it is already configured. Otherwise the report clearly stops at connection readiness.</p>
                    <select name="integration_connection_id" class="mt-4 block w-full max-w-xl rounded-xl border-zinc-300 text-sm focus:border-emerald-700 focus:ring-emerald-700" data-connection-select>
                        <option value="">No connection selected yet</option>
                        @foreach($connections as $connection)
                            <option value="{{ $connection->id }}" data-provider="{{ $connection->provider }}">{{ str($connection->provider)->headline() }} · {{ $connection->external_account_label ?: $connection->external_account_id ?: 'Connected account' }} ({{ $connection->status }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-3 bg-zinc-50 p-6 sm:flex-row sm:items-center sm:justify-between">
                    <p class="max-w-2xl text-xs leading-5 text-zinc-600">The next step is a mapping report. A later, owner-approved native cutover requires reconciliation, payment and tax readiness, shipping readiness, a site preview, and remains reversible until publishing.</p>
                    <button class="fb-btn fb-btn-primary justify-center whitespace-nowrap" type="submit">Create mapping report</button>
                </div>
            </form>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-800">Audit trail</p><h2 class="mt-1 text-xl font-bold text-zinc-950">Recent mapping reports</h2></div><span class="text-xs text-zinc-500">Tenant {{ $tenant->name }}</span></div>
                <div class="mt-5 divide-y divide-zinc-100">
                    @forelse($runs as $run)
                        <div class="py-4"><div class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm text-zinc-950">{{ str($run->source->provider)->headline() }} · report #{{ $run->id }}</strong><span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-bold text-zinc-700">{{ str($run->status)->headline() }}</span></div><p class="mt-1 text-sm text-zinc-600">{{ implode(', ', $run->requested_resources ?? []) ?: 'No resources selected' }}</p><p class="mt-1 text-xs text-zinc-500">{{ data_get($run->report, 'write_back') ? 'Write-back enabled' : 'No write-back' }} · {{ data_get($run->report, 'native_website_tables') ? 'Native tables included' : 'Native tables untouched' }} · {{ $run->created_at->format('M j, Y g:i A') }}</p></div>
                    @empty
                        <p class="py-8 text-sm text-zinc-500">No reports yet. Start with the parts of your current system you want Everbranch to understand.</p>
                    @endforelse
                </div>
            </section>
        </div>
        <script>
            (() => {
                const capabilities = @json($providerCapabilities);
                const radios = document.querySelectorAll('[data-provider-control]');
                const update = () => {
                    const provider = [...radios].find((input) => input.checked)?.value || 'shopify';
                    document.querySelectorAll('[data-resource-control]').forEach((input) => {
                        const capability = capabilities[provider][input.dataset.resourceControl];
                        const card = input.closest('[data-resource-card]');
                        card.querySelector('[data-resource-label]').textContent = capability.label;
                        card.querySelector('[data-resource-reason]').textContent = capability.reason;
                        input.disabled = !capability.available;
                        if (!capability.available) input.checked = false;
                        card.classList.toggle('opacity-50', !capability.available);
                    });
                    document.querySelectorAll('[data-connection-select] option[data-provider]').forEach((option) => option.hidden = option.dataset.provider !== provider);
                };
                radios.forEach((input) => input.addEventListener('change', update));
                update();
            })();
        </script>
    </flux:main>
</x-layouts::app.sidebar>
