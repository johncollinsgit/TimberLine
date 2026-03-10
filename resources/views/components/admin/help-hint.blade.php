@props([
    'title' => 'Helpful Hint',
    'tone' => 'info',
])

@php
    $tones = [
        'info' => [
            'shell' => 'border-emerald-300/25 bg-emerald-500/10',
            'badge' => 'border-emerald-300/35 bg-emerald-500/20 text-emerald-50',
            'text' => 'text-emerald-50/80',
        ],
        'neutral' => [
            'shell' => 'border-white/15 bg-white/5',
            'badge' => 'border-white/20 bg-white/10 text-white/85',
            'text' => 'text-white/75',
        ],
        'warning' => [
            'shell' => 'border-amber-300/30 bg-amber-500/10',
            'badge' => 'border-amber-300/35 bg-amber-500/20 text-amber-50',
            'text' => 'text-amber-50/80',
        ],
    ];

    $palette = $tones[$tone] ?? $tones['info'];
@endphp

<aside {{ $attributes->class(['rounded-2xl border p-4 sm:p-5', $palette['shell']]) }}>
    <div class="flex items-start gap-3">
        <span class="{{ $palette['badge'] }} inline-flex shrink-0 items-center rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.22em]">
            Hint
        </span>
        <div class="min-w-0 space-y-1">
            <div class="text-sm font-semibold text-white">{{ $title }}</div>
            <div class="{{ $palette['text'] }} text-sm leading-relaxed">
                {{ $slot }}
            </div>
        </div>
    </div>
</aside>
