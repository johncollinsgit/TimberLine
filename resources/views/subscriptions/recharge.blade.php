<x-layouts::app.sidebar title="Recharge rollout preparation">
    <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        <header>
            <a href="{{ route('subscriptions.workspace.index', ['tenant' => $tenant->id]) }}" class="text-sm font-medium text-emerald-900">Subscriptions</a>
            <p class="mt-4 text-sm text-zinc-600">{{ $tenant->name }}</p>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-zinc-950">Prepare Recharge customers and settings</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">Capture the source, compare private customer drafts and review settings before a future Everbranch rollout.</p>
        </header>

        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
            <h2 class="font-semibold">Preparation only — rollout is off</h2>
            <p class="mt-2 leading-6">Billing, card retries, subscription changes, customer messages and cutover are disabled. Recharge remains the source. Drafts do not create live customers or move payment methods.</p>
            <p class="mt-2 leading-6">Opening this page uses saved information and makes no provider request. A complete capture and matching fields do not prove full functional parity.</p>
        </section>

        @if(session('recharge_notice'))<div role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">{{ session('recharge_notice') }}</div>@endif
        @if($errors->any())<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-950">The preparation request could not finish. Review the fields, store access and current capture before trying again.</div>@endif

        @unless($readsEnabled)
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 text-sm text-zinc-700">
                <h2 class="font-semibold text-zinc-950">Source imports are paused</h2>
                <p class="mt-2 leading-6">Private credential drafts and local comparison remain available. A setup operator must enable read-only capture for this workspace before verification or import. This is separate from billing and rollout activation.</p>
            </section>
        @endunless

        <details class="rounded-2xl border border-zinc-200 bg-white p-5" @if($connections->isEmpty()) open @endif>
            <summary class="cursor-pointer text-lg font-semibold text-zinc-950">Private source setup</summary>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-600">Use a store-specific Recharge API credential with Store, Customers, Subscriptions and Payment Methods read access. Enter the expected Recharge store ID and platform identifier from independently reviewed source settings. The identifier is not assumed to be a Shopify domain. Enter the credential here, never in chat.</p>
            <form method="POST" action="{{ route('subscriptions.recharge.source', ['tenant' => $tenant->id]) }}" class="mt-4 grid gap-4 sm:grid-cols-2" autocomplete="off">
                @csrf
                <input type="hidden" name="request_uuid" value="{{ $requestUuid }}"><input type="hidden" name="platform" value="shopify">
                <label class="min-w-0 text-sm font-medium text-zinc-700">Expected Recharge store ID<input required name="provider_store_id" maxlength="100" class="mt-2 w-full rounded-xl border-zinc-300" autocomplete="off"></label>
                <label class="min-w-0 text-sm font-medium text-zinc-700">Expected platform identifier<input required name="identifier" maxlength="190" class="mt-2 w-full rounded-xl border-zinc-300" autocomplete="off"></label>
                <label class="min-w-0 text-sm font-medium text-zinc-700 sm:col-span-2">Private Recharge API credential<input required type="password" name="recharge_access_token" minlength="16" maxlength="4096" class="mt-2 w-full rounded-xl border-zinc-300" autocomplete="new-password" spellcheck="false"></label>
                <div class="sm:col-span-2"><button class="rounded-xl bg-emerald-900 px-5 py-2.5 text-sm font-semibold text-white">Save private credential draft</button><p class="mt-2 text-xs text-zinc-600">Stored encrypted. Saving does not contact Recharge or enable any live action.</p></div>
            </form>
            @if($sources->isNotEmpty())
                <div class="mt-6 space-y-3 border-t border-zinc-200 pt-4">
                    @foreach($sources as $source)
                        <form method="POST" action="{{ route('subscriptions.recharge.verify', ['tenant' => $tenant->id]) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 text-sm">
                            @csrf<input type="hidden" name="source_id" value="{{ $source->id }}"><input type="hidden" name="request_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <p class="min-w-0 break-words">Private draft #{{ $source->id }} · Store {{ data_get($source->metadata, 'expected_identity.provider_store_id') }}</p>
                            <button @disabled(!$readsEnabled) class="rounded-xl border border-emerald-900 px-4 py-2 font-semibold text-emerald-900 disabled:cursor-not-allowed disabled:opacity-50">Verify source identity</button>
                        </form>
                    @endforeach
                </div>
            @endif
        </details>

        @if($connections->isNotEmpty())
            <form method="GET" action="{{ route('subscriptions.recharge.index') }}" class="flex flex-wrap items-end gap-4 rounded-2xl border border-zinc-200 bg-white p-5">
                <input type="hidden" name="tenant" value="{{ $tenant->id }}">
                <label class="min-w-0 flex-1 text-sm font-medium text-zinc-700">Verified source<select name="connection" class="mt-2 w-full rounded-xl border-zinc-300">@foreach($connections as $entry)<option value="{{ $entry->id }}" @selected($connection?->id === $entry->id)>Recharge {{ $entry->provider_store_id }} · {{ $entry->identifier }}</option>@endforeach</select></label>
                <button class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold">Open source</button>
            </form>
            <section class="grid gap-4 sm:grid-cols-3" aria-label="Source capture totals">
                <div class="rounded-2xl border border-zinc-200 bg-white p-5"><h2 class="text-sm font-medium text-zinc-600">Customers captured</h2><p class="mt-2 text-3xl font-semibold">{{ $summary['customer_count'] ?? '—' }}</p><p class="mt-2 text-sm text-zinc-600">From the last complete accepted capture.</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5"><h2 class="text-sm font-medium text-zinc-600">Subscriptions captured</h2><p class="mt-2 text-3xl font-semibold">{{ $summary['subscription_count'] ?? '—' }}</p><p class="mt-2 text-sm text-zinc-600">All captured statuses remain available for review.</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5"><h2 class="text-sm font-medium text-zinc-600">Last complete capture</h2><p class="mt-2 text-lg font-semibold">{{ $connection?->last_complete_sync_at?->format('M j, Y g:i a T') ?? 'Not captured' }}</p><p class="mt-2 text-sm text-zinc-600">{{ ($readiness['stale'] ?? true) ? 'Needs a fresh verified read before rollout decisions.' : 'A bounded observation, with rollout still disabled.' }}</p></div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Capture and compare</h2>
                @if($readiness['sync_progress'] ?? null)<p class="mt-2 text-sm leading-6 text-zinc-600">A source capture is in progress. Previously accepted customers and totals remain visible until every collection and its repeat verification finish.</p>@endif
                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <form method="POST" action="{{ route('subscriptions.recharge.capture', ['tenant' => $tenant->id]) }}" class="space-y-3">
                        @csrf<input type="hidden" name="connection_id" value="{{ $connection->id }}"><input type="hidden" name="request_uuid" value="{{ $requestUuid }}">
                        <button @disabled(!($readiness['read_queries_enabled'] ?? false)) class="rounded-xl bg-emerald-900 px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{{ ($readiness['sync_progress'] ?? null) ? 'Continue source capture' : 'Start source capture' }}</button>
                        <label class="block text-xs text-zinc-600"><input type="checkbox" name="restart" value="1" class="mr-2 rounded">Restart the entire capture after reviewing changed source data</label>
                    </form>
                    @if($summary['generation_id'] ?? null)
                        <form method="POST" action="{{ route('subscriptions.recharge.prepare', ['tenant' => $tenant->id]) }}">
                            @csrf<input type="hidden" name="connection_id" value="{{ $connection->id }}"><input type="hidden" name="generation_id" value="{{ $summary['generation_id'] }}">
                            <button @disabled(($summary['unreviewed_count'] ?? 0) === 0) class="rounded-xl border border-emerald-900 px-5 py-2.5 text-sm font-semibold text-emerald-900 disabled:cursor-not-allowed disabled:opacity-50">Prepare next 100 local drafts</button>
                        </form>
                    @endif
                </div>
                <p class="mt-3 text-sm leading-6 text-zinc-600">Each source request reads one bounded page. Local preparation preserves source values and verifies customer/address links. It never creates an operational customer, subscription or payment method.</p>
                @if($summary['mapping_counts'] !== null)<p class="mt-3 text-sm text-zinc-700">Fields preserved: {{ number_format($summary['mapping_counts']['mapped'] ?? 0) }} records · Needs review: {{ number_format($summary['mapping_counts']['unverified'] ?? 0) }} · Not prepared: {{ number_format($summary['unreviewed_count'] ?? 0) }}</p>@endif
            </section>

            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
                <div class="border-b border-zinc-200 p-5"><h2 class="text-lg font-semibold">Private source and draft review</h2>
                    <form method="GET" action="{{ route('subscriptions.recharge.index') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                        <input type="hidden" name="tenant" value="{{ $tenant->id }}"><input type="hidden" name="connection" value="{{ $connection->id }}">
                        <label class="text-sm font-medium text-zinc-700">Records<select name="type" class="mt-2 w-full rounded-xl border-zinc-300">@foreach(['customer'=>'Customers','address'=>'Addresses','subscription'=>'Subscriptions','store'=>'Store settings','payment_method'=>'Payment references'] as $key=>$label)<option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>@endforeach</select></label>
                        <label class="text-sm font-medium text-zinc-700">Exact source reference<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" class="mt-2 w-full rounded-xl border-zinc-300"></label>
                        <button class="self-end rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold">Apply filters</button>
                    </form>
                </div>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-600"><tr><th class="px-5 py-3 font-medium">Source reference</th><th class="px-5 py-3 font-medium">Source status</th><th class="px-5 py-3 font-medium">Draft review</th><th class="px-5 py-3 font-medium">Observed</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100">@forelse($resources as $item)
                        @php($saved = $item->snapshot ?? [])
                        @php($customerName = trim((is_string($saved['first_name'] ?? null) ? $saved['first_name'] : '').' '.(is_string($saved['last_name'] ?? null) ? $saved['last_name'] : '')))
                        <tr><td class="px-5 py-4"><a class="font-semibold text-emerald-900 underline" href="{{ route('subscriptions.recharge.show', ['resource'=>$item->id,'tenant'=>$tenant->id]) }}">#{{ $item->provider_id }}</a>
                            @if($type === 'customer' && $customerName !== '')<p class="mt-1 break-words font-medium">{{ $customerName }}</p>@endif
                            @if($type === 'customer' && is_string($saved['email'] ?? null))<p class="mt-1 break-words text-xs text-zinc-600">{{ $saved['email'] }}</p>@endif
                        </td><td class="px-5 py-4">{{ in_array($item->status, ['active','cancelled','expired','observed'], true) ? ucfirst($item->status) : 'Requires review' }}</td><td class="px-5 py-4">{{ $item->mapping_state === 'mapped' ? 'Source fields preserved' : ($item->mapping_state === 'unreviewed' ? 'Not prepared' : 'Needs review') }}</td><td class="whitespace-nowrap px-5 py-4">{{ $item->observed_at?->format('M j, Y g:i a T') }}</td></tr>
                    @empty<tr><td colspan="4" class="px-5 py-8 text-zinc-600">{{ ($summary['generation_id'] ?? null) ? 'No source records match this selection.' : 'No complete source capture has been accepted. An unfinished import is not an empty customer list.' }}</td></tr>@endforelse</tbody>
                </table></div>
                @if($resources->hasPages())<div class="border-t border-zinc-200 p-5">{{ $resources->links() }}</div>@endif
            </section>
        @endif

        <section class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold">Settings requiring source review</h2><p class="mt-2 text-sm leading-6 text-zinc-600">Store currency/timezone can be captured through the API. These settings need source evidence and a matching Everbranch configuration draft before one-to-one readiness can be claimed.</p><ul class="mt-4 space-y-3 text-sm text-zinc-700">@foreach(\App\Services\Subscriptions\SubscriptionRechargeReadinessService::SETTINGS_GAPS as $label)<li class="rounded-xl bg-zinc-50 p-3">{{ $label }} <span class="block text-xs text-amber-900">Unverified</span></li>@endforeach</ul></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold">Remaining coverage checks</h2><ul class="mt-4 space-y-3 text-sm text-zinc-700">@foreach(\App\Services\Subscriptions\SubscriptionRechargeReadinessService::COVERAGE_GAPS as $label)<li class="rounded-xl bg-zinc-50 p-3">{{ $label }} <span class="block text-xs text-amber-900">Source evidence required</span></li>@endforeach</ul><p class="mt-4 text-sm leading-6 text-zinc-600">Recharge limits processed-charge history to 90 days, and delivery projections cover at most 365 days. Full historical parity needs additional source evidence. Saved-card references stay bound to their provider vault.</p></div>
        </section>
    </div>
</x-layouts::app.sidebar>
