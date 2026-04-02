<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $displayLabels = is_array($displayLabels ?? null) ? $displayLabels : [];
        $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }
    @endphp
    <title>Consent Confirmation</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--fb-page-background)] text-zinc-900">
<main class="mx-auto max-w-2xl px-4 py-8 space-y-5">
    <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
        <div class="text-xs uppercase tracking-[0.22em] text-zinc-500">TimberLine Marketing</div>
        <h1 class="mt-2 text-2xl font-semibold text-zinc-950">Consent Confirmation</h1>
        <p class="mt-2 text-sm text-zinc-600">
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
        <section class="rounded-3xl border border-emerald-300/30 bg-emerald-100 p-5 text-emerald-800">
            <h2 class="text-lg font-semibold">Thanks, you're in.</h2>
            <p class="mt-2 text-sm">{{ session('status') }}</p>
            @if($bonus > 0)
                <p class="mt-2 text-sm">Bonus awarded: {{ $bonusFormatted }} {{ $rewardsLabel }}.</p>
            @endif
            @if($profile)
                <p class="mt-2 text-xs text-emerald-800/80">Profile reference: #{{ $profile->id }}</p>
            @endif
        </section>
    @else
        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 text-sm text-zinc-600">
            No confirmation payload was provided for this request.
        </section>
    @endif

    <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
        <div class="text-xs text-zinc-500">
            This page is part of minimal public utilities for event/QR and consent capture workflows. Online storefront UI remains on Shopify theme integration.
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            @if($eventSlug !== '')
                <a href="{{ route('marketing.public.events.optin', ['eventSlug' => $eventSlug]) }}" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-1.5 text-xs font-semibold text-zinc-700">
                    Back to Event Opt-In
                </a>
                <a href="{{ route('marketing.public.events.rewards', ['eventSlug' => $eventSlug]) }}" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-1.5 text-xs font-semibold text-zinc-700">
                    Check Event {{ $rewardsLabel }}
                </a>
            @endif
            <a href="{{ route('marketing.public.rewards-lookup') }}" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-1.5 text-xs font-semibold text-zinc-700">
                {{ $rewardsLabel }} Lookup
            </a>
        </div>
    </section>
</main>
</body>
</html>
