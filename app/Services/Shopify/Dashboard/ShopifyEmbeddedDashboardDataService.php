<?php

namespace App\Services\Shopify\Dashboard;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Marketing\CandleCashEarnedAnalyticsService;
use App\Services\Marketing\CandleCashLedgerNormalizationService;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\MarketingAttributionSourceMetaBuilder;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\OrderProfitCalculator;
use App\Services\Reporting\AnalyticsComparisonService;
use App\Support\Marketing\CandleCashMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ShopifyEmbeddedDashboardDataService
{
    public function __construct(
        protected ShopifyEmbeddedDashboardConfig $config,
        protected ShopifyEmbeddedDashboardQuery $query,
        protected ShopifyEmbeddedDashboardCandleCashValueProvider $candleCashProvider,
        protected ShopifyEmbeddedDashboardProfitEstimator $profitEstimator,
        protected OrderProfitCalculator $orderProfitCalculator,
        protected ShopifyEmbeddedDashboardAttributionClassifier $attributionClassifier,
        protected ShopifyEmbeddedDashboardAttributionAggregator $attributionAggregator,
        protected AnalyticsComparisonService $comparisonService,
        protected CandleCashService $candleCashService,
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder,
        protected CandleCashEarnedAnalyticsService $candleCashEarnedAnalyticsService,
        protected CandleCashLedgerNormalizationService $candleCashLedgerNormalizationService,
        protected MarketingEmailReadiness $marketingEmailReadiness
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function payload(array $input = []): array
    {
        $config = $this->config->payload();
        $resolvedQuery = $this->query->resolve($input, $config);
        $cacheKey = $this->cacheKey($resolvedQuery);
        $cacheTtlSeconds = $this->cacheTtlSeconds($resolvedQuery);
        $forceRefresh = $this->shouldForceRefresh($input);
        $cacheHit = false;

        if ($forceRefresh) {
            $payload = $this->buildPayload($config, $resolvedQuery, $cacheTtlSeconds);
            Cache::put($cacheKey, $payload, now()->addSeconds($cacheTtlSeconds));
        } else {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $payload = $cached;
                $cacheHit = true;
            } else {
                $payload = $this->buildPayload($config, $resolvedQuery, $cacheTtlSeconds);
                Cache::put($cacheKey, $payload, now()->addSeconds($cacheTtlSeconds));
            }
        }

        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['freshness'] = [
            'cache' => [
                'hit' => $cacheHit,
                'forced' => $forceRefresh,
                'ttlSeconds' => $cacheTtlSeconds,
            ],
        ];

        return $payload;
    }

    protected function shouldForceRefresh(array $input): bool
    {
        $refresh = $input['refresh'] ?? $input['fresh'] ?? null;

        return filter_var($refresh, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $resolvedQuery
     * @return array<string,mixed>
     */
    protected function buildPayload(array $config, array $resolvedQuery, int $cacheTtlSeconds): array
    {
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
                'cacheTtlSeconds' => $cacheTtlSeconds,
                'partialData' => [
                    'attribution' => (bool) $primarySnapshot['flags']['attribution_partial'],
                    'locations' => (bool) $primarySnapshot['flags']['location_partial'],
                    'profit' => (bool) $primarySnapshot['flags']['profit_partial'],
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
            'candleCashEngagement' => $this->candleCashEngagementSection($primarySnapshot, $comparisonSnapshot),
            'flags' => [
                'hasAnyData' => (bool) $primarySnapshot['flags']['has_any_data'],
                'usesFallbackAttribution' => (bool) $primarySnapshot['flags']['attribution_partial'],
                'usesEstimatedOrderRevenue' => (bool) $primarySnapshot['flags']['profit_used_estimator_fallback'],
            ],
        ];
    }

    protected function cacheKey(array $resolvedQuery): string
    {
        return 'shopify:embedded-dashboard:'.sha1(json_encode($resolvedQuery));
    }

    protected function cacheTtlSeconds(array $resolvedQuery): int
    {
        $timeframe = (string) ($resolvedQuery['timeframe'] ?? 'last_30_days');
        $primaryWindow = $this->query->rehydrateWindow((array) ($resolvedQuery['primary'] ?? []));
        $windowTouchesToday = $primaryWindow['to']->greaterThanOrEqualTo(now()->startOfDay()->toImmutable());

        if ($timeframe === 'today') {
            return 30;
        }

        if ($windowTouchesToday) {
            return 60;
        }

        return in_array($timeframe, ['year_to_date', 'full_year'], true) ? 600 : 300;
    }

    protected function currency(float $value): string
    {
        return '$'.number_format($value, 2);
    }

    protected function integerCurrency(float $value): string
    {
        return '$'.number_format(round($value));
    }

    protected function percentage(float $value, int $precision = 1): string
    {
        return number_format($value, $precision).'%';
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
            return ($delta >= 0 ? '+' : '-').$this->currency(abs($delta));
        }

        return ($delta >= 0 ? '+' : '').number_format($delta, 1).'%';
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
        $candleCashEngagement = $this->candleCashEarnedAnalyticsService->snapshot($from, $to);
        $emailReadiness = $this->marketingEmailReadiness->summary();
        $returningCustomerRate = $this->returningCustomerRate($from, $to);
        $realizedRewardCost = max(0.0, (float) data_get($candleCash, 'realizedRewardCost', 0));
        $birthdayRewardLiability = round((float) data_get($candleCash, 'issuedBirthdayValue', 0), 2);
        $profit = $this->profitSummary($revenueRows, $realizedRewardCost);

        return [
            'revenueRows' => $revenueRows,
            'locationRows' => $locationRows,
            'attributionRows' => $classifiedAttribution['rowsForSection'],
            'attributionSummary' => $classifiedAttribution['summary'],
            'candleCash' => $candleCash,
            'candleCashEngagement' => [
                ...$candleCashEngagement,
                'reminderEligibility' => [
                    ...(array) data_get($candleCashEngagement, 'reminderEligibility', []),
                    'emailReadiness' => [
                        'status' => (string) ($emailReadiness['status'] ?? 'disabled'),
                        'enabled' => (bool) ($emailReadiness['enabled'] ?? false),
                        'dryRun' => (bool) ($emailReadiness['dry_run'] ?? false),
                        'missingReasons' => (array) ($emailReadiness['missing_reasons'] ?? []),
                    ],
                ],
            ],
            'rewardSales' => round((float) $revenueRows->sum('revenue'), 2),
            'rewardsOrderCount' => (int) $revenueRows->count(),
            'returningCustomerRate' => $returningCustomerRate,
            'netProfit' => (float) $profit['net_profit'],
            'financials' => [
                'grossRevenueTouched' => round((float) $revenueRows->sum('revenue'), 2),
                'rewardCostAbsorbed' => round((float) $candleCash['rewardCostAmount'], 2),
                'incrementalRetainedRevenue' => round(max(0, (float) $revenueRows->sum('revenue') - (float) $candleCash['rewardCostAmount']), 2),
                'netProfit' => (float) $profit['net_profit'],
                'profitBreakdown' => $profit,
                'realizedRewardCost' => $realizedRewardCost,
                'birthdayRewardLiability' => $birthdayRewardLiability,
            ],
            'flags' => [
                'has_any_data' => $revenueRows->isNotEmpty()
                    || $candleCash['used']['count'] > 0
                    || (int) data_get($candleCashEngagement, 'earned.eventCount', 0) > 0,
                'attribution_partial' => (bool) data_get($classifiedAttribution, 'summary.has_unknown_rows', false),
                'location_partial' => $locationRows->isNotEmpty()
                    ? $locationRows->contains(fn (array $row): bool => (bool) ($row['partial'] ?? false))
                    : true,
                'profit_partial' => (bool) ($profit['confidence_level'] !== 'high' || ($profit['unresolved_revenue'] ?? 0) > 0),
                'profit_used_estimator_fallback' => (bool) ($profit['used_estimator_fallback'] ?? false),
            ],
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $revenueRows
     * @return array<string,mixed>
     */
    protected function profitSummary(Collection $revenueRows, float $realizedRewardCost): array
    {
        $orderIds = $revenueRows
            ->pluck('orderId')
            ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $totalRevenue = round((float) $revenueRows->sum('revenue'), 2);

        if ($orderIds->isEmpty()) {
            $fallback = $this->profitEstimator->estimate([
                'revenue' => $totalRevenue,
                'discounts' => $realizedRewardCost,
            ]);

            return [
                'revenue' => (float) $fallback['revenue'],
                'product_cost_total' => (float) $fallback['productCost'],
                'discount_total' => (float) $fallback['discounts'],
                'refund_total' => (float) $fallback['refunds'],
                'shipping_revenue' => 0.0,
                'shipping_cost' => (float) $fallback['shippingCost'],
                'payment_fee' => 0.0,
                'candle_cash_cost' => 0.0,
                'net_profit' => (float) $fallback['netProfit'],
                'confidence_level' => 'low',
                'assumptions_used' => array_keys(array_filter((array) ($fallback['assumptions'] ?? []), fn ($value) => $value !== null)),
                'resolved_order_count' => 0,
                'expected_order_count' => 0,
                'unresolved_order_count' => 0,
                'unresolved_revenue' => 0.0,
                'confidence_mix' => ['high' => 0, 'medium' => 0, 'low' => $totalRevenue > 0 ? 1 : 0],
                'used_estimator_fallback' => $totalRevenue > 0,
                'channel_profit' => [],
            ];
        }

        $orders = Order::query()
            ->with(['lines.size'])
            ->whereIn('id', $orderIds->all())
            ->get()
            ->keyBy('id');

        $summary = [
            'revenue' => 0.0,
            'product_cost_total' => 0.0,
            'discount_total' => 0.0,
            'refund_total' => 0.0,
            'shipping_revenue' => 0.0,
            'shipping_cost' => 0.0,
            'payment_fee' => 0.0,
            'candle_cash_cost' => 0.0,
            'net_profit' => 0.0,
            'resolved_order_count' => 0,
            'expected_order_count' => $orderIds->count(),
            'unresolved_order_count' => 0,
            'unresolved_revenue' => 0.0,
            'confidence_mix' => ['high' => 0, 'medium' => 0, 'low' => 0],
            'assumptions_used' => [],
            'used_estimator_fallback' => false,
            'channel_profit' => [],
        ];

        $rowsByOrder = $revenueRows
            ->filter(fn (array $row): bool => is_numeric($row['orderId'] ?? null) && (int) ($row['orderId'] ?? 0) > 0)
            ->groupBy(fn (array $row): int => (int) $row['orderId']);

        foreach ($rowsByOrder as $orderId => $rows) {
            /** @var Order|null $order */
            $order = $orders->get((int) $orderId);

            if (! $order) {
                $summary['unresolved_order_count']++;
                $summary['unresolved_revenue'] += (float) $rows->sum('revenue');

                continue;
            }

            $profit = $this->orderProfitCalculator->calculate($order);
            $summary['resolved_order_count']++;
            $summary['confidence_mix'][$profit['confidence_level']]++;
            $summary['assumptions_used'] = array_values(array_unique([
                ...$summary['assumptions_used'],
                ...array_keys((array) ($profit['assumptions_used'] ?? [])),
            ]));

            foreach ([
                'revenue',
                'product_cost_total',
                'discount_total',
                'refund_total',
                'shipping_revenue',
                'shipping_cost',
                'payment_fee',
                'candle_cash_cost',
                'net_profit',
            ] as $field) {
                $summary[$field] += (float) $profit[$field];
            }

            $channel = strtolower(trim((string) ($rows->sortByDesc('revenue')->first()['channel'] ?? 'unknown'))) ?: 'unknown';
            if (! isset($summary['channel_profit'][$channel])) {
                $summary['channel_profit'][$channel] = [
                    'net_profit' => 0.0,
                    'revenue' => 0.0,
                    'resolved_order_count' => 0,
                    'confidence_mix' => ['high' => 0, 'medium' => 0, 'low' => 0],
                ];
            }

            $summary['channel_profit'][$channel]['net_profit'] += (float) $profit['net_profit'];
            $summary['channel_profit'][$channel]['revenue'] += (float) $profit['revenue'];
            $summary['channel_profit'][$channel]['resolved_order_count']++;
            $summary['channel_profit'][$channel]['confidence_mix'][$profit['confidence_level']]++;
        }

        foreach ([
            'revenue',
            'product_cost_total',
            'discount_total',
            'refund_total',
            'shipping_revenue',
            'shipping_cost',
            'payment_fee',
            'candle_cash_cost',
            'net_profit',
            'unresolved_revenue',
        ] as $field) {
            $summary[$field] = round((float) $summary[$field], 2);
        }

        foreach ($summary['channel_profit'] as $channel => $channelProfit) {
            $summary['channel_profit'][$channel]['net_profit'] = round((float) $channelProfit['net_profit'], 2);
            $summary['channel_profit'][$channel]['revenue'] = round((float) $channelProfit['revenue'], 2);
        }

        $summary['confidence_level'] = $this->profitConfidenceLevel($summary);

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    protected function profitConfidenceLevel(array $summary): string
    {
        if ((int) ($summary['resolved_order_count'] ?? 0) === 0) {
            return 'low';
        }

        if (
            (float) ($summary['unresolved_revenue'] ?? 0) > 0
            || (int) data_get($summary, 'confidence_mix.low', 0) > 0
        ) {
            return 'low';
        }

        if ((int) data_get($summary, 'confidence_mix.medium', 0) > 0) {
            return 'medium';
        }

        return 'high';
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
                        ? 'order:'.$issuance->order_id
                        : 'birthday:'.$issuance->id,
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
            return 'order:'.$sourceId;
        }

        if ($sourceId !== '') {
            return $sourceType.':'.$sourceId;
        }

        return $sourceType.':row:'.$fallbackId;
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
                'candle_cash_earned' => (float) data_get($primarySnapshot, 'candleCashEngagement.earned.amount', 0),
                'time_to_first_redemption_days' => $this->daysValue(data_get($primarySnapshot, 'candleCashEngagement.timeToFirstRedemption.averageDays')),
            ],
            $comparisonSnapshot ? [
                'reward_sales' => (float) $comparisonSnapshot['rewardSales'],
                'returning_customer_rate' => (float) $comparisonSnapshot['returningCustomerRate'],
                'candle_cash_used' => (float) data_get($comparisonSnapshot, 'candleCash.used.amount', 0),
                'net_profit' => (float) $comparisonSnapshot['netProfit'],
                'candle_cash_earned' => (float) data_get($comparisonSnapshot, 'candleCashEngagement.earned.amount', 0),
                'time_to_first_redemption_days' => $this->daysValue(data_get($comparisonSnapshot, 'candleCashEngagement.timeToFirstRedemption.averageDays')),
            ] : null,
            ['reward_sales', 'returning_customer_rate', 'candle_cash_used', 'net_profit', 'candle_cash_earned', 'time_to_first_redemption_days']
        );

        $metrics = $comparison['metrics'];
        $timeToRedeemDelta = data_get($metrics, 'time_to_first_redemption_days.delta_pct');
        $timeToRedeemCaption = 'Average '.data_get($primarySnapshot, 'candleCashEngagement.timeToFirstRedemption.formattedAverageDays', 'No redemptions yet')
            .' · Median '.data_get($primarySnapshot, 'candleCashEngagement.timeToFirstRedemption.formattedMedianDays', 'No redemptions yet')
            .'. Lower is better for conversion velocity.';

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
                'caption' => 'Contribution from stored COGS and order-level costs, with confidence reduced when fallback assumptions still carry the period.',
            ],
            [
                'key' => 'candle_cash_earned',
                'label' => 'Candle Cash Earned',
                'value' => (float) data_get($primarySnapshot, 'candleCashEngagement.earned.amount', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'candleCashEngagement.earned.amount', 0)),
                'comparisonValue' => data_get($metrics, 'candle_cash_earned.comparison'),
                'deltaPct' => data_get($metrics, 'candle_cash_earned.delta_pct'),
                'deltaLabel' => $this->formatDelta(data_get($metrics, 'candle_cash_earned.delta_pct')),
                'tone' => $this->toneForDelta(data_get($metrics, 'candle_cash_earned.delta_pct')),
                'caption' => (string) data_get($primarySnapshot, 'candleCashEngagement.earned.sourceSummary', 'No new program-earned Candle Cash events in this window.'),
            ],
            [
                'key' => 'earned_candle_cash_outstanding',
                'label' => 'Earned Candle Cash Outstanding',
                'value' => (float) data_get($primarySnapshot, 'candleCashEngagement.outstanding.amount', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'candleCashEngagement.outstanding.amount', 0)),
                'comparisonValue' => null,
                'deltaPct' => null,
                'deltaLabel' => 'Point-in-time metric',
                'tone' => 'neutral',
                'caption' => (string) data_get($primarySnapshot, 'candleCashEngagement.outstanding.helperText', 'Currently outstanding earned Candle Cash excludes imported opening balances.'),
            ],
            [
                'key' => 'time_to_first_redemption',
                'label' => 'Time to First Redemption',
                'value' => $this->daysValue(data_get($primarySnapshot, 'candleCashEngagement.timeToFirstRedemption.averageDays')),
                'formattedValue' => $this->formatDays(data_get($primarySnapshot, 'candleCashEngagement.timeToFirstRedemption.averageDays')),
                'comparisonValue' => data_get($metrics, 'time_to_first_redemption_days.comparison'),
                'deltaPct' => $timeToRedeemDelta,
                'deltaLabel' => $this->formatDelta($timeToRedeemDelta),
                'tone' => $this->toneForDelta($timeToRedeemDelta !== null ? -1 * (float) $timeToRedeemDelta : null),
                'caption' => $timeToRedeemCaption,
            ],
            [
                'key' => 'customers_with_unredeemed_earned',
                'label' => 'Customers With Unredeemed Earned Candle Cash',
                'value' => (float) data_get($primarySnapshot, 'candleCashEngagement.customersWithOutstandingEarned.count', 0),
                'formattedValue' => number_format((float) data_get($primarySnapshot, 'candleCashEngagement.customersWithOutstandingEarned.count', 0)),
                'comparisonValue' => null,
                'deltaPct' => null,
                'deltaLabel' => 'Point-in-time metric',
                'tone' => 'neutral',
                'caption' => 'Only customers with program-earned Candle Cash still outstanding are counted. Grandfathered-only balances are excluded.',
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
        $unit = (string) data_get($resolvedQuery, 'interval.unit', 'day');
        $definitions = $this->chartSeriesDefinitions();
        $focusMetricKey = (string) data_get($resolvedQuery, 'chartMetric', 'rewards_sales');
        if (! array_key_exists($focusMetricKey, $definitions)) {
            $focusMetricKey = 'rewards_sales';
        }

        $primarySeries = $this->chartSeriesMap(
            collect($primarySnapshot['revenueRows'] ?? []),
            $from,
            $to,
            $unit
        );
        $comparisonSeries = $comparisonWindow && $comparisonSnapshot
            ? $this->chartSeriesMap(
                collect($comparisonSnapshot['revenueRows'] ?? []),
                $comparisonWindow['from'],
                $comparisonWindow['to'],
                $unit
            )
            : [];

        $rows = $this->chartRows(
            $primarySeries,
            $comparisonSeries,
            array_keys($definitions),
            $focusMetricKey,
            $comparisonWindow !== null
        );

        $selectedKeys = $this->defaultChartSelection($focusMetricKey, array_keys($definitions));
        $seriesOptions = collect($definitions)
            ->map(function (array $definition, string $key) use ($primarySeries, $comparisonSeries, $selectedKeys, $comparisonWindow): array {
                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'color' => $definition['color'],
                    'selected' => in_array($key, $selectedKeys, true),
                    'formattedPrimaryTotal' => $this->currency($this->seriesTotal($primarySeries[$key] ?? [])),
                    'formattedComparisonTotal' => $comparisonWindow
                        ? $this->currency($this->seriesTotal($comparisonSeries[$key] ?? []))
                        : null,
                ];
            })
            ->values()
            ->all();

        $bestPrimary = collect($rows)->sortByDesc('primary')->first();
        $hasAnyData = collect($rows)->contains(function (array $row): bool {
            $primaryValues = collect((array) ($row['values'] ?? []));
            $comparisonValues = collect((array) ($row['comparisonValues'] ?? []));

            return $primaryValues->contains(fn ($value): bool => abs((float) $value) > 0.001)
                || $comparisonValues->contains(fn ($value): bool => $value !== null && abs((float) $value) > 0.001);
        });

        return [
            'title' => 'Performance trend',
            'subtitle' => 'Compare reward-attributed sales, birthday and referral revenue, plus Candle Cash earned and redeemed across the same timeframe and comparison window.',
            'metric' => [
                'key' => $focusMetricKey,
                'label' => $definitions[$focusMetricKey]['label'],
            ],
            'visualization' => (string) $resolvedQuery['visualization'],
            'series' => $rows,
            'seriesOptions' => $seriesOptions,
            'benchmarkLabel' => 'Peak selected series period',
            'benchmarkValue' => $bestPrimary
                ? $this->currency((float) $bestPrimary['primary'])
                : '$0.00',
            'empty' => ! $hasAnyData,
        ];
    }

    /**
     * @return array<string,array{label:string,description:string,color:string}>
     */
    protected function chartSeriesDefinitions(): array
    {
        return [
            'rewards_sales' => [
                'label' => 'Rewards Sales',
                'description' => 'All reward-attributed revenue normalized from campaign conversions, birthday rewards, and referrals.',
                'color' => '#15803d',
            ],
            'birthday_redemption_revenue' => [
                'label' => 'Birthday Redemption Revenue',
                'description' => 'Revenue tied specifically to redeemed birthday rewards.',
                'color' => '#d97706',
            ],
            'referral_revenue' => [
                'label' => 'Referral Revenue',
                'description' => 'Qualified referral order revenue tied to the loyalty/referral program.',
                'color' => '#2563eb',
            ],
            'candle_cash_earned' => [
                'label' => 'Candle Cash Earned',
                'description' => 'Program-earned Candle Cash credited in the selected period, excluding imported opening balances.',
                'color' => '#0f766e',
            ],
            'candle_cash_redeemed' => [
                'label' => 'Candle Cash Redeemed',
                'description' => 'Candle Cash converted into redeemed Shopify discount value in the selected period.',
                'color' => '#7c3aed',
            ],
        ];
    }

    /**
     * Build the chart from live reward-attributed revenue rows plus Candle Cash
     * earn/redemption ledger activity so the embedded chart stays backed by
     * real order and rewards data instead of front-end placeholder series.
     *
     * @param  Collection<int,array<string,mixed>>  $revenueRows
     * @return array<string,array<int,array{label:string,value:float,start:string,end:string}>>
     */
    protected function chartSeriesMap(Collection $revenueRows, CarbonImmutable $from, CarbonImmutable $to, string $unit): array
    {
        return [
            'rewards_sales' => $this->bucketRevenueSeries($revenueRows, $from, $to, $unit),
            'birthday_redemption_revenue' => $this->bucketRevenueSeries(
                $revenueRows->filter(fn (array $row): bool => (string) ($row['sourceType'] ?? '') === 'birthday_reward')->values(),
                $from,
                $to,
                $unit
            ),
            'referral_revenue' => $this->bucketRevenueSeries(
                $revenueRows->filter(fn (array $row): bool => (string) ($row['sourceType'] ?? '') === 'referral')->values(),
                $from,
                $to,
                $unit
            ),
            'candle_cash_earned' => $this->candleCashEarnedSeries($from, $to, $unit),
            'candle_cash_redeemed' => $this->candleCashRedeemedSeries($from, $to, $unit),
        ];
    }

    /**
     * @param  array<string,array<int,array{label:string,value:float,start:string,end:string}>>  $primarySeries
     * @param  array<string,array<int,array{label:string,value:float,start:string,end:string}>>  $comparisonSeries
     * @param  array<int,string>  $seriesKeys
     * @return array<int,array<string,mixed>>
     */
    protected function chartRows(
        array $primarySeries,
        array $comparisonSeries,
        array $seriesKeys,
        string $focusMetricKey,
        bool $hasComparison
    ): array {
        $focusSeries = $primarySeries[$focusMetricKey] ?? [];
        $rows = [];

        foreach ($focusSeries as $index => $point) {
            $values = [];
            $comparisonValues = [];

            foreach ($seriesKeys as $key) {
                $values[$key] = round((float) data_get($primarySeries, $key.'.'.$index.'.value', 0), 2);
                $comparisonValues[$key] = $hasComparison
                    ? round((float) data_get($comparisonSeries, $key.'.'.$index.'.value', 0), 2)
                    : null;
            }

            $rows[] = [
                'label' => (string) ($point['label'] ?? ''),
                'bucketStart' => (string) ($point['start'] ?? ''),
                'bucketEnd' => (string) ($point['end'] ?? ''),
                'primary' => (float) ($values[$focusMetricKey] ?? 0),
                'comparison' => $hasComparison ? (float) ($comparisonValues[$focusMetricKey] ?? 0) : null,
                'values' => $values,
                'comparisonValues' => $comparisonValues,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int,string>  $seriesKeys
     * @return array<int,string>
     */
    protected function defaultChartSelection(string $focusMetricKey, array $seriesKeys): array
    {
        return collect([
            $focusMetricKey,
            'candle_cash_earned',
            'candle_cash_redeemed',
        ])
            ->filter(fn (string $key): bool => in_array($key, $seriesKeys, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array{label:string,value:float}>  $series
     */
    protected function seriesTotal(array $series): float
    {
        return round(collect($series)->sum('value'), 2);
    }

    /**
     * @return array<int,array{label:string,value:float,start:string,end:string}>
     */
    protected function candleCashEarnedSeries(CarbonImmutable $from, CarbonImmutable $to, string $unit): array
    {
        $rows = CandleCashTransaction::query()
            ->where('candle_cash_delta', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'type', 'candle_cash_delta', 'source', 'source_id', 'description', 'created_at'])
            ->reject(fn (CandleCashTransaction $transaction): bool => $this->candleCashLedgerNormalizationService->isGrandfatheredOpening($transaction))
            ->map(function (CandleCashTransaction $transaction): array {
                $amount = CandleCashMeasurement::normalizeStoredAmount($transaction->candle_cash_delta ?? 0);

                return [
                    'date' => optional($transaction->created_at)?->toImmutable() ?: now()->toImmutable(),
                    'revenue' => round($this->candleCashService->amountFromPoints($amount), 2),
                ];
            });

        return $this->bucketRevenueSeries($rows, $from, $to, $unit);
    }

    /**
     * @return array<int,array{label:string,value:float,start:string,end:string}>
     */
    protected function candleCashRedeemedSeries(CarbonImmutable $from, CarbonImmutable $to, string $unit): array
    {
        $rows = CandleCashRedemption::query()
            ->where('status', 'redeemed')
            ->whereNotNull('redeemed_at')
            ->whereBetween('redeemed_at', [$from, $to])
            ->orderBy('redeemed_at')
            ->get(['id', 'candle_cash_spent', 'redeemed_at'])
            ->map(function (CandleCashRedemption $redemption): array {
                $amount = CandleCashMeasurement::normalizeStoredAmount($redemption->candle_cash_spent ?? 0);

                return [
                    'date' => optional($redemption->redeemed_at)?->toImmutable() ?: now()->toImmutable(),
                    'revenue' => round($this->candleCashService->amountFromPoints($amount), 2),
                ];
            });

        return $this->bucketRevenueSeries($rows, $from, $to, $unit);
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
                'start' => $cursor->toIso8601String(),
                'end' => $this->bucketEnd($cursor, $unit)->toIso8601String(),
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

    protected function birthdayRedemptionDailySeries(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $aggregated = BirthdayRewardIssuance::query()
            ->where('status', 'redeemed')
            ->whereNotNull('redeemed_at')
            ->whereBetween('redeemed_at', [$from, $to])
            ->selectRaw('DATE(redeemed_at) as redemption_day')
            ->selectRaw('COUNT(DISTINCT id) as redemption_count')
            ->selectRaw('SUM(COALESCE(attributed_revenue, order_total, 0)) as revenue_total')
            ->groupByRaw('DATE(redeemed_at)')
            ->get()
            ->keyBy('redemption_day');

        $rows = [];
        $cursor = $from->startOfDay();

        while ($cursor->lte($to)) {
            $dayKey = $cursor->toDateString();
            $daySummary = $aggregated->get($dayKey);
            $rows[] = [
                'date' => $cursor,
                'label' => $cursor->format('M j'),
                'count' => (int) ($daySummary->redemption_count ?? 0),
                'revenue' => round((float) ($daySummary->revenue_total ?? 0), 2),
            ];
            $cursor = $cursor->addDay();
        }

        return collect($rows);
    }

    protected function bucketDailyMetricSeries(
        Collection $rows,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $unit,
        string $metric
    ): array {
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
                : CarbonImmutable::parse((string) ($row['date'] ?? $row['occurredAt'] ?? 'now'));
            $key = $this->bucketKey($this->bucketStart($date, $unit), $unit);
            if (! array_key_exists($key, $buckets)) {
                continue;
            }

            $buckets[$key]['value'] = round($buckets[$key]['value'] + (float) ($row[$metric] ?? 0), 2);
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

    protected function bucketEnd(CarbonImmutable $date, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'hour' => $date->endOfHour(),
            'week' => $date->endOfWeek(),
            'month' => $date->endOfMonth(),
            default => $date->endOfDay(),
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
            ->map(function (string $key) use ($primaryRows, $comparisonRows, $primarySnapshot): array {
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
                    'profit' => (float) data_get($primarySnapshot, 'financials.profitBreakdown.channel_profit.'.$key.'.net_profit', 0),
                    'formattedProfit' => $this->currency((float) data_get($primarySnapshot, 'financials.profitBreakdown.channel_profit.'.$key.'.net_profit', 0)),
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
                    'source_id' => $storeKey.':'.$order->shopify_order_id,
                ];
            }
        }

        $pairs = collect($pairs)->unique(fn (array $pair): string => $pair['source_type'].'|'.$pair['source_id'])->values();

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
            $linkMeta[strtolower(trim((string) $link->source_type)).'|'.trim((string) $link->source_id)] = (array) ($link->source_meta ?? []);
        }

        $signals = [];
        foreach ($orders as $order) {
            $sourceMeta = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
            $orderMeta = $linkMeta['order|'.$order->id] ?? [];
            $shopifyMeta = [];
            if ($order->shopify_order_id) {
                $storeKey = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown');
                $shopifyMeta = $linkMeta['shopify_order|'.$storeKey.':'.$order->shopify_order_id] ?? [];
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
            'subtitle' => 'Net contribution now uses stored COGS where Backstage has them and degrades confidence conservatively when cost coverage is incomplete.',
            'items' => $items,
            'netProfit' => [
                'value' => (float) data_get($primarySnapshot, 'financials.netProfit', 0),
                'formattedValue' => $this->currency((float) data_get($primarySnapshot, 'financials.netProfit', 0)),
                'comparisonValue' => $comparisonSnapshot ? (float) data_get($comparisonSnapshot, 'financials.netProfit', 0) : null,
                'label' => 'Net profit created',
                'confidenceLevel' => (string) data_get($primarySnapshot, 'financials.profitBreakdown.confidence_level', 'low'),
                'detail' => $this->profitConfidenceDetail((array) data_get($primarySnapshot, 'financials.profitBreakdown', [])),
            ],
            'realizedRewardCost' => (float) data_get($primarySnapshot, 'financials.realizedRewardCost', 0),
            'birthdayRewardLiability' => (float) data_get($primarySnapshot, 'financials.birthdayRewardLiability', 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $profitBreakdown
     */
    protected function profitConfidenceDetail(array $profitBreakdown): string
    {
        $confidence = strtolower(trim((string) ($profitBreakdown['confidence_level'] ?? 'low'))) ?: 'low';
        $resolved = (int) ($profitBreakdown['resolved_order_count'] ?? 0);
        $expected = (int) ($profitBreakdown['expected_order_count'] ?? 0);
        $unresolvedRevenue = (float) ($profitBreakdown['unresolved_revenue'] ?? 0);

        $detail = ucfirst($confidence).' confidence';

        if ($expected > 0) {
            $detail .= ' · '.$resolved.' of '.$expected.' attributed orders resolved from stored cost data.';
        }

        if ($unresolvedRevenue > 0) {
            $detail .= ' '.$this->currency($unresolvedRevenue).' in attributed revenue still lacks an order-backed profit trace.';
        }

        if (! empty($profitBreakdown['assumptions_used'])) {
            $detail .= ' Conservative fallback assumptions still cover the remaining gaps.';
        }

        return trim($detail);
    }

    /**
     * @param  array<string,mixed>|null  $comparisonSnapshot
     * @return array<string,mixed>
     */
    protected function candleCashEngagementSection(array $primarySnapshot, ?array $comparisonSnapshot): array
    {
        $primary = (array) data_get($primarySnapshot, 'candleCashEngagement', []);
        $comparison = (array) data_get($comparisonSnapshot, 'candleCashEngagement', []);

        return [
            ...$primary,
            'comparison' => [
                'earnedAmount' => data_get($comparison, 'earned.amount'),
                'timeToFirstRedemptionAverageDays' => data_get($comparison, 'timeToFirstRedemption.averageDays'),
            ],
        ];
    }

    protected function daysValue(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    protected function formatDays(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'No redemptions yet';
        }

        return number_format((float) $value, 2).' days';
    }
}
