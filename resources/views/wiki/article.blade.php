<x-layouts::app :title="$article['title']">
    <div class="mx-auto w-full max-w-[1200px] px-4 py-6 md:px-6">
        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_260px]">
            <aside class="hidden lg:block">
                <div class="sticky top-6">
                    @include('wiki.partials.local-nav')
                </div>
            </aside>

            <article class="space-y-6">
                <section class="rounded-3xl border border-white/10 bg-zinc-950/40 p-6">
                    <div class="mb-3 flex justify-end">
                        @include('wiki.partials.admin-article-pills', ['article' => $article])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-300">Wiki</a>
                        @if($category)
                            <span class="mx-1">/</span>
                            <a href="{{ route('wiki.category', ['slug' => $category['slug']]) }}" class="hover:text-zinc-300">{{ $category['title'] }}</a>
                        @endif
                        <span class="mx-1">/</span>
                        <span>{{ $article['title'] }}</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-white">{{ $article['title'] }}</h1>
                    <p class="mt-2 text-sm text-zinc-300">{{ $article['excerpt'] }}</p>
                    <div class="mt-3 text-xs text-zinc-500">Updated {{ $article['updated_at']->toDateString() }}</div>
                </section>

                @if(!empty($article['needs_details']))
                    <section class="rounded-2xl border border-amber-300/35 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                        Needs details: this page is a placeholder and should be completed before operational use.
                    </section>
                @endif

                @if(!empty($article['sections']))
                    <section class="rounded-3xl border border-white/10 bg-zinc-950/40 p-5">
                        <h2 class="text-lg font-semibold text-white">Table of contents</h2>
                        <ol class="mt-3 space-y-2 text-sm text-zinc-300">
                            @foreach($article['sections'] as $section)
                                <li>
                                    <a href="#{{ $section['id'] }}" class="hover:text-white">{{ $section['title'] }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @foreach($article['sections'] as $section)
                    <section id="{{ $section['id'] }}" class="rounded-3xl border border-white/10 bg-zinc-950/40 p-5">
                        <h2 class="text-xl font-semibold text-white">{{ $section['title'] }}</h2>

                        @if(!empty($section['paragraphs']))
                            <div class="mt-3 space-y-3 text-sm leading-6 text-zinc-200">
                                @foreach($section['paragraphs'] as $line)
                                    <p>{!! $wiki->linkify($line) !!}</p>
                                @endforeach
                            </div>
                        @endif

                        @if(!empty($section['checklist']))
                            <ul class="mt-3 space-y-2 text-sm text-zinc-200">
                                @foreach($section['checklist'] as $item)
                                    <li class="flex gap-2">
                                        <span class="mt-1 inline-block h-2.5 w-2.5 rounded-full bg-sky-300/80"></span>
                                        <span>{!! $wiki->linkify($item) !!}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if(!empty($section['quicklinks']))
                            <div class="mt-4 rounded-2xl border border-sky-300/30 bg-sky-500/10 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-sky-100/90">Quicklinks</div>
                                <ul class="mt-2 space-y-2 text-sm text-sky-50/95">
                                    @foreach($section['quicklinks'] as $quick)
                                        <li>{!! $wiki->linkify($quick) !!}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(!empty($section['templates']))
                            <div class="mt-4 rounded-2xl border border-white/10 bg-zinc-900/50 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-zinc-400">Templates</div>
                                <ul class="mt-2 space-y-2 text-sm text-zinc-200">
                                    @foreach($section['templates'] as $template)
                                        @php
                                            $templateSlug = $template['slug'] ?? null;
                                            $templateTarget = $templateSlug ? $wiki->article($templateSlug) : null;
                                        @endphp
                                        <li>
                                            @if($templateTarget)
                                                <a href="{{ $templateTarget['url'] }}" class="text-sky-300 hover:text-sky-200 underline underline-offset-2">{{ $template['label'] }}</a>
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
                    <section class="rounded-3xl border border-white/10 bg-zinc-950/40 p-5">
                        <h2 class="text-lg font-semibold text-white">Related pages</h2>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach($related as $relatedArticle)
                                <a href="{{ $relatedArticle['url'] }}" class="rounded-2xl border border-white/10 bg-zinc-900/50 p-4 hover:border-sky-300/30">
                                    <div class="text-sm font-semibold text-white">{{ $relatedArticle['title'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-300">{{ $relatedArticle['excerpt'] }}</div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </article>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-white/10 bg-zinc-950/40 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-300">
                        @foreach($article['sections'] as $section)
                            <li><a href="#{{ $section['id'] }}" class="hover:text-white">{{ $section['title'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
