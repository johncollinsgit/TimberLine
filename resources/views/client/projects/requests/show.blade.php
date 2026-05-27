<x-layouts::app.sidebar title="{{ $ticket->title }}">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Project Request</div>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="fb-title-xl">{{ $ticket->title }}</h1>
                        <p class="fb-subtitle">{{ $typeLabels[$ticket->type] ?? $ticket->typeLabel() }} for {{ $ticket->project?->title ?? 'client project' }}.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if($ticket->project)
                            <a href="{{ route('client.projects.show', ['project' => $ticket->project]) }}" class="fb-btn fb-btn-secondary">Project</a>
                        @endif
                        <a href="{{ route('client.projects.requests.index') }}" class="fb-btn fb-btn-secondary">All requests</a>
                    </div>
                </div>
            </header>

            @if (session('status'))
                <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
            @endif

            <div class="grid gap-6 xl:grid-cols-[1fr_0.85fr]">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Scope</div>
                            <div class="fb-panel-copy">The client-visible request summary and current review state.</div>
                        </div>
                        <span class="fb-state text-xs">{{ $statusLabels[$ticket->status] ?? $ticket->statusLabel() }}</span>
                    </div>
                    <div class="fb-panel-body space-y-4 text-sm text-zinc-700">
                        <div>
                            <div class="font-semibold text-zinc-950">Problem</div>
                            <p class="mt-1 whitespace-pre-line">{{ $ticket->problem_summary }}</p>
                        </div>
                        @if(filled($ticket->desired_outcome))
                            <div>
                                <div class="font-semibold text-zinc-950">Desired outcome</div>
                                <p class="mt-1 whitespace-pre-line">{{ $ticket->desired_outcome }}</p>
                            </div>
                        @endif
                        @if(filled($ticket->scope_notes))
                            <div>
                                <div class="font-semibold text-zinc-950">Scope notes</div>
                                <p class="mt-1 whitespace-pre-line">{{ $ticket->scope_notes }}</p>
                            </div>
                        @endif
                        <div class="grid gap-3 md:grid-cols-3">
                            <div class="fb-state">Phase: {{ $ticket->phase?->name ?? 'Not assigned' }}</div>
                            <div class="fb-state">Milestone: {{ $ticket->milestone?->title ?? 'Not assigned' }}</div>
                            <div class="fb-state">Priority: {{ $priorityLabels[$ticket->priority] ?? str($ticket->priority)->headline() }}</div>
                        </div>
                    </div>
                </section>

                <aside class="space-y-6">
                    <section class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Tasks</div>
                                <div class="fb-panel-copy">Early task breakdown for this request.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-3">
                            @forelse($ticket->tasks as $task)
                                <article class="rounded-xl border border-zinc-200 bg-white p-4">
                                    <div class="font-semibold text-zinc-950">{{ $task->title }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">Owner: {{ str($task->owner_type)->replace('_', ' ')->headline() }} &middot; Status: {{ str($task->status)->replace('_', ' ')->headline() }}</div>
                                </article>
                            @empty
                                <p class="text-sm text-zinc-600">No tasks have been added yet.</p>
                            @endforelse
                        </div>
                    </section>

                    <section class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">References</div>
                                <div class="fb-panel-copy">Links, notes, screenshots, or source material.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-3">
                            @forelse($ticket->references as $reference)
                                <article class="rounded-xl border border-zinc-200 bg-white p-4">
                                    @if($reference->url)
                                        <a href="{{ $reference->url }}" target="_blank" rel="noopener" class="font-semibold text-zinc-950 hover:text-zinc-700">{{ $reference->label }}</a>
                                    @else
                                        <div class="font-semibold text-zinc-950">{{ $reference->label }}</div>
                                    @endif
                                    @if($reference->notes)
                                        <p class="mt-1 text-sm text-zinc-600">{{ $reference->notes }}</p>
                                    @endif
                                </article>
                            @empty
                                <p class="text-sm text-zinc-600">No references have been added yet.</p>
                            @endforelse
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
