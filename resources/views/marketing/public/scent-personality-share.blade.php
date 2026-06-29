<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $headline = trim((string) ($result->headline ?? 'My Modern Forestry scent personality')) ?: 'My Modern Forestry scent personality';
        $title = trim((string) ($result->personality_title ?? 'Scent personality')) ?: 'Scent personality';
        $body = trim((string) ($result->personality_body ?? 'A Modern Forestry candle profile built from scent preferences.')) ?: 'A Modern Forestry candle profile built from scent preferences.';
        $dominantTraits = array_values(array_filter((array) ($dominantTraits ?? [])));
        $axes = array_values((array) ($axes ?? []));
        $quizUrl = trim((string) ($quizUrl ?? 'https://theforestrystudio.com/apps/forestry/account?scent_quiz=1'));
        $cardVersion = (string) (optional($result->updated_at)->getTimestamp() ?: $result->id);
        $shareUrl = url()->full();
        $shareImageUrl = route('marketing.public.scent-personality-share.image', ['token' => $result->public_share_token, 'v' => $cardVersion]);
    @endphp
    <title>{{ $headline }} | Modern Forestry</title>
    <meta name="description" content="{{ $body }}">
    <meta property="og:title" content="{{ $headline }}">
    <meta property="og:description" content="{{ $body }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $shareUrl }}">
    <meta property="og:image" content="{{ $shareImageUrl }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $headline }} scent personality card">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $headline }}">
    <meta name="twitter:description" content="{{ $body }}">
    <meta name="twitter:image" content="{{ $shareImageUrl }}">
    @vite(['resources/css/app.css'])
    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 12%, rgba(20, 83, 45, 0.28), transparent 28%),
                radial-gradient(circle at 86% 18%, rgba(245, 158, 11, 0.18), transparent 30%),
                linear-gradient(135deg, #111b16, #1e3528 52%, #f5efe7 52%, #fbfaf8);
        }
        .share-shell {
            width: min(940px, calc(100vw - 2rem));
            margin: 0 auto;
            padding: 42px 0;
        }
        .share-card {
            overflow: hidden;
            border-radius: 34px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 30px 90px rgba(15, 23, 42, 0.24);
        }
        .share-hero {
            background:
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.26), transparent 32%),
                linear-gradient(135deg, #10241a, #244f38);
            color: #f8fafc;
            padding: clamp(2rem, 6vw, 4.5rem);
        }
        .share-kicker {
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(248, 250, 252, 0.68);
        }
        .share-hero h1 {
            margin-top: 1rem;
            font-size: clamp(2.4rem, 8vw, 5.8rem);
            line-height: 0.95;
            letter-spacing: -0.06em;
        }
        .share-body {
            display: grid;
            gap: 1.5rem;
            padding: clamp(1.25rem, 4vw, 2rem);
        }
        .share-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: rgba(236, 253, 245, 0.95);
            border: 1px solid rgba(5, 150, 105, 0.18);
            color: #064e3b;
            padding: 0.55rem 0.85rem;
            font-size: 12px;
            font-weight: 900;
        }
        .share-axis {
            border-radius: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #f8fafc;
            padding: 1rem;
        }
        .share-axis-track {
            height: 10px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.18);
        }
        .share-axis-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #14532d, #10b981);
        }
        .share-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #111827;
            color: #fff;
            padding: 0.95rem 1.25rem;
            font-size: 14px;
            font-weight: 900;
            text-decoration: none;
        }
    </style>
</head>
<body class="text-slate-900">
<main class="share-shell">
    <article class="share-card">
        <section class="share-hero">
            <div class="share-kicker">Modern Forestry scent personality</div>
            <h1>{{ $headline }}</h1>
            <p class="mt-5 max-w-2xl text-base leading-7 text-slate-100/85">{{ $body }}</p>
        </section>
        <section class="share-body">
            <div>
                <div class="text-sm font-black uppercase tracking-[0.16em] text-emerald-800">{{ $title }}</div>
                @if(count($dominantTraits) > 0)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($dominantTraits as $trait)
                            <span class="share-chip">{{ \Illuminate\Support\Str::headline((string) $trait) }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            @if(count($axes) > 0)
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach($axes as $axis)
                        @php
                            $label = (string) data_get($axis, 'label', data_get($axis, 'key', 'Scent'));
                            $score = max(0, min(100, (int) data_get($axis, 'score', 0)));
                        @endphp
                        <div class="share-axis">
                            <div class="flex items-center justify-between gap-3 text-sm font-black">
                                <span>{{ $label }}</span>
                                <span>{{ $score }}%</span>
                            </div>
                            <div class="share-axis-track mt-3">
                                <div class="share-axis-fill" style="width: {{ $score }}%;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="rounded-3xl border border-emerald-100 bg-emerald-50/80 p-5">
                <h2 class="text-xl font-black tracking-tight text-slate-950">Find your candle personality</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Take the quiz, save your scent profile to your account, and get candle recommendations that fit your actual preferences.</p>
                <a href="{{ $quizUrl }}" class="share-action mt-4">Take the scent quiz</a>
            </div>
        </section>
    </article>
</main>
</body>
</html>
