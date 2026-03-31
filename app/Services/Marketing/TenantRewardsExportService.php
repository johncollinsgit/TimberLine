<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Support\Marketing\CandleCashMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class TenantRewardsExportService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashLedgerNormalizationService $normalizer,
        protected CandleCashEarnedAnalyticsService $earnedAnalyticsService,
        protected TenantRewardsReminderLogService $reminderLogService,
        protected TenantRewardsReminderScheduleService $scheduleService,
        protected TenantRewardsFinanceSummaryService $financeSummaryService
    ) {
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $filters
     * @return array{columns:array<int,string>,rows:array<int,array<string,mixed>>,filename:string}
     */
    public function build(string $type, int $tenantId, array $policy, array $filters = []): array
    {
        $type = strtolower(trim($type));
        $filters = $this->normalizedFilters($filters);

        return match ($type) {
            'reminder_history' => [
                'columns' => $this->reminderHistoryColumns(),
                'rows' => $this->reminderHistoryRows($tenantId, $filters),
                'filename' => $this->filename('rewards-reminder-history', $tenantId, $filters),
            ],
            'reward_issuance' => [
                'columns' => $this->rewardIssuanceColumns(),
                'rows' => $this->rewardIssuanceRows($tenantId, $filters),
                'filename' => $this->filename('rewards-issuance', $tenantId, $filters),
            ],
            'reward_redemption' => [
                'columns' => $this->rewardRedemptionColumns(),
                'rows' => $this->rewardRedemptionRows($tenantId, $filters),
                'filename' => $this->filename('rewards-redemption', $tenantId, $filters),
            ],
            'expiring_rewards' => [
                'columns' => $this->expiringRewardsColumns(),
                'rows' => $this->expiringRewardsRows($tenantId, $policy, $filters),
                'filename' => $this->filename('rewards-expiring', $tenantId, $filters),
            ],
            'finance_summary' => [
                'columns' => $this->financeSummaryColumns(),
                'rows' => $this->financeSummaryRows($tenantId, $policy, $filters),
                'filename' => $this->filename('rewards-finance', $tenantId, $filters),
            ],
            default => [
                'columns' => [],
                'rows' => [],
                'filename' => $this->filename('rewards-export', $tenantId, $filters),
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function reminderHistoryRows(int $tenantId, array $filters): array
    {
        $rewardContextCache = [];

        return collect($this->reminderLogService->filteredForTenant($tenantId, [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'channel' => $filters['channel'],
            'status' => $filters['status'],
        ], 5000))
            ->map(function (array $row) use ($tenantId, &$rewardContextCache): array {
                $rewardIdentifier = trim((string) ($row['reward_identifier'] ?? ''));
                if ($rewardIdentifier !== '' && ! array_key_exists($rewardIdentifier, $rewardContextCache)) {
                    $rewardContextCache[$rewardIdentifier] = $this->earnedAnalyticsService->rewardContext($tenantId, $rewardIdentifier);
                }

                $rewardContext = is_array($rewardContextCache[$rewardIdentifier] ?? null)
                    ? (array) $rewardContextCache[$rewardIdentifier]
                    : [];

                return [
                    'occurred_at' => $row['occurred_at'] ?? null,
                    'marketing_profile_id' => $row['marketing_profile_id'] ?? null,
                    'reward_identifier' => $row['reward_identifier'] ?? null,
                    'reward_source_key' => $row['reward_source_key'] ?? ($rewardContext['source_key'] ?? null),
                    'reward_source' => $row['reward_source_label'] ?? ($rewardContext['source_label'] ?? null),
                    'channel' => strtoupper((string) ($row['channel'] ?? '')),
                    'status' => $row['status'] ?? null,
                    'timing_days_before_expiration' => $row['timing_days_before_expiration'] ?? null,
                    'scheduled_at' => $row['scheduled_at'] ?? null,
                    'attempted_at' => $row['attempted_at'] ?? null,
                    'sent_at' => $row['sent_at'] ?? null,
                    'failed_at' => $row['failed_at'] ?? null,
                    'skipped_at' => $row['skipped_at'] ?? null,
                    'skip_reason' => $row['skip_reason'] ?? null,
                    'policy_version' => $row['policy_version'] ?? null,
                    'delivery_reference' => $row['delivery_reference'] ?? null,
                ];
            })
            ->filter(function (array $row) use ($filters): bool {
                $rewardType = $filters['reward_type'] ?? null;
                if (! is_string($rewardType) || trim($rewardType) === '') {
                    return true;
                }

                return strtolower(trim((string) ($row['reward_source'] ?? ''))) === strtolower(trim($rewardType))
                    || strtolower(trim((string) ($row['reward_source_key'] ?? ''))) === strtolower(trim($rewardType));
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function rewardIssuanceRows(int $tenantId, array $filters): array
    {
        $rows = CandleCashTransaction::query()
            ->with('profile:id,tenant_id,first_name,last_name,email')
            ->whereHas('profile', function (EloquentBuilder $query) use ($tenantId): void {
                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->where('candle_cash_delta', '>', 0)
            ->orderByDesc('created_at')
            ->get();

        return $rows
            ->reject(fn (CandleCashTransaction $transaction): bool => $this->normalizer->isGrandfatheredOpening($transaction))
            ->filter(fn (CandleCashTransaction $transaction): bool => $this->withinRange($transaction->created_at, $filters))
            ->map(function (CandleCashTransaction $transaction): array {
                $profile = $transaction->profile;
                $sourceKey = $this->normalizer->classifyEarnSource($transaction);
                $definitions = $this->normalizer->sourceDefinitions();
                $sourceLabel = (string) data_get($definitions, $sourceKey.'.label', 'Other earn');
                $amount = round($this->candleCashService->amountFromPoints(
                    CandleCashMeasurement::normalizeStoredAmount($transaction->candle_cash_delta ?? 0)
                ), 2);

                return [
                    'issued_at' => optional($transaction->created_at)?->toIso8601String(),
                    'transaction_id' => (int) $transaction->id,
                    'marketing_profile_id' => $transaction->marketing_profile_id,
                    'customer_name' => trim(((string) ($profile?->first_name ?? '')).' '.((string) ($profile?->last_name ?? ''))),
                    'customer_email' => $profile?->email,
                    'reward_source' => $sourceLabel,
                    'amount' => $amount,
                    'formatted_amount' => '$'.number_format($amount, 2),
                    'source' => $transaction->source,
                    'source_id' => $transaction->source_id,
                    'description' => $transaction->description,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function rewardRedemptionRows(int $tenantId, array $filters): array
    {
        return CandleCashRedemption::query()
            ->with(['profile:id,tenant_id,first_name,last_name,email', 'reward:id,name,reward_type'])
            ->whereHas('profile', function (EloquentBuilder $query) use ($tenantId): void {
                $query->where('marketing_profiles.tenant_id', $tenantId);
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (CandleCashRedemption $redemption) use ($filters): bool {
                $date = $redemption->redeemed_at ?? $redemption->issued_at ?? $redemption->created_at;

                return $this->withinRange($date, $filters);
            })
            ->map(function (CandleCashRedemption $redemption): array {
                $profile = $redemption->profile;
                $amount = round($this->candleCashService->amountFromPoints(
                    CandleCashMeasurement::normalizeStoredAmount($redemption->candle_cash_spent ?? 0)
                ), 2);

                return [
                    'status' => $redemption->status,
                    'reward_code' => $redemption->redemption_code,
                    'reward_name' => $redemption->reward?->name,
                    'reward_type' => $redemption->reward?->reward_type,
                    'marketing_profile_id' => $redemption->marketing_profile_id,
                    'customer_name' => trim(((string) ($profile?->first_name ?? '')).' '.((string) ($profile?->last_name ?? ''))),
                    'customer_email' => $profile?->email,
                    'amount' => $amount,
                    'formatted_amount' => '$'.number_format($amount, 2),
                    'issued_at' => optional($redemption->issued_at)?->toIso8601String(),
                    'expires_at' => optional($redemption->expires_at)?->toIso8601String(),
                    'redeemed_at' => optional($redemption->redeemed_at)?->toIso8601String(),
                    'canceled_at' => optional($redemption->canceled_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function expiringRewardsRows(int $tenantId, array $policy, array $filters): array
    {
        $now = now()->toImmutable();
        $policyVersion = max(0, (int) data_get($policy, 'versioning.current_version', data_get($policy, 'access_state.policy_version', 0)));
        $dateFrom = $this->asDate($filters['date_from'] ?? null) ?? $now->startOfDay();
        $dateTo = $this->asDate($filters['date_to'] ?? null) ?? $now->addDays(max(1, (int) ($filters['expiring_soon_days'] ?? 14)))->endOfDay();

        return collect($this->earnedAnalyticsService->outstandingRewardBuckets($tenantId))
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
                    'customer_email' => $reward['email'] ?? null,
                    'reward_source' => $reward['source_label'] ?? null,
                    'remaining_amount' => round((float) ($reward['remaining_amount'] ?? 0), 2),
                    'formatted_remaining_amount' => $reward['formatted_remaining_amount'] ?? '$0.00',
                    'expires_at' => $expiresAt->toIso8601String(),
                    'policy_version' => $policyVersion,
                ];
            })
            ->filter(fn ($row): bool => is_array($row))
            ->filter(function (array $row) use ($dateFrom, $dateTo): bool {
                $expiresAt = $this->asDate($row['expires_at'] ?? null);

                return $expiresAt instanceof CarbonImmutable
                    && $expiresAt->greaterThanOrEqualTo($dateFrom)
                    && $expiresAt->lessThanOrEqualTo($dateTo);
            })
            ->sortBy('expires_at')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    protected function financeSummaryRows(int $tenantId, array $policy, array $filters): array
    {
        $summary = $this->financeSummaryService->summaryForTenant($tenantId, $policy, [
            'expiring_soon_days' => max(1, (int) ($filters['expiring_soon_days'] ?? 14)),
        ]);

        return [[
            'as_of' => $summary['as_of'] ?? now()->toIso8601String(),
            'policy_version' => $summary['policy_version'] ?? 0,
            'outstanding_liability' => data_get($summary, 'outstanding_liability.amount', 0),
            'outstanding_liability_formatted' => data_get($summary, 'outstanding_liability.formatted_amount', '$0.00'),
            'open_reward_count' => data_get($summary, 'outstanding_liability.open_reward_count', 0),
            'customer_count' => data_get($summary, 'outstanding_liability.customer_count', 0),
            'issued_amount' => data_get($summary, 'issued.amount', 0),
            'issued_amount_formatted' => data_get($summary, 'issued.formatted_amount', '$0.00'),
            'redeemed_amount' => data_get($summary, 'redeemed.amount', 0),
            'redeemed_amount_formatted' => data_get($summary, 'redeemed.formatted_amount', '$0.00'),
            'unredeemed_amount' => data_get($summary, 'unredeemed.amount', 0),
            'unredeemed_amount_formatted' => data_get($summary, 'unredeemed.formatted_amount', '$0.00'),
            'breakage_estimate_amount' => data_get($summary, 'breakage_estimate.amount', 0),
            'breakage_estimate_formatted' => data_get($summary, 'breakage_estimate.formatted_amount', '$0.00'),
            'breakage_observed_rate_percent' => data_get($summary, 'breakage_estimate.observed_rate', 0),
            'expiring_soon_amount' => data_get($summary, 'expiring_soon.amount', 0),
            'expiring_soon_formatted' => data_get($summary, 'expiring_soon.formatted_amount', '$0.00'),
            'expiring_soon_count' => data_get($summary, 'expiring_soon.count', 0),
            'realized_discount_value' => data_get($summary, 'realized_discount_value.amount', 0),
            'realized_discount_value_formatted' => data_get($summary, 'realized_discount_value.formatted_amount', '$0.00'),
            'liability_alert_threshold' => data_get($summary, 'alert_threshold', 0),
        ]];
    }

    /**
     * @return array<int,string>
     */
    protected function reminderHistoryColumns(): array
    {
        return [
            'occurred_at',
            'marketing_profile_id',
            'reward_identifier',
            'reward_source',
            'channel',
            'status',
            'timing_days_before_expiration',
            'scheduled_at',
            'attempted_at',
            'sent_at',
            'failed_at',
            'skipped_at',
            'skip_reason',
            'policy_version',
            'delivery_reference',
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function rewardIssuanceColumns(): array
    {
        return [
            'issued_at',
            'transaction_id',
            'marketing_profile_id',
            'customer_name',
            'customer_email',
            'reward_source',
            'amount',
            'formatted_amount',
            'source',
            'source_id',
            'description',
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function rewardRedemptionColumns(): array
    {
        return [
            'status',
            'reward_code',
            'reward_name',
            'reward_type',
            'marketing_profile_id',
            'customer_name',
            'customer_email',
            'amount',
            'formatted_amount',
            'issued_at',
            'expires_at',
            'redeemed_at',
            'canceled_at',
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function expiringRewardsColumns(): array
    {
        return [
            'reward_identifier',
            'marketing_profile_id',
            'customer_name',
            'customer_email',
            'reward_source',
            'remaining_amount',
            'formatted_remaining_amount',
            'expires_at',
            'policy_version',
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function financeSummaryColumns(): array
    {
        return [
            'as_of',
            'policy_version',
            'outstanding_liability',
            'outstanding_liability_formatted',
            'open_reward_count',
            'customer_count',
            'issued_amount',
            'issued_amount_formatted',
            'redeemed_amount',
            'redeemed_amount_formatted',
            'unredeemed_amount',
            'unredeemed_amount_formatted',
            'breakage_estimate_amount',
            'breakage_estimate_formatted',
            'breakage_observed_rate_percent',
            'expiring_soon_amount',
            'expiring_soon_formatted',
            'expiring_soon_count',
            'realized_discount_value',
            'realized_discount_value_formatted',
            'liability_alert_threshold',
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function normalizedFilters(array $filters): array
    {
        return [
            'date_from' => $this->asDate($filters['date_from'] ?? null)?->toDateString(),
            'date_to' => $this->asDate($filters['date_to'] ?? null)?->toDateString(),
            'channel' => $this->nullableString($filters['channel'] ?? null),
            'status' => $this->nullableString($filters['status'] ?? null),
            'reward_type' => $this->nullableString($filters['reward_type'] ?? null),
            'expiring_soon_days' => max(1, (int) ($filters['expiring_soon_days'] ?? 14)),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function withinRange(mixed $value, array $filters): bool
    {
        $date = $this->asDate($value);
        if (! $date instanceof CarbonImmutable) {
            return true;
        }

        $dateFrom = $this->asDate($filters['date_from'] ?? null)?->startOfDay();
        $dateTo = $this->asDate($filters['date_to'] ?? null)?->endOfDay();

        if ($dateFrom instanceof CarbonImmutable && $date->lessThan($dateFrom)) {
            return false;
        }

        if ($dateTo instanceof CarbonImmutable && $date->greaterThan($dateTo)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function filename(string $prefix, int $tenantId, array $filters): string
    {
        $dateFrom = preg_replace('/[^0-9\\-]/', '', (string) ($filters['date_from'] ?? '')) ?: 'start';
        $dateTo = preg_replace('/[^0-9\\-]/', '', (string) ($filters['date_to'] ?? '')) ?: 'end';

        return sprintf('%s-tenant-%d-%s-to-%s.csv', $prefix, $tenantId, $dateFrom, $dateTo);
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

    protected function nullableString(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
