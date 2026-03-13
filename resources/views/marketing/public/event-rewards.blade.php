<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Rewards</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100">
<main class="mx-auto max-w-3xl px-4 py-8 space-y-5">
    <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
        <div class="text-xs uppercase tracking-[0.22em] text-zinc-400">TimberLine Event Rewards</div>
        <h1 class="mt-2 text-2xl font-semibold text-white">Event Rewards Lookup</h1>
        <p class="mt-2 text-sm text-zinc-300">
            @if($eventContext)
                {{ $eventContext['title'] }}{{ $eventContext['date'] ? ' · ' . $eventContext['date'] : '' }}
            @else
                Event context: {{ $eventSlug }}
            @endif
        </p>
        <p class="mt-2 text-sm text-zinc-300">
            Lightweight public utility for event customers to check Candle Cash balance and recent reward redemptions.
        </p>
    </section>

    <section class="rounded-3xl border border-white/10 bg-black/20 p-5">
        <form method="GET" action="{{ route('marketing.public.events.rewards', ['eventSlug' => $eventSlug]) }}" class="grid gap-3 sm:grid-cols-3">
            <input type="email" name="email" value="{{ request('email') }}" placeholder="Email" class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white sm:col-span-1">
            <input type="text" name="phone" value="{{ request('phone') }}" placeholder="Phone" class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white sm:col-span-1">
            <button type="submit" class="rounded-xl border border-sky-300/35 bg-sky-500/20 px-4 py-2 text-sm font-semibold text-sky-100 sm:col-span-1">Lookup</button>
        </form>
        <p class="mt-2 text-xs text-zinc-400">Use both email and phone for lookup. This page returns only minimal rewards data.</p>
        @if(($lookupState ?? '') !== '')
            <div class="mt-2 text-xs text-zinc-300">
                State: <span class="font-semibold">{{ strtoupper((string) $lookupState) }}</span>
                @if($lookupState === 'verification_required')
                    · Please provide both email and phone.
                @elseif($lookupState === 'needs_verification')
                    · Identity could not be linked automatically; try again or contact support.
                @elseif($lookupState === 'unknown_customer')
                    · No matching profile found yet.
                @endif
            </div>
        @endif
    </section>

    @if($profile)
        @php
            $maskedEmail = $profile->email ? preg_replace('/(^.).+(@.*$)/', '$1***$2', $profile->email) : null;
            $maskedPhone = $profile->phone ? preg_replace('/\d(?=\d{2})/', '*', preg_replace('/\D+/', '', $profile->phone)) : null;
        @endphp
        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/20 p-5">
                <h2 class="text-sm font-semibold text-white">Current Balance</h2>
                <div class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) $balance) }} pts</div>
                <div class="mt-2 text-xs text-zinc-400">Matched identity: {{ $maskedEmail ?: $maskedPhone ?: 'verified' }}</div>
            </article>
            <article class="rounded-3xl border border-white/10 bg-black/20 p-5">
                <h2 class="text-sm font-semibold text-white">Available Rewards</h2>
                <div class="mt-2 space-y-2">
                    @forelse($availableRewards as $reward)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                            {{ $reward->name }} · {{ (int) $reward->points_cost }} pts
                        </div>
                    @empty
                        <div class="text-sm text-zinc-400">No active rewards right now.</div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/20 p-5">
            <h2 class="text-sm font-semibold text-white">Recent Redemptions</h2>
            <div class="mt-2 space-y-2 text-sm text-white/80">
                @forelse($redemptions as $redemption)
                    <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                        {{ $redemption->reward?->name ?: ('Reward #' . $redemption->reward_id) }}
                        · {{ (int) $redemption->points_spent }} pts
                        · {{ strtoupper((string) ($redemption->status ?: 'issued')) }}
                        · {{ optional($redemption->redeemed_at)->format('Y-m-d H:i') ?: '—' }}
                    </div>
                @empty
                    <div class="text-sm text-zinc-400">No redemptions found.</div>
                @endforelse
            </div>
        </section>
    @elseif(request()->query('email') || request()->query('phone'))
        <section class="rounded-3xl border border-amber-300/30 bg-amber-500/15 p-4 text-sm text-amber-100">
            No profile was found for that verified email + phone combination yet.
        </section>
    @endif
</main>
</body>
</html>
