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
    $accentClass = static function (string $accent): string {
        return match (strtolower(trim($accent))) {
            'amber' => 'fb-accent-amber',
            'sky' => 'fb-accent-sky',
            'rose' => 'fb-accent-rose',
            default => 'fb-accent-emerald',
        };
    };
@endphp

<section class="fb-page-surface p-5 sm:p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="fb-kpi-label">Marketing</div>
            <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-zinc-950">{{ $title ?: $section['label'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-zinc-600">{{ $description ?: $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.overview') }}" wire:navigate class="fb-btn-soft fb-link-soft">
            Open Overview
        </a>
    </div>
</section>

<x-admin.help-hint :title="$hintTitle ?: $section['hint_title']">
    {{ $hintText ?: $section['hint_text'] }}
</x-admin.help-hint>

<section class="fb-page-surface fb-page-surface--muted p-4 sm:p-5">
    <div class="flex items-end justify-between gap-4">
        <div class="fb-kpi-label">Pages</div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
        @foreach($sectionGroups as $group)
            @php($groupAccent = $accentClass((string) ($group['accent'] ?? 'emerald')))
            <article class="fb-surface-inset p-3 sm:p-4 {{ $groupAccent }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="fb-kpi-label">{{ $group['label'] }}</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">{{ $group['description'] }}</p>
                    </div>
                    <span class="fb-accent-dot" aria-hidden="true"></span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($group['items'] as $item)
                        <a
                            href="{{ $item['href'] }}"
                            wire:navigate
                            class="fb-chip {{ $item['current'] ? 'fb-chip--active' : 'fb-chip--quiet' }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
