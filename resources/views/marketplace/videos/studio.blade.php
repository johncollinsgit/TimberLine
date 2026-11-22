@extends('marketplace.layout')
@section('title', 'Maker Studio')
@section('content')
<div class="page-shell">
    <a class="back-link" href="{{ route('marketplace.public.maker.dashboard', $maker->id) }}">← {{ $maker->name }} workspace</a>
    <div class="heading-row"><h1>Maker Studio</h1>
    @if($invited)<a class="button" href="{{ route('marketplace.public.maker.videos.new', $maker->id) }}">Create a video</a>@endif</div>
    @unless($invited)<p>Video submissions are open to invited makers during the pilot.</p>@endunless
    @if($invited)<p><a class="text-link" href="{{ route('marketplace.public.maker.video-catalog', $maker->id) }}">Manage your video catalog</a></p>@endif
    <div class="dashboard-list">
        @forelse($videos as $video)
            <article class="panel"><h2><a href="{{ route('marketplace.public.maker.videos.edit', [$maker->id, $video->id]) }}">{{ $video->title }}</a></h2>
                <p>{{ ucfirst($video->format) }} video · {{ ucfirst($video->status) }} · {{ number_format($video->views) }} views</p>
            </article>
        @empty
            @if($invited)<p>Your first video will appear here after you save a draft.</p>@endif
        @endforelse
    </div>
    {{ $videos->links() }}
</div>
@endsection
