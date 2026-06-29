<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $headline = trim((string) ($result->headline ?? 'My Modern Forestry scent personality')) ?: 'My Modern Forestry scent personality';
        $title = trim((string) ($displayPersonalityTitle ?? $result->personality_title ?? 'Scent personality')) ?: 'Scent personality';
        $body = trim((string) ($result->personality_body ?? 'A Modern Forestry candle profile built from scent preferences.')) ?: 'A Modern Forestry candle profile built from scent preferences.';
        $dominantTraits = array_values(array_filter((array) ($dominantTraits ?? [])));
        $axes = array_values((array) ($axes ?? []));
        $shareSource = trim((string) ($shareSource ?? 'generic_share')) ?: 'generic_share';
        $saveResultsUrl = trim((string) ($saveResultsUrl ?? ''));
        $appDownloadUrl = trim((string) ($appDownloadUrl ?? ''));
        $shopYourMatchesUrl = trim((string) ($shopYourMatchesUrl ?? ''));
        $publicQuizPageUrl = trim((string) ($publicQuizPageUrl ?? ''));
        $publicQuizDefinition = is_array($publicQuizDefinition ?? null) ? $publicQuizDefinition : [];
        $publicQuizQuestions = is_array($publicQuizDefinition['questions'] ?? null) ? array_values($publicQuizDefinition['questions']) : [];
        $publicQuizResult = is_array($publicQuizResult ?? null) ? $publicQuizResult : null;
        $publicQuizAxes = is_array(data_get($publicQuizResult, 'axes')) ? array_values((array) data_get($publicQuizResult, 'axes')) : [];
        $recommendedProducts = is_array($recommendedProducts ?? null) ? array_values($recommendedProducts) : [];
        $publicQuizActionUrl = trim((string) ($publicQuizActionUrl ?? ''));
        $publicQuizEventUrl = trim((string) ($publicQuizEventUrl ?? ''));
        $pageMode = in_array((string) ($pageMode ?? 'landing'), ['landing', 'quiz', 'results'], true) ? (string) $pageMode : 'landing';
        $cardVersion = (string) (optional($result->updated_at)->getTimestamp() ?: $result->id);
        $shareUrl = url()->current();
        $shareImageUrl = route('marketing.public.scent-personality-share.image', ['token' => $result->public_share_token, 'v' => $cardVersion]);
        $logoUrl = asset('brand/forestry-backstage-intro-tree.png');
        $typeLabel = 'Scent Personality Type: '.$headline;
        $resultHeadline = trim((string) data_get($publicQuizResult, 'headline', '')) ?: $headline;
        $resultTitle = trim((string) data_get($publicQuizResult, 'personalityTitle', '')) ?: $title;
        $resultBody = trim((string) data_get($publicQuizResult, 'personalityBody', '')) ?: $body;
        $scoreAxes = $pageMode === 'results' && $publicQuizAxes !== [] ? $publicQuizAxes : $axes;
    @endphp
    <title>{{ $headline }} | Modern Forestry</title>
    <meta name="description" content="{{ $body }}">
    <meta property="og:title" content="{{ $typeLabel }}">
    <meta property="og:description" content="{{ $body }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $shareUrl }}">
    <meta property="og:image" content="{{ $shareImageUrl }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $headline }} scent personality card">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $typeLabel }}">
    <meta name="twitter:description" content="{{ $body }}">
    <meta name="twitter:image" content="{{ $shareImageUrl }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css'])
    <style>
        :root {
            --forest: #143d29;
            --moss: #24563a;
            --pine: #0e2419;
            --cream: #f8f1e8;
            --paper: #fffaf2;
            --ink: #101713;
            --muted: #6f695f;
            --line: rgba(16, 23, 19, 0.12);
            --glow: rgba(212, 174, 93, 0.24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at 9% 7%, rgba(219, 183, 99, 0.34), transparent 25%),
                radial-gradient(circle at 92% 16%, rgba(51, 107, 72, 0.42), transparent 30%),
                linear-gradient(135deg, #0c1d14 0%, #193524 48%, #f8f1e8 48%, #fffaf2 100%);
            color: var(--ink);
            overflow-x: hidden;
        }

        .shell {
            width: min(1160px, calc(100vw - 28px));
            margin: 0 auto;
            padding: clamp(18px, 4vw, 42px) 0 54px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
            color: #f9fafb;
            text-decoration: none;
        }

        .brand img {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            background: rgba(255, 255, 255, 0.08);
        }

        .brand-wordmark {
            display: grid;
            gap: 2px;
            line-height: 1;
        }

        .brand-wordmark strong {
            font-size: clamp(20px, 4vw, 34px);
            letter-spacing: 0.02em;
        }

        .brand-wordmark span {
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.36em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.66);
        }

        .card {
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: clamp(22px, 4vw, 38px);
            background: rgba(255, 250, 242, 0.97);
            box-shadow: 0 30px 90px rgba(8, 17, 12, 0.28);
        }

        .hero {
            display: grid;
            gap: clamp(22px, 4vw, 40px);
            grid-template-columns: minmax(0, 1.05fr) minmax(280px, 0.95fr);
            align-items: center;
            padding: clamp(28px, 6vw, 72px);
            background:
                radial-gradient(circle at top right, rgba(66, 151, 102, 0.42), transparent 38%),
                linear-gradient(135deg, #102519, #24563a);
            color: #f8fafc;
        }

        .kicker {
            max-width: 100%;
            overflow-wrap: anywhere;
            font-size: 12px;
            font-weight: 950;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: rgba(248, 250, 252, 0.72);
        }

        .hero h1,
        .page-title {
            margin: 14px 0 0;
            max-width: 100%;
            overflow-wrap: anywhere;
            font-size: clamp(38px, 8vw, 92px);
            line-height: 0.92;
            letter-spacing: -0.06em;
        }

        .copy {
            max-width: 720px;
            font-size: clamp(16px, 2vw, 19px);
            line-height: 1.62;
            color: rgba(248, 250, 252, 0.84);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 26px;
        }

        .action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            border: 0;
            border-radius: 999px;
            padding: 14px 22px;
            background: var(--pine);
            color: #fff;
            font: inherit;
            font-size: 15px;
            font-weight: 950;
            text-decoration: none;
            cursor: pointer;
        }

        .action.secondary {
            background: #f9fafb;
            color: var(--ink);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }

        .action.full {
            width: 100%;
        }

        .hero-panel,
        .panel {
            border: 1px solid var(--line);
            border-radius: 26px;
            background: rgba(255, 255, 255, 0.92);
            padding: clamp(18px, 3vw, 28px);
            color: var(--ink);
            box-shadow: 0 18px 40px rgba(8, 17, 12, 0.08);
        }

        .hero-panel h2,
        .panel h2 {
            margin: 0;
            font-size: clamp(28px, 4vw, 46px);
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .archetype {
            margin-top: 10px;
            font-size: 13px;
            font-weight: 950;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: #0d6b42;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 18px;
        }

        .chip {
            display: inline-flex;
            border-radius: 999px;
            background: rgba(20, 83, 45, 0.1);
            border: 1px solid rgba(20, 83, 45, 0.2);
            color: var(--forest);
            padding: 8px 13px;
            font-size: 12px;
            font-weight: 950;
        }

        .content {
            display: grid;
            gap: 18px;
            padding: clamp(18px, 4vw, 30px);
        }

        .score-panel[hidden] {
            display: none;
        }

        .score-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: minmax(260px, 0.9fr) minmax(0, 1.1fr);
            align-items: center;
        }

        .axis-grid,
        .match-grid {
            display: grid;
            gap: 12px;
        }

        .axis-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .axis {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: #f8fafc;
            padding: 14px;
        }

        .axis-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 15px;
            font-weight: 950;
        }

        .track {
            height: 11px;
            overflow: hidden;
            border-radius: 999px;
            margin-top: 12px;
            background: #e5e7eb;
        }

        .fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #14532d, #10b981);
        }

        .radar {
            border: 1px solid var(--line);
            border-radius: 28px;
            background: #fffaf2;
            padding: 18px;
        }

        .quiz-wrap {
            padding: clamp(18px, 4vw, 34px);
        }

        .progress {
            position: sticky;
            top: 0;
            z-index: 5;
            margin: -6px 0 22px;
            padding: 12px 0;
            background: linear-gradient(180deg, rgba(255, 250, 242, 0.98), rgba(255, 250, 242, 0.78));
            backdrop-filter: blur(12px);
        }

        .progress-bar {
            height: 12px;
            overflow: hidden;
            border-radius: 999px;
            background: #e7e1d7;
        }

        .progress-fill {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #14532d, #0ea66b);
            transition: width 0.22s ease;
        }

        .question {
            border: 1px solid var(--line);
            border-radius: 26px;
            background: #fff;
            padding: 18px;
        }

        .question + .question {
            margin-top: 14px;
        }

        .option {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 18px;
            padding: 13px 14px;
            background: #fbfaf8;
            color: #384038;
        }

        .option + .option {
            margin-top: 9px;
        }

        .option input {
            margin-top: 2px;
            accent-color: var(--forest);
        }

        .match-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .match {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: #fff;
        }

        .match img {
            display: block;
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            background: #ece7dd;
        }

        .muted {
            color: var(--muted);
        }

        @media (max-width: 880px) {
            .hero,
            .score-grid {
                grid-template-columns: 1fr;
            }

            .match-grid,
            .axis-grid {
                grid-template-columns: 1fr;
            }

            .hero {
                padding: 26px;
            }

            .brand {
                color: var(--ink);
            }

            .brand-wordmark span {
                color: rgba(16, 23, 19, 0.58);
            }
        }
    </style>
</head>
<body>
@php
    $radarAxes = $scoreAxes;
    $radarPoints = [];
    $count = count($radarAxes);
    if ($count > 0) {
        $center = 150;
        $radius = 104;
        foreach ($radarAxes as $index => $axis) {
            $angle = (-M_PI / 2) + ((2 * M_PI / max(1, $count)) * $index);
            $scoreRadius = (max(0, min(100, (int) data_get($axis, 'score', 0))) / 100) * $radius;
            $radarPoints[] = round($center + (cos($angle) * $scoreRadius), 2).','.round($center + (sin($angle) * $scoreRadius), 2);
        }
    }
@endphp

<main class="shell">
    <a class="brand" href="https://theforestrystudio.com">
        <img src="{{ $logoUrl }}" alt="Modern Forestry logo" loading="eager">
        <span class="brand-wordmark">
            <strong>Modern Forestry</strong>
            <span>Soy Candles</span>
        </span>
    </a>

    <article class="card">
        @if($pageMode === 'quiz')
            <section class="quiz-wrap">
                <div class="kicker" style="color: var(--forest);">Modern Forestry scent personality</div>
                <h1 class="page-title">{{ data_get($publicQuizDefinition, 'intro.title', 'Find your scent personality') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 muted">
                    {{ data_get($publicQuizDefinition, 'intro.body', 'Answer the quiz, see your scent map, and shop the top matches right away.') }}
                </p>

                <form method="POST" action="{{ $publicQuizActionUrl }}" data-public-quiz-form>
                    @csrf
                    <input type="hidden" name="source" value="{{ $shareSource }}">
                    <div class="progress" aria-label="Quiz progress">
                        <div class="mb-2 flex items-center justify-between text-sm font-black">
                            <span data-progress-label>Question 0 of {{ count($publicQuizQuestions) }}</span>
                            <span>{{ count($publicQuizQuestions) }} quick questions</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" data-progress-fill></div>
                        </div>
                    </div>

                    @foreach($publicQuizQuestions as $questionIndex => $question)
                        <section class="question">
                            <input type="hidden" name="answers[{{ $questionIndex }}][question_id]" value="{{ data_get($question, 'id') }}">
                            <div class="text-xs font-black uppercase tracking-[0.16em] text-emerald-800">Question {{ $questionIndex + 1 }} of {{ count($publicQuizQuestions) }}</div>
                            <h2 class="mt-2 text-xl font-black">{{ data_get($question, 'prompt', 'Question') }}</h2>
                            <div class="mt-4">
                                @foreach((array) data_get($question, 'options', []) as $option)
                                    <label class="option">
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

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                        <a href="{{ route('marketing.public.scent-personality-share', ['token' => $result->public_share_token, 'source' => $shareSource]) }}" class="action secondary">Back to profile</a>
                        <button type="submit" class="action">Show my results</button>
                    </div>
                </form>
            </section>
        @else
            <section class="hero">
                <div>
                    <div class="kicker">{{ $typeLabel }}</div>
                    <h1>{{ $pageMode === 'results' ? $resultHeadline : $headline }}</h1>
                    <p class="copy">{{ $pageMode === 'results' ? $resultBody : $body }}</p>
                    <div class="actions">
                        @if($publicQuizPageUrl !== '')
                            <a href="{{ $publicQuizPageUrl }}" class="action" data-quiz-trigger>Take the quiz</a>
                        @endif
                        @if($shopYourMatchesUrl !== '')
                            <a href="{{ $shopYourMatchesUrl }}" class="action secondary">Shop the vibe</a>
                        @endif
                    </div>
                </div>

                <aside class="hero-panel">
                    <div class="archetype">Archetype</div>
                    <h2>{{ $pageMode === 'results' ? $resultTitle : $title }}</h2>
                    <p class="mt-4 leading-7 muted">
                        {{ $pageMode === 'results'
                            ? 'Your quiz results are ready. These scent matches are built around your strongest profile traits.'
                            : 'Curious what your own candle personality looks like? Take the same 15-question quiz and let it lead you into the best-matching scents.' }}
                    </p>
                    <div class="chips">
                        @foreach($pageMode === 'results' ? (array) data_get($publicQuizResult, 'dominantTraits', []) : $dominantTraits as $trait)
                            <span class="chip">{{ \Illuminate\Support\Str::headline((string) $trait) }}</span>
                        @endforeach
                    </div>
                </aside>
            </section>

            <section class="content">
                <div class="panel">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="archetype">Scent map</div>
                            <h2>See where {{ $pageMode === 'results' ? 'you' : 'I' }} scored</h2>
                        </div>
                        <button class="action secondary" type="button" data-score-toggle>See where I scored</button>
                    </div>
                    <div class="score-panel mt-5" data-score-panel hidden>
                        <div class="score-grid">
                            <div class="radar">
                                <svg viewBox="0 0 300 300" width="100%" role="img" aria-label="Scent personality radar chart">
                                    @for($ring = 1; $ring <= 4; $ring++)
                                        @php
                                            $ringPoints = [];
                                            $center = 150;
                                            $radius = 104 * ($ring / 4);
                                            foreach ($radarAxes as $index => $axis) {
                                                $angle = (-M_PI / 2) + ((2 * M_PI / max(1, count($radarAxes))) * $index);
                                                $ringPoints[] = round($center + (cos($angle) * $radius), 2).','.round($center + (sin($angle) * $radius), 2);
                                            }
                                        @endphp
                                        <polygon points="{{ implode(' ', $ringPoints) }}" fill="none" stroke="rgba(111,105,95,0.22)" stroke-width="1.2"></polygon>
                                    @endfor
                                    @foreach($radarAxes as $index => $axis)
                                        @php
                                            $angle = (-M_PI / 2) + ((2 * M_PI / max(1, count($radarAxes))) * $index);
                                            $lineX = round(150 + (cos($angle) * 104), 2);
                                            $lineY = round(150 + (sin($angle) * 104), 2);
                                            $labelX = round(150 + (cos($angle) * 130), 2);
                                            $labelY = round(150 + (sin($angle) * 130), 2);
                                        @endphp
                                        <line x1="150" y1="150" x2="{{ $lineX }}" y2="{{ $lineY }}" stroke="rgba(111,105,95,0.22)" stroke-width="1.2"></line>
                                        <text x="{{ $labelX }}" y="{{ $labelY }}" text-anchor="middle" dominant-baseline="middle" font-size="11" font-weight="800" fill="#6f695f">{{ strtoupper((string) data_get($axis, 'label', 'SCENT')) }}</text>
                                    @endforeach
                                    @if($radarPoints !== [])
                                        <polygon points="{{ implode(' ', $radarPoints) }}" fill="rgba(20,83,45,0.18)" stroke="#14532d" stroke-width="3"></polygon>
                                    @endif
                                </svg>
                            </div>

                            <div class="axis-grid">
                                @foreach($scoreAxes as $axis)
                                    <div class="axis">
                                        <div class="axis-head">
                                            <span>{{ data_get($axis, 'label', 'Scent') }}</span>
                                            <span>{{ (int) data_get($axis, 'score', 0) }}%</span>
                                        </div>
                                        <div class="track">
                                            <div class="fill" style="width: {{ max(0, min(100, (int) data_get($axis, 'score', 0))) }}%;"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                @if($pageMode === 'results')
                    <div class="panel" data-save-results-prompt>
                        <div class="archetype">Next step</div>
                        <h2>Save it or take it shopping</h2>
                        <p class="mt-3 leading-7 muted">Save your profile to sync wishlist, rewards, Candle Cash, and app-only offers. Or start with the candle matches below.</p>
                        <div class="actions">
                            @if($saveResultsUrl !== '')
                                <a href="{{ $saveResultsUrl }}" class="action secondary">Save My Results</a>
                            @endif
                            @if($appDownloadUrl !== '')
                                <a href="{{ $appDownloadUrl }}" class="action secondary" data-app-install-cta>Download the App</a>
                            @endif
                        </div>
                    </div>
                @endif

                @if($recommendedProducts !== [])
                    <section class="panel" id="top-matches">
                        <div class="archetype">Top 4 scent matches</div>
                        <h2>Candles to start with</h2>
                        <p class="mt-3 leading-7 muted">These are the strongest matches from the quiz result, designed to move from curiosity into candle selection.</p>

                        <div class="match-grid mt-6">
                            @foreach($recommendedProducts as $product)
                                <article class="match">
                                    @if(trim((string) data_get($product, 'imageUrl', '')) !== '')
                                        <img src="{{ data_get($product, 'imageUrl') }}" alt="{{ data_get($product, 'title', 'Modern Forestry candle') }}" loading="lazy">
                                    @endif
                                    <div class="p-4">
                                        <div class="text-lg font-black">{{ data_get($product, 'title', 'Modern Forestry candle') }}</div>
                                        @if(trim((string) data_get($product, 'price', '')) !== '')
                                            <div class="mt-1 text-sm font-black text-emerald-900">${{ data_get($product, 'price') }}</div>
                                        @endif
                                        <p class="mt-3 text-sm leading-6 muted">{{ data_get($product, 'reason', 'A strong fit for this scent profile.') }}</p>
                                        <div class="mt-4 grid gap-2">
                                            <a href="{{ route('marketing.public.scent-personality-share.product', ['token' => $result->public_share_token, 'handle' => data_get($product, 'handle'), 'source' => $shareSource]) }}" class="action secondary full">View Product</a>
                                            <a href="{{ route('marketing.public.scent-personality-share.add-to-cart', ['token' => $result->public_share_token, 'handle' => data_get($product, 'handle'), 'source' => $shareSource]) }}" class="action full" data-add-to-cart-link>Add to Cart</a>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif
            </section>
        @endif
    </article>
</main>

<script>
    (function () {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const eventUrl = @json($publicQuizEventUrl);
        const source = @json($shareSource);
        const totalQuestions = {{ count($publicQuizQuestions) }};
        let quizStarted = false;

        function track(eventName) {
            if (!eventUrl || !csrfToken) {
                return;
            }

            fetch(eventUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ event: eventName, source: source }),
            }).catch(function () {});
        }

        function markQuizStarted() {
            if (quizStarted) {
                return;
            }

            quizStarted = true;
            track('quiz_started');
        }

        function updateProgress() {
            const answeredNames = new Set();
            document.querySelectorAll('[data-public-quiz-form] input[type="radio"]:checked').forEach(function (input) {
                answeredNames.add(input.name);
            });

            const answered = answeredNames.size;
            const percent = totalQuestions > 0 ? Math.round((answered / totalQuestions) * 100) : 0;
            const fill = document.querySelector('[data-progress-fill]');
            const label = document.querySelector('[data-progress-label]');
            if (fill) {
                fill.style.width = percent + '%';
            }
            if (label) {
                label.textContent = 'Question ' + answered + ' of ' + totalQuestions;
            }
        }

        document.querySelectorAll('[data-quiz-trigger]').forEach(function (button) {
            button.addEventListener('click', markQuizStarted, { once: true });
        });

        document.querySelectorAll('[data-public-quiz-form] input[type="radio"]').forEach(function (input) {
            input.addEventListener('change', function () {
                markQuizStarted();
                updateProgress();
            });
        });

        document.querySelectorAll('[data-score-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                const panel = document.querySelector('[data-score-panel]');
                if (!panel) {
                    return;
                }

                const willOpen = panel.hasAttribute('hidden');
                panel.toggleAttribute('hidden', !willOpen);
                button.textContent = willOpen ? 'Hide where I scored' : 'See where I scored';
                if (willOpen) {
                    track('score_opened');
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });

        document.querySelectorAll('[data-add-to-cart-link]').forEach(function (link) {
            link.addEventListener('click', function () {
                track('add_to_cart_clicked');
            });
        });

        if (document.querySelector('[data-save-results-prompt]')) {
            track('save_result_prompt_shown');
        }

        document.querySelectorAll('[data-app-install-cta]').forEach(function (button) {
            button.addEventListener('click', function () {
                track('app_download_clicked');
            });
        });

        updateProgress();
    })();
</script>
</body>
</html>
