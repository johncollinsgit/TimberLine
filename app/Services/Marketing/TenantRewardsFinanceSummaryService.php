<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Support\Marketing\CandleCashMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class TenantRewardsFinanceSummaryService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashLedgerNormalizationService $normalizer,
        protected CandleCashEarnedAnalyticsService $earnedAnalyticsService,
        protected TenantRewardsReminderScheduleService $scheduleService
    ) {
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function summaryForTenant(int $tenantId, array $policy, array $options = []): array
    {
        $now = $this->asDate($options['now'] ?? null) ?? now()->toImmutable();
        $expiringSoonDays = max(1, min(90, (int) ($options['expiring_soon_days'] ?? 14)));
        $policyVersion = max(0, (int) ($options['policy_version'] ?? data_get($policy, 'versioning.current_version', data_get($policy, 'access_state.policy_version', 0))));

        $issuedTransactions = CandleCashTransaction::query()
            ->whereHas('profile', function (EloquentBuilder $query) use ($tenantId): void {
                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->where('candle_cash_delta', '>', 0)
            ->orderBy('created_at')
            ->get();

        $issuedTransactions = $issuedTransactions
            ->reject(fn (CandleCashTransaction $transaction): bool => $this->normalizer->isGrandfatheredOpening($transaction))
            ->values();

        $issuedPoints = CandleCashMeasurement::normalizeStoredAmount($issuedTransactions->sum('candle_cash_delta'));
        $issuedAmount = round($this->candleCashService->amountFromPoints($issuedPoints), 2);

        $redeemedRows = CandleCashRedemption::query()
            ->whereHas('profile', function (EloquentBuilder $query) use ($tenantId): void {
                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->with('reward:id,name,reward_type')
            ->get(['id', 'reward_id', 'marketing_profile_id', 'status', 'candle_cash_spent', 'issued_at', 'expires_at', 'redeemed_at', 'canceled_at']);

        $redeemedAmount = round($redeemedRows
            ->where('status', 'redeemed')
            ->sum(fn (CandleCashRedemption $row): float => $this->candleCashService->amountFromPoints(
                CandleCashMeasurement::normalizeStoredAmount($row->candle_cash_spent ?? 0)
            )), 2);

        $observedBreakageAmount = round($redeemedRows
            ->filter(function (CandleCashRedemption $row) use ($now): bool {
                if (in_array((string) $row->status, ['expired', 'canceled'], true)) {
                    return true;
                }

                return (string) $row->status === 'issued'
                    && $row->expires_at !== null
                    && $row->expires_at->isPast();
            })
            ->sum(fn (CandleCashRedemption $row): float => $this->candleCashService->amountFromPoints(
                CandleCashMeasurement::normalizeStoredAmount($row->candle_cash_spent ?? 0)
            )), 2);

        $closedValue = round($redeemedAmount + $observedBreakageAmount, 2);
        $breakageRate = $closedValue > 0 ? round($observedBreakageAmount / $closedValue, 4) : 0.0;

        $outstandingRewards = collect($this->earnedAnalyticsService->outstandingRewardBuckets($tenantId));
        $outstandingLiability = round((float) $outstandingRewards->sum(fn (array $row): float => (float) ($row['remaining_amount'] ?? 0)), 2);

        $expiringSoonItems = $outstandingRewards
            ->map(function (array $reward) use ($policy, $policyVersion, $now): ?array {
                $schedule = $this->scheduleService->evaluate($policy, [
                    ...$reward,
                    'policy_version' => $policyVersion,
                ], [
                    'tenant_id' => $reward['tenant_id'] ?? null,
                    'policy_version' => $policyVersion,
                    'now' => $now->toIso8601String(),
                ]);

                $expiresAt = $this->asDate(data_get($schedule, 'reward.expires_at'));
                if (! $expiresAt instanceof CarbonImmutable || $expiresAt->lessThan($now)) {
                    return null;
                }

                return [
                    'reward_identifier' => $reward['reward_identifier'] ?? null,
                    'marketing_profile_id' => $reward['marketing_profile_id'] ?? null,
                    'customer_name' => $reward['customer_name'] ?? null,
                    'reward_source_label' => $reward['source_label'] ?? null,
                    'remaining_amount' => round((float) ($reward['remaining_amount'] ?? 0), 2),
                    'formatted_remaining_amount' => $reward['formatted_remaining_amount'] ?? $this->formatCurrency((float) ($reward['remaining_amount'] ?? 0)),
                    'expires_at' => $expiresAt->toIso8601String(),
                ];
            })
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn (array $row): bool => ($this->asDate($row['expires_at'] ?? null)?->lessThanOrEqualTo($now->addDays($expiringSoonDays)) ?? false))
            ->sortBy('expires_at')
            ->values();

        $expiringSoonAmount = round((float) $expiringSoonItems->sum(fn (array $row): float => (float) ($row['remaining_amount'] ?? 0)), 2);
        $breakageEstimateAmount = round($outstandingLiability * $breakageRate, 2);
        $unredeemedAmount = round($outstandingLiability, 2);
        $liabilityThreshold = (float) data_get($policy, 'finance_and_safety.liability_alert_threshold_dollars', 0);

        return [
            'headline' => 'Estimated rewards exposure and realized discount value.',
            'as_of' => $now->toIso8601String(),
            'policy_version' => $policyVersion,
            'alert_threshold' => $liabilityThreshold > 0 ? round($liabilityThreshold, 2) : 0.0,
            'settings' => [
                'liability_alert_threshold_dollars' => $liabilityThreshold > 0 ? round($liabilityThreshold, 2) : null,
            ],
            'outstanding_liability' => [
                'amount' => $outstandingLiability,
                'formatted_amount' => $this->formatCurrency($outstandingLiability),
                'open_reward_count' => $outstandingRewards->count(),
                'customer_count' => $outstandingRewards->pluck('marketing_profile_id')->unique()->count(),
            ],
            'issued' => [
                'amount' => $issuedAmount,
                'formatted_amount' => $this->formatCurrency($issuedAmount),
                'count' => $issuedTransactions->count(),
            ],
            'redeemed' => [
                'amount' => $redeemedAmount,
                'formatted_amount' => $this->formatCurrency($redeemedAmount),
                'count' => $redeemedRows->where('status', 'redeemed')->count(),
            ],
            'unredeemed' => [
                'amount' => $unredeemedAmount,
                'formatted_amount' => $this->formatCurrency($unredeemedAmount),
                'count' => $outstandingRewards->count(),
            ],
            'breakage_estimate' => [
                'amount' => $breakageEstimateAmount,
                'formatted_amount' => $this->formatCurrency($breakageEstimateAmount),
                'observed_rate' => round($breakageRate * 100, 1),
                'observed_breakage_amount' => $observedBreakageAmount,
                'basis' => $closedValue > 0
                    ? 'Estimated from historical expired and canceled reward-code value.'
                    : 'No historical closed-code basis yet, so the estimate is currently $0.00.',
            ],
            'expiring_soon' => [
                'days' => $expiringSoonDays,
                'amount' => $expiringSoonAmount,
                'formatted_amount' => $this->formatCurrency($expiringSoonAmount),
                'count' => $expiringSoonItems->count(),
                'items' => $expiringSoonItems->take(20)->all(),
            ],
            'realized_discount_value' => [
                'amount' => $redeemedAmount,
                'formatted_amount' => $this->formatCurrency($redeemedAmount),
            ],
            'signals' => [
                [
                    'code' => 'liability_threshold',
                    'level' => $liabilityThreshold > 0 && $outstandingLiability >= $liabilityThreshold ? 'warning' : 'info',
                    'message' => $liabilityThreshold > 0
                        ? ($outstandingLiability >= $liabilityThreshold
                            ? 'Outstanding reward liability is above the current alert threshold.'
                            : 'Outstanding reward liability is below the current alert threshold.')
                        : 'No liability alert threshold is set yet.',
                ],
            ],
            'cards' => [
                [
                    'label' => 'Outstanding liability',
                    'value' => $this->formatCurrency($outstandingLiability),
                    'tone' => $liabilityThreshold > 0 && $outstandingLiability >= $liabilityThreshold ? 'warning' : 'neutral',
                ],
                [
                    'label' => 'Rewards issued',
                    'value' => $this->formatCurrency($issuedAmount),
                    'tone' => 'neutral',
                ],
                [
                    'label' => 'Realized discounts',
                    'value' => $this->formatCurrency($redeemedAmount),
                    'tone' => 'success',
                ],
                [
                    'label' => 'Breakage estimate',
                    'value' => $this->formatCurrency($breakageEstimateAmount),
                    'tone' => 'neutral',
                ],
                [
                    'label' => 'Expiring soon',
                    'value' => $this->formatCurrency($expiringSoonAmount),
                    'tone' => $expiringSoonAmount > 0 ? 'warning' : 'neutral',
                ],
            ],
        ];
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function formatCurrency(float $value): string
    {
        return '$'.number_format(round($value, 2), 2);
    }
}
