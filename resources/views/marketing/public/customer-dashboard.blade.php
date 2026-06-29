<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $displayLabels = is_array($displayLabels ?? null) ? $displayLabels : [];
        $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards')) ?: 'Rewards';
        $rewardCreditLabel = trim((string) ($displayLabels['reward_credit_label'] ?? 'reward credit')) ?: 'reward credit';
        $copy = is_array($contentPublished ?? null)
            ? $contentPublished
            : (is_array($contentDefaults ?? null) ? $contentDefaults : []);
        $brandName = trim((string) ($copy['brand_name'] ?? 'Modern Forestry')) ?: 'Modern Forestry';
        $supportLink = trim((string) ($supportLink ?? ''));
        $privacyUrl = trim((string) data_get($copy, 'privacy_url', ''));
        $termsUrl = trim((string) data_get($copy, 'terms_url', ''));
        $dataDeletionUrl = trim((string) data_get($copy, 'data_deletion_url', ''));
        $dataDeletionEmail = trim((string) data_get($copy, 'data_deletion_email', data_get($copy, 'support_email', '')));
        $dataDeletionLink = $dataDeletionUrl !== '' ? $dataDeletionUrl : ($dataDeletionEmail !== '' ? 'mailto:'.$dataDeletionEmail : '');
        $rewardsLookupQuery = array_filter([
            'email' => request('email'),
            'phone' => request('phone'),
            'store_key' => request('store_key'),
            'shop' => request('shop'),
        ]);
        $rewardBalance = data_get($balance, 'candle_cash_amount_formatted', '$0.00');
        $ordersCount = is_countable($orders ?? null) ? count($orders) : 0;
        $rewardsCount = is_countable($availableRewards ?? null) ? count($availableRewards) : 0;
        $messagesThread = is_array($messages ?? null) ? (array) $messages : [];
        $messagesList = is_array($messagesThread['messages'] ?? null) ? array_values((array) $messagesThread['messages']) : [];
        $messagesPhoneDisplay = trim((string) ($messagesThread['phone_display'] ?? ''));
        if ($messagesPhoneDisplay === '') {
            $messagesPhoneDisplay = $profile ? (string) ($profile->phone ?? 'No phone on file') : 'No phone on file';
        }
        $messagesSupportPrompt = trim((string) ($messagesThread['support_prompt'] ?? ''));
        $messagesComposerEnabled = (bool) ($messagesThread['can_compose'] ?? false);
        $messagesNotice = trim((string) ($messageNotice ?? ''));
        $scentQuizDefinition = is_array($scentQuiz ?? null) ? $scentQuiz : null;
        $latestScentQuiz = is_array($scentQuizDefinition['latestResult'] ?? null) ? $scentQuizDefinition['latestResult'] : null;
        $scentQuizQuestions = is_array($scentQuizDefinition['questions'] ?? null) ? array_values($scentQuizDefinition['questions']) : [];
        $scentQuizAxes = is_array($latestScentQuiz['axes'] ?? null) ? array_values($latestScentQuiz['axes']) : [];
        $scentQuizNotice = trim((string) ($scentQuizNotice ?? ''));
        $scentQuizOpen = request()->boolean('scent_quiz') || $scentQuizNotice !== '' || $latestScentQuiz === null;
        $scentQuizShopUrl = '/collections/all?mf_source_label=scent_quiz&mf_template_key=modern_forestry_scent_quiz&mf_module_type=scent_quiz&mf_link_label=Shop%20my%20profile';
        $socialShareConfig = is_array($socialShareConfig ?? null) ? $socialShareConfig : null;
        $socialShareRewardLabel = (string) data_get($socialShareConfig, 'reward.label', '$1 Candle Cash');
        $socialShareScentTarget = is_array(data_get($socialShareConfig, 'scentPersonality')) ? data_get($socialShareConfig, 'scentPersonality') : null;
        $socialShareStartedUrl = trim((string) ($socialShareStartedUrl ?? ''));
        $socialShareClaimUrl = trim((string) ($socialShareClaimUrl ?? ''));
        $socialShareNotice = trim((string) ($socialShareNotice ?? ''));
    @endphp
    <title>{{ $brandName }} Account</title>
    @vite(['resources/css/app.css'])
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(16, 185, 129, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(120, 113, 108, 0.12), transparent 24%),
                linear-gradient(180deg, #f5efe7 0%, #fbfaf8 54%, #f7f4ee 100%);
        }
        .forest-shell {
            width: min(1180px, calc(100vw - 2rem));
            margin: 0 auto;
            padding: 24px 0 40px;
        }
        .forest-hero {
            border-radius: 28px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background:
                radial-gradient(circle at top right, rgba(15, 118, 110, 0.28), transparent 28%),
                linear-gradient(135deg, rgba(14, 42, 36, 0.98), rgba(23, 68, 55, 0.96));
            color: #f8fafc;
            padding: 28px;
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
        }
        .forest-hero h1 {
            font-size: clamp(2rem, 4vw, 3.4rem);
            line-height: 1.02;
            letter-spacing: -0.04em;
        }
        .forest-kicker {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(248, 250, 252, 0.68);
        }
        .forest-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.08);
            padding: 0.45rem 0.8rem;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
        }
        .forest-card {
            border-radius: 24px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.05);
        }
        .forest-card h2,
        .forest-card h3 {
            letter-spacing: -0.02em;
        }
        .forest-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.78rem 1rem;
            font-size: 13px;
            font-weight: 800;
            transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
        }
        .forest-action:hover {
            transform: translateY(-1px);
        }
        .forest-message-pane {
            display: grid;
            gap: 14px;
        }
        .forest-message-thread {
            display: grid;
            gap: 10px;
            max-height: 340px;
            overflow: auto;
            padding-right: 4px;
        }
        .forest-message-row {
            display: grid;
            gap: 4px;
            max-width: 82%;
        }
        .forest-message-row.is-outbound {
            justify-self: end;
            text-align: right;
        }
        .forest-message-row.is-inbound {
            justify-self: start;
        }
        .forest-message-bubble {
            border-radius: 18px;
            padding: 12px 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.9);
            color: #0f172a;
            font-size: 13px;
            line-height: 1.55;
            white-space: pre-wrap;
        }
        .forest-message-row.is-outbound .forest-message-bubble {
            background: linear-gradient(180deg, rgba(236, 253, 245, 1), rgba(209, 250, 229, 0.88));
            border-color: rgba(15, 118, 110, 0.18);
        }
        .forest-message-meta {
            font-size: 11px;
            color: rgba(15, 23, 42, 0.52);
        }
        .forest-message-composer textarea {
            width: 100%;
            min-height: 120px;
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            padding: 14px 16px;
            background: #fff;
            color: #0f172a;
            resize: vertical;
        }
        .forest-message-composer .forest-action {
            border: 0;
            cursor: pointer;
        }
        .forest-quiz-shell {
            display: grid;
            gap: 1rem;
        }
        .forest-quiz-axis-row {
            display: grid;
            gap: 0.55rem;
        }
        .forest-quiz-axis-track {
            width: 100%;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.16);
            height: 10px;
        }
        .forest-quiz-axis-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(5, 150, 105, 0.95), rgba(16, 185, 129, 0.72));
        }
        .forest-quiz-question {
            border-radius: 22px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.9);
            padding: 1rem;
        }
        .forest-quiz-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .forest-quiz-option span {
            display: block;
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: #fff;
            padding: 0.85rem 0.95rem;
            font-size: 13px;
            line-height: 1.5;
            color: #0f172a;
            transition: border-color 0.16s ease, background 0.16s ease, transform 0.16s ease;
        }
        .forest-quiz-option input:checked + span {
            border-color: rgba(5, 150, 105, 0.45);
            background: rgba(236, 253, 245, 0.95);
            transform: translateY(-1px);
        }
        .forest-quiz-details {
            border-radius: 24px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.88);
            padding: 1rem;
        }
        .forest-quiz-details summary {
            cursor: pointer;
            list-style: none;
        }
        .forest-quiz-details summary::-webkit-details-marker {
            display: none;
        }
        .forest-home-banner {
            position: relative;
            overflow: hidden;
            border-radius: 26px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background:
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.18), transparent 34%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(240, 253, 250, 0.92));
            padding: 1.25rem;
        }
        .forest-social-share {
            border-radius: 22px;
            border: 1px solid rgba(5, 150, 105, 0.18);
            background:
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.18), transparent 36%),
                linear-gradient(135deg, rgba(240, 253, 244, 0.95), rgba(255, 255, 255, 0.92));
            padding: 1rem;
        }
        .forest-social-share button {
            border: 0;
            cursor: pointer;
        }
        .forest-social-state {
            min-height: 1.25rem;
            font-size: 12px;
            font-weight: 700;
            color: #047857;
        }
    </style>
</head>
<body class="min-h-screen text-slate-900">
<main class="forest-shell space-y-6">
    <section class="forest-hero">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl space-y-4">
                <div class="forest-kicker">{{ $brandName }} account</div>
                <h1>{{ data_get($copy, 'hero_title', 'Your account') }}</h1>
                <p class="max-w-2xl text-sm leading-6 text-slate-200/90 sm:text-base">
                    {{ data_get($copy, 'hero_body', 'Check rewards, recent orders, and quick actions in one place.') }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <a href="#rewards" class="forest-action bg-emerald-200 text-emerald-950">{{ data_get($copy, 'primary_cta_label', 'View rewards') }}</a>
                    <a href="#orders" class="forest-action border border-white/15 bg-white/10 text-white">{{ data_get($copy, 'secondary_cta_label', 'Review orders') }}</a>
                    @if($profile)
                        <a href="#scent-quiz" class="forest-action border border-white/15 bg-white/10 text-white">Take scent quiz</a>
                    @endif
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[340px] lg:grid-cols-2">
                <div class="forest-chip">{{ $rewardBalance }} {{ $rewardsLabel }}</div>
                <div class="forest-chip">{{ $ordersCount }} orders</div>
                <div class="forest-chip">{{ $rewardsCount }} rewards</div>
                <div class="forest-chip">{{ $profile ? 'Linked account' : 'Lookup required' }}</div>
            </div>
        </div>
    </section>

    <section class="forest-card p-4 sm:p-5">
        <form method="GET" action="{{ route('marketing.public.account-rewards') }}" class="grid gap-3 lg:grid-cols-[1fr_1fr_auto_auto]">
            <input type="email" name="email" value="{{ request('email') }}" placeholder="Email" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm">
            <input type="text" name="phone" value="{{ request('phone') }}" placeholder="Phone" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm">
            @if(request('store_key'))
                <input type="hidden" name="store_key" value="{{ request('store_key') }}">
            @endif
            @if(request('shop'))
                <input type="hidden" name="shop" value="{{ request('shop') }}">
            @endif
            <button type="submit" class="forest-action bg-slate-900 text-white">Check rewards</button>
            <a href="{{ route('marketing.public.account-rewards', $rewardsLookupQuery) }}" class="forest-action border border-slate-200 bg-white text-slate-700">Rewards lookup</a>
        </form>
        <div class="mt-3 text-xs text-slate-500">
            {{ $profile ? 'Your account is linked.' : 'Use the same email and phone you used at checkout.' }}
            @if(($lookupState ?? '') === 'verification_required')
                Open with both fields to continue.
            @elseif(($lookupState ?? '') === 'needs_verification')
                We need to verify this identity before showing customer details.
            @elseif(($lookupState ?? '') === 'unknown_customer')
                No linked customer found yet.
            @elseif(($lookupState ?? '') === 'customer_login_required')
                Sign in on the storefront to open orders and account activity.
            @endif
        </div>
    </section>

    @if($profile)
        @if($socialShareNotice !== '')
            <section class="forest-card p-4 text-sm font-semibold text-emerald-950">
                {{ $socialShareNotice }}
            </section>
        @endif
        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="forest-card p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ data_get($copy, 'rewards_title', 'Rewards') }}</div>
                <div class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ $rewardBalance }}</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ data_get($copy, 'rewards_body', 'Redeem on Shopify checkout when you are ready.') }}</p>
            </article>
            <article class="forest-card p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</div>
                <div class="mt-3 space-y-2 text-sm text-slate-700">
                    <div>SMS: <strong>{{ $profile->accepts_sms_marketing ? 'On' : 'Off' }}</strong></div>
                    <div>Email: <strong>{{ $profile->accepts_email_marketing ? 'On' : 'Off' }}</strong></div>
                    <div>Profile: <strong>{{ $profile->email ? 'Verified' : 'Limited' }}</strong></div>
                </div>
            </article>
            <article class="forest-card p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Orders</div>
                <div class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ $ordersCount }}</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ data_get($copy, 'orders_body', 'Reorder the items you want again with a Shopify cart handoff.') }}</p>
            </article>
            <article class="forest-card p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Support</div>
                <div class="mt-3 text-sm font-semibold text-slate-950">{{ data_get($copy, 'support_title', 'Support') }}</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ data_get($copy, 'support_body', 'Need help? Reach out and we will follow up.') }}</p>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.08fr_0.92fr]" id="scent-quiz">
            <article class="forest-card p-5">
                <div class="forest-quiz-shell">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Scent quiz</div>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">{{ data_get($scentQuizDefinition, 'intro.title', 'Find your scent personality') }}</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                {{ data_get($scentQuizDefinition, 'intro.body', 'Answer the quiz, save the result to your account, and let that profile follow you into shopping.') }}
                            </p>
                        </div>
                        <a href="{{ $scentQuizShopUrl }}" class="forest-action bg-slate-900 text-white">Shop with this profile</a>
                    </div>

                    @if($scentQuizNotice !== '')
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                            {{ $scentQuizNotice }}
                        </div>
                    @endif

                    @if($latestScentQuiz)
                        <div class="grid gap-4 lg:grid-cols-[0.92fr_1.08fr]">
                            <div class="home-banner">
                                <div class="forest-home-banner">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Saved to your account</div>
                                    <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ data_get($latestScentQuiz, 'headline', 'Your scent profile') }}</div>
                                    <div class="mt-2 text-sm font-semibold text-emerald-900">{{ data_get($latestScentQuiz, 'personalityTitle', 'Scent personality') }}</div>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ data_get($latestScentQuiz, 'personalityBody') }}</p>
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach((array) data_get($latestScentQuiz, 'dominantTraits', []) as $trait)
                                            <span class="forest-chip border-emerald-200 bg-emerald-50 text-emerald-900">{{ \Illuminate\Support\Str::headline((string) $trait) }}</span>
                                        @endforeach
                                    </div>
                                    @if($socialShareScentTarget)
                                        <div class="forest-social-share mt-4" data-social-share-box>
                                            <div class="text-sm font-semibold text-slate-950">Share your candle personality</div>
                                            <p class="mt-1 text-xs leading-5 text-slate-600">Share this result and claim {{ $socialShareRewardLabel }} once per platform.</p>
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $platform => $label)
                                                    <button
                                                        type="button"
                                                        class="forest-action bg-emerald-200 text-emerald-950"
                                                        data-social-share
                                                        data-platform="{{ $platform }}"
                                                        data-target='@json($socialShareScentTarget)'
                                                    >Share on {{ $label }}</button>
                                                    <button
                                                        type="button"
                                                        class="forest-action border border-slate-200 bg-white text-slate-700"
                                                        data-social-claim
                                                        data-platform="{{ $platform }}"
                                                        data-target='@json($socialShareScentTarget)'
                                                    >I shared on {{ $label }}</button>
                                                @endforeach
                                            </div>
                                            <div class="forest-social-state mt-2" data-social-state></div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach($scentQuizAxes as $axis)
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex items-center justify-between gap-3 text-sm font-semibold text-slate-900">
                                            <span>{{ data_get($axis, 'label', 'Axis') }}</span>
                                            <span>{{ (int) data_get($axis, 'score', 0) }}%</span>
                                        </div>
                                        <div class="forest-quiz-axis-track mt-3">
                                            <div class="forest-quiz-axis-fill" style="width: {{ max(0, min(100, (int) data_get($axis, 'score', 0))) }}%;"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <details class="forest-quiz-details" {{ $scentQuizOpen ? 'open' : '' }}>
                        <summary class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-semibold text-slate-950">{{ $latestScentQuiz ? 'Retake your scent quiz' : 'Take your scent quiz' }}</div>
                                <div class="mt-1 text-xs text-slate-500">Results stay tied to this customer account for future shopping and reporting.</div>
                            </div>
                            <span class="forest-action border border-slate-200 bg-white text-slate-700">{{ count($scentQuizQuestions) }} questions</span>
                        </summary>

                        <form method="POST" action="{{ $scentQuizActionUrl }}" class="mt-4 grid gap-4">
                            @csrf
                            <div class="grid gap-4 lg:grid-cols-2">
                                @foreach($scentQuizQuestions as $questionIndex => $question)
                                    <section class="forest-quiz-question">
                                        <input type="hidden" name="answers[{{ $questionIndex }}][question_id]" value="{{ data_get($question, 'id') }}">
                                        <div class="text-sm font-semibold text-slate-950">{{ data_get($question, 'prompt', 'Question') }}</div>
                                        <div class="mt-3 grid gap-2">
                                            @foreach((array) data_get($question, 'options', []) as $option)
                                                <label class="forest-quiz-option">
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
                                <p class="text-xs text-slate-500">Saving the quiz refreshes your account profile and enables quiz-attributed wishlist and purchase tracking.</p>
                                <button type="submit" class="forest-action bg-emerald-200 text-emerald-950">Save scent profile</button>
                            </div>
                        </form>
                    </details>
                </div>
            </article>

            <article class="forest-card p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Why this matters</div>
                <h2 class="mt-2 text-lg font-semibold text-slate-950">Turn scent taste into a living profile</h2>
                <div class="mt-3 grid gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-semibold text-slate-950">Saved to your account</div>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Each result is attached to your customer record, so the latest quiz stays with your account instead of disappearing after one visit.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-semibold text-slate-950">Measured against shopping behavior</div>
                        <p class="mt-1 text-sm leading-6 text-slate-600">When the quiz sends you back into shopping, we can measure quiz-driven wishlist adds and candle purchases over time.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-semibold text-slate-950">Weekly team visibility</div>
                        <p class="mt-1 text-sm leading-6 text-slate-600">A weekly summary now goes to <strong>info@theforestrystudio.com</strong> with recent takers, total takers, wishlist adds, and purchases tied back to the quiz.</p>
                    </div>
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
            <article class="forest-card p-5" id="rewards">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ data_get($copy, 'rewards_title', 'Rewards') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ data_get($copy, 'rewards_body', 'Redeem on Shopify checkout when you are ready.') }}</p>
                    </div>
                    <span class="forest-chip border-slate-200 bg-slate-50 text-slate-700">{{ $rewardsCount }} active</span>
                </div>

                <div class="mt-4 grid gap-3">
                    @forelse($availableRewards as $reward)
                        @php
                            $canRedeem = (bool) data_get($reward, 'is_redeemable_now', false);
                            $rewardId = (int) data_get($reward, 'id', 0);
                        @endphp
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">{{ data_get($reward, 'name', 'Reward') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ data_get($reward, 'candle_cash_amount_formatted', '$0.00') }} · Shopify checkout handoff</div>
                                </div>
                                @if($profile && $rewardId > 0)
                                    <form method="POST" action="{{ route('marketing.public.account-rewards.redeem') }}" class="shrink-0">
                                        @csrf
                                        <input type="hidden" name="email" value="{{ $profile->email }}">
                                        <input type="hidden" name="phone" value="{{ $profile->phone }}">
                                        <input type="hidden" name="reward_id" value="{{ $rewardId }}">
                                        <button type="submit" class="forest-action {{ $canRedeem ? 'bg-emerald-200 text-emerald-950' : 'bg-slate-200 text-slate-500' }}" {{ $canRedeem ? '' : 'disabled' }}>
                                            {{ $canRedeem ? 'Redeem' : 'Balance short' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                            @if(data_get($reward, 'description'))
                                <div class="mt-3 text-sm leading-6 text-slate-600">{{ data_get($reward, 'description') }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-5 text-sm text-slate-500">
                            {{ data_get($copy, 'empty_rewards', 'No active rewards right now.') }}
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="forest-card p-5">
                <div class="flex items-start justify-between gap-4" id="orders">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ data_get($copy, 'orders_title', 'Recent orders') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ data_get($copy, 'orders_body', 'Reorder the items you want again with a Shopify cart handoff.') }}</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3">
                    @forelse($orders as $order)
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">{{ data_get($order, 'title') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ data_get($order, 'ordered_at') ? \Illuminate\Support\Carbon::parse((string) data_get($order, 'ordered_at'))->format('M j, Y') : 'Recently' }} · {{ data_get($order, 'line_count', 0) }} items</div>
                                </div>
                                @if(data_get($order, 'reorder_url'))
                                    <a href="{{ data_get($order, 'reorder_url') }}" target="_blank" rel="noreferrer" class="forest-action bg-slate-900 text-white">Reorder in Shopify</a>
                                @endif
                            </div>
                            <div class="mt-3 text-sm text-slate-600">
                                {{ data_get($order, 'line_preview') ?: data_get($copy, 'empty_orders', 'No recent orders yet.') }}
                            </div>
                            @if(is_array(data_get($order, 'lines')) && count((array) data_get($order, 'lines')) > 0)
                                <div class="mt-3 grid gap-2">
                                    @foreach((array) data_get($order, 'lines') as $line)
                                        @php
                                            $lineHandle = trim((string) data_get($line, 'handle', ''));
                                            $lineTarget = [
                                                'type' => data_get($line, 'share_target_type', 'purchased_product'),
                                                'id' => data_get($line, 'share_target_id'),
                                                'handle' => $lineHandle,
                                                'title' => data_get($line, 'title'),
                                                'imageUrl' => data_get($line, 'image_url'),
                                            ];
                                        @endphp
                                        @if($lineHandle !== '')
                                            <div class="rounded-2xl border border-white bg-white/80 p-3" data-social-share-box>
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <div class="text-sm font-semibold text-slate-950">{{ data_get($line, 'title', 'Purchased item') }}</div>
                                                        <div class="mt-1 text-xs text-slate-500">Qty {{ data_get($line, 'quantity', 1) }} · Share for {{ $socialShareRewardLabel }}</div>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2">
                                                        @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $platform => $label)
                                                            <button
                                                                type="button"
                                                                class="forest-action {{ $platform === 'facebook' ? 'bg-emerald-200 text-emerald-950' : 'border border-slate-200 bg-white text-slate-700' }}"
                                                                data-social-share
                                                                data-platform="{{ $platform }}"
                                                                data-target='@json($lineTarget)'
                                                            >{{ $label }}</button>
                                                            <button
                                                                type="button"
                                                                class="forest-action border border-slate-200 bg-white text-slate-700"
                                                                data-social-claim
                                                                data-platform="{{ $platform }}"
                                                                data-target='@json($lineTarget)'
                                                            >I shared</button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <div class="forest-social-state mt-2" data-social-state></div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <div class="mt-2 text-xs text-slate-500">
                                {{ data_get($order, 'total_price_formatted') }} · {{ strtoupper((string) data_get($order, 'status', 'open')) }}
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-5 text-sm text-slate-500">
                            {{ data_get($copy, 'empty_orders', 'No recent orders yet.') }}
                        </div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.05fr_0.95fr]">
            <article class="forest-card p-5" id="messages">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Messages</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ $messagesSupportPrompt !== '' ? $messagesSupportPrompt : 'Send a question here and we will keep the conversation threaded with Shopify Messages.' }}
                        </p>
                    </div>
                    <span class="forest-chip border-slate-200 bg-slate-50 text-slate-700">{{ $messagesPhoneDisplay }}</span>
                </div>

                @if($messagesNotice !== '')
                    <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                        {{ $messagesNotice }}
                    </div>
                @endif

                @if($messagesComposerEnabled)
                    <form method="POST" action="{{ $messageActionUrl }}" class="forest-message-composer mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="email" value="{{ $profile->email }}">
                        <input type="hidden" name="phone" value="{{ $profile->phone }}">
                        <textarea name="message_body" rows="5" maxlength="2000" placeholder="Write your message here. Our team will see it in the Messages inbox and can reply by text.">{{ old('message_body') }}</textarea>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-slate-500">Replies stay connected to your account and the Shopify inbox.</p>
                            <button type="submit" class="forest-action bg-slate-900 text-white">Send message</button>
                        </div>
                    </form>
                @else
                    <div class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-600">
                        Messages are unavailable for this account right now.
                        @if($supportLink)
                            <a href="{{ $supportLink }}" class="font-semibold text-emerald-700 hover:underline">Contact support</a>.
                        @endif
                    </div>
                @endif
            </article>

            <article class="forest-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Conversation</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Your recent messages and replies from Modern Forestry appear here.</p>
                    </div>
                    <span class="forest-chip border-slate-200 bg-slate-50 text-slate-700">{{ count($messagesList) }} messages</span>
                </div>

                <div class="mt-4 forest-message-thread">
                    @forelse($messagesList as $message)
                        @php
                            $direction = (string) ($message['direction'] ?? 'inbound');
                            $isOutbound = $direction === 'outbound';
                            $messageTime = trim((string) ($message['created_at'] ?? ''));
                            $messageTime = $messageTime !== '' ? \Illuminate\Support\Carbon::parse($messageTime)->format('M j, Y · g:i A') : 'Just now';
                            $messageLabel = $isOutbound ? 'Modern Forestry' : 'You';
                        @endphp
                        <div class="forest-message-row {{ $isOutbound ? 'is-outbound' : 'is-inbound' }}">
                            <div class="forest-message-meta">{{ $messageLabel }} · {{ $messageTime }}</div>
                            <div class="forest-message-bubble">{{ $message['body'] ?? '' }}</div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-500">
                            No messages yet. Start the conversation on the left and we will keep it threaded here.
                        </div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
            <article class="forest-card p-5">
                <h2 class="text-lg font-semibold text-slate-950">Support</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ data_get($copy, 'support_body', 'Need help? Reach out and we will follow up.') }}</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    @if($supportLink)
                        <a href="{{ $supportLink }}" class="forest-action bg-emerald-200 text-emerald-950">{{ data_get($copy, 'support_cta_label', 'Contact support') }}</a>
                    @endif
                    <a href="{{ route('marketing.public.account-rewards', $rewardsLookupQuery) }}" class="forest-action border border-slate-200 bg-white text-slate-700">Open rewards lookup</a>
                    @if($privacyUrl !== '')
                        <a href="{{ $privacyUrl }}" class="forest-action border border-slate-200 bg-white text-slate-700">Privacy</a>
                    @endif
                    @if($termsUrl !== '')
                        <a href="{{ $termsUrl }}" class="forest-action border border-slate-200 bg-white text-slate-700">Terms</a>
                    @endif
                    @if($dataDeletionLink !== '')
                        <a href="{{ $dataDeletionLink }}" class="forest-action border border-slate-200 bg-white text-slate-700">Data requests</a>
                    @endif
                </div>
                <div class="mt-4 text-xs text-slate-500">
                    {{ data_get($copy, 'account_note', 'Live customer copy uses the published snapshot only.') }}
                </div>
            </article>

            <article class="forest-card p-5">
                <h2 class="text-lg font-semibold text-slate-950">Recent activity</h2>
                <div class="mt-4 grid gap-2">
                    @forelse($transactions->take(5) as $transaction)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-sm font-semibold text-slate-950">{{ data_get($transaction, 'category', 'Activity') }}</div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ data_get($transaction, 'signed_candle_cash_amount_formatted', '$0.00') }} · {{ data_get($transaction, 'description') ?: data_get($transaction, 'source') }}
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-5 text-sm text-slate-500">
                            No recent activity yet.
                        </div>
                    @endforelse
                </div>
            </article>
        </section>
    @else
        <section class="forest-card p-5">
            <h2 class="text-lg font-semibold text-slate-950">Find your account</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">
                Enter your email and phone to check rewards. Sign in on the storefront to view orders and account activity.
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                @if($supportLink)
                    <a href="{{ $supportLink }}" class="forest-action bg-emerald-200 text-emerald-950">{{ data_get($copy, 'support_cta_label', 'Contact support') }}</a>
                @endif
                @if($privacyUrl !== '')
                    <a href="{{ $privacyUrl }}" class="forest-action border border-slate-200 bg-white text-slate-700">Privacy</a>
                @endif
                @if($dataDeletionLink !== '')
                    <a href="{{ $dataDeletionLink }}" class="forest-action border border-slate-200 bg-white text-slate-700">Data requests</a>
                @endif
            </div>
            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                The scent quiz saves directly onto a signed-in customer account, so sign in first and then open this page again to take it.
            </div>
        </section>
    @endif
</main>
@if($profile && $socialShareStartedUrl !== '' && $socialShareClaimUrl !== '')
    <script>
        (function () {
            const startedUrl = @json($socialShareStartedUrl);
            const claimUrl = @json($socialShareClaimUrl);
            const csrfToken = @json(csrf_token());

            function targetFrom(button) {
                try {
                    return JSON.parse(button.getAttribute('data-target') || '{}');
                } catch (error) {
                    return {};
                }
            }

            function statusFor(button, message, isError) {
                const box = button.closest('[data-social-share-box]');
                const state = box ? box.querySelector('[data-social-state]') : null;
                if (!state) {
                    return;
                }

                state.textContent = message;
                state.style.color = isError ? '#b91c1c' : '#047857';
            }

            async function postJson(url, payload) {
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                const json = await response.json().catch(function () {
                    return {};
                });

                if (!response.ok) {
                    throw new Error((json && json.message) || 'Share reward is unavailable right now.');
                }

                return json.data || json;
            }

            function shareUrlFor(platform, shareUrl, text) {
                if (platform === 'facebook') {
                    return 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(shareUrl) + '&quote=' + encodeURIComponent(text || 'Take a look at this from Modern Forestry.');
                }

                return shareUrl;
            }

            async function openShare(platform, payload, target) {
                const claim = payload.claim || {};
                const shareUrl = claim.shareUrl || target.share_url || target.shareUrl || window.location.href;
                const title = target.title || 'Modern Forestry';
                const text = target.body || 'I found something from Modern Forestry worth sharing.';

                if (platform === 'instagram' && navigator.share) {
                    await navigator.share({ title: title, text: text, url: shareUrl });
                    return;
                }

                if (platform === 'instagram' && navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text + '\n\n' + shareUrl);
                }

                window.open(shareUrlFor(platform, shareUrl, text), '_blank', 'noopener,noreferrer,width=720,height=680');
            }

            document.querySelectorAll('[data-social-share]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const platform = button.getAttribute('data-platform') || '';
                    const target = targetFrom(button);
                    button.disabled = true;
                    statusFor(button, 'Opening share window...', false);

                    try {
                        const payload = await postJson(startedUrl, { platform: platform, target: target });
                        await openShare(platform, payload, target);
                        statusFor(button, 'After posting, tap “I shared” to claim your reward.', false);
                    } catch (error) {
                        statusFor(button, error.message || 'Unable to start this share.', true);
                    } finally {
                        button.disabled = false;
                    }
                });
            });

            document.querySelectorAll('[data-social-claim]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const platform = button.getAttribute('data-platform') || '';
                    const target = targetFrom(button);
                    button.disabled = true;
                    statusFor(button, 'Checking your reward...', false);

                    try {
                        const payload = await postJson(claimUrl, { platform: platform, target: target });
                        const already = Boolean(payload.alreadyAwarded);
                        const reward = (payload.reward && payload.reward.label) || @json($socialShareRewardLabel);
                        statusFor(button, already ? 'Already rewarded for this share.' : reward + ' added to your account.', false);
                    } catch (error) {
                        statusFor(button, error.message || 'Unable to claim this reward.', true);
                    } finally {
                        button.disabled = false;
                    }
                });
            });
        }());
    </script>
@endif
@if($scentQuizAttributionPayload ?? null)
    <script>
        (function () {
            const payload = @json($scentQuizAttributionPayload);
            if (!payload || typeof payload !== 'object') {
                return;
            }

            payload.landing_url = window.location.href;
            payload.landing_path = window.location.pathname;
            payload.captured_at = Date.now();

            try {
                window.localStorage.setItem('forestry:marketing:attribution', JSON.stringify(payload));
            } catch (error) {
                // Keep the account experience resilient even if localStorage is blocked.
            }
        }());
    </script>
@endif
</body>
</html>
