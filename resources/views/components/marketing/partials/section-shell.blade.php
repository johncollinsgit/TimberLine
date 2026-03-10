@props([
    'section',
    'sections',
    'title' => null,
    'description' => null,
    'hintTitle' => null,
    'hintText' => null,
])

<section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)]">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">Marketing</div>
            <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">{{ $title ?: $section['label'] }}</h1>
            <p class="mt-2 text-sm text-white/70 max-w-3xl">{{ $description ?: $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.overview') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
            Open Marketing Overview
        </a>
    </div>
</section>

<x-admin.help-hint :title="$hintTitle ?: $section['hint_title']">
    {{ $hintText ?: $section['hint_text'] }}
</x-admin.help-hint>

<section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5">
    <div class="text-[11px] uppercase tracking-[0.28em] text-white/55">Marketing Sections</div>
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach($sections as $item)
            <a
                href="{{ $item['href'] }}"
                wire:navigate
                class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $item['current'] ? 'border-emerald-300/40 bg-emerald-500/20 text-emerald-50' : 'border-white/10 bg-white/5 text-white/80 hover:bg-white/10' }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</section>
