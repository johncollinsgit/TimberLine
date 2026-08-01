@extends('managed-website.store-layout')
@section('store-content')
<section class="store-hero"><p>EVERBRANCH WEBSITE</p><h1>{{ $tenant->brandProfile?->display_name ?: $tenant->name }}</h1><span>Tell us what electrical work you need and we’ll help you get started.</span></section>
<section class="store-shell"><div class="store-heading"><h2>Electrical services</h2></div><div class="store-grid">@forelse($products as $product)<article class="store-card"><div class="store-card__image"></div><p>Request a quote</p><h3>{{ $product->title }}</h3><span>{{ str($product->description)->limit(105) }}</span><a href="{{ route('managed-website.store.products.show', ['handle' => $product->handle]) }}">Request a quote</a></article>@empty<p class="store-empty">Services are being prepared. Please check back soon.</p>@endforelse</div></section>
@endsection
