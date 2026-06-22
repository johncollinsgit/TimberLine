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
        </section>
    @endif
</main>
</body>
</html>
