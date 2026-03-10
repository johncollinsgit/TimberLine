<x-layouts::app :title="$currentSection['label']">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">Marketing</div>
                    <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">{{ $currentSection['label'] }}</h1>
                    <p class="mt-2 text-sm text-white/70 max-w-3xl">{{ $currentSection['description'] }}</p>
                </div>
                <a href="{{ route('marketing.overview') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
                    Open Marketing Overview
                </a>
            </div>
        </section>

        <x-admin.help-hint :title="$currentSection['hint_title']">
            {{ $currentSection['hint_text'] }}
        </x-admin.help-hint>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5">
            <div class="text-[11px] uppercase tracking-[0.28em] text-white/55">Marketing Sections</div>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($sections as $section)
                    <a
                        href="{{ $section['href'] }}"
                        wire:navigate
                        class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $section['current'] ? 'border-emerald-300/40 bg-emerald-500/20 text-emerald-50' : 'border-white/10 bg-white/5 text-white/80 hover:bg-white/10' }}"
                    >
                        {{ $section['label'] }}
                    </a>
                @endforeach
            </div>
        </section>

        @if($currentSectionKey === 'overview')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <div class="text-[11px] uppercase tracking-[0.28em] text-white/55">Stage 1 Status Map</div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($overviewCards as $card)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <h2 class="text-sm font-semibold text-white">{{ $card['title'] }}</h2>
                            <p class="mt-2 text-xs text-white/75">{{ $card['what'] }}</p>
                            <div class="mt-3 rounded-xl border border-white/10 bg-black/20 p-3">
                                <div class="text-[10px] uppercase tracking-[0.2em] text-white/55">Current Status</div>
                                <div class="mt-1 text-xs text-white/80">{{ $card['status'] }}</div>
                            </div>
                            <div class="mt-3 text-xs text-white/65">
                                <span class="font-semibold text-white/80">Future Stage:</span>
                                {{ $card['next'] }}
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-white">Coming in later stages</h2>
                <ul class="mt-3 space-y-2 text-sm text-white/75">
                    @foreach($currentSection['coming_next'] as $item)
                        <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @elseif($currentSectionKey === 'customers')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="Customers roadmap">
                    This page will become the unified marketing customer index that links identity, commerce behavior, event activity, rewards state, and messaging history.
                </x-admin.help-hint>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($customersFocusAreas as $area)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <h2 class="text-sm font-semibold text-white">{{ $area['title'] }}</h2>
                            <p class="mt-2 text-xs text-white/75">{{ $area['detail'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <h2 class="text-lg font-semibold text-white">Planned capabilities (later stages)</h2>
                <ul class="space-y-2 text-sm text-white/75">
                    <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">Search and filtering across unified customer profiles.</li>
                    <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">Customer CRUD operations and detailed profile drill-down views.</li>
                    <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">Identity conflict review workflow with manual resolution tools.</li>
                    <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">Prefilled send-message actions tied to campaign and template systems.</li>
                </ul>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-white">Discovery Summary</h2>
                    <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1 text-[11px] uppercase tracking-[0.2em] text-white/60">Safe read-only snapshot</span>
                </div>
                @if($customersDiscoverySummary !== [])
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach($customersDiscoverySummary as $item)
                            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-xs uppercase tracking-[0.2em] text-white/55">{{ $item['label'] }}</div>
                                <div class="mt-2 text-2xl font-semibold text-white">{{ number_format($item['value']) }}</div>
                                <p class="mt-2 text-xs text-white/70">{{ $item['note'] }}</p>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/70">
                        Discovery counts are not available in the current runtime context.
                    </div>
                @endif
            </section>
        @else
            <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-white">Coming in later stages</h2>
                <p class="mt-2 text-sm text-white/70">
                    Stage 1 intentionally reserves this page while we establish safe foundations for identity, permissions, and integration mapping.
                </p>
                <ul class="mt-4 space-y-2 text-sm text-white/75">
                    @foreach($currentSection['coming_next'] as $item)
                        <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts::app>
