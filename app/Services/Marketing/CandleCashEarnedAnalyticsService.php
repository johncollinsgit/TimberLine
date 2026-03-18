<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CandleCashEarnedAnalyticsService
{
    /**
     * @var array<string,mixed>|null
     */
    protected ?array $currentLedgerState = null;

    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashLedgerNormalizationService $normalizer
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $state = $this->currentLedgerState();
        $sourceDefinitions = $this->normalizer->sourceDefinitions();

        $windowEvents = collect((array) ($state['program_earn_events'] ?? []))
            ->filter(function (array $event) use ($from, $to): bool {
                $earnedAt = $event['earned_at'] ?? null;
                if (! $earnedAt instanceof CarbonImmutable) {
                    return false;
                }

                return $earnedAt->greaterThanOrEqualTo($from) && $earnedAt->lessThanOrEqualTo($to);
            })
            ->values();

        $earnedPoints = (int) $windowEvents->sum('points');
        $earnedAmount = round($this->candleCashService->amountFromPoints($earnedPoints), 2);

        $breakdown = collect($sourceDefinitions)
            ->map(function (array $definition, string $key): array {
                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'definition' => $definition['definition'],
                    'points' => 0,
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
                    'definition' => 'Program-earned Candle Cash events grouped under a fallback source bucket.',
                    'points' => 0,
                    'amount' => 0.0,
                    'formattedAmount' => $this->formatCurrency(0),
                    'sharePct' => 0.0,
                    'eventCount' => 0,
                    'customerCount' => 0,
                ]);
            }

            $row = $breakdown->get($sourceKey);
            $row['points'] += (int) ($event['points'] ?? 0);
            $row['eventCount']++;
            $row['__customers'] = [
                ...((array) ($row['__customers'] ?? [])),
                (int) ($event['marketing_profile_id'] ?? 0),
            ];

            $breakdown->put($sourceKey, $row);
        }

        $breakdownRows = $breakdown
            ->map(function (array $row) use ($earnedAmount): array {
                $points = (int) ($row['points'] ?? 0);
                $amount = round($this->candleCashService->amountFromPoints($points), 2);
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
        $outstandingPoints = (int) $outstandingRows->sum('points');
        $outstandingAmount = round($this->candleCashService->amountFromPoints($outstandingPoints), 2);
        $outstandingCustomerCount = (int) $outstandingRows->count();
        $excludedOpeningPoints = (int) ($state['excluded_opening_points'] ?? 0);
        $excludedOpeningAmount = round($this->candleCashService->amountFromPoints($excludedOpeningPoints), 2);

        $timeToFirstRedemption = $this->timeToFirstRedemptionMetrics(
            $windowEvents,
            (array) ($state['redeemed_at_by_profile'] ?? []),
            (array) ($state['debit_at_by_profile'] ?? [])
        );

        $reminderCandidates = $this->reminderCandidates();

        return [
            'title' => 'Candle Cash earn activity',
            'subtitle' => 'Program-earned Candle Cash and redemption behavior for the selected timeframe.',
            'earned' => [
                'points' => $earnedPoints,
                'amount' => $earnedAmount,
                'formattedAmount' => $this->formatCurrency($earnedAmount),
                'eventCount' => (int) $windowEvents->count(),
                'customerCount' => (int) $windowEvents
                    ->pluck('marketing_profile_id')
                    ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
                    ->unique()
                    ->count(),
                'sourceSummary' => $topSourceSummary !== '' ? $topSourceSummary : 'No new program-earned Candle Cash events in this window.',
            ],
            'breakdown' => [
                'rows' => $breakdownRows->all(),
                'sourceDefinitions' => $sourceDefinitions,
            ],
            'outstanding' => [
                'points' => $outstandingPoints,
                'amount' => $outstandingAmount,
                'formattedAmount' => $this->formatCurrency($outstandingAmount),
                'customerCount' => $outstandingCustomerCount,
                'excludedGrandfatheredPoints' => $excludedOpeningPoints,
                'excludedGrandfatheredAmount' => $excludedOpeningAmount,
                'helperText' => 'Currently outstanding earned Candle Cash excludes imported, grandfathered, and manual opening balances.',
            ],
            'timeToFirstRedemption' => $timeToFirstRedemption,
            'customersWithOutstandingEarned' => [
                'count' => $outstandingCustomerCount,
            ],
            'reminderEligibility' => [
                'eligibleCustomers' => (int) ($reminderCandidates['eligible_customers'] ?? 0),
                'missingEmailCustomers' => (int) ($reminderCandidates['missing_email_customers'] ?? 0),
                'expirationPolicy' => 'No fixed expiration date is currently stored for earned Candle Cash buckets in this ledger.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reminderCandidates(): array
    {
        $state = $this->currentLedgerState();
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
                    'points' => (int) $sourceBuckets->sum('remaining_points'),
                    'amount' => round($this->candleCashService->amountFromPoints((int) $sourceBuckets->sum('remaining_points')), 2),
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
                'outstanding_points' => (int) ($outstanding['points'] ?? 0),
                'outstanding_amount' => round((float) ($outstanding['amount'] ?? 0), 2),
                'formatted_outstanding_amount' => $this->formatCurrency((float) ($outstanding['amount'] ?? 0)),
                'earned_date' => $firstEarnedAt instanceof CarbonImmutable ? $firstEarnedAt->toIso8601String() : null,
                'latest_earned_date' => $latestEarnedAt instanceof CarbonImmutable ? $latestEarnedAt->toIso8601String() : null,
                'outstanding_bucket_count' => (int) $buckets->count(),
                'top_sources' => $topSources,
                'expiration_date' => null,
                'expiration_policy' => 'No fixed expiration date is currently stored for earned Candle Cash buckets in this ledger.',
            ];
        }

        return [
            'rows' => $rows,
            'eligible_customers' => $eligibleCustomers,
            'missing_email_customers' => $missingEmailCustomers,
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
            // Primary method: first redeemed Candle Cash order after each earn event.
            // Fallback method: first post-earn negative Candle Cash ledger movement when order linkage is missing.
            'approximation' => $usedFallback
                ? 'Approximated from the first redeemed Candle Cash order after each earn event, with fallback to first post-earn Candle Cash debit when direct order linkage is unavailable.'
                : 'Approximated from the first redeemed Candle Cash order after each earn event for the same customer profile.',
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
    protected function currentLedgerState(): array
    {
        if ($this->currentLedgerState !== null) {
            return $this->currentLedgerState;
        }

        $transactions = CandleCashTransaction::query()
            ->where('points', '!=', 0)
            ->orderBy('marketing_profile_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'marketing_profile_id',
                'type',
                'points',
                'source',
                'source_id',
                'description',
                'created_at',
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

            $points = (int) ($transaction->points ?? 0);
            if ($points === 0) {
                continue;
            }

            if (! array_key_exists($profileId, $bucketsByProfile)) {
                $bucketsByProfile[$profileId] = [];
            }

            if ($points > 0) {
                $isOpening = $this->normalizer->isGrandfatheredOpening($transaction);
                $taskHandle = $taskHandlesByTransactionId[(int) $transaction->id] ?? null;
                $sourceKey = $isOpening ? 'opening_balance' : $this->normalizer->classifyEarnSource($transaction, $taskHandle);
                $sourceDefinition = (array) ($this->normalizer->sourceDefinitions()[$sourceKey] ?? [
                    'label' => 'Other earn',
                    'definition' => 'Program-earned Candle Cash events grouped under a fallback source bucket.',
                ]);

                $earnedAt = $this->timestampForTransaction($transaction);
                $bucket = [
                    'transaction_id' => (int) $transaction->id,
                    'marketing_profile_id' => $profileId,
                    'kind' => $isOpening ? 'opening' : 'program',
                    'source_key' => $sourceKey,
                    'source_label' => (string) ($sourceDefinition['label'] ?? 'Other earn'),
                    'source_definition' => (string) ($sourceDefinition['definition'] ?? ''),
                    'remaining_points' => $points,
                    'earned_at' => $earnedAt,
                ];

                $bucketsByProfile[$profileId][] = $bucket;

                if (! $isOpening) {
                    $programEarnEvents[] = [
                        'transaction_id' => (int) $transaction->id,
                        'marketing_profile_id' => $profileId,
                        'points' => $points,
                        'amount' => round($this->candleCashService->amountFromPoints($points), 2),
                        'source_key' => $sourceKey,
                        'source_label' => (string) ($sourceDefinition['label'] ?? 'Other earn'),
                        'source_definition' => (string) ($sourceDefinition['definition'] ?? ''),
                        'earned_at' => $earnedAt,
                    ];
                }

                continue;
            }

            $remainingToConsume = abs($points);
            if ($remainingToConsume <= 0) {
                continue;
            }

            $debitAtByProfile[$profileId] ??= [];
            $debitAtByProfile[$profileId][] = $this->timestampForTransaction($transaction);

            foreach ($bucketsByProfile[$profileId] as $index => $bucket) {
                $available = (int) ($bucket['remaining_points'] ?? 0);
                if ($available <= 0) {
                    continue;
                }

                $consumed = min($available, $remainingToConsume);
                $bucket['remaining_points'] = $available - $consumed;
                $bucketsByProfile[$profileId][$index] = $bucket;
                $remainingToConsume -= $consumed;

                if ($remainingToConsume <= 0) {
                    break;
                }
            }
        }

        $outstandingByProfile = [];
        $excludedOpeningPoints = 0;

        foreach ($bucketsByProfile as $profileId => $buckets) {
            $programBuckets = collect($buckets)
                ->filter(fn (array $bucket): bool => ($bucket['kind'] ?? '') === 'program' && (int) ($bucket['remaining_points'] ?? 0) > 0)
                ->values();

            $openingRemaining = (int) collect($buckets)
                ->filter(fn (array $bucket): bool => ($bucket['kind'] ?? '') === 'opening')
                ->sum('remaining_points');

            $excludedOpeningPoints += max(0, $openingRemaining);

            if ($programBuckets->isEmpty()) {
                continue;
            }

            $programPoints = (int) $programBuckets->sum('remaining_points');
            $outstandingByProfile[$profileId] = [
                'points' => $programPoints,
                'amount' => round($this->candleCashService->amountFromPoints($programPoints), 2),
                'buckets' => $programBuckets->all(),
            ];
        }

        $this->currentLedgerState = [
            'program_earn_events' => $programEarnEvents,
            'outstanding_by_profile' => $outstandingByProfile,
            'excluded_opening_points' => $excludedOpeningPoints,
            'redeemed_at_by_profile' => $this->redeemedAtByProfile(),
            'debit_at_by_profile' => $debitAtByProfile,
        ];

        return $this->currentLedgerState;
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
    protected function redeemedAtByProfile(): array
    {
        $rows = CandleCashRedemption::query()
            ->where('status', 'redeemed')
            ->whereNotNull('redeemed_at')
            ->orderBy('marketing_profile_id')
            ->orderBy('redeemed_at')
            ->get(['marketing_profile_id', 'redeemed_at']);

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
}
