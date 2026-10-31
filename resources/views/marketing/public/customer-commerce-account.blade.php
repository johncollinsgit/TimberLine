<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your account · {{ $brandName }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-950">
    <main class="mx-auto max-w-5xl space-y-6 px-4 py-10">
        <header>
            <p class="text-sm text-zinc-600">{{ $brandName }}</p>
            <h1 class="mt-2 text-3xl font-semibold">Your account</h1>
        </header>
        @if($profile)
            <x-customer-commerce :snapshot="$customerCommerce" :rewards-label="$rewardsLabel" />
        @else
            <p class="rounded-2xl border border-zinc-200 bg-white p-5">{{ $lookupState === 'unknown_customer' ? 'Your account has not been connected yet. Contact the store for help.' : 'Sign in on the storefront to view your purchases and rewards.' }}</p>
        @endif
    </main>
</body>
</html>
