@php
    $statusClass = static fn (string $status): string => match ($status) {
        'success', 'succeeded' => 'bg-emerald-100 text-emerald-800',
        'running', 'pending', 'delayed' => 'bg-sky-100 text-sky-800',
        'held' => 'bg-amber-100 text-amber-900',
        'discarded' => 'bg-zinc-200 text-zinc-700',
        default => 'bg-rose-100 text-rose-800',
    };
    $renderSummary = static function (mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? '—');
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
    };
    $hasV2Items = $run->items->isNotEmpty();
    $canRetry = in_array((string) $run->status, ['failed', 'partial_failure'], true)
        && (! $hasV2Items || $run->items->contains('status', \App\Models\AutomationWorkflowRunItem::STATUS_FAILED));
@endphp

<x-layouts::app :title="'Workflow run #'.$run->id">
    <div class="min-h-full bg-stone-50">
        <div class="mx-auto max-w-5xl space-y-6 px-4 py-7 sm:px-6 lg:px-8">
            <a href="{{ route('workflows.history', ['workflow' => $run->automation_workflow_id]) }}" wire:navigate class="text-sm font-bold text-zinc-600">← Back to history</a>

            <header class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span class="text-xs font-black uppercase tracking-[0.2em] text-emerald-700">Run #{{ $run->id }}</span>
                        <h1 class="mt-2 text-2xl font-black text-zinc-950">{{ $run->workflow?->name ?? 'Deleted workflow' }}</h1>
                        <p class="mt-2 text-sm text-zinc-500">
                            {{ $run->created_at->format('M j, Y \a\t g:i:s A') }}
                            · {{ str($run->mode)->headline() }}
                            @if($run->version) · Version {{ $run->version->version }} @endif
                        </p>
                    </div>
                    <span class="rounded-full px-3 py-1.5 text-xs font-black {{ $statusClass((string) $run->status) }}">{{ str($run->status)->headline() }}</span>
                </div>

                @if($run->counts)
                    <div class="mt-5 flex flex-wrap gap-2">
                        @foreach($run->counts as $key => $value)
                            @if(is_scalar($value))
                                <span class="rounded-lg bg-zinc-100 px-3 py-2 text-xs text-zinc-700"><strong>{{ str($key)->headline() }}:</strong> {{ $renderSummary($value) }}</span>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if($run->error_summary)
                    <div class="mt-5 rounded-lg border {{ $run->status === 'held' ? 'border-amber-200 bg-amber-50 text-amber-950' : 'border-rose-200 bg-rose-50 text-rose-900' }} p-4 text-sm">
                        <strong class="block">{{ $run->status === 'held' ? 'Operator review required' : 'What needs attention' }}</strong>
                        <span class="mt-1 block">{{ $run->error_summary }}</span>
                    </div>
                @endif
            </header>

            @if($hasV2Items)
                <section class="space-y-4" aria-label="Run items">
                    @foreach($run->items->sortBy('id') as $item)
                        <article class="rounded-xl border border-zinc-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 px-5 py-4">
                                <div>
                                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-zinc-400">Item #{{ $item->id }}</span>
                                    <h2 class="mt-1 font-black text-zinc-950">{{ str($item->source_system)->replace('_', ' ')->headline() }} · {{ $item->source_id }}</h2>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $item->attempt_count }} execution attempt(s)
                                        @if($item->available_at && in_array($item->status, ['pending', 'delayed'], true))
                                            · resumes {{ $item->available_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-black {{ $statusClass((string) $item->status) }}">{{ str($item->status)->headline() }}</span>
                            </div>

                            @if($item->error_summary)
                                <div class="mx-5 mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">{{ $item->error_summary }}</div>
                            @endif

                            <div class="divide-y divide-zinc-100 px-5">
                                @forelse($item->steps as $step)
                                    <div class="py-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <span class="text-[10px] font-black uppercase tracking-[0.18em] text-zinc-400">
                                                    Step {{ $step->position }} · {{ str($step->kind)->headline() }}
                                                    @if($step->branch_key) · Branch {{ $step->branch_key }} @endif
                                                    · Attempt {{ $step->attempt }}
                                                </span>
                                                <h3 class="mt-1 font-bold text-zinc-950">{{ str($step->provider)->replace('_', ' ')->headline() }}</h3>
                                            </div>
                                            <span class="rounded-full px-2.5 py-1 text-[10px] font-black {{ $statusClass((string) $step->status) }}">{{ str($step->status)->headline() }}</span>
                                        </div>

                                        @if($step->error_message)
                                            <p class="mt-3 rounded-lg bg-rose-50 p-3 text-xs text-rose-900">{{ $step->error_message }}</p>
                                        @endif

                                        @php($summary = array_filter([
                                            'Summary' => $step->summary,
                                            'Input' => $step->input_summary,
                                            'Output' => $step->output_summary,
                                        ]))
                                        @if($summary)
                                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                                @foreach($summary as $label => $values)
                                                    <div class="rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600">
                                                        <strong class="block text-zinc-900">{{ $label }}</strong>
                                                        @foreach((array) $values as $key => $value)
                                                            <div class="mt-1"><span class="font-semibold">{{ str($key)->headline() }}:</span> {{ $renderSummary($value) }}</div>
                                                        @endforeach
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="py-5 text-sm text-zinc-500">No steps have executed for this item yet.</p>
                                @endforelse
                            </div>
                        </article>
                    @endforeach
                </section>
            @else
                <section class="space-y-3" aria-label="Run steps">
                    @foreach($run->steps as $step)
                        <article class="rounded-xl border border-zinc-200 bg-white p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-zinc-400">Step {{ $step->position }} · {{ ucfirst($step->kind) }}</span>
                                    <h2 class="mt-1 font-black text-zinc-950">{{ str($step->provider)->replace('_', ' ')->headline() }}</h2>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-black {{ $statusClass((string) $step->status) }}">{{ str($step->status)->headline() }}</span>
                            </div>
                            @if($step->summary)
                                <div class="mt-4 grid gap-2 rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 sm:grid-cols-3">
                                    @foreach($step->summary as $key => $value)
                                        <div><span class="block font-black text-zinc-900">{{ str($key)->headline() }}</span>{{ $renderSummary($value) }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endif

            <div class="flex flex-wrap gap-3">
                @if($canRetry)
                    <form method="POST" action="{{ route('workflows.runs.retry', $run) }}">
                        @csrf
                        <button class="rounded-lg bg-zinc-950 px-5 py-3 text-sm font-black text-white">Retry failed items safely</button>
                    </form>
                @endif
                @if($run->workflow)
                    <a href="{{ route('workflows.show', $run->workflow) }}" wire:navigate class="rounded-lg border border-zinc-300 bg-white px-5 py-3 text-sm font-black text-zinc-900">Open workflow</a>
                @endif
            </div>
        </div>
    </div>
</x-layouts::app>
