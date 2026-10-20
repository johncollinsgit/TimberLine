<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Discover locally made goods from independent makers in Upstate South Carolina. Shop for market pickup or delivery to your door.">
    <title>@yield('title', 'Good things, made close to home') — Markets & Makers</title>
    @vite('resources/css/marketplace.css')
    @stack('head')
</head>
@php($marketRoot = app(\App\Services\Marketplace\MarketplaceAccess::class)->publicHost(request()->getHost()) ? '' : 'https://'.config('marketplace.host'))
<body class="{{ request()->routeIs('marketplace.operator.*', 'marketplace.workspace.*', 'marketplace.public.maker.*') ? 'workspace-screen' : '' }}">
<a class="skip-link" href="#main">Skip to content</a>
@if(config('marketplace.demo') && app()->environment('local'))<div class="demo-notice">Local preview · Example makers, products, and markets · Checkout is off</div>@endif
<div class="announcement">Rooted in the Upstate. Made for everyday. <span>Shop small. Find something good.</span></div>
<header class="site-header">
    <a class="wordmark" href="{{ $marketRoot }}/" aria-label="Markets and Makers home"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 2v28M2 16h28M6 6l20 20M6 26 26 6" stroke="currentColor" stroke-width="3"/></svg><span>markets<span class="amp">&</span>makers<small>GOODS WITH A GOOD STORY</small></span></a>
    <nav aria-label="Main navigation"><a href="{{ $marketRoot }}/#shop">Shop the goods</a><a href="{{ $marketRoot }}/#makers">Meet the makers</a><a href="{{ $marketRoot }}/markets">Markets & shows</a><a href="{{ $marketRoot }}/sell">Become a maker</a></nav>
    <div class="header-actions"><a href="{{ $marketRoot }}/account" aria-label="Your account">@include('marketplace.partials.icon', ['name'=>'user'])<span>Account</span></a><a href="{{ $marketRoot }}/cart" class="bag-link" aria-label="Shopping bag">@include('marketplace.partials.icon', ['name'=>'bag'])<span class="bag-count">{{ array_sum(array_column(session('marketplace_cart', []), 'quantity')) }}</span></a></div>
</header>
<main id="main">
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<footer class="site-footer"><div><a class="footer-brand" href="{{ $marketRoot }}/">markets & makers<span>Good things. Good people. Right here.</span></a><p>A little closer to the people who make your favorite things.</p></div><nav aria-label="Footer"><a href="{{ $marketRoot }}/sell">Sell with us</a><a href="{{ $marketRoot }}/policies/shipping">Shipping & pickup</a><a href="{{ $marketRoot }}/policies/return">Returns</a><a href="{{ $marketRoot }}/policies/privacy">Privacy</a><a href="{{ $marketRoot }}/account">Your orders</a></nav><div class="footer-bottom"><span>© {{ date('Y') }} Markets and Makers</span><span>Made with care. Connected by Everbranch.</span></div></footer>
@stack('scripts')
</body></html>
