<x-layouts::app :title="'Wholesale Custom Scents'">
    <div class="mx-auto w-full max-w-[1200px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_250px]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <div class="space-y-6">
                <section id="overview" class="rounded-3xl border border-zinc-200 bg-white p-6">
                    <div class="mb-3 flex justify-end">
                        @include('wiki.partials.admin-article-pills', ['article' => ['slug' => 'wholesale-custom-scents']])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-600">Wiki</a>
                        <span class="mx-1">/</span>
                        <a href="{{ route('wiki.category', ['slug' => 'wholesale']) }}" class="hover:text-zinc-600">Wholesale</a>
                        <span class="mx-1">/</span>
                        <span>Wholesale Custom Scents</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-zinc-950">Wholesale Custom Scents</h1>
                    <p class="mt-2 text-sm text-zinc-600">Account-specific names mapped to canonical scents. Managed in Admin -> Wholesale Custom Scents.</p>
                </section>

                <section id="account-list" class="space-y-6 rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Accounts</h2>
                    @forelse($records as $account => $rows)
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                            <div class="text-sm font-semibold text-zinc-950">{{ $account }}</div>
                            <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200">
                                <table class="min-w-[680px] w-full text-sm">
                                    <thead class="bg-zinc-50 text-zinc-500">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Custom Scent</th>
                                            <th class="px-3 py-2 text-left">Canonical Scent</th>
                                            <th class="px-3 py-2 text-left">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200">
                                        @foreach($rows as $row)
                                            <tr class="text-zinc-700">
                                                <td class="px-3 py-2">{{ $row->custom_scent_name }}</td>
                                                <td class="px-3 py-2">{{ $row->canonicalScent?->name ?? '—' }}</td>
                                                <td class="px-3 py-2">
                                                    @if($row->canonical_scent_id)
                                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] bg-emerald-100 text-emerald-900">Mapped</span>
                                                    @else
                                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] bg-amber-100 text-amber-900">Unmapped</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 text-sm text-zinc-600">
                            No wholesale custom scents yet.
                        </div>
                    @endforelse
                </section>
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-600">
                        <li><a href="#overview" class="hover:text-zinc-950">Overview</a></li>
                        <li><a href="#account-list" class="hover:text-zinc-950">Accounts</a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
