@extends('marketplace.layout')
@section('title', $video->title)
@push('head') @vite('resources/js/marketplace-videos.js') @endpush
@section('content')
<div class="page-shell watch-channel">
    <a class="back-link" href="{{ route('marketplace.public.watch') }}">← Watch</a>
    <div class="watch-feature">
        <section>
            <video class="watch-player" controls playsinline preload="metadata" data-watch-player data-play-url="{{ route('marketplace.public.watch.play', $video->id) }}" poster="{{ route('marketplace.public.watch.poster', $video->id) }}">
                <source src="{{ route('marketplace.public.watch.file', $video->id) }}" type="video/mp4">
                @if($video->captions)<track kind="captions" label="English" srclang="en" src="{{ route('marketplace.public.watch.captions', $video->id) }}" default>@endif
            </video>
            <h1>{{ $video->title }}</h1>
            <p>By <a class="text-link" href="{{ route('marketplace.public.maker', $video->maker->slug) }}">{{ $video->maker->name }}</a> · <a href="{{ route('marketplace.public.watch', ['theme' => $video->theme]) }}">{{ \App\Models\Marketplace\MakerVideo::THEMES[$video->theme] }}</a></p>
            <p>Scouting for a market? <a class="text-link" href="{{ route('marketplace.public.maker', $video->maker->slug) }}">Explore this maker’s business</a></p>
            @if($video->description)<p class="preserve-lines">{{ $video->description }}</p>@endif
        </section>
        @if($cards->isNotEmpty())
            <aside aria-label="Featured products"><h2>Shop this video</h2>
            @foreach($cards as ['tag' => $tag, 'card' => $card])
                <article class="watch-product" data-tag-start="{{ $tag->start_seconds }}" data-tag-end="{{ $tag->end_seconds }}">
                    <small>{{ $video->maker->name }}</small>
                    <h3>{{ $card['label'] ?? $tag->label }}</h3>
                    @if(!empty($card['variant']))<p>{{ $card['variant'] }}</p>@endif
                    @if($tag->start_seconds !== null)<button type="button" class="watch-time" data-seek="{{ $tag->start_seconds }}" hidden>Shown at {{ gmdate($tag->start_seconds >= 3600 ? 'G:i:s' : 'i:s', $tag->start_seconds) }}</button>@endif
                    @if(!empty($card['available']) && !empty($card['href']))
                        <form action="{{ route('marketplace.public.watch.shop', [$video->id, $tag->id]) }}" method="post">@csrf
                            <button class="button compact">{{ match ($card['lane']) { 'maker_quote' => 'Request a quote', 'marketplace' => 'Shop on Markets & Makers', default => 'Shop at '.$video->maker->name } }}</button>
                        </form>
                    @else
                        <p>Currently unavailable</p>
                    @endif
                </article>
            @endforeach
            </aside>
        @endif
    </div>
</div>
@endsection
