@props(['tone' => 'neutral'])
@php($classes = ['success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'warning' => 'bg-amber-50 text-amber-900 ring-amber-200', 'danger' => 'bg-rose-50 text-rose-800 ring-rose-200', 'info' => 'bg-sky-50 text-sky-800 ring-sky-200', 'neutral' => 'bg-zinc-100 text-zinc-700 ring-zinc-200'][$tone] ?? 'bg-zinc-100 text-zinc-700 ring-zinc-200')
<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset '.$classes]) }}>{{ $slot }}</span>
