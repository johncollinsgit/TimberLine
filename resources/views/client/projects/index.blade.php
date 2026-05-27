@php
    $tenantName = (string) ($tenant->name ?? $tenant->slug ?? 'Workspace');
    $projects = collect($projects ?? []);
    $activeProjects = $projects->whereNotIn('status', ['complete'])->count();
    $blockedProjects = $projects->where('health', 'blocked')->count();
    $latestUpdates = $projects
        ->flatMap(fn ($project) => $project->updates->take(1)->map(fn ($update) => ['project' => $project, 'update' => $update]))
        ->sortByDesc(fn ($row) => optional($row['update']->published_at ?? $row['update']->created_at)->timestamp ?? 0)
        ->take(3)
        ->values();
    $statusClass = function (?string $status): string {
        return match ($status) {
            'complete' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'in_progress' => 'border-sky-200 bg-sky-50 text-sky-800',
            'blocked' => 'border-rose-200 bg-rose-50 text-rose-800',
            'review' => 'border-amber-200 bg-amber-50 text-amber-800',
            default => 'border-zinc-200 bg-zinc-50 text-zinc-700',
        };
    };
@endphp

<x-layouts::app.sidebar title="Client Projects">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Client Portal</div>
                <h1 class="fb-title-xl">{{ $tenantName }} Projects</h1>
                <p class="fb-subtitle">A clear view of active builds, milestones, status notes, and what is coming next.</p>

                <div class="fb-metric-grid">
                    <div class="fb-metric">
                        <div class="fb-metric-label">Projects</div>
                        <div class="fb-metric-value">{{ number_format($projects->count()) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Active</div>
                        <div class="fb-metric-value">{{ number_format($activeProjects) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Needs attention</div>
                        <div class="fb-metric-value">{{ number_format($blockedProjects) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Recent updates</div>
                        <div class="fb-metric-value">{{ number_format($latestUpdates->count()) }}</div>
                    </div>
                </div>
            </header>

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Project progress</div>
                            <div class="fb-panel-copy">Open a project to see phases, milestones, timeline, links, and recent updates.</div>
                        </div>
                    </div>

                    <div class="fb-panel-body space-y-4">
                        @forelse($projects as $project)
                            @php
                                $nextMilestone = $project->milestones
                                    ->filter(fn ($milestone) => ! in_array((string) $milestone->status, ['complete'], true))
                                    ->sortBy(fn ($milestone) => optional($milestone->due_on)->timestamp ?? PHP_INT_MAX)
                                    ->first();
                                $completeCount = $project->milestones->where('status', 'complete')->count();
                                $milestoneCount = max(1, $project->milestones->count());
                                $percent = (int) round($completeCount / $milestoneCount * 100);
                            @endphp
                            <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <a href="{{ route('client.projects.show', ['project' => $project]) }}" class="text-lg font-semibold text-zinc-950 hover:text-zinc-700">
                                            {{ $project->title }}
                                        </a>
                                        <p class="mt-1 max-w-3xl text-sm text-zinc-600">{{ $project->summary ?: 'No project summary has been posted yet.' }}</p>
                                    </div>
                                    <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClass((string) $project->status) }}">
                                        {{ $statusLabels[$project->status] ?? ucfirst(str_replace('_', ' ', (string) $project->status)) }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-3 md:grid-cols-3">
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Health</div>
                                        <div class="mt-1 font-semibold text-zinc-900">{{ $healthLabels[$project->health] ?? 'On track' }}</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Due</div>
                                        <div class="mt-1 font-semibold text-zinc-900">{{ optional($project->due_on)->format('M j, Y') ?: 'Not scheduled' }}</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Next milestone</div>
                                        <div class="mt-1 font-semibold text-zinc-900">{{ $nextMilestone?->title ?? 'No open milestones' }}</div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="mb-1 flex justify-between text-xs font-semibold text-zinc-600">
                                        <span>Milestone completion</span>
                                        <span>{{ $percent }}%</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-zinc-100">
                                        <div class="h-2 rounded-full bg-[var(--fb-accent)]" style="width: {{ $percent }}%"></div>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center">
                                <h2 class="text-lg font-semibold text-zinc-950">No client projects yet</h2>
                                <p class="mt-2 text-sm text-zinc-600">Once Evergrove adds a project, progress and updates will appear here.</p>
                            </div>
                        @endforelse
                    </div>
                </section>

                <aside class="space-y-6">
                    <section class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Latest updates</div>
                                <div class="fb-panel-copy">Recent client-visible notes across active projects.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-3">
                            @forelse($latestUpdates as $row)
                                <article class="rounded-xl border border-zinc-200 bg-white p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['project']->title }}</div>
                                    <h3 class="mt-1 font-semibold text-zinc-950">{{ $row['update']->title }}</h3>
                                    <p class="mt-1 text-sm text-zinc-600">{{ $row['update']->body }}</p>
                                </article>
                            @empty
                                <p class="text-sm text-zinc-600">No updates have been posted yet.</p>
                            @endforelse
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
