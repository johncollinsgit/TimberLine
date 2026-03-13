<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Marketing SMS Consent Opt-In</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100">
    <main class="mx-auto max-w-3xl px-4 py-10 space-y-6">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="text-xs uppercase tracking-[0.22em] text-zinc-400">TimberLine Marketing</div>
            <h1 class="mt-2 text-2xl font-semibold text-white">SMS Consent Opt-In (Scaffold)</h1>
            <p class="mt-2 text-sm text-zinc-300">
                This Stage 6 scaffold captures an SMS consent intent, links or creates a marketing profile, and routes the user to verify confirmation.
                It does not auto-send messages.
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

        <section class="rounded-3xl border border-white/10 bg-black/20 p-6">
            <form method="POST" action="{{ route('marketing.consent.optin.store') }}" class="space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-400">First Name</label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white">
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-400">Last Name</label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-400">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white">
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-400">Phone</label>
                        <input type="text" name="phone" value="{{ old('phone') }}" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white">
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 text-sm text-zinc-200">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="accepts_email" value="1" class="rounded border-white/20 bg-white/10">
                        Also mark email consent
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="award_bonus" value="1" checked class="rounded border-white/20 bg-white/10">
                        Allow optional Candle Cash consent bonus (if enabled)
                    </label>
                </div>

                <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/20 px-5 py-2 text-sm font-semibold text-emerald-50">
                    Continue to Verify
                </button>
            </form>
        </section>
    </main>
</body>
</html>

