@php
    use Carbon\CarbonImmutable;
    use Carbon\CarbonPeriod;

    $tenantName = (string) ($tenant->name ?? $tenant->slug ?? 'Workspace');
    $statusClass = function (?string $status): string {
        return match ($status) {
            'complete' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'in_progress' => 'border-sky-200 bg-sky-50 text-sky-800',
            'blocked' => 'border-rose-200 bg-rose-50 text-rose-800',
            'review' => 'border-amber-200 bg-amber-50 text-amber-800',
            default => 'border-zinc-200 bg-zinc-50 text-zinc-700',
        };
    };
    $barClass = function (?string $status): string {
        return match ($status) {
            'complete' => 'bg-emerald-500',
            'blocked' => 'bg-rose-500',
            'review' => 'bg-amber-500',
            'in_progress' => 'bg-[var(--fb-accent)]',
            default => 'bg-zinc-400',
        };
    };
    $datesFromModels = collect([$project])
        ->merge($project->phases)
        ->merge($project->milestones)
        ->flatMap(fn ($item) => [$item->starts_on, $item->due_on])
        ->filter();
    $timelineStart = $datesFromModels->isNotEmpty()
        ? CarbonImmutable::parse($datesFromModels->min())->startOfDay()->subDays(2)
        : CarbonImmutable::now()->startOfDay()->subDays(3);
    $timelineEnd = $datesFromModels->isNotEmpty()
        ? CarbonImmutable::parse($datesFromModels->max())->startOfDay()->addDays(5)
        : CarbonImmutable::now()->startOfDay()->addDays(18);
    if ($timelineEnd->lessThan($timelineStart->addDays(14))) {
        $timelineEnd = $timelineStart->addDays(14);
    }
    $dates = collect(CarbonPeriod::create($timelineStart, $timelineEnd))->values();
    $timelineRows = collect()
        ->merge($project->phases->map(fn ($phase) => [
            'type' => 'Phase',
            'title' => $phase->name,
            'status' => (string) $phase->status,
            'starts_on' => $phase->starts_on,
            'due_on' => $phase->due_on,
            'summary' => $phase->summary,
        ]))
        ->merge($project->milestones->map(fn ($milestone) => [
            'type' => 'Milestone',
            'title' => $milestone->title,
            'status' => (string) $milestone->status,
            'starts_on' => $milestone->starts_on ?: $milestone->due_on,
            'due_on' => $milestone->due_on ?: $milestone->starts_on,
            'summary' => $milestone->summary,
        ]));
    $nextMilestone = $project->milestones
        ->filter(fn ($milestone) => ! in_array((string) $milestone->status, ['complete'], true))
        ->sortBy(fn ($milestone) => optional($milestone->due_on)->timestamp ?? PHP_INT_MAX)
        ->first();
@endphp

<x-layouts::app.sidebar title="{{ $project->title }}">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Client Project</div>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="fb-title-xl">{{ $project->title }}</h1>
                        <p class="fb-subtitle">{{ $project->summary ?: 'Progress, milestones, links, and client-visible updates for '.$tenantName.'.' }}</p>
                    </div>
                    <a href="{{ route('client.projects.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        All projects
                    </a>
                </div>

                <div class="fb-metric-grid">
                    <div class="fb-metric">
                        <div class="fb-metric-label">Status</div>
                        <div class="fb-metric-value">{{ $statusLabels[$project->status] ?? ucfirst(str_replace('_', ' ', (string) $project->status)) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Health</div>
                        <div class="fb-metric-value">{{ $healthLabels[$project->health] ?? 'On track' }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Next milestone</div>
                        <div class="fb-metric-value">{{ $nextMilestone?->title ?? 'None' }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Due</div>
                        <div class="fb-metric-value">{{ optional($project->due_on)->format('M j') ?: 'TBD' }}</div>
                    </div>
                </div>
            </header>

            <section class="fb-panel mb-6">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Timeline</div>
                        <div class="fb-panel-copy">A simple Gantt-style view of phases and milestones.</div>
                    </div>
                    <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClass((string) $project->status) }}">
                        {{ $statusLabels[$project->status] ?? 'Planning' }}
                    </span>
                </div>

                <div class="fb-panel-body">
                    @if($timelineRows->isNotEmpty())
                        <div data-gantt-scroll data-gantt-snap="today" class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
                            <div class="min-w-max">
                                <div class="grid border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-500" style="grid-template-columns: 180px repeat({{ $dates->count() }}, 42px);">
                                    <div class="sticky left-0 z-10 border-r border-zinc-200 bg-zinc-50 px-3 py-2">Work</div>
                                    @foreach($dates as $date)
                                        <div data-gantt-day data-date="{{ $date->toDateString() }}" class="border-r border-zinc-200 px-1 py-2 text-center">
                                            <div>{{ $date->format('M j') }}</div>
                                            <div class="text-[10px] font-medium">{{ $date->format('D') }}</div>
                                        </div>
                                    @endforeach
                                </div>

                                @foreach($timelineRows as $row)
                                    @php
                                        $rowStart = $row['starts_on'] ? CarbonImmutable::parse($row['starts_on'])->startOfDay() : $timelineStart;
                                        $rowEnd = $row['due_on'] ? CarbonImmutable::parse($row['due_on'])->startOfDay() : $rowStart;
                                        if ($rowEnd->lessThan($rowStart)) {
                                            $rowEnd = $rowStart;
                                        }
                                        $startIndex = max(0, (int) $timelineStart->diffInDays($rowStart, false));
                                        $endIndex = min($dates->count() - 1, max($startIndex, (int) $timelineStart->diffInDays($rowEnd, false)));
                                        $span = max(1, $endIndex - $startIndex + 1);
                                    @endphp
                                    <div class="grid min-h-16 border-b border-zinc-100" style="grid-template-columns: 180px repeat({{ $dates->count() }}, 42px);">
                                        <div class="sticky left-0 z-10 border-r border-zinc-200 bg-white px-3 py-3">
                                            <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-400">{{ $row['type'] }}</div>
                                            <div class="mt-1 text-sm font-semibold text-zinc-900">{{ $row['title'] }}</div>
                                        </div>
                                        <div class="self-center rounded-full px-3 py-2 text-xs font-semibold text-white {{ $barClass($row['status']) }}" style="grid-column: {{ $startIndex + 2 }} / span {{ $span }};">
                                            {{ $statusLabels[$row['status']] ?? ucfirst(str_replace('_', ' ', $row['status'])) }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">
                            Timeline details have not been posted yet.
                        </div>
                    @endif
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[1fr_0.85fr]">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Phases and milestones</div>
                            <div class="fb-panel-copy">The current shape of the work.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-4">
                        @forelse($project->phases as $phase)
                            <article class="rounded-2xl border border-zinc-200 bg-white p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 class="font-semibold text-zinc-950">{{ $phase->name }}</h2>
                                        <p class="mt-1 text-sm text-zinc-600">{{ $phase->summary ?: 'No phase summary yet.' }}</p>
                                    </div>
                                    <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClass((string) $phase->status) }}">
                                        {{ $statusLabels[$phase->status] ?? ucfirst(str_replace('_', ' ', (string) $phase->status)) }}
                                    </span>
                                </div>
                                <div class="mt-3 h-2 rounded-full bg-zinc-100">
                                    <div class="h-2 rounded-full bg-[var(--fb-accent)]" style="width: {{ max(0, min(100, (int) $phase->percent_complete)) }}%"></div>
                                </div>
                                @if($phase->milestones->isNotEmpty())
                                    <div class="mt-4 space-y-2">
                                        @foreach($phase->milestones as $milestone)
                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm">
                                                <span class="font-semibold text-zinc-900">{{ $milestone->title }}</span>
                                                <span class="text-xs text-zinc-600">{{ optional($milestone->due_on)->format('M j, Y') ?: 'TBD' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @empty
                            <p class="text-sm text-zinc-600">No phases have been posted yet.</p>
                        @endforelse
                    </div>
                </section>

                <aside class="space-y-6">
                    <section class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Recent updates</div>
                                <div class="fb-panel-copy">Client-visible status notes.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-3">
                            @forelse($project->updates as $update)
                                <article class="rounded-xl border border-zinc-200 bg-white p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                        {{ optional($update->published_at ?? $update->created_at)->format('M j, Y') }}
                                    </div>
                                    <h3 class="mt-1 font-semibold text-zinc-950">{{ $update->title }}</h3>
                                    <p class="mt-1 text-sm text-zinc-600">{{ $update->body }}</p>
                                </article>
                            @empty
                                <p class="text-sm text-zinc-600">No client-visible updates have been posted yet.</p>
                            @endforelse
                        </div>
                    </section>

                    <section class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Links and deliverables</div>
                                <div class="fb-panel-copy">Shared project resources.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-3">
                            @forelse($project->links as $link)
                                <a href="{{ $link->url }}" target="_blank" rel="noopener" class="block rounded-xl border border-zinc-200 bg-white p-4 hover:bg-zinc-50">
                                    <div class="font-semibold text-zinc-950">{{ $link->label }}</div>
                                    @if($link->description)
                                        <p class="mt-1 text-sm text-zinc-600">{{ $link->description }}</p>
                                    @endif
                                </a>
                            @empty
                                <p class="text-sm text-zinc-600">No links have been posted yet.</p>
                            @endforelse
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
