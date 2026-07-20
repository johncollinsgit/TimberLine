<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

@php
    $authTenantPresentation = $authTenantPresentation ?? [];
    $headTenant = $currentTenant ?? request()->attributes->get('current_tenant');
    $isNeutralTenantSurface = request()->routeIs('agreements.*', 'proposals.*', 'billing.*', 'payments.*', 'invoices.*')
        || request()->is('agreements*', 'proposals*', 'billing*', 'payments*', 'invoices*');
    $headBrand = app(\App\Services\Tenancy\TenantBrandProfileService::class)->presentationFor(
        ! $isNeutralTenantSurface && $headTenant instanceof \App\Models\Tenant ? $headTenant : null
    );
    $appMetaName = (string) ($app_name ?? $authTenantPresentation['app_name'] ?? $headBrand['display_name'] ?? config('everbranch.product_name', 'Everbranch'));
    $resolvedTitle = trim((string) ($title ?? ''));
    $brandAssets = (array) ($brand_assets ?? config('everbranch.brand_assets', []));
    $mfAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $mfPageTitle = ($resolvedTitle !== '' && mb_strtolower($resolvedTitle) !== mb_strtolower($appMetaName))
        ? $resolvedTitle.' - '.$appMetaName
        : $appMetaName;
    $mfDescription = trim((string) ($description ?? config('product_surfaces.promo.summary', 'Everbranch unifies production, shipping, and customer growth in one place.')));
    $mfOgImage = asset((string) ($brandAssets['og_image'] ?? 'og-image.png')).'?v='.$mfAssetVersion;
    $mfFaviconSvg = ! $isNeutralTenantSurface && $headTenant instanceof \App\Models\Tenant
        ? (string) $headBrand['icon_url']
        : asset((string) ($brandAssets['favicon_svg'] ?? 'brand/everbranch-favicon.svg')).'?v='.$mfAssetVersion;
    $mfFaviconPng = asset((string) ($brandAssets['favicon_png'] ?? 'favicon.png')).'?v='.$mfAssetVersion;
    $mfFaviconIco = asset((string) ($brandAssets['favicon_ico'] ?? 'favicon.ico')).'?v='.$mfAssetVersion;
    $mfAppleTouchIcon = asset((string) ($brandAssets['apple_touch_icon'] ?? 'apple-touch-icon.png')).'?v='.$mfAssetVersion;
@endphp

<title>{{ $mfPageTitle }}</title>
<meta name="description" content="{{ $mfDescription }}">
<meta name="application-name" content="{{ $appMetaName }}">
<meta name="apple-mobile-web-app-title" content="{{ $appMetaName }}">
<meta property="og:site_name" content="{{ $appMetaName }}">
<meta property="og:title" content="{{ $mfPageTitle }}">
<meta property="og:description" content="{{ $mfDescription }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ $mfOgImage }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $mfPageTitle }}">
<meta name="twitter:description" content="{{ $mfDescription }}">
<meta name="twitter:image" content="{{ $mfOgImage }}">

<link rel="icon" href="{{ $mfFaviconSvg }}" type="image/svg+xml">
<link rel="icon" href="{{ $mfFaviconPng }}" type="image/png" sizes="512x512">
<link rel="icon" href="{{ $mfFaviconIco }}" type="image/x-icon" sizes="16x16 32x32 48x48">
<link rel="shortcut icon" href="{{ $mfFaviconIco }}">
<link rel="apple-touch-icon" href="{{ $mfAppleTouchIcon }}" sizes="180x180">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
