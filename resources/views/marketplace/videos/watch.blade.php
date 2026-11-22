@extends('marketplace.layout')
@section('title', 'Watch the makers')
@push('head') @vite('resources/js/marketplace-videos.js') @endpush
@section('content')
<div class="page-shell watch-channel">
    <h1>{{ $maker ? 'Watch '.$maker->name : 'Watch the makers' }}</h1>
    <p>Find ideas for starting a business, go inside makers’ workshops, or discover a business for your next market.</p>
    <nav class="watch-filters" aria-label="Video theme">
        @foreach(['' => 'All themes', ...\App\Models\Marketplace\MakerVideo::THEMES] as $theme => $label)
            <a href="{{ route('marketplace.public.watch', array_filter([...$filters, 'theme' => $theme])) }}" @if((string) ($filters['theme'] ?? '') === $theme) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <nav class="watch-filters" aria-label="Video length">
        @foreach(['' => 'All lengths', 'short' => 'Shorts', 'long' => 'Long videos'] as $format => $label)
            <a href="{{ route('marketplace.public.watch', array_filter([...$filters, 'format' => $format])) }}" @if((string) ($filters['format'] ?? '') === $format) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @if($maker)<p><a href="{{ route('marketplace.public.watch', array_diff_key($filters, ['maker' => true])) }}">See videos from all makers</a></p>@endif
    <div class="watch-grid">
        @forelse($videos as $video)
            <article class="watch-tile">
                <a href="{{ route('marketplace.public.watch.show', $video->id) }}">
                    <img src="{{ route('marketplace.public.watch.poster', $video->id) }}" alt="" loading="lazy">
                    <small>{{ \App\Models\Marketplace\MakerVideo::THEMES[$video->theme] }} · {{ gmdate($video->duration >= 3600 ? 'G:i:s' : 'i:s', (int) $video->duration) }}</small>
                    <h2>{{ $video->title }}</h2>
                </a>
                <a class="text-link" href="{{ route('marketplace.public.maker', $video->maker->slug) }}">{{ $video->maker->name }}</a>
            </article>
        @empty
            <div>
                @if($filters)<h2>No videos match these filters yet.</h2><a class="text-link" href="{{ route('marketplace.public.watch') }}">Browse all maker videos</a>
                @else<h2>The makers are getting ready.</h2><p>Watch will feature videos from invited makers after editorial review.</p><a class="text-link" href="{{ route('marketplace.public.home') }}#makers">Explore the maker directory</a>
                @endif
            </div>
        @endforelse
    </div>
    {{ $videos->links() }}
</div>
@endsection
