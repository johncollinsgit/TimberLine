@props([
    'title' => 'Helpful Hint',
    'tone' => 'info',
])

@php
    $tones = [
        'info' => [
            'shell' => 'border-emerald-200 bg-emerald-50',
            'badge' => 'border-emerald-300 bg-emerald-100 text-emerald-800',
            'text' => 'text-zinc-700',
        ],
        'neutral' => [
            'shell' => 'border-zinc-200 bg-zinc-50',
            'badge' => 'border-zinc-300 bg-white text-zinc-700',
            'text' => 'text-zinc-700',
        ],
        'warning' => [
            'shell' => 'border-amber-200 bg-amber-50',
            'badge' => 'border-amber-300 bg-amber-100 text-amber-800',
            'text' => 'text-zinc-700',
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
            <div class="text-sm font-semibold text-zinc-900">{{ $title }}</div>
            <div class="{{ $palette['text'] }} text-sm leading-relaxed">
                {{ $slot }}
            </div>
        </div>
    </div>
</aside>
