<x-layouts::app :title="$article['title']">
    <div class="mx-auto w-full max-w-[1200px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_260px]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <article class="space-y-6">
                <section class="rounded-3xl border border-zinc-200 bg-white p-6">
                    <div class="mb-3 flex justify-end">
                        @include('wiki.partials.admin-article-pills', ['article' => $article])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-600">Wiki</a>
                        @if($category)
                            <span class="mx-1">/</span>
                            <a href="{{ route('wiki.category', ['slug' => $category['slug']]) }}" class="hover:text-zinc-600">{{ $category['title'] }}</a>
                        @endif
                        <span class="mx-1">/</span>
                        <span>{{ $article['title'] }}</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-zinc-950">{{ $article['title'] }}</h1>
                    <p class="mt-2 text-sm text-zinc-600">{{ $article['excerpt'] }}</p>
                    <div class="mt-3 text-xs text-zinc-500">Updated {{ $article['updated_at']->toDateString() }}</div>
                </section>

                @if(!empty($article['needs_details']))
                    <section class="rounded-2xl border border-amber-300/35 bg-amber-100 px-4 py-3 text-sm text-amber-900">
                        Needs details: this page is a placeholder and should be completed before operational use.
                    </section>
                @endif

                @if(!empty($article['sections']))
                    <section class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-zinc-950">Table of contents</h2>
                        <ol class="mt-3 space-y-2 text-sm text-zinc-600">
                            @foreach($article['sections'] as $section)
                                <li>
                                    <a href="#{{ $section['id'] }}" class="hover:text-zinc-950">{{ $section['title'] }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @foreach($article['sections'] as $section)
                    <section id="{{ $section['id'] }}" class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-xl font-semibold text-zinc-950">{{ $section['title'] }}</h2>

                        @if(!empty($section['paragraphs']))
                            <div class="mt-3 space-y-3 text-sm leading-6 text-zinc-700">
                                @foreach($section['paragraphs'] as $line)
                                    <p>{!! $wiki->linkify($line) !!}</p>
                                @endforeach
                            </div>
                        @endif

                        @if(!empty($section['checklist']))
                            <ul class="mt-3 space-y-2 text-sm text-zinc-700">
                                @foreach($section['checklist'] as $item)
                                    <li class="flex gap-2">
                                        <span class="mt-1 inline-block h-2.5 w-2.5 rounded-full bg-sky-300/80"></span>
                                        <span>{!! $wiki->linkify($item) !!}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if(!empty($section['quicklinks']))
                            <div class="mt-4 rounded-2xl border border-sky-300/30 bg-sky-100 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-sky-800">Quicklinks</div>
                                <ul class="mt-2 space-y-2 text-sm text-sky-800">
                                    @foreach($section['quicklinks'] as $quick)
                                        <li>{!! $wiki->linkify($quick) !!}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(!empty($section['templates']))
                            <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-zinc-400">Templates</div>
                                <ul class="mt-2 space-y-2 text-sm text-zinc-700">
                                    @foreach($section['templates'] as $template)
                                        @php
                                            $templateSlug = $template['slug'] ?? null;
                                            $templateTarget = $templateSlug ? $wiki->article($templateSlug) : null;
                                        @endphp
                                        <li>
                                            @if($templateTarget)
                                                <a href="{{ $templateTarget['url'] }}" class="text-sky-700 hover:text-sky-800 underline underline-offset-2">{{ $template['label'] }}</a>
                                            @else
                                                <span>{{ $template['label'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </section>
                @endforeach

                @if($related->isNotEmpty())
                    <section class="rounded-3xl border border-zinc-200 bg-white p-5">
                        <h2 class="text-lg font-semibold text-zinc-950">Related pages</h2>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach($related as $relatedArticle)
                                <a href="{{ $relatedArticle['url'] }}" class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 hover:border-sky-300/30">
                                    <div class="text-sm font-semibold text-zinc-950">{{ $relatedArticle['title'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-600">{{ $relatedArticle['excerpt'] }}</div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </article>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-600">
                        @foreach($article['sections'] as $section)
                            <li><a href="#{{ $section['id'] }}" class="hover:text-zinc-950">{{ $section['title'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
