<x-layouts::app :title="'Create automation'">
    <div class="min-h-full bg-stone-50">
        <div class="mx-auto max-w-6xl space-y-7 px-4 py-7 sm:px-6 lg:px-8">
            <a href="{{ route('workflows.index') }}" wire:navigate class="text-sm font-bold text-zinc-600 hover:text-zinc-950">← Back to workflows</a>
            <header><span class="text-xs font-black uppercase tracking-[0.2em] text-emerald-700">Guided templates</span><h1 class="mt-2 text-3xl font-black text-zinc-950">What should Everbranch automate?</h1><p class="mt-2 text-zinc-600">Start with a proven two-step workflow. You can connect your own accounts before publishing.</p></header>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach($templates as $key => $template)
                    <article class="flex flex-col rounded-3xl border {{ ($template['launchable'] ?? false) ? 'border-zinc-200 bg-white shadow-lg shadow-zinc-900/5' : 'border-zinc-200 bg-zinc-100/70' }} p-5">
                        <div class="flex items-center justify-between">
                            <div class="flex -space-x-2"><x-workflows.partials.provider-icon :provider="$template['trigger_provider']" :providers="$providers" size="lg" /><x-workflows.partials.provider-icon :provider="$template['action_provider']" :providers="$providers" size="lg" /></div>
                            <span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ ($template['launchable'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-white text-zinc-500' }}">{{ ($template['launchable'] ?? false) ? 'Ready' : 'Connector beta' }}</span>
                        </div>
                        <h2 class="mt-5 text-lg font-black text-zinc-950">{{ $template['name'] }}</h2>
                        <p class="mt-2 flex-1 text-sm leading-6 text-zinc-600">{{ $template['description'] }}</p>
                        <div class="mt-5 space-y-2 rounded-2xl bg-stone-50 p-3 text-xs text-zinc-700"><div><strong>When:</strong> {{ $template['trigger_event'] }}</div><div><strong>Then:</strong> {{ $template['action_event'] }}</div></div>
                        <form method="POST" action="{{ route('workflows.store') }}" class="mt-4">@csrf<input type="hidden" name="template_key" value="{{ $key }}" /><button @disabled(!($template['launchable'] ?? false)) class="w-full rounded-xl px-4 py-2.5 text-sm font-bold {{ ($template['launchable'] ?? false) ? 'bg-zinc-950 text-white hover:bg-zinc-800' : 'cursor-not-allowed bg-zinc-200 text-zinc-500' }}">{{ ($template['launchable'] ?? false) ? 'Use this template' : 'Connection support in progress' }}</button></form>
                    </article>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts::app>
