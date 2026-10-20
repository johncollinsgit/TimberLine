<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#244d3b">
    <title>{{ $teamAppName }}</title>
    <link rel="icon" href="{{ asset('brand/everbranch-mark.svg') }}" type="image/svg+xml">
    <style>
        html{color-scheme:light}body{margin:0;background:#f3f6ef;color:#243d30;font:16px/1.5 system-ui,sans-serif}*{box-sizing:border-box}.teams-shell{min-height:100dvh;display:grid;grid-template-columns:248px minmax(0,1fr)}.teams-sidebar{position:sticky;top:0;height:100dvh;display:flex;flex-direction:column;gap:24px;padding:28px 20px;background:#e6eee2;border-right:1px solid #d0ddcb}.teams-brand{display:flex;align-items:center;gap:12px;color:inherit;font-size:22px;line-height:1.25;font-weight:750;text-decoration:none;overflow-wrap:anywhere}.teams-brand img{width:40px;height:40px;flex-shrink:0}.teams-eyebrow{margin:10px 0 0;font-size:12px;letter-spacing:.08em;color:#344b3d}.teams-nav{display:grid;gap:8px}.teams-nav button,.teams-create{min-height:46px;padding:11px 14px;border:1px solid transparent;border-radius:10px;background:transparent;color:#243d30;text-align:left;font:inherit;cursor:pointer}.teams-nav button:hover,.teams-nav button[aria-current=page]{background:white;border-color:#c5d6bf;font-weight:650}.teams-create{background:#315940;color:white;text-align:center}.teams-bottom{margin-top:auto;display:grid;gap:12px;font-size:14px}.teams-bottom a{display:block;min-height:44px;padding:10px 0;color:#244d3b}.teams-user{padding-top:14px;border-top:1px solid #c8d7c1;overflow-wrap:anywhere}.teams-content{min-width:0}.teams-content #team-work-app h1{overflow-wrap:anywhere}.teams-sidebar button:disabled{opacity:1;cursor:not-allowed;background:#edf1eb;color:#344b3d;border-color:#bdccbd;border-style:dashed}.teams-skip{position:absolute;left:12px;top:-100px;z-index:20;padding:12px;background:white;color:#244d3b}.teams-skip:focus{top:12px}.teams-sidebar :focus-visible{outline:3px solid #ba7f25;outline-offset:3px}@media(max-width:800px){.teams-shell{grid-template-columns:1fr}.teams-sidebar{position:static;height:auto;padding:18px 20px;gap:14px;border-right:0;border-bottom:1px solid #d0ddcb}.teams-brand{font-size:23px}.teams-nav{grid-template-columns:repeat(4,minmax(0,1fr));gap:5px}.teams-nav button{padding:10px 5px;text-align:center;font-size:14px}.teams-bottom{grid-template-columns:1fr 1fr;gap:8px;margin-top:0}.teams-user{grid-column:1/-1;padding-top:8px;font-size:13px}.teams-create{width:100%}}@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
    </style>
</head>
<body class="{{ config('team_work.premium_enabled') ? 'teams-premium' : '' }}">
<a class="teams-skip" href="#teams-main">Skip to work</a>
<div class="teams-shell" data-team-standalone>
    <aside class="teams-sidebar" aria-label="Teams application">@if(config('team_work.premium_enabled'))<button type="button" class="teams-mobile-close" data-teams-menu-close aria-label="Close navigation">×</button>@endif
        <div>
            <a class="teams-brand" href="{{ route('team.app', ['tenant' => $tenant->slug]) }}"><img src="{{ asset('brand/everbranch-mark.svg') }}" alt=""><span>{{ $teamAppName }}</span></a>
            <p class="teams-eyebrow">AN EVERBRANCH APPLICATION</p>
        </div>
        <nav class="teams-nav" aria-label="Teams sections">
            <button type="button" data-team-mode="home" aria-current="page">Home</button>
            <button type="button" data-team-mode="mine">My Tasks</button>
            <button type="button" data-team-mode="all">All work</button>
            <button type="button" data-team-mode="inbox">Inbox</button>
        </nav>
        <button type="button" id="teams-create" class="teams-create" disabled>Create task</button>
        @if(config('team_work.premium_enabled'))
        <div class="teams-projects"><p class="teams-section-label">Favorites</p><div id="teams-favorite-list"></div><p class="teams-section-label" style="margin-top:26px">Projects</p><input id="tw-project-nav-search" type="search" placeholder="Find a project…" aria-label="Find a project in navigation" style="width:100%;border:1px solid #ffffff20;background:#ffffff08;color:#dce7dd;padding:8px 10px;border-radius:5px;font:inherit;font-size:12px;margin-bottom:10px"><div id="teams-project-list"></div>@if(config('team_work.asana_enabled'))<p class="teams-section-label" style="margin-top:26px">Asana</p><div id="tw-asana-project-nav"></div>@endif</div>
        @endif
        <div class="teams-bottom">
            <a href="{{ route('team.index', ['tenant' => $tenant->slug]) }}" data-team-surface-link>Back to Everbranch</a>
            @if(app(\App\Services\AiBranch\AiBranchAccess::class)->visible($tenant, auth()->user()))
            <a href="{{ route('ai-branch.index', ['tenant' => $tenant->slug]) }}" data-team-surface-link>AI Voice Branch</a>
            @endif
            <a href="{{ route('profile.edit', ['tenant' => $tenant->slug]) }}">Account settings</a>
            <div class="teams-user">@if(config('team_work.premium_enabled'))<span class="teams-avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>@endif<span>{{ auth()->user()->name }}<br>{{ $tenant->name }}</span></div>
        </div>
    </aside>
    <main class="teams-content" id="teams-main" tabindex="-1">
        @include('team.workspace')
    </main>
</div>
</body>
</html>
