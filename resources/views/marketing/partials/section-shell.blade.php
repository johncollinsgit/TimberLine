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
            'dot' => 'bg-emerald-500 shadow-[0_0_0_5px_rgba(16,185,129,0.12)]',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'panel_current' => 'border-emerald-300/55 bg-white',
            'pill_current' => 'border-emerald-300 bg-emerald-100 text-emerald-900',
        ],
        'sky' => [
            'dot' => 'bg-sky-500 shadow-[0_0_0_5px_rgba(14,165,233,0.12)]',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'panel_current' => 'border-sky-300/55 bg-white',
            'pill_current' => 'border-sky-300 bg-sky-100 text-sky-900',
        ],
        'amber' => [
            'dot' => 'bg-amber-500 shadow-[0_0_0_5px_rgba(245,158,11,0.12)]',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'panel_current' => 'border-amber-300/55 bg-white',
            'pill_current' => 'border-amber-300 bg-amber-100 text-amber-900',
        ],
        'rose' => [
            'dot' => 'bg-rose-500 shadow-[0_0_0_5px_rgba(244,63,94,0.12)]',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'panel_current' => 'border-rose-300/55 bg-white',
            'pill_current' => 'border-rose-300 bg-rose-100 text-rose-900',
        ],
    ];
@endphp

<section class="rounded-[2rem] border border-zinc-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.32em] text-zinc-500">Marketing</div>
            <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-zinc-950">{{ $title ?: $section['label'] }}</h1>
            <p class="mt-2 text-sm text-zinc-600 max-w-3xl">{{ $description ?: $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.overview') }}" wire:navigate class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
            Open Overview
        </a>
    </div>
</section>

<x-admin.help-hint :title="$hintTitle ?: $section['hint_title']">
    {{ $hintText ?: $section['hint_text'] }}
</x-admin.help-hint>

<section class="rounded-[2rem] border border-zinc-200 bg-zinc-50 p-4 shadow-sm sm:p-5">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.28em] text-zinc-500">Pages</div>
            <div class="mt-1 text-sm text-zinc-600">Grouped so it is easier to find things fast.</div>
        </div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-500">Menu</div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
        @foreach($sectionGroups as $group)
            @php
                $accent = $accentStyles[$group['accent']] ?? $accentStyles['emerald'];
            @endphp
            <article class="rounded-[1.55rem] border p-3 shadow-sm sm:p-4 {{ $group['current'] ? $accent['panel_current'] : $accent['panel'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.28em] text-zinc-500">{{ $group['label'] }}</div>
                        <p class="mt-1 text-xs leading-5 text-zinc-500">{{ $group['description'] }}</p>
                    </div>
                    <span class="mt-1 h-2.5 w-2.5 rounded-full {{ $accent['dot'] }}"></span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($group['items'] as $item)
                        <a
                            href="{{ $item['href'] }}"
                            wire:navigate
                            class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $item['current'] ? $accent['pill_current'] : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-100 hover:text-zinc-900' }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
