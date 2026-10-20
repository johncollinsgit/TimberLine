<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Markets & Makers')</title>
    @vite(['resources/css/marketplace.css', 'resources/js/marketplace-management.js'])
    @stack('head')
</head>
@php
    // Every caller supplies its already-authorized role/context. No tenant shell.
    $previewMode = ($consoleRole ?? '') === 'preview';
    $makerMode = ($consoleRole ?? '') === 'maker';
    $groups = \App\Services\Marketplace\MarketplaceManagementPresentation::groups($makerMode ? 'maker' : 'operator');
    $activeTab = $consoleTab ?? ($makerMode ? 'products' : 'overview');
    $activeLabel = collect($groups)->flatMap(fn($items) => $items)->get($activeTab, 'Maker details');
    $retainedFilters = $makerMode || $previewMode ? [] : request()->only(['from', 'to', 'maker', 'customer', 'include_test']);
@endphp
<body class="mm-console" data-mm-role="{{ $consoleRole ?? 'operator' }}">
<a class="mm-skip" href="#mm-main">Skip to workspace</a>
<div class="mm-shell">
    <aside class="mm-sidebar" id="mm-navigation" aria-label="Markets & Makers navigation" tabindex="-1">
        <a class="mm-brand" href="{{ $consoleBase }}{{ $makerMode ? '#products' : '?tab=overview' }}"><span class="mm-mark" aria-hidden="true">✳</span><span>markets & makers<small>AN EVERBRANCH APPLICATION</small></span></a>
        <div class="mm-context"><span class="mm-context-dot" aria-hidden="true"></span><div>{{ $consoleContext }}<small>{{ $makerMode ? 'Maker workspace' : ($previewMode ? 'Example workspace' : 'Merchant workspace') }}@if($consoleContextHint ?? null) · {{ $consoleContextHint }}@endif</small></div></div>
        <button type="button" class="mm-drawer-close" data-mm-close-nav>Close navigation ×</button>
        <nav aria-label="{{ $makerMode ? 'Maker workspace' : 'Marketplace operations' }}">
            @foreach($groups as $group => $items)
                <div class="mm-nav-group"><p>{{ $group }}</p>
                @foreach($items as $key => $label)
                    <a href="{{ $consoleBase }}{{ $makerMode ? '#'.$key : '?'.http_build_query(['tab'=>$key, ...$retainedFilters]) }}" @if($activeTab === $key) aria-current="page" @endif @if($makerMode) data-mm-pane-link="{{ $key }}" @endif><span class="mm-nav-dot" aria-hidden="true"></span>{{ $label }}</a>
                @endforeach
                </div>
            @endforeach
        </nav>
        <div class="mm-sidebar-bottom">
            @if($makerMode)<a href="/account">← Maker account</a><small>Independent makers welcome.<br>No Everbranch subscription required.</small>
            @else<a href="{{ rtrim(config('app.url'), '/') }}/dashboard">← Back to Everbranch</a><a href="{{ rtrim(config('app.url'), '/') }}/settings/profile">Account & security</a>@endif
        </div>
    </aside>
    <button type="button" class="mm-backdrop" data-mm-backdrop hidden tabindex="-1" aria-label="Close navigation"></button>
    <div class="mm-workspace" data-mm-workspace>
        <header class="mm-topbar">
            <button type="button" class="mm-menu" data-mm-menu aria-label="Navigation" aria-controls="mm-navigation" aria-expanded="false">☰</button>
            <div><span class="mm-breadcrumb">{{ $makerMode ? 'Your business' : 'Markets & Makers' }}</span><strong data-mm-page-title>{{ $activeLabel }}</strong></div>
            <span class="mm-role-badge">{{ $previewMode ? 'Read-only preview' : ($makerMode ? 'Maker' : 'Operator') }}</span>
        </header>
        @if($previewMode)
            <div class="mm-preview-banner"><strong>Private preview · Example data</strong><span>Read only · Storefront off · Payments off</span></div>
        @endif
        <main class="mm-main" id="mm-main" tabindex="-1">
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice error" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</div>
<noscript><style>@media(max-width:800px){.mm-sidebar{position:static;transform:none;visibility:visible;width:100%;height:auto}.mm-menu,.mm-drawer-close{display:none}}</style></noscript>
@stack('scripts')
</body>
</html>
