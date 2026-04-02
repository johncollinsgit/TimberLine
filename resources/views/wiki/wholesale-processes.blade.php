<x-layouts::app :title="'Wholesale Process Index'">
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
                        @include('wiki.partials.admin-category-pills', ['category' => $category])
                    </div>
                    <nav class="text-xs text-zinc-500">
                        <a href="{{ route('wiki.index') }}" class="hover:text-zinc-600">Wiki</a>
                        <span class="mx-1">/</span>
                        <span>Wholesale Processes</span>
                    </nav>
                    <h1 class="mt-2 text-3xl font-semibold text-zinc-950">Wholesale Process Index</h1>
                    <p class="mt-2 text-sm text-zinc-600">Central index for wholesale workflows and special-case handling.</p>
                    @if($updated)
                        <div class="mt-3 text-xs text-zinc-500">Updated {{ $updated->toDateString() }}</div>
                    @endif
                </section>

                <section id="process-tiles" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Core Processes</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($processArticles as $article)
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 hover:border-sky-300/30">
                                <div class="mb-2 flex justify-end">@include('wiki.partials.admin-article-pills', ['article' => $article])</div>
                                <a href="{{ $article['url'] }}" class="block">
                                    <div class="text-sm font-semibold text-zinc-950">{{ $article['title'] }}</div>
                                    <p class="mt-2 text-xs text-zinc-600">{{ $article['excerpt'] }}</p>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section id="special-cases" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Special Cases</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($specialCases as $article)
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 hover:border-sky-300/30">
                                <div class="mb-2 flex justify-end">@include('wiki.partials.admin-article-pills', ['article' => $article])</div>
                                <a href="{{ $article['url'] }}" class="block">
                                    <div class="text-sm font-semibold text-zinc-950">{{ $article['title'] }}</div>
                                    <p class="mt-2 text-xs text-zinc-600">{{ $article['excerpt'] }}</p>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section id="cadence" class="rounded-3xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Daily / Weekly / Quarterly Rhythm</h2>
                    <div class="mt-4 grid gap-3 md:grid-cols-3">
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-sm font-semibold text-zinc-950">Daily</div>
                            <ul class="mt-2 space-y-2 text-xs text-zinc-600">
                                <li>Check inbound channels and log updates in Asana.</li>
                                <li>Review Shopify wholesale/business gift orders.</li>
                                <li>Update current-year sheets and account notes.</li>
                            </ul>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-sm font-semibold text-zinc-950">Weekly</div>
                            <ul class="mt-2 space-y-2 text-xs text-zinc-600">
                                <li>Audit pending follow-ups and stalled approvals.</li>
                                <li>Clean up labels/statuses across Gmail and Asana.</li>
                                <li>Confirm first-order onboarding progress.</li>
                            </ul>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-sm font-semibold text-zinc-950">Quarterly</div>
                            <ul class="mt-2 space-y-2 text-xs text-zinc-600">
                                <li>Run account health check and outreach plan.</li>
                                <li>Update account tiering and lifecycle notes.</li>
                                <li>Execute inactive-account deactivation workflow as needed.</li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="hidden xl:block">
                <div class="sticky top-6 rounded-2xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">On this page</div>
                    <ul class="mt-3 space-y-2 text-sm text-zinc-600">
                        <li><a href="#overview" class="hover:text-zinc-950">Overview</a></li>
                        <li><a href="#process-tiles" class="hover:text-zinc-950">Core Processes</a></li>
                        <li><a href="#special-cases" class="hover:text-zinc-950">Special Cases</a></li>
                        <li><a href="#cadence" class="hover:text-zinc-950">Rhythm</a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts::app>
