@extends('marketplace.layout')
@section('title', $maker->name)
@section('content')
<div class="page-shell"><a class="back-link" href="/#makers">← Meet the makers</a><div class="maker-intro"><span class="maker-initial large">{{ mb_substr($maker->name,0,1) }}</span><div><span class="eyebrow">MADE IN {{ strtoupper($maker->city) }}, SOUTH CAROLINA</span><h1>{{ $maker->name }}</h1><p class="preserve-lines">{{ $maker->story }}</p></div></div>
@if($hasVideos)<a class="button secondary" href="{{ route('marketplace.public.watch', ['maker' => $maker->slug]) }}">Watch {{ $maker->name }}</a>@endif
@if($markets->isNotEmpty())
    <section><h2>Meet this maker at a market</h2><ul>
        @foreach($markets as $market)<li><a href="{{ route('marketplace.public.markets', ['maker' => $maker->slug]) }}#market-{{ $market->id }}">{{ $market->name }}</a> · {{ $market->starts_at->timezone($market->timezone)->format('M j, Y') }}</li>@endforeach
    </ul></section>
@endif
<h2>From this maker’s hands.</h2><div class="product-grid">@forelse($listings as $listing)@include('marketplace.partials.card')@empty<p>Fresh goods are on their way. Check back soon.</p>@endforelse</div></div>
@endsection
