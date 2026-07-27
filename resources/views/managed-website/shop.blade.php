@extends('managed-website.store-layout')
@section('store-content')
<section class="store-hero"><p>EVERBRANCH WEBSITE</p><h1>{{ $tenant->brandProfile?->display_name ?: $tenant->name }}</h1><span>Shop products and reserve services directly from this website.</span></section>
<section class="store-shell"><div class="store-heading"><h2>Shop</h2><a href="{{ route('managed-website.store.cart') }}">Cart</a></div><div class="store-grid">@forelse($products as $product)<article class="store-card"><div class="store-card__image"></div><p>{{ str($product->product_type)->headline() }}</p><h3>{{ $product->title }}</h3><span>{{ str($product->description)->limit(105) }}</span><a href="{{ route('managed-website.store.products.show', ['handle' => $product->handle]) }}">View {{ str($product->product_type)->lower() }}</a></article>@empty<p class="store-empty">This shop is being prepared. Please check back soon.</p>@endforelse</div></section>
@endsection
