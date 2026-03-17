<?php

namespace App\Services\Shopify\Dashboard;

use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Marketing\MarketingAttributionSourceMetaBuilder;
use App\Services\Marketing\CandleCashService;
use App\Services\Reporting\AnalyticsComparisonService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ShopifyEmbeddedDashboardDataService
{
    public function __construct(
        protected ShopifyEmbeddedDashboardConfig $config,
        protected ShopifyEmbeddedDashboardQuery $query,
        protected ShopifyEmbeddedDashboardCandleCashValueProvider $candleCashProvider,
        protected ShopifyEmbeddedDashboardProfitEstimator $profitEstimator,
        protected ShopifyEmbeddedDashboardAttributionClassifier $attributionClassifier,
        protected ShopifyEmbeddedDashboardAttributionAggregator $attributionAggregator,
        protected AnalyticsComparisonService $comparisonService,
        protected CandleCashService $candleCashService,
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder
    ) {
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function payload(array $input = []): array
    {
        $config = $this->config->payload();
        $resolvedQuery = $this->query->resolve($input, $config);
        $cacheKey = $this->cacheKey($resolvedQuery);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($config, $resolvedQuery): array {
            $primaryWindow = $this->query->rehydrateWindow((array) $resolvedQuery['primary']);
            $comparisonWindow = is_array($resolvedQuery['comparisonWindow'] ?? null)
                ? $this->query->rehydrateWindow((array) $resolvedQuery['comparisonWindow'])
                : null;

            $primarySnapshot = $this->windowSnapshot(
                $primaryWindow['from'],
                $primaryWindow['to'],
                (string) $resolvedQuery['locationGrouping']
            );
            $comparisonSnapshot = $comparisonWindow
                ? $this->windowSnapshot(
                    $comparisonWindow['from'],
                    $comparisonWindow['to'],
                    (string) $resolvedQuery['locationGrouping']
                )
                : null;

            return [
                'meta' => [
                    'generatedAt' => now()->toIso8601String(),
                    'currencyCode' => 'USD',
                    'partialData' => [
                        'attribution' => (bool) $primarySnapshot['flags']['attribution_partial'],
                        'locations' => (bool) $primarySnapshot['flags']['location_partial'],
                        'profit' => (bool) $primarySnapshot['flags']['profit_estimated'],
                    ],
                ],
                'query' => $resolvedQuery,
                'config' => $config,
                'topMetrics' => $this->topMetrics($primarySnapshot, $comparisonSnapshot),
                'chart' => $this->chart(
                    $primarySnapshot,
                    $comparisonSnapshot,
                    $primaryWindow['from'],
                    $primaryWindow['to'],
                    $comparisonWindow,
                    $resolvedQuery
                ),
                'attribution' => $this->attributionSection($primarySnapshot, $comparisonSnapshot, $config),
                'locationOrigins' => $this->locationSection($primarySnapshot, $resolvedQuery),
                'financialSummary' => $this->financialSummary($primarySnapshot, $comparisonSnapshot),
                'flags' => [
                    'hasAnyData' => (bool) $primarySnapshot['flags']['has_any_data'],
                    'usesFallbackAttribution' => (bool) $primarySnapshot['flags']['attribution_partial'],
                    'usesEstimatedOrderRevenue' => true,
                ],
            ];
        });
    }

    protected function cacheKey(array $resolvedQuery): string
    {
        return 'shopify:embedded-dashboard:' . sha1(json_encode($resolvedQuery));
    }

    protected function currency(float $value): string
    {
        return '$' . number_format($value, 2);
    }

    protected function integerCurrency(float $value): string
    {
        return '$' . number_format(round($value));
    }

    protected function percentage(float $value, int $precision = 1): string
    {
        return number_format($value, $precision) . '%';
    }

    protected function toneForDelta(?float $delta): string
    {
        if ($delta === null || abs($delta) < 0.0001) {
            return 'neutral';
        }

        return $delta > 0 ? 'positive' : 'negative';
    }

    protected function formatDelta(?float $delta, string $kind = 'percentage'): string
    {
        if ($delta === null) {
            return 'No prior period';
        }

        if ($kind === 'currency') {
            return ($delta >= 0 ? '+' : '-') . $this->currency(abs($delta));
        }

        return ($delta >= 0 ? '+' : '') . number_format($delta, 1) . '%';
    }

    /**
     * @return array<string,mixed>
     */
    protected function windowSnapshot(CarbonImmutable $from, CarbonImmutable $to, string $locationGrouping): array
    {
        $conversionRows = $this->conversionRows($from, $to);
        $birthdayRows = $this->birthdayRows($from, $to);
        $referralRows = $this->referralRows($from, $to);
        $revenueRows = $this->normalizedRevenueRows($conversionRows, $birthdayRows, $referralRows);
        $classifiedAttribution = $this->classifyAttributionRows(
            $revenueRows,
            (array) $this->config->payload()['visibleAttributionSources']
        );
        $revenueRows = $classifiedAttribution['rows'];
        $locationRows = $this->locationRows($revenueRows, $locationGrouping);
        $candleCash = $this->candleCashProvider->snapshot($from, $to);
        $returningCustomerRate = $this->returningCustomerRate($from, $to);
        $profit = $this->profitEstimator->estimate([
            'revenue' => (float) $revenueRows->sum('revenue'),
            'discounts' => (float) $candleCash['rewardCostAmount'],
        ]);

        return [
            'revenueRows' => $revenueRows,
            'locationRows' => $locationRows,
            'attributionRows' => $classifiedAttribution['rowsForSection'],
            'attributionSummary' => $classifiedAttribution['summary'],
            'candleCash' => $candleCash,
            'rewardSales' => round((float) $revenueRows->sum('revenue'), 2),
            'rewardsOrderCount' => (int) $revenueRows->count(),
            'returningCustomerRate' => $returningCustomerRate,
            'netProfit' => (float) $profit['netProfit'],
            'financials' => [
                'grossRevenueTouched' => round((float) $revenueRows->sum('revenue'), 2),
                'rewardCostAbsorbed' => round((float) $candleCash['rewardCostAmount'], 2),
                'incrementalRetainedRevenue' => round(max(0, (float) $revenueRows->sum('revenue') - (float) $candleCash['rewardCostAmount']), 2),
                'netProfit' => (float) $profit['netProfit'],
                'profitBreakdown' => $profit,
            ],
            'flags' => [
                'has_any_data' => $revenueRows->isNotEmpty() || $candleCash['used']['count'] > 0,
                'attribution_partial' => (bool) data_get($classifiedAttribution, 'summary.has_unknown_rows', false),
                'location_partial' => $locationRows->isNotEmpty()
                    ? $locationRows->contains(fn (array $row): bool => (bool) ($row['partial'] ?? false))
                    : true,
                'profit_estimated' => true,
            ],
        ];
    }

    protected function conversionRows(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return MarketingCampaignConversion::query()
            ->with(['campaign:id,channel', 'profile:id,country,state,city'])
            ->whereBetween('converted_at', [$from, $to])
            ->get()
            ->map(function (MarketingCampaignConversion $conversion): array {
                $channel = strtolower(trim((string) ($conversion->campaign?->channel ?? 'other')));
                $channel = match ($channel) {
                    'sms' => 'text',
                    'email' => 'email',
                    default => 'other',
                };
                $snapshot = is_array($conversion->attribution_snapshot ?? null) ? $conversion->attribution_snapshot : [];

                return [
                    'sourceKey' => $this->revenueKey((string) $conversion->source_type, (string) $conversion->source_id, (int) $conversion->id),
                    'sourceType' => (string) $conversion->source_type,
                    'sourceId' => (string) $conversion->source_id,
                    'orderId' => is_numeric($conversion->source_id) ? (int) $conversion->source_id : null,
                    'occurredAt' => optional($conversion->converted_at)->toIso8601String(),
                    'date' => optional($conversion->converted_at)?->toImmutable() ?: now()->toImmutable(),
                    'revenue' => round((float) ($conversion->order_total ?? 0), 2),
                    'profileId' => (int) $conversion->marketing_profile_id,
                    'channel' => $snapshot['channel'] ?? $channel,
                    'attributionExplicitChannel' => in_array($channel, ['text', 'email'], true) ? $channel : null,
                    'attributionSnapshot' => $snapshot,
                    'attributionSignalData' => [
                        'source_type' => (string) $conversion->source_type,
                        'source_id' => (string) $conversion->source_id,
                        'campaign_channel' => $conversion->campaign?->channel,
                        'attribution_type' => $conversion->attribution_type,
                        'notes' => $conversion->notes,
                    ],
                    'country' => trim((string) ($conversion->profile?->country ?? '')),
                    'state' => trim((string) ($conversion->profile?->state ?? '')),
                    'city' => trim((string) ($conversion->profile?->city ?? '')),
                ];
            });
    }

    protected function birthdayRows(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return BirthdayRewardIssuance::query()
            ->with([
                'marketingProfile:id,country,state,city',
                'birthdayProfile:id,signup_source,source,metadata',
            ])
            ->whereBetween('redeemed_at', [$from, $to])
            ->get()
            ->map(function (BirthdayRewardIssuance $issuance): array {
                $revenue = $issuance->attributed_revenue !== null
                    ? (float) $issuance->attributed_revenue
                    : (float) ($issuance->order_total ?? 0);

                return [
                    'sourceKey' => $issuance->order_id
                        ? 'order:' . $issuance->order_id
                        : 'birthday:' . $issuance->id,
                    'sourceType' => 'birthday_reward',
                    'sourceId' => (string) $issuance->id,
                    'orderId' => $issuance->order_id ? (int) $issuance->order_id : null,
                    'occurredAt' => optional($issuance->redeemed_at)->toIso8601String(),
                    'date' => optional($issuance->redeemed_at)?->toImmutable() ?: now()->toImmutable(),
                    'revenue' => round($revenue, 2),
                    'profileId' => (int) $issuance->marketing_profile_id,
                    'channel' => 'other',
                    'attributionExplicitChannel' => null,
                    'attributionSignalData' => [
                        'source_type' => 'birthday_reward',
                        'campaign_type' => $issuance->campaign_type,
                        'signup_source' => $issuance->birthdayProfile?->signup_source,
                        'source' => $issuance->birthdayProfile?->source,
                        'metadata' => is_array($issuance->metadata) ? $issuance->metadata : [],
                        'birthday_profile_metadata' => is_array($issuance->birthdayProfile?->metadata) ? $issuance->birthdayProfile?->metadata : [],
                    ],
                    'country' => trim((string) ($issuance->marketingProfile?->country ?? '')),
                    'state' => trim((string) ($issuance->marketingProfile?->state ?? '')),
                    'city' => trim((string) ($issuance->marketingProfile?->city ?? '')),
                ];
            });
    }

    protected function referralRows(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return \App\Models\CandleCashReferral::query()
            ->with(['referredProfile:id,country,state,city'])
            ->whereNotNull('qualified_at')
            ->whereBetween('qualified_at', [$from, $to])
            ->get()
            ->map(function (\App\Models\CandleCashReferral $referral): array {
                return [
                    'sourceKey' => $this->revenueKey(
                        (string) ($referral->qualifying_order_source ?? 'referral'),
                        (string) ($referral->qualifying_order_id ?? $referral->id),
                        (int) $referral->id
                    ),
                    'sourceType' => 'referral',
                    'sourceId' => (string) $referral->id,
                    'orderId' => $referral->qualifying_order_id ? (int) $referral->qualifying_order_id : null,
                    'occurredAt' => optional($referral->qualified_at)->toIso8601String(),
                    'date' => optional($referral->qualified_at)?->toImmutable() ?: now()->toImmutable(),
                    'revenue' => round((float) ($referral->qualifying_order_total ?? 0), 2),
                    'profileId' => (int) ($referral->referred_marketing_profile_id ?? 0),
                    'channel' => 'other',
                    'attributionExplicitChannel' => null,
                    'attributionSignalData' => [
                        'source_type' => 'referral',
                        'qualifying_order_source' => $referral->qualifying_order_source,
                        'referral_code' => $referral->referral_code,
                        'metadata' => is_array($referral->metadata) ? $referral->metadata : [],
                    ],
                    'country' => trim((string) ($referral->referredProfile?->country ?? '')),
                    'state' => trim((string) ($referral->referredProfile?->state ?? '')),
                    'city' => trim((string) ($referral->referredProfile?->city ?? '')),
                ];
            });
    }

    protected function revenueKey(string $sourceType, string $sourceId, int $fallbackId): string
    {
        $sourceType = strtolower(trim($sourceType));
        $sourceId = trim($sourceId);

        if ($sourceType === 'order' && $sourceId !== '') {
            return 'order:' . $sourceId;
        }

        if ($sourceId !== '') {
            return $sourceType . ':' . $sourceId;
        }

        return $sourceType . ':row:' . $fallbackId;
    }

    protected function normalizedRevenueRows(Collection $conversionRows, Collection $birthdayRows, Collection $referralRows): Collection
    {
        return $conversionRows
            ->concat($birthdayRows)
            ->concat($referralRows)
            ->filter(fn (array $row): bool => (float) ($row['revenue'] ?? 0) > 0)
            ->groupBy('sourceKey')
            ->map(function (Collection $rows): array {
                $preferred = $rows
                    ->sortByDesc(fn (array $row): array => [
                        $row['channel'] === 'other' ? 0 : 1,
                        (float) ($row['revenue'] ?? 0),
                    ])
                    ->first();

                return $preferred ?? [];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $revenueRows
     * @param  array<int,string>  $visibleSources
     * @return array{rows:Collection<int,array<string,mixed>>,rowsForSection:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    protected function classifyAttributionRows(Collection $revenueRows, array $visibleSources): array
    {
        if ($revenueRows->isEmpty()) {
            $empty = $this->attributionAggregator->aggregate(collect(), $visibleSources);

            return [
                'rows' => collect(),
                'rowsForSection' => $empty['rows'],
                'summary' => $empty['summary'],
            ];
        }

        $orderSignals = $this->orderAttributionSignals($revenueRows);

        $classifiedRows = $revenueRows
            ->map(function (array $row) use ($orderSignals): array {
                $snapshot = is_array($row['attributionSnapshot'] ?? null) ? $row['attributionSnapshot'] : [];
                if (($snapshot['channel'] ?? null) !== null) {
                    return [
                        ...$row,
                        'channel' => (string) $snapshot['channel'],
                        'attributionConfidence' => $snapshot['confidence'] ?? 'medium',
                        'attributionMatchedBy' => $snapshot['matched_by'] ?? 'conversion_snapshot',
                        'attributionMatchedValue' => $snapshot['matched_value'] ?? ($snapshot['channel'] ?? null),
                    ];
                }

                $orderId = (int) ($row['orderId'] ?? 0);
                $orderSignal = $orderId > 0 ? (array) ($orderSignals[$orderId] ?? []) : [];
                $sourceMeta = $this->normalizedAttributionSourceMeta(
                    (array) ($row['attributionSignalData'] ?? []),
                    (array) ($orderSignal['sourceMeta'] ?? [])
                );

                $classification = $this->attributionClassifier->classify([
                    'explicitChannel' => $row['attributionExplicitChannel'] ?? null,
                    'sourceType' => $row['sourceType'] ?? null,
                    'sourceId' => $row['sourceId'] ?? null,
                    'source' => $orderSignal['orderSource'] ?? null,
                    'sourceMeta' => $sourceMeta,
                    'campaignType' => data_get($row, 'attributionSignalData.campaign_type'),
                    'signupSource' => data_get($row, 'attributionSignalData.signup_source'),
                ]);

                return [
                    ...$row,
                    'channel' => $classification['channel'],
                    'attributionConfidence' => $classification['confidence'],
                    'attributionMatchedBy' => $classification['matchedBy'],
                    'attributionMatchedValue' => $classification['matchedValue'],
                ];
            })
            ->values();

        $aggregated = $this->attributionAggregator->aggregate($classifiedRows, $visibleSources);

        return [
            'rows' => $classifiedRows,
            'rowsForSection' => $aggregated['rows'],
            'summary' => $aggregated['summary'],
        ];
    }

    /**
     * @param  array<string,mixed>  $signalData
     * @param  array<string,mixed>  $orderSourceMeta
     * @return array<string,mixed>
     */
    protected function normalizedAttributionSourceMeta(array $signalData, array $orderSourceMeta): array
    {
        $flatSignalData = collect($signalData)
            ->reject(fn ($value) => is_array($value))
            ->all();

        return array_filter([
            ...$flatSignalData,
            ...((array) ($signalData['metadata'] ?? [])),
            ...((array) ($signalData['birthday_profile_metadata'] ?? [])),
            ...$orderSourceMeta,
        ], fn ($value) => $value !== null && $value !== '' && ! is_array($value));
    }

    protected function locationRows(Collection $revenueRows, string $grouping): Collection
    {
        $field = match ($grouping) {
            'country' => 'country',
            'city' => 'city',
            default => 'state',
        };

        $totalRevenue = max(0.01, (float) $revenueRows->sum('revenue'));

        return $revenueRows
            ->groupBy(function (array $row) use ($field): string {
                $value = trim((string) ($row[$field] ?? ''));

                return $value !== '' ? $value : 'Unknown';
            })
            ->map(function (Collection $rows, string $label) use ($totalRevenue): array {
                $revenue = round((float) $rows->sum('revenue'), 2);

                return [
                    'name' => $label,
                    'orders' => (int) $rows->count(),
                    'revenue' => $revenue,
                    'share' => round(($revenue / $totalRevenue) * 100, 1),
                    'partial' => $label === 'Unknown',
                ];
            })
            ->sortByDesc('revenue')
            ->take(6)
            ->values();
    }

    protected function returningCustomerRate(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $orderIds = Order::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($orderIds === []) {
            return 0.0;
        }

        $profileIds = MarketingProfileLink::query()
            ->where('source_type', 'order')
            ->whereIn('source_id', $orderIds)
            ->pluck('marketing_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($profileIds->isEmpty()) {
            return 0.0;
        }

        $orderCounts = MarketingProfileLink::query()
            ->select('marketing_profile_id')
            ->selectRaw('count(*) as order_count')
            ->where('source_type', 'order')
            ->whereIn('marketing_profile_id', $profileIds->all())
            ->groupBy('marketing_profile_id')
            ->pluck('order_count', 'marketing_profile_id');

        $returning = $profileIds->filter(fn (int $profileId): bool => ((int) ($orderCounts[$profileId] ?? 0)) > 1)->count();

        return round(($returning / max(1, $profileIds->count())) * 100, 1);
    }

    /**
     * @param  array<string,mixed>|null  $comparisonSnapshot
     * @return array<int,array<string,mixed>>
     */
    protected function topMetrics(array $primarySnapshot, ?array $comparisonSnapshot): array
    {
        $comparison = $this->comparisonService->compareTotals(
            [
                'reward_sales' => (float) $primarySnapshot['rewardSales'],
                'returning_customer_rate' => (float) $primarySnapshot['returningCustomerRate'],
                'candle_cash_used' => (float) data_get($primarySnapshot, 'candleCash.used.amount', 0),
                'net_profit' => (float) $primarySnapshot['netProfit'],
            ],
            $comparisonSnapshot ? [
                'reward_sales' => (float) $comparisonSnapshot['rewardSales'],
                'returning_customer_rate' => (float) $comparisonSnapshot['returningCustomerRate'],
                'candle_cash_used' => (float) data_get($comparisonSnapshot, 'candleCash.used.amount', 0),
                'net_profit' => (float) $comparisonSnapshot['netProfit'],
            ] : null,
            ['reward_sales', 'returning_customer_rate', 'candle_cash_used', 'net_profit']
        );

        $metrics = $comparison['metrics'];

        return [
            [
                'key' => 'rewards_sales',
                'label' => 'Rewards Sales',
                'value' => (float) $primarySnapshot['rewardSales'],
                'formattedValue' => $this->currency((float) $primarySnapshot['rewardSales']),
                'comparisonValue' => data_get($metrics, 'reward_sales.comparison'),
                'deltaPct' => data_get($metrics, 'reward_sales.delta_pct'),
                'deltaLabel' => $this->formatDelta(data_get($metrics, 'reward_sales.delta_pct')),
                'tone' => $this->toneForDelta(data_get($metrics, 'reward_sales.delta_pct')),
                'caption' => 'Attributed loyalty and rewards-influenced revenue across the selected window.',
            ],
            [
                'key' => 'returning_customer_rate',
                'label' => 'Returning Customer Rate',
                'value' => (float) $primarySnapshot['returningCustomerRate'],
                'formattedValue' => $this->percentage((float) $primarySnapshot['returningCustomerRate']),
                'comparisonValue' => data_get($metrics, 'returning_customer_rate.comparison'),
                'deltaPct' => data_get($metrics, 'returning_customer_rate.delta_pct'),
                'deltaLabel' => $this->formatDelta(data_get($metrics, 'returning_customer_rate.delta_pct')),
                'tone' => $this->toneForDelta(data_get($metrics, 'returning_customer_rate.delta_pct')),
                'caption' => 'Share of reward-aware customers in the window who already have more than one linked order.',
            ],
            [
                'key' => 'candle_cash_used',
                'label' => 'Candle Cash Used',
                'value' => (float) data_get($primarySnapshot, 'candleCash.used.amount', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'candleCash.used.amount', 0)),
                'comparisonValue' => data_get($metrics, 'candle_cash_used.comparison'),
                'deltaPct' => data_get($metrics, 'candle_cash_used.delta_pct'),
                'deltaLabel' => $this->formatDelta(data_get($metrics, 'candle_cash_used.delta_pct')),
                'tone' => $this->toneForDelta(data_get($metrics, 'candle_cash_used.delta_pct')),
                'caption' => 'Redeemed Candle Cash value using the normalized local redemption ledger.',
            ],
            [
                'key' => 'net_profit_created',
                'label' => 'Net Profit Created',
                'value' => (float) $primarySnapshot['netProfit'],
                'formattedValue' => $this->currency((float) $primarySnapshot['netProfit']),
                'comparisonValue' => data_get($metrics, 'net_profit.comparison'),
                'deltaPct' => data_get($metrics, 'net_profit.delta_pct'),
                'deltaLabel' => $this->formatDelta(data_get($metrics, 'net_profit.delta_pct')),
                'tone' => $this->toneForDelta(data_get($metrics, 'net_profit.delta_pct')),
                'caption' => 'Estimated contribution after reward cost and conservative fulfillment-cost assumptions.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $comparisonSnapshot
     * @param  array{from:CarbonImmutable,to:CarbonImmutable}|null  $comparisonWindow
     * @param  array<string,mixed>  $resolvedQuery
     * @return array<string,mixed>
     */
    protected function chart(
        array $primarySnapshot,
        ?array $comparisonSnapshot,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $comparisonWindow,
        array $resolvedQuery
    ): array {
        $primarySeries = $this->bucketRevenueSeries(
            $primarySnapshot['revenueRows'],
            $from,
            $to,
            (string) data_get($resolvedQuery, 'interval.unit', 'day')
        );
        $comparisonSeries = $comparisonWindow
            ? $this->bucketRevenueSeries(
                $comparisonSnapshot['revenueRows'] ?? collect(),
                $comparisonWindow['from'],
                $comparisonWindow['to'],
                (string) data_get($resolvedQuery, 'interval.unit', 'day')
            )
            : [];

        $rows = [];
        foreach ($primarySeries as $index => $point) {
            $comparisonPoint = $comparisonSeries[$index] ?? null;
            $rows[] = [
                'label' => $point['label'],
                'primary' => $point['value'],
                'comparison' => $comparisonPoint['value'] ?? null,
            ];
        }

        $bestPrimary = collect($rows)->sortByDesc('primary')->first();

        return [
            'title' => 'Performance trend',
            'subtitle' => 'Rewards-influenced revenue across the selected timeframe, synchronized with the top-line cards.',
            'metric' => [
                'key' => 'rewards_sales',
                'label' => 'Rewards Sales',
            ],
            'visualization' => (string) $resolvedQuery['visualization'],
            'series' => $rows,
            'benchmarkLabel' => 'Best period',
            'benchmarkValue' => $bestPrimary ? $this->currency((float) $bestPrimary['primary']) : '$0.00',
            'empty' => empty($rows),
        ];
    }

    protected function bucketRevenueSeries(Collection $rows, CarbonImmutable $from, CarbonImmutable $to, string $unit): array
    {
        $buckets = [];
        $cursor = $this->bucketStart($from, $unit);

        while ($cursor->lte($to)) {
            $key = $this->bucketKey($cursor, $unit);
            $buckets[$key] = [
                'label' => $this->bucketLabel($cursor, $unit),
                'value' => 0.0,
            ];
            $cursor = $this->nextBucket($cursor, $unit);
        }

        foreach ($rows as $row) {
            $date = $row['date'] instanceof CarbonImmutable
                ? $row['date']
                : CarbonImmutable::parse((string) $row['occurredAt']);
            $key = $this->bucketKey($this->bucketStart($date, $unit), $unit);
            if (! array_key_exists($key, $buckets)) {
                continue;
            }

            $buckets[$key]['value'] = round($buckets[$key]['value'] + (float) ($row['revenue'] ?? 0), 2);
        }

        return array_values($buckets);
    }

    protected function bucketStart(CarbonImmutable $date, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'hour' => $date->startOfHour(),
            'week' => $date->startOfWeek(),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    protected function nextBucket(CarbonImmutable $date, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'hour' => $date->addHour(),
            'week' => $date->addWeek(),
            'month' => $date->addMonth(),
            default => $date->addDay(),
        };
    }

    protected function bucketKey(CarbonImmutable $date, string $unit): string
    {
        return match ($unit) {
            'hour' => $date->format('Y-m-d-H'),
            'week' => $date->format('o-W'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    protected function bucketLabel(CarbonImmutable $date, string $unit): string
    {
        return match ($unit) {
            'hour' => $date->format('gA'),
            'week' => $date->format('M j'),
            'month' => $date->format('M'),
            default => $date->format('M j'),
        };
    }

    /**
     * @param  array<string,mixed>|null  $comparisonSnapshot
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    protected function attributionSection(array $primarySnapshot, ?array $comparisonSnapshot, array $config): array
    {
        $primaryRows = collect($primarySnapshot['attributionRows'])->keyBy('key');
        $comparisonRows = collect($comparisonSnapshot['attributionRows'] ?? [])->keyBy('key');

        $sources = collect((array) $config['visibleAttributionSources'])
            ->map(function (string $key) use ($primaryRows, $comparisonRows): array {
                $primary = (array) ($primaryRows->get($key) ?? []);
                $comparison = (array) ($comparisonRows->get($key) ?? []);
                $primaryRevenue = (float) ($primary['revenue'] ?? 0);
                $comparisonRevenue = (float) ($comparison['revenue'] ?? 0);
                $deltaPct = null;
                if (abs($comparisonRevenue) > 0.001) {
                    $deltaPct = round((($primaryRevenue - $comparisonRevenue) / $comparisonRevenue) * 100, 1);
                }

                return [
                    'key' => $key,
                    'label' => $primary['label'] ?? $this->attributionLabel($key),
                    'revenue' => $primaryRevenue,
                    'formattedRevenue' => $this->currency($primaryRevenue),
                    'orders' => (int) ($primary['orders'] ?? 0),
                    'deltaPct' => $deltaPct,
                    'deltaLabel' => $this->formatDelta($deltaPct),
                    'tone' => $this->toneForDelta($deltaPct),
                    'description' => $this->attributionDescription($key, (bool) ($primary['partial'] ?? false)),
                    'live' => ! (bool) ($primary['partial'] ?? false),
                ];
            })
            ->all();

        return [
            'title' => 'Attribution',
            'subtitle' => 'Revenue influence normalized from embedded marketing and rewards data already present in Backstage.',
            'sources' => $sources,
            'empty' => collect($sources)->every(fn (array $row): bool => (float) $row['revenue'] <= 0.0),
        ];
    }

    protected function attributionDescription(string $key, bool $partial): string
    {
        if ($partial) {
            return 'Live normalization for this source is not fully wired yet, so the dashboard is preserving the slot without faking revenue.';
        }

        return match ($key) {
            'text' => 'Attributed from SMS campaign conversions and reward follow-up flows.',
            'email' => 'Attributed from email campaign conversions recorded in the local marketing domain.',
            'instagram' => 'Normalized from Instagram-style referral domains and UTM source patterns found on linked order metadata.',
            'facebook' => 'Normalized from Facebook and Meta referral domains plus conservative paid-social source patterns.',
            'google' => 'Normalized from Google referral/search signals without treating unrelated Google-owned surfaces as acquisition.',
            'direct' => 'Reserved for records with a clear direct or no-referrer style signal rather than inferred traffic.',
            'unknown' => 'Used when the local order and link metadata do not provide enough trustworthy source detail yet.',
            default => 'Attributed from recognized non-priority sources that do not belong to a named channel.',
        };
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $revenueRows
     * @return array<int,array<string,mixed>>
     */
    protected function orderAttributionSignals(Collection $revenueRows): array
    {
        $orderIds = $revenueRows
            ->pluck('orderId')
            ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', $orderIds->all())
            ->get(['id', 'source', 'attribution_meta', 'shopify_store_key', 'shopify_store', 'shopify_order_id'])
            ->keyBy('id');

        $pairs = [];
        foreach ($orders as $order) {
            $pairs[] = ['source_type' => 'order', 'source_id' => (string) $order->id];

            if ($order->shopify_order_id) {
                $storeKey = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown');
                $pairs[] = [
                    'source_type' => 'shopify_order',
                    'source_id' => $storeKey . ':' . $order->shopify_order_id,
                ];
            }
        }

        $pairs = collect($pairs)->unique(fn (array $pair): string => $pair['source_type'] . '|' . $pair['source_id'])->values();

        $links = MarketingProfileLink::query()
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($nested) use ($pair): void {
                        $nested
                            ->where('source_type', $pair['source_type'])
                            ->where('source_id', $pair['source_id']);
                    });
                }
            })
            ->get(['source_type', 'source_id', 'source_meta']);

        $linkMeta = [];
        foreach ($links as $link) {
            $linkMeta[strtolower(trim((string) $link->source_type)) . '|' . trim((string) $link->source_id)] = (array) ($link->source_meta ?? []);
        }

        $signals = [];
        foreach ($orders as $order) {
            $sourceMeta = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
            $orderMeta = $linkMeta['order|' . $order->id] ?? [];
            $shopifyMeta = [];
            if ($order->shopify_order_id) {
                $storeKey = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown');
                $shopifyMeta = $linkMeta['shopify_order|' . $storeKey . ':' . $order->shopify_order_id] ?? [];
            }

            $sourceMeta = $this->attributionSourceMetaBuilder->mergeSourceMeta($sourceMeta, $shopifyMeta);
            $sourceMeta = $this->attributionSourceMetaBuilder->mergeSourceMeta($sourceMeta, $orderMeta);

            $signals[(int) $order->id] = [
                'orderSource' => $order->source,
                'sourceMeta' => $sourceMeta,
            ];
        }

        return $signals;
    }

    /**
     * @param  array<string,mixed>  $resolvedQuery
     * @return array<string,mixed>
     */
    protected function locationSection(array $primarySnapshot, array $resolvedQuery): array
    {
        $rows = collect($primarySnapshot['locationRows'])
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'formattedRevenue' => $this->currency((float) $row['revenue']),
                'share' => (float) $row['share'],
                'partial' => (bool) $row['partial'],
            ])
            ->all();

        return [
            'title' => 'Location origins',
            'subtitle' => 'Reward-aware order origins grouped from the best available local customer geography.',
            'grouping' => (string) $resolvedQuery['locationGrouping'],
            'items' => $rows,
            'empty' => $rows === [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $comparisonSnapshot
     * @return array<string,mixed>
     */
    protected function financialSummary(array $primarySnapshot, ?array $comparisonSnapshot): array
    {
        $items = [
            [
                'label' => 'Gross revenue touched',
                'value' => (float) data_get($primarySnapshot, 'financials.grossRevenueTouched', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'financials.grossRevenueTouched', 0)),
                'detail' => 'Deduped attributed revenue from campaign conversions, referrals, and birthday reward redemptions.',
            ],
            [
                'label' => 'Reward cost absorbed',
                'value' => (float) data_get($primarySnapshot, 'financials.rewardCostAbsorbed', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'financials.rewardCostAbsorbed', 0)),
                'detail' => 'Redeemed Candle Cash plus issued birthday reward cost normalized through the current local rewards domain.',
            ],
            [
                'label' => 'Incremental retained revenue',
                'value' => (float) data_get($primarySnapshot, 'financials.incrementalRetainedRevenue', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'financials.incrementalRetainedRevenue', 0)),
                'detail' => 'Revenue touched after subtracting the direct reward cost visible in this timeframe.',
            ],
        ];

        return [
            'title' => 'Financial summary',
            'subtitle' => 'Net contribution is estimated conservatively so the dashboard can stay honest before deeper cost modeling lands.',
            'items' => $items,
            'netProfit' => [
                'value' => (float) data_get($primarySnapshot, 'financials.netProfit', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'financials.netProfit', 0)),
                'comparisonValue' => $comparisonSnapshot ? (float) data_get($comparisonSnapshot, 'financials.netProfit', 0) : null,
            ],
        ];
    }
}
