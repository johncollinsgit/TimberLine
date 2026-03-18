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
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Total Customers</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['total_customers'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Candle Cash Holders</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['candle_cash_holders'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Growave Linked</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['growave_linked'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Missing Contact</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['missing_contact'] ?? 0)) }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-3 sm:p-4 md:p-6">
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
                        <div class="text-xs uppercase tracking-[0.28em] text-emerald-100/55">Customer master index</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">Manage Customers</h2>
                        <div class="mt-1 text-sm text-white/65">
                            {{ number_format((int) ($profiles->total() ?? 0)) }} result{{ (int) ($profiles->total() ?? 0) === 1 ? '' : 's' }}.
                            Candle Cash and legacy Growave balances are shown separately.
                        </div>
                    </div>
                    <a href="{{ route('marketing.customers.create') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Add Customer
                    </a>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-[1820px] text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[220px]">Customer</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[180px]">Email</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[150px]">Phone</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[130px]">Candle Cash</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[120px]">Display</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[130px]">Legacy Growave</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[110px]">Tier</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[100px]">Referrals</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[120px]">Reviews</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[100px]">Orders</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[120px]">Birthday</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[280px]">Sources</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap min-w-[110px]">Updated</th>
                                <th class="px-4 py-3 text-right whitespace-nowrap min-w-[95px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse($profiles as $profile)
                                @php
                                    $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                                    $displayName = $name !== '' ? $name : ($profile->email ?: ($profile->phone ?: 'Unnamed profile'));
                                    $channels = is_array($profile->source_channels) ? $profile->source_channels : [];
                                    $stats = $derivedStats[(int) $profile->id] ?? ['order_count' => 0, 'last_order_at' => null, 'source_badges' => []];
                                    $loyalty = $loyaltyStats[(int) $profile->id] ?? ['candle_cash_points' => 0, 'candle_cash_amount' => 0, 'legacy_growave_points' => 0, 'tier' => null, 'referrals' => 0, 'has_growave' => false, 'review_count' => 0, 'average_rating' => null, 'last_synced_at' => null];
                                    $hasShopifySource = in_array('shopify', $channels, true) || in_array('Shopify', (array) ($stats['source_badges'] ?? []), true);
                                    $birthdayLabel = 'Missing';
                                    if ($profile->birthdayProfile?->birthday_full_date) {
                                        $birthdayLabel = $profile->birthdayProfile->birthday_full_date;
                                    } elseif ($profile->birthdayProfile?->birth_month && $profile->birthdayProfile?->birth_day) {
                                        $birthdayLabel = sprintf('%02d/%02d', (int) $profile->birthdayProfile->birth_month, (int) $profile->birthdayProfile->birth_day);
                                    }
                                @endphp
                                <tr class="hover:bg-white/5">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="font-semibold text-emerald-100 hover:text-white">
                                            {{ $displayName }}
                                        </a>
                                        <div class="text-xs text-white/45">ID #{{ $profile->id }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-white/80">{{ $profile->email ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/80">{{ $profile->phone ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/80">${{ number_format((float) ($loyalty['candle_cash_amount'] ?? 0), 2) }}</td>
                                    <td class="px-4 py-3 text-white/80">{{ number_format((float) ($loyalty['candle_cash_amount'] ?? 0), 2) }} Candle Cash</td>
                                    <td class="px-4 py-3 text-white/80">{{ number_format((int) ($loyalty['legacy_growave_points'] ?? 0)) }}</td>
                                    <td class="px-4 py-3 text-white/80">{{ $loyalty['tier'] ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/80">{{ number_format((int) ($loyalty['referrals'] ?? 0)) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1.5">
                                            <span class="text-white/80">{{ number_format((int) ($loyalty['review_count'] ?? 0)) }}</span>
                                            @if(($loyalty['average_rating'] ?? null) !== null)
                                                <span class="text-xs text-white/55">{{ number_format((float) $loyalty['average_rating'], 2) }} avg</span>
                                            @else
                                                <span class="text-xs text-white/45">—</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-white/75">
                                        <div>{{ (int) ($stats['order_count'] ?? 0) }}</div>
                                        <div class="mt-1 text-xs text-white/45">{{ $stats['last_order_at'] ?: 'No orders' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-white/75">{{ $birthdayLabel }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1.5">
                                            @if($hasShopifySource)
                                                <span class="inline-flex rounded-full border border-blue-300/35 bg-blue-500/15 px-2 py-0.5 text-[11px] text-blue-100">Shopify</span>
                                            @endif
                                            @if(($loyalty['has_growave'] ?? false) === true)
                                                <span class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-2 py-0.5 text-[11px] text-emerald-100">Growave</span>
                                            @endif
                                            @foreach($stats['source_badges'] ?? [] as $badge)
                                                @continue(in_array($badge, ['Shopify'], true))
                                                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/60">{{ $badge }}</span>
                                            @endforeach
                                            @foreach($channels as $channel)
                                                @continue(strtolower((string) $channel) === 'shopify')
                                                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/60">{{ ucwords(str_replace('_', ' ', (string) $channel)) }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-white/60">{{ optional($profile->updated_at)->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80 hover:bg-white/10">
                                            Open
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="px-4 py-8 text-center text-white/55">
                                        No marketing profiles found for the current filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        @if(($profiles->total() ?? 0) === 0 && !empty($emptyStateDiagnostics))
            <section class="rounded-3xl border border-amber-300/35 bg-amber-500/10 p-4 sm:p-5">
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-100">Unified Profile Index Not Built</h2>
                <p class="mt-2 text-sm text-amber-50/90">
                    No marketing profiles have been built yet, but upstream Shopify/Growave/Square customer candidates exist.
                    Run profile sync to build the canonical customer index.
                </p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs text-amber-100/90">
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-500/15 px-2.5 py-1">
                        Shopify Orders: {{ number_format((int) ($emptyStateDiagnostics['shopify_order_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-500/15 px-2.5 py-1">
                        Shopify Customers: {{ number_format((int) ($emptyStateDiagnostics['shopify_customer_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-500/15 px-2.5 py-1">
                        Growave: {{ number_format((int) ($emptyStateDiagnostics['growave_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-500/15 px-2.5 py-1">
                        Square Customers: {{ number_format((int) ($emptyStateDiagnostics['square_customer_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-500/15 px-2.5 py-1">
                        Square Orders: {{ number_format((int) ($emptyStateDiagnostics['square_order_candidates'] ?? 0)) }}
                    </span>
                    <span class="inline-flex rounded-full border border-amber-200/30 bg-amber-500/15 px-2.5 py-1">
                        Square Payments: {{ number_format((int) ($emptyStateDiagnostics['square_payment_candidates'] ?? 0)) }}
                    </span>
                </div>
                <div class="mt-3 text-xs text-amber-50/80">
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
