<x-layouts::app :title="$currentSection['label']">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">Marketing</div>
                    <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">{{ $currentSection['label'] }}</h1>
                    <p class="mt-2 text-sm text-white/70 max-w-3xl">{{ $currentSection['description'] }}</p>
                </div>
                <a href="{{ route('marketing.overview') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
                    Open Marketing Overview
                </a>
            </div>
        </section>

        <x-admin.help-hint :title="$currentSection['hint_title']">
            {{ $currentSection['hint_text'] }}
        </x-admin.help-hint>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5">
            <div class="text-[11px] uppercase tracking-[0.28em] text-white/55">Marketing Sections</div>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($sections as $section)
                    <a
                        href="{{ $section['href'] }}"
                        wire:navigate
                        class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $section['current'] ? 'border-emerald-300/40 bg-emerald-500/20 text-emerald-50' : 'border-white/10 bg-white/5 text-white/80 hover:bg-white/10' }}"
                    >
                        {{ $section['label'] }}
                    </a>
                @endforeach
            </div>
        </section>

        @if($currentSectionKey === 'overview')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <div class="text-[11px] uppercase tracking-[0.28em] text-white/55">Current Rollout Status</div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($overviewCards as $card)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <h2 class="text-sm font-semibold text-white">{{ $card['title'] }}</h2>
                            <p class="mt-2 text-xs text-white/75">{{ $card['what'] }}</p>
                            <div class="mt-3 rounded-xl border border-white/10 bg-black/20 p-3">
                                <div class="text-[10px] uppercase tracking-[0.2em] text-white/55">Current Status</div>
                                <div class="mt-1 text-xs text-white/80">{{ $card['status'] }}</div>
                            </div>
                            <div class="mt-3 text-xs text-white/65">
                                <span class="font-semibold text-white/80">Future Stage:</span>
                                {{ $card['next'] }}
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-white">Coming in later stages</h2>
                <ul class="mt-3 space-y-2 text-sm text-white/75">
                    @foreach($currentSection['coming_next'] as $item)
                        <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @elseif($currentSectionKey === 'customers')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="Customers lives here">
                    The Customers command center now has a dedicated route with real profile management, search, and add-customer wizard flows.
                </x-admin.help-hint>
                <div>
                    <a href="{{ route('marketing.customers') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Open Customers Command Center
                    </a>
                </div>
            </section>
        @elseif($currentSectionKey === 'candle-cash')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="Redemption lifecycle">
                    `issued` codes are created when points are spent. Shopify code usage is validated in ingestion. Square/event usage can be staff-reconciled and audited.
                </x-admin.help-hint>
                <div>
                    <a href="{{ route('marketing.operations.reconciliation') }}" wire:navigate class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-4 py-2 text-sm font-semibold text-amber-100">
                        Open Reconciliation Operations
                    </a>
                    <div class="mt-2 text-xs text-white/60">Shopify widget/public events logged in last 24h: {{ number_format((int) ($candleCashDashboard['widget_events_24h'] ?? 0)) }}</div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Profiles</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($candleCashDashboard['profiles_count'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Issued Codes</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($candleCashDashboard['outstanding_issued'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Redeemed</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) (($candleCashDashboard['status_breakdown']['redeemed'] ?? 0))) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Canceled / Expired</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) (($candleCashDashboard['status_breakdown']['canceled'] ?? 0) + ($candleCashDashboard['status_breakdown']['expired'] ?? 0))) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Open Ops Issues</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($candleCashDashboard['unresolved_issues_open'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Reward-Assisted Orders</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($candleCashDashboard['reward_assisted_orders'] ?? 0)) }}</div>
                    </article>
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <h2 class="text-lg font-semibold text-white">Recent Redemptions</h2>
                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left">Code</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Platform</th>
                                <th class="px-4 py-3 text-left">Reward</th>
                                <th class="px-4 py-3 text-left">Profile</th>
                                <th class="px-4 py-3 text-left">External Order</th>
                                <th class="px-4 py-3 text-left">Updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse(($candleCashDashboard['recent_redemptions'] ?? collect()) as $redemption)
                                <tr>
                                    <td class="px-4 py-3 text-white/75 font-mono">{{ $redemption->redemption_code }}</td>
                                    <td class="px-4 py-3 text-white/70">{{ strtoupper((string) ($redemption->status ?: 'issued')) }}</td>
                                    <td class="px-4 py-3 text-white/70">{{ strtoupper((string) ($redemption->platform ?: 'n/a')) }}</td>
                                    <td class="px-4 py-3 text-white/70">{{ $redemption->reward?->name ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/70">
                                        @if($redemption->profile)
                                            <a href="{{ route('marketing.customers.show', $redemption->profile) }}" wire:navigate class="underline decoration-dotted">
                                                {{ trim(($redemption->profile->first_name ?? '') . ' ' . ($redemption->profile->last_name ?? '')) ?: ($redemption->profile->email ?: ($redemption->profile->phone ?: ('Profile #' . $redemption->marketing_profile_id))) }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-white/60">{{ $redemption->external_order_source ?: '—' }}{{ $redemption->external_order_id ? (' · ' . $redemption->external_order_id) : '' }}</td>
                                    <td class="px-4 py-3 text-white/55">{{ optional($redemption->updated_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-6 text-center text-white/55">No redemptions recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-white">Coming in later stages</h2>
                <p class="mt-2 text-sm text-white/70">
                    Stage 1 intentionally reserves this page while we establish safe foundations for identity, permissions, and integration mapping.
                </p>
                <ul class="mt-4 space-y-2 text-sm text-white/75">
                    @foreach($currentSection['coming_next'] as $item)
                        <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts::app>
