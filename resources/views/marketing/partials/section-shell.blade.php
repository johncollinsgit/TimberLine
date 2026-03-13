@props([
    'section',
    'sections',
    'title' => null,
    'description' => null,
    'hintTitle' => null,
    'hintText' => null,
])

@php
    $sectionGroups = \App\Support\Marketing\MarketingSectionRegistry::groupNavigationItems($sections);
    $accentStyles = [
        'emerald' => [
            'dot' => 'bg-emerald-300 shadow-[0_0_0_5px_rgba(52,211,153,0.12)]',
            'panel' => 'border-emerald-300/15 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'panel_current' => 'border-emerald-300/25 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.22),transparent_56%),linear-gradient(180deg,rgba(255,255,255,0.10),rgba(255,255,255,0.05))]',
            'pill_current' => 'border-emerald-300/45 bg-emerald-400/15 text-emerald-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(16,185,129,0.85)]',
        ],
        'sky' => [
            'dot' => 'bg-sky-300 shadow-[0_0_0_5px_rgba(125,211,252,0.12)]',
            'panel' => 'border-sky-300/15 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'panel_current' => 'border-sky-300/25 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.22),transparent_56%),linear-gradient(180deg,rgba(255,255,255,0.10),rgba(255,255,255,0.05))]',
            'pill_current' => 'border-sky-300/45 bg-sky-400/15 text-sky-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(14,165,233,0.85)]',
        ],
        'amber' => [
            'dot' => 'bg-amber-300 shadow-[0_0_0_5px_rgba(252,211,77,0.12)]',
            'panel' => 'border-amber-300/15 bg-[radial-gradient(circle_at_top_left,rgba(245,158,11,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'panel_current' => 'border-amber-300/25 bg-[radial-gradient(circle_at_top_left,rgba(245,158,11,0.22),transparent_56%),linear-gradient(180deg,rgba(255,255,255,0.10),rgba(255,255,255,0.05))]',
            'pill_current' => 'border-amber-300/45 bg-amber-400/15 text-amber-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(245,158,11,0.85)]',
        ],
        'rose' => [
            'dot' => 'bg-rose-300 shadow-[0_0_0_5px_rgba(253,164,175,0.12)]',
            'panel' => 'border-rose-300/15 bg-[radial-gradient(circle_at_top_left,rgba(244,63,94,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'panel_current' => 'border-rose-300/25 bg-[radial-gradient(circle_at_top_left,rgba(244,63,94,0.22),transparent_56%),linear-gradient(180deg,rgba(255,255,255,0.10),rgba(255,255,255,0.05))]',
            'pill_current' => 'border-rose-300/45 bg-rose-400/15 text-rose-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(244,63,94,0.85)]',
        ],
    ];
@endphp

<section class="rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.12),transparent_35%),linear-gradient(180deg,rgba(255,255,255,0.09),rgba(255,255,255,0.04))] p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)] backdrop-blur-xl">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">Marketing</div>
            <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">{{ $title ?: $section['label'] }}</h1>
            <p class="mt-2 text-sm text-white/70 max-w-3xl">{{ $description ?: $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.overview') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
            Open Overview
        </a>
    </div>
</section>

<x-admin.help-hint :title="$hintTitle ?: $section['hint_title']">
    {{ $hintText ?: $section['hint_text'] }}
</x-admin.help-hint>

<section class="rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.08),transparent_48%),linear-gradient(180deg,rgba(17,24,39,0.55),rgba(10,14,24,0.78))] p-4 sm:p-5 shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_24px_60px_-44px_rgba(0,0,0,0.8)] backdrop-blur-xl">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.28em] text-white/50">Pages</div>
            <div class="mt-1 text-sm text-white/68">Grouped so it is easier to find things fast.</div>
        </div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-white/35">Menu</div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
        @foreach($sectionGroups as $group)
            @php
                $accent = $accentStyles[$group['accent']] ?? $accentStyles['emerald'];
            @endphp
            <article class="rounded-[1.55rem] border p-3 sm:p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_16px_34px_-26px_rgba(0,0,0,0.9)] backdrop-blur-xl {{ $group['current'] ? $accent['panel_current'] : $accent['panel'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.28em] text-white/48">{{ $group['label'] }}</div>
                        <p class="mt-1 text-xs leading-5 text-white/60">{{ $group['description'] }}</p>
                    </div>
                    <span class="mt-1 h-2.5 w-2.5 rounded-full {{ $accent['dot'] }}"></span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($group['items'] as $item)
                        <a
                            href="{{ $item['href'] }}"
                            wire:navigate
                            class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition backdrop-blur-md {{ $item['current'] ? $accent['pill_current'] : 'border-white/10 bg-white/[0.06] text-white/[0.78] hover:border-white/20 hover:bg-white/[0.12] hover:text-white' }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
