<x-layouts::app :title="'Marketing Customers'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Marketing Customers"
            description="Unified marketing customer index derived from operational orders, Shopify ingest, Square sync, and legacy imports through the additive identity layer."
            hint-title="How this index works"
            hint-text="Profiles are derived from source records and linked by exact normalized email/phone matches. Ambiguous matches are held in Identity Review instead of auto-merged."
        />

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Birthday Capture</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((float) ($birthdayReporting['capture_rate'] ?? 0), 1) }}%</div>
                <div class="text-xs text-white/60">{{ number_format((int) ($birthdayReporting['with_birthday'] ?? 0)) }} / {{ number_format((int) ($birthdayReporting['total_profiles'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Missing Birthdays</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($birthdayReporting['missing_birthday'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Birthdays Today</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($birthdayReporting['birthdays_today'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Birthdays This Week</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($birthdayReporting['birthdays_this_week'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Rewards Issued (Year)</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($birthdayReporting['rewards_issued_this_year'] ?? 0)) }}</div>
            </article>
            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Rewards Claimed (Year)</div>
                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($birthdayReporting['rewards_claimed_this_year'] ?? 0)) }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <form method="GET" action="{{ route('marketing.customers') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <label for="search" class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                    <input
                        id="search"
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Name, email, or phone"
                        class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35"
                    />
                </div>
                <div class="md:col-span-2">
                    <label for="sort" class="text-xs uppercase tracking-[0.2em] text-white/55">Sort</label>
                    <select id="sort" name="sort" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="updated_at" @selected($sort === 'updated_at')>Updated</option>
                        <option value="created_at" @selected($sort === 'created_at')>Created</option>
                        <option value="email" @selected($sort === 'email')>Email</option>
                        <option value="first_name" @selected($sort === 'first_name')>First Name</option>
                        <option value="last_name" @selected($sort === 'last_name')>Last Name</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="dir" class="text-xs uppercase tracking-[0.2em] text-white/55">Direction</label>
                    <select id="dir" name="dir" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="desc" @selected($dir === 'desc')>Desc</option>
                        <option value="asc" @selected($dir === 'asc')>Asc</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="birthday_filter" class="text-xs uppercase tracking-[0.2em] text-white/55">Birthday Filter</label>
                    <select id="birthday_filter" name="birthday_filter" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all" @selected(($birthdayFilter ?? 'all') === 'all')>All</option>
                        <option value="today" @selected(($birthdayFilter ?? 'all') === 'today')>Birthdays Today</option>
                        <option value="week" @selected(($birthdayFilter ?? 'all') === 'week')>This Week</option>
                        <option value="month" @selected(($birthdayFilter ?? 'all') === 'month')>This Month</option>
                        <option value="missing" @selected(($birthdayFilter ?? 'all') === 'missing')>Missing</option>
                    </select>
                </div>
                <div class="md:col-span-2">
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
                    No marketing profiles have been built yet, but upstream Shopify/Growave customer candidates exist.
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
                <table class="min-w-[1450px] text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[190px]">Customer</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[190px]">Email</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[140px]">Phone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[180px]">Source Channels</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[130px]">Linked Sources</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[95px]">Orders</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[120px]">Last Order</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[120px]">Last Activity</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[130px]">Marketing Score</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[150px]">Consent</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[110px]">Updated</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap min-w-[95px]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($profiles as $profile)
                            @php
                                $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                                $displayName = $name !== '' ? $name : 'Unnamed profile';
                                $channels = is_array($profile->source_channels) ? $profile->source_channels : [];
                                $stats = $derivedStats[(int) $profile->id] ?? ['order_count' => 0, 'last_order_at' => null];
                            @endphp
                            <tr
                                class="cursor-pointer hover:bg-white/5"
                                onclick="window.location='{{ route('marketing.customers.show', $profile) }}'"
                            >
                                <td class="px-4 py-3">
                                    <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="font-semibold text-emerald-100 hover:text-white">
                                        {{ $displayName }}
                                    </a>
                                    <div class="text-xs text-white/45">ID #{{ $profile->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/80">{{ $profile->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $profile->phone ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse($channels as $channel)
                                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $channel }}</span>
                                        @empty
                                            <span class="text-white/40">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-white/75">{{ (int) $profile->links_count }}</td>
                                <td class="px-4 py-3 text-white/75">{{ (int) ($stats['order_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/75">
                                    {{ $stats['last_order_at'] ?: '—' }}
                                    @if(!empty($stats['source_badges']))
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($stats['source_badges'] as $badge)
                                                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[10px] text-white/65">{{ $badge }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/75">{{ $stats['last_activity_at'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/65">{{ $profile->marketing_score !== null ? number_format((float) $profile->marketing_score, 2) : 'Pending' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_email_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                                            Email {{ $profile->accepts_email_marketing ? 'Opt-In' : 'Opt-Out' }}
                                        </span>
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_sms_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                                            SMS {{ $profile->accepts_sms_marketing ? 'Opt-In' : 'Opt-Out' }}
                                        </span>
                                    </div>
                                </td>
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
