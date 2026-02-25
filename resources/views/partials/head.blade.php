<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php
    $mfPageTitle = $title ? $title.' · Modern Forestry Backstage' : 'Modern Forestry Backstage';
    $mfOgImage = asset('apple-touch-icon.png').'?v=mf2';
@endphp

<title>{{ $mfPageTitle }}</title>
<meta name="application-name" content="Modern Forestry Backstage">
<meta name="apple-mobile-web-app-title" content="Modern Forestry Backstage">
<meta property="og:site_name" content="Modern Forestry Backstage">
<meta property="og:title" content="{{ $mfPageTitle }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ $mfOgImage }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $mfPageTitle }}">
<meta name="twitter:image" content="{{ $mfOgImage }}">

<link rel="icon" href="{{ asset('favicon.ico') }}?v=mf2" sizes="any">
<link rel="icon" href="{{ asset('favicon.svg') }}?v=mf2" type="image/svg+xml">
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=mf2">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=mf2">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
