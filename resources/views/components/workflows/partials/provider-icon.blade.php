@props(['provider', 'providers', 'size' => 'md'])
@php
    $meta = (array) ($providers[$provider] ?? []);
    $sizes = $size === 'lg' ? 'h-14 w-14 text-base' : ($size === 'sm' ? 'h-8 w-8 text-[10px]' : 'h-10 w-10 text-xs');
    $colors = match((string) ($meta['accent'] ?? 'zinc')) {
        'rose' => 'border-rose-200 bg-rose-50 text-rose-700',
        'sky' => 'border-sky-200 bg-sky-50 text-sky-700',
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'violet' => 'border-violet-200 bg-violet-50 text-violet-700',
        default => 'border-zinc-300 bg-white text-zinc-900',
    };
@endphp
<span {{ $attributes->class("{$sizes} {$colors} inline-flex shrink-0 items-center justify-center rounded-xl border font-black shadow-sm") }} aria-hidden="true">{{ $meta['initials'] ?? strtoupper(substr((string) $provider, 0, 2)) }}</span>
