@extends('marketplace.management-layout', ['consoleRole'=>'operator', 'consoleContext'=>'Markets & Makers', 'consoleBase'=>'/marketplace/operations'])
@section('title', 'Review '.$video->title)
@push('head') @vite('resources/js/marketplace-videos.js') @endpush
@section('content')
<div class="page-shell watch-editor">
    <a class="back-link" href="{{ route('marketplace.operator.videos') }}">← Review queue</a>
    <h1>{{ $video->title }}</h1>
    <p>{{ $video->maker->name }} · {{ ucfirst($video->status) }} · Revision {{ $video->revision }}</p>
    @if($video->description)<p class="preserve-lines">{{ $video->description }}</p>@endif
    @if($video->render_path)
        <video class="watch-preview" controls playsinline preload="metadata" src="{{ route('marketplace.operator.videos.preview', [$video->id, 'film']) }}">
            @if($video->captions)<track kind="captions" label="English" srclang="en" src="{{ route('marketplace.operator.videos.preview', [$video->id, 'captions']) }}" default>@endif
        </video>
    @endif
    @if($cards->isNotEmpty())<h2>Featured products</h2><ul>
        @foreach($cards as ['tag' => $tag, 'card' => $card])
            <li>{{ $tag->label }} · {{ ($card['available'] ?? false) ? 'Available' : 'Currently unavailable' }} @if($tag->start_seconds !== null || $tag->end_seconds !== null) · {{ $tag->start_seconds ?? 0 }}s to {{ $tag->end_seconds !== null ? $tag->end_seconds.'s' : 'end' }}@endif</li>
        @endforeach
    </ul>@endif
    <details class="panel"><summary>Production time and cost</summary>
        <form class="form-grid" action="{{ route('marketplace.operator.videos.costs', $video->id) }}" method="post">@csrf
            <label>Team production time (minutes)<input type="number" name="minutes" min="0" max="100000" value="{{ old('minutes', $video->production_minutes) }}"></label>
            <label>Production cost ($)<input type="number" min="0" step=".01" name="cost" value="{{ old('cost', isset($video->production_cost_cents) ? number_format($video->production_cost_cents/100,2,'.','') : '') }}"></label>
            <button class="button secondary">Save production figures</button>
        </form>
    </details>
    @if($video->review_note)<p>{{ $video->review_note }}</p>@endif
    @if($video->render_path && in_array($video->status, ['approved','review'], true))
        <form method="post" class="stack panel" action="{{ route('marketplace.operator.videos.decide', $video->id) }}">@csrf
            <input type="hidden" name="revision" value="{{ $video->revision }}">
            <label>Publish at ({{ config('marketplace.timezone') }})<input name="publish_at" type="datetime-local" value="{{ old('publish_at', $video->publish_at?->timezone(config('marketplace.timezone'))->format('Y-m-d\TH:i')) }}"></label>
            <p>Leave the date empty to publish on approval.</p>
            <label>Feedback for maker<textarea name="note" maxlength="2000">{{ old('note', $video->review_note) }}</textarea></label>
            <div class="inline-actions"><button name="action" value="approve" class="button">Approve publication</button><button name="action" value="changes" class="button secondary">Return for changes</button></div>
        </form>
    @endif
</div>
@endsection
