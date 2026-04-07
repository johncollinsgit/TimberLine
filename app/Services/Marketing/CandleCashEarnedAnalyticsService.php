<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Support\Marketing\CandleCashMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;

class CandleCashEarnedAnalyticsService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $currentLedgerStateByScope = [];

    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashLedgerNormalizationService $normalizer
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to, ?int $tenantId = null): array
    {
        $state = $this->currentLedgerState($tenantId);
        $sourceDefinitions = $this->normalizer->sourceDefinitions();
        $balanceLiability = $this->balanceLiability($tenantId);

        $windowEvents = collect((array) ($state['program_earn_events'] ?? []))
            ->filter(function (array $event) use ($from, $to): bool {
                $earnedAt = $event['earned_at'] ?? null;
                if (! $earnedAt instanceof CarbonImmutable) {
                    return false;
                }

                return $earnedAt->greaterThanOrEqualTo($from) && $earnedAt->lessThanOrEqualTo($to);
            })
            ->values();

        $earnedPoints = CandleCashMeasurement::normalizeStoredAmount($windowEvents->sum('candle_cash_delta'));
        $earnedAmount = round($this->candleCashService->amountFromPoints($earnedPoints), 2);

        $breakdown = collect($sourceDefinitions)
            ->map(function (array $definition, string $key): array {
                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'definition' => $definition['definition'],
                    'candleCash' => 0.0,
                    'amount' => 0.0,
                    'formattedAmount' => $this->formatCurrency(0),
                    'sharePct' => 0.0,
                    'eventCount' => 0,
                    'customerCount' => 0,
                ];
            })
            ->keyBy('key');

        foreach ($windowEvents as $event) {
            $sourceKey = (string) ($event['source_key'] ?? 'other_earn');
            if (! $breakdown->has($sourceKey)) {
                $breakdown->put($sourceKey, [
                    'key' => $sourceKey,
                    'label' => ucwords(str_replace('_', ' ', $sourceKey)),
                    'definition' => 'Program-earned reward credit events grouped under a fallback source bucket.',
                    'candleCash' => 0.0,
                    'amount' => 0.0,
                    'formattedAmount' => $this->formatCurrency(0),
                    'sharePct' => 0.0,
                    'eventCount' => 0,
                    'customerCount' => 0,
                ]);
            }

            $row = $breakdown->get($sourceKey);
            $row['candleCash'] += round($this->candleCashService->amountFromPoints($event['candle_cash_delta'] ?? 0), 2);
            $row['eventCount']++;
            $row['__customers'] = [
                ...((array) ($row['__customers'] ?? [])),
                (int) ($event['marketing_profile_id'] ?? 0),
            ];

            $breakdown->put($sourceKey, $row);
        }

        $breakdownRows = $breakdown
            ->map(function (array $row) use ($earnedAmount): array {
                $amount = round((float) ($row['candleCash'] ?? 0), 2);
                $customers = collect((array) ($row['__customers'] ?? []))
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->count();
                unset($row['__customers']);

                return [
                    ...$row,
                    'amount' => $amount,
                    'formattedAmount' => $this->formatCurrency($amount),
                    'sharePct' => $earnedAmount > 0 ? round(($amount / $earnedAmount) * 100, 1) : 0.0,
                    'customerCount' => $customers,
                ];
            })
            ->sortByDesc('amount')
            ->values();

        $topSourceSummary = $breakdownRows
            ->filter(fn (array $row): bool => (float) ($row['amount'] ?? 0) > 0)
            ->take(3)
            ->map(fn (array $row): string => $row['label'].' '.$row['formattedAmount'].' ('.number_format((float) $row['sharePct'], 1).'%)')
            ->implode(' · ');

        $outstandingRows = collect((array) ($state['outstanding_by_profile'] ?? []));
        $outstandingPoints = CandleCashMeasurement::normalizeStoredAmount($outstandingRows->sum('points'));
        $outstandingAmount = round($this->candleCashService->amountFromPoints($outstandingPoints), 2);
        $outstandingCustomerCount = (int) $outstandingRows->count();
        $excludedOpeningPoints = CandleCashMeasurement::normalizeStoredAmount($state['excluded_opening_points'] ?? 0);
        $excludedOpeningAmount = round($this->candleCashService->amountFromPoints($excludedOpeningPoints), 2);

        $timeToFirstRedemption = $this->timeToFirstRedemptionMetrics(
            $windowEvents,
            (array) ($state['redeemed_at_by_profile'] ?? []),
            (array) ($state['debit_at_by_profile'] ?? [])
        );

        $reminderCandidates = $this->reminderCandidates($tenantId);

        return [
            'title' => 'Program-earned Candle Cash activity',
            'subtitle' => 'Selected-period earn, redemption, and outstanding behavior for expiring program-earned Candle Cash.',
            'earned' => [
                'amount' => $earnedAmount,
                'formattedAmount' => $this->formatCurrency($earnedAmount),
                'eventCount' => (int) $windowEvents->count(),
                'customerCount' => (int) $windowEvents
                    ->pluck('marketing_profile_id')
                    ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
                    ->unique()
                    ->count(),
                'sourceSummary' => $topSourceSummary !== '' ? $topSourceSummary : 'No new program-earned reward credit events in this window.',
            ],
            'breakdown' => [
                'rows' => $breakdownRows->all(),
                'sourceDefinitions' => $sourceDefinitions,
            ],
            'outstanding' => [
                'amount' => $outstandingAmount,
                'formattedAmount' => $this->formatCurrency($outstandingAmount),
                'customerCount' => $outstandingCustomerCount,
                'excludedGrandfatheredAmount' => $excludedOpeningAmount,
                'helperText' => 'Outstanding expiring Candle Cash includes only new program-earned credit. Legacy Growave-migrated and manual non-expiring opening balances stay in the same customer balance, but are excluded from this expiring pool.',
            ],
            'timeToFirstRedemption' => $timeToFirstRedemption,
            'customersWithOutstandingEarned' => [
                'count' => $outstandingCustomerCount,
            ],
            'reminderEligibility' => [
                'eligibleCustomers' => (int) ($reminderCandidates['eligible_customers'] ?? 0),
                'missingEmailCustomers' => (int) ($reminderCandidates['missing_email_customers'] ?? 0),
                'expirationPolicy' => 'Program-earned Candle Cash follows the tenant rewards expiration policy when reminder schedules are evaluated. Legacy Growave-migrated Candle Cash is excluded from that expiring pool.',
            ],
            'balanceLiability' => $balanceLiability,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function balanceLiability(?int $tenantId = null): array
    {
        $state = $this->currentLedgerState($tenantId);
        $totalRemainingPoints = CandleCashMeasurement::normalizeStoredAmount($state['total_remaining_points'] ?? 0);
        $legacyRemainingPoints = CandleCashMeasurement::normalizeStoredAmount($state['legacy_remaining_points'] ?? 0);
        $programRemainingPoints = CandleCashMeasurement::normalizeStoredAmount($state['program_remaining_points'] ?? 0);
        $manualRemainingPoints = CandleCashMeasurement::normalizeStoredAmount($state['manual_nonexpiring_points'] ?? 0);
        $balanceTablePoints = CandleCashMeasurement::normalizeStoredAmount($state['balance_table_points'] ?? 0);
        $differencePoints = CandleCashMeasurement::normalizeStoredAmount($totalRemainingPoints - $balanceTablePoints);
        $reconciled = abs($differencePoints) < 0.005;

        return [
            'title' => 'Current Candle Cash liability',
            'subtitle' => 'The live customer-visible Candle Cash pool, split between non-expiring migrated credit and expiring program-earned credit.',
            'totalCurrentBalance' => $this->balanceLiabilityValue($totalRemainingPoints),
            'legacyMigrated' => $this->balanceLiabilityValue($legacyRemainingPoints),
            'programExpiring' => $this->balanceLiabilityValue($programRemainingPoints),
            'manualNonExpiring' => $this->balanceLiabilityValue($manualRemainingPoints),
            'reconciled' => $reconciled,
            'ledgerBalance' => $this->balanceLiabilityValue($totalRemainingPoints),
            'balanceTable' => $this->balanceLiabilityValue($balanceTablePoints),
            'difference' => $this->balanceLiabilityValue($differencePoints),
            'helperText' => 'Legacy Growave-migrated Candle Cash remains visible in the same customer balance pool, while only new program-earned Candle Cash is treated as expiring.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reminderCandidates(?int $tenantId = null): array
    {
        $state = $this->currentLedgerState($tenantId);
        $outstandingByProfile = (array) ($state['outstanding_by_profile'] ?? []);
        $profileIds = collect(array_keys($outstandingByProfile))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($profileIds->isEmpty()) {
            return [
                'rows' => [],
                'eligible_customers' => 0,
                'missing_email_customers' => 0,
            ];
        }

        $profiles = MarketingProfile::query()
            ->when(
                $tenantId === null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('marketing_profiles.tenant_id'),
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('marketing_profiles.tenant_id', $tenantId)
            )
            ->whereIn('id', $profileIds->all())
            ->get(['id', 'first_name', 'last_name', 'email', 'normalized_email'])
            ->keyBy('id');

        $rows = [];
        $eligibleCustomers = 0;
        $missingEmailCustomers = 0;

        foreach ($profileIds as $profileId) {
            $profile = $profiles->get($profileId);
            $outstanding = (array) ($outstandingByProfile[$profileId] ?? []);
            $buckets = collect((array) ($outstanding['buckets'] ?? []));

            $email = trim((string) ($profile?->normalized_email ?: $profile?->email ?: ''));
            if ($email === '') {
                $missingEmailCustomers++;
            } else {
                $eligibleCustomers++;
            }

            $firstEarnedAt = $buckets
                ->pluck('earned_at')
                ->filter(fn ($value): bool => $value instanceof CarbonImmutable)
                ->sort()
                ->first();

            $latestEarnedAt = $buckets
                ->pluck('earned_at')
                ->filter(fn ($value): bool => $value instanceof CarbonImmutable)
                ->sortDesc()
                ->first();

            $topSources = $buckets
                ->groupBy('source_label')
                ->map(fn (Collection $sourceBuckets, string $label): array => [
                    'label' => $label,
                    'candle_cash' => round($this->candleCashService->amountFromPoints($sourceBuckets->sum('remaining_points')), 2),
                    'amount' => round($this->candleCashService->amountFromPoints($sourceBuckets->sum('remaining_points')), 2),
                ])
                ->sortByDesc('amount')
                ->take(3)
                ->values()
                ->all();

            $rows[] = [
                'marketing_profile_id' => $profileId,
                'first_name' => trim((string) ($profile?->first_name ?? '')),
                'last_name' => trim((string) ($profile?->last_name ?? '')),
                'email' => $email !== '' ? $email : null,
                'outstanding_candle_cash' => round((float) ($outstanding['amount'] ?? 0), 2),
                'outstanding_amount' => round((float) ($outstanding['amount'] ?? 0), 2),
                'formatted_outstanding_amount' => $this->formatCurrency((float) ($outstanding['amount'] ?? 0)),
                'earned_date' => $firstEarnedAt instanceof CarbonImmutable ? $firstEarnedAt->toIso8601String() : null,
                'latest_earned_date' => $latestEarnedAt instanceof CarbonImmutable ? $latestEarnedAt->toIso8601String() : null,
                'outstanding_bucket_count' => (int) $buckets->count(),
                'top_sources' => $topSources,
                'expiration_date' => null,
                'expiration_policy' => 'No fixed expiration date is currently stored for earned reward credit buckets in this ledger.',
            ];
        }

        return [
            'rows' => $rows,
            'eligible_customers' => $eligibleCustomers,
            'missing_email_customers' => $missingEmailCustomers,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function outstandingRewardBuckets(?int $tenantId = null): array
    {
        $state = $this->currentLedgerState($tenantId);
        $outstandingByProfile = (array) ($state['outstanding_by_profile'] ?? []);
        $profileIds = collect(array_keys($outstandingByProfile))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($profileIds->isEmpty()) {
            return [];
        }

        $profiles = MarketingProfile::query()
            ->when(
                $tenantId === null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('marketing_profiles.tenant_id'),
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('marketing_profiles.tenant_id', $tenantId)
            )
            ->whereIn('id', $profileIds->all())
            ->get([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_email_marketing',
                'accepts_sms_marketing',
            ])
            ->keyBy('id');

        $rows = [];
        foreach ($profileIds as $profileId) {
            /** @var MarketingProfile|null $profile */
            $profile = $profiles->get($profileId);
            if (! $profile) {
                continue;
            }

            $tenantForProfile = isset($profile->tenant_id) ? (int) $profile->tenant_id : $tenantId;
            $email = trim((string) ($profile->normalized_email ?: $profile->email ?: ''));
            $phone = trim((string) ($profile->normalized_phone ?: $profile->phone ?: ''));
            $name = trim((string) (($profile->first_name ?? '').' '.($profile->last_name ?? '')));

            foreach ((array) data_get($outstandingByProfile, $profileId.'.buckets', []) as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }

                $transactionId = (int) ($bucket['transaction_id'] ?? 0);
                $remainingPoints = CandleCashMeasurement::normalizeStoredAmount($bucket['remaining_points'] ?? 0);
                if ($transactionId <= 0 || $remainingPoints <= 0) {
                    continue;
                }

                $amount = round($this->candleCashService->amountFromPoints($remainingPoints), 2);
                $earnedAt = $bucket['earned_at'] ?? null;

                $rows[] = [
                    'reward_identifier' => 'earned-bucket:tx:'.$transactionId,
                    'transaction_id' => $transactionId,
                    'tenant_id' => $tenantForProfile,
                    'marketing_profile_id' => $profileId,
                    'reward_code' => null,
                    'status' => 'issued',
                    'first_name' => trim((string) ($profile->first_name ?? '')),
                    'last_name' => trim((string) ($profile->last_name ?? '')),
                    'customer_name' => $name !== '' ? $name : 'Customer #'.$profileId,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'accepts_email_marketing' => (bool) ($profile->accepts_email_marketing ?? false),
                    'accepts_sms_marketing' => (bool) ($profile->accepts_sms_marketing ?? false),
                    'email_contactable' => $email !== '',
                    'sms_contactable' => $phone !== '' && (bool) ($profile->accepts_sms_marketing ?? false),
                    'earned_at' => $earnedAt instanceof CarbonImmutable ? $earnedAt->toIso8601String() : null,
                    'expires_at' => null,
                    'remaining_points' => $remainingPoints,
                    'remaining_candle_cash' => $amount,
                    'remaining_amount' => $amount,
                    'formatted_remaining_amount' => $this->formatCurrency($amount),
                    'source_key' => (string) ($bucket['source_key'] ?? 'other_earn'),
                    'source_label' => (string) ($bucket['source_label'] ?? 'Reward earn'),
                    'source_definition' => (string) ($bucket['source_definition'] ?? ''),
                ];
            }
        }

        return collect($rows)
            ->sortBy([
                ['earned_at', 'asc'],
                ['transaction_id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function outstandingRewardBucket(?int $tenantId, string $rewardIdentifier): ?array
    {
        $identifier = strtolower(trim($rewardIdentifier));
        if ($identifier === '') {
            return null;
        }

        return collect($this->outstandingRewardBuckets($tenantId))
            ->first(fn (array $row): bool => strtolower(trim((string) ($row['reward_identifier'] ?? ''))) === $identifier);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function rewardContext(?int $tenantId, string $rewardIdentifier): ?array
    {
        $identifier = strtolower(trim($rewardIdentifier));
        if ($identifier === '') {
            return null;
        }

        $current = $this->outstandingRewardBucket($tenantId, $identifier);
        if (is_array($current)) {
            return [
                'reward_identifier' => $current['reward_identifier'] ?? null,
                'transaction_id' => $current['transaction_id'] ?? null,
                'marketing_profile_id' => $current['marketing_profile_id'] ?? null,
                'source_key' => $current['source_key'] ?? null,
                'source_label' => $current['source_label'] ?? null,
                'earned_at' => $current['earned_at'] ?? null,
                'amount' => $current['remaining_amount'] ?? $current['remaining_candle_cash'] ?? null,
            ];
        }

        if (! preg_match('/^earned-bucket:tx:(\d+)$/', $identifier, $matches)) {
            return null;
        }

        $transactionId = (int) ($matches[1] ?? 0);
        if ($transactionId <= 0) {
            return null;
        }

        $transaction = CandleCashTransaction::query()
            ->with('profile:id,tenant_id')
            ->whereKey($transactionId)
            ->first();

        if (! $transaction) {
            return null;
        }

        $profileTenantId = $transaction->profile?->tenant_id;
        if ($tenantId === null) {
            if ($profileTenantId !== null) {
                return null;
            }
        } elseif ((int) $profileTenantId !== $tenantId) {
            return null;
        }

        $taskHandle = $this->taskHandlesByTransactionId(collect([$transaction]))[(int) $transaction->id] ?? null;
        $sourceKey = $this->normalizer->classifyEarnSource($transaction, $taskHandle);
        $sourceDefinition = (array) ($this->normalizer->sourceDefinitions()[$sourceKey] ?? []);
        $amount = round($this->candleCashService->amountFromPoints(
            CandleCashMeasurement::normalizeStoredAmount($transaction->candle_cash_delta ?? 0)
        ), 2);

        return [
            'reward_identifier' => $identifier,
            'transaction_id' => (int) $transaction->id,
            'marketing_profile_id' => (int) ($transaction->marketing_profile_id ?? 0),
            'source_key' => $sourceKey,
            'source_label' => (string) ($sourceDefinition['label'] ?? ucwords(str_replace('_', ' ', $sourceKey))),
            'earned_at' => $this->timestampForTransaction($transaction)->toIso8601String(),
            'amount' => $amount,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $windowEvents
     * @param  array<int,array<int,CarbonImmutable>>  $redeemedAtByProfile
     * @param  array<int,array<int,CarbonImmutable>>  $debitAtByProfile
     * @return array<string,mixed>
     */
    protected function timeToFirstRedemptionMetrics(
        Collection $windowEvents,
        array $redeemedAtByProfile,
        array $debitAtByProfile
    ): array {
        $lags = [];
        $usedFallback = false;

        foreach ($windowEvents as $event) {
            $earnedAt = $event['earned_at'] ?? null;
            $profileId = (int) ($event['marketing_profile_id'] ?? 0);
            if (! $earnedAt instanceof CarbonImmutable || $profileId <= 0) {
                continue;
            }

            $redemptions = collect($redeemedAtByProfile[$profileId] ?? []);
            $firstAfterEarn = $redemptions
                ->filter(fn ($redemptionAt): bool => $redemptionAt instanceof CarbonImmutable && $redemptionAt->greaterThanOrEqualTo($earnedAt))
                ->sort()
                ->first();

            if (! $firstAfterEarn instanceof CarbonImmutable) {
                $firstAfterEarn = collect($debitAtByProfile[$profileId] ?? [])
                    ->filter(fn ($debitAt): bool => $debitAt instanceof CarbonImmutable && $debitAt->greaterThanOrEqualTo($earnedAt))
                    ->sort()
                    ->first();
                $usedFallback = $usedFallback || $firstAfterEarn instanceof CarbonImmutable;
            }

            if (! $firstAfterEarn instanceof CarbonImmutable) {
                continue;
            }

            $lagDays = max(0, round(($firstAfterEarn->getTimestamp() - $earnedAt->getTimestamp()) / 86400, 2));
            $lags[] = $lagDays;
        }

        sort($lags);
        $sampleCount = count($lags);
        $averageDays = $sampleCount > 0 ? round(array_sum($lags) / $sampleCount, 2) : null;
        $medianDays = $sampleCount > 0 ? $this->median($lags) : null;

        return [
            'averageDays' => $averageDays,
            'medianDays' => $medianDays,
            'formattedAverageDays' => $averageDays !== null ? number_format($averageDays, 2).' days' : 'No redemptions yet',
            'formattedMedianDays' => $medianDays !== null ? number_format($medianDays, 2).' days' : 'No redemptions yet',
            'sampleCount' => $sampleCount,
            // We cannot directly tie each redeemed order to a specific earn bucket in the current ledger.
            // Primary method: first redeemed reward credit order after each earn event.
            // Fallback method: first post-earn negative reward credit ledger movement when order linkage is missing.
            'approximation' => $usedFallback
                ? 'Approximated from the first redeemed reward credit order after each earn event, with fallback to first post-earn reward credit debit when direct order linkage is unavailable.'
                : 'Approximated from the first redeemed reward credit order after each earn event for the same customer profile.',
        ];
    }

    protected function median(array $lags): float
    {
        $count = count($lags);
        if ($count === 0) {
            return 0.0;
        }

        $middle = (int) floor(($count - 1) / 2);
        if ($count % 2 === 1) {
            return round((float) $lags[$middle], 2);
        }

        return round((((float) $lags[$middle]) + ((float) $lags[$middle + 1])) / 2, 2);
    }

    /**
     * @return array<string,mixed>
     */
    protected function currentLedgerState(?int $tenantId = null): array
    {
        $scopeKey = $tenantId !== null ? 'tenant:'.$tenantId : 'tenant:none';
        if (array_key_exists($scopeKey, $this->currentLedgerStateByScope)) {
            return $this->currentLedgerStateByScope[$scopeKey];
        }

        $transactions = CandleCashTransaction::query()
            ->join('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_transactions.marketing_profile_id')
            ->when(
                $tenantId === null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('mp.tenant_id'),
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('mp.tenant_id', $tenantId)
            )
            ->where('candle_cash_delta', '!=', 0)
            ->orderBy('candle_cash_transactions.marketing_profile_id')
            ->orderBy('candle_cash_transactions.created_at')
            ->orderBy('candle_cash_transactions.id')
            ->get([
                'candle_cash_transactions.id',
                'candle_cash_transactions.marketing_profile_id',
                'candle_cash_transactions.type',
                'candle_cash_transactions.candle_cash_delta',
                'candle_cash_transactions.source',
                'candle_cash_transactions.source_id',
                'candle_cash_transactions.description',
                'candle_cash_transactions.legacy_points_origin',
                'candle_cash_transactions.created_at',
            ]);

        $taskHandlesByTransactionId = $this->taskHandlesByTransactionId($transactions);

        $bucketsByProfile = [];
        $programEarnEvents = [];
        $debitAtByProfile = [];

        foreach ($transactions as $transaction) {
            $profileId = (int) ($transaction->marketing_profile_id ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            $points = CandleCashMeasurement::normalizeStoredAmount($transaction->candle_cash_delta ?? 0);
            if (abs($points) < 0.0005) {
                continue;
            }

            if (! array_key_exists($profileId, $bucketsByProfile)) {
                $bucketsByProfile[$profileId] = [];
            }

            if ($points > 0) {
                $taskHandle = $taskHandlesByTransactionId[(int) $transaction->id] ?? null;
                $bucketKind = $this->positiveBucketKind($transaction);
                $sourceKey = $bucketKind === 'program'
                    ? $this->normalizer->classifyEarnSource($transaction, $taskHandle)
                    : ($bucketKind === 'legacy' ? 'legacy_growave_migrated' : 'manual_nonexpiring_opening_balance');
                $sourceDefinition = $bucketKind === 'program'
                    ? (array) ($this->normalizer->sourceDefinitions()[$sourceKey] ?? [
                        'label' => 'Other earn',
                        'definition' => 'Program-earned reward credit events grouped under a fallback source bucket.',
                    ])
                    : $this->nonProgramBucketDefinition($bucketKind);

                $earnedAt = $this->timestampForTransaction($transaction);
                $bucket = [
                    'transaction_id' => (int) $transaction->id,
                    'marketing_profile_id' => $profileId,
                    'kind' => $bucketKind,
                    'source_key' => $sourceKey,
                    'source_label' => (string) ($sourceDefinition['label'] ?? 'Other earn'),
                    'source_definition' => (string) ($sourceDefinition['definition'] ?? ''),
                    'remaining_points' => $points,
                    'earned_at' => $earnedAt,
                ];

                $bucketsByProfile[$profileId][] = $bucket;

                if ($this->normalizer->isEarnedLimitEligible($transaction)) {
                    $programEarnEvents[] = [
                        'transaction_id' => (int) $transaction->id,
                        'marketing_profile_id' => $profileId,
                        'candle_cash_delta' => $points,
                        'amount' => round($this->candleCashService->amountFromPoints($points), 2),
                        'source_key' => $sourceKey,
                        'source_label' => (string) ($sourceDefinition['label'] ?? 'Other earn'),
                        'source_definition' => (string) ($sourceDefinition['definition'] ?? ''),
                        'earned_at' => $earnedAt,
                    ];
                }

                continue;
            }

            $remainingToConsume = CandleCashMeasurement::normalizeStoredAmount(abs($points));
            if ($remainingToConsume <= 0) {
                continue;
            }

            $debitAtByProfile[$profileId] ??= [];
            $debitAtByProfile[$profileId][] = $this->timestampForTransaction($transaction);

            foreach ($bucketsByProfile[$profileId] as $index => $bucket) {
                $available = CandleCashMeasurement::normalizeStoredAmount($bucket['remaining_points'] ?? 0);
                if ($available <= 0) {
                    continue;
                }

                $consumed = min($available, $remainingToConsume);
                $bucket['remaining_points'] = CandleCashMeasurement::normalizeStoredAmount($available - $consumed);
                $bucketsByProfile[$profileId][$index] = $bucket;
                $remainingToConsume = CandleCashMeasurement::normalizeStoredAmount($remainingToConsume - $consumed);

                if ($remainingToConsume <= 0) {
                    break;
                }
            }
        }

        $outstandingByProfile = [];
        $excludedOpeningPoints = 0.0;
        $legacyRemainingPoints = 0.0;
        $manualNonExpiringPoints = 0.0;
        $totalRemainingPoints = 0.0;

        foreach ($bucketsByProfile as $profileId => $buckets) {
            $programBuckets = collect($buckets)
                ->filter(fn (array $bucket): bool => ($bucket['kind'] ?? '') === 'program' && (float) ($bucket['remaining_points'] ?? 0) > 0)
                ->values();

            $legacyRemaining = CandleCashMeasurement::normalizeStoredAmount(collect($buckets)
                ->filter(fn (array $bucket): bool => ($bucket['kind'] ?? '') === 'legacy')
                ->sum('remaining_points'));
            $manualRemaining = CandleCashMeasurement::normalizeStoredAmount(collect($buckets)
                ->filter(fn (array $bucket): bool => ($bucket['kind'] ?? '') === 'manual_nonexpiring')
                ->sum('remaining_points'));
            $profileRemaining = CandleCashMeasurement::normalizeStoredAmount(collect($buckets)->sum('remaining_points'));

            $legacyRemainingPoints += max(0, $legacyRemaining);
            $manualNonExpiringPoints += max(0, $manualRemaining);
            $excludedOpeningPoints += max(0, CandleCashMeasurement::normalizeStoredAmount($legacyRemaining + $manualRemaining));
            $totalRemainingPoints += max(0, $profileRemaining);

            if ($programBuckets->isEmpty()) {
                continue;
            }

            $programPoints = CandleCashMeasurement::normalizeStoredAmount($programBuckets->sum('remaining_points'));
            $outstandingByProfile[$profileId] = [
                'points' => $programPoints,
                'amount' => round($this->candleCashService->amountFromPoints($programPoints), 2),
                'buckets' => $programBuckets->all(),
            ];
        }

        $programRemainingPoints = CandleCashMeasurement::normalizeStoredAmount(collect($outstandingByProfile)->sum('points'));
        $balanceTablePoints = $this->balanceTablePoints($tenantId);

        $this->currentLedgerStateByScope[$scopeKey] = [
            'program_earn_events' => $programEarnEvents,
            'outstanding_by_profile' => $outstandingByProfile,
            'excluded_opening_points' => $excludedOpeningPoints,
            'legacy_remaining_points' => CandleCashMeasurement::normalizeStoredAmount($legacyRemainingPoints),
            'manual_nonexpiring_points' => CandleCashMeasurement::normalizeStoredAmount($manualNonExpiringPoints),
            'program_remaining_points' => $programRemainingPoints,
            'total_remaining_points' => CandleCashMeasurement::normalizeStoredAmount($totalRemainingPoints),
            'balance_table_points' => $balanceTablePoints,
            'redeemed_at_by_profile' => $this->redeemedAtByProfile($tenantId),
            'debit_at_by_profile' => $debitAtByProfile,
        ];

        return $this->currentLedgerStateByScope[$scopeKey];
    }

    /**
     * @param  Collection<int,CandleCashTransaction>  $transactions
     * @return array<int,string>
     */
    protected function taskHandlesByTransactionId(Collection $transactions): array
    {
        $transactionIds = $transactions
            ->filter(fn (CandleCashTransaction $transaction): bool => strtolower(trim((string) $transaction->source)) === 'candle_cash_task')
            ->pluck('id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($transactionIds->isEmpty()) {
            return [];
        }

        $handles = CandleCashTaskCompletion::query()
            ->with('task:id,handle')
            ->whereIn('candle_cash_transaction_id', $transactionIds->all())
            ->get(['id', 'candle_cash_transaction_id', 'candle_cash_task_id'])
            ->mapWithKeys(function (CandleCashTaskCompletion $completion): array {
                $transactionId = (int) ($completion->candle_cash_transaction_id ?? 0);
                if ($transactionId <= 0) {
                    return [];
                }

                return [$transactionId => strtolower(trim((string) ($completion->task?->handle ?? '')))];
            })
            ->all();

        return $handles;
    }

    /**
     * @return array<int,array<int,CarbonImmutable>>
     */
    protected function redeemedAtByProfile(?int $tenantId = null): array
    {
        $rows = CandleCashRedemption::query()
            ->join('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_redemptions.marketing_profile_id')
            ->when(
                $tenantId === null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('mp.tenant_id'),
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('mp.tenant_id', $tenantId)
            )
            ->where('status', 'redeemed')
            ->whereNotNull('redeemed_at')
            ->orderBy('marketing_profile_id')
            ->orderBy('redeemed_at')
            ->get(['candle_cash_redemptions.marketing_profile_id', 'candle_cash_redemptions.redeemed_at']);

        $grouped = [];
        foreach ($rows as $row) {
            $profileId = (int) ($row->marketing_profile_id ?? 0);
            if ($profileId <= 0 || ! $row->redeemed_at) {
                continue;
            }

            $grouped[$profileId] ??= [];
            $grouped[$profileId][] = CarbonImmutable::instance($row->redeemed_at);
        }

        return $grouped;
    }

    protected function timestampForTransaction(CandleCashTransaction $transaction): CarbonImmutable
    {
        if ($transaction->created_at !== null) {
            return CarbonImmutable::instance($transaction->created_at);
        }

        return CarbonImmutable::now();
    }

    protected function formatCurrency(float $value): string
    {
        return '$'.number_format(round($value, 2), 2);
    }

    protected function positiveBucketKind(CandleCashTransaction $transaction): string
    {
        if ($this->normalizer->isLegacyNonExpiringCredit($transaction)) {
            return 'legacy';
        }

        if ($this->normalizer->isEarnedLimitEligible($transaction)) {
            return 'program';
        }

        return 'manual_nonexpiring';
    }

    /**
     * @return array{label:string,definition:string}
     */
    protected function nonProgramBucketDefinition(string $kind): array
    {
        if ($kind === 'legacy') {
            return [
                'label' => 'Legacy Growave migrated Candle Cash',
                'definition' => 'Converted Growave loyalty value that remains customer-visible in Candle Cash, but is excluded from the expiring program-earned pool.',
            ];
        }

        return [
            'label' => 'Manual non-expiring Candle Cash',
            'definition' => 'Manual opening, grandfathered, or seed credit that stays customer-visible but is excluded from the expiring program-earned pool.',
        ];
    }

    protected function balanceTablePoints(?int $tenantId = null): float
    {
        $points = CandleCashBalance::query()
            ->join('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_balances.marketing_profile_id')
            ->when(
                $tenantId === null,
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('mp.tenant_id'),
                fn (EloquentBuilder $query): EloquentBuilder => $query->where('mp.tenant_id', $tenantId)
            )
            ->sum('candle_cash_balances.balance');

        return CandleCashMeasurement::normalizeStoredAmount($points);
    }

    /**
     * @return array{points:float,amount:float,formattedAmount:string}
     */
    protected function balanceLiabilityValue(float $points): array
    {
        $normalizedPoints = CandleCashMeasurement::normalizeStoredAmount($points);
        $amount = round($this->candleCashService->amountFromPoints($normalizedPoints), 2);

        return [
            'points' => $normalizedPoints,
            'amount' => $amount,
            'formattedAmount' => $this->formatCurrency($amount),
        ];
    }
}
