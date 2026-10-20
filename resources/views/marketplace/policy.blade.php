@extends('marketplace.layout')
@section('title', $title)
@section('content')<div class="page-shell narrow"><h1>{{ $title }}</h1><div class="preserve-lines">{{ $body ?: 'We’re preparing these details before checkout opens. Please check back soon.' }}</div>@if($supportEmail)<p>Questions? <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>@endif</div>@endsection
