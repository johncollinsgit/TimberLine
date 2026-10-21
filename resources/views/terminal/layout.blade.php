<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Everbranch terminal') · Everbranch</title>
    <style nonce="{{ $nonce }}">{!! file_get_contents(resource_path('css/terminal-workspace.css')) !!}</style>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="terminal-shell">
    <header class="terminal-header">
        <a class="brand" href="{{ route('terminal.entry') }}"><img src="/brand/everbranch-mark.svg" alt="" width="34" height="34"><span>Everbranch<small>TERMINAL</small></span></a>
        <span class="availability"><span aria-hidden="true"></span>Live payments unavailable</span>
        @isset($actor)
            <form method="POST" action="{{ route('logout') }}" class="signout-form">@csrf<button type="submit" class="button secondary compact-button">Sign out of Everbranch</button></form>
        @endisset
    </header>
    <main id="main" tabindex="-1">
        @if(session('terminal_notice'))<div class="notice" role="status">{{ session('terminal_notice') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><strong>Review these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    <footer class="terminal-footer"><strong>Everbranch terminal</strong><span>Workspace access and saved readiness only. No live payment collection.</span></footer>
</div>
</body>
</html>
