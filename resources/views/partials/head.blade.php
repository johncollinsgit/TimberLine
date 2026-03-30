<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

@php
    $authTenantPresentation = $authTenantPresentation ?? [];
    $appMetaName = (string) ($authTenantPresentation['app_name'] ?? 'Forestry Backstage');
    $resolvedTitle = trim((string) ($title ?? ''));
    $mfPageTitle = ($resolvedTitle !== '' && mb_strtolower($resolvedTitle) !== mb_strtolower($appMetaName))
        ? $resolvedTitle.' · '.$appMetaName
        : $appMetaName;
    $mfDescription = trim((string) ($description ?? config('product_surfaces.promo.summary', 'Forestry Backstage unifies production, shipping, and customer growth in one place.')));
    $mfOgImage = asset('og-image.png').'?v=fb3';
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

<link rel="icon" href="{{ asset('favicon.ico') }}?v=fb4" sizes="any">
<link rel="icon" href="{{ asset('favicon.svg') }}?v=fb4" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon.png') }}?v=fb4" type="image/png">
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=fb4">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=fb4" sizes="180x180">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
