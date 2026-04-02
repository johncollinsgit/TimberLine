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
        $rewardsLabelLc = strtolower($rewardsLabel);
        $rewardsBalanceLabel = trim((string) ($displayLabels['rewards_balance_label'] ?? ($rewardsLabel . ' balance')));
        if ($rewardsBalanceLabel === '') {
            $rewardsBalanceLabel = $rewardsLabel . ' balance';
        }
        $rewardCreditLabel = trim((string) ($displayLabels['reward_credit_label'] ?? 'reward credit'));
        if ($rewardCreditLabel === '') {
            $rewardCreditLabel = 'reward credit';
        }
        $rewardCreditLabelTitle = \Illuminate\Support\Str::title($rewardCreditLabel);
    @endphp
    <title>{{ $rewardsLabel }} Account</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--fb-page-background)] text-zinc-900">
<main class="mx-auto max-w-5xl px-4 py-8 space-y-5">
    <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
        <div class="text-xs uppercase tracking-[0.22em] text-zinc-500">TimberLine {{ $rewardsLabel }} Account</div>
        <h1 class="mt-2 text-2xl font-semibold text-zinc-950">{{ $rewardsLabel }} Account Lookup</h1>
        <p class="mt-2 text-sm text-zinc-600">
            Check your {{ $rewardsBalanceLabel }}, recent activity, referral details, and your current $10 redemption status.
        </p>
    </section>

    <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
        <form method="GET" action="{{ route('marketing.public.account-rewards') }}" class="grid gap-3 sm:grid-cols-3">
            <input type="email" name="email" value="{{ request('email') }}" placeholder="Email" class="rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 sm:col-span-1">
            <input type="text" name="phone" value="{{ request('phone') }}" placeholder="Phone" class="rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 sm:col-span-1">
            <button type="submit" class="rounded-xl border border-sky-300/35 bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-900 sm:col-span-1">Lookup</button>
        </form>
        <p class="mt-2 text-xs text-zinc-500">Lookup requires both email and phone to reduce accidental exposure.</p>
        @if(($lookupState ?? '') !== '')
            <div class="mt-2 text-xs text-zinc-600">
                State: <span class="font-semibold">{{ strtoupper((string) $lookupState) }}</span>
                @if($lookupState === 'verification_required')
                    · Provide both fields to continue.
                @elseif($lookupState === 'needs_verification')
                    · Identity is ambiguous and needs verification.
                @elseif($lookupState === 'unknown_customer')
                    · No profile match found yet.
                @endif
            </div>
        @endif
    </section>

    @if(is_array($redeemResult ?? null))
        <section class="rounded-3xl border p-4 text-sm {{ ($redeemResult['ok'] ?? false) ? 'border-emerald-300/30 bg-emerald-100 text-emerald-800' : 'border-amber-300/30 bg-amber-100 text-amber-800' }}">
            <div class="font-semibold">{{ ($redeemResult['ok'] ?? false) ? 'Redemption Update' : 'Redemption Failed' }}</div>
            <div class="mt-1">{{ (string) ($redeemResult['message'] ?? 'Unknown redemption state.') }}</div>
            @if(($redeemResult['ok'] ?? false) && !empty($redeemResult['redemption_code']))
                <div class="mt-2 text-xs">Code: <span class="font-mono">{{ $redeemResult['redemption_code'] }}</span></div>
            @endif
            @if(($redeemResult['ok'] ?? false) && !empty($redeemResult['apply_url']))
                <div class="mt-2 text-xs">
                    <a
                        href="{{ $redeemResult['apply_url'] }}"
                        class="inline-flex rounded-lg border border-current/30 px-3 py-1 font-semibold hover:bg-zinc-100"
                    >
                        Apply on storefront
                    </a>
                </div>
            @endif
            <div class="mt-2 text-xs opacity-90">
                State: {{ strtoupper((string) ($redeemResult['state'] ?? 'unknown')) }}
                @if(data_get($redeemResult, 'balance.candle_cash_amount_formatted'))
                    · Balance: {{ data_get($redeemResult, 'balance.candle_cash_amount_formatted') }}
                @endif
                @if(! empty($redeemResult['discount_sync_status'] ?? null))
                    · Discount: {{ strtoupper((string) $redeemResult['discount_sync_status']) }}
                @endif
            </div>
        </section>
    @endif

    @if($profile)
        @php
            $maskedEmail = $profile->email ? preg_replace('/(^.).+(@.*$)/', '$1***$2', $profile->email) : null;
            $maskedPhone = $profile->phone ? preg_replace('/\d(?=\d{2})/', '*', preg_replace('/\D+/', '', $profile->phone)) : null;
            $activeReviewCount = (int) data_get($reviewSummary ?? [], 'review_count', 0);
            $activeReviewAverage = data_get($reviewSummary ?? [], 'average_rating');
            $activeReviewLastReviewedAt = data_get($reviewSummary ?? [], 'last_reviewed_at');
            $nativeReviewCount = (int) data_get($nativeReviewSummary ?? [], 'review_count', 0);
            $nativeReviewAverage = data_get($nativeReviewSummary ?? [], 'average_rating');
            $legacyReviewCount = (int) ($legacyReviewSummary?->review_count ?? 0);
            $legacyReviewAverage = $legacyReviewSummary?->average_rating;
        @endphp
        <section class="grid gap-4 lg:grid-cols-3">
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <h2 class="text-sm font-semibold text-zinc-950">{{ $rewardsBalanceLabel }}</h2>
                <div class="mt-2 text-3xl font-semibold text-zinc-950">{{ data_get($balance, 'candle_cash_amount_formatted', '$0.00') }}</div>
                <div class="mt-2 text-xs text-zinc-500">Redeem $10 {{ $rewardsLabel }} at a time. Limit $10 {{ $rewardsLabel }} per order.</div>
                <div class="mt-2 text-xs text-zinc-500">Matched identity: {{ $maskedEmail ?: $maskedPhone ?: 'verified' }}</div>
            </article>
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <h2 class="text-sm font-semibold text-zinc-950">Referral (Legacy Snapshot)</h2>
                @if($latestGrowaveExternal && $latestGrowaveExternal->referral_link)
                    <div class="mt-2 break-all text-sm text-sky-200">{{ $latestGrowaveExternal->referral_link }}</div>
                    <div class="mt-2 text-xs text-zinc-500">
                        Legacy Growave referral snapshot only (read-only). Live referral enrollment and editing are not enabled in this surface.
                    </div>
                @else
                    <div class="mt-2 text-sm text-zinc-500">No legacy Growave referral link on file.</div>
                @endif
                <div class="mt-3 text-xs text-zinc-500">
                    Legacy Growave ID: {{ $latestGrowaveExternal?->external_customer_id ?: '—' }}
                </div>
            </article>
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <h2 class="text-sm font-semibold text-zinc-950">Review Status</h2>
                <div class="mt-2 text-sm text-zinc-700">
                    {{ $activeReviewCount }} reviews
                    · Avg {{ $activeReviewAverage !== null ? number_format((float) $activeReviewAverage, 2) : '—' }}
                </div>
                <div class="mt-2 text-xs text-zinc-500">
                    Review {{ $rewardsLabelLc }}: {{ (int) ($reviewRewardStatus['count'] ?? 0) }}
                    @if(! empty($reviewRewardStatus['last_rewarded_at'] ?? null))
                        · Last: {{ \Illuminate\Support\Carbon::parse((string) $reviewRewardStatus['last_rewarded_at'])->format('Y-m-d H:i') }}
                    @endif
                </div>
                <div class="mt-2 text-xs text-zinc-500">
                    Source:
                    @if(($reviewDataSource ?? 'none') === 'native')
                        Native Backstage reviews
                    @elseif(($reviewDataSource ?? 'none') === 'legacy_growave')
                        Legacy Growave history
                    @else
                        No review data yet
                    @endif
                    @if($activeReviewLastReviewedAt)
                        · Last review: {{ \Illuminate\Support\Carbon::parse((string) $activeReviewLastReviewedAt)->format('Y-m-d H:i') }}
                    @endif
                </div>
                @if($nativeReviewCount > 0 || (int) ($nativeReviewRewardStatus['count'] ?? 0) > 0)
                    <div class="mt-2 text-xs text-zinc-500">
                        Native: {{ $nativeReviewCount }} reviews
                        · Avg {{ $nativeReviewAverage !== null ? number_format((float) $nativeReviewAverage, 2) : '—' }}
                        · {{ $rewardsLabel }} {{ (int) ($nativeReviewRewardStatus['count'] ?? 0) }}
                    </div>
                @endif
                @if($legacyReviewCount > 0 || (int) ($legacyReviewRewardStatus['count'] ?? 0) > 0)
                    <div class="mt-2 text-xs text-zinc-500">
                        Legacy Growave (read-only): {{ $legacyReviewCount }} reviews
                        · Avg {{ $legacyReviewAverage !== null ? number_format((float) $legacyReviewAverage, 2) : '—' }}
                        · {{ $rewardsLabel }} {{ (int) ($legacyReviewRewardStatus['count'] ?? 0) }}
                    </div>
                @endif
                <div class="mt-2 text-xs text-zinc-500">
                    Last Growave sync: {{ $lastGrowaveSyncAt ? \Illuminate\Support\Carbon::parse((string) $lastGrowaveSyncAt)->format('Y-m-d H:i') : '—' }}
                </div>
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <h2 class="text-sm font-semibold text-zinc-950">Redeem {{ $rewardsLabel }}</h2>
                <p class="mt-2 text-xs text-zinc-500">{{ $rewardCreditLabelTitle }} is redeemed in $10 increments, with a limit of $10 per order.</p>
                @if(! data_get($redemptionAccess ?? [], 'redeem_enabled', true))
                    <p class="mt-2 text-xs text-zinc-500">{{ data_get($redemptionAccess, 'message', $rewardsLabel . ' are temporarily available for selected accounts only.') }}</p>
                @endif
                <div class="mt-3 space-y-3">
                    @forelse($availableRewards as $reward)
                        @php
                            $canRedeem = (bool) data_get($reward, 'is_redeemable_now', false);
                            $redeemEnabled = (bool) data_get($redemptionAccess ?? [], 'redeem_enabled', true);
                            $buttonEnabled = $canRedeem && $redeemEnabled;
                            $buttonLabel = ! $redeemEnabled
                                ? 'COMING SOON!'
                                : ($canRedeem ? 'Redeem $10 ' . $rewardCreditLabelTitle : 'Need more balance');
                        @endphp
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-semibold text-zinc-950">{{ data_get($reward, 'name', 'Redeem $10 ' . $rewardCreditLabelTitle) }}</div>
                                    <div class="text-xs text-zinc-500">{{ data_get($reward, 'candle_cash_amount_formatted', '$10.00') }} off this order · Limit $10 per order</div>
                                </div>
                                @if($redeemEnabled)
                                    <form method="POST" action="{{ route('marketing.public.account-rewards.redeem') }}" class="shrink-0">
                                        @csrf
                                        <input type="hidden" name="email" value="{{ request('email') }}">
                                        <input type="hidden" name="phone" value="{{ request('phone') }}">
                                        <input type="hidden" name="reward_id" value="{{ (int) data_get($reward, 'id', 0) }}">
                                        <button type="submit" class="rounded-lg border px-3 py-1.5 text-xs font-semibold {{ $buttonEnabled ? 'border-emerald-300/40 bg-emerald-100 text-emerald-800' : 'border-zinc-500/40 bg-zinc-700/30 text-zinc-600' }}" {{ $buttonEnabled ? '' : 'disabled' }}>
                                            {{ $buttonLabel }}
                                        </button>
                                    </form>
                                @else
                                    <button type="button" class="shrink-0 rounded-lg border border-zinc-500/40 bg-zinc-700/30 px-3 py-1.5 text-xs font-semibold text-zinc-600" disabled aria-disabled="true" tabindex="-1">
                                        {{ $buttonLabel }}
                                    </button>
                                @endif
                            </div>
                            @if(data_get($reward, 'description'))
                                <div class="mt-2 text-xs text-zinc-500">{{ data_get($reward, 'description') }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-zinc-500">No active rewards configured.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <h2 class="text-sm font-semibold text-zinc-950">Recent Redemptions</h2>
                <div class="mt-3 space-y-2 text-sm text-zinc-700">
                    @forelse($redemptions as $redemption)
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                            {{ data_get($redemption, 'name', 'Redeem $10 ' . $rewardCreditLabelTitle) }}
                            · {{ data_get($redemption, 'candle_cash_amount_formatted', '$0.00') }}
                            · {{ strtoupper((string) data_get($redemption, 'status', 'issued')) }}
                            @if(data_get($redemption, 'redemption_code'))
                                · <span class="font-mono text-xs">{{ data_get($redemption, 'redemption_code') }}</span>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-zinc-500">No redemptions found.</div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
            <h2 class="text-sm font-semibold text-zinc-950">Transaction History</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm text-zinc-800">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="py-2 pr-4">When</th>
                            <th class="py-2 pr-4">Category</th>
                            <th class="py-2 pr-4">{{ $rewardsLabel }}</th>
                            <th class="py-2">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                    @forelse($transactions as $row)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['occurred_at'] ? \Illuminate\Support\Carbon::parse((string) $row['occurred_at'])->format('Y-m-d H:i') : '—' }}</td>
                            <td class="py-2 pr-4">{{ $row['category'] }}</td>
                            <td class="py-2 pr-4 font-semibold {{ ((float) data_get($row, 'candle_cash_amount', 0)) >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                {{ data_get($row, 'signed_candle_cash_amount_formatted', '$0.00') }}
                            </td>
                            <td class="py-2">{{ $row['description'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-4 text-zinc-500">No loyalty transactions available yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif(request()->query('email') || request()->query('phone'))
        <section class="rounded-3xl border border-amber-300/30 bg-amber-100 p-4 text-sm text-amber-800">
            No profile found for that email + phone combination.
        </section>
    @endif
</main>
</body>
</html>
