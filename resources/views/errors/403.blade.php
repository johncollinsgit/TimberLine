<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100">
    <main class="mx-auto flex min-h-screen max-w-2xl items-center px-6">
        <section class="w-full rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl">
            <p class="text-xs uppercase tracking-[0.3em] text-zinc-400">403</p>
            <h1 class="mt-3 text-3xl font-semibold">You do not have access to that page.</h1>
            <p class="mt-3 text-sm text-zinc-300">
                If you think this is a mistake, contact an administrator. Otherwise use the link below to return to your workspace.
            </p>
            <div class="mt-6">
                <a href="{{ route('home') }}"
                   class="inline-flex items-center rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-sm text-emerald-100 hover:bg-emerald-500/25">
                    Back to Backstage
                </a>
            </div>
        </section>
    </main>
</body>
</html>
