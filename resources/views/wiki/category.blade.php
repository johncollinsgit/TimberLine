<x-layouts::app :title="$category['title']">
    <div class="mx-auto w-full max-w-[1200px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_250px]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <div class="space-y-6">
                <section id="category-top" class="rounded-3xl border border-zinc-200 bg-white p-6">
                    <div class="mb-3 flex flex-wrap justify-end gap-2">
                        @if(auth()->user()?->isAdmin())
                            <a href="{{ route('wiki.admin.article.create', ['category' => $category['slug']]) }}" class="rounded-full border border-sky-300/40 bg-sky-100 px-3 py-1 text-xs font-medium text-sky-900 hover:bg-sky-100">New Article</a>
                        @endif
                        @include('wiki.partials.admin-category-pills', ['category' => $category])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-600">Wiki</a>
                        <span class="mx-1">/</span>
                        <span>{{ $category['title'] }}</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-zinc-950">{{ $category['title'] }}</h1>
                    <p class="mt-2 text-sm text-zinc-600">{{ $category['description'] }}</p>
                </section>

                @if($subcategories->isNotEmpty())
                    <section id="subcategories" class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-zinc-950">Subcategories</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach($subcategories as $sub)
                                <a href="{{ route('wiki.category', ['slug' => $sub['slug']]) }}" class="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs text-zinc-700 hover:border-sky-300/40 hover:text-sky-900">{{ $sub['title'] }}</a>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section id="articles" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Articles</h2>
                    @if($articles->isEmpty())
                        <p class="mt-3 text-sm text-zinc-400">No articles in this category yet.</p>
                    @else
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($articles as $article)
                                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 hover:border-sky-300/30">
                                    <div class="mb-2 flex justify-end">@include('wiki.partials.admin-article-pills', ['article' => $article])</div>
                                    <a href="{{ $article['url'] }}" class="block">
                                        <div class="text-sm font-semibold text-zinc-950">{{ $article['title'] }}</div>
                                        <p class="mt-2 text-xs text-zinc-600">{{ $article['excerpt'] }}</p>
                                        <div class="mt-2 text-xs text-zinc-500">Updated {{ $article['updated_at']->toDateString() }}</div>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a href="#category-top" class="text-zinc-600 hover:text-zinc-950">Category</a></li>
                        @if($subcategories->isNotEmpty())
                            <li><a href="#subcategories" class="text-zinc-600 hover:text-zinc-950">Subcategories</a></li>
                        @endif
                        <li><a href="#articles" class="text-zinc-600 hover:text-zinc-950">Articles</a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
