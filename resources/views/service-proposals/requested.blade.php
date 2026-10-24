@extends('service-proposals.layout')
@section('title', 'Agreement requested')
@section('content')
<header><span class="brand">Evergrove Software</span><p class="eyebrow">A clear next step</p><h1>Your agreement request is recorded.</h1><p>Your proposal owner can review these exact services, prices and durations. Nothing has been signed or charged. No subscription has started. No email was sent.</p></header>
<section class="card"><h2>Requested scope · draft agreement basis</h2>@include('service-proposals.selection')<p class="hash">Request reference {{ $review->id }}<br>Selection reference {{ $review->selection_hash }}<br>Published version reference {{ $review->payload['version_hash'] }}</p></section>
@endsection
