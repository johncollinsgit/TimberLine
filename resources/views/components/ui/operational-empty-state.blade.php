@props(['title', 'description', 'actionLabel' => null, 'actionHref' => null])
<div {{ $attributes->merge(['class' => 'px-6 py-14 text-center']) }}>
    <h2 class="text-sm font-semibold text-zinc-950">{{ $title }}</h2>
    <p class="mx-auto mt-1 max-w-md text-sm leading-5 text-zinc-600">{{ $description }}</p>
    @if($actionLabel && $actionHref)<a href="{{ $actionHref }}" class="fb-btn fb-btn-primary mt-4">{{ $actionLabel }}</a>@endif
</div>
