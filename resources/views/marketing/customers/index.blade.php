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

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Total Customers</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['total_customers'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Shopify-Linked</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['shopify_linked'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square-Linked</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['square_linked'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Growave Linked</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['growave_linked'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Missing Email</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['missing_email'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Missing Phone</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($quickStats['missing_phone'] ?? 0)) }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-white">Manage Customers</h2>
                    <div class="mt-1 text-sm text-white/65">{{ number_format((int) ($profiles->total() ?? 0)) }} result{{ (int) ($profiles->total() ?? 0) === 1 ? '' : 's' }}</div>
                </div>
                <a href="{{ route('marketing.customers.create') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                    Add Customer
                </a>
            </div>

            <form method="GET" action="{{ route('marketing.customers') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <label for="search" class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                    <input
                        id="search"
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Name, email, phone, source ID"
                        class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35"
                    />
                </div>
                <div class="md:col-span-2">
                    <label for="source" class="text-xs uppercase tracking-[0.2em] text-white/55">Source</label>
                    <select id="source" name="source" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all" @selected(($sourceFilter ?? 'all') === 'all')>All</option>
                        <option value="shopify" @selected(($sourceFilter ?? 'all') === 'shopify')>Shopify</option>
                        <option value="growave" @selected(($sourceFilter ?? 'all') === 'growave')>Growave</option>
                        <option value="square" @selected(($sourceFilter ?? 'all') === 'square')>Square</option>
                        <option value="wholesale" @selected(($sourceFilter ?? 'all') === 'wholesale')>Wholesale</option>
                        <option value="event" @selected(($sourceFilter ?? 'all') === 'event')>Event</option>
                        <option value="manual" @selected(($sourceFilter ?? 'all') === 'manual')>Manual</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="has_points" class="text-xs uppercase tracking-[0.2em] text-white/55">Has Points</label>
                    <select id="has_points" name="has_points" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all" @selected(($hasPointsFilter ?? 'all') === 'all')>All</option>
                        <option value="yes" @selected(($hasPointsFilter ?? 'all') === 'yes')>Yes</option>
                        <option value="no" @selected(($hasPointsFilter ?? 'all') === 'no')>No</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="has_phone" class="text-xs uppercase tracking-[0.2em] text-white/55">Has Phone</label>
                    <select id="has_phone" name="has_phone" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all" @selected(($hasPhoneFilter ?? 'all') === 'all')>All</option>
                        <option value="yes" @selected(($hasPhoneFilter ?? 'all') === 'yes')>Yes</option>
                        <option value="no" @selected(($hasPhoneFilter ?? 'all') === 'no')>No</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label for="sort" class="text-xs uppercase tracking-[0.2em] text-white/55">Sort</label>
                    <select id="sort" name="sort" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="updated_at" @selected($sort === 'updated_at')>Updated</option>
                        <option value="created_at" @selected($sort === 'created_at')>Created</option>
                        <option value="email" @selected($sort === 'email')>Email</option>
                        <option value="first_name" @selected($sort === 'first_name')>First</option>
                        <option value="last_name" @selected($sort === 'last_name')>Last</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label for="dir" class="text-xs uppercase tracking-[0.2em] text-white/55">Dir</label>
                    <select id="dir" name="dir" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="desc" @selected($dir === 'desc')>Desc</option>
                        <option value="asc" @selected($dir === 'asc')>Asc</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label for="per_page" class="text-xs uppercase tracking-[0.2em] text-white/55">Rows</label>
                    <select id="per_page" name="per_page" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        @foreach([25, 50, 100] as $rowCount)
                            <option value="{{ $rowCount }}" @selected($perPage === $rowCount)>{{ $rowCount }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-1 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-white">
                        Apply
                    </button>
                </div>
            </form>
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

        <section class="rounded-3xl border border-white/10 bg-black/15 p-2 sm:p-3">
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-[1650px] text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[220px]">Customer</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[180px]">Email</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[150px]">Phone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[95px]">Points</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[110px]">Tier</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[100px]">Referrals</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[220px]">Source Badges</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[130px]">Linked Sources</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[95px]">Orders</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[120px]">Last Order</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[110px]">Updated</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap min-w-[95px]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($profiles as $profile)
                            @php
                                $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                                $displayName = $name !== '' ? $name : ($profile->email ?: ($profile->phone ?: 'Unnamed profile'));
                                $stats = $derivedStats[(int) $profile->id] ?? ['order_count' => 0, 'last_order_at' => null, 'source_badges' => []];
                                $loyalty = $loyaltyStats[(int) $profile->id] ?? ['points' => 0, 'tier' => null, 'referrals' => 0, 'has_growave' => false];
                            @endphp
                            <tr class="cursor-pointer hover:bg-white/5" onclick="window.location='{{ route('marketing.customers.show', $profile) }}'">
                                <td class="px-4 py-3">
                                    <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="font-semibold text-emerald-100 hover:text-white">
                                        {{ $displayName }}
                                    </a>
                                    <div class="text-xs text-white/45">ID #{{ $profile->id }}</div>
                                    <div class="mt-1 text-xs text-white/50">
                                        @if($profile->email)
                                            {{ $profile->email }}
                                        @elseif($profile->phone)
                                            {{ $profile->phone }}
                                        @else
                                            No direct contact info
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-white/80">{{ $profile->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $profile->phone ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80">{{ number_format((int) ($loyalty['points'] ?? 0)) }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $loyalty['tier'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80">{{ number_format((int) ($loyalty['referrals'] ?? 0)) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse(($stats['source_badges'] ?? []) as $badge)
                                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/80">{{ $badge }}</span>
                                        @empty
                                            <span class="text-white/40">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-white/75">{{ (int) $profile->links_count }}</td>
                                <td class="px-4 py-3 text-white/75">{{ (int) ($stats['order_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $stats['last_order_at'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($profile->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80 hover:bg-white/10">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="px-4 py-8 text-center text-white/55">
                                    No marketing profiles found for the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-2 pt-4">
                {{ $profiles->links() }}
            </div>
        </section>
    </div>
</x-layouts::app>
