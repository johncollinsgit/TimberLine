<x-layouts::app :title="'Wholesale Custom Scents'">
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
                        @include('wiki.partials.admin-article-pills', ['article' => ['slug' => 'wholesale-custom-scents']])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-300">Wiki</a>
                        <span class="mx-1">/</span>
                        <a href="{{ route('wiki.category', ['slug' => 'wholesale']) }}" class="hover:text-zinc-300">Wholesale</a>
                        <span class="mx-1">/</span>
                        <span>Wholesale Custom Scents</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-white">Wholesale Custom Scents</h1>
                    <p class="mt-2 text-sm text-zinc-300">Account-specific names mapped to canonical scents. Managed in Admin -> Wholesale Custom Scents.</p>
                </section>

                <section id="account-list" class="space-y-6 rounded-3xl border border-white/10 bg-zinc-950/40 p-5">
                    <h2 class="text-lg font-semibold text-white">Accounts</h2>
                    @forelse($records as $account => $rows)
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/50 p-5">
                            <div class="text-sm font-semibold text-white">{{ $account }}</div>
                            <div class="mt-3 overflow-x-auto rounded-xl border border-white/10">
                                <table class="min-w-[680px] w-full text-sm">
                                    <thead class="bg-black/30 text-white/60">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Custom Scent</th>
                                            <th class="px-3 py-2 text-left">Canonical Scent</th>
                                            <th class="px-3 py-2 text-left">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/10">
                                        @foreach($rows as $row)
                                            <tr class="text-white/80">
                                                <td class="px-3 py-2">{{ $row->custom_scent_name }}</td>
                                                <td class="px-3 py-2">{{ $row->canonicalScent?->name ?? '—' }}</td>
                                                <td class="px-3 py-2">
                                                    @if($row->canonical_scent_id)
                                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] bg-emerald-500/20 text-emerald-100">Mapped</span>
                                                    @else
                                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] bg-amber-500/20 text-amber-100">Unmapped</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/50 p-5 text-sm text-zinc-300">
                            No wholesale custom scents yet.
                        </div>
                    @endforelse
                </section>
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-white/10 bg-zinc-950/40 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-300">
                        <li><a href="#overview" class="hover:text-white">Overview</a></li>
                        <li><a href="#account-list" class="hover:text-white">Accounts</a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
