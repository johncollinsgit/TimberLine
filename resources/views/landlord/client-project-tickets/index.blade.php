<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Client Project Requests</h1>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-900">
                {{ session('status') }}
            </section>
        @endif

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Everbranch Admin</p>
                    <h2 class="mt-1 text-2xl font-semibold text-zinc-950">Client request triage</h2>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                        Project-aware feature, app, scope, and question tickets. Internal notes stay hidden from tenants.
                    </p>
                </div>
                <a href="{{ route('landlord.dashboard') }}" class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Back to Dashboard</a>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($filterOptions as $key => $label)
                    <a
                        href="{{ route('landlord.client-project-tickets.index', ['filter' => $key]) }}"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $activeFilter === $key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100' }}"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-5 py-4">
                <h3 class="text-base font-semibold text-zinc-950">Requests</h3>
                <p class="mt-1 text-xs text-zinc-600">Scope notes can be client-visible; landlord notes are internal only.</p>
            </div>
            <div class="divide-y divide-zinc-200">
                @forelse($tickets as $ticket)
                    <article class="p-5">
                        <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
                            <div class="space-y-3 text-sm text-zinc-700">
                                <div>
                                    <div class="text-base font-semibold text-zinc-950">{{ $ticket->title }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">
                                        Tenant: {{ $ticket->tenant?->name ?? 'Unknown tenant' }}
                                        &middot; Project: {{ $ticket->project?->title ?? 'Unknown project' }}
                                        &middot; Status: {{ $statusLabels[$ticket->status] ?? $ticket->statusLabel() }}
                                    </div>
                                </div>
                                <p class="whitespace-pre-line">{{ $ticket->problem_summary }}</p>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    @if($ticket->phase)
                                        <span class="rounded-full border border-zinc-300 px-2.5 py-1">Phase: {{ $ticket->phase->name }}</span>
                                    @endif
                                    <span class="rounded-full border border-zinc-300 px-2.5 py-1">Type: {{ $ticket->typeLabel() }}</span>
                                    <span class="rounded-full border border-zinc-300 px-2.5 py-1">Tasks: {{ $ticket->tasks->count() }}</span>
                                    <span class="rounded-full border border-zinc-300 px-2.5 py-1">References: {{ $ticket->references->count() }}</span>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('landlord.client-project-tickets.update', ['ticket' => $ticket]) }}" class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                @csrf
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Status
                                    <select name="status" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                                        @foreach($statusLabels as $key => $label)
                                            <option value="{{ $key }}" @selected($ticket->status === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Priority
                                    <select name="priority" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                                        @foreach($priorityLabels as $key => $label)
                                            <option value="{{ $key }}" @selected($ticket->priority === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Scope notes
                                    <textarea name="scope_notes" rows="3" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">{{ old('scope_notes', $ticket->scope_notes) }}</textarea>
                                </label>
                                <label class="block text-xs font-semibold text-zinc-700">
                                    Internal notes
                                    <textarea name="landlord_notes" rows="3" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">{{ old('landlord_notes', $ticket->landlord_notes) }}</textarea>
                                </label>
                                <button type="submit" class="rounded-md bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Update request</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="p-8 text-center text-sm text-zinc-500">No client project requests match this filter.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
