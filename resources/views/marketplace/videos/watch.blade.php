@extends('marketplace.layout')
@section('title', 'Watch the makers')
@push('head') @vite('resources/js/marketplace-videos.js') @endpush
@section('content')
<div class="page-shell watch-channel">
    <h1>Watch the makers</h1>
    <nav class="watch-filters" aria-label="Video length">
        @foreach(['' => 'All videos', 'short' => 'Shorts', 'long' => 'Long videos'] as $format => $label)
            <a href="{{ route('marketplace.public.watch', $format ? ['format' => $format] : []) }}" @if((string) request('format') === $format) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="watch-grid">
        @forelse($videos as $video)
            <a class="watch-tile" href="{{ route('marketplace.public.watch.show', $video->id) }}">
                <img src="{{ route('marketplace.public.watch.poster', $video->id) }}" alt="" loading="lazy">
                <small>{{ $video->maker->name }} · {{ gmdate($video->duration >= 3600 ? 'G:i:s' : 'i:s', (int) $video->duration) }}</small>
                <h2>{{ $video->title }}</h2>
            </a>
        @empty
            <p>There are no published {{ request('format') === 'short' ? 'shorts' : 'videos' }} here yet.</p>
        @endforelse
    </div>
    {{ $videos->links() }}
</div>
@endsection
