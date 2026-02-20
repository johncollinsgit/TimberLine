<x-layouts::app :title="'Candle Club Scents'">
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
                        @include('wiki.partials.admin-article-pills', ['article' => ['slug' => 'candle-club']])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-300">Wiki</a>
                        <span class="mx-1">/</span>
                        <a href="{{ route('wiki.category', ['slug' => 'events']) }}" class="hover:text-zinc-300">Events</a>
                        <span class="mx-1">/</span>
                        <span>Candle Club Scents</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-white">Candle Club Scents</h1>
                    <p class="mt-2 text-sm text-zinc-300">Monthly Candle Club scent archive. Managed in Admin -> Candle Club.</p>
                </section>

                <section id="archive" class="rounded-3xl border border-white/10 bg-zinc-950/40 p-5">
                    <h2 class="text-lg font-semibold text-white">Archive</h2>
                    <div class="mt-4 space-y-3 md:hidden">
                        @forelse($records as $row)
                            <div class="rounded-2xl border border-white/10 bg-zinc-900/50 p-4 text-xs text-white/80">
                                <div class="font-semibold">{{ \Carbon\Carbon::create()->month($row->month)->format('F') }} {{ $row->year }}</div>
                                <div class="mt-2">Scent: <span class="text-white">{{ $row->scent?->display_name ?? $row->scent?->name ?? '—' }}</span></div>
                                <div class="mt-1">Oil Reference: {{ $row->scent?->oil_reference_name ?? '—' }}</div>
                                <div class="mt-1 text-white/50">Updated {{ optional($row->updated_at)->toDateString() ?? '—' }}</div>
                            </div>
                        @empty
                            <div class="px-3 py-3 text-xs text-white/60">No Candle Club scents recorded yet.</div>
                        @endforelse
                    </div>
                    <div class="mt-4 hidden overflow-x-auto rounded-2xl border border-white/10 md:block">
                        <div class="grid grid-cols-4 gap-0 bg-black/30 px-3 py-2 text-[11px] text-white/50">
                            <div>Month / Year</div>
                            <div>Scent</div>
                            <div>Oil Reference</div>
                            <div>Updated</div>
                        </div>
                        <div class="divide-y divide-white/10">
                            @forelse($records as $row)
                                <div class="grid grid-cols-4 gap-0 px-3 py-2 text-xs text-white/80">
                                    <div>{{ \Carbon\Carbon::create()->month($row->month)->format('F') }} {{ $row->year }}</div>
                                    <div class="font-semibold">{{ $row->scent?->display_name ?? $row->scent?->name ?? '—' }}</div>
                                    <div class="text-white/70">{{ $row->scent?->oil_reference_name ?? '—' }}</div>
                                    <div class="text-white/50">{{ optional($row->updated_at)->toDateString() ?? '—' }}</div>
                                </div>
                            @empty
                                <div class="px-3 py-3 text-xs text-white/60">No Candle Club scents recorded yet.</div>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-white/10 bg-zinc-950/40 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-300">
                        <li><a href="#overview" class="hover:text-white">Overview</a></li>
                        <li><a href="#archive" class="hover:text-white">Archive</a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
