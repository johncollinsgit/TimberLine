<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

@php
    $authTenantPresentation = $authTenantPresentation ?? [];
    $appMetaName = (string) ($authTenantPresentation['app_name'] ?? 'Forestry Backstage');
    $resolvedTitle = trim((string) ($title ?? ''));
    $mfAssetVersion = 'fb7';
    $mfPageTitle = ($resolvedTitle !== '' && mb_strtolower($resolvedTitle) !== mb_strtolower($appMetaName))
        ? $resolvedTitle.' · '.$appMetaName
        : $appMetaName;
    $mfDescription = trim((string) ($description ?? config('product_surfaces.promo.summary', 'Forestry Backstage unifies production, shipping, and customer growth in one place.')));
    $mfOgImage = asset('og-image.png').'?v=fb3';
    $mfFaviconPng = asset('favicon.png').'?v='.$mfAssetVersion;
    $mfFaviconIco = asset('favicon.ico').'?v='.$mfAssetVersion;
    $mfAppleTouchIcon = asset('apple-touch-icon.png').'?v='.$mfAssetVersion;
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

<link rel="icon" href="{{ $mfFaviconPng }}" type="image/png" sizes="512x512">
<link rel="icon" href="{{ $mfFaviconIco }}" type="image/x-icon" sizes="16x16 32x32 48x48">
<link rel="shortcut icon" href="{{ $mfFaviconIco }}">
<link rel="apple-touch-icon" href="{{ $mfAppleTouchIcon }}" sizes="180x180">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
