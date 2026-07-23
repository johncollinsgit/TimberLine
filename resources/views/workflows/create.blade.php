<x-layouts::app :title="'Create workflow'">
    @php
        $defaultTemplateKey = (string) (collect($templates)->search(fn (array $template): bool => (bool) ($template['launchable'] ?? false)) ?: array_key_first($templates));
        $providerOptions = collect($templates)
            ->map(fn (array $template): string => (string) $template['trigger_provider'])
            ->unique()
            ->values();
    @endphp

    <div
        class="min-h-full bg-zinc-50"
        x-data="{
            search: '',
            status: 'all',
            provider: 'all',
            selected: @js($defaultTemplateKey),
            items: @js(collect($templates)->map(fn (array $template): array => [
                'name' => $template['name'],
                'description' => $template['description'],
                'provider' => $template['trigger_provider'],
                'launchable' => (bool) ($template['launchable'] ?? false),
            ])->values()),
            matches(name, description, provider, launchable) {
                const term = this.search.trim().toLowerCase();
                const matchesSearch = !term || `${name} ${description} ${provider}`.toLowerCase().includes(term);
                const matchesStatus = this.status === 'all' || (this.status === 'ready' ? launchable : !launchable);
                const matchesProvider = this.provider === 'all' || this.provider === provider;

                return matchesSearch && matchesStatus && matchesProvider;
            },
            visibleCount() {
                return this.items.filter((item) => this.matches(item.name, item.description, item.provider, item.launchable)).length;
            },
        }"
        data-template-browser
    >
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-[1480px] items-center gap-4 px-4 py-3 sm:px-6">
                <a
                    href="{{ route('workflows.index') }}"
                    wire:navigate
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900"
                    aria-label="Back to workflows"
                >←</a>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-base font-bold text-zinc-950">Create workflow</h1>
                    <p class="text-xs text-zinc-500">Choose a starting point, then connect and test each step.</p>
                </div>
                <a href="{{ route('workflows.connections') }}" wire:navigate class="fb-btn-soft px-3 py-2 text-xs font-bold">Manage connections</a>
            </div>
        </header>

        <div class="mx-auto grid max-w-[1480px] lg:min-h-[calc(100vh-11rem)] lg:grid-cols-[236px_minmax(340px,1fr)_390px]">
            <aside class="border-b border-zinc-200 bg-white px-4 py-5 lg:border-b-0 lg:border-r lg:px-5">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.6-3.6"></path>
                    </svg>
                    <input
                        x-model.debounce.150ms="search"
                        type="search"
                        placeholder="Search templates"
                        class="w-full rounded-lg border-zinc-200 bg-zinc-50 py-2 pl-9 pr-3 text-sm placeholder:text-zinc-400 focus:border-emerald-600 focus:ring-emerald-600"
                    >
                </div>

                <nav class="mt-6 space-y-1" aria-label="Template filters">
                    <p class="mb-2 px-2 text-[10px] font-bold uppercase tracking-[0.14em] text-zinc-400">Availability</p>
                    @foreach([
                        ['all', 'All templates', count($templates)],
                        ['ready', 'Available now', collect($templates)->where('launchable', true)->count()],
                        ['preview', 'Connector preview', collect($templates)->where('launchable', false)->count()],
                    ] as [$value, $label, $count])
                        <button
                            type="button"
                            @click="status = @js($value)"
                            :class="status === @js($value) ? 'bg-zinc-100 text-zinc-950' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950'"
                            class="flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm font-semibold transition"
                        >
                            <span>{{ $label }}</span>
                            <span class="text-xs font-medium text-zinc-400">{{ $count }}</span>
                        </button>
                    @endforeach
                </nav>

                <nav class="mt-7 space-y-1" aria-label="Provider filters">
                    <p class="mb-2 px-2 text-[10px] font-bold uppercase tracking-[0.14em] text-zinc-400">Starts with</p>
                    <button
                        type="button"
                        @click="provider = 'all'"
                        :class="provider === 'all' ? 'bg-zinc-100 text-zinc-950' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950'"
                        class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm font-semibold transition"
                    >
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-zinc-200 bg-white text-xs text-zinc-500">⌘</span>
                        All apps
                    </button>
                    @foreach($providerOptions as $providerKey)
                        <button
                            type="button"
                            @click="provider = @js($providerKey)"
                            :class="provider === @js($providerKey) ? 'bg-zinc-100 text-zinc-950' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm font-semibold transition"
                        >
                            <x-workflows.partials.provider-icon :provider="$providerKey" :providers="$providers" size="sm" class="!h-6 !w-6 !rounded-md !shadow-none" />
                            {{ data_get($providers, $providerKey.'.label', str($providerKey)->headline()) }}
                        </button>
                    @endforeach
                </nav>
            </aside>

            <main class="min-w-0 border-b border-zinc-200 bg-white lg:border-b-0 lg:border-r">
                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="text-sm font-bold text-zinc-950">Workflow templates</h2>
                        <p class="mt-0.5 text-xs text-zinc-500">Built-in starting points for common work.</p>
                    </div>
                    <span class="rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-500">{{ count($templates) }} templates</span>
                </div>

                <div class="divide-y divide-zinc-100" data-template-results>
                    @foreach($templates as $key => $template)
                        @php
                            $triggerProvider = (string) $template['trigger_provider'];
                            $launchable = (bool) ($template['launchable'] ?? false);
                        @endphp
                        <button
                            type="button"
                            x-show="matches(@js($template['name']), @js($template['description']), @js($triggerProvider), @js($launchable))"
                            @click="selected = @js($key)"
                            :class="selected === @js($key) ? 'bg-emerald-50/60 before:bg-emerald-600' : 'bg-white hover:bg-zinc-50 before:bg-transparent'"
                            class="relative flex w-full items-start gap-4 px-5 py-4 text-left transition before:absolute before:inset-y-0 before:left-0 before:w-0.5 sm:px-6"
                            data-template-row="{{ $key }}"
                        >
                            <span class="flex shrink-0 items-center -space-x-1.5">
                                <x-workflows.partials.provider-icon :provider="$template['trigger_provider']" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                                <x-workflows.partials.provider-icon :provider="$template['action_provider']" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-zinc-950">{{ $template['name'] }}</span>
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-[0.1em] {{ $launchable ? 'text-emerald-700' : 'text-zinc-400' }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $launchable ? 'bg-emerald-500' : 'bg-zinc-300' }}"></span>
                                        {{ $launchable ? 'Available' : 'Preview' }}
                                    </span>
                                </span>
                                <span class="mt-1 block text-sm leading-5 text-zinc-500">{{ $template['description'] }}</span>
                                <span class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                                    <strong class="font-semibold text-zinc-700">{{ $template['trigger_event'] }}</strong>
                                    <span aria-hidden="true">→</span>
                                    <strong class="font-semibold text-zinc-700">{{ $template['action_event'] }}</strong>
                                </span>
                            </span>
                            <span class="mt-2 text-zinc-300" aria-hidden="true">›</span>
                        </button>
                    @endforeach
                </div>

                <div
                    x-show="visibleCount() === 0"
                    x-cloak
                    class="px-6 py-16 text-center"
                >
                    <p class="text-sm font-semibold text-zinc-700">No templates match those filters.</p>
                    <button type="button" @click="search = ''; status = 'all'; provider = 'all'" class="mt-2 text-sm font-bold text-emerald-700 hover:text-emerald-900">Clear filters</button>
                </div>
            </main>

            <aside class="bg-zinc-50 p-4 sm:p-6 lg:p-5">
                <div class="sticky top-5 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                    @foreach($templates as $key => $template)
                        @php
                            $launchable = (bool) ($template['launchable'] ?? false);
                            $triggerLabel = data_get($providers, $template['trigger_provider'].'.label', str($template['trigger_provider'])->headline());
                            $actionLabel = data_get($providers, $template['action_provider'].'.label', str($template['action_provider'])->headline());
                        @endphp
                        <section x-show="selected === @js($key)" x-cloak data-template-preview="{{ $key }}">
                            <div class="border-b border-zinc-200 px-5 py-4">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-zinc-400">Workflow preview</p>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $launchable ? 'text-emerald-700' : 'text-zinc-500' }}">
                                        <span class="h-2 w-2 rounded-full {{ $launchable ? 'bg-emerald-500' : 'bg-zinc-300' }}"></span>
                                        {{ $launchable ? 'Available now' : 'Connector in progress' }}
                                    </span>
                                </div>
                                <h2 class="mt-2 text-lg font-bold leading-6 text-zinc-950">{{ $template['name'] }}</h2>
                                <p class="mt-1 text-sm leading-5 text-zinc-500">{{ $template['description'] }}</p>
                            </div>

                            <div class="relative bg-zinc-50 px-5 py-7 [background-image:radial-gradient(#d4d4d8_0.8px,transparent_0.8px)] [background-size:16px_16px]">
                                <article class="relative rounded-lg border border-zinc-200 bg-white p-3.5 shadow-sm">
                                    <div class="flex items-center gap-3">
                                        <x-workflows.partials.provider-icon :provider="$template['trigger_provider']" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-400">1 · Trigger</p>
                                            <p class="truncate text-sm font-semibold text-zinc-950">{{ $template['trigger_event'] }}</p>
                                            <p class="text-xs text-zinc-500">{{ $triggerLabel }}</p>
                                        </div>
                                    </div>
                                </article>
                                <div class="mx-auto flex h-12 w-8 flex-col items-center justify-center">
                                    <span class="h-3 w-px bg-zinc-300"></span>
                                    <span class="flex h-6 w-6 items-center justify-center rounded-md border border-zinc-300 bg-white text-sm text-zinc-500">+</span>
                                    <span class="h-3 w-px bg-zinc-300"></span>
                                </div>
                                <article class="relative rounded-lg border border-zinc-200 bg-white p-3.5 shadow-sm">
                                    <div class="flex items-center gap-3">
                                        <x-workflows.partials.provider-icon :provider="$template['action_provider']" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-400">2 · Action</p>
                                            <p class="truncate text-sm font-semibold text-zinc-950">{{ $template['action_event'] }}</p>
                                            <p class="text-xs text-zinc-500">{{ $actionLabel }}</p>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <div class="border-t border-zinc-200 p-5">
                                @if($launchable)
                                    <form method="POST" action="{{ route('workflows.store') }}">
                                        @csrf
                                        <input type="hidden" name="template_key" value="{{ $key }}">
                                        <button class="w-full rounded-lg bg-zinc-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-zinc-800">
                                            Use this template
                                        </button>
                                    </form>
                                    <p class="mt-2 text-center text-xs text-zinc-500">You will connect accounts and test both steps before publishing.</p>
                                @else
                                    <button disabled class="w-full cursor-not-allowed rounded-lg bg-zinc-100 px-4 py-2.5 text-sm font-bold text-zinc-400">
                                        Connector in progress
                                    </button>
                                    <p class="mt-2 text-center text-xs leading-5 text-zinc-500">Preview the workflow now. It will become selectable after this connector passes validation.</p>
                                @endif
                            </div>
                        </section>
                    @endforeach
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
