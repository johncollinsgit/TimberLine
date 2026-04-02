@php
    $tenantId = request()?->attributes->get('current_tenant_id');
    $resolvedTenantId = is_numeric($tenantId) ? (int) $tenantId : null;
    $resolvedLabels = app(\App\Services\Tenancy\TenantDisplayLabelResolver::class)->resolve($resolvedTenantId);
    $displayLabels = is_array($resolvedLabels['labels'] ?? null) ? (array) $resolvedLabels['labels'] : [];
    $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
    if ($rewardsLabel === '') {
        $rewardsLabel = 'Rewards';
    }
@endphp

<x-layouts::app :title="'Customers'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Customers"
            description="Manage canonical customer profiles linked across Shopify, Growave enrichment, Square, wholesale, event, and manual sources."
            hint-title="How this index works"
            hint-text="Canonical profiles are source-of-truth. External provider records enrich customer context without replacing identity ownership."
        />

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Total Customers</div>
                <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($quickStats['total_customers'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">{{ $rewardsLabel }} Holders</div>
                <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($quickStats['candle_cash_holders'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Growave Linked</div>
                <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($quickStats['growave_linked'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Missing Contact</div>
                <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($quickStats['missing_contact'] ?? 0)) }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-3 sm:p-4 md:p-6">
            <div
                id="marketing-customers-grid"
                data-endpoint="{{ data_get($customerGrid, 'endpoint') }}"
                data-add-customer-url="{{ route('marketing.customers.create') }}"
                data-initial-filters='@json(data_get($customerGrid, "filters", []))'
                data-sort-options='@json(data_get($customerGrid, "sort_options", []))'
                class="space-y-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase tracking-[0.28em] text-emerald-800">Customer master index</div>
                        <h2 class="mt-2 text-lg font-semibold text-zinc-950">Manage Customers</h2>
                        <div class="mt-1 text-sm text-zinc-600">
                            {{ number_format((int) ($totalProfiles ?? 0)) }} customer profile{{ (int) ($totalProfiles ?? 0) === 1 ? '' : 's' }} indexed.
                            Search-first results load in the live grid below, and {{ $rewardsLabel }} stays separate from the legacy Growave balance.
                        </div>
                    </div>
                    <a href="{{ route('marketing.customers.create') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        Add Customer
                    </a>
                </div>
                <div class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 px-4 py-4 text-sm text-zinc-500">
                    The live grid below loads rows on demand so search and filters stay fast. Use the search bar first, then open advanced filters only when you need them.
                </div>
                <noscript class="mt-4 block rounded-2xl border border-amber-300/25 bg-amber-100 px-4 py-3 text-sm text-amber-900">
                    JavaScript is required for the interactive customer grid. Open a customer directly from the search page or enable JavaScript for the faster management view.
                </noscript>
            </div>
        </section>

        @if(($totalProfiles ?? 0) === 0 && !empty($emptyStateDiagnostics))
            <section class="rounded-3xl border border-amber-300/35 bg-amber-100 p-4 sm:p-5">
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-900">Unified Profile Index Not Built</h2>
                <p class="mt-2 text-sm text-amber-800">
                    No marketing profiles have been built yet, but upstream Shopify/Growave/Square customer candidates exist.
                    Run profile sync to build the canonical customer index.
                </p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs text-amber-800">
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-100 px-2.5 py-1">
                        Shopify Orders: {{ number_format((int) ($emptyStateDiagnostics['shopify_order_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-100 px-2.5 py-1">
                        Shopify Customers: {{ number_format((int) ($emptyStateDiagnostics['shopify_customer_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-100 px-2.5 py-1">
                        Growave: {{ number_format((int) ($emptyStateDiagnostics['growave_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-100 px-2.5 py-1">
                        Square Customers: {{ number_format((int) ($emptyStateDiagnostics['square_customer_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-100 px-2.5 py-1">
                        Square Orders: {{ number_format((int) ($emptyStateDiagnostics['square_order_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-100 px-2.5 py-1">
                        Square Payments: {{ number_format((int) ($emptyStateDiagnostics['square_payment_candidates'] ?? 0)) }}
                    </span>
                </div>
                <div class="mt-3 text-xs text-amber-800">
                    <code>php artisan marketing:sync-profiles --source=all --chunk=500</code>
                    @if(!empty($emptyStateDiagnostics['last_sync_at']))
                        <span class="ml-2">
                            Last sync: {{ $emptyStateDiagnostics['last_sync_at'] }}
                            @if(!empty($emptyStateDiagnostics['last_sync_status']))
                                ({{ $emptyStateDiagnostics['last_sync_status'] }})
                            @endif
                        </span>
                    @endif
                </div>
            </section>
        @endif
    </div>

    @once
        @vite('resources/js/marketing/customers-grid.tsx')
    @endonce
</x-layouts::app>
