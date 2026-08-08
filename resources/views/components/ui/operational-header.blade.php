@props(['eyebrow' => null, 'title', 'description' => null, 'back' => null])

<header {{ $attributes->merge(['class' => 'flex flex-col gap-3 border-b border-zinc-200 pb-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if($back)
            <a class="text-sm font-medium text-emerald-800 hover:underline" href="{{ $back['href'] }}">← {{ $back['label'] }}</a>
        @endif
        @if($eyebrow)<p class="mt-1 text-[11px] font-bold uppercase tracking-[.14em] text-emerald-800">{{ $eyebrow }}</p>@endif
        <h1 class="mt-1 font-sans text-2xl font-semibold tracking-tight text-zinc-950">{{ $title }}</h1>
        @if($description)<p class="mt-1 max-w-3xl text-sm leading-5 text-zinc-600">{{ $description }}</p>@endif
    </div>
    @if(trim($slot) !== '')<div class="flex shrink-0 flex-wrap items-center gap-2">{{ $slot }}</div>@endif
</header>
