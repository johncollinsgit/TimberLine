<x-layouts::app :title="$workflow->name">
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-8 sm:px-6">
        <a href="{{ route('workflows.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← Back to workflows</a>
        <section class="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-zinc-500">Workflow Studio</p>
            <h1 class="mt-2 text-2xl font-bold text-zinc-950">{{ $workflow->name }}</h1>
            <p class="mt-3 text-sm leading-6 text-zinc-600">
                Workflow Studio is not enabled for this workspace. The saved workflow and its published version have not been changed.
                Restore the workspace rollout before editing or resuming it.
            </p>
            <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-zinc-500">Status</dt><dd class="font-semibold text-zinc-950">{{ str($workflow->status)->headline() }}</dd></div>
                <div><dt class="text-zinc-500">Published version</dt><dd class="font-semibold text-zinc-950">{{ $workflow->publishedVersion?->version ?? 'Not published' }}</dd></div>
            </dl>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('workflows.history', ['workflow' => $workflow->id]) }}" wire:navigate class="fb-btn-soft px-4 py-2 text-sm font-bold">View run history</a>
                <a href="{{ route('workflows.connections') }}" wire:navigate class="fb-btn-soft px-4 py-2 text-sm font-bold">Connections</a>
                @if($workflow->status === 'active')
                    <form method="POST" action="{{ route('workflows.pause', $workflow) }}">
                        @csrf
                        <button class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-bold text-amber-950">Pause workflow</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</x-layouts::app>
