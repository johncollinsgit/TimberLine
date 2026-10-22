@extends('service-proposals.layout')
@section('title', 'Review selected scope')
@section('content')
<header><span class="brand">Evergrove Software</span><h1>Review your selected scope.</h1><p>Prepared for {{ $version->payload['client']['name'] }} · version {{ $version->number }}</p></header>
<section class="card">@include('service-proposals.selection')<h2>The next step is a conversation.</h2><p>Request an agreement based on these exact selections. Your request does not sign a contract, approve unreviewed legal language, start a subscription or authorize a charge.</p>
<form method="post" action="{{ route('service-proposals.recipient.request', $token) }}">@csrf<input type="hidden" name="version_hash" value="{{ $version->content_hash }}"><input type="hidden" name="request_key" value="{{ $requestKey }}">@foreach($selected as $choice)<input type="hidden" name="selected[]" value="{{ $choice['id'] }}">@if($choice['term_months'])<input type="hidden" name="terms[{{ $choice['id'] }}]" value="{{ $choice['term_months'] }}">@endif @endforeach
<label><input type="checkbox" name="acknowledge" value="1" required>I understand this is a non-binding request for an agreement.</label><p><button>Request an agreement</button></p></form><a href="{{ route('service-proposals.recipient.show', $token) }}">Change my selections</a></section>
@endsection
