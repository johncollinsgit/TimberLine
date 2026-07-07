<x-app-layout>
    @php
        // Full class strings (not built dynamically) so Tailwind's JIT keeps them.
        $toneBadge = [
            'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'rose' => 'border-rose-200 bg-rose-50 text-rose-800',
            'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
            'sky' => 'border-sky-200 bg-sky-50 text-sky-800',
            'violet' => 'border-violet-200 bg-violet-50 text-violet-800',
            'zinc' => 'border-zinc-200 bg-zinc-100 text-zinc-700',
        ];
        $toneDot = [
            'emerald' => 'bg-emerald-500',
            'rose' => 'bg-rose-500',
            'amber' => 'bg-amber-500',
            'zinc' => 'bg-zinc-400',
        ];

        $sched = (array) ($status['scheduler'] ?? []);
        $backup = (array) ($status['backup'] ?? []);
        $issues = (array) ($status['issues'] ?? []);
        $import = (array) ($status['import'] ?? []);

        $schedOnline = (bool) ($sched['online'] ?? false);
        $schedKnown = (bool) ($sched['known'] ?? false);
        $schedLast = $sched['last_at'] ?? null;
        $threshold = (int) ($sched['threshold_minutes'] ?? 10);
        $statusTone = $schedOnline ? 'emerald' : ($schedKnown ? 'rose' : 'amber');
        $statusText = $schedOnline ? 'Online' : ($schedKnown ? 'Stalled' : 'Unknown');

        $backupAt = $backup['at'] ?? null;
        $importAt = $import['at'] ?? null;

        $issueTotal = (int) ($issues['total'] ?? 0);
        $bySeverity = (array) ($issues['by_severity'] ?? []);
        $issueTone = $issueTotal === 0 ? 'emerald' : ($issueTotal > 3 ? 'rose' : 'amber');

        $impactTone = ['high' => 'rose', 'medium' => 'amber', 'low' => 'sky'];

        // Detail payloads for the health cards (shown in the popup).
        $healthCards = [
            [
                'key' => 'status',
                'kicker' => 'System status',
                'title' => $statusText,
                'tone' => $statusTone,
                'blurb' => $schedOnline
                    ? 'Scheduler is ticking on time — imports, reminders, and health checks are running.'
                    : ($schedKnown ? 'The scheduler has not ticked recently. Cron-driven work may be stalled.' : 'No scheduler heartbeat recorded yet.'),
                'chips' => array_values(array_filter([
                    $statusText,
                    $schedLast ? 'last tick '.$schedLast->diffForHumans() : null,
                    'threshold '.$threshold.'m',
                ])),
                'body' => "The scheduler heartbeat (`scheduler:heartbeat`) stamps a timestamp every minute. If organic traffic sees a heartbeat older than {$threshold} minutes, a health event is raised.\n\n".
                    ($schedLast ? 'Last heartbeat: '.$schedLast->toDayDateTimeString().' ('.$schedLast->diffForHumans().').' : 'No heartbeat has been recorded yet — confirm the cron is running php artisan schedule:run every minute on the server.'),
            ],
            [
                'key' => 'backups',
                'kicker' => 'Backups',
                'title' => $backupAt ? $backupAt->format('M j, Y') : 'Awaiting first report',
                'tone' => $backupAt ? 'emerald' : 'amber',
                'blurb' => $backupAt
                    ? 'Last database backup was confirmed '.$backupAt->diffForHumans().'.'
                    : 'Daily backups are configured in Forge, but no completion has been reported to this dashboard yet.',
                'chips' => array_values(array_filter([
                    $backupAt ? 'confirmed '.$backupAt->diffForHumans() : 'not yet reported',
                    'daily · Forge',
                ])),
                'body' => "Automated database backups run daily via Laravel Forge (Business plan) to object storage.\n\n".
                    ($backupAt
                        ? 'Last confirmed backup: '.$backupAt->toDayDateTimeString().'.'
                        : "To light this up, call `php artisan ops:record-backup` from Forge's \"run a command after backup\" hook (Server → Database → Backups). It stamps the timestamp shown here so you can see, at a glance, that backups are actually completing."),
            ],
            [
                'key' => 'errors',
                'kicker' => 'Error tracking',
                'title' => $issueTotal === 0 ? 'All clear' : $issueTotal.' open',
                'tone' => $issueTone,
                'blurb' => $issueTotal === 0
                    ? 'No open integration health events right now.'
                    : $issueTotal.' open integration health event'.($issueTotal === 1 ? '' : 's').' need attention.',
                'chips' => array_values(array_filter([
                    ($bySeverity['error'] ?? 0).' error',
                    ($bySeverity['warning'] ?? 0).' warning',
                    ($bySeverity['info'] ?? 0).' info',
                ])),
                'body' => "Live signal from the integration_health_events table — Shopify imports, webhooks, scheduler, and other integrations open an event when something breaks and auto-resolve when it recovers.\n\n".
                    ($issueTotal === 0
                        ? 'Nothing open right now. Deeper application error tracking (Sentry) is on the vision board as the next observability layer.'
                        : 'Review open events with `php artisan integration-health:list-open`. Deeper application error tracking (Sentry) is on the vision board as the next observability layer.'),
            ],
        ];
    @endphp

    <div class="space-y-6" x-data="{ detail: null }" @keydown.escape.window="detail = null">
        {{-- Header --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Everbranch · Operator</p>
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-950">Developer Control Center</h1>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                        Live system health, the recent autonomous changes made to the platform, and the ideas queued up next.
                        Tap any card for the full story.
                    </p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold {{ $toneBadge[$statusTone] }}">
                    <span class="h-2 w-2 rounded-full {{ $toneDot[$statusTone] }}"></span>
                    System {{ strtolower($statusText) }}
                </span>
            </div>
        </section>

        {{-- Status metric strip --}}
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Status</p>
                <p class="mt-2 flex items-center gap-2 text-2xl font-semibold text-zinc-950">
                    <span class="h-2.5 w-2.5 rounded-full {{ $toneDot[$statusTone] }}"></span>{{ $statusText }}
                </p>
                <p class="mt-1 text-xs text-zinc-500">Scheduler heartbeat</p>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Last backup</p>
                <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $backupAt ? $backupAt->format('M j, Y') : '—' }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ $backupAt ? $backupAt->diffForHumans() : 'Not reported yet' }}</p>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Open issues</p>
                <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($issueTotal) }}</p>
                <p class="mt-1 text-xs text-zinc-500">Integration health</p>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Last import</p>
                <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $importAt ? $importAt->diffForHumans(short: true) : '—' }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ $import['store'] ?? 'Shopify orders' }}</p>
            </article>
        </section>

        {{-- Health cards (clickable) --}}
        <section class="grid gap-4 lg:grid-cols-3">
            @foreach ($healthCards as $card)
                <article
                    role="button" tabindex="0"
                    @click="detail = @js([
                        'kicker' => $card['kicker'],
                        'title' => $card['title'],
                        'chips' => $card['chips'],
                        'body' => $card['body'],
                        'meta' => null,
                    ])"
                    @keydown.enter="$el.click()" @keydown.space.prevent="$el.click()"
                    class="cursor-pointer rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-zinc-300"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ $card['kicker'] }}</p>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[0.7rem] font-semibold {{ $toneBadge[$card['tone']] }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $toneDot[$card['tone']] ?? $toneDot['zinc'] }}"></span>
                            {{ $card['title'] }}
                        </span>
                    </div>
                    <p class="mt-3 text-sm text-zinc-600">{{ $card['blurb'] }}</p>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ($card['chips'] as $chip)
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-[0.7rem] font-medium text-zinc-600">{{ $chip }}</span>
                        @endforeach
                    </div>
                    <p class="mt-4 text-xs font-semibold text-zinc-400">View details →</p>
                </article>
            @endforeach
        </section>

        {{-- Production readiness checklist --}}
        @php
            $checklistTotal = $checklist->count();
            $donePct = $checklistTotal > 0 ? (int) round(($checklistDone / $checklistTotal) * 100) : 0;
            $statusIcon = [
                'done' => ['tone' => 'emerald', 'mark' => '✓', 'label' => 'Done'],
                'partial' => ['tone' => 'amber', 'mark' => '~', 'label' => 'In progress'],
                'todo' => ['tone' => 'rose', 'mark' => '✕', 'label' => 'Not started'],
            ];
            $iconTone = [
                'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                'amber' => 'border-amber-200 bg-amber-50 text-amber-700',
                'rose' => 'border-rose-200 bg-rose-50 text-rose-600',
            ];
        @endphp
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Production readiness</h2>
                    <p class="mt-1 text-sm text-zinc-600">The checklist a grown-up software system is expected to have. Completed items become checkmarks; shipping a vision-board idea moves it here.</p>
                </div>
                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{{ $checklistDone }} / {{ $checklistTotal }} complete</span>
            </div>

            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-zinc-100">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $donePct }}%"></div>
            </div>

            <div class="mt-6 grid gap-6 md:grid-cols-2">
                @foreach ($checklist->groupBy('category') as $category => $items)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ $category }}</p>
                        <ul class="mt-3 space-y-1.5">
                            @foreach ($items as $item)
                                @php
                                    $meta = $statusIcon[$item->status] ?? $statusIcon['todo'];
                                    $payload = [
                                        'kicker' => 'Readiness · '.$meta['label'],
                                        'title' => $item->label,
                                        'chips' => [$meta['label'], $item->category],
                                        'body' => $item->detail ?? '',
                                        'meta' => null,
                                    ];
                                @endphp
                                <li
                                    role="button" tabindex="0"
                                    @click="detail = @js($payload)"
                                    @keydown.enter="$el.click()" @keydown.space.prevent="$el.click()"
                                    class="group flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 transition hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-zinc-200"
                                >
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[0.7rem] font-bold {{ $iconTone[$meta['tone']] }}">{{ $meta['mark'] }}</span>
                                    <span class="text-sm {{ $item->status === 'done' ? 'text-zinc-700' : 'text-zinc-900' }}">{{ $item->label }}</span>
                                    @if ($item->status === 'partial')
                                        <span class="ml-auto rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[0.65rem] font-semibold text-amber-700">in progress</span>
                                    @elseif ($item->status === 'todo')
                                        <span class="ml-auto rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[0.65rem] font-semibold text-zinc-500">to do</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Recent agentic changes --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Recent changes</h2>
                    <p class="mt-1 text-sm text-zinc-600">What the agents have changed on the system, newest first. Tap a ticket for the full write-up.</p>
                </div>
                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-medium text-zinc-600">{{ $changes->count() }} tracked</span>
            </div>

            @if ($changes->isEmpty())
                <p class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-center text-sm text-zinc-500">
                    No changes recorded yet. Run <span class="font-mono">php artisan db:seed --class=DeveloperDashboardSeeder</span> to populate the log.
                </p>
            @else
                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($changes as $change)
                        @php
                            $changedAt = $change->changed_at;
                            $chips = array_values(array_filter([
                                $change->category,
                                $change->status,
                                $change->impact ? $change->impact.' impact' : null,
                            ]));
                            $payload = [
                                'kicker' => 'Change · '.($changedAt ? $changedAt->format('M j, Y') : ''),
                                'title' => $change->title,
                                'chips' => $chips,
                                'body' => $change->summary,
                                'meta' => $change->reference ? 'Reference: '.$change->reference : null,
                            ];
                        @endphp
                        <article
                            role="button" tabindex="0"
                            @click="detail = @js($payload)"
                            @keydown.enter="$el.click()" @keydown.space.prevent="$el.click()"
                            class="flex h-full cursor-pointer flex-col rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-zinc-300"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500">{{ $change->category }}</span>
                                <span class="text-[0.7rem] text-zinc-400">{{ $changedAt ? $changedAt->format('M j') : '' }}</span>
                            </div>
                            <h3 class="mt-2 text-sm font-semibold text-zinc-950">{{ $change->title }}</h3>
                            <p class="mt-1.5 line-clamp-3 text-xs leading-relaxed text-zinc-600">{{ \Illuminate\Support\Str::limit($change->summary, 150) }}</p>
                            <p class="mt-auto pt-3 text-[0.7rem] font-semibold text-zinc-400">Read change →</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Vision board --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Vision board — what's next</h2>
                    <p class="mt-1 text-sm text-zinc-600">Candidate next steps from the most recent system analysis. Tap an idea for the pitch.</p>
                </div>
                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-medium text-zinc-600">{{ $ideas->count() }} ideas</span>
            </div>

            @if ($ideas->isEmpty())
                <p class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-center text-sm text-zinc-500">
                    No ideas recorded yet. Run <span class="font-mono">php artisan db:seed --class=DeveloperDashboardSeeder</span> to populate the board.
                </p>
            @else
                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($ideas as $idea)
                        @php
                            $tone = $impactTone[$idea->impact] ?? 'zinc';
                            $payload = [
                                'kicker' => 'Idea · '.ucfirst($idea->category),
                                'title' => $idea->title,
                                'chips' => array_values(array_filter([
                                    $idea->impact ? ucfirst($idea->impact).' impact' : null,
                                    $idea->effort ? ucfirst($idea->effort).' effort' : null,
                                ])),
                                'body' => $idea->pitch,
                                'meta' => $idea->source ? 'Source: '.$idea->source : null,
                            ];
                        @endphp
                        <article
                            role="button" tabindex="0"
                            @click="detail = @js($payload)"
                            @keydown.enter="$el.click()" @keydown.space.prevent="$el.click()"
                            class="flex h-full cursor-pointer flex-col rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-zinc-300"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[0.65rem] font-semibold {{ $toneBadge[$tone] }}">
                                    {{ ucfirst($idea->impact) }} impact
                                </span>
                                <span class="text-[0.7rem] text-zinc-400">{{ ucfirst($idea->effort) }} effort</span>
                            </div>
                            <h3 class="mt-2 text-sm font-semibold text-zinc-950">{{ $idea->title }}</h3>
                            <p class="mt-1.5 line-clamp-3 text-xs leading-relaxed text-zinc-600">{{ \Illuminate\Support\Str::limit($idea->pitch, 150) }}</p>
                            <p class="mt-auto pt-3 text-[0.7rem] font-semibold text-zinc-400">See the pitch →</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <p class="px-1 text-xs text-zinc-400">
            Operator-only. Read surface — this dashboard reports status and history; it does not deploy, mutate tenants, or change billing.
        </p>

        {{-- Shared detail popup --}}
        <template x-if="detail">
            <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4"
                 style="position: fixed; inset: 0;"
                 @click.self="detail = null">
                <div class="w-full max-w-2xl rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl"
                     x-trap.noscroll="detail">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500" x-text="detail.kicker"></p>
                            <h3 class="mt-1 text-xl font-semibold text-zinc-950" x-text="detail.title"></h3>
                        </div>
                        <button type="button" @click="detail = null"
                                class="rounded-full border border-zinc-200 p-1.5 text-zinc-500 hover:bg-zinc-100"
                                aria-label="Close">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <template x-for="chip in (detail.chips || [])" :key="chip">
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-[0.7rem] font-medium text-zinc-600" x-text="chip"></span>
                        </template>
                    </div>

                    <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-zinc-700" x-text="detail.body"></p>

                    <template x-if="detail.meta">
                        <p class="mt-4 border-t border-zinc-100 pt-3 font-mono text-xs text-zinc-500" x-text="detail.meta"></p>
                    </template>
                </div>
            </div>
        </template>
    </div>
</x-app-layout>
