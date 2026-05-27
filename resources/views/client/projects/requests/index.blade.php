@php
    $tenantName = (string) ($tenant->name ?? $tenant->slug ?? 'Workspace');
    $tickets = collect($tickets ?? []);
@endphp

<x-layouts::app.sidebar title="Project Requests">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Client Portal</div>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="fb-title-xl">{{ $tenantName }} Requests</h1>
                        <p class="fb-subtitle">Feature ideas, app requests, questions, and scope changes tied to active client projects.</p>
                    </div>
                    <a href="{{ route('client.projects.index') }}" class="fb-btn fb-btn-secondary">Back to projects</a>
                </div>
            </header>

            @if (session('status'))
                <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
            @endif

            <section class="fb-panel">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Request queue</div>
                        <div class="fb-panel-copy">Open a project to submit a new request against its phase, scope, and task list.</div>
                    </div>
                    <span class="fb-state text-xs">{{ $tickets->count() }} requests</span>
                </div>
                <div class="fb-panel-body space-y-3">
                    @forelse($tickets as $ticket)
                        <article class="rounded-2xl border border-zinc-200 bg-white p-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $typeLabels[$ticket->type] ?? $ticket->typeLabel() }}</div>
                                    <a href="{{ route('client.projects.requests.show', ['ticket' => $ticket]) }}" class="mt-1 block text-lg font-semibold text-zinc-950 hover:text-zinc-700">
                                        {{ $ticket->title }}
                                    </a>
                                    <div class="mt-1 text-sm text-zinc-600">
                                        Project: {{ $ticket->project?->title ?? 'Unknown project' }}
                                        @if($ticket->phase)
                                            &middot; Phase: {{ $ticket->phase->name }}
                                        @endif
                                    </div>
                                    <div class="mt-1 text-xs text-zinc-500">Status: {{ $statusLabels[$ticket->status] ?? $ticket->statusLabel() }}</div>
                                </div>
                                <a href="{{ route('client.projects.requests.show', ['ticket' => $ticket]) }}" class="fb-btn fb-btn-secondary">View request</a>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center">
                            <h2 class="text-lg font-semibold text-zinc-950">No project requests yet</h2>
                            <p class="mt-2 text-sm text-zinc-600">Open a project and use New request when you want to ask for a feature, app, change, or clarification.</p>
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
