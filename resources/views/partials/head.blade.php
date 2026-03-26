<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

@php
    $authTenantPresentation = $authTenantPresentation ?? [];
    $appMetaName = (string) ($authTenantPresentation['app_name'] ?? config('app.name', 'Modern Forestry Backstage'));
    $mfPageTitle = $title ? $title.' · '.$appMetaName : $appMetaName;
    $mfOgImage = asset('apple-touch-icon.png').'?v=bs4';
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

<link rel="icon" href="{{ asset('favicon.svg') }}?v=bs4" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon.png') }}?v=bs4" type="image/png" sizes="512x512">
<link rel="shortcut icon" href="{{ asset('favicon.png') }}?v=bs4">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=bs4">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
