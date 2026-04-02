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
    <title>Marketing SMS Consent Verify</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--fb-page-background)] text-zinc-900">
    <main class="mx-auto max-w-3xl px-4 py-10 space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-6">
            <div class="text-xs uppercase tracking-[0.22em] text-zinc-500">TimberLine Marketing</div>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950">SMS Consent Verification (Scaffold)</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Verification confirms SMS consent on the matched marketing profile. If configured, a {{ $rewardsLabel }} bonus is awarded only after successful verify.
            </p>
        </section>

        @if(session('status'))
            <div class="rounded-2xl border border-emerald-300/30 bg-emerald-100 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-rose-300/30 bg-rose-100 px-4 py-3 text-sm text-rose-800">
                <ul class="space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($confirmed)
            <section class="rounded-3xl border border-emerald-300/30 bg-emerald-100 p-6">
                <div class="text-lg font-semibold text-emerald-800">Consent Confirmed</div>
                <p class="mt-2 text-sm text-emerald-800">
                    SMS consent was confirmed and recorded for profile #{{ $profile?->id ?? '—' }}.
                    @if((int) request()->query('bonus', 0) > 0)
                        {{ $rewardsLabel }} bonus awarded: ${{ number_format((float) app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints((int) request()->query('bonus', 0)), 2) }}.
                    @endif
                </p>
            </section>
        @endif

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-6 space-y-4">
            <div class="text-sm text-zinc-700">
                <div>Token: <span class="font-mono text-xs text-zinc-600">{{ $token ?: 'missing' }}</span></div>
                <div class="mt-1">Profile: {{ $profile ? ('#' . $profile->id . ' · ' . ($profile->email ?: $profile->phone ?: 'no contact')) : 'Not resolved' }}</div>
            </div>

            <form method="POST" action="{{ route('marketing.consent.verify.store') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="inline-flex rounded-full border border-sky-300/35 bg-sky-100 px-5 py-2 text-sm font-semibold text-sky-900">
                    Confirm SMS Consent
                </button>
            </form>

            <a href="{{ route('marketing.consent.optin') }}" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-1.5 text-xs font-semibold text-zinc-700">
                Start New Opt-In
            </a>
        </section>
    </main>
</body>
</html>
