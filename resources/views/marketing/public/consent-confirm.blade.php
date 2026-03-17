<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consent Confirmation</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100">
<main class="mx-auto max-w-2xl px-4 py-8 space-y-5">
    <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
        <div class="text-xs uppercase tracking-[0.22em] text-zinc-400">TimberLine Marketing</div>
        <h1 class="mt-2 text-2xl font-semibold text-white">Consent Confirmation</h1>
        <p class="mt-2 text-sm text-zinc-300">
            @if($eventContext)
                Event context: {{ $eventContext['title'] }}{{ $eventContext['date'] ? ' · ' . $eventContext['date'] : '' }}
            @elseif($eventSlug !== '')
                Event context: {{ $eventSlug }}
            @else
                This lightweight page confirms event/public opt-in capture.
            @endif
        </p>
    </section>

    @if(session('status'))
        <section class="rounded-3xl border border-emerald-300/30 bg-emerald-500/15 p-5 text-emerald-100">
            <h2 class="text-lg font-semibold">Thanks, you're in.</h2>
            <p class="mt-2 text-sm">{{ session('status') }}</p>
            @if($bonus > 0)
                <p class="mt-2 text-sm">Bonus awarded: {{ $bonusFormatted }} Candle Cash.</p>
            @endif
            @if($profile)
                <p class="mt-2 text-xs text-emerald-100/80">Profile reference: #{{ $profile->id }}</p>
            @endif
        </section>
    @else
        <section class="rounded-3xl border border-white/10 bg-black/20 p-5 text-sm text-zinc-300">
            No confirmation payload was provided for this request.
        </section>
    @endif

    <section class="rounded-3xl border border-white/10 bg-black/20 p-5">
        <div class="text-xs text-zinc-400">
            This page is part of minimal public utilities for event/QR and consent capture workflows. Online storefront UI remains on Shopify theme integration.
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            @if($eventSlug !== '')
                <a href="{{ route('marketing.public.events.optin', ['eventSlug' => $eventSlug]) }}" class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold text-zinc-200">
                    Back to Event Opt-In
                </a>
                <a href="{{ route('marketing.public.events.rewards', ['eventSlug' => $eventSlug]) }}" class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold text-zinc-200">
                    Check Event Rewards
                </a>
            @endif
            <a href="{{ route('marketing.public.rewards-lookup') }}" class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold text-zinc-200">
                Rewards Lookup
            </a>
        </div>
    </section>
</main>
</body>
</html>
