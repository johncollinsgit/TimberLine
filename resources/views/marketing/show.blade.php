<x-layouts::app :title="$currentSection['label']">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$currentSection"
            :sections="$sections"
        />

        @if($currentSectionKey === 'overview')
            @php
                $toneClasses = [
                    'emerald' => [
                        'card' => 'border-emerald-300/20 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.16),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.08),rgba(255,255,255,0.04))]',
                        'badge' => 'border-emerald-300/30 bg-emerald-400/[0.12] text-emerald-50',
                    ],
                    'sky' => [
                        'card' => 'border-sky-300/20 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.16),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.08),rgba(255,255,255,0.04))]',
                        'badge' => 'border-sky-300/30 bg-sky-400/[0.12] text-sky-50',
                    ],
                    'amber' => [
                        'card' => 'border-amber-300/20 bg-[radial-gradient(circle_at_top_left,rgba(245,158,11,0.16),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.08),rgba(255,255,255,0.04))]',
                        'badge' => 'border-amber-300/30 bg-amber-400/[0.12] text-amber-50',
                    ],
                    'rose' => [
                        'card' => 'border-rose-300/20 bg-[radial-gradient(circle_at_top_left,rgba(244,63,94,0.16),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.08),rgba(255,255,255,0.04))]',
                        'badge' => 'border-rose-300/30 bg-rose-400/[0.12] text-rose-50',
                    ],
                ];
                $heroMetrics = data_get($overviewDashboard, 'hero_metrics', []);
                $sourceCards = data_get($overviewDashboard, 'source_cards', []);
                $systemCards = data_get($overviewDashboard, 'system_cards', []);
                $bucketSummary = data_get($overviewDashboard, 'bucket_summary', []);
                $focusActions = data_get($overviewDashboard, 'focus_actions', []);
                $recentImportRuns = data_get($overviewDashboard, 'recent_import_runs', []);
                $latestShopifyRun = data_get($overviewDashboard, 'latest_shopify_run');
            @endphp

            <section class="rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.09),transparent_34%),linear-gradient(180deg,rgba(255,255,255,0.05),rgba(255,255,255,0.03))] p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.65)] backdrop-blur-xl">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.28em] text-white/50">Customer Universe</div>
                        <h2 class="mt-2 text-xl font-semibold text-white sm:text-2xl">What is actually resident in the marketing system</h2>
                        <p class="mt-2 max-w-4xl text-sm text-white/65">This overview is now built from the imported Shopify, Square, Growave, loyalty, review, and messaging data already living in Backstage. No rollout placeholders.</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-white/60">
                        Source overlap base: {{ number_format((int) data_get($overviewDashboard, 'source_overlap_total_profiles', 0)) }} canonical profiles
                    </div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($heroMetrics as $metric)
                        @php($tone = $toneClasses[$metric['tone']] ?? $toneClasses['emerald'])
                        <article class="rounded-[1.55rem] border p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_16px_36px_-28px_rgba(0,0,0,0.9)] {{ $tone['card'] }}">
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $metric['label'] }}</div>
                            <div class="mt-3 text-3xl font-semibold text-white">{{ number_format((int) $metric['value']) }}</div>
                            <p class="mt-2 text-sm leading-6 text-white/66">{{ $metric['caption'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr),minmax(360px,0.8fr)]">
                <article class="rounded-[2rem] border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4 shadow-[0_24px_60px_-46px_rgba(0,0,0,0.85)] backdrop-blur-xl">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.26em] text-white/45">Source Footprint</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">Where the customer universe is coming from</h2>
                        </div>
                        <a href="{{ route('marketing.providers-integrations') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open Provider Report</a>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        @foreach($sourceCards as $card)
                            @php($tone = $toneClasses[$card['tone']] ?? $toneClasses['emerald'])
                            <article class="rounded-[1.45rem] border p-4 {{ $tone['card'] }}">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-white">{{ $card['label'] }}</div>
                                    <span class="inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold {{ $tone['badge'] }}">Profiles</span>
                                </div>
                                <div class="mt-3 text-3xl font-semibold text-white">{{ number_format((int) $card['profiles']) }}</div>
                                <div class="mt-2 text-xs uppercase tracking-[0.22em] text-white/42">{{ number_format((int) $card['supporting_value']) }} {{ $card['supporting_label'] }}</div>
                                <p class="mt-3 text-sm leading-6 text-white/64">{{ $card['detail'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </article>

                <article class="rounded-[2rem] border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4 shadow-[0_24px_60px_-46px_rgba(0,0,0,0.85)] backdrop-blur-xl">
                    <div class="text-[11px] uppercase tracking-[0.26em] text-white/45">Sync Snapshot</div>
                    <h2 class="text-lg font-semibold text-white">Latest ingestion state</h2>

                    @if($latestShopifyRun)
                        <div class="rounded-[1.35rem] border border-emerald-300/20 bg-emerald-500/8 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-semibold text-white">Latest Shopify import</div>
                                <span class="inline-flex rounded-full border border-emerald-300/30 bg-emerald-400/10 px-2 py-1 text-[11px] font-semibold uppercase text-emerald-100">{{ strtoupper((string) ($latestShopifyRun['status'] ?? 'unknown')) }}</span>
                            </div>
                            <div class="mt-2 text-sm text-white/70">{{ strtoupper((string) ($latestShopifyRun['store'] ?? 'unknown')) }} · {{ strtoupper((string) ($latestShopifyRun['type'] ?? 'shopify')) }}</div>
                            <div class="mt-2 text-xs text-white/52">Finished: {{ $latestShopifyRun['finished_at'] ?? ($latestShopifyRun['started_at'] ?? '—') }}</div>
                        </div>
                    @endif

                    <div class="space-y-3">
                        @forelse($recentImportRuns as $run)
                            <div class="rounded-[1.35rem] border border-white/10 bg-white/5 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-white">{{ $run['source_label'] }}</div>
                                        <div class="mt-1 text-xs uppercase tracking-[0.22em] text-white/42">{{ $run['type'] }}</div>
                                    </div>
                                    <span class="inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold uppercase {{ ($run['status'] ?? '') === 'completed' ? 'border-emerald-300/30 bg-emerald-400/10 text-emerald-100' : ((($run['status'] ?? '') === 'running') ? 'border-sky-300/30 bg-sky-400/10 text-sky-100' : 'border-amber-300/30 bg-amber-400/10 text-amber-100') }}">
                                        {{ strtoupper((string) ($run['status'] ?? 'unknown')) }}
                                    </span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-white/60">
                                    @if(!is_null($run['processed']))
                                        <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Processed {{ number_format((int) $run['processed']) }}</span>
                                    @endif
                                    @if(!is_null($run['errors']))
                                        <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Errors {{ number_format((int) $run['errors']) }}</span>
                                    @endif
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">{{ $run['finished_at'] ?: ($run['updated_at'] ?: 'No timestamp') }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-[1.35rem] border border-white/10 bg-white/5 p-4 text-sm text-white/60">No marketing import runs recorded yet.</div>
                        @endforelse
                    </div>
                </article>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.15fr),minmax(360px,0.85fr)]">
                <article class="rounded-[2rem] border border-white/10 bg-black/15 p-5 sm:p-6 shadow-[0_24px_60px_-46px_rgba(0,0,0,0.85)] backdrop-blur-xl">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.26em] text-white/45">Source Overlap</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">How the canonical customer universe breaks down</h2>
                        </div>
                        <a href="{{ route('marketing.providers-integrations', ['overlap_filter' => 'all']) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open overlap dashboard</a>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach($bucketSummary as $bucket)
                            <article class="rounded-[1.35rem] border border-white/10 bg-white/5 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-white">{{ $bucket['label'] }}</div>
                                        <p class="mt-1 text-xs leading-5 text-white/58">{{ $bucket['description'] }}</p>
                                    </div>
                                    <span class="inline-flex rounded-full border border-white/10 bg-black/20 px-2 py-1 text-[11px] font-semibold text-white/72">{{ number_format((float) ($bucket['percent_of_total'] ?? 0), 1) }}%</span>
                                </div>
                                <div class="mt-4 text-3xl font-semibold text-white">{{ number_format((int) ($bucket['profile_count'] ?? 0)) }}</div>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-white/60">
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Missing both {{ number_format((int) ($bucket['missing_both_count'] ?? 0)) }}</span>
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Balance {{ number_format((int) ($bucket['total_candle_cash_balance'] ?? 0)) }}</span>
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Reviews {{ number_format((int) ($bucket['total_review_count'] ?? 0)) }}</span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </article>

                <article class="rounded-[2rem] border border-white/10 bg-black/15 p-5 sm:p-6 shadow-[0_24px_60px_-46px_rgba(0,0,0,0.85)] backdrop-blur-xl">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.26em] text-white/45">Operator Queue</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">What should be worked next</h2>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse($focusActions as $action)
                            @php($tone = $toneClasses[$action['tone']] ?? $toneClasses['emerald'])
                            <div class="rounded-[1.35rem] border p-4 {{ $tone['card'] }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-white">{{ $action['title'] }}</div>
                                        <p class="mt-2 text-sm leading-6 text-white/65">{{ $action['detail'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-3xl font-semibold text-white">{{ number_format((int) $action['metric']) }}</div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <a href="{{ $action['href'] }}" wire:navigate class="inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold {{ $tone['badge'] }}">{{ $action['cta'] }}</a>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-[1.35rem] border border-white/10 bg-white/5 p-4 text-sm text-white/60">
                                No urgent operator queues detected from the current overlap and sync state.
                            </div>
                        @endforelse
                    </div>
                </article>
            </section>

            <section class="rounded-[2rem] border border-white/10 bg-black/15 p-5 sm:p-6 shadow-[0_24px_60px_-46px_rgba(0,0,0,0.85)] backdrop-blur-xl">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.26em] text-white/45">Working Surfaces</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">The parts of marketing that are already operational</h2>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 xl:grid-cols-3">
                    @foreach($systemCards as $card)
                        @php($tone = $toneClasses[$card['tone']] ?? $toneClasses['emerald'])
                        <article class="rounded-[1.45rem] border p-4 {{ $tone['card'] }}">
                            <div class="text-[11px] uppercase tracking-[0.22em] text-white/42">{{ $card['title'] }}</div>
                            <div class="mt-3 text-sm font-semibold text-white">{{ $card['primary_label'] }}</div>
                            <div class="mt-2 text-3xl font-semibold text-white">{{ $card['primary_value'] }}</div>
                            <p class="mt-3 text-sm leading-6 text-white/65">{{ $card['secondary'] }}</p>
                            <div class="mt-4">
                                <a href="{{ $card['href'] }}" wire:navigate class="inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold {{ $tone['badge'] }}">{{ $card['cta'] }}</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @elseif($currentSectionKey === 'customers')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="Customers lives here">
                    The Customers command center now has a dedicated route with real profile management, search, and add-customer wizard flows.
                </x-admin.help-hint>
                <div>
                    <a href="{{ route('marketing.customers') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Open Customers
                    </a>
                </div>
            </section>
        @elseif($currentSectionKey === 'messages')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="How Messages works now">
                    This restores the Messages entry as a hub over the current messaging stack: Groups for curated lists, internal groups for direct sends, Campaigns for approval-driven SMS/email, and Templates for reusable copy.
                </x-admin.help-hint>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Groups</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($messagesDashboard, 'counts.groups', 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Internal Send Groups</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($messagesDashboard, 'counts.internal_groups', 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Campaigns</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($messagesDashboard, 'counts.campaigns', 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Queued Approvals</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($messagesDashboard, 'counts.queued_approvals', 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Templates</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($messagesDashboard, 'counts.templates', 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Active Templates</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($messagesDashboard, 'counts.active_templates', 0)) }}</div>
                    </article>
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Quick Actions</h2>
                        <p class="mt-1 text-sm text-white/70">Fastest paths for adding groups, drafting manual text/email copy, or starting a campaign.</p>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <a href="{{ route('marketing.send.all-opted-in') }}" wire:navigate class="rounded-2xl border border-sky-300/35 bg-sky-500/15 p-4 text-left">
                        <div class="text-sm font-semibold text-white">Send to All Opted-In</div>
                        <div class="mt-2 text-sm text-white/70">Fast lane for sending one SMS/email blast to every opted-in customer.</div>
                    </a>
                    <a href="{{ route('marketing.groups.create') }}" wire:navigate class="rounded-2xl border border-emerald-300/35 bg-emerald-500/15 p-4 text-left">
                        <div class="text-sm font-semibold text-white">Create Group</div>
                        <div class="mt-2 text-sm text-white/70">Start a curated outreach list, then add members manually or by CSV.</div>
                    </a>
                    <a href="{{ route('marketing.groups') }}" wire:navigate class="rounded-2xl border border-white/10 bg-white/5 p-4 text-left">
                        <div class="text-sm font-semibold text-white">Manage Groups</div>
                        <div class="mt-2 text-sm text-white/70">Open existing groups, review members, and use internal direct-send flows.</div>
                    </a>
                    <a href="{{ route('marketing.campaigns.create') }}" wire:navigate class="rounded-2xl border border-white/10 bg-white/5 p-4 text-left">
                        <div class="text-sm font-semibold text-white">Create Campaign</div>
                        <div class="mt-2 text-sm text-white/70">Build approval-driven SMS or email sends against segments and groups.</div>
                    </a>
                    <a href="{{ route('marketing.message-templates.create', ['channel' => 'sms']) }}" wire:navigate class="rounded-2xl border border-white/10 bg-white/5 p-4 text-left">
                        <div class="text-sm font-semibold text-white">New SMS Template</div>
                        <div class="mt-2 text-sm text-white/70">Draft reusable text copy for manual sends and campaigns.</div>
                    </a>
                    <a href="{{ route('marketing.message-templates.create', ['channel' => 'email']) }}" wire:navigate class="rounded-2xl border border-white/10 bg-white/5 p-4 text-left">
                        <div class="text-sm font-semibold text-white">New Email Template</div>
                        <div class="mt-2 text-sm text-white/70">Draft reusable email copy with preview variables.</div>
                    </a>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.25fr),minmax(0,1fr)]">
                <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Internal Direct Send Groups</h2>
                            <p class="mt-1 text-sm text-white/70">Use these for manual texts/emails without campaign approval, while still honoring consent and contact eligibility.</p>
                        </div>
                        <a href="{{ route('marketing.groups') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open Groups</a>
                    </div>
                    <div class="space-y-3">
                        @forelse(data_get($messagesDashboard, 'internal_groups', collect()) as $group)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <div class="text-base font-semibold text-white">{{ $group->name }}</div>
                                        <div class="mt-1 text-sm text-white/60">{{ $group->description ?: 'Internal messaging group.' }}</div>
                                        <div class="mt-2 text-xs text-white/45">Members: {{ number_format((int) ($group->members_count ?? 0)) }}</div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('marketing.groups.show', $group) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Open</a>
                                        <a href="{{ route('marketing.groups.send', $group) }}" wire:navigate class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/20 px-3 py-1 text-xs font-semibold text-amber-100">Manual Send</a>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/60">
                                No internal groups yet. Create one from Groups, mark it internal, then use the direct send workflow for manual texts/emails.
                            </div>
                        @endforelse
                    </div>
                </article>

                <div class="space-y-4">
                    <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Recent Campaigns</h2>
                                <p class="mt-1 text-sm text-white/70">Approval-driven sends and follow-up orchestration.</p>
                            </div>
                            <a href="{{ route('marketing.campaigns') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open Campaigns</a>
                        </div>
                        <div class="space-y-3">
                            @forelse(data_get($messagesDashboard, 'campaigns', collect()) as $campaign)
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-white">{{ $campaign->name }}</div>
                                            <div class="mt-1 text-xs text-white/50">{{ strtoupper((string) $campaign->channel) }} · {{ strtoupper((string) $campaign->status) }}</div>
                                            <div class="mt-2 text-xs text-white/45">Recipients: {{ number_format((int) ($campaign->recipients_count ?? 0)) }}</div>
                                        </div>
                                        <a href="{{ route('marketing.campaigns.show', $campaign) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Open</a>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/60">
                                    No campaigns yet. Start with a group or template, then create a campaign when you want approvals and tracking.
                                </div>
                            @endforelse
                        </div>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-white">Recent Templates</h2>
                                <p class="mt-1 text-sm text-white/70">Reusable copy blocks for SMS/email.</p>
                            </div>
                            <a href="{{ route('marketing.message-templates') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open Templates</a>
                        </div>
                        <div class="space-y-3">
                            @forelse(data_get($messagesDashboard, 'templates', collect()) as $template)
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-white">{{ $template->name }}</div>
                                            <div class="mt-1 text-xs text-white/50">{{ strtoupper((string) $template->channel) }} · {{ $template->objective ?: 'general' }}</div>
                                            <div class="mt-2 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold {{ $template->is_active ? 'border-emerald-300/30 bg-emerald-500/10 text-emerald-100' : 'border-white/10 bg-white/5 text-white/55' }}">
                                                {{ $template->is_active ? 'Active' : 'Inactive' }}
                                            </div>
                                        </div>
                                        <a href="{{ route('marketing.message-templates.edit', $template) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Edit</a>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/60">
                                    No templates yet. Create SMS or email templates here before building larger campaigns.
                                </div>
                            @endforelse
                        </div>
                    </article>
                </div>
            </section>
        @elseif($currentSectionKey === 'candle-cash')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="Redemption lifecycle">
                    `issued` codes are created when reward credit is redeemed. Shopify code usage is validated in ingestion. Square/event usage can be staff-reconciled and audited.
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
