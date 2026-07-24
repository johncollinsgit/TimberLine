@php
    $launchableTemplates = collect($templates)->filter(
        fn (array $template): bool => (bool) ($template['launchable'] ?? false)
    );
@endphp

<x-layouts::app :title="'Create workflow'">
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-5">
            <div>
                <a href="{{ route('workflows.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← Back to workflows</a>
                <h1 class="mt-3 text-2xl font-bold text-zinc-950">Create workflow</h1>
                <p class="mt-1 text-sm text-zinc-600">Choose a working starter, then connect and test both steps.</p>
            </div>
            <a href="{{ route('workflows.connections') }}" wire:navigate class="fb-btn-soft px-4 py-2 text-sm font-bold">Manage connections</a>
        </header>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
            This workspace is using the compatible workflow builder while Workflow Studio rolls out.
        </div>

        @if($launchableTemplates->isEmpty())
            <section class="rounded-lg border border-zinc-200 bg-white p-8 text-center">
                <h2 class="font-bold text-zinc-950">No executable starters are available</h2>
                <p class="mt-2 text-sm text-zinc-600">Check Connections or contact your Everbranch administrator.</p>
            </section>
        @else
            <section class="grid gap-4 md:grid-cols-2" aria-label="Executable workflow starters">
                @foreach($launchableTemplates as $key => $template)
                    <article class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center gap-3">
                            <span class="flex -space-x-1.5" aria-hidden="true">
                                <x-workflows.partials.provider-icon :provider="$template['trigger_provider']" :providers="$providers" size="sm" />
                                <x-workflows.partials.provider-icon :provider="$template['action_provider']" :providers="$providers" size="sm" />
                            </span>
                            <span class="text-xs font-bold uppercase tracking-wider text-emerald-700">Available</span>
                        </div>
                        <h2 class="mt-4 text-lg font-bold text-zinc-950">{{ $template['name'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">{{ $template['description'] }}</p>
                        <dl class="mt-4 space-y-2 text-sm">
                            <div><dt class="inline font-semibold text-zinc-900">Trigger:</dt> <dd class="inline text-zinc-600">{{ $template['trigger_event'] }}</dd></div>
                            <div><dt class="inline font-semibold text-zinc-900">Action:</dt> <dd class="inline text-zinc-600">{{ $template['action_event'] }}</dd></div>
                        </dl>
                        <form method="POST" action="{{ route('workflows.legacy.store') }}" class="mt-5">
                            @csrf
                            <input type="hidden" name="template_key" value="{{ $key }}">
                            <button class="w-full rounded-md bg-emerald-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-950">
                                Use this starter
                            </button>
                        </form>
                    </article>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts::app>
