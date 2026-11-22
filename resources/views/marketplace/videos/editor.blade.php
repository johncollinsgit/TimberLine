@extends('marketplace.layout')
@section('title', $video->exists ? $video->title : 'Create a maker video')
@push('head') @vite('resources/js/marketplace-videos.js') @endpush
@section('content')
<div class="page-shell watch-editor">
    <a class="back-link" href="{{ route('marketplace.public.maker.videos', $maker->id) }}">← Maker Studio</a>
    <h1>{{ $video->exists ? $video->title : 'Create a video' }}</h1>
    @if($video->exists)
        <p><strong>{{ ucfirst($video->status) }}</strong>
            @if($video->publish_at) · {{ $video->publish_at->timezone(config('marketplace.timezone'))->format('M j, Y · g:i a T') }}@endif
            @if($video->review_note) · {{ $video->review_note }}@endif
        </p>
        <section class="panel"><h2>Video performance</h2>
            <p>{{ number_format($video->views) }} views · {{ number_format($tags->sum('clicks')) }} product clicks · {{ number_format($summary['orders']) }} verified marketplace orders · {{ '$'.number_format($summary['net_merchandise_cents']/100, 2) }} net marketplace merchandise</p>
            @if($tags->isNotEmpty())
            <table><caption>Performance by product</caption><thead><tr><th>Product</th><th>Clicks</th><th>Verified marketplace orders</th><th>Net merchandise</th></tr></thead><tbody>
            @foreach($tags as $tag)
                <tr><th>{{ $tag->label }}</th><td>{{ $tag->clicks }}</td><td>{{ $sales[$tag->id]['orders'] ?? '—' }}</td><td>{{ isset($sales[$tag->id]) ? '$'.number_format($sales[$tag->id]['net_merchandise_cents']/100,2) : '—' }}</td></tr>
            @endforeach
            </tbody></table>
            @endif
            <p class="fine-print">Maker-store referrals report clicks and carry a 0% referral fee during the pilot. Marketplace order totals reflect confirmed sales and refunds.</p>
        </section>
    @endif
    @if($invited && $maker->status === 'approved')
    @php
        $rows = old('tags', $tags->where('active', true)->map(fn ($t) => ['key' => $t->product_key, 'start' => $t->start_seconds, 'end' => $t->end_seconds])->values()->all());
        $rows = array_slice(array_values($rows), 0, 12);
        if (count($rows) < 12) $rows[] = [];
    @endphp
    <form class="stack watch-form" method="post" enctype="multipart/form-data" data-video-editor data-max-mb="{{ config('marketplace.video_upload_mb') }}" action="{{ $video->exists ? route('marketplace.public.maker.videos.save', [$maker->id, $video->id]) : route('marketplace.public.maker.videos.create', $maker->id) }}">
        @csrf <input type="hidden" name="revision" value="{{ $video->revision }}">
        <input type="hidden" name="source_sha256" value="">
        @include('marketplace.videos.social-import')
        <details class="panel" data-package-panel hidden><summary>Import a Windows Studio export</summary>
            <label>Approved public export folder<input type="file" data-package-files webkitdirectory multiple></label>
            <label>Video to import<select data-package-choice></select></label>
            <button class="button secondary compact" type="button" data-package-apply disabled>Verify and use this video</button>
            <p role="status" aria-live="polite"></p>
        </details>
        <fieldset class="panel"><legend>Footage</legend>
            <label>{{ $video->exists ? 'Replace source video' : 'Upload a video' }}
                <input data-video-file type="file" name="source" accept="video/mp4,video/webm,video/quicktime,.mov,.mp4,.webm" @required(!$video->exists)>
            </label>
            <p class="fine-print">MP4, MOV or WebM, up to {{ config('marketplace.video_upload_mb') }} MB. Your source recording is private.</p>
            <div class="inline-actions">
                <button type="button" class="button secondary compact" data-record hidden>Record with camera</button>
                <button type="button" class="button secondary compact" data-stop hidden>Finish recording</button>
            </div>
            <span data-record-status role="status"></span>
            <video class="watch-preview" data-source-preview controls playsinline preload="metadata" @if($video->exists) src="{{ route('marketplace.public.maker.videos.source', [$maker->id, $video->id]) }}" @else hidden @endif></video>
            <div class="form-grid">
                <label>Trim start (seconds)<input type="number" min="0" max="14399" name="trim_start" value="{{ old('trim_start', $video->trim_start ?? 0) }}"><button class="watch-time" type="button" data-trim="trim_start" hidden>Use playhead</button></label>
                <label>Trim end (seconds)<input type="number" min="1" max="14400" name="trim_end" value="{{ old('trim_end', $video->trim_end) }}" placeholder="End of recording"><button class="watch-time" type="button" data-trim="trim_end" hidden>Use playhead</button></label>
            </div>
        </fieldset>
        <fieldset class="panel"><legend>Video details</legend>
            <label>Title<input name="title" maxlength="160" required value="{{ old('title', $video->title) }}"></label>
            <label>Description<textarea name="description" rows="3" maxlength="5000">{{ old('description', $video->description) }}</textarea></label>
            <label>Watch theme<select name="theme" required>@foreach(\App\Models\Marketplace\MakerVideo::THEMES as $key => $label)<option value="{{ $key }}" @selected(old('theme', $video->theme) === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Format<select name="format">@foreach(['short' => 'Short video', 'long' => 'Long video'] as $key => $label)<option value="{{ $key }}" @selected(old('format', $video->format) === $key)>{{ $label }}</option>@endforeach</select></label>
            <label data-vtt-import hidden>Import English captions (.vtt)<input type="file" accept=".vtt,text/vtt" data-vtt-file></label>
            <label>English captions (WebVTT)<textarea name="captions" rows="6" maxlength="1000000" placeholder="WEBVTT&#10;&#10;00:00:00.000 --> 00:00:03.000&#10;Your opening line">{{ old('captions', $video->captions) }}</textarea></label>
            <p class="fine-print">Caption and product times start at zero on your trimmed video.</p>
        </fieldset>
        <fieldset class="panel"><legend>Shop this video</legend>
            <p><a class="text-link" href="{{ route('marketplace.public.maker.video-catalog', $maker->id) }}">Manage your video catalog</a></p>
            <div data-tag-list>
                @foreach($rows as $index => $row)
                    <div class="watch-tag-row" data-tag-row>
                        <label>Product or variant<select name="tags[{{ $index }}][key]"><option value="">No tag</option>
                            @if(!empty($row['key']) && !collect($options)->contains('key', $row['key']))<option selected value="{{ $row['key'] }}">{{ $tags->firstWhere('product_key', $row['key'])?->label ?? 'Previous product' }} (unavailable)</option>@endif
                            @foreach($options as $option)
                                <option value="{{ $option['key'] }}" @selected(($row['key'] ?? '') === $option['key'])>{{ $option['label'] }} {{ $option['variant'] ?? '' }} · {{ $option['lane'] === 'marketplace' ? 'Marketplace' : 'Maker store' }}</option>
                            @endforeach
                        </select></label>
                        <label>From (sec)<input type="number" min="0" max="14399" name="tags[{{ $index }}][start]" value="{{ $row['start'] ?? '' }}"></label>
                        <label>Until (sec)<input type="number" min="1" max="14400" name="tags[{{ $index }}][end]" value="{{ $row['end'] ?? '' }}"></label>
                    </div>
                @endforeach
            </div>
            <button class="button secondary compact" type="button" data-add-tag hidden>Add another product</button>
            <p class="fine-print">Leave times empty to feature a product throughout. Choose “No tag” to remove it.</p>
        </fieldset>
        @if($video->status === 'approved')<p>Saving changes takes this version off Watch until the next edit is approved.</p>@endif
        <div class="inline-actions"><button name="action" value="draft" class="button secondary">Save draft</button><button name="action" value="submit" class="button">Submit for review</button></div>
    </form>
    @endif
</div>
@endsection
