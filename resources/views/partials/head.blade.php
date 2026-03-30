<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

@php
    $authTenantPresentation = $authTenantPresentation ?? [];
    $appMetaName = (string) ($authTenantPresentation['app_name'] ?? config('app.name', 'Forestry Backstage'));
    $resolvedTitle = trim((string) ($title ?? ''));
    $mfPageTitle = $resolvedTitle !== '' ? $resolvedTitle.' · '.$appMetaName : $appMetaName;
    $mfOgImage = asset('brand/forestry-backstage-lockup.svg').'?v=fb1';
@endphp

<title>{{ $mfPageTitle }}</title>
<meta name="application-name" content="{{ $appMetaName }}">
<meta name="apple-mobile-web-app-title" content="{{ $appMetaName }}">
<meta property="og:site_name" content="{{ $appMetaName }}">
<meta property="og:title" content="{{ $mfPageTitle }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ $mfOgImage }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $mfPageTitle }}">
<meta name="twitter:image" content="{{ $mfOgImage }}">

<link rel="icon" href="{{ asset('brand/forestry-backstage-favicon.svg') }}?v=fb1" type="image/svg+xml">
<link rel="shortcut icon" href="{{ asset('brand/forestry-backstage-favicon.svg') }}?v=fb1">
<link rel="apple-touch-icon" href="{{ asset('brand/forestry-backstage-mark.svg') }}?v=fb1">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
