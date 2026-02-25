<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ? $title.' · Modern Forestry Backstage' : 'Modern Forestry Backstage' }}</title>

<link rel="icon" href="{{ asset('favicon.svg') }}?v=mf1" type="image/svg+xml">
<link rel="shortcut icon" href="{{ asset('favicon.svg') }}?v=mf1" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=mf1">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&family=fraunces:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@fluxAppearance
@livewireStyles
