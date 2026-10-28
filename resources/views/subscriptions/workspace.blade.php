<x-layouts::app.sidebar title="Subscriptions">
    <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        <header>
            <p class="text-sm text-zinc-600">{{ $tenant->name }}</p>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-zinc-950">Subscriptions</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">Review verified Shopify contract history and the last completed synchronization for this workspace.</p>
            <a href="{{ route('subscriptions.recharge.index', ['tenant' => $tenant->id]) }}" class="mt-3 inline-flex rounded-xl bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Prepare Recharge customers and settings</a>
        </header>

        @include('subscriptions.pilot-report', ['pilotTenant' => $tenant])

        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950" aria-label="Subscription availability">
            <h2 class="font-semibold">History and setup review</h2>
            <p class="mt-2 leading-6">Card retries, payment-update emails and contract changes are not available yet. Existing subscription billing continues with its current provider.</p>
            <p class="mt-2 leading-6">This page uses saved records. Opening it does not contact Shopify or refresh customer information.</p>
        </section>

        @if($errors->any())
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">The filters could not be applied. Review your search and try again.</div>
        @endif

        @if(in_array(data_get($readiness, 'sync_progress.phase'), ['collect', 'verify'], true))
            <section role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-950">
                <h2 class="font-semibold">Synchronization in progress</h2>
                <p class="mt-2 leading-6">Contract history is being checked. The saved total updates after the entire verification finishes.</p>
                @if(is_int(data_get($readiness, 'sync_progress.staged_contract_count')) && data_get($readiness, 'sync_progress.staged_contract_count') >= 0)
                    <p class="mt-2">{{ number_format(data_get($readiness, 'sync_progress.staged_contract_count')) }} contracts checked so far.</p>
                @endif
            </section>
        @endif

        @if($installations->isEmpty())
            <section class="rounded-2xl border border-zinc-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-zinc-950">Verify your subscription store</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">A setup operator must verify this store and the app that owns its contracts before history can appear here. Products, local request records and another app's subscriptions do not establish ownership.</p>
            </section>
        @else
            <form method="GET" action="{{ route('subscriptions.workspace.index') }}" class="grid gap-4 rounded-2xl border border-zinc-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_0.8fr_auto] lg:items-end">
                @if(request()->query('tenant'))<input type="hidden" name="tenant" value="{{ $tenant->id }}">@endif
                <label class="min-w-0 text-sm font-medium text-zinc-700">Store
                    <select name="installation" class="mt-2 w-full rounded-xl border-zinc-300">
                        @foreach($installations as $store)<option value="{{ $store->id }}" @selected($installation?->id === $store->id)>{{ $store->shop_domain }}</option>@endforeach
                    </select>
                </label>
                <label class="min-w-0 text-sm font-medium text-zinc-700">Contract reference
                    <input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" class="mt-2 w-full rounded-xl border-zinc-300" placeholder="Search a reference">
                </label>
                <label class="min-w-0 text-sm font-medium text-zinc-700">Status
                    <select name="status" class="mt-2 w-full rounded-xl border-zinc-300">
                        @foreach(['all' => 'All statuses', 'active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'failed' => 'Failed'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <button class="rounded-xl bg-emerald-900 px-5 py-2.5 text-sm font-semibold text-white">Apply filters</button>
            </form>

            <section class="grid gap-4 sm:grid-cols-3" aria-label="Saved subscription readiness">
                <div class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-sm font-medium text-zinc-600">Store verification</h2>
                    <p class="mt-2 text-xl font-semibold text-zinc-950">{{ ($readiness['identity_verified'] ?? false) ? 'Verified' : 'Needs review' }}</p>
                    <p class="mt-2 break-words text-sm text-zinc-600">{{ $installation?->shop_domain }}</p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-sm font-medium text-zinc-600">Last complete synchronization</h2>
                    <p class="mt-2 text-lg font-semibold text-zinc-950">{{ $installation?->last_synced_at?->format('M j, Y g:i a T') ?? 'Not checked' }}</p>
                    <p class="mt-2 text-sm text-zinc-600">{{ $installation?->last_synced_at ? 'History reflects this observation, including paused and cancelled contracts.' : 'An incomplete or failed read does not establish an empty contract list.' }}</p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-sm font-medium text-zinc-600">Contracts owned by this app</h2>
                    <p class="mt-2 text-3xl font-semibold text-zinc-950">{{ $readiness['owned_contract_count'] ?? '—' }}</p>
                    <p class="mt-2 text-sm text-zinc-600">{{ ($readiness['owned_contract_count'] ?? null) !== null ? 'From the last complete verified read.' : 'Available after a complete verified read.' }}</p>
                </div>
            </section>

            @if(!empty($readiness['blockers']))
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 text-sm text-zinc-700">
                    <h2 class="font-semibold text-zinc-950">Provider setup requires review</h2>
                    <p class="mt-2 leading-6">A setup operator should review the store's access, credentials and synchronization status before relying on this history. Existing saved records are retained.</p>
                    @if($readiness['stale'] ?? true)<p class="mt-2 leading-6">Saved history needs a fresh verified read before it can guide renewal decisions.</p>@endif
                </section>
            @endif

            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
                <div class="border-b border-zinc-200 px-5 py-4"><h2 class="text-lg font-semibold text-zinc-950">Saved contract history</h2></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-zinc-600"><tr><th class="px-5 py-3 font-medium">Reference</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 font-medium">Observation</th><th class="px-5 py-3 font-medium">History</th></tr></thead>
                        <tbody class="divide-y divide-zinc-100">
                        @forelse($contracts as $contract)
                            @php($reference = preg_match('/^gid:\/\/shopify\/SubscriptionContract\/([0-9]+)$/D', $contract->provider_gid, $matches) ? '#'.$matches[1] : 'Reference requires review')
                            <tr><td class="px-5 py-4 font-medium text-zinc-950">{{ $reference }}</td><td class="px-5 py-4">{{ in_array(strtolower($contract->status ?? ''), ['active', 'paused', 'cancelled', 'expired', 'failed'], true) ? ucfirst(strtolower($contract->status)) : 'Needs review' }}</td><td class="whitespace-nowrap px-5 py-4">{{ $contract->observed_at?->format('M j, Y g:i a T') ?? 'Not checked' }}</td><td class="px-5 py-4">{{ $contract->completeness === 'complete' && !$contract->stale_reason ? 'Complete at observation' : 'Requires review' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-8 text-zinc-600">{{ $installation?->last_synced_at ? 'No saved contracts match these filters. Other apps can own subscriptions that are not available here.' : 'No complete verified contract synchronization has been recorded.' }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($contracts->hasPages())<div class="border-t border-zinc-200 px-5 py-4">{{ $contracts->links() }}</div>@endif
            </section>
        @endif
    </div>
</x-layouts::app.sidebar>
