<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Marketing SMS Consent Verify</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100">
    <main class="mx-auto max-w-3xl px-4 py-10 space-y-6">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="text-xs uppercase tracking-[0.22em] text-zinc-400">TimberLine Marketing</div>
            <h1 class="mt-2 text-2xl font-semibold text-white">SMS Consent Verification (Scaffold)</h1>
            <p class="mt-2 text-sm text-zinc-300">
                Verification confirms SMS consent on the matched marketing profile. If configured, a Candle Cash bonus is awarded only after successful verify.
            </p>
        </section>

        @if(session('status'))
            <div class="rounded-2xl border border-emerald-300/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-rose-300/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-100">
                <ul class="space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($confirmed)
            <section class="rounded-3xl border border-emerald-300/30 bg-emerald-500/15 p-6">
                <div class="text-lg font-semibold text-emerald-100">Consent Confirmed</div>
                <p class="mt-2 text-sm text-emerald-50/90">
                    SMS consent was confirmed and recorded for profile #{{ $profile?->id ?? '—' }}.
                    @if((int) request()->query('bonus', 0) > 0)
                        Candle Cash bonus awarded: ${{ number_format((float) app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints((int) request()->query('bonus', 0)), 2) }}.
                    @endif
                </p>
            </section>
        @endif

        <section class="rounded-3xl border border-white/10 bg-black/20 p-6 space-y-4">
            <div class="text-sm text-zinc-200">
                <div>Token: <span class="font-mono text-xs text-zinc-300">{{ $token ?: 'missing' }}</span></div>
                <div class="mt-1">Profile: {{ $profile ? ('#' . $profile->id . ' · ' . ($profile->email ?: $profile->phone ?: 'no contact')) : 'Not resolved' }}</div>
            </div>

            <form method="POST" action="{{ route('marketing.consent.verify.store') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="inline-flex rounded-full border border-sky-300/35 bg-sky-500/20 px-5 py-2 text-sm font-semibold text-sky-100">
                    Confirm SMS Consent
                </button>
            </form>

            <a href="{{ route('marketing.consent.optin') }}" class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold text-zinc-200">
                Start New Opt-In
            </a>
        </section>
    </main>
</body>
</html>
