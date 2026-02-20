<x-layouts::app :title="'Oil Blend Recipes'">
    <div class="mx-auto w-full max-w-[1200px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_250px]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <div class="space-y-6">
                <section id="overview" class="rounded-3xl border border-white/10 bg-zinc-950/40 p-6">
                    <div class="mb-3 flex justify-end">
                        @include('wiki.partials.admin-article-pills', ['article' => ['slug' => 'oil-blends']])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-300">Wiki</a>
                        <span class="mx-1">/</span>
                        <a href="{{ route('wiki.category', ['slug' => 'production']) }}" class="hover:text-zinc-300">Production</a>
                        <span class="mx-1">/</span>
                        <span>Oil Blend Recipes</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-white">Oil Blend Recipes</h1>
                    <p class="mt-2 text-sm text-zinc-300">Global blend definitions used by the pouring room.</p>
                </section>

                <section id="blend-list" class="space-y-4 rounded-3xl border border-white/10 bg-zinc-950/40 p-5">
                    <h2 class="text-lg font-semibold text-white">Blends</h2>
                    @forelse($blends as $blend)
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/50 p-5">
                            <div class="text-white font-semibold">{{ $blend->name }}</div>
                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                @forelse($blend->components as $component)
                                    <div class="flex items-center justify-between rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-xs text-white/80">
                                        <div>{{ $component->baseOil?->name ?? 'Unknown oil' }}</div>
                                        <div class="text-zinc-300">Weight {{ $component->ratio_weight }}</div>
                                    </div>
                                @empty
                                    <div class="text-xs text-white/60">No components yet.</div>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/50 p-5 text-sm text-zinc-300">
                            No blends yet.
                        </div>
                    @endforelse
                </section>
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-white/10 bg-zinc-950/40 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-300">
                        <li><a href="#overview" class="hover:text-white">Overview</a></li>
                        <li><a href="#blend-list" class="hover:text-white">Blends</a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
