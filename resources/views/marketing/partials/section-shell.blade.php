@props([
    'section',
    'sections',
    'title' => null,
    'description' => null,
    'hintTitle' => null,
    'hintText' => null,
])

<header class="mb-5">
    <div class="text-[11px] uppercase tracking-[0.32em] text-zinc-500">Marketing</div>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 sm:text-3xl">{{ $title ?: $section['label'] }}</h1>
</header>
