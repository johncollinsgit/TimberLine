<x-layouts::app :title="'Wiki Categories'">
    <div class="mx-auto w-full max-w-[1200px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <div class="space-y-6">
                <section class="rounded-3xl border border-white/10 bg-zinc-950/40 p-6">
                    @if(auth()->user()?->isAdmin())
                        <div class="mb-3 flex justify-end">
                            <a href="{{ route('wiki.admin.category.create') }}" class="rounded-full border border-sky-300/40 bg-sky-500/15 px-3 py-1 text-xs font-medium text-sky-100 hover:bg-sky-500/25">New Category</a>
                        </div>
                    @endif
                    <div class="text-xs uppercase tracking-[0.26em] text-zinc-400">Wiki</div>
                    <h1 class="mt-2 text-3xl font-semibold text-white">Categories</h1>
                    <p class="mt-2 text-sm text-zinc-300">Browse wiki content by category.</p>
                </section>

                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($categories as $category)
                        <div class="rounded-2xl border border-white/10 bg-zinc-950/40 p-5 hover:border-sky-300/30">
                            <div class="mb-3 flex justify-end">@include('wiki.partials.admin-category-pills', ['category' => $category])</div>
                            <a href="{{ route('wiki.category', ['slug' => $category['slug']]) }}" class="block">
                                <div class="text-base font-semibold text-white">{{ $category['title'] }}</div>
                                <p class="mt-2 text-xs text-zinc-300">{{ $category['description'] }}</p>
                                <div class="mt-3 text-xs text-zinc-500">View category</div>
                            </a>
                        </div>
                    @endforeach
                </section>
            </div>
        </div>
    </div>
</x-layouts::app>
