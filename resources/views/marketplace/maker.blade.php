@extends('marketplace.layout')
@section('title', $maker->name)
@section('content')
<div class="page-shell"><a class="back-link" href="/#makers">← Meet the makers</a><div class="maker-intro"><span class="maker-initial large">{{ mb_substr($maker->name,0,1) }}</span><div><span class="eyebrow">MADE IN {{ strtoupper($maker->city) }}, SOUTH CAROLINA</span><h1>{{ $maker->name }}</h1><p class="preserve-lines">{{ $maker->story }}</p></div></div><h2>From this maker’s hands.</h2><div class="product-grid">@forelse($listings as $listing)@include('marketplace.partials.card')@empty<p>Fresh goods are on their way. Check back soon.</p>@endforelse</div></div>
@endsection
