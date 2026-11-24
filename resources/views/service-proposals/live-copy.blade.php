@extends('service-proposals.layout')
@section('title', 'Edit live proposal copy')
@section('content')
<header>
    <h1>Edit live proposal copy</h1>
    <p>{{ $version->payload['title'] }} · Version {{ $version->number }}</p>
    <a href="{{ route('service-proposals.owner.index', $tenant->slug) }}">Back to proposals</a>
</header>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul class="notice" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form class="card" method="post" action="{{ route('service-proposals.owner.live-copy.update', [$tenant->slug, $version->id]) }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="revision" value="{{ old('revision', $copy?->revision ?? 0) }}">
    <p>Saving updates the existing private link. Use the draft editor and publish a new version for pricing, terms, or PDF changes.</p>
    <label for="intro">Overview introduction</label>
    <p class="muted" id="intro-help">Leave blank to use the published introduction.</p>
    <textarea id="intro" name="intro" maxlength="6000" rows="5" aria-describedby="intro-help">{{ old('intro', $copy?->payload['intro'] ?? '') }}</textarea>
    <h2>Optional section above the tabs</h2>
    <p class="muted">Use this for next steps or a short update. Clear both fields to remove the section.</p>
    <label for="heading">Section heading</label>
    <input id="heading" name="heading" maxlength="120" value="{{ old('heading', $copy?->payload['heading'] ?? '') }}">
    <label for="body">Section text</label>
    <textarea id="body" name="body" maxlength="4000" rows="6">{{ old('body', $copy?->payload['body'] ?? '') }}</textarea>
    <p><button>Save to live proposal</button></p>
</form>
@endsection
