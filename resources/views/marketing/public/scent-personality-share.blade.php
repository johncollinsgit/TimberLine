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
        $shareSource = trim((string) ($shareSource ?? 'generic_share')) ?: 'generic_share';
        $quizUrl = trim((string) ($quizUrl ?? 'https://theforestrystudio.com/apps/forestry/account?scent_quiz=1'));
        $saveResultsUrl = trim((string) ($saveResultsUrl ?? $quizUrl));
        $appDownloadUrl = trim((string) ($appDownloadUrl ?? ''));
        $shopYourMatchesUrl = trim((string) ($shopYourMatchesUrl ?? ''));
        $publicQuizDefinition = is_array($publicQuizDefinition ?? null) ? $publicQuizDefinition : [];
        $publicQuizQuestions = is_array($publicQuizDefinition['questions'] ?? null) ? array_values($publicQuizDefinition['questions']) : [];
        $publicQuizResult = is_array($publicQuizResult ?? null) ? $publicQuizResult : null;
        $publicQuizAxes = is_array(data_get($publicQuizResult, 'axes')) ? array_values((array) data_get($publicQuizResult, 'axes')) : [];
        $recommendedProducts = is_array($recommendedProducts ?? null) ? array_values($recommendedProducts) : [];
        $publicQuizActionUrl = trim((string) ($publicQuizActionUrl ?? ''));
        $publicQuizEventUrl = trim((string) ($publicQuizEventUrl ?? ''));
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
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
            width: min(1120px, calc(100vw - 2rem));
            margin: 0 auto;
            padding: 32px 0 48px;
        }
        .share-card {
            overflow: hidden;
            border-radius: 34px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.95);
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
            font-size: clamp(2.6rem, 8vw, 5.8rem);
            line-height: 0.94;
            letter-spacing: -0.06em;
        }
        .share-grid {
            display: grid;
            gap: 1.25rem;
            padding: clamp(1.25rem, 4vw, 2rem);
        }
        .share-panel {
            border-radius: 28px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fff;
            padding: 1.25rem;
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.06);
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
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #f8fafc;
            padding: 0.9rem;
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
        .share-action--secondary {
            background: #f8fafc;
            color: #111827;
            border: 1px solid rgba(15, 23, 42, 0.1);
        }
        .share-action--forest {
            background: #14532d;
        }
        .quiz-option input {
            accent-color: #14532d;
        }
        .quiz-card {
            border-radius: 22px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #fcfcfb;
            padding: 1rem;
        }
        .match-grid {
            display: grid;
            gap: 1rem;
        }
        .match-card {
            border-radius: 24px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            overflow: hidden;
            background: #fff;
        }
        .match-card img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            background: #f3f4f6;
        }
        .radar-wrap {
            border-radius: 24px;
            background:
                radial-gradient(circle at top, rgba(20, 83, 45, 0.08), transparent 40%),
                #fbfaf8;
            border: 1px solid rgba(15, 23, 42, 0.08);
            padding: 1rem;
        }
    </style>
</head>
<body class="text-slate-900">
@php
    $radarPoints = [];
    if ($publicQuizAxes !== []) {
        $count = count($publicQuizAxes);
        $center = 150;
        $radius = 108;
        foreach ($publicQuizAxes as $index => $axis) {
            $angle = (-M_PI / 2) + ((2 * M_PI / max(1, $count)) * $index);
            $scoreRadius = (max(0, min(100, (int) data_get($axis, 'score', 0))) / 100) * $radius;
            $x = $center + (cos($angle) * $scoreRadius);
            $y = $center + (sin($angle) * $scoreRadius);
            $radarPoints[] = round($x, 2).','.round($y, 2);
        }
    }
@endphp
<main class="share-shell">
    <article class="share-card">
        <section class="share-hero">
            <div class="share-kicker">Modern Forestry scent personality</div>
            <h1>{{ $headline }}</h1>
            <p class="mt-5 max-w-2xl text-base leading-7 text-slate-100/85">{{ $body }}</p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="#public-quiz" class="share-action share-action--forest" data-quiz-trigger>Take the quiz</a>
                @if($shopYourMatchesUrl !== '')
                    <a href="{{ $shopYourMatchesUrl }}" class="share-action share-action--secondary">Shop the vibe</a>
                @endif
            </div>
        </section>

        <section class="share-grid">
            <div class="grid gap-4 lg:grid-cols-[0.95fr_1.05fr]">
                <div class="share-panel">
                    <div class="text-sm font-black uppercase tracking-[0.16em] text-emerald-800">{{ $title }}</div>
                    @if(count($dominantTraits) > 0)
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($dominantTraits as $trait)
                                <span class="share-chip">{{ \Illuminate\Support\Str::headline((string) $trait) }}</span>
                            @endforeach
                        </div>
                    @endif
                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        Shared from {{ str_replace('_', ' ', $shareSource) }}. Curious what your own candle personality looks like? Take the same 15-question quiz below and let it lead you into the best-matching scents.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach($axes as $axis)
                        <div class="share-axis">
                            <div class="flex items-center justify-between gap-3 text-sm font-black">
                                <span>{{ data_get($axis, 'label', 'Scent') }}</span>
                                <span>{{ (int) data_get($axis, 'score', 0) }}%</span>
                            </div>
                            <div class="share-axis-track mt-3">
                                <div class="share-axis-fill" style="width: {{ max(0, min(100, (int) data_get($axis, 'score', 0))) }}%;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <section class="share-panel" id="public-quiz">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-black uppercase tracking-[0.16em] text-emerald-800">Take your own scent quiz</div>
                        <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-950">{{ data_get($publicQuizDefinition, 'intro.title', 'Find your scent personality') }}</h2>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            {{ data_get($publicQuizDefinition, 'intro.body', 'Answer the quiz, see your scent map, and shop the top matches right away.') }}
                        </p>
                    </div>
                    <span class="share-chip">{{ count($publicQuizQuestions) }} questions</span>
                </div>

                <form method="POST" action="{{ $publicQuizActionUrl }}" class="mt-6 grid gap-4" data-public-quiz-form>
                    @csrf
                    <input type="hidden" name="source" value="{{ $shareSource }}">
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach($publicQuizQuestions as $questionIndex => $question)
                            <section class="quiz-card">
                                <input type="hidden" name="answers[{{ $questionIndex }}][question_id]" value="{{ data_get($question, 'id') }}">
                                <div class="text-sm font-semibold text-slate-950">{{ data_get($question, 'prompt', 'Question') }}</div>
                                <div class="mt-3 grid gap-2">
                                    @foreach((array) data_get($question, 'options', []) as $option)
                                        <label class="quiz-option flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                            <input
                                                type="radio"
                                                name="answers[{{ $questionIndex }}][option_id]"
                                                value="{{ data_get($option, 'id') }}"
                                                required
                                            >
                                            <span>{{ data_get($option, 'label', 'Option') }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-slate-500">No app install required. Take the quiz here first, then decide if you want to save it to an account for wishlist syncing, rewards, and app-only perks.</p>
                        <button type="submit" class="share-action share-action--forest">Show my scent map</button>
                    </div>
                </form>
            </section>

            @if($publicQuizResult)
                <section class="share-panel" data-save-results-prompt>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="text-xs font-black uppercase tracking-[0.16em] text-emerald-800">Your result</div>
                            <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-950">{{ data_get($publicQuizResult, 'headline', 'Your scent profile') }}</h2>
                            <div class="mt-2 text-sm font-semibold text-emerald-900">{{ data_get($publicQuizResult, 'personalityTitle', 'Scent personality') }}</div>
                            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ data_get($publicQuizResult, 'personalityBody') }}</p>
                        </div>
                        @if($shopYourMatchesUrl !== '')
                            <a href="{{ $shopYourMatchesUrl }}" class="share-action share-action--forest">Shop Your Matches</a>
                        @endif
                    </div>

                    <div class="mt-6 grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">
                        <div class="radar-wrap">
                            <svg viewBox="0 0 300 300" class="mx-auto block w-full max-w-[320px]" role="img" aria-label="Scent personality radar chart">
                                @for($ring = 1; $ring <= 4; $ring++)
                                    @php
                                        $count = max(1, count($publicQuizAxes));
                                        $center = 150;
                                        $radius = 108 * ($ring / 4);
                                        $ringPoints = [];
                                        foreach ($publicQuizAxes as $index => $axis) {
                                            $angle = (-M_PI / 2) + ((2 * M_PI / $count) * $index);
                                            $ringPoints[] = round($center + (cos($angle) * $radius), 2).','.round($center + (sin($angle) * $radius), 2);
                                        }
                                    @endphp
                                    <polygon points="{{ implode(' ', $ringPoints) }}" fill="none" stroke="rgba(148,163,184,0.35)" stroke-width="1.2"></polygon>
                                @endfor
                                @foreach($publicQuizAxes as $index => $axis)
                                    @php
                                        $count = max(1, count($publicQuizAxes));
                                        $angle = (-M_PI / 2) + ((2 * M_PI / $count) * $index);
                                        $lineX = round(150 + (cos($angle) * 108), 2);
                                        $lineY = round(150 + (sin($angle) * 108), 2);
                                        $labelX = round(150 + (cos($angle) * 132), 2);
                                        $labelY = round(150 + (sin($angle) * 132), 2);
                                    @endphp
                                    <line x1="150" y1="150" x2="{{ $lineX }}" y2="{{ $lineY }}" stroke="rgba(148,163,184,0.35)" stroke-width="1.2"></line>
                                    <text x="{{ $labelX }}" y="{{ $labelY }}" text-anchor="middle" dominant-baseline="middle" font-size="11" font-weight="700" fill="#475569">
                                        {{ strtoupper((string) data_get($axis, 'label', 'SCENT')) }}
                                    </text>
                                @endforeach
                                @if($radarPoints !== [])
                                    <polygon points="{{ implode(' ', $radarPoints) }}" fill="rgba(20,83,45,0.18)" stroke="#14532d" stroke-width="3"></polygon>
                                @endif
                            </svg>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach((array) data_get($publicQuizResult, 'dominantTraits', []) as $trait)
                                    <span class="share-chip">{{ \Illuminate\Support\Str::headline((string) $trait) }}</span>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach($publicQuizAxes as $axis)
                                <div class="share-axis">
                                    <div class="flex items-center justify-between gap-3 text-sm font-black">
                                        <span>{{ data_get($axis, 'label', 'Axis') }}</span>
                                        <span>{{ (int) data_get($axis, 'score', 0) }}%</span>
                                    </div>
                                    <div class="share-axis-track mt-3">
                                        <div class="share-axis-fill" style="width: {{ max(0, min(100, (int) data_get($axis, 'score', 0))) }}%;"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ $saveResultsUrl }}" class="share-action share-action--secondary">Save My Results</a>
                        @if($appDownloadUrl !== '')
                            <a href="{{ $appDownloadUrl }}" class="share-action share-action--secondary" data-app-install-cta>Get the App</a>
                        @endif
                    </div>
                </section>
            @endif

            @if($recommendedProducts !== [])
                <section class="share-panel" id="top-matches">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="text-xs font-black uppercase tracking-[0.16em] text-emerald-800">Top 4 scent matches</div>
                            <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-950">Candles to start with</h2>
                            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">These are the strongest matches from your quiz result, designed to move you straight from curiosity into candle selection.</p>
                        </div>
                        @if($shopYourMatchesUrl !== '')
                            <a href="{{ $shopYourMatchesUrl }}" class="share-action share-action--forest">Shop Your Matches</a>
                        @endif
                    </div>

                    <div class="match-grid mt-6 md:grid-cols-2 xl:grid-cols-4">
                        @foreach($recommendedProducts as $product)
                            <article class="match-card">
                                @if(trim((string) data_get($product, 'imageUrl', '')) !== '')
                                    <img src="{{ data_get($product, 'imageUrl') }}" alt="{{ data_get($product, 'title', 'Modern Forestry candle') }}" loading="lazy">
                                @endif
                                <div class="p-4">
                                    <div class="text-lg font-black tracking-tight text-slate-950">{{ data_get($product, 'title', 'Modern Forestry candle') }}</div>
                                    @if(trim((string) data_get($product, 'price', '')) !== '')
                                        <div class="mt-1 text-sm font-semibold text-emerald-900">${{ data_get($product, 'price') }}</div>
                                    @endif
                                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ data_get($product, 'reason', 'A strong fit for your scent profile.') }}</p>
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <a
                                            href="{{ route('marketing.public.scent-personality-share.product', ['token' => $result->public_share_token, 'handle' => data_get($product, 'handle'), 'source' => $shareSource]) }}"
                                            class="share-action share-action--secondary"
                                        >View Product</a>
                                        <a
                                            href="{{ route('marketing.public.scent-personality-share.add-to-cart', ['token' => $result->public_share_token, 'handle' => data_get($product, 'handle'), 'source' => $shareSource]) }}"
                                            class="share-action share-action--forest"
                                        >Add to Cart</a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </section>
    </article>
</main>

<script>
    (function () {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const eventUrl = @json($publicQuizEventUrl);
        const source = @json($shareSource);
        let quizStarted = false;

        async function track(eventName) {
            if (!eventUrl || !csrfToken) {
                return;
            }

            try {
                await fetch(eventUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        event: eventName,
                        source: source,
                    }),
                });
            } catch (error) {
                void error;
            }
        }

        function markQuizStarted() {
            if (quizStarted) {
                return;
            }

            quizStarted = true;
            track('quiz_started');
        }

        document.querySelectorAll('[data-quiz-trigger]').forEach(function (button) {
            button.addEventListener('click', markQuizStarted, { once: true });
        });

        document.querySelectorAll('[data-public-quiz-form] input[type="radio"]').forEach(function (input) {
            input.addEventListener('change', markQuizStarted, { once: true });
        });

        const prompt = document.querySelector('[data-save-results-prompt]');
        if (prompt) {
            track('save_result_prompt_shown');
        }

        document.querySelectorAll('[data-app-install-cta]').forEach(function (button) {
            button.addEventListener('click', function () {
                track('app_install_cta_clicked');
            });
        });
    })();
</script>
</body>
</html>
