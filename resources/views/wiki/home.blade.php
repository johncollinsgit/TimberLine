<x-layouts::app :title="'Backstage Wiki'">
    <div class="mx-auto w-full max-w-[1320px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_260px]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <div class="space-y-6">
                <section id="hero" class="rounded-3xl border border-zinc-200 bg-white p-6 md:p-8">
                    @if(auth()->user()?->isAdmin())
                        <div class="mb-3 flex flex-wrap justify-end gap-2">
                            <a href="{{ route('wiki.admin.article.create') }}" class="rounded-full border border-sky-300/40 bg-sky-100 px-3 py-1 text-xs font-medium text-sky-900 hover:bg-sky-100">New Article</a>
                            <a href="{{ route('wiki.admin.category.create') }}" class="rounded-full border border-sky-300/40 bg-sky-100 px-3 py-1 text-xs font-medium text-sky-900 hover:bg-sky-100">New Category</a>
                        </div>
                    @endif
                    <div class="text-xs uppercase tracking-[0.26em] text-zinc-400">Backstage Wiki</div>
                    <h1 class="mt-2 text-3xl font-semibold text-zinc-950 md:text-4xl">Knowledge hub</h1>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">Find process docs, policy references, and operational guides across the organization.</p>

                    <form action="{{ route('wiki.index') }}" method="GET" class="mt-5">
                        <label for="wiki-search" class="sr-only">Search wiki</label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input id="wiki-search" name="q" value="{{ $query }}" placeholder="Search articles, categories, and workflows"
                                   class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 placeholder:text-zinc-500 focus:border-sky-400 focus:outline-none" />
                            <button type="submit" class="rounded-xl border border-sky-300/40 bg-sky-100 px-4 py-3 text-sm font-medium text-sky-900 hover:bg-sky-100">Search</button>
                        </div>
                    </form>
                </section>

                <section id="categories" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-zinc-950">Categories</h2>
                        <a href="{{ route('wiki.categories') }}" class="text-xs text-sky-700 hover:text-sky-800">Browse all</a>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($categories as $category)
                            <a href="{{ route('wiki.category', ['slug' => $category['slug']]) }}" class="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs text-zinc-700 hover:border-sky-300/40 hover:text-sky-900">
                                {{ $category['title'] }}
                            </a>
                        @endforeach
                    </div>
                </section>

                <section id="operations-quicklinks" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Operations Quicklinks</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <a href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="rounded-2xl border border-zinc-200 bg-emerald-100 p-4 hover:border-emerald-300/40 hover:bg-emerald-100 transition">
                            <div class="text-[11px] uppercase tracking-[0.16em] text-emerald-800">Events / Market Room</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-950">Market Room Process</div>
                            <div class="mt-2 text-xs text-emerald-800">Unpacking and packing workflow, supplies, bags, tents, and coordinator responsibilities.</div>
                        </a>
                        <a href="{{ route('wiki.wholesale-processes') }}" class="rounded-2xl border border-sky-200/20 bg-sky-100 p-4 hover:border-sky-300/40 hover:bg-sky-100 transition">
                            <div class="text-[11px] uppercase tracking-[0.16em] text-sky-800">Operations / Wholesale</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-950">Wholesale Processes</div>
                            <div class="mt-2 text-xs text-sky-800">Wholesale workflow index with structured process sections and checklists.</div>
                        </a>
                    </div>
                </section>

                @if($featured)
                    <section id="featured" class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">Featured article</div>
                        <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                @include('wiki.partials.admin-article-pills', ['article' => $featured])
                            </div>
                        <a href="{{ $featured['url'] }}" class="block hover:border-sky-300/30">
                            <h2 class="text-xl font-semibold text-zinc-950">{{ $featured['title'] }}</h2>
                            <p class="mt-2 text-sm text-zinc-600">{{ $featured['excerpt'] }}</p>
                            <div class="mt-3 text-xs text-zinc-500">Updated {{ $featured['updated_at']->toDateString() }}</div>
                        </a>
                        </div>
                    </section>
                @endif

                <section id="from-wiki" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">From the wiki</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($fromWiki as $article)
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 hover:border-sky-300/30">
                                <div class="mb-2 flex items-center justify-end">
                                    @include('wiki.partials.admin-article-pills', ['article' => $article])
                                </div>
                                <a href="{{ $article['url'] }}" class="block">
                                    <div class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">{{ $article['category_meta']['title'] ?? 'General' }}</div>
                                    <div class="mt-1 text-sm font-semibold text-zinc-950">{{ $article['title'] }}</div>
                                    <div class="mt-2 text-xs text-zinc-600">{{ $article['excerpt'] }}</div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section id="updates" class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-zinc-950">Recently updated</h2>
                        <ul class="mt-3 space-y-2">
                            @foreach($recentlyUpdated as $article)
                                <li>
                                    <a href="{{ $article['url'] }}" class="flex items-start justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 hover:border-sky-300/30">
                                        <span class="text-sm text-zinc-700">{{ $article['title'] }}</span>
                                        <span class="shrink-0 text-xs text-zinc-500">{{ $article['updated_at']->toDateString() }}</span>
                                    </a>
                                    <div class="mt-1 flex justify-end">@include('wiki.partials.admin-article-pills', ['article' => $article])</div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-zinc-950">Most viewed</h2>
                        <p class="mt-1 text-xs text-zinc-500">No analytics configured, showing most recently edited.</p>
                        <ul class="mt-3 space-y-2">
                            @foreach($popular as $article)
                                <li>
                                    <a href="{{ $article['url'] }}" class="flex items-start justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 hover:border-sky-300/30">
                                        <span class="text-sm text-zinc-700">{{ $article['title'] }}</span>
                                        <span class="shrink-0 text-xs text-zinc-500">{{ $article['updated_at']->toDateString() }}</span>
                                    </a>
                                    <div class="mt-1 flex justify-end">@include('wiki.partials.admin-article-pills', ['article' => $article])</div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>

                <section id="random" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Random article</h2>
                    @if($randomArticle)
                        <div class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="mb-2 flex justify-end">
                                @include('wiki.partials.admin-article-pills', ['article' => $randomArticle])
                            </div>
                            <div class="text-sm font-semibold text-zinc-950">{{ $randomArticle['title'] }}</div>
                            <p class="mt-1 text-xs text-zinc-600">{{ $randomArticle['excerpt'] }}</p>
                            <div class="mt-3 flex gap-2">
                                <a href="{{ $randomArticle['url'] }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs text-zinc-700 hover:border-sky-300/40">Open article</a>
                                <a href="{{ route('wiki.random') }}" class="rounded-lg border border-sky-300/40 bg-sky-100 px-3 py-1.5 text-xs text-sky-900 hover:bg-sky-100">Try another</a>
                            </div>
                        </div>
                    @endif
                </section>

                @if($query !== '')
                    <section id="search-results" class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-zinc-950">Search results for "{{ $query }}"</h2>
                        @if($searchResults->isEmpty())
                            <p class="mt-3 text-sm text-zinc-400">No results found.</p>
                        @else
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach($searchResults as $article)
                                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 hover:border-sky-300/30">
                                        <div class="mb-2 flex justify-end">@include('wiki.partials.admin-article-pills', ['article' => $article])</div>
                                        <a href="{{ $article['url'] }}" class="block">
                                            <div class="text-sm font-semibold text-zinc-950">{{ $article['title'] }}</div>
                                            <div class="mt-1 text-xs text-zinc-600">{{ $article['excerpt'] }}</div>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">Contents</div>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#hero">Search</a></li>
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#categories">Categories</a></li>
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#operations-quicklinks">Operations Quicklinks</a></li>
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#featured">Featured article</a></li>
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#from-wiki">From the wiki</a></li>
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#updates">Recently updated</a></li>
                        <li><a class="text-zinc-600 hover:text-zinc-950" href="#random">Random article</a></li>
                        @if($query !== '')
                            <li><a class="text-zinc-600 hover:text-zinc-950" href="#search-results">Search results</a></li>
                        @endif
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
